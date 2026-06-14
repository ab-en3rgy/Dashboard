#!/usr/bin/env php
<?php
// cron/sync_keitaro_offers.php
// Imports Keitaro offer-level daily facts into offer_insights_daily.
// Safe parallel contour: does not modify insights_daily or the existing Keitaro sync.

declare(strict_types=1);

require __DIR__ . '/../lib/DB.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';
require_once __DIR__ . '/../lib/ApiSyncLogger.php';

$cfg = require __DIR__ . '/../config/config.php';
$db  = DB::getInstance();
GlobalLogger::ensureSchema($db);
ApiSyncLogger::ensureSchema($db);
$runId = 'sync_keitaro_offers:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));

$kt  = $cfg['keitaro'] ?? [];
$url = rtrim($kt['url'] ?? '', '/');
$key = $kt['key'] ?? '';
$TZ  = $kt['report_tz'] ?? $cfg['display_tz'] ?? $kt['tz'] ?? 'Europe/Chisinau';

$ts = static fn(): string => '[' . date('Y-m-d H:i:s') . '] ';
$streamLog = static function (string $message) use ($ts): void {
    echo $ts() . $message . PHP_EOL;
};

if (!$url || !$key) {
    echo $ts() . "Keitaro offers sync skipped: url/key are empty\n";
    $streamLog('Skipped: Keitaro url/key are empty');
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'cron_skipped',
        'entity_type' => 'sync',
        'status' => 'warning',
        'action' => 'sync_keitaro_offers',
        'reason' => 'Keitaro url/key are empty',
        'correlation_id' => $runId,
    ]);
    exit(0);
}

$schema = __DIR__ . '/../sql/offer_insights_daily.sql';
if (is_file($schema)) {
    $db->exec((string)file_get_contents($schema));
}

try {
    syncKeitaroStreams($db, $url, $key, $ts, $streamLog);
} catch (Throwable $e) {
    echo $ts() . 'Keitaro streams sync warning: ' . $e->getMessage() . "\n";
    $streamLog('Fatal warning: ' . $e->getMessage());
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'cron_warning',
        'entity_type' => 'sync',
        'status' => 'warning',
        'action' => 'sync_keitaro_streams',
        'reason' => 'Keitaro streams sync warning',
        'error' => $e->getMessage(),
        'correlation_id' => $runId,
    ]);
}

$days = max(1, (int)($argv[1] ?? 1));
$debugStreamId = trim((string)($argv[2] ?? ''));
$tzObj = new DateTimeZone($TZ);
$now = new DateTime('now', $tzObj);
$from = (clone $now)->modify("-{$days} days midnight");

$fromStr = $from->format('Y-m-d H:i');
$toStr = $now->format('Y-m-d H:i');
$dateFrom = $from->format('Y-m-d');
$dateTo = $now->format('Y-m-d');

echo "\n" . $ts() . "=== Keitaro offers sync started ===\n";
echo $ts() . "Window: {$fromStr} -> {$toStr} {$TZ} ({$days} days)\n";
GlobalLogger::log($db, [
    'source' => 'cron/sync_keitaro_offers',
    'actor' => 'cron',
    'event_type' => 'cron_started',
    'entity_type' => 'sync',
    'status' => 'running',
    'action' => 'sync_keitaro_offers',
    'reason' => 'Keitaro offers sync started',
    'payload' => [
        'days' => $days,
        'debug_stream_id' => $debugStreamId,
        'from' => $fromStr,
        'to' => $toStr,
        'timezone' => $TZ,
    ],
    'correlation_id' => $runId,
]);

$payload = [
    'range' => [
        'interval' => 'custom',
        'from' => $fromStr,
        'to' => $toStr,
        'timezone' => $TZ,
    ],
    'measures' => ['campaign_unique_clicks', 'regs', 'deposits', 'conversions', 'revenue'],
    'dimensions' => [
        'datetime',
        'stream_id',
        'offer_id',
        'offer',
        'affiliate_network',
        'campaign_id',
        'campaign',
        'country',
        'sub_id_1',
        'sub_id_10',
        'sub_id_11',
        'sub_id_12',
        'sub_id_13',
        'sub_id_15',
    ],
    'filters' => ['OR' => [
        ['name' => 'campaign_unique_clicks', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'regs', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'conversions', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'revenue', 'operator' => 'GREATER_THAN', 'expression' => '0'],
    ]],
    'sort' => [['name' => 'datetime', 'order' => 'asc']],
    'summary' => false,
    'extended' => true,
    'limit' => 100000,
    'offset' => 0,
];

