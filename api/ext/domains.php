<?php
// api/ext/domains.php — External API (auth via extension_secret)
//
// POST /api/ext/domains.php
// Body JSON: { "secret": "<extension_secret from config.php>" }
//
// Response: active records only + geo rules:
// { "ok": true, "total": 42, "data": [ {id, bm, geo, domain, fp_name}, ... ], "geo_rules": {...} }

require __DIR__ . '/_bootstrap.php';

$db->exec("
    ALTER TABLE public.domains_fp
    ADD COLUMN IF NOT EXISTS status varchar(10) NOT NULL DEFAULT 'active'
");

$stmt = $db->query("
    SELECT id, bm, geo, domain, fp_name, page_id, pixel_id
    FROM public.domains_fp
    WHERE status = 'active'
    ORDER BY bm, geo, id
");

$rows = $stmt->fetchAll();

$rulesPath = __DIR__ . '/../../config/geo_rules.json';
$geoRules = [];
if (is_file($rulesPath)) {
    $rulesJson = file_get_contents($rulesPath);
    $rawRules = json_decode($rulesJson, true);
    if (!is_array($rawRules)) {
        $rawRules = json_decode(preg_replace('/,\s*([}\]])/', '$1', $rulesJson), true);
    }
    if (is_array($rawRules)) {
        $geoRules = $rawRules;
    }
}

extOk([
    'total'     => count($rows),
    'data'      => $rows,
    'geo_rules' => $geoRules,
]);
