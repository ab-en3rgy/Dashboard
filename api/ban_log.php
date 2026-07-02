<?php
// api/ban_log.php
// @version 1.0.0

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';

GlobalLogger::ensureSchema($db);

$bmIds = array_values(array_filter(array_map('strval', $auth->allowedBmIds($me))));
if (!$bmIds) {
    apiOk(['rows' => [], 'count' => 0, 'limit' => 0]);
}

$params = [];
$bmPlaceholders = [];
foreach ($bmIds as $i => $bmId) {
    $key = ':bm_' . $i;
    $bmPlaceholders[] = $key;
    $params[$key] = $bmId;
}

$where = [
    "gl.event_type = 'account_banned'",
    'gl.bm_id IN (' . implode(',', $bmPlaceholders) . ')',
];

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = "(
        gl.account_id ILIKE :q OR
        COALESCE(aa.name, '') ILIKE :q OR
        gl.bm_id ILIKE :q OR
        COALESCE(bm.name, '') ILIKE :q OR
        COALESCE(gl.reason, '') ILIKE :q OR
        gl.payload::text ILIKE :q
    )";
    $params[':q'] = '%' . $q . '%';
}

$limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));

$stmt = $db->prepare("
    SELECT
        gl.id,
        gl.created_at,
        gl.bm_id,
        gl.account_id,
        gl.reason,
        gl.payload,
        gl.result,
        bm.name AS bm_name,
        aa.name AS account_name
    FROM public.global_log gl
    LEFT JOIN public.business_managers bm ON bm.id::text = gl.bm_id
    LEFT JOIN public.ad_accounts aa ON aa.id::text = gl.account_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY gl.created_at DESC, gl.id DESC
    LIMIT {$limit}
");
$stmt->execute($params);

$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $payload = json_decode((string)($row['payload'] ?? '{}'), true);
    if (!is_array($payload)) $payload = [];
    $result = json_decode((string)($row['result'] ?? '{}'), true);
    if (!is_array($result)) $result = [];
    $rows[] = [
        'id' => (int)$row['id'],
        'created_at' => $row['created_at'],
        'bm_id' => (string)$row['bm_id'],
        'bm_name' => (string)($row['bm_name'] ?: ($result['bm_name'] ?? $row['bm_id'])),
        'account_id' => (string)$row['account_id'],
        'account_name' => (string)($row['account_name'] ?: ($result['account_name'] ?? $row['account_id'])),
        'reason' => (string)($row['reason'] ?? ''),
        'disabled_date' => $payload['disabled_date'] ?? null,
        'currency' => $payload['currency'] ?? 'USD',
        'balance' => isset($payload['balance']) ? (float)$payload['balance'] : null,
        'amount_spent' => isset($payload['amount_spent']) ? (float)$payload['amount_spent'] : null,
        'spend_cap' => isset($payload['spend_cap']) ? (float)$payload['spend_cap'] : null,
        'launch_status' => $payload['launch_status'] ?? null,
        'launch_restricted' => !empty($payload['launch_restricted']),
        'launch_block_reason' => $payload['launch_block_reason'] ?? null,
        'campaigns' => [
            'active_count' => (int)($payload['campaigns']['active_count'] ?? 0),
            'total_count' => (int)($payload['campaigns']['total_count'] ?? 0),
            'top' => array_values(array_filter($payload['campaigns']['top'] ?? [], 'is_array')),
        ],
        'stats_7d' => [
            'spend' => (float)($payload['stats_7d']['spend'] ?? 0),
            'deps' => (int)($payload['stats_7d']['deps'] ?? 0),
            'revenue' => (float)($payload['stats_7d']['revenue'] ?? 0),
        ],
        'stats_30d' => [
            'spend' => (float)($payload['stats_30d']['spend'] ?? 0),
            'deps' => (int)($payload['stats_30d']['deps'] ?? 0),
            'revenue' => (float)($payload['stats_30d']['revenue'] ?? 0),
        ],
    ];
}

apiOk([
    'rows' => $rows,
    'count' => count($rows),
    'limit' => $limit,
]);
