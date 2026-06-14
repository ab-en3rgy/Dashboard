<?php
// cron/balance_offer_weights.php
// Rebalances Keitaro stream offer weights using the same model as view=streams.
//
// Usage:
//   php cron/balance_offer_weights.php --dry-run --all
//   php cron/balance_offer_weights.php --apply --all
//   php cron/balance_offer_weights.php --dry-run --stream=4468
//   php cron/balance_offer_weights.php --apply --stream=4468

declare(strict_types=1);

require_once __DIR__ . '/../lib/DB.php';
require_once __DIR__ . '/../lib/StreamOfferBalancer.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';

$cfg = require __DIR__ . '/../config/config.php';
$ktUrl = rtrim((string)($cfg['keitaro']['url'] ?? ''), '/');
$ktKey = (string)($cfg['keitaro']['key'] ?? '');
$tzName = (string)($cfg['display_tz'] ?? 'Europe/Chisinau');

$args = getopt('', ['all', 'stream:', 'dry-run', 'apply', 'skip-sync', 'sync-days:']);
$apply = isset($args['apply']);
$dryRun = isset($args['dry-run']) || !$apply;
$streamFilter = isset($args['stream']) ? trim((string)$args['stream']) : '';
$skipSync = isset($args['skip-sync']);
$syncDays = max(1, (int)($args['sync-days'] ?? 1));

if (!$streamFilter && !isset($args['all'])) {
    echo "Usage:\n";
    echo "  php cron/balance_offer_weights.php --dry-run --all\n";
    echo "  php cron/balance_offer_weights.php --apply --all\n";
    echo "  php cron/balance_offer_weights.php --dry-run --stream=4468\n";
    echo "  php cron/balance_offer_weights.php --apply --stream=4468\n";
    echo "Options:\n";
    echo "  --skip-sync      Do not refresh Keitaro streams/offers before balancing\n";
    echo "  --sync-days=1    Days to refresh in sync_keitaro_offers.php before balancing\n";
    exit(0);
}
$db = DB::getInstance();
GlobalLogger::ensureSchema($db);
$runId = 'balance_offer_weights:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));

if ($apply && (!$ktUrl || !$ktKey)) {
    GlobalLogger::log($db, [
        'source' => 'cron/balance_offer_weights',
        'actor' => 'cron',
        'event_type' => 'cron_failed',
        'entity_type' => 'stream',
        'status' => 'failed',
        'action' => 'balance_offer_weights',
        'reason' => 'Keitaro url/key are required for apply',
        'correlation_id' => $runId,
    ]);
    throw new RuntimeException('Keitaro url/key are required for --apply');
}

[$from7, $to7] = rollingDateRange(7, $tzName);
[$from30, $to30] = rollingDateRange(30, $tzName);
[$from90, $to90] = rollingDateRange(90, $tzName);

logLine('=== Stream offer balance ' . ($dryRun ? 'DRY RUN' : 'APPLY') . ' ===');
logLine('Model: ' . StreamOfferBalancer::MODEL . ', blended Safe EPC 60% 7d + 30% 30d + 10% 90d, zero-share offers excluded from rank/weight');
logLine("Periods: 7d={$from7}..{$to7}; 30d={$from30}..{$to30}; 90d={$from90}..{$to90}");
GlobalLogger::log($db, [
    'source' => 'cron/balance_offer_weights',
    'actor' => 'cron',
    'event_type' => 'cron_started',
    'entity_type' => 'stream',
    'status' => 'running',
    'action' => 'balance_offer_weights',
    'reason' => 'Stream offer balance started',
    'payload' => [
        'mode' => $dryRun ? 'dry_run' : 'apply',
        'stream_filter' => $streamFilter,
        'skip_sync' => $skipSync,
        'sync_days' => $syncDays,
        'model' => StreamOfferBalancer::MODEL,
        'periods' => [
            '7d' => ['from' => $from7, 'to' => $to7],
            '30d' => ['from' => $from30, 'to' => $to30],
            '90d' => ['from' => $from90, 'to' => $to90],
        ],
    ],
    'correlation_id' => $runId,
]);

