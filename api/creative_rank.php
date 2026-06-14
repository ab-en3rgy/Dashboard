<?php
// GET /api/creative_rank.php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CreativeGeoRank.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) {
    apiOk([
        'rank_map' => [],
        'periods' => [],
        'model' => 'creative_geo_rank_v2',
    ]);
}

$bmFilter = trim((string)($_GET['bm_id'] ?? ''));
$accountId = normalizeCreativeRankAccountId((string)($_GET['account_id'] ?? ''));
$accountScope = trim((string)($_GET['account_scope'] ?? ''));
$campaignIds = creativeRankCsv($_GET['campaign_id'] ?? null);
$adsetIds = creativeRankCsv($_GET['adset_id'] ?? null);
$adName = trim((string)($_GET['ad_name'] ?? ''));
$geoList = array_values(array_filter(array_map(
    static fn(string $geo): string => strtoupper(trim($geo)),
    explode(',', (string)($_GET['geo'] ?? ''))
)));
$effectiveStatus = trim((string)($_GET['effective_status'] ?? ''));
$campaignStatus = trim((string)($_GET['campaign_status'] ?? ''));
$adsetStatus = trim((string)($_GET['adset_status'] ?? ''));
$accountStatusRaw = trim((string)($_GET['account_status'] ?? ''));
$accountStatus = match ($accountStatusRaw) {
    'ACTIVE' => 'ACTIVE',
    'PAUSED' => 'PAUSED',
    default => '',
};

$period3 = creativeRankWindow($me, 3);
$period30 = creativeRankWindow($me, 30);

$stats3 = fetchCreativeRankWindowStats(
    $db,
    $bmIds,
    $bmFilter,
    $accountId,
    $accountScope,
    $campaignIds,
    $adsetIds,
    $adName,
    $geoList,
    $campaignStatus,
    $adsetStatus,
    $effectiveStatus,
    $accountStatus,
    $period3
);
$stats30 = fetchCreativeRankWindowStats(
    $db,
    $bmIds,
    $bmFilter,
    $accountId,
    $accountScope,
    $campaignIds,
    $adsetIds,
    $adName,
    $geoList,
    $campaignStatus,
    $adsetStatus,
    $effectiveStatus,
    $accountStatus,
    $period30
);

apiOk([
    'rank_map' => rankCreativeGeoStats($stats3, $stats30),
    'periods' => [
        '3d' => $period3,
        '30d' => $period30,
    ],
    'model' => 'creative_geo_rank_v2',
]);

function creativeRankCsv(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
}

function normalizeCreativeRankAccountId(string $accountId): string
{
    $accountId = trim($accountId);
    if ($accountId !== '' && preg_match('/^\d+$/', $accountId)) {
        return 'act_' . $accountId;
    }
    return $accountId;
}

function creativeRankWindow(array $me, int $days): array
{
    $tz = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
    $tzObj = appDateTimeZone($tz);
    $now = new DateTime('now', $tzObj);
    $from = (clone $now)->modify('-' . max(0, $days - 1) . ' days midnight');
    return [
        'from' => $from->format('Y-m-d'),
        'to' => $now->format('Y-m-d'),
        'label' => $days . 'd',
    ];
}

function creativeRankStatusSql(string $alias, string $paramBase, string $value): array
{
    if ($value === 'ACTIVE') {
        return [
            " AND {$alias}.status = :{$paramBase}_status_active AND {$alias}.effective_status = :{$paramBase}_effective_active",
            [
                ":{$paramBase}_status_active" => 'ACTIVE',
                ":{$paramBase}_effective_active" => 'ACTIVE',
            ],
        ];
    }
    return [
        " AND ({$alias}.status = :{$paramBase}_status OR {$alias}.effective_status = :{$paramBase}_effective)",
        [
            ":{$paramBase}_status" => $value,
            ":{$paramBase}_effective" => $value,
        ],
    ];
}