$ch = curl_init("{$url}/admin_api/v1/report/build");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        "Api-Key: {$key}",
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'cron_failed',
        'entity_type' => 'sync',
        'status' => 'failed',
        'action' => 'sync_keitaro_offers',
        'reason' => 'Keitaro offers request cURL error',
        'error' => $curlErr,
        'correlation_id' => $runId,
    ]);
    echo $ts() . "cURL error: {$curlErr}\n";
    exit(1);
}
if ($httpCode !== 200) {
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'cron_failed',
        'entity_type' => 'sync',
        'status' => 'failed',
        'action' => 'sync_keitaro_offers',
        'reason' => "Keitaro offers request HTTP {$httpCode}",
        'error' => substr((string)$response, 0, 500),
        'correlation_id' => $runId,
    ]);
    echo $ts() . "HTTP {$httpCode}: " . substr((string)$response, 0, 500) . "\n";
    exit(1);
}

$json = json_decode((string)$response, true);
if (!is_array($json)) {
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'cron_failed',
        'entity_type' => 'sync',
        'status' => 'failed',
        'action' => 'sync_keitaro_offers',
        'reason' => 'Invalid JSON response',
        'error' => substr((string)$response, 0, 300),
        'correlation_id' => $runId,
    ]);
    echo $ts() . "Invalid JSON response\n";
    exit(1);
}

$rows = $json['rows'] ?? [];
echo $ts() . 'Rows from Keitaro: ' . count($rows) . "\n";

if (!$rows) {
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'cron_finished',
        'entity_type' => 'sync',
        'status' => 'done',
        'action' => 'sync_keitaro_offers',
        'reason' => 'Keitaro offers sync finished with no data',
        'payload' => ['rows' => 0, 'days' => $days],
        'correlation_id' => $runId,
    ]);
    echo $ts() . "No data\n";
    exit(0);
}

function kt_first(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return $default;
}

function kt_int(array $row, array $keys): int
{
    $value = kt_first($row, $keys, '0');
    return (int)round((float)str_replace(',', '.', $value));
}

function kt_float(array $row, array $keys): float
{
    $value = kt_first($row, $keys, '0');
    return (float)str_replace(',', '.', $value);
}

function kt_add_stream_summary(array &$summary, string $date, int $clicks, int $regs, int $deps, float $revenue): void
{
    if (!isset($summary[$date])) {
        $summary[$date] = ['rows' => 0, 'clicks' => 0, 'regs' => 0, 'deps' => 0, 'revenue' => 0.0];
    }
    $summary[$date]['rows']++;
    $summary[$date]['clicks'] += $clicks;
    $summary[$date]['regs'] += $regs;
    $summary[$date]['deps'] += $deps;
    $summary[$date]['revenue'] += $revenue;
}

function kt_print_stream_summary(string $title, array $summary): void
{
    ksort($summary);
    echo $title . "\n";
    foreach ($summary as $date => $row) {
        echo sprintf(
            "  %s rows=%d clicks=%d regs=%d deps=%d revenue=%.2f\n",
            $date,
            (int)$row['rows'],
            (int)$row['clicks'],
            (int)$row['regs'],
            (int)$row['deps'],
            (float)$row['revenue']
        );
    }
    if (!$summary) {
        echo "  no rows\n";
    }
}