if (!$skipSync) {
    $syncScript = __DIR__ . '/sync_keitaro_offers.php';
    if (!is_file($syncScript)) {
        throw new RuntimeException("Pre-sync script not found: {$syncScript}");
    }
    logLine("Pre-sync: refreshing Keitaro stream weights and recent offer stats (sync-days={$syncDays})");
    runPhpScript($syncScript, [$syncDays]);
    logLine('Pre-sync completed');
} else {
    logLine('Pre-sync skipped by flag');
}

$streams = loadStreams($db, $streamFilter);
logLine('Streams to process: ' . count($streams));

$updated = 0;
$unchanged = 0;
$failed = 0;

foreach ($streams as $stream) {
    $streamId = (string)$stream['stream_id'];
    $offers = loadStreamOffers($db, $streamId);
    $offerIds = array_values(array_unique(array_map(fn($offer) => (string)$offer['offer_id'], $offers)));
    $activeOffers = array_values(array_filter($offers, fn($offer) => (int)($offer['share'] ?? 0) > 0));

    if (!$activeOffers) {
        logLine("Stream {$streamId} {$stream['stream_name']}: skipped, no active offer weights");
        $unchanged++;
        continue;
    }

    $stats7 = loadStreamStats($db, $from7, $to7, $streamId, $offerIds, (string)$stream['geo']);
    $stats30 = loadStreamStats($db, $from30, $to30, $streamId, $offerIds, (string)$stream['geo']);
    $stats90 = loadStreamStats($db, $from90, $to90, $streamId, $offerIds, (string)$stream['geo']);
    $recommendations = StreamOfferBalancer::calculateRecommendedWeights($offers, $stats7, $stats30, $stats90, [
        'range' => ['label' => '7d', 'from' => $from7, 'to' => $to7, 'weight' => 0.6],
        '30d' => ['label' => '30d', 'from' => $from30, 'to' => $to30, 'weight' => 0.3],
        '90d' => ['label' => '90d', 'from' => $from90, 'to' => $to90, 'weight' => 0.1],
    ]);

    $currentShares = array_column($offers, 'share', 'offer_id');
    $newShares = [];
    foreach ($offers as $offer) {
        $offerId = (string)$offer['offer_id'];
        $newShares[$offerId] = (int)($currentShares[$offerId] ?? 0);
        if (isset($recommendations['offers'][$offerId])) {
            $newShares[$offerId] = (int)$recommendations['offers'][$offerId]['recommended_share'];
        }
    }

    $changes = [];
    foreach ($newShares as $offerId => $newShare) {
        $oldShare = (int)($currentShares[$offerId] ?? 0);
        if ($oldShare !== $newShare) {
            $changes[$offerId] = ['old' => $oldShare, 'new' => $newShare];
        }
    }

    printStreamReport($stream, $offers, $recommendations, $changes);

    if (!$changes) {
        $unchanged++;
        continue;
    }

    if ($dryRun) {
        logLine("Stream {$streamId}: dry-run, not applied");
        GlobalLogger::log($db, [
            'source' => 'cron/balance_offer_weights',
            'actor' => 'cron',
            'event_type' => 'offer_weight_rebalance_recommended',
            'entity_type' => 'stream',
            'entity_id' => $streamId,
            'status' => 'info',
            'action' => 'balance_offer_weights',
            'reason' => 'Dry-run offer weight rebalance recommendation',
            'before_state' => ['shares' => $currentShares],
            'desired_state' => ['shares' => $newShares],
            'payload' => [
                'stream_name' => $stream['stream_name'] ?? '',
                'geo' => $stream['geo'] ?? '',
                'changes' => $changes,
                'recommendations' => $recommendations,
            ],
            'correlation_id' => $runId,
        ]);
        continue;
    }

    try {
        $payload = array_map(static function (array $offer) use ($newShares): array {
            $offerId = (string)$offer['offer_id'];
            return [
                'offer_id' => is_numeric($offerId) ? (int)$offerId : $offerId,
                'share' => (int)($newShares[$offerId] ?? $offer['share']),
            ];
        }, $offers);

        ktPut($ktUrl, $ktKey, "streams/{$streamId}", ['offers' => $payload]);
        updateLocalShares($db, $streamId, $newShares);
        logLine("Stream {$streamId}: applied " . count($changes) . ' changed weights');
        GlobalLogger::log($db, [
            'source' => 'cron/balance_offer_weights',
            'actor' => 'cron',
            'event_type' => 'offer_weight_rebalance_applied',
            'entity_type' => 'stream',
            'entity_id' => $streamId,
            'status' => 'done',
            'action' => 'balance_offer_weights',
            'reason' => 'Offer weight rebalance applied to Keitaro',
            'before_state' => ['shares' => $currentShares],
            'desired_state' => ['shares' => $newShares],
            'after_state' => ['shares' => $newShares],
            'payload' => [
                'stream_name' => $stream['stream_name'] ?? '',
                'geo' => $stream['geo'] ?? '',
                'changes' => $changes,
            ],
            'correlation_id' => $runId,
        ]);
        $updated++;
    } catch (Throwable $e) {
        logLine("Stream {$streamId}: apply failed: " . $e->getMessage());
        GlobalLogger::log($db, [
            'source' => 'cron/balance_offer_weights',
            'actor' => 'cron',
            'event_type' => 'offer_weight_rebalance_failed',
            'entity_type' => 'stream',
            'entity_id' => $streamId,
            'status' => 'failed',
            'action' => 'balance_offer_weights',
            'reason' => 'Offer weight rebalance apply failed',
            'before_state' => ['shares' => $currentShares],
            'desired_state' => ['shares' => $newShares],
            'payload' => [
                'stream_name' => $stream['stream_name'] ?? '',
                'geo' => $stream['geo'] ?? '',
                'changes' => $changes,
            ],
            'error' => $e->getMessage(),
            'correlation_id' => $runId,
        ]);
        $failed++;
    }
}