function fetchCreativeRankWindowStats(
    PDO $db,
    array $bmIds,
    string $bmFilter,
    string $accountId,
    string $accountScope,
    array $campaignIds,
    array $adsetIds,
    string $adName,
    array $geoList,
    string $campaignStatus,
    string $adsetStatus,
    string $effectiveStatus,
    string $accountStatus,
    array $window
): array {
    $bmParams = [];
    $bmPh = [];
    foreach ($bmIds as $i => $bmId) {
        $key = ":bm_{$i}";
        $bmPh[] = $key;
        $bmParams[$key] = (string)$bmId;
    }

    $where = "
        AND aa.bm_id IN (" . implode(',', $bmPh) . ")
        AND campaign_geo(c.name) <> 'XX'
        AND COALESCE(a.status, '') != 'DELETED'
        AND COALESCE(s.status, '') != 'DELETED'
        AND COALESCE(c.status, '') != 'DELETED'
        AND a.name IS NOT NULL
        AND a.name <> ''
    ";
    $params = $bmParams + [
        ':date_from' => $window['from'],
        ':date_to' => $window['to'],
    ];

    if ($bmFilter !== '') {
        $where .= ' AND bm.id::text = :bm_filter';
        $params[':bm_filter'] = $bmFilter;
    }
    if ($accountId !== '') {
        $where .= ' AND aa.id = :account_id';
        $params[':account_id'] = $accountId;
    } elseif ($accountScope === 'active') {
        $where .= ' AND aa.status = 1';
    }
    if ($campaignIds) {
        $ph = [];
        foreach ($campaignIds as $i => $campaignId) {
            $key = ":campaign_id_{$i}";
            $ph[] = $key;
            $params[$key] = $campaignId;
        }
        $where .= ' AND a.campaign_id IN (' . implode(',', $ph) . ')';
    }
    if ($adsetIds) {
        $ph = [];
        foreach ($adsetIds as $i => $adsetId) {
            $key = ":adset_id_{$i}";
            $ph[] = $key;
            $params[$key] = $adsetId;
        }
        $where .= ' AND a.ad_set_id IN (' . implode(',', $ph) . ')';
    }
    if ($adName !== '') {
        $where .= ' AND a.name = :ad_name';
        $params[':ad_name'] = $adName;
    }
    if ($geoList) {
        $ph = [];
        foreach ($geoList as $i => $geo) {
            $key = ":geo_{$i}";
            $ph[] = $key;
            $params[$key] = $geo;
        }
        $where .= " AND campaign_geo(c.name) IN (" . implode(',', $ph) . ')';
    }
    if ($campaignStatus !== '') {
        [$sqlStatus, $statusParams] = creativeRankStatusSql('c', 'campaign', $campaignStatus);
        $where .= $sqlStatus;
        $params += $statusParams;
    }
    if ($adsetStatus !== '') {
        [$sqlStatus, $statusParams] = creativeRankStatusSql('s', 'adset', $adsetStatus);
        $where .= $sqlStatus;
        $params += $statusParams;
    }
    if ($effectiveStatus !== '') {
        [$sqlStatus, $statusParams] = creativeRankStatusSql('a', 'effective', $effectiveStatus);
        $where .= $sqlStatus;
        $params += $statusParams;
    }
    if ($effectiveStatus === 'ACTIVE' || $accountStatus === 'ACTIVE') {
        $where .= ' AND aa.status = 1';
    } elseif ($accountStatus === 'PAUSED') {
        $where .= ' AND aa.status != 1';
    }

    $stmt = $db->prepare("
        SELECT
            campaign_geo(c.name) AS geo,
            a.name::text AS creative_name,
            COUNT(DISTINCT a.id) AS ads_count,
            MAX(i.date)::text AS last_seen,
            COALESCE(SUM(i.impressions), 0) AS impressions,
            COALESCE(SUM(i.clicks), 0) AS clicks,
            COALESCE(SUM(i.spend), 0) AS spend,
            COALESCE(SUM(i.delta), 0) AS delta,
            COALESCE(SUM(i.leads), 0) AS leads,
            COALESCE(SUM(i.regs), 0) AS regs,
            COALESCE(SUM(i.deps), 0) AS deps,
            COALESCE(SUM(i.revenue), 0) AS revenue
        FROM public.insights_daily i
        JOIN public.ads a ON a.id = i.ad_id
        JOIN public.ad_sets s ON s.id = a.ad_set_id
        JOIN public.campaigns c ON c.id = a.campaign_id
        JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
        JOIN public.business_managers bm ON bm.id = aa.bm_id
        WHERE i.date >= :date_from
          AND i.date <= :date_to
          {$where}
        GROUP BY campaign_geo(c.name), a.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $geo = (string)($row['geo'] ?? 'XX');
        $name = (string)($row['creative_name'] ?? '');
        if ($name === '') {
            continue;
        }
        $key = $geo . '||' . $name;
        $out[$key] = [
            'geo' => $geo,
            'name' => $name,
            'ads_count' => (int)($row['ads_count'] ?? 0),
            'last_seen' => (string)($row['last_seen'] ?? ''),
            'stats' => creativeGeoFinalizeStats([
                'spend' => (float)($row['spend'] ?? 0),
                'delta' => (float)($row['delta'] ?? 0),
                'impressions' => (int)($row['impressions'] ?? 0),
                'clicks' => (int)($row['clicks'] ?? 0),
                'leads' => (int)($row['leads'] ?? 0),
                'regs' => (int)($row['regs'] ?? 0),
                'deps' => (int)($row['deps'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
            ]),
        ];
    }

    return $out;
}
