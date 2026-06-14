<?php
// api/geocabs.php — Geo&Accounts report
// Returns:
// {
//   accounts: [ { id, name, profit_30d } ],   -- only active (status=1), sorted by profit_30d desc
//   geos:     ["AR","DE",...],                 -- sorted by total profit_30d desc
//   cells: {  "account_id:GEO": { active, total } }
// }

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/BmOptions.php';

$allBmIds = $auth->allowedBmIds($me);
if (!$allBmIds) apiOk(['accounts'=>[],'geos'=>[],'cells'=>[],'bms'=>[]]);
$selectorBms = bmSelectorOptions($db, $allBmIds);
$bmIds = $allBmIds;

// Optional filter for a specific BM
$bmFilter = $_GET['bm_id'] ?? null;
if ($bmFilter && in_array((int)$bmFilter, array_map('intval', $bmIds))) {
    $bmIds = [(int)$bmFilter];
}

// Build IN list for BM
$bmInSql  = '(' . implode(',', array_fill(0, count($bmIds), '?')) . ')';
$bmParams = array_values($bmIds);

$tz    = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
$tzObj = appDateTimeZone($tz);
$now   = new DateTime('now', $tzObj);
$dtFrom = (clone $now)->modify('-29 days midnight');
$dateFrom = $dtFrom->format('Y-m-d');
$dateTo   = $now->format('Y-m-d');

$campaignStatsCte = "
    WITH stats_structured AS (
        SELECT
            a.campaign_id::text AS campaign_id,
            SUM(i.spend)        AS spend,
            SUM(i.revenue)      AS revenue
        FROM insights_daily i
        JOIN ads a ON a.id = i.ad_id
        LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE i.date BETWEEN ? AND ?
          AND (aa.bm_id IN $bmInSql OR aa.bm_id IS NULL)
          AND a.campaign_id IS NOT NULL
        GROUP BY a.campaign_id
    ),
    stats_orphan AS (
        SELECT
            i.sub_id_10::text AS campaign_id,
            SUM(i.spend)      AS spend,
            SUM(i.revenue)    AS revenue
        FROM insights_daily i
        LEFT JOIN ads a ON a.id = i.ad_id
        WHERE a.id IS NULL
          AND i.sub_id_10 IS NOT NULL
          AND i.sub_id_10 != ''
          AND i.sub_id_10 NOT LIKE '{%}'
          AND i.date BETWEEN ? AND ?
        GROUP BY i.sub_id_10
    ),
    campaign_stats AS (
        SELECT
            campaign_id,
            SUM(spend)   AS spend,
            SUM(revenue) AS revenue
        FROM (
            SELECT * FROM stats_structured
            UNION ALL
            SELECT * FROM stats_orphan
        ) x
        GROUP BY campaign_id
    )
";
$campaignStatsParams = array_merge([$dateFrom, $dateTo], $bmParams, [$dateFrom, $dateTo]);

// ── 1. 30d profit per account (active only) ──
$sql = $campaignStatsCte . "
    SELECT
        aa.id          AS account_id,
        aa.name        AS account_name,
        bm.name        AS bm_name,
        COALESCE(SUM(cs.revenue),0) - COALESCE(SUM(cs.spend),0) AS profit_30d
    FROM ad_accounts aa
    JOIN business_managers bm ON bm.id = aa.bm_id
    LEFT JOIN campaigns c     ON c.ad_account_id = aa.id AND c.status != 'DELETED'
    LEFT JOIN campaign_stats cs ON cs.campaign_id = c.id::text
    WHERE aa.status = 1
      AND aa.bm_id IN $bmInSql
    GROUP BY aa.id, aa.name, bm.name
    ORDER BY profit_30d DESC
";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge($campaignStatsParams, $bmParams));
$accounts = $stmt->fetchAll();

if (!$accounts) {
    apiOk(['accounts'=>[],'geos'=>[],'cells'=>[],'bms'=>$selectorBms]);
}

$accountIds = array_column($accounts, 'account_id');

// ── 2. Campaigns: active/total for each account+geo ──
// Geo is extracted from the campaign name: split('_')[1]
$inPh = implode(',', array_fill(0, count($accountIds), '?'));

$sql2 = "
    SELECT
        c.ad_account_id                                            AS account_id,
        campaign_geo(c.name)                                      AS geo,
        COUNT(*)                                                   AS total,
        COUNT(*) FILTER (
            WHERE c.status = 'ACTIVE'
              AND COALESCE(NULLIF(c.effective_status, ''), 'ACTIVE') = 'ACTIVE'
        ) AS active
    FROM campaigns c
    WHERE c.status != 'DELETED'
      AND c.ad_account_id IN ($inPh)
      AND campaign_geo(c.name) <> 'XX'
    GROUP BY c.ad_account_id, campaign_geo(c.name)
";
$stmt2 = $db->prepare($sql2);
$stmt2->execute($accountIds);
$campRows = $stmt2->fetchAll();

// ── 3. 30d profit by geo (aggregated across all accounts) ──
$sql3 = $campaignStatsCte . "
    SELECT
        campaign_geo(c.name)                                          AS geo,
        COALESCE(SUM(cs.revenue),0) - COALESCE(SUM(cs.spend),0)        AS profit_30d
    FROM campaigns c
    JOIN ad_accounts aa ON aa.id = c.ad_account_id
    LEFT JOIN campaign_stats cs ON cs.campaign_id = c.id::text
    WHERE c.status != 'DELETED'
      AND aa.bm_id IN $bmInSql
      AND campaign_geo(c.name) <> 'XX'
    GROUP BY campaign_geo(c.name)
    ORDER BY profit_30d DESC, geo ASC
";
$stmt3 = $db->prepare($sql3);
$stmt3->execute(array_merge($campaignStatsParams, $bmParams));
$geoRows = $stmt3->fetchAll();

// ── Build the response ──────────────────────────────────────────
$cells = [];
foreach ($campRows as $r) {
    $key = $r['account_id'] . ':' . strtoupper($r['geo']);
    $cells[$key] = [
        'active' => (int)$r['active'],
        'total'  => (int)$r['total'],
    ];
}

$geos = array_map(fn($r) => $r['geo'], $geoRows);
$geoProfit30d = [];
foreach ($geoRows as $r) {
    $geoProfit30d[$r['geo']] = round((float)$r['profit_30d'], 2);
}
$geosWithCells = array_fill_keys(array_map(fn($r) => $r['geo'], $campRows), true);
$geos = array_values(array_filter($geos, fn($geo) => isset($geosWithCells[$geo])));
foreach (array_keys($geosWithCells) as $geo) {
    if (!in_array($geo, $geos, true)) $geos[] = $geo;
}

$accountsOut = array_map(fn($a) => [
    'id'        => $a['account_id'],
    'name'      => $a['account_name'],
    'bm_name'   => $a['bm_name'],
    'profit_30d'=> round((float)$a['profit_30d'], 2),
], $accounts);

// List all available BMs for selector
apiOk([
    'accounts' => $accountsOut,
    'geos'     => $geos,
    'geo_profit_30d' => $geoProfit30d,
    'cells'    => $cells,
    'bms'      => $selectorBms,
    'date_from'=> $dateFrom,
    'date_to'  => $dateTo,
]);