function kt_admin_get(PDO $db, string $baseUrl, string $apiKey, string $endpoint, string $requestType): array
{
    $url = $baseUrl . '/admin_api/v1/' . ltrim($endpoint, '/');
    $startedAt = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Api-Key: {$apiKey}"],
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    curl_close($ch);

    if ($err) {
        ApiSyncLogger::log($db, [
            'request_type' => $requestType,
            'endpoint' => $url,
            'status' => 'failed',
            'http_code' => $httpCode ?: null,
            'duration_ms' => $durationMs,
            'error_msg' => $err,
            'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
            'raw_error' => [
                'curl_error' => $err,
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
            ],
        ]);
        throw new RuntimeException("cURL: {$err}");
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        ApiSyncLogger::log($db, [
            'request_type' => $requestType,
            'endpoint' => $url,
            'status' => 'failed',
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'error_msg' => "HTTP {$httpCode}",
            'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
            'raw_error' => [
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
            ],
        ]);
        throw new RuntimeException("HTTP {$httpCode}: " . substr((string)$response, 0, 300));
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        ApiSyncLogger::log($db, [
            'request_type' => $requestType,
            'endpoint' => $url,
            'status' => 'failed',
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'error_msg' => 'Invalid JSON',
            'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
            'raw_error' => [
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
                'json_error' => json_last_error_msg(),
            ],
        ]);
        throw new RuntimeException('Invalid JSON: ' . substr((string)$response, 0, 200));
    }
    if (isset($json['error'])) {
        ApiSyncLogger::log($db, [
            'request_type' => $requestType,
            'endpoint' => $url,
            'status' => 'failed',
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'error_msg' => 'Keitaro API error',
            'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
            'raw_error' => [
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
                'error' => $json['error'],
            ],
        ]);
        throw new RuntimeException('Keitaro API error: ' . json_encode($json['error'], JSON_UNESCAPED_UNICODE));
    }
    ApiSyncLogger::log($db, [
        'request_type' => $requestType,
        'endpoint' => $url,
        'status' => 'ok',
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'rows_returned' => is_array($json['rows'] ?? null) ? count($json['rows']) : 0,
        'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
        'raw_error' => null,
    ]);
    return $json;
}

function kt_rows(array $data, array $keys = []): array
{
    foreach (array_merge($keys, ['rows', 'data', 'items', 'campaigns', 'streams', 'offers']) as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
    }
    if ($data === []) {
        return [];
    }
    return array_keys($data) === range(0, count($data) - 1) ? $data : [];
}

function kt_text(mixed $value): string
{
    if (is_array($value)) {
        foreach (['name', 'title', 'value', 'id'] as $key) {
            if (isset($value[$key]) && trim((string)$value[$key]) !== '') {
                return trim((string)$value[$key]);
            }
        }
        return '';
    }
    return trim((string)$value);
}

function loadKeitaroOfferCatalog(string $baseUrl, string $apiKey): array
{
    $catalog = [];
    try {
        $rows = kt_rows(kt_admin_get($db, $baseUrl, $apiKey, 'offers', 'keitaro:offers'), ['offers']);
    } catch (Throwable) {
        return [];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = kt_text($row['id'] ?? $row['offer_id'] ?? '');
        if ($id === '') {
            continue;
        }
        $network = kt_text($row['affiliate_network'] ?? $row['affiliate_network_name'] ?? $row['network'] ?? '');
        if ($network === '' && isset($row['affiliate_network_id'])) {
            $network = (string)$row['affiliate_network_id'];
        }
        $catalog[$id] = [
            'offer_name' => kt_text($row['name'] ?? $row['offer'] ?? $row['offer_name'] ?? ''),
            'affiliate_network' => $network,
        ];
    }

    return $catalog;
}

