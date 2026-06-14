<?php
// api/accounts.php
// @version 1.0.1
require __DIR__.'/_bootstrap.php';
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS disabled_date DATE");

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk([]);

$tz    = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
$range = $_GET['range'] ?? 'today';

function buildAccountsGeoFilter(string $geoParam, string $campField = 'c.name', string $prefix = 'geo'): array {
    $geos = array_filter(array_map('trim', explode(',', strtoupper($geoParam))));
    if (!$geos) return ['', []];

    $conds = [];
    $params = [];
    foreach ($geos as $i => $geo) {
        if (!preg_match('/^[A-Z]{2}$/', $geo)) continue;
        $p0 = ":{$prefix}_{$i}_0";
        $conds[] = "campaign_geo({$campField}) = {$p0}";
        $params[$p0] = $geo;
    }

    return $conds ? [' AND (' . implode(' OR ', $conds) . ')', $params] : ['', []];
}

$tzObj = appDateTimeZone($tz);
$now   = new DateTime('now', $tzObj);
switch ($range) {
    case 'yesterday':   $dtFrom=(clone $now)->modify('yesterday midnight');$dtTo=(clone $now)->modify('yesterday 23:59:59');break;
    case 'yesterday_today': $dtFrom=(clone $now)->modify('yesterday midnight');$dtTo=$now;break;
    case '3d':          $dtFrom=(clone $now)->modify('-2 days midnight');$dtTo=$now;break;
    case '7d':          $dtFrom=(clone $now)->modify('-6 days midnight');$dtTo=$now;break;
    case '14d':         $dtFrom=(clone $now)->modify('-13 days midnight');$dtTo=$now;break;
    case 'this_week':   $dtFrom=(clone $now)->modify('monday this week midnight');$dtTo=$now;break;
    case 'this_month':  $dtFrom=(clone $now)->modify('first day of this month midnight');$dtTo=$now;break;
    case 'last_month':  $dtFrom=(clone $now)->modify('first day of last month midnight');$dtTo=(clone $now)->modify('last day of last month 23:59:59');break;
    case '30d':         $dtFrom=(clone $now)->modify('-29 days midnight');$dtTo=$now;break;
    case '90d':         $dtFrom=(clone $now)->modify('-89 days midnight');$dtTo=$now;break;
    case 'this_year':   $dtFrom=(clone $now)->modify('first day of january this year midnight');$dtTo=$now;break;
    case 'all':         $dtFrom=new DateTime('2020-01-01', $tzObj);$dtTo=$now;break;
    default:            $dtFrom=(clone $now)->modify('midnight');$dtTo=$now;
}
$dateFrom = $dtFrom->format('Y-m-d');
$dateTo   = $dtTo->format('Y-m-d');

[$geoCampSql, $geoCampParams] = buildAccountsGeoFilter($_GET['geo'] ?? '', 'c.name', 'geo_camp');
[$geoStatsSql, $geoStatsParams] = buildAccountsGeoFilter($_GET['geo'] ?? '', 'c.name', 'geo_stats');
[$geoOrphanSql, $geoOrphanParams] = buildAccountsGeoFilter($_GET['geo'] ?? '', 'id.sub_id_11', 'geo_orphan');

// IN list for bm_ids
$inPh = []; $inPnamed = [];
foreach ($bmIds as $i => $v) { $k=":bm_{$i}"; $inPh[]=$k; $inPnamed[$k]=$v; }
$inSql = '('.implode(',', $inPh).')';

// Additional filter for specific BMs (from request)
$filterBmSql = '';
if (!empty($_GET['bm_id'])) {
    $reqBmIds = array_filter(array_map('trim', explode(',', $_GET['bm_id'])));
    // Intersect with allowedBmIds so foreign BMs cannot be requested
    $reqBmIds = array_values(array_intersect($reqBmIds, $bmIds));
    if ($reqBmIds) {
        $fPh = [];
        foreach ($reqBmIds as $i => $v) { $k=":fbm_{$i}"; $fPh[]=$k; $inPnamed[$k]=$v; }
        $filterBmSql = ' AND aa.bm_id IN ('.implode(',', $fPh).')';
    }
}

