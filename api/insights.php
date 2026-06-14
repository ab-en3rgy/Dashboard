<?php
// api/insights.php
// GET /api/insights.php?range=today&account_id=act_xxx
// GET /api/insights.php?range=30d&campaign_id=123
// Daily data (insights_daily), always grouped by date

require __DIR__.'/_bootstrap.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk([]);

$range   = $_GET['range']       ?? 'today';
$accId   = $_GET['account_id']  ?? null;
$campId  = $_GET['campaign_id'] ?? null;
$adsetId = $_GET['adset_id']    ?? null;
$adId    = $_GET['ad_id']       ?? null;
$tz      = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');

// Period (in user timezone, filter by DATE)
$tzObj = appDateTimeZone($tz);
$now   = new DateTime('now', $tzObj);
switch ($range) {
    case 'yesterday':
        $dateFrom = (clone $now)->modify('yesterday')->format('Y-m-d');
        $dateTo   = $dateFrom;
        break;
    case 'yesterday_today':
        $dateFrom = (clone $now)->modify('yesterday')->format('Y-m-d');
        $dateTo   = $now->format('Y-m-d');
        break;
    case '3d':
        $dateFrom = (clone $now)->modify('-2 days')->format('Y-m-d');
        $dateTo   = $now->format('Y-m-d');
        break;
    case '7d':
        $dateFrom = (clone $now)->modify('-6 days')->format('Y-m-d');
        $dateTo   = $now->format('Y-m-d');
        break;
    case '14d':
        $dateFrom = (clone $now)->modify('-13 days')->format('Y-m-d');
        $dateTo   = $now->format('Y-m-d');
        break;
    case '30d':
        $dateFrom = (clone $now)->modify('-29 days')->format('Y-m-d');
        $dateTo   = $now->format('Y-m-d');
        break;
    case '90d':
        $dateFrom = (clone $now)->modify('-89 days')->format('Y-m-d');
        $dateTo   = $now->format('Y-m-d');
        break;
    default: // today
        $dateFrom = $now->format('Y-m-d');
        $dateTo   = $dateFrom;
}

// IN placeholder for bm_ids
$inPlaceholders = [];
$params         = [':date_from' => $dateFrom, ':date_to' => $dateTo];
foreach ($bmIds as $i => $v) {
    $key = ":fb_id_{$i}";
    $inPlaceholders[] = $key;
    $params[$key] = $v;
}
$fbInSql = '('.implode(',', $inPlaceholders).')';

// Hierarchy filters
$extraFilter = '';
if ($adId)    { $extraFilter .= ' AND id.ad_id = :ad_id';         $params[':ad_id']    = $adId;    }
if ($adsetId) { $extraFilter .= ' AND a.adset_id = :adset_id';    $params[':adset_id'] = $adsetId; }
if ($campId)  { $extraFilter .= ' AND a.campaign_id = :camp_id';  $params[':camp_id']  = $campId;  }
if ($accId)   { $extraFilter .= ' AND a.ad_account_id = :acc_id'; $params[':acc_id']   = $accId;   }

$sql = "
    SELECT
        id.date                                     AS date,
        SUM(id.spend)                               AS spend,
        SUM(id.delta)                               AS delta,
        SUM(id.impressions)                         AS impressions,
        SUM(id.clicks)                              AS clicks,
        CASE WHEN SUM(id.impressions) > 0
             THEN SUM(id.clicks)::numeric / SUM(id.impressions) * 100
             ELSE 0 END                             AS ctr,
        CASE WHEN SUM(id.impressions) > 0
             THEN SUM(id.spend) / SUM(id.impressions) * 1000
             ELSE 0 END                             AS cpm,
        CASE WHEN SUM(id.clicks) > 0
             THEN SUM(id.spend) / SUM(id.clicks)
             ELSE 0 END                             AS cpc,
        SUM(id.leads)                               AS leads,
        SUM(id.regs)                                AS regs,
        SUM(id.deps)                                AS deps,
        SUM(id.revenue)                             AS revenue
    FROM insights_daily id
    JOIN ads a          ON a.id  = id.ad_id
    JOIN ad_accounts aa ON aa.id = a.ad_account_id
    WHERE id.date >= :date_from
      AND id.date <= :date_to
      AND aa.bm_id IN {$fbInSql}
      {$extraFilter}
    GROUP BY id.date
    ORDER BY id.date ASC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$points = array_map(fn(array $r): array => [
    'date'        => $r['date'],
    'spend'       => round((float)$r['spend'], 4),
    'delta'       => round((float)$r['delta'], 4),
    'impressions' => (int)$r['impressions'],
    'clicks'      => (int)$r['clicks'],
    'ctr'         => round((float)$r['ctr'], 4),
    'cpm'         => round((float)$r['cpm'], 4),
    'cpc'         => round((float)$r['cpc'], 4),
    'leads'       => (int)$r['leads'],
    'regs'        => (int)$r['regs'],
    'deps'        => (int)$r['deps'],
    'revenue'     => round((float)$r['revenue'], 4),
], $rows);

apiOk($points, [
    'range'     => $range,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'tz'        => $tz,
    'count'     => count($points),
]);