function syncKeitaroStreams(PDO $db, string $baseUrl, string $apiKey, callable $ts, callable $log): void
{
    $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
    $log("=== Keitaro streams sync started ===");
    $log("API base: {$baseUrl}");

    $catalog = loadKeitaroOfferCatalog($baseUrl, $apiKey);
    $log('Offer catalog rows: ' . count($catalog));

    $campaigns = kt_rows(kt_admin_get($db, $baseUrl, $apiKey, 'campaigns', 'keitaro:campaigns'), ['campaigns']);
    $log('Campaign rows from Keitaro: ' . count($campaigns));

    $upsertStream = $db->prepare("
        INSERT INTO public.keitaro_streams
            (id, name, state, kt_campaign_id, kt_campaign_name, synced_at)
        VALUES
            (:id, :name, :state, :kt_campaign_id, :kt_campaign_name, NOW())
        ON CONFLICT (id) DO UPDATE SET
            name = EXCLUDED.name,
            state = EXCLUDED.state,
            kt_campaign_id = EXCLUDED.kt_campaign_id,
            kt_campaign_name = EXCLUDED.kt_campaign_name,
            synced_at = NOW()
    ");
    $deleteOffers = $db->prepare("DELETE FROM public.keitaro_stream_offers WHERE stream_id = :stream_id");
    $insertOffer = $db->prepare("
        INSERT INTO public.keitaro_stream_offers
            (stream_id, offer_id, offer_name, affiliate_network, share, state, synced_at)
        VALUES
            (:stream_id, :offer_id, :offer_name, :affiliate_network, :share, :state, NOW())
        ON CONFLICT (stream_id, offer_id) DO UPDATE SET
            offer_name = EXCLUDED.offer_name,
            affiliate_network = EXCLUDED.affiliate_network,
            share = EXCLUDED.share,
            state = EXCLUDED.state,
            synced_at = NOW()
    ");

    $streamCount = 0;
    $linkCount = 0;
    $activeCampaignCount = 0;
    $slotCandidateCount = 0;
    $skippedInactiveCampaigns = 0;
    $skippedNameCount = 0;
    $hadErrors = false;

    foreach ($campaigns as $campaign) {
        if (!is_array($campaign)) {
            continue;
        }
        $campaignId = kt_text($campaign['id'] ?? '');
        if ($campaignId === '') {
            continue;
        }
        $campaignState = strtolower(kt_text($campaign['state'] ?? ''));
        if ($campaignState !== '' && $campaignState !== 'active') {
            $skippedInactiveCampaigns++;
            continue;
        }
        $activeCampaignCount++;
        $campaignName = kt_text($campaign['name'] ?? '');

        try {
            $streams = kt_rows(kt_admin_get($db, $baseUrl, $apiKey, "campaigns/{$campaignId}/streams", 'keitaro:streams'), ['streams']);
            $log("Campaign {$campaignId} '{$campaignName}': streams=" . count($streams));
        } catch (Throwable $e) {
            $hadErrors = true;
            echo $ts() . "Keitaro stream warning campaign={$campaignId}: " . $e->getMessage() . "\n";
            $log("Campaign {$campaignId} '{$campaignName}': ERROR " . $e->getMessage());
            global $runId;
            GlobalLogger::log($db, [
                'source' => 'cron/sync_keitaro_offers',
                'actor' => 'cron',
                'event_type' => 'cron_warning',
                'entity_type' => 'stream',
                'entity_id' => $campaignId,
                'status' => 'warning',
                'action' => 'sync_keitaro_streams',
                'reason' => 'Failed to load Keitaro campaign streams',
                'payload' => [
                    'kt_campaign_id' => $campaignId,
                    'kt_campaign_name' => $campaignName,
                ],
                'error' => $e->getMessage(),
                'correlation_id' => $runId ?? null,
            ]);
            continue;
        }

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $streamId = kt_text($stream['id'] ?? '');
            $streamName = kt_text($stream['name'] ?? '');
            if ($streamId === '' || !preg_match('/^[A-Z]{2}\s+slots$/i', $streamName)) {
                $skippedNameCount++;
                continue;
            }
            $slotCandidateCount++;

            $upsertStream->execute([
                ':id' => $streamId,
                ':name' => $streamName,
                ':state' => kt_text($stream['state'] ?? ''),
                ':kt_campaign_id' => $campaignId,
                ':kt_campaign_name' => $campaignName,
            ]);
            $deleteOffers->execute([':stream_id' => $streamId]);
            $streamCount++;
            $streamLinks = 0;

            foreach (($stream['offers'] ?? []) as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $offerId = kt_text($link['offer_id'] ?? '');
                if ($offerId === '' && isset($link['offer']) && is_array($link['offer'])) {
                    $offerId = kt_text($link['offer']['id'] ?? '');
                }
                $state = kt_text($link['state'] ?? '');
                if ($offerId === '' || strtolower($state) === 'deleted') {
                    continue;
                }
                $catalogRow = $catalog[$offerId] ?? [];
                $offerName = kt_text($link['offer'] ?? $link['offer_name'] ?? $link['name'] ?? '') ?: (string)($catalogRow['offer_name'] ?? '');
                $network = kt_text($link['affiliate_network'] ?? $link['affiliate_network_name'] ?? '') ?: (string)($catalogRow['affiliate_network'] ?? '');
                $insertOffer->execute([
                    ':stream_id' => $streamId,
                    ':offer_id' => $offerId,
                    ':offer_name' => $offerName !== '' ? $offerName : 'Offer ' . $offerId,
                    ':affiliate_network' => $network,
                    ':share' => (int)($link['share'] ?? 0),
                    ':state' => $state,
                ]);
                $linkCount++;
                $streamLinks++;
            }
            $log("Stream {$streamId} '{$streamName}' saved: offers={$streamLinks}, campaign={$campaignId}");
        }
    }

    if (!$hadErrors) {
        $deleteStale = $db->prepare("DELETE FROM public.keitaro_streams WHERE name ~* '^[A-Z]{2}\\s+slots$' AND synced_at < :started_at");
        $deleteStale->execute([':started_at' => $startedAt]);
        $log('Stale slot streams deleted: ' . $deleteStale->rowCount());
    } else {
        $log('Stale cleanup skipped because some campaigns had errors');
    }

    echo $ts() . "Keitaro streams synced: {$streamCount} streams, {$linkCount} offer links" . ($hadErrors ? ' (stale cleanup skipped)' : '') . "\n";
    $log("Summary: active_campaigns={$activeCampaignCount}, skipped_inactive_campaigns={$skippedInactiveCampaigns}, skipped_non_slot_streams={$skippedNameCount}, matched_slot_streams={$slotCandidateCount}, saved_streams={$streamCount}, saved_offer_links={$linkCount}");
    $log("=== Keitaro streams sync finished ===");
    global $runId;
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'keitaro_streams_synced',
        'entity_type' => 'stream',
        'status' => $hadErrors ? 'warning' : 'done',
        'action' => 'sync_keitaro_streams',
        'reason' => 'Keitaro slot streams synced',
        'payload' => [
            'active_campaigns' => $activeCampaignCount,
            'skipped_inactive_campaigns' => $skippedInactiveCampaigns,
            'skipped_non_slot_streams' => $skippedNameCount,
            'matched_slot_streams' => $slotCandidateCount,
            'saved_streams' => $streamCount,
            'saved_offer_links' => $linkCount,
            'stale_cleanup' => $hadErrors ? 'skipped' : 'applied',
        ],
        'correlation_id' => $runId ?? null,
    ]);
}

