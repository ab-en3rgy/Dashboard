<?php
// @version 1.0.12
// GET /api/streams.php?range=30d

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/StreamOfferBalancer.php';
$cfg = require __DIR__ . '/../config/config.php';

try {
    [$dateFrom, $dateTo, $range] = streamDateRange($_GET['range'] ?? '30d', $me);
    $allowedBmIds = array_map('strval', $auth->allowedBmIds($me));
    $requestedCampaignId = trim((string)($_GET['campaign_id'] ?? ''));
    $requestedStreamId = trim((string)($_GET['stream_id'] ?? ''));

    $streams = loadKeitaroLiveStreams($cfg['keitaro'] ?? []);
    $liveStats = [];
    if ($requestedStreamId !== '' && strtolower($requestedStreamId) !== 'all') {
        $liveStats = loadKeitaroLiveStatsBundle($cfg['keitaro'] ?? [], $dateFrom, $dateTo, '', $requestedStreamId);
    } elseif ($requestedCampaignId !== '') {
        $liveStats = loadKeitaroLiveStatsBundle($cfg['keitaro'] ?? [], $dateFrom, $dateTo, $requestedCampaignId, '');
    }
    if (!$allowedBmIds && !$streams) {
        apiOk(['streams' => [], 'installed' => false, 'range' => $range, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    $exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
    if (!$streams && !$exists) {
        apiOk(['streams' => [], 'installed' => false, 'range' => $range, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    if (!$streams && empty($_GET['bm_id'])) {
        $streams = loadGeoStreams($db, $dateFrom, $dateTo);
    }
    if (!$streams) {
        $streams = loadCampaignGeoStreams($db, $dateFrom, $dateTo, $allowedBmIds, $me);
    }
    if (!$streams) {
        $streams = loadDbStreams($db, $dateFrom, $dateTo);
    }

    if (!$streams) {
        apiOk(['streams' => [], 'installed' => true, 'range' => $range, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    usort($streams, fn($a, $b) => strcmp((string)$a['geo'], (string)$b['geo']) ?: strcmp((string)$a['stream_name'], (string)$b['stream_name']));

    $geoFilter = strtoupper(trim(explode(',', (string)($_GET['geo'] ?? ''))[0] ?? ''));
    if ($geoFilter !== '') {
        $streams = array_values(array_filter($streams, static fn(array $stream): bool => ($stream['geo'] ?? '') === $geoFilter));
        if (!$streams) {
            apiOk(['streams' => [], 'installed' => true, 'range' => $range, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        }
    }

    $showAllStreams = $requestedStreamId === '' || strtolower($requestedStreamId) === 'all';
    if ($showAllStreams) {
        $overall = emptyStreamStats();
        foreach ($streams as &$stream) {
            $allOffers = $stream['offers'] ?? loadDbStreamOffers($db, (string)$stream['stream_id']);
            if (($stream['source'] ?? '') === 'keitaro_api' && isset($liveStats['streams'][(string)$stream['stream_id']])) {
                $bundle = $liveStats['streams'][(string)$stream['stream_id']];
                $stream['totals'] = $bundle['totals'] ?? emptyStreamStats();
                $offerStats = $bundle['offers'] ?? [];
                foreach ($allOffers as &$offer) {
                    $rowStats = $offerStats[(string)($offer['offer_id'] ?? '')] ?? emptyStreamStats();
                    $offer = array_merge($offer, $rowStats);
                }
                unset($offer);
            } elseif (($stream['source'] ?? '') !== 'geo') {
                $offerIds = array_map(fn($offer) => (string)$offer['offer_id'], $allOffers);
                $stats = loadStreamStats($db, $auth, $me, $allowedBmIds, $dateFrom, $dateTo, (string)$stream['stream_id'], array_unique($offerIds), (string)$stream['geo']);
                $totals = emptyStreamStats();
                foreach ($stats as $rowStats) {
                    addStreamStats($totals, $rowStats);
                }
                finishStreamStats($totals);
                $stream['totals'] = $totals;
            }
            addStreamStats($overall, $stream['totals']);
            $stream['offer_count'] = count($allOffers);
            $stream['active_offer_count'] = count(array_filter($allOffers, static fn(array $offer): bool => (int)($offer['share'] ?? 0) > 0));
            $stream['total_offer_count'] = count($allOffers);
            if (($stream['source'] ?? '') !== 'geo') {
                $stream['offers'] = [];
            } else {
                $stream['offers'] = array_values($allOffers);
            }
        }
        unset($stream);
        finishStreamStats($overall);

        apiOk([
            'installed' => true,
            'range' => $range,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'selected_stream_id' => '',
            'streams_synced_at' => max(array_map(static fn($s): string => (string)($s['synced_at'] ?? ''), $streams)),
            'totals' => $overall,
            'streams' => $streams,
        ]);
    }

    $selectedIndex = null;
    foreach ($streams as $i => $stream) {
        if ((string)$stream['stream_id'] === $requestedStreamId) {
            $selectedIndex = $i;
            break;
        }
    }
    $selectedIndex ??= 0;

    $selected = $streams[$selectedIndex];
    $allSelectedOffers = $selected['offers'] ?? loadDbStreamOffers($db, (string)$selected['stream_id']);
    $selected['total_offer_count'] = count($allSelectedOffers);
    $selected['active_offer_count'] = count(array_filter($allSelectedOffers, static fn(array $offer): bool => (int)($offer['share'] ?? 0) > 0));
    $selected['offers'] = $allSelectedOffers;

    if (($selected['source'] ?? '') === 'keitaro_api' && isset($liveStats['streams'][(string)$selected['stream_id']])) {
        $bundle = $liveStats['streams'][(string)$selected['stream_id']];
        $selected['totals'] = $bundle['totals'] ?? emptyStreamStats();
        $offerStats = $bundle['offers'] ?? [];
        foreach ($selected['offers'] as &$offer) {
            $rowStats = $offerStats[(string)($offer['offer_id'] ?? '')] ?? emptyStreamStats();
            $offer = array_merge($offer, $rowStats);
        }
        unset($offer);
        $selected['recommendation_meta'] = ['source' => 'keitaro_live'];
        $selected['totals_30d'] = $selected['totals'];
        $selected['totals_90d'] = $selected['totals'];
        $selected['ranking_periods'] = [];
    } elseif (($selected['source'] ?? '') !== 'geo') {
        $offerIds = array_map(fn($offer) => (string)$offer['offer_id'], $selected['offers']);
        $stats = loadStreamStats($db, $auth, $me, $allowedBmIds, $dateFrom, $dateTo, (string)$selected['stream_id'], array_unique($offerIds), (string)$selected['geo']);
        [$from7, $to7] = streamRollingDateRange(7, $me);
        [$from30, $to30] = streamRollingDateRange(30, $me);
        [$from90, $to90] = streamRollingDateRange(90, $me);
        $stats7 = loadStreamStats($db, $auth, $me, $allowedBmIds, $from7, $to7, (string)$selected['stream_id'], array_unique($offerIds), (string)$selected['geo']);
        $stats30 = loadStreamStats($db, $auth, $me, $allowedBmIds, $from30, $to30, (string)$selected['stream_id'], array_unique($offerIds), (string)$selected['geo']);
        $stats90 = loadStreamStats($db, $auth, $me, $allowedBmIds, $from90, $to90, (string)$selected['stream_id'], array_unique($offerIds), (string)$selected['geo']);
        $rankingPeriods = [
            'range' => ['label' => '7d', 'from' => $from7, 'to' => $to7, 'weight' => 0.6],
            '30d' => ['label' => '30d', 'from' => $from30, 'to' => $to30, 'weight' => 0.3],
            '90d' => ['label' => '90d', 'from' => $from90, 'to' => $to90, 'weight' => 0.1],
        ];
        $recommendations = StreamOfferBalancer::calculateRecommendedWeights($selected['offers'], $stats7, $stats30, $stats90, $rankingPeriods);

        $totals = emptyStreamStats();
        $totals30 = emptyStreamStats();
        $totals90 = emptyStreamStats();
        foreach ($selected['offers'] as &$offer) {
            $originalName = (string)($offer['offer_name'] ?? '');
            $originalNetwork = (string)($offer['affiliate_network'] ?? '');
            $rowStats = $stats[(string)$offer['offer_id']] ?? emptyStreamStats();
            $rowStats30 = $stats30[(string)$offer['offer_id']] ?? emptyStreamStats();
            $rowStats90 = $stats90[(string)$offer['offer_id']] ?? emptyStreamStats();
            $rec = $recommendations['offers'][(string)$offer['offer_id']] ?? StreamOfferBalancer::emptyRecommendation((int)($offer['share'] ?? 0));
            $offer = array_merge($offer, $rowStats, [
                'offer_name' => $rowStats['offer_name'] ?: $originalName,
                'affiliate_network' => $rowStats['affiliate_network'] ?: $originalNetwork,
                'stats_30d' => $rowStats30,
                'stats_90d' => $rowStats90,
                'recommendation' => $rec,
                'recommended_share' => $rec['recommended_share'],
                'recommended_weight' => $rec['raw_target'],
                'recommended_delta' => $rec['delta'],
                'recommendation_mode' => $rec['mode'],
                'recommendation_score' => $rec['score'],
                'epc_rank' => $rec['rank'],
                'rank_excluded' => $rec['rank_excluded'],
                'safe_epc' => $rec['safe_epc'],
                'safe_epc_quality' => $rec['safe_epc_quality'],
                'confidence' => $rec['confidence'],
                'rank_score' => $rec['score'],
                'rank_mode' => $rec['mode'],
                'rank_note' => $rec['reason'],
                'test_weight' => $rec['test_weight'],
                'weight_cap' => $rec['weight_cap'],
                'ranking_clicks' => $rec['ranking_clicks'],
                'ranking_periods' => $rec['periods'],
            ]);
            if (empty($offer['offer_name']) || str_starts_with($offer['offer_name'], 'Offer ')) {
                $offer['offer_name'] = $rowStats['offer_name'] ?: $offer['offer_name'];
            }
            if (empty($offer['affiliate_network']) && !empty($rowStats['affiliate_network'])) {
                $offer['affiliate_network'] = $rowStats['affiliate_network'];
            }
            addStreamStats($totals, $rowStats);
            addStreamStats($totals30, $rowStats30);
            addStreamStats($totals90, $rowStats90);
        }
        unset($offer);
        finishStreamStats($totals);
        finishStreamStats($totals30);
        finishStreamStats($totals90);
        $selected['recommendation_meta'] = $recommendations['meta'];
        usort($selected['offers'], fn($a, $b) => ((int)$b['share'] <=> (int)$a['share']) ?: ((float)$b['profit'] <=> (float)$a['profit']));
        $selected['totals'] = $totals;
        $selected['totals_30d'] = $totals30;
        $selected['totals_90d'] = $totals90;
        $selected['ranking_periods'] = $rankingPeriods;
    } else {
        $selected['recommendation_meta'] = ['source' => $selected['source'] ?? 'geo_fallback'];
        $selected['totals_30d'] = $selected['totals'];
        $selected['totals_90d'] = $selected['totals'];
        $selected['ranking_periods'] = [];
    }
    $streams[$selectedIndex] = $selected;

    foreach ($streams as $i => &$stream) {
        if ($i === $selectedIndex) {
            continue;
        }
        $stream['offers'] = [];
        $stream['totals'] = emptyStreamStats();
    }
    unset($stream);

    apiOk([
        'installed' => true,
        'range' => $range,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'selected_stream_id' => $selected['stream_id'],
        'streams_synced_at' => max(array_map(static fn($s): string => (string)($s['synced_at'] ?? ''), $streams)),
        'streams' => $streams,
    ]);
} catch (Throwable $e) {
    apiError(500, $e->getMessage());
}

function loadDbStreams(PDO $db, string $dateFrom, string $dateTo): array
{
    $streams = [];
    $seenIds = [];

    $exists = $db->query("SELECT to_regclass('public.keitaro_streams')")->fetchColumn();
    if ($exists) {
        $stmt = $db->query("
            SELECT id::text AS stream_id,
                   name AS stream_name,
                   state,
                   kt_campaign_id::text AS campaign_id,
                   kt_campaign_name AS campaign_name,
                   synced_at::text AS synced_at
            FROM public.keitaro_streams
            WHERE name ~* '^[A-Z]{2}\\s+.+$'
            ORDER BY upper(substr(name, 1, 2)), name
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['stream_name'] ?? '');
            if (!preg_match('/^([A-Z]{2})/i', $name, $m)) {
                continue;
            }
            $streamId = (string)($row['stream_id'] ?? '');
            if ($streamId === '') {
                continue;
            }
            $seenIds[$streamId] = true;
            $streams[] = [
                'stream_id' => $streamId,
                'stream_name' => $name,
                'geo' => strtoupper($m[1]),
                'state' => (string)($row['state'] ?? ''),
                'campaign_id' => (string)($row['campaign_id'] ?? ''),
                'campaign_name' => (string)($row['campaign_name'] ?? ''),
                'synced_at' => (string)($row['synced_at'] ?? ''),
                'offer_count' => 0,
                'active_offer_count' => 0,
                'total_offer_count' => 0,
                'offers' => [],
                'totals' => emptyStreamStats(),
            ];
        }
    }

    $exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
    if ($exists) {
        $stmt = $db->prepare("
            SELECT stream_id::text AS stream_id,
                   COALESCE(MAX(NULLIF(geo, '')), '') AS geo,
                   COUNT(DISTINCT offer_id) AS offer_count,
                   MAX(synced_at)::text AS synced_at
            FROM public.offer_insights_daily
            WHERE stream_id <> ''
              AND date BETWEEN :date_from AND :date_to
            GROUP BY stream_id
            ORDER BY upper(COALESCE(NULLIF(MAX(NULLIF(geo, '')), ''), 'XX')), stream_id
        ");
        $stmt->execute([
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $streamId = (string)($row['stream_id'] ?? '');
            if ($streamId === '' || isset($seenIds[$streamId])) {
                continue;
            }
            $geo = strtoupper(trim((string)($row['geo'] ?? '')));
            $geo = $geo !== '' ? $geo : 'XX';
            $streams[] = [
                'stream_id' => $streamId,
                'stream_name' => $geo . ' stream',
                'geo' => $geo,
                'state' => '',
                'campaign_id' => '',
                'campaign_name' => '',
                'synced_at' => (string)($row['synced_at'] ?? ''),
                'offer_count' => (int)($row['offer_count'] ?? 0),
                'active_offer_count' => 0,
                'total_offer_count' => (int)($row['offer_count'] ?? 0),
                'offers' => [],
                'totals' => emptyStreamStats(),
            ];
        }
    }

    usort($streams, fn($a, $b) => strcmp((string)$a['geo'], (string)$b['geo']) ?: strcmp((string)$a['stream_name'], (string)$b['stream_name']));

    return $streams;
}

function loadKeitaroLiveStreams(array $kt): array
{
    $url = rtrim((string)($kt['url'] ?? ''), '/');
    $key = (string)($kt['key'] ?? '');
    if ($url === '' || $key === '') {
        return [];
    }

    try {
        $campaigns = keitaroAdminRows($url, $key, 'campaigns', (bool)($kt['insecure_ssl'] ?? false));
    } catch (Throwable) {
        return [];
    }
    if (!$campaigns) {
        return [];
    }

    $streams = [];
    $seen = [];
    foreach ($campaigns as $campaign) {
        if (!is_array($campaign)) {
            continue;
        }
        $campaignId = keitaroText($campaign['id'] ?? $campaign['campaign_id'] ?? '');
        if ($campaignId === '') {
            continue;
        }
        $campaignState = strtolower(keitaroText($campaign['state'] ?? $campaign['status'] ?? ''));
        if ($campaignState === 'deleted') {
            continue;
        }
        $campaignName = keitaroText($campaign['name'] ?? $campaign['campaign_name'] ?? '') ?: ('Campaign ' . $campaignId);

        try {
            $campaignStreams = keitaroAdminRows($url, $key, "campaigns/{$campaignId}/streams", (bool)($kt['insecure_ssl'] ?? false));
        } catch (Throwable) {
            continue;
        }

        foreach ($campaignStreams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $streamId = keitaroText($stream['id'] ?? $stream['stream_id'] ?? '');
            $streamName = keitaroText($stream['name'] ?? $stream['stream_name'] ?? '');
            if ($streamId === '' || $streamName === '') {
                continue;
            }
            if (!preg_match('/^([A-Z]{2})\s+slots$/i', $streamName, $m)) {
                continue;
            }
            if (isset($seen[$streamId])) {
                continue;
            }

            $offers = keitaroStreamOffers($stream);
            $streams[] = [
                'stream_id' => $streamId,
                'stream_name' => $streamName,
                'geo' => strtoupper($m[1]),
                'source' => 'keitaro_api',
                'state' => keitaroText($stream['state'] ?? ''),
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'synced_at' => '',
                'offer_count' => count($offers),
                'active_offer_count' => count(array_filter($offers, static fn(array $offer): bool => (int)($offer['share'] ?? 0) > 0)),
                'total_offer_count' => count($offers),
                'offers' => $offers,
                'totals' => emptyStreamStats(),
            ];
            $seen[$streamId] = true;
        }
    }

    usort($streams, fn($a, $b) => strcmp((string)$a['geo'], (string)$b['geo']) ?: strcmp((string)$a['stream_name'], (string)$b['stream_name']));
    return $streams;
}

function loadKeitaroLiveStatsBundle(array $kt, string $dateFrom, string $dateTo, string $campaignId = '', string $streamId = ''): array
{
    $url = rtrim((string)($kt['url'] ?? ''), '/');
    $key = (string)($kt['key'] ?? '');
    if ($url === '' || $key === '') {
        return [];
    }

    $tz = (string)($kt['tz'] ?? 'Europe/Chisinau');
    $range = [
        'interval' => 'custom',
        'from' => $dateFrom . ' 00:00',
        'to' => $dateTo . ' 23:59',
        'timezone' => $tz,
    ];
    $filters = ['AND' => []];
    if ($streamId !== '') {
        $filters['AND'][] = ['name' => 'stream_id', 'operator' => 'EQUALS', 'expression' => (int)$streamId];
    } elseif ($campaignId !== '') {
        $filters['AND'][] = ['name' => 'campaign_id', 'operator' => 'EQUALS', 'expression' => (int)$campaignId];
    }
    $filters['AND'][] = ['OR' => [
        ['name' => 'campaign_unique_clicks', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'regs', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'deposits', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'revenue', 'operator' => 'GREATER_THAN', 'expression' => '0'],
    ]];

    $payload = [
        'range' => $range,
        'measures' => ['campaign_unique_clicks', 'regs', 'deposits', 'revenue'],
        'dimensions' => ['stream_id', 'offer_id', 'offer', 'affiliate_network', 'campaign_id', 'campaign'],
        'filters' => $filters,
        'sort' => [['name' => 'stream_id', 'order' => 'asc'], ['name' => 'offer_id', 'order' => 'asc']],
        'summary' => false,
        'extended' => true,
        'limit' => 100000,
        'offset' => 0,
    ];

    $ch = curl_init($url . '/admin_api/v1/report/build');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Api-Key: {$key}",
        ],
    ]);
    if (!empty($kt['insecure_ssl'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
        return [];
    }
    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        return [];
    }
    $rows = is_array($json['rows'] ?? null) ? $json['rows'] : [];
    $bundle = ['streams' => []];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = trim((string)($row['stream_id'] ?? ''));
        $oid = trim((string)($row['offer_id'] ?? ''));
        if ($sid === '' || $oid === '') {
            continue;
        }
        if (!isset($bundle['streams'][$sid])) {
            $bundle['streams'][$sid] = [
                'totals' => emptyStreamStats(),
                'offers' => [],
            ];
        }
        $rowStats = emptyStreamStats();
        $rowStats['clicks'] = 0;
        $rowStats['leads'] = (int)($row['campaign_unique_clicks'] ?? 0);
        $rowStats['regs'] = (int)($row['regs'] ?? 0);
        $rowStats['deps'] = (int)($row['deposits'] ?? 0);
        $rowStats['revenue'] = (float)($row['revenue'] ?? 0);
        finishStreamStats($rowStats);
        addStreamStats($bundle['streams'][$sid]['totals'], $rowStats);
        $bundle['streams'][$sid]['offers'][$oid] = array_merge($bundle['streams'][$sid]['offers'][$oid] ?? emptyStreamStats(), [
            'offer_id' => $oid,
            'offer_name' => keitaroText($row['offer'] ?? ''),
            'affiliate_network' => keitaroText($row['affiliate_network'] ?? ''),
            'clicks' => $rowStats['clicks'],
            'leads' => $rowStats['leads'],
            'regs' => $rowStats['regs'],
            'deps' => $rowStats['deps'],
            'revenue' => $rowStats['revenue'],
            'profit' => $rowStats['profit'],
            'roi' => $rowStats['roi'],
            'cpl' => $rowStats['cpl'],
            'cpr' => $rowStats['cpr'],
            'cpd' => $rowStats['cpd'],
        ]);
    }
    foreach ($bundle['streams'] as &$stream) {
        finishStreamStats($stream['totals']);
        $stream['offers'] = array_values($stream['offers']);
    }
    unset($stream);
    return $bundle;
}

function keitaroAdminRows(string $baseUrl, string $apiKey, string $endpoint, bool $insecureSsl = false): array
{
    $response = keitaroAdminRequest($baseUrl, $apiKey, $endpoint, $insecureSsl);
    foreach (['rows', 'data', 'items', 'campaigns', 'streams', 'offers'] as $key) {
        if (isset($response[$key]) && is_array($response[$key])) {
            return $response[$key];
        }
    }
    return array_is_list($response) ? $response : [];
}

function keitaroAdminRequest(string $baseUrl, string $apiKey, string $endpoint, bool $insecureSsl = false): array
{
    $url = rtrim($baseUrl, '/') . '/admin_api/v1/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Api-Key: {$apiKey}",
        ],
    ]);
    if ($insecureSsl) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($curlErr) {
        throw new RuntimeException($curlErr);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("HTTP {$httpCode}: " . substr((string)$response, 0, 300));
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON from Keitaro');
    }

    return $json;
}

function keitaroText(mixed $value): string
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

function keitaroStreamOffers(array $stream): array
{
    $offers = $stream['offers'] ?? [];
    if (!is_array($offers)) {
        return [];
    }

    $rows = [];
    foreach ($offers as $link) {
        if (!is_array($link)) {
            continue;
        }
        $offerId = keitaroText($link['offer_id'] ?? ($link['offer']['id'] ?? ''));
        if ($offerId === '') {
            continue;
        }
        $offerName = keitaroText($link['offer_name'] ?? $link['offer'] ?? $link['name'] ?? '');
        $network = keitaroText($link['affiliate_network'] ?? $link['affiliate_network_name'] ?? '');
        $rows[] = [
            'offer_id' => $offerId,
            'offer_name' => $offerName !== '' ? $offerName : 'Offer ' . $offerId,
            'affiliate_network' => $network,
            'share' => (int)($link['share'] ?? 0),
            'state' => keitaroText($link['state'] ?? ''),
        ];
    }

    return $rows;
}

function loadGeoStreams(PDO $db, string $dateFrom, string $dateTo): array
{
    $exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
    if (!$exists) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT geo,
               COUNT(DISTINCT offer_id) AS offer_count,
               MAX(synced_at)::text AS synced_at,
               COALESCE(MAX(NULLIF(kt_campaign_id, '')), '') AS campaign_id,
               COALESCE(MAX(NULLIF(kt_campaign_name, '')), '') AS campaign_name
        FROM public.offer_insights_daily
        WHERE date BETWEEN :date_from AND :date_to
          AND COALESCE(NULLIF(geo, ''), '') <> ''
        GROUP BY geo
        ORDER BY geo
    ");
    $stmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);

    $streams = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $geo = strtoupper(trim((string)($row['geo'] ?? '')));
        if ($geo === '') {
            continue;
        }
        $offers = loadGeoStreamOffers($db, $dateFrom, $dateTo, $geo);
        $totals = emptyStreamStats();
        foreach ($offers as &$offer) {
            addStreamStats($totals, $offer);
        }
        unset($offer);
        finishStreamStats($totals);

        $streams[] = [
            'stream_id' => $geo,
            'stream_name' => $geo . ' slots',
            'geo' => $geo,
            'source' => 'geo',
            'state' => 'active',
            'campaign_id' => (string)($row['campaign_id'] ?? ''),
            'campaign_name' => (string)($row['campaign_name'] ?? ''),
            'synced_at' => (string)($row['synced_at'] ?? ''),
            'offer_count' => count($offers),
            'active_offer_count' => count(array_filter($offers, static fn(array $offer): bool => (int)($offer['share'] ?? 0) > 0)),
            'total_offer_count' => count($offers),
            'offers' => $offers,
            'totals' => $totals,
        ];
    }

    return $streams;
}

function loadGeoStreamOffers(PDO $db, string $dateFrom, string $dateTo, string $geo): array
{
    $stmt = $db->prepare("
        SELECT offer_id::text AS offer_id,
               COALESCE(MAX(NULLIF(offer_name, '')), '') AS offer_name,
               COALESCE(MAX(NULLIF(affiliate_network, '')), '') AS affiliate_network,
               SUM(clicks) AS clicks,
               SUM(regs) AS regs,
               SUM(deps) AS deps,
               SUM(conversions) AS conversions,
               SUM(revenue) AS revenue,
               SUM(allocated_spend) AS spend
        FROM public.offer_insights_daily
        WHERE date BETWEEN :date_from AND :date_to
          AND UPPER(COALESCE(NULLIF(geo, ''), '')) = :geo
        GROUP BY offer_id
        ORDER BY SUM(revenue) DESC, SUM(clicks) DESC, offer_id
    ");
    $stmt->execute([
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
        ':geo' => strtoupper($geo),
    ]);

    $offers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $offer = emptyStreamStats();
        $offer['offer_id'] = (string)($row['offer_id'] ?? '');
        $offer['offer_name'] = (string)($row['offer_name'] ?? '');
        $offer['affiliate_network'] = (string)($row['affiliate_network'] ?? '');
        $offer['clicks'] = (int)($row['clicks'] ?? 0);
        $offer['leads'] = (int)($row['clicks'] ?? 0);
        $offer['regs'] = (int)($row['regs'] ?? 0);
        $offer['deps'] = (int)($row['deps'] ?? 0);
        $offer['conversions'] = (int)($row['conversions'] ?? 0);
        $offer['revenue'] = (float)($row['revenue'] ?? 0);
        $offer['spend'] = (float)($row['spend'] ?? 0);
        $offer['profit'] = $offer['revenue'] - $offer['spend'];
        finishStreamStats($offer);
        $offers[] = $offer;
    }

    return $offers;
}

function loadCampaignGeoStreams(PDO $db, string $dateFrom, string $dateTo, array $allowedBmIds, array $me): array
{
    if (!$allowedBmIds) {
        return [];
    }
    $bmPh = [];
    $bmParams = [];
    foreach (array_values($allowedBmIds) as $i => $bmId) {
        $key = ":bm_{$i}";
        $bmPh[] = $key;
        $bmParams[$key] = (string)$bmId;
    }
    $stmt = $db->prepare("
        WITH campaign_stats AS (
            SELECT
                a.campaign_id::text AS campaign_id,
                SUM(i.clicks) AS clicks,
                SUM(i.leads) AS leads,
                SUM(i.regs) AS regs,
                SUM(i.deps) AS deps,
                SUM(i.conversions) AS conversions,
                SUM(i.revenue) AS revenue,
                SUM(i.allocated_spend) AS spend
            FROM public.insights_daily i
            JOIN public.ads a ON a.id = i.ad_id
            JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
            WHERE i.date BETWEEN :date_from AND :date_to
              AND aa.bm_id IN (" . implode(',', $bmPh) . ")
            GROUP BY a.campaign_id
        )
        SELECT
            COALESCE(NULLIF(campaign_geo(c.name), ''), 'XX') AS geo,
            c.id::text AS campaign_id,
            c.name AS campaign_name,
            c.synced_at::text AS synced_at,
            COALESCE(SUM(cs.clicks), 0) AS clicks,
            COALESCE(SUM(cs.leads), 0) AS leads,
            COALESCE(SUM(cs.regs), 0) AS regs,
            COALESCE(SUM(cs.deps), 0) AS deps,
            COALESCE(SUM(cs.conversions), 0) AS conversions,
            COALESCE(SUM(cs.revenue), 0) AS revenue,
            COALESCE(SUM(cs.spend), 0) AS spend
        FROM public.campaigns c
        JOIN public.ad_accounts aa ON aa.id = c.ad_account_id
        LEFT JOIN campaign_stats cs ON cs.campaign_id = c.id::text
        WHERE c.status != 'DELETED'
          AND aa.bm_id IN (" . implode(',', $bmPh) . ")
        GROUP BY 1, c.id, c.name, c.synced_at
        ORDER BY 1, c.name
    ");
    $stmt->execute(array_merge([
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
    ], $bmParams));

    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $geo = strtoupper(trim((string)($row['geo'] ?? '')));
        if ($geo === '' || $geo === 'XX') {
            continue;
        }
        if (!isset($groups[$geo])) {
            $groups[$geo] = [
                'stream_id' => $geo,
                'stream_name' => $geo . ' slots',
                'geo' => $geo,
                'source' => 'campaigns',
                'state' => 'active',
                'campaign_id' => (string)($row['campaign_id'] ?? ''),
                'campaign_name' => (string)($row['campaign_name'] ?? ''),
                'synced_at' => (string)($row['synced_at'] ?? ''),
                'offer_count' => 0,
                'active_offer_count' => 0,
                'total_offer_count' => 0,
                'offers' => [],
                'totals' => emptyStreamStats(),
            ];
        }

        $rowStats = emptyStreamStats();
        $rowStats['clicks'] = (int)($row['clicks'] ?? 0);
        $rowStats['leads'] = (int)($row['leads'] ?? 0);
        $rowStats['regs'] = (int)($row['regs'] ?? 0);
        $rowStats['deps'] = (int)($row['deps'] ?? 0);
        $rowStats['conversions'] = (int)($row['conversions'] ?? 0);
        $rowStats['revenue'] = (float)($row['revenue'] ?? 0);
        $rowStats['spend'] = (float)($row['spend'] ?? 0);
        $rowStats['profit'] = $rowStats['revenue'] - $rowStats['spend'];
        finishStreamStats($rowStats);
        addStreamStats($groups[$geo]['totals'], $rowStats);

        $groups[$geo]['offers'][] = [
            'offer_id' => (string)($row['campaign_id'] ?? ''),
            'offer_name' => (string)($row['campaign_name'] ?? ''),
            'affiliate_network' => '',
            'share' => 0,
            'state' => 'active',
            'clicks' => $rowStats['clicks'],
            'leads' => $rowStats['leads'],
            'regs' => $rowStats['regs'],
            'deps' => $rowStats['deps'],
            'conversions' => $rowStats['conversions'],
            'revenue' => $rowStats['revenue'],
            'spend' => $rowStats['spend'],
            'profit' => $rowStats['profit'],
            'roi' => $rowStats['roi'],
            'cpl' => $rowStats['cpl'],
            'cpr' => $rowStats['cpr'],
            'cpd' => $rowStats['cpd'],
        ];
    }

    foreach ($groups as &$stream) {
        $stream['offer_count'] = count($stream['offers']);
        $stream['total_offer_count'] = count($stream['offers']);
        $stream['active_offer_count'] = 0;
        $stream['offers'] = array_values($stream['offers']);
        finishStreamStats($stream['totals']);
    }
    unset($stream);

    return array_values($groups);
}

function loadDbStreamOffers(PDO $db, string $streamId): array
{
    if ($streamId === '') {
        return [];
    }

    $stmt = $db->prepare("
        SELECT offer_id::text AS offer_id,
               offer_name,
               affiliate_network,
               share,
               state
        FROM public.keitaro_stream_offers
        WHERE stream_id::text = :stream_id
        ORDER BY share DESC, offer_name
    ");
    $stmt->execute([':stream_id' => $streamId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        return array_map(static fn($row): array => [
            'offer_id' => (string)($row['offer_id'] ?? ''),
            'offer_name' => (string)($row['offer_name'] ?? ''),
            'affiliate_network' => (string)($row['affiliate_network'] ?? ''),
            'share' => (int)($row['share'] ?? 0),
            'state' => (string)($row['state'] ?? ''),
        ], $rows);
    }

    $exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
    if (!$exists) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT offer_id::text AS offer_id,
               COALESCE(MAX(NULLIF(offer_name, '')), '') AS offer_name,
               COALESCE(MAX(NULLIF(affiliate_network, '')), '') AS affiliate_network,
               COUNT(*) AS hit_count
        FROM public.offer_insights_daily
        WHERE stream_id::text = :stream_id
        GROUP BY offer_id
        ORDER BY COUNT(*) DESC, MAX(NULLIF(offer_name, ''))
    ");
    $stmt->execute([':stream_id' => $streamId]);

    return array_map(static fn($row): array => [
        'offer_id' => (string)($row['offer_id'] ?? ''),
        'offer_name' => (string)($row['offer_name'] ?? ''),
        'affiliate_network' => (string)($row['affiliate_network'] ?? ''),
        'share' => 0,
        'state' => '',
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function streamDateRange(string $range, array $me): array
{
    $tz = appTimezoneName($me['display_tz'] ?? 'Europe/Chisinau');
    $tzObj = appDateTimeZone($tz);
    $now = new DateTime('now', $tzObj);
    switch ($range) {
        case 'today':
            $from = (clone $now)->modify('midnight');
            $to = $now;
            break;
        case 'yesterday':
            $from = (clone $now)->modify('yesterday midnight');
            $to = (clone $now)->modify('yesterday 23:59:59');
            break;
        case 'yesterday_today':
            $from = (clone $now)->modify('yesterday midnight');
            $to = $now;
            break;
        case '3d':
            $from = (clone $now)->modify('-2 days midnight');
            $to = $now;
            break;
        case '7d':
            $from = (clone $now)->modify('-6 days midnight');
            $to = $now;
            break;
        case '14d':
            $from = (clone $now)->modify('-13 days midnight');
            $to = $now;
            break;
        case 'this_week':
            $from = (clone $now)->modify('monday this week midnight');
            $to = $now;
            break;
        case 'this_month':
            $from = (clone $now)->modify('first day of this month midnight');
            $to = $now;
            break;
        case 'last_month':
            $from = (clone $now)->modify('first day of last month midnight');
            $to = (clone $now)->modify('last day of last month 23:59:59');
            break;
        case '90d':
            $from = (clone $now)->modify('-89 days midnight');
            $to = $now;
            break;
        case 'this_year':
            $from = (clone $now)->modify('first day of january this year midnight');
            $to = $now;
            break;
        case 'all':
            $from = new DateTime('2000-01-01 00:00:00', $tzObj);
            $to = $now;
            break;
        case '30d':
        default:
            $range = '30d';
            $from = (clone $now)->modify('-29 days midnight');
            $to = $now;
            break;
    }
    return [$from->format('Y-m-d'), $to->format('Y-m-d'), $range];
}

function streamRollingDateRange(int $days, array $me): array
{
    $tz = appTimezoneName($me['display_tz'] ?? 'Europe/Chisinau');
    $tzObj = appDateTimeZone($tz);
    $now = new DateTime('now', $tzObj);
    $from = (clone $now)->modify('-' . max(0, $days - 1) . ' days midnight');
    return [$from->format('Y-m-d'), $now->format('Y-m-d')];
}

function loadStreamStats(PDO $db, Auth $auth, array $me, array $allowedBmIds, string $dateFrom, string $dateTo, string $streamId, array $offerIds, string $streamGeo): array
{
    if ($streamId === '' || !$offerIds) {
        return [];
    }
    $exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
    if (!$exists) {
        return [];
    }
    $params = [':date_from' => $dateFrom, ':date_to' => $dateTo, ':stream_id' => $streamId];
    $where = ['oi.date BETWEEN :date_from AND :date_to'];
    $where[] = 'oi.stream_id::text = :stream_id';
    addInFilter($where, $params, 'oi.offer_id', 'offer', $offerIds);
    $streamGeo = strtoupper(trim($streamGeo));

    if (!empty($_GET['bm_id'])) {
        $bmId = trim((string)$_GET['bm_id']);
        if (!in_array($bmId, $allowedBmIds, true)) {
            apiError(403, 'BM is not allowed');
        }
        $where[] = 'aa.bm_id::text = :bm_filter';
        $params[':bm_filter'] = $bmId;
    } elseif (($me['role'] ?? '') !== 'admin') {
        addInFilter($where, $params, 'aa.bm_id::text', 'bm_allowed', $allowedBmIds);
    }

    $filterMap = [
        'account_id' => 'aa.id',
        'campaign_id' => 'oi.fb_campaign_id',
        'adset_id' => 'oi.fb_adset_id',
    ];
    foreach ($filterMap as $param => $column) {
        if (!empty($_GET[$param])) {
            $values = array_values(array_filter(array_map('trim', explode(',', (string)$_GET[$param]))));
            addInFilter($where, $params, $column, $param, $values);
        }
    }

    $stats = queryStreamStats($db, $where, $params);

    $geoTotals = loadStreamGeoFbTotals($db, $me, $allowedBmIds, $dateFrom, $dateTo, $streamGeo);
    applyStreamGeoTotals($stats, $geoTotals);

    return $stats;
}

function queryStreamStats(PDO $db, array $where, array $params): array
{
    $whereSql = implode(' AND ', $where);
    $metricSql = streamMetricSql();
    $stmt = $db->prepare("
        SELECT oi.offer_id::text AS offer_id,
               MAX(oi.offer_name) AS offer_name,
               MAX(oi.affiliate_network) AS affiliate_network,
               {$metricSql}
        FROM offer_insights_daily oi
        LEFT JOIN ads a ON a.id::text = oi.ad_id
        LEFT JOIN campaigns c ON c.id::text = COALESCE(NULLIF(oi.fb_campaign_id, ''), a.campaign_id::text)
        LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE {$whereSql}
        GROUP BY oi.offer_id
    ");
    $stmt->execute($params);

    $stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = normalizeStreamStats($row);
        $stats[(string)$row['offer_id']] = $row;
    }

    return $stats;
}

function addStreamGeoFilter(array &$where, array &$params, string $geo): void
{
    $params[':stream_geo_mid'] = '%\\_' . $geo . '\\_%';
    $params[':stream_geo_end'] = '%\\_' . $geo;
    $params[':stream_geo_space'] = '%\\_' . $geo . ' %';
    $where[] = "(
        COALESCE(NULLIF(oi.fb_campaign_name, ''), c.name, '') ILIKE :stream_geo_mid ESCAPE '\\'
        OR COALESCE(NULLIF(oi.fb_campaign_name, ''), c.name, '') ILIKE :stream_geo_end ESCAPE '\\'
        OR COALESCE(NULLIF(oi.fb_campaign_name, ''), c.name, '') ILIKE :stream_geo_space ESCAPE '\\'
    )";
}

function streamStatsHasData(array $row): bool
{
    foreach (['clicks', 'regs', 'deps', 'conversions', 'revenue'] as $key) {
        if ((float)($row[$key] ?? 0) != 0.0) {
            return true;
        }
    }
    return false;
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

function streamSqlList(PDO $db, array $values): string
{
    $quoted = [];
    foreach ($values as $value) {
        $quoted[] = $db->quote((string)$value);
    }
    return implode(',', $quoted);
}

function streamMetricSql(): string
{
    return "
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
    ";
}

function loadStreamGeoFbTotals(PDO $db, array $me, array $allowedBmIds, string $dateFrom, string $dateTo, string $streamGeo): array
{
    $geo = strtoupper(trim($streamGeo));
    if ($geo === '') {
        return ['spend' => 0.0, 'clicks' => 0];
    }

    $dateFromSql = $db->quote($dateFrom);
    $dateToSql = $db->quote($dateTo);
    $geoMidSql = $db->quote('%\\_' . $geo . '\\_%');
    $geoEndSql = $db->quote('%\\_' . $geo);
    $geoSpaceSql = $db->quote('%\\_' . $geo . ' %');
    $where = [
        "id.date BETWEEN {$dateFromSql} AND {$dateToSql}",
        "(c.name ILIKE {$geoMidSql} ESCAPE '\\' OR c.name ILIKE {$geoEndSql} ESCAPE '\\' OR c.name ILIKE {$geoSpaceSql} ESCAPE '\\')",
    ];

    if (!empty($_GET['bm_id'])) {
        $bmId = trim((string)$_GET['bm_id']);
        if (!in_array($bmId, $allowedBmIds, true)) {
            apiError(403, 'BM is not allowed');
        }
        $where[] = 'aa.bm_id::text = ' . $db->quote($bmId);
    } elseif (($me['role'] ?? '') !== 'admin') {
        if (!$allowedBmIds) {
            return ['spend' => 0.0, 'clicks' => 0];
        }
        $where[] = 'aa.bm_id::text IN (' . streamSqlList($db, $allowedBmIds) . ')';
    }

    $filterMap = [
        'account_id' => 'aa.id',
        'campaign_id' => 'c.id',
        'adset_id' => 'a.ad_set_id',
    ];
    foreach ($filterMap as $param => $column) {
        if (!empty($_GET[$param])) {
            $values = array_values(array_filter(array_map('trim', explode(',', (string)$_GET[$param]))));
            if (!$values) {
                $where[] = '1=0';
                continue;
            }
            $where[] = "{$column}::text IN (" . streamSqlList($db, $values) . ')';
        }
    }

    $stmt = $db->query("
        SELECT COALESCE(SUM(id.spend), 0) AS spend,
               COALESCE(SUM(id.clicks), 0) AS clicks
        FROM insights_daily id
        JOIN ads a ON a.id = id.ad_id
        JOIN campaigns c ON c.id = a.campaign_id
        JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE " . implode(' AND ', $where) . "
    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'spend' => round((float)($row['spend'] ?? 0), 4),
        'clicks' => (int)($row['clicks'] ?? 0),
    ];
}

function applyStreamGeoTotals(array &$stats, array $geoTotals): void
{
    $geoSpend = (float)($geoTotals['spend'] ?? 0);
    $geoClicks = (int)($geoTotals['clicks'] ?? 0);
    $totalLeads = 0;
    $totalAllocatedSpend = 0.0;
    foreach ($stats as $row) {
        $totalLeads += (int)($row['leads'] ?? 0);
        $totalAllocatedSpend += max(0.0, (float)($row['spend'] ?? 0));
    }

    $clickAlloc = [];
    if ($totalLeads > 0 && $geoClicks > 0) {
        $fractions = [];
        $allocated = 0;
        foreach ($stats as $key => $row) {
            $raw = $geoClicks * ((int)($row['leads'] ?? 0) / $totalLeads);
            $base = (int)floor($raw);
            $clickAlloc[$key] = $base;
            $fractions[$key] = $raw - $base;
            $allocated += $base;
        }
        arsort($fractions);
        $remaining = $geoClicks - $allocated;
        foreach (array_keys($fractions) as $key) {
            if ($remaining-- <= 0) {
                break;
            }
            $clickAlloc[$key]++;
        }
    }

    foreach ($stats as $key => &$row) {
        $leads = (int)($row['leads'] ?? 0);
        $allocatedSpend = max(0.0, (float)($row['spend'] ?? 0));
        if ($totalAllocatedSpend > 0) {
            $spend = round($geoSpend * ($allocatedSpend / $totalAllocatedSpend), 4);
        } elseif ($totalLeads > 0) {
            $spend = round($geoSpend * ($leads / $totalLeads), 4);
        } else {
            $spend = 0.0;
        }
        $revenue = (float)($row['revenue'] ?? 0);
        $regs = (int)($row['regs'] ?? 0);
        $deps = (int)($row['deps'] ?? 0);
        $profit = round($revenue - $spend, 4);

        $row['clicks'] = $clickAlloc[$key] ?? 0;
        $row['spend'] = $spend;
        $row['profit'] = $profit;
        $row['roi'] = $spend > 0 ? round($profit / $spend * 100, 4) : null;
        $row['cpl'] = $leads > 0 ? round($spend / $leads, 4) : null;
        $row['cpr'] = $regs > 0 ? round($spend / $regs, 4) : null;
        $row['cpd'] = $deps > 0 ? round($spend / $deps, 4) : null;
    }
    unset($row);
}

function emptyStreamStats(): array
{
    return [
        'offer_name' => '',
        'affiliate_network' => '',
        'clicks' => 0,
        'leads' => 0,
        'regs' => 0,
        'deps' => 0,
        'conversions' => 0,
        'revenue' => 0.0,
        'spend' => 0.0,
        'profit' => 0.0,
        'roi' => null,
        'cpl' => null,
        'cpr' => null,
        'cpd' => null,
    ];
}

function normalizeStreamStats(array $row): array
{
    foreach (['clicks', 'leads', 'regs', 'deps', 'conversions'] as $key) {
        $row[$key] = (int)($row[$key] ?? 0);
    }
    foreach (['revenue', 'spend', 'profit', 'roi', 'cpl', 'cpr', 'cpd'] as $key) {
        $row[$key] = !array_key_exists($key, $row) || $row[$key] === null ? null : round((float)$row[$key], 4);
    }
    return array_merge(emptyStreamStats(), $row);
}

function addStreamStats(array &$total, array $row): void
{
    foreach (['clicks', 'leads', 'regs', 'deps', 'conversions'] as $key) {
        $total[$key] += (int)($row[$key] ?? 0);
    }
    foreach (['revenue', 'spend', 'profit'] as $key) {
        $total[$key] += (float)($row[$key] ?? 0);
    }
}

function finishStreamStats(array &$row): void
{
    $row['revenue'] = round((float)$row['revenue'], 4);
    $row['spend'] = round((float)$row['spend'], 4);
    $row['profit'] = round((float)$row['profit'], 4);
    $row['roi'] = $row['spend'] > 0 ? round($row['profit'] / $row['spend'] * 100, 4) : null;
    $row['cpl'] = $row['leads'] > 0 ? round($row['spend'] / $row['leads'], 4) : null;
    $row['cpr'] = $row['regs'] > 0 ? round($row['spend'] / $row['regs'], 4) : null;
    $row['cpd'] = $row['deps'] > 0 ? round($row['spend'] / $row['deps'], 4) : null;
}
