<?php
// api/creative_calendar.php
// @version 1.0.2
// GET /api/creative_calendar.php

require __DIR__.'/_bootstrap.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk(['geos' => [], 'rows' => []]);

$bmParams = [];
$bmPh = [];
foreach ($bmIds as $i => $v) {
    $k = ":bm_{$i}";
    $bmPh[] = $k;
    $bmParams[$k] = $v;
}
$bmInSql = '(' . implode(',', $bmPh) . ')';

$params = $bmParams;

$baseCte = "
    WITH base AS (
        SELECT
            campaign_geo(c.name) AS geo,
            a.name::text AS creative_name,
            a.id::text AS ad_id,
            i.date,
            i.impressions,
            i.clicks,
            i.spend,
            i.delta,
            i.leads,
            i.regs,
            i.deps,
            i.revenue
        FROM insights_daily i
        JOIN ads a ON a.id = i.ad_id
        JOIN ad_sets s ON s.id = a.ad_set_id
        JOIN campaigns c ON c.id = a.campaign_id
        JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE aa.bm_id IN {$bmInSql}
          AND aa.status = 1
          AND COALESCE(a.status, '') != 'DELETED'
          AND COALESCE(s.status, '') != 'DELETED'
          AND COALESCE(c.status, '') != 'DELETED'
          AND a.name IS NOT NULL
          AND a.name <> ''
    ),
    clean AS (
        SELECT *
        FROM base
        WHERE geo ~ '^[A-Z]{2}$'
    )
";

try {
    $geoStmt = $db->prepare($baseCte . "
        SELECT geo, MAX(date)::text AS last_seen, COUNT(DISTINCT creative_name) AS creatives
        FROM clean
        GROUP BY geo
        ORDER BY geo
    ");
    $geoStmt->execute($params);
    $geos = array_map(static fn(array $r): array => [
        'geo' => (string)$r['geo'],
        'last_seen' => (string)$r['last_seen'],
        'creatives' => (int)$r['creatives'],
    ], $geoStmt->fetchAll());

    $stmt = $db->prepare($baseCte . "
        SELECT
            geo,
            creative_name,
            MIN(date)::text AS first_seen,
            MAX(date)::text AS last_seen,
            COUNT(DISTINCT ad_id) AS ads_count,
            SUM(impressions) AS impressions,
            SUM(clicks) AS clicks,
            SUM(spend) AS spend,
            SUM(delta) AS delta,
            SUM(leads) AS leads,
            SUM(regs) AS regs,
            SUM(deps) AS deps,
            SUM(revenue) AS revenue
        FROM clean
        GROUP BY geo, creative_name
        ORDER BY MIN(date) DESC, SUM(spend) DESC, creative_name ASC
    ");
    $stmt->execute($params);
    $rawRows = $stmt->fetchAll();
} catch (Throwable $e) {
    apiError(500, 'DB error: ' . $e->getMessage());
}

$rows = array_map(static function(array $r): array {
    $spend = (float)$r['spend'];
    $delta = (float)$r['delta'];
    $impressions = (int)$r['impressions'];
    $clicks = (int)$r['clicks'];
    $leads = (int)$r['leads'];
    $regs = (int)$r['regs'];
    $deps = (int)$r['deps'];
    $revenue = (float)$r['revenue'];
    $profit = $revenue - $spend;

    return [
        'geo' => (string)$r['geo'],
        'creative_name' => (string)$r['creative_name'],
        'first_seen' => (string)$r['first_seen'],
        'last_seen' => (string)$r['last_seen'],
        'ads_count' => (int)$r['ads_count'],
        'impressions' => $impressions,
        'clicks' => $clicks,
        'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : 0,
        'delta' => round($delta, 4),
        'leads' => $leads,
        'cpl' => $leads > 0 ? round($spend / $leads, 4) : 0,
        'regs' => $regs,
        'cpr' => $regs > 0 ? round($spend / $regs, 4) : 0,
        'deps' => $deps,
        'cpd' => $deps > 0 ? round($spend / $deps, 4) : 0,
        'spend' => round($spend, 4),
        'revenue' => round($revenue, 4),
        'profit' => round($profit, 4),
        'roi' => $spend > 0 ? round($profit / $spend * 100, 4) : 0,
        'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 4) : 0,
        'cpm' => $impressions > 0 ? round($spend / $impressions * 1000, 4) : 0,
    ];
}, $rawRows);

apiOk([
    'geos' => $geos,
    'rows' => $rows,
], ['count' => count($rows)]);
