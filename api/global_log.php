<?php
// api/global_log.php - BM-scoped audit log report API.

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';

GlobalLogger::ensureSchema($db);

$bmIds = array_values(array_filter(array_map('strval', $auth->allowedBmIds($me))));
if (!$bmIds) {
    apiOk(['rows' => [], 'count' => 0]);
}

$where = [];
$params = [];
$bmPh = [];
foreach ($bmIds as $i => $bmId) {
    $key = ":bm_{$i}";
    $bmPh[] = $key;
    $params[$key] = $bmId;
}
$where[] = 'gl.bm_id IN (' . implode(',', $bmPh) . ')';

$filters = [
    'event_type' => 'gl.event_type',
    'entity_type' => 'gl.entity_type',
    'status' => 'gl.status',
    'action' => 'gl.action',
    'source' => 'gl.source',
    'bm_id' => 'gl.bm_id',
    'account_id' => 'gl.account_id',
    'campaign_id' => 'gl.campaign_id',
    'adset_id' => 'gl.adset_id',
    'task_id' => 'gl.task_id::text',
];

foreach ($filters as $param => $column) {
    $value = trim((string)($_GET[$param] ?? ''));
    if ($value === '') continue;
    $key = ':' . $param;
    $where[] = "{$column} = {$key}";
    $params[$key] = $value;
}

$from = trim((string)($_GET['from'] ?? ''));
if ($from !== '') {
    $where[] = 'gl.created_at >= CAST(:from_ts AS timestamptz)';
    $params[':from_ts'] = $from;
}
$to = trim((string)($_GET['to'] ?? ''));
if ($to !== '') {
    $where[] = 'gl.created_at <= CAST(:to_ts AS timestamptz)';
    $params[':to_ts'] = $to;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = "(
        gl.id::text ILIKE :q OR
        COALESCE(gl.task_id::text, '') ILIKE :q OR
        gl.event_type ILIKE :q OR
        gl.entity_type ILIKE :q OR
        gl.action ILIKE :q OR
        gl.source ILIKE :q OR
        gl.actor ILIKE :q OR
        gl.bm_id ILIKE :q OR
        gl.account_id ILIKE :q OR
        COALESCE(gl.campaign_id, '') ILIKE :q OR
        COALESCE(gl.adset_id, '') ILIKE :q OR
        COALESCE(gl.reason, '') ILIKE :q OR
        COALESCE(gl.error, '') ILIKE :q OR
        gl.payload::text ILIKE :q OR
        COALESCE(gl.result::text, '') ILIKE :q
    )";
    $params[':q'] = '%' . $q . '%';
}

$limit = max(1, min(1000, (int)($_GET['limit'] ?? 250)));

$stmt = $db->prepare("
    SELECT
        gl.*,
        bm.name AS bm_name,
        aa.name AS account_name,
        c.name AS campaign_name,
        s.name AS adset_name
    FROM public.global_log gl
    LEFT JOIN public.business_managers bm ON bm.id::text = gl.bm_id
    LEFT JOIN public.ad_accounts aa ON aa.id::text = gl.account_id
    LEFT JOIN public.campaigns c ON c.id::text = gl.campaign_id
    LEFT JOIN public.ad_sets s ON s.id::text = gl.adset_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY gl.created_at DESC, gl.id DESC
    LIMIT {$limit}
");
$stmt->execute($params);
$rows = array_map('formatGlobalLogRow', $stmt->fetchAll(PDO::FETCH_ASSOC));

apiOk([
    'rows' => $rows,
    'count' => count($rows),
    'limit' => $limit,
]);

function formatGlobalLogRow(array $row): array
{
    foreach (['before_state', 'desired_state', 'after_state', 'payload', 'result'] as $field) {
        if (array_key_exists($field, $row) && is_string($row[$field]) && $row[$field] !== '') {
            $decoded = json_decode($row[$field], true);
            $row[$field] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row[$field];
        }
    }
    foreach (['id', 'task_id'] as $field) {
        if (isset($row[$field])) $row[$field] = (int)$row[$field];
    }
    return $row;
}