logLine("Done. updated={$updated}, unchanged={$unchanged}, failed={$failed}");
GlobalLogger::log($db, [
    'source' => 'cron/balance_offer_weights',
    'actor' => 'cron',
    'event_type' => 'cron_finished',
    'entity_type' => 'stream',
    'status' => $failed > 0 ? 'warning' : 'done',
    'action' => 'balance_offer_weights',
    'reason' => 'Stream offer balance finished',
    'payload' => [
        'mode' => $dryRun ? 'dry_run' : 'apply',
        'streams' => count($streams),
        'updated' => $updated,
        'unchanged' => $unchanged,
        'failed' => $failed,
        'stream_filter' => $streamFilter,
    ],
    'correlation_id' => $runId,
]);
exit($failed > 0 ? 1 : 0);

function loadStreams(PDO $db, string $streamFilter): array
{
    $where = ["ks.name ~* '^[A-Z]{2}\\s+slots$'"];
    $params = [];
    if ($streamFilter !== '') {
        $where[] = 'ks.id::text = :stream_id';
        $params[':stream_id'] = $streamFilter;
    }

    $stmt = $db->prepare("
        SELECT ks.id::text AS stream_id,
               ks.name AS stream_name,
               ks.state,
               ks.kt_campaign_id::text AS campaign_id,
               ks.kt_campaign_name AS campaign_name,
               upper(substr(ks.name, 1, 2)) AS geo
        FROM public.keitaro_streams ks
        WHERE " . implode(' AND ', $where) . "
        ORDER BY upper(substr(ks.name, 1, 2)), ks.name
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadStreamOffers(PDO $db, string $streamId): array
{
    $stmt = $db->prepare("
        SELECT offer_id::text AS offer_id,
               offer_name,
               affiliate_network,
               share,
               state
        FROM public.keitaro_stream_offers
        WHERE stream_id::text = :stream_id
          AND COALESCE(state, '') <> 'deleted'
        ORDER BY share DESC, offer_name
    ");
    $stmt->execute([':stream_id' => $streamId]);

    return array_map(static fn($row): array => [
        'offer_id' => (string)($row['offer_id'] ?? ''),
        'offer_name' => (string)($row['offer_name'] ?? ''),
        'affiliate_network' => (string)($row['affiliate_network'] ?? ''),
        'share' => (int)($row['share'] ?? 0),
        'state' => (string)($row['state'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function loadStreamStats(PDO $db, string $dateFrom, string $dateTo, string $streamId, array $offerIds, string $geo): array
{
    $offerIds = array_values(array_filter(array_map('strval', $offerIds)));
    if ($streamId === '' || !$offerIds) {
        return [];
    }

    $params = [':date_from' => $dateFrom, ':date_to' => $dateTo, ':stream_id' => $streamId];
    $where = ['oi.date BETWEEN :date_from AND :date_to'];
    $where[] = 'oi.stream_id::text = :stream_id';
    addInFilter($where, $params, 'oi.offer_id', 'offer', $offerIds);

    $stmt = $db->prepare("
        SELECT oi.offer_id::text AS offer_id,
               MAX(oi.offer_name) AS offer_name,
               MAX(oi.affiliate_network) AS affiliate_network,
               0::numeric AS clicks,
               SUM(oi.clicks) AS leads,
               SUM(oi.regs) AS regs,
               SUM(oi.deps) AS deps,
               SUM(oi.conversions) AS conversions,
               SUM(oi.revenue) AS revenue,
               SUM(oi.allocated_spend) AS spend,
               SUM(oi.revenue) - SUM(oi.allocated_spend) AS profit,
               CASE WHEN SUM(oi.allocated_spend) > 0
                    THEN (SUM(oi.revenue) - SUM(oi.allocated_spend)) / SUM(oi.allocated_spend) * 100
                    ELSE NULL
               END AS roi,
               CASE WHEN SUM(oi.clicks) > 0 THEN SUM(oi.allocated_spend) / SUM(oi.clicks) ELSE NULL END AS cpl,
               CASE WHEN SUM(oi.regs) > 0 THEN SUM(oi.allocated_spend) / SUM(oi.regs) ELSE NULL END AS cpr,
               CASE WHEN SUM(oi.deps) > 0 THEN SUM(oi.allocated_spend) / SUM(oi.deps) ELSE NULL END AS cpd
        FROM public.offer_insights_daily oi
        LEFT JOIN public.ads a ON a.id::text = oi.ad_id
        LEFT JOIN public.campaigns c ON c.id::text = COALESCE(NULLIF(oi.fb_campaign_id, ''), a.campaign_id::text)
        WHERE " . implode(' AND ', $where) . "
        GROUP BY oi.offer_id
    ");
    $stmt->execute($params);

    $stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = StreamOfferBalancer::normalizeStats($row);
        $stats[(string)$row['offer_id']] = $row;
    }

    $geoTotals = loadGeoFbTotals($db, $dateFrom, $dateTo, $geo);
    StreamOfferBalancer::applyGeoTotals($stats, $geoTotals);

    return $stats;
}

function loadGeoFbTotals(PDO $db, string $dateFrom, string $dateTo, string $geo): array
{
    $geo = strtoupper(trim($geo));
    if ($geo === '') {
        return ['spend' => 0.0, 'clicks' => 0];
    }

    $params = [
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
        ':geo_mid' => '%\\_' . $geo . '\\_%',
        ':geo_end' => '%\\_' . $geo,
        ':geo_space' => '%\\_' . $geo . ' %',
    ];

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(id.spend), 0) AS spend,
               COALESCE(SUM(id.clicks), 0) AS clicks
        FROM public.insights_daily id
        JOIN public.ads a ON a.id = id.ad_id
        JOIN public.campaigns c ON c.id = a.campaign_id
        WHERE id.date BETWEEN :date_from AND :date_to
          AND (
            c.name ILIKE :geo_mid ESCAPE '\\'
            OR c.name ILIKE :geo_end ESCAPE '\\'
            OR c.name ILIKE :geo_space ESCAPE '\\'
          )
    ");
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'spend' => round((float)($row['spend'] ?? 0), 4),
        'clicks' => (int)($row['clicks'] ?? 0),
    ];
}

function updateLocalShares(PDO $db, string $streamId, array $newShares): void
{
    $stmt = $db->prepare("
        UPDATE public.keitaro_stream_offers
        SET share = :share,
            synced_at = NOW()
        WHERE stream_id::text = :stream_id
          AND offer_id::text = :offer_id
    ");
    foreach ($newShares as $offerId => $share) {
        $stmt->execute([
            ':stream_id' => $streamId,
            ':offer_id' => (string)$offerId,
            ':share' => (int)$share,
        ]);
    }
}

function addGeoFilter(array &$where, array &$params, string $geo): void
{
    if ($geo === '') {
        return;
    }
    $params[':stream_geo_mid'] = '%\\_' . $geo . '\\_%';
    $params[':stream_geo_end'] = '%\\_' . $geo;
    $params[':stream_geo_space'] = '%\\_' . $geo . ' %';
    $where[] = "(
        COALESCE(NULLIF(oi.fb_campaign_name, ''), c.name, '') ILIKE :stream_geo_mid ESCAPE '\\'
        OR COALESCE(NULLIF(oi.fb_campaign_name, ''), c.name, '') ILIKE :stream_geo_end ESCAPE '\\'
        OR COALESCE(NULLIF(oi.fb_campaign_name, ''), c.name, '') ILIKE :stream_geo_space ESCAPE '\\'
    )";
}

function addInFilter(array &$where, array &$params, string $column, string $prefix, array $values): void
{
    if (!$values) {
        $where[] = '1=0';
        return;
    }
    $ph = [];
    foreach (array_values($values) as $i => $value) {
        $key = ":{$prefix}_{$i}";
        $ph[] = $key;
        $params[$key] = (string)$value;
    }
    $where[] = "{$column}::text IN (" . implode(',', $ph) . ')';
}

function rollingDateRange(int $days, string $tzName): array
{
    $tz = new DateTimeZone($tzName);
    $now = new DateTime('now', $tz);
    $from = (clone $now)->modify('-' . max(0, $days - 1) . ' days midnight');
    return [$from->format('Y-m-d'), $now->format('Y-m-d')];
}

function printStreamReport(array $stream, array $offers, array $recommendations, array $changes): void
{
    logLine("Stream {$stream['stream_id']} {$stream['stream_name']} / {$stream['campaign_name']}: changes=" . count($changes));
    printf("  %-8s %-42s %7s %7s %8s %8s %10s\n", 'Offer', 'Name', 'Old', 'New', 'Delta', 'Mode', 'Score');
    foreach ($offers as $offer) {
        $offerId = (string)$offer['offer_id'];
        $rec = $recommendations['offers'][$offerId] ?? StreamOfferBalancer::emptyRecommendation((int)$offer['share']);
        $old = (int)$offer['share'];
        $new = (int)$rec['recommended_share'];
        $delta = $new - $old;
        if ($old === 0 && $new === 0) {
            continue;
        }
        $marker = $delta === 0 ? ' ' : '*';
        printf(
            "%s %-8s %-42s %7d %7d %+8d %-8s %10.4f\n",
            $marker,
            $offerId,
            mb_substr((string)($offer['offer_name'] ?: 'Offer ' . $offerId), 0, 42),
            $old,
            $new,
            $delta,
            (string)$rec['mode'],
            (float)$rec['score']
        );
    }
}

function ktPut(string $baseUrl, string $key, string $endpoint, array $body): array
{
    $ch = curl_init($baseUrl . '/admin_api/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', "Api-Key: {$key}"],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        throw new RuntimeException("cURL: {$err}");
    }
    $data = json_decode((string)$res, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON: ' . substr((string)$res, 0, 200));
    }
    if (isset($data['error'])) {
        throw new RuntimeException('KT API error: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    return $data;
}

function runPhpScript(string $script, array $args = []): void
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string)$arg);
    }

    $descriptors = [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException("Failed to start PHP script: {$script}");
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("PHP script failed with exit code {$exitCode}: {$script}");
    }
}

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}
