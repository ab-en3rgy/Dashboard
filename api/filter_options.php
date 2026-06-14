<?php
// api/filter_options.php
// Options for the report filter bar.

require __DIR__.'/_bootstrap.php';

function formatLaunchDateLabel(string $value): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return "{$m[3]}.{$m[2]}." . substr($m[1], -2);
    }
    return $value;
}

$bmIds = array_map('strval', $auth->allowedBmIds($me));
if (!$bmIds) {
    apiOk(['geos'=>[], 'bms'=>[], 'accounts'=>[], 'launch_dates'=>[], 'campaigns'=>[], 'adsets'=>[], 'creatives'=>[]]);
}

$filters = [
    'geo' => strtoupper(trim((string)($_GET['geo'] ?? ''))),
    'bm_id' => trim((string)($_GET['bm_id'] ?? '')),
    'account_id' => trim((string)($_GET['account_id'] ?? '')),
    'account_scope' => trim((string)($_GET['account_scope'] ?? '')),
    'launch_date' => trim((string)($_GET['launch_date'] ?? '')),
    'launch_mode' => trim((string)($_GET['launch_mode'] ?? '')),
    'campaign_id' => trim((string)($_GET['campaign_id'] ?? '')),
    'adset_id' => trim((string)($_GET['adset_id'] ?? '')),
    'ad_name' => trim((string)($_GET['ad_name'] ?? '')),
];
$activeAccountScope = $filters['account_scope'] === 'active' && $filters['account_id'] === '';

$params = [];
$bmPh = [];
foreach ($bmIds as $i => $id) {
    $key = ":bm_{$i}";
    $bmPh[] = $key;
    $params[$key] = $id;
}
$bmInSql = '(' . implode(',', $bmPh) . ')';

function addGeoFilter(array &$where, array &$params, string $field, string $geo, string $prefix): void {
    $geos = array_values(array_filter(array_map('trim', explode(',', strtoupper($geo))), fn($g) => preg_match('/^[A-Z]{2}$/', $g)));
    if (!$geos) return;
    $conds = [];
    foreach ($geos as $i => $g) {
        $p0 = ":{$prefix}_{$i}_0";
        $conds[] = "({$field} = {$p0})";
        $params[$p0] = $g;
    }
    $where[] = '(' . implode(' OR ', $conds) . ')';
}

function addEq(array &$where, array &$params, string $field, string $value, string $key): void {
    if ($value === '') return;
    $where[] = "{$field} = :{$key}";
    $params[":{$key}"] = $value;
}

