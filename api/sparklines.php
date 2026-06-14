<?php
// api/sparklines.php
// GET /api/sparklines.php?type=account|campaign&ids=id1,id2&range=today|yesterday|3d|7d|30d
// Returns:
//   daily: { id -> [{ date, spend }] } for the last 30 days
//   totals: { id → { today, yesterday, 3d, 7d, 30d } }

require __DIR__.'/_bootstrap.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk(['daily'=>[], 'totals'=>[]]);

$tz    = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
$tzObj = appDateTimeZone($tz);
$now   = new DateTime('now', $tzObj);

$type    = $_GET['type'] ?? 'campaign'; // account | campaign
$idsRaw  = $_GET['ids']  ?? '';
$ids     = array_filter(array_map('trim', explode(',', $idsRaw)));
if (!$ids) apiError(400, 'ids required');

// BM filter
$bmPh = []; $bmParams = [];
foreach ($bmIds as $i => $v) { $k=":bm_{$i}"; $bmPh[]=$k; $bmParams[$k]=$v; }
$bmInSql = '('.implode(',', $bmPh).')';

// Periods
$date30d = (clone $now)->modify('-29 days midnight')->format('Y-m-d');
$dateTo  = $now->format('Y-m-d');
$dateYest= (clone $now)->modify('yesterday midnight')->format('Y-m-d');
$date3d  = (clone $now)->modify('-2 days midnight')->format('Y-m-d');
$date7d  = (clone $now)->modify('-6 days midnight')->format('Y-m-d');
$dateToday = (clone $now)->modify('midnight')->format('Y-m-d');

// ID placeholders
$idPh = [];
foreach ($ids as $i => $id) { $k=":id_{$i}"; $idPh[]=$k; $bmParams[$k]=$id; }
$idInSql = '('.implode(',', $idPh).')';

if ($type === 'account') {
    $groupCol = 'a.ad_account_id';
    $filterCol = 'aa.id';
} else {
    $groupCol = 'a.campaign_id';
    $filterCol = 'c.id::text';
}

// Daily spend for 30d
$joinCampaign = $type === 'campaign' ? 'JOIN campaigns c ON c.id = a.campaign_id' : '';
$sql = "
    SELECT
        {$groupCol}::text AS entity_id,
        id.date,
        ROUND(SUM(id.spend)::numeric, 2) AS spend
    FROM insights_daily id
    JOIN ads a ON a.id = id.ad_id
    JOIN ad_accounts aa ON aa.id = a.ad_account_id
    {$joinCampaign}
    WHERE id.date >= :date_30d
      AND id.date <= :date_to
      AND aa.bm_id IN {$bmInSql}
      AND {$filterCol} IN {$idInSql}
    GROUP BY {$groupCol}, id.date
    ORDER BY {$groupCol}, id.date
";

$params = array_merge([':date_30d'=>$date30d, ':date_to'=>$dateTo], $bmParams);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by entity_id
$daily = [];
foreach ($ids as $id) $daily[$id] = [];
foreach ($rows as $r) {
    $daily[$r['entity_id']][] = ['date'=>$r['date'], 'spend'=>(float)$r['spend']];
}

// Totals by period
$totals = [];
foreach ($ids as $id) $totals[$id] = ['today'=>0,'yesterday'=>0,'3d'=>0,'7d'=>0,'30d'=>0];
foreach ($rows as $r) {
    $id = $r['entity_id'];
    $d  = $r['date'];
    $s  = (float)$r['spend'];
    $totals[$id]['30d'] += $s;
    if ($d >= $date3d)     $totals[$id]['3d'] += $s;
    if ($d >= $date7d)     $totals[$id]['7d'] += $s;
    if ($d === $dateYest)  $totals[$id]['yesterday'] += $s;
    if ($d === $dateToday) $totals[$id]['today'] += $s;
}
// Round totals
foreach ($totals as $id => &$t) {
    foreach ($t as $k => &$v) $v = round($v, 2);
}

apiOk(['daily'=>$daily, 'totals'=>$totals]);