$sql = "
    SELECT
        aa.id, aa.name, aa.status, aa.timezone_name, aa.currency,
        aa.spend_cap, aa.amount_spent, aa.balance, aa.disabled_date, aa.synced_at,
        bm.id::text AS bm_id,
        bm.name AS bm_name,
        CASE WHEN aa.status = 1 THEN COALESCE(camp.campaigns_active_count, 0) ELSE 0 END AS campaigns_active_count,
        CASE WHEN aa.status = 1 THEN COALESCE(camp.campaigns_count, 0) ELSE 0 END AS campaigns_count,
        COALESCE(s.spend,       0) AS spend,
        COALESCE(s.delta,       0) AS delta,
        COALESCE(s.impressions, 0) AS impressions,
        COALESCE(s.clicks,      0) AS clicks,
        COALESCE(s.leads,       0) AS leads,
        COALESCE(s.regs,        0) AS regs,
        COALESCE(s.deps,        0) AS deps,
        COALESCE(s.revenue,     0) AS revenue
    FROM ad_accounts aa
    JOIN business_managers bm ON bm.id = aa.bm_id
    LEFT JOIN (
        SELECT
            c.ad_account_id,
            COUNT(*) FILTER (
                WHERE c.status = 'ACTIVE'
                  AND COALESCE(NULLIF(c.effective_status, ''), 'ACTIVE') = 'ACTIVE'
            ) AS campaigns_active_count,
            COUNT(*) FILTER (WHERE COALESCE(c.status, '') != 'DELETED') AS campaigns_count
        FROM campaigns c
        WHERE 1=1 {$geoCampSql}
        GROUP BY c.ad_account_id
    ) camp ON camp.ad_account_id = aa.id
    LEFT JOIN (
        SELECT
            acc_id AS ad_account_id,
            SUM(spend) AS spend, SUM(impressions) AS impressions,
            SUM(clicks) AS clicks,
            SUM(delta) AS delta,
            SUM(leads) AS leads, SUM(regs) AS regs,
            SUM(deps) AS deps, SUM(revenue) AS revenue
        FROM (
            -- Structured rows: account_id from ads
            SELECT a.ad_account_id AS acc_id,
                id.spend, id.impressions, id.clicks, id.cpc,
                id.delta, id.leads, id.regs, id.deps, id.revenue
            FROM insights_daily id
            JOIN ads a ON a.id = id.ad_id
            LEFT JOIN campaigns c ON c.id = a.campaign_id
            WHERE id.date >= :date_from AND id.date <= :date_to
              {$geoStatsSql}

            UNION ALL

            -- Orphan rows: account_id from sub_id_10
            SELECT
                CASE
                    WHEN id.sub_id_10 ~ '^\d+$' THEN 'act_' || id.sub_id_10
                    ELSE id.sub_id_10
                END AS acc_id,
                id.spend, id.impressions, id.clicks, id.cpc,
                id.delta, id.leads, id.regs, id.deps, id.revenue
            FROM insights_daily id
            LEFT JOIN ads a ON a.id = id.ad_id
            WHERE a.id IS NULL
              AND id.sub_id_10 IS NOT NULL
              AND id.sub_id_10 != ''
              AND id.sub_id_10 NOT LIKE '{%}'
              AND id.date >= :date_from AND id.date <= :date_to
              {$geoOrphanSql}
        ) combined
        GROUP BY acc_id
    ) s ON s.ad_account_id = aa.id
    WHERE aa.bm_id IN {$inSql}
          {$filterBmSql}
    ORDER BY bm.name, aa.name
";

$params = array_merge([':date_from'=>$dateFrom,':date_to'=>$dateTo], $inPnamed, $geoCampParams, $geoStatsParams, $geoOrphanParams);
$stmt   = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$accounts = array_map(fn($r) => [
    'id'           => $r['id'],
    'name'         => $r['name'],
    'status'       => (int)$r['status'],
    'timezone'     => $r['timezone_name'],
    'currency'     => $r['currency'],
    'spend_cap'    => $r['spend_cap'] !== null ? (float)$r['spend_cap'] : null,
    'amount_spent' => (float)$r['amount_spent'],
    'balance'      => (float)$r['balance'],
    'disabled_date'=> $r['disabled_date'],
    'synced_at'    => $r['synced_at'],
    'bm_id' => (string)$r['bm_id'],
    'bm_name'      => $r['bm_name'],
    'campaigns_active_count' => (int)$r['campaigns_active_count'],
    'campaigns_count' => (int)$r['campaigns_count'],
    'period'       => [
        'spend'       => (float)$r['spend'],
        'delta'       => (float)$r['delta'],
        'impressions' => (int)$r['impressions'],
        'clicks'      => (int)$r['clicks'],
        'cpc'         => $r['clicks'] > 0 ? round((float)$r['spend'] / (int)$r['clicks'], 4) : 0,
        'leads'       => (int)$r['leads'],
        'regs'        => (int)$r['regs'],
        'deps'        => (int)$r['deps'],
        'revenue'     => (float)$r['revenue'],
    ],
], $rows);

// Time of last syncs
$fbSync = $db->query("
    SELECT MAX(fb_synced_at) AS fb_synced_at
    FROM insights_daily
    WHERE impressions > 0 OR spend > 0
")->fetchColumn();

$ktSync = $db->query("
    SELECT MAX(kt_synced_at) AS kt_synced_at
    FROM insights_daily
    WHERE leads > 0 OR regs > 0 OR deps > 0 OR revenue > 0
")->fetchColumn();

apiOk($accounts, [
    'range'        => $range,
    'date_from'    => $dateFrom,
    'date_to'      => $dateTo,
    'tz'           => $tz,
    'count'        => count($accounts),
    'fb_synced_at' => $fbSync ?: null,
    'kt_synced_at' => $ktSync ?: null,
]);