function fetchPairs(PDO $db, string $sql, array $params): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $geoRows = fetchPairs($db, "
        SELECT geo, COUNT(*) AS cnt
        FROM (
            SELECT DISTINCT campaign_geo(c.name) AS geo
            FROM campaigns c
            JOIN ad_accounts aa ON aa.id = c.ad_account_id
            WHERE aa.bm_id IN {$bmInSql}
        ) x
        WHERE geo ~ '^[A-Z]{2}$'
        GROUP BY geo
        ORDER BY geo
    ", $params);

    $bmWhere = ["bm.id::text IN {$bmInSql}", 'bm.is_active = TRUE'];
    $bmParams = $params;
    $bms = fetchPairs($db, "
        SELECT bm.id::text AS id, bm.name AS name
        FROM business_managers bm
        WHERE " . implode(' AND ', $bmWhere) . "
        ORDER BY bm.name, bm.id
    ", $bmParams);

    $accWhere = ["aa.bm_id IN {$bmInSql}"];
    if ($activeAccountScope) $accWhere[] = 'aa.status = 1';
    $accParams = $params;
    addEq($accWhere, $accParams, 'aa.bm_id::text', $filters['bm_id'], 'acc_bm_id');
    if ($filters['geo'] !== '') {
        $accWhere[] = "EXISTS (SELECT 1 FROM campaigns c WHERE c.ad_account_id = aa.id";
        $geoWhere = [];
        addGeoFilter($geoWhere, $accParams, 'campaign_geo(c.name)', $filters['geo'], 'acc_geo');
        $accWhere[count($accWhere)-1] .= $geoWhere ? ' AND ' . implode(' AND ', $geoWhere) . ')' : ')';
    }
    $accounts = fetchPairs($db, "
        SELECT aa.id::text AS id, aa.name AS name, aa.bm_id::text AS bm_id
        FROM ad_accounts aa
        WHERE " . implode(' AND ', $accWhere) . "
        ORDER BY aa.name, aa.id
    ", $accParams);

    $campWhere = ["aa.bm_id IN {$bmInSql}"];
    if ($activeAccountScope) $campWhere[] = 'aa.status = 1';
    $campParams = $params;
    addEq($campWhere, $campParams, 'aa.bm_id::text', $filters['bm_id'], 'camp_bm_id');
    addEq($campWhere, $campParams, 'c.ad_account_id::text', $filters['account_id'], 'camp_account_id');
    if ($filters['launch_date'] !== '') {
        $campWhere[] = match ($filters['launch_mode']) {
            'after' => 'c.created_time::date >= CAST(:camp_launch_date AS date)',
            'before' => 'c.created_time::date <= CAST(:camp_launch_date AS date)',
            default => 'c.created_time::date = CAST(:camp_launch_date AS date)',
        };
        $campParams[':camp_launch_date'] = $filters['launch_date'];
    }
    addGeoFilter($campWhere, $campParams, 'campaign_geo(c.name)', $filters['geo'], 'camp_geo');
    $campaigns = fetchPairs($db, "
        SELECT c.id::text AS id, c.name AS name, c.ad_account_id::text AS account_id
        FROM campaigns c
        JOIN ad_accounts aa ON aa.id = c.ad_account_id
        WHERE " . implode(' AND ', $campWhere) . "
        ORDER BY c.name, c.id
        LIMIT 1000
    ", $campParams);

    $launchWhere = ["aa.bm_id IN {$bmInSql}", 'c.created_time IS NOT NULL'];
    if ($activeAccountScope) $launchWhere[] = 'aa.status = 1';
    $launchParams = $params;
    addEq($launchWhere, $launchParams, 'aa.bm_id::text', $filters['bm_id'], 'launch_bm_id');
    addEq($launchWhere, $launchParams, 'c.ad_account_id::text', $filters['account_id'], 'launch_account_id');
    addGeoFilter($launchWhere, $launchParams, 'campaign_geo(c.name)', $filters['geo'], 'launch_geo');
    $launchDates = fetchPairs($db, "
        SELECT DISTINCT c.created_time::date::text AS id, c.created_time::date::text AS name
        FROM campaigns c
        JOIN ad_accounts aa ON aa.id = c.ad_account_id
        WHERE " . implode(' AND ', $launchWhere) . "
        ORDER BY id DESC
        LIMIT 1000
    ", $launchParams);

    $adsetWhere = ["aa.bm_id IN {$bmInSql}"];
    if ($activeAccountScope) $adsetWhere[] = 'aa.status = 1';
    $adsetParams = $params;
    addEq($adsetWhere, $adsetParams, 'aa.bm_id::text', $filters['bm_id'], 'adset_bm_id');
    addEq($adsetWhere, $adsetParams, 's.ad_account_id::text', $filters['account_id'], 'adset_account_id');
    addEq($adsetWhere, $adsetParams, 's.campaign_id::text', $filters['campaign_id'], 'adset_campaign_id');
    addGeoFilter($adsetWhere, $adsetParams, 'campaign_geo(c.name)', $filters['geo'], 'adset_geo');
    $adsets = fetchPairs($db, "
        SELECT s.id::text AS id, s.name AS name, s.campaign_id::text AS campaign_id
        FROM ad_sets s
        JOIN campaigns c ON c.id = s.campaign_id
        JOIN ad_accounts aa ON aa.id = s.ad_account_id
        WHERE " . implode(' AND ', $adsetWhere) . "
          AND COALESCE(s.status, '') != 'DELETED'
          AND COALESCE(c.status, '') != 'DELETED'
        ORDER BY s.name, s.id
        LIMIT 1000
    ", $adsetParams);

    $creoWhere = ["aa.bm_id IN {$bmInSql}", "a.name IS NOT NULL", "a.name <> ''"];
    if ($activeAccountScope) $creoWhere[] = 'aa.status = 1';
    $creoParams = $params;
    addEq($creoWhere, $creoParams, 'aa.bm_id::text', $filters['bm_id'], 'creo_bm_id');
    addEq($creoWhere, $creoParams, 'a.ad_account_id::text', $filters['account_id'], 'creo_account_id');
    addEq($creoWhere, $creoParams, 'a.campaign_id::text', $filters['campaign_id'], 'creo_campaign_id');
    addEq($creoWhere, $creoParams, 'a.ad_set_id::text', $filters['adset_id'], 'creo_adset_id');
    addGeoFilter($creoWhere, $creoParams, 'campaign_geo(c.name)', $filters['geo'], 'creo_geo');
    $creatives = fetchPairs($db, "
        SELECT a.name AS id, a.name AS name, COUNT(*) AS ads_count
        FROM ads a
        JOIN ad_sets s ON s.id = a.ad_set_id
        JOIN campaigns c ON c.id = a.campaign_id
        JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE " . implode(' AND ', $creoWhere) . "
          AND aa.status = 1
          AND COALESCE(a.status, '') != 'DELETED'
          AND COALESCE(s.status, '') != 'DELETED'
          AND COALESCE(c.status, '') != 'DELETED'
        GROUP BY a.name
        ORDER BY a.name
        LIMIT 1500
    ", $creoParams);
} catch (Throwable $e) {
    apiError(500, 'DB error: ' . $e->getMessage());
}

apiOk([
    'geos' => array_map(static fn($r) => ['id'=>(string)$r['geo'], 'name'=>(string)$r['geo']], $geoRows),
    'bms' => array_map(static fn($r) => ['id'=>(string)$r['id'], 'name'=>(string)$r['name']], $bms),
    'accounts' => array_map(static fn($r) => ['id'=>(string)$r['id'], 'name'=>(string)$r['name']], $accounts),
    'launch_dates' => array_map(static fn($r) => ['id'=>(string)$r['id'], 'name'=>formatLaunchDateLabel((string)$r['name'])], $launchDates),
    'campaigns' => array_map(static fn($r) => ['id'=>(string)$r['id'], 'name'=>(string)$r['name']], $campaigns),
    'adsets' => array_map(static fn($r) => ['id'=>(string)$r['id'], 'name'=>(string)$r['name']], $adsets),
    'creatives' => array_map(static fn($r) => ['id'=>(string)$r['id'], 'name'=>(string)$r['name']], $creatives),
]);