$agg = [];
$debugRaw = [];
$debugAgg = [];
$skipped = 0;

foreach ($rows as $row) {
    if (!is_array($row)) {
        $skipped++;
        continue;
    }

    $dtStr = kt_first($row, ['datetime', 'day', 'date']);
    if ($dtStr === '') {
        $skipped++;
        continue;
    }

    try {
        $date = (new DateTime($dtStr, $tzObj))->format('Y-m-d');
    } catch (Exception) {
        $skipped++;
        continue;
    }

    $adId = kt_first($row, ['sub_id_1'], 'unknown_ad');
    $streamId = kt_first($row, ['stream_id', 'stream.id']);
    $offerName = kt_first($row, ['offer', 'offer_name'], 'Unknown offer');
    $offerId = kt_first($row, ['offer_id', 'offer.id'], $offerName !== '' ? $offerName : 'unknown_offer');
    $geo = strtoupper(kt_first($row, ['country_code', 'country_raw', 'geo', 'country']));
    $ktCampaignId = kt_first($row, ['campaign_id', 'campaign.id']);
    $clicks = kt_int($row, ['campaign_unique_clicks', 'unique_clicks', 'clicks']);
    $regs = kt_int($row, ['regs', 'registrations']);
    $deps = kt_int($row, ['deposits', 'deps']);
    $conversions = kt_int($row, ['conversions']);
    $revenue = kt_float($row, ['revenue']);

    if ($debugStreamId !== '' && $streamId === $debugStreamId) {
        kt_add_stream_summary($debugRaw, $date, $clicks, $regs, $deps, $revenue);
    }

    $keyParts = [$date, $adId, $offerId, $geo, $ktCampaignId, $streamId];
    $key = implode('|', array_map(static fn($v): string => str_replace('|', '/', (string)$v), $keyParts));

    if (!isset($agg[$key])) {
        $agg[$key] = [
            'date' => $date,
            'ad_id' => $adId,
            'offer_id' => $offerId,
            'offer_name' => $offerName,
            'affiliate_network' => kt_first($row, ['affiliate_network', 'affiliate_network_name']),
            'geo' => $geo,
            'stream_id' => $streamId,
            'kt_campaign_id' => $ktCampaignId,
            'kt_campaign_name' => kt_first($row, ['campaign', 'campaign_name']),
            'fb_campaign_id' => kt_first($row, ['sub_id_10']),
            'fb_campaign_name' => kt_first($row, ['sub_id_11']),
            'fb_adset_id' => kt_first($row, ['sub_id_12']),
            'fb_adset_name' => kt_first($row, ['sub_id_13']),
            'fb_ad_name' => kt_first($row, ['sub_id_15']),
            'clicks' => 0,
            'regs' => 0,
            'deps' => 0,
            'conversions' => 0,
            'revenue' => 0.0,
            'allocated_spend' => 0.0,
            'source_ad_spend' => 0.0,
            'matched_ad' => false,
            'spend_allocation_basis' => 'clicks',
        ];
    }

    $agg[$key]['clicks'] += $clicks;
    $agg[$key]['regs'] += $regs;
    $agg[$key]['deps'] += $deps;
    $agg[$key]['conversions'] += $conversions;
    $agg[$key]['revenue'] += $revenue;
}

