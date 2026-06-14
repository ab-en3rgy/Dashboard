<?php
// api/ext/geo_metrics.php — for extension (auth via secret)
require __DIR__.'/_bootstrap.php';
require __DIR__.'/../_geo_metrics_logic.php';

$tz = $body['tz'] ?? 'Europe/Kyiv';

// Without BM filter, use all data
$bmInSql = '(SELECT id FROM business_managers)';
$bmParams = [];

try {
    $result = geoCalcResult($db, $bmInSql, $bmParams, $tz);
    extOk($result);
} catch (Exception $e) {
    extError(500, 'DB error: ' . $e->getMessage());
}
