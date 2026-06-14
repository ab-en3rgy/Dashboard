<?php
// @version 1.0.3
// GET /api/streams.php?range=30d

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/StreamOfferBalancer.php';

try {
    $exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
    if (!$exists) {
        apiOk(['streams' => [], 'installed' => false]);
    }

    [$dateFrom, $dateTo, $range] = streamDateRange($_GET['range'] ?? '30d', $me);
    $allowedBmIds = array_map('strval', $auth->allowedBmIds($me));
    if (!$allowedBmIds) {
        apiOk(['streams' => [], 'installed' => true, 'range' => $range, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    $streams = [];
    if (empty($_GET['bm_id'])) {
        $streams = loadGeoStreams($db, $dateFrom, $dateTo);
    }
    if (!$streams) {
        $streams = loadDbStreams($db, $dateFrom, $dateTo);
    }

    if (!$streams) {
        apiOk(['streams' => [], 'installed' => true, 'range' => $range, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    usort($streams, fn($a, $b) => strcmp((string)$a['geo'], (string)$b['geo']) ?: strcmp((string)$a['stream_name'], (string)$b['stream_name']));

    $requestedStreamId = trim((string)($_GET['stream_id'] ?? ''));
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
            if (!in_array(($stream['source'] ?? ''), ['keitaro', 'geo'], true)) {
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
            if (!in_array(($stream['source'] ?? ''), ['keitaro', 'geo'], true)) {
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

    if (!in_array(($selected['source'] ?? ''), ['keitaro', 'geo'], true)) {
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

    $params = [
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
        ':geo_mid' => '%\\_' . $geo . '\\_%',
        ':geo_end' => '%\\_' . $geo,
        ':geo_space' => '%\\_' . $geo . ' %',
    ];
    $where = [
        'id.date BETWEEN :date_from AND :date_to',
        "(c.name ILIKE :geo_mid ESCAPE '\\' OR c.name ILIKE :geo_end ESCAPE '\\' OR c.name ILIKE :geo_space ESCAPE '\\')",
    ];

    if (!empty($_GET['bm_id'])) {
        $bmId = trim((string)$_GET['bm_id']);
        if (!in_array($bmId, $allowedBmIds, true)) {
            apiError(403, 'BM is not allowed');
        }
        $where[] = 'aa.bm_id::text = :geo_bm_filter';
        $params[':geo_bm_filter'] = $bmId;
    } elseif (($me['role'] ?? '') !== 'admin') {
        addInFilter($where, $params, 'aa.bm_id::text', 'geo_bm_allowed', $allowedBmIds);
    }

    $filterMap = [
        'account_id' => 'aa.id',
        'campaign_id' => 'c.id',
        'adset_id' => 'a.ad_set_id',
    ];
    foreach ($filterMap as $param => $column) {
        if (!empty($_GET[$param])) {
            $values = array_values(array_filter(array_map('trim', explode(',', (string)$_GET[$param]))));
            addInFilter($where, $params, $column, 'geo_' . $param, $values);
        }
    }

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(id.spend), 0) AS spend,
               COALESCE(SUM(id.clicks), 0) AS clicks
        FROM insights_daily id
        JOIN ads a ON a.id = id.ad_id
        JOIN campaigns c ON c.id = a.campaign_id
        JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);

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