echo $ts() . 'Aggregated rows: ' . count($agg) . ", skipped: {$skipped}\n";
if ($debugStreamId !== '') {
    kt_print_stream_summary($ts() . "Keitaro raw stream {$debugStreamId}:", $debugRaw);
}
if (!$agg) {
    GlobalLogger::log($db, [
        'source' => 'cron/sync_keitaro_offers',
        'actor' => 'cron',
        'event_type' => 'cron_finished',
        'entity_type' => 'sync',
        'status' => 'warning',
        'action' => 'sync_keitaro_offers',
        'reason' => 'Keitaro offers sync finished with no aggregate rows',
        'payload' => ['rows' => count($rows), 'skipped' => $skipped],
        'correlation_id' => $runId,
    ]);
    exit(0);
}

$adDayClicks = [];
$adIds = [];
foreach ($agg as $item) {
    if ($debugStreamId !== '' && (string)$item['stream_id'] === $debugStreamId) {
        kt_add_stream_summary($debugAgg, (string)$item['date'], (int)$item['clicks'], (int)$item['regs'], (int)$item['deps'], (float)$item['revenue']);
    }
    $adDay = $item['date'] . '|' . $item['ad_id'];
    $adDayClicks[$adDay] = ($adDayClicks[$adDay] ?? 0) + (int)$item['clicks'];
    if (preg_match('/^\d+$/', $item['ad_id'])) {
        $adIds[$item['ad_id']] = true;
    }
}

$spendMap = [];
if ($adIds) {
    $ids = array_keys($adIds);
    $ph = implode(',', array_map(static fn($i): string => ":ad{$i}", array_keys($ids)));
    $params = [':date_from' => $dateFrom, ':date_to' => $dateTo];
    foreach ($ids as $i => $id) {
        $params[":ad{$i}"] = $id;
    }
    $stmt = $db->prepare("
        SELECT date::text AS date, ad_id::text AS ad_id, spend
        FROM insights_daily
        WHERE date BETWEEN :date_from AND :date_to
          AND ad_id::text IN ({$ph})
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $spendMap[$row['date'] . '|' . $row['ad_id']] = (float)$row['spend'];
    }
}

foreach ($agg as &$item) {
    $adDay = $item['date'] . '|' . $item['ad_id'];
    $sourceSpend = $spendMap[$adDay] ?? 0.0;
    $totalClicks = $adDayClicks[$adDay] ?? 0;
    $item['source_ad_spend'] = round($sourceSpend, 4);
    $item['matched_ad'] = isset($spendMap[$adDay]);
    if ($sourceSpend > 0 && $totalClicks > 0) {
        $item['allocated_spend'] = round($sourceSpend * ((int)$item['clicks'] / $totalClicks), 4);
        $item['spend_allocation_basis'] = 'clicks';
    } else {
        $item['allocated_spend'] = 0.0;
        $item['spend_allocation_basis'] = $totalClicks > 0 ? 'no_fb_spend' : 'no_clicks';
    }
}
unset($item);

