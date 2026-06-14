<?php
// api/geo_metrics.php — for dashboard (session auth)
require __DIR__.'/_bootstrap.php';
require __DIR__.'/_geo_metrics_logic.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) { apiOk(['geos' => [], 'rules' => []]); exit; }

$bmFilter = trim((string)($_GET['bm_id'] ?? ''));
if ($bmFilter !== '') {
    $allowed = array_map('strval', $bmIds);
    if (!in_array($bmFilter, $allowed, true)) {
        apiError(403, 'BM is not allowed');
    }
    $bmIds = [$bmFilter];
}

$tz = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');

$bmPh = []; $bmParams = [];
foreach ($bmIds as $i => $v) { $k = ":bm_{$i}"; $bmPh[] = $k; $bmParams[$k] = $v; }
$bmInSql = '(' . implode(',', $bmPh) . ')';

try {
    apiOk(geoCalcResult($db, $bmInSql, $bmParams, $tz));
} catch (Exception $e) {
    apiError(500, 'DB error: ' . $e->getMessage());
}
