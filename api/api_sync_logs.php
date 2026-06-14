<?php
// api/api_sync_logs.php - HTTP request log report.

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/ApiSyncLogger.php';

ApiSyncLogger::ensureSchema($db);

if (($me['role'] ?? '') !== 'admin') {
    apiError(403, 'Forbidden');
}

$where = [];
$params = [];

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = "(
        fr.id::text ILIKE :q OR
        COALESCE(fr.request_type, '') ILIKE :q OR
        COALESCE(fr.endpoint, '') ILIKE :q OR
        COALESCE(fr.status, '') ILIKE :q OR
        COALESCE(fr.error_msg, '') ILIKE :q OR
        COALESCE(fr.response_preview, '') ILIKE :q OR
        COALESCE(fr.raw_error::text, '') ILIKE :q
    )";
    $params[':q'] = '%' . $q . '%';
}

foreach ([
    'request_type' => 'fr.request_type',
    'status' => 'fr.status',
    'endpoint' => 'fr.endpoint',
] as $param => $column) {
    $value = trim((string)($_GET[$param] ?? ''));
    if ($value === '') {
        continue;
    }
    $where[] = "{$column} = :{$param}";
    $params[":{$param}"] = $value;
}

$httpCode = trim((string)($_GET['http_code'] ?? ''));
if ($httpCode !== '') {
    $where[] = 'fr.http_code = :http_code';
    $params[':http_code'] = (int)$httpCode;
}

$limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));

$sql = "
    SELECT
        fr.*,
        sl.started_at AS sync_started_at,
        sl.finished_at AS sync_finished_at,
        sl.status AS sync_status
    FROM public.fb_request_log fr
    LEFT JOIN public.sync_log sl ON sl.id = fr.sync_log_id
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY fr.ts DESC, fr.id DESC LIMIT {$limit}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    if (isset($row['sync_log_id'])) {
        $row['sync_log_id'] = $row['sync_log_id'] !== null ? (int)$row['sync_log_id'] : null;
    }
    foreach (['batch_size', 'http_code', 'duration_ms', 'rows_returned', 'attempt', 'error_code'] as $field) {
        if (isset($row[$field]) && $row[$field] !== null) {
            $row[$field] = (int)$row[$field];
        }
    }
    if (isset($row['raw_error']) && is_string($row['raw_error']) && $row['raw_error'] !== '') {
        $decoded = json_decode($row['raw_error'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $row['raw_error'] = $decoded;
        }
    }
}
unset($row);

apiOk([
    'rows' => $rows,
    'count' => count($rows),
    'limit' => $limit,
]);