$windowCleanup = $db->prepare("
    DELETE FROM offer_insights_daily
    WHERE date BETWEEN :date_from AND :date_to
");
$windowCleanup->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
if ($windowCleanup->rowCount() > 0) {
    echo $ts() . 'Deleted previous offer rows in import window: ' . $windowCleanup->rowCount() . "\n";
}

$stmt = $db->prepare("
    INSERT INTO offer_insights_daily
        (date, ad_id, offer_id, offer_name, affiliate_network, geo, stream_id,
         kt_campaign_id, kt_campaign_name,
         fb_campaign_id, fb_campaign_name, fb_adset_id, fb_adset_name, fb_ad_name,
         clicks, regs, deps, conversions, revenue,
         allocated_spend, source_ad_spend, matched_ad, spend_allocation_basis, synced_at)
    VALUES
        (:date, :ad_id, :offer_id, :offer_name, :affiliate_network, :geo, :stream_id,
         :kt_campaign_id, :kt_campaign_name,
         :fb_campaign_id, :fb_campaign_name, :fb_adset_id, :fb_adset_name, :fb_ad_name,
         :clicks, :regs, :deps, :conversions, :revenue,
         :allocated_spend, :source_ad_spend, :matched_ad, :spend_allocation_basis, NOW())
    ON CONFLICT (date, ad_id, offer_id, geo, kt_campaign_id, stream_id) DO UPDATE SET
        offer_name = EXCLUDED.offer_name,
        affiliate_network = EXCLUDED.affiliate_network,
        kt_campaign_name = EXCLUDED.kt_campaign_name,
        fb_campaign_id = EXCLUDED.fb_campaign_id,
        fb_campaign_name = EXCLUDED.fb_campaign_name,
        fb_adset_id = EXCLUDED.fb_adset_id,
        fb_adset_name = EXCLUDED.fb_adset_name,
        fb_ad_name = EXCLUDED.fb_ad_name,
        clicks = EXCLUDED.clicks,
        regs = EXCLUDED.regs,
        deps = EXCLUDED.deps,
        conversions = EXCLUDED.conversions,
        revenue = EXCLUDED.revenue,
        allocated_spend = EXCLUDED.allocated_spend,
        source_ad_spend = EXCLUDED.source_ad_spend,
        matched_ad = EXCLUDED.matched_ad,
        spend_allocation_basis = EXCLUDED.spend_allocation_basis,
        synced_at = NOW()
");

$upserted = 0;
$errors = 0;
foreach ($agg as $item) {
    try {
        $item['matched_ad'] = $item['matched_ad'] ? 'TRUE' : 'FALSE';
        $stmt->execute($item);
        $upserted++;
    } catch (PDOException $e) {
        echo $ts() . "Row error ad_id={$item['ad_id']} offer={$item['offer_id']} date={$item['date']}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

$allocated = array_sum(array_map(static fn($r): float => (float)$r['allocated_spend'], $agg));
$revenue = array_sum(array_map(static fn($r): float => (float)$r['revenue'], $agg));

if ($debugStreamId !== '') {
    kt_print_stream_summary($ts() . "Aggregated stream {$debugStreamId}:", $debugAgg);
    $debugDb = $db->prepare("
        SELECT date::text AS date,
               COUNT(*) AS rows,
               COALESCE(SUM(clicks), 0) AS clicks,
               COALESCE(SUM(regs), 0) AS regs,
               COALESCE(SUM(deps), 0) AS deps,
               COALESCE(SUM(revenue), 0) AS revenue
        FROM offer_insights_daily
        WHERE date BETWEEN :date_from AND :date_to
          AND stream_id = :stream_id
        GROUP BY date
        ORDER BY date
    ");
    $debugDb->execute([':date_from' => $dateFrom, ':date_to' => $dateTo, ':stream_id' => $debugStreamId]);
    $dbSummary = [];
    foreach ($debugDb->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dbSummary[(string)$row['date']] = [
            'rows' => (int)$row['rows'],
            'clicks' => (int)$row['clicks'],
            'regs' => (int)$row['regs'],
            'deps' => (int)$row['deps'],
            'revenue' => (float)$row['revenue'],
        ];
    }
    kt_print_stream_summary($ts() . "DB stream {$debugStreamId} after save:", $dbSummary);
}

echo $ts() . "Saved: {$upserted}, errors: {$errors}, allocated_spend=" . round($allocated, 2) . ", revenue=" . round($revenue, 2) . "\n";
echo $ts() . "=== Done ===\n";
GlobalLogger::log($db, [
    'source' => 'cron/sync_keitaro_offers',
    'actor' => 'cron',
    'event_type' => 'cron_finished',
    'entity_type' => 'sync',
    'status' => $errors > 0 ? 'warning' : 'done',
    'action' => 'sync_keitaro_offers',
    'reason' => 'Keitaro offers sync finished',
    'payload' => [
        'rows' => count($rows),
        'aggregated_rows' => count($agg),
        'skipped' => $skipped,
        'deleted_previous_rows' => $windowCleanup->rowCount(),
        'upserted' => $upserted,
        'errors' => $errors,
        'allocated_spend' => round($allocated, 2),
        'revenue' => round($revenue, 2),
        'days' => $days,
        'from' => $fromStr,
        'to' => $toStr,
    ],
    'correlation_id' => $runId,
]);
