<?php
// api/tasks.php
// @version 1.0.7
// Internal dashboard view of external task queue.

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';

ensureTasksDashboardSchema($db);
ensureAdsetBidFields($db);
GlobalLogger::ensureSchema($db);

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') apiError(403, 'No BM access');
    apiOk(['tasks' => [], 'counts' => []]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) apiError(400, 'Invalid JSON body');
    $action = strtolower(trim((string)($input['action'] ?? 'create')));
    if (in_array($action, ['retry_failed', 'reset_failed', 'retry'], true)) {
        retryDashboardTask($db, $bmIds, $input, false);
    }
    if ($action === 'retry_task') {
        retryDashboardTask($db, $bmIds, $input, true);
    }
    if (in_array($action, ['delete_task', 'remove_task'], true)) {
        deleteDashboardTask($db, $bmIds, $input);
    }
    if ($action === 'pause_creative_ads') {
        pauseCreativeAdsTasks($db, $me, $bmIds, $input);
    }
    createDashboardTask($db, $me, $bmIds, $input);
}

$status = strtolower(trim((string)($_GET['status'] ?? '')));
$type = strtolower(trim((string)($_GET['task_type'] ?? '')));
$taskId = (int)($_GET['task_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$qTaskId = 0;
$limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));

$where = [];
$params = [];

$bmPh = [];
foreach ($bmIds as $i => $bmId) {
    $key = ":bm_{$i}";
    $bmPh[] = $key;
    $params[$key] = (string)$bmId;
}
$where[] = 't.bm_id IN (' . implode(',', $bmPh) . ')';

if ($status !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $status;
}
if ($type !== '') {
    $where[] = 't.task_type = :task_type';
    $params[':task_type'] = $type;
}
if ($taskId > 0) {
    $where[] = 't.id = :task_id';
    $params[':task_id'] = $taskId;
}
if ($q !== '') {
    if (preg_match('/^#?(\d+)$/', $q, $m)) {
        $qTaskId = (int)$m[1];
        if ($qTaskId > 0) {
            $params[':q_task_id'] = $qTaskId;
        }
    }
    $where[] = "(
        " . ($qTaskId > 0 ? 't.id = :q_task_id OR' : '') . "
        t.id::text ILIKE :q OR
        t.task_type ILIKE :q OR
        t.status ILIKE :q OR
        t.bm_id ILIKE :q OR
        t.account_id ILIKE :q OR
        COALESCE(t.campaign_id, '') ILIKE :q OR
        COALESCE(t.adset_id, '') ILIKE :q OR
        COALESCE(t.ad_id, '') ILIKE :q OR
        COALESCE(a.name, '') ILIKE :q OR
        t.payload::text ILIKE :q OR
        COALESCE(t.error, '') ILIKE :q
    )";
    $params[':q'] = '%' . $q . '%';
}

$orderExactTask = $qTaskId > 0 ? "CASE WHEN t.id = :q_task_id THEN 0 ELSE 1 END," : '';

$sql = "
    SELECT
        t.*,
        bm.name AS bm_name,
        aa.name AS account_name,
        c.name AS campaign_name,
        s.name AS adset_name,
        a.name AS ad_name
    FROM public.tasks t
    LEFT JOIN public.business_managers bm ON bm.id::text = t.bm_id
    LEFT JOIN public.ad_accounts aa ON aa.id::text = t.account_id
    LEFT JOIN public.campaigns c ON c.id::text = t.campaign_id
    LEFT JOIN public.ad_sets s ON s.id::text = t.adset_id
    LEFT JOIN public.ads a ON a.id::text = t.ad_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        {$orderExactTask}
        t.created_at DESC,
        t.id DESC
    LIMIT {$limit}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = array_map('formatDashboardTask', $stmt->fetchAll(PDO::FETCH_ASSOC));

$countParams = [];
$countPh = [];
foreach ($bmIds as $i => $bmId) {
    $key = ":cbm_{$i}";
    $countPh[] = $key;
    $countParams[$key] = (string)$bmId;
}
$countStmt = $db->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM public.tasks
    WHERE bm_id IN (" . implode(',', $countPh) . ")
    GROUP BY status
");
$countStmt->execute($countParams);
$counts = [];
foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $counts[$row['status']] = (int)$row['cnt'];
}

apiOk([
    'task' => $taskId > 0 ? ($tasks[0] ?? null) : null,
    'tasks' => $tasks,
    'counts' => $counts,
], [
    'count' => count($tasks),
    'limit' => $limit,
]);

function formatDashboardTask(array $row): array {
    foreach (['payload', 'result'] as $field) {
        if (array_key_exists($field, $row) && is_string($row[$field]) && $row[$field] !== '') {
            $decoded = json_decode($row[$field], true);
            $row[$field] = is_array($decoded) ? $decoded : null;
        }
    }
    foreach (['id', 'priority', 'attempts', 'max_attempts'] as $field) {
        if (isset($row[$field])) $row[$field] = (int)$row[$field];
    }
    return $row;
}

function createDashboardTask(PDO $db, array $me, array $allowedBmIds, array $input): void {
    $taskType = strtolower(trim((string)($input['task_type'] ?? $input['type'] ?? '')));
    if (in_array($taskType, ['campaign_status', 'set_status'], true)) $taskType = 'set_campaign_status';
    if (in_array($taskType, ['adset_status', 'set_adset_status'], true)) $taskType = 'set_adset_status';
    if (in_array($taskType, ['ad_status', 'set_ad_status'], true)) $taskType = 'set_ad_status';
    if (in_array($taskType, ['ad_text_refresh', 'edit_ad_text', 'refresh_ad_text'], true)) $taskType = 'refresh_ad_text';
    if (in_array($taskType, ['campaign_delete', 'delete'], true)) $taskType = 'delete_campaign';
    if (!in_array($taskType, ['set_campaign_status', 'set_adset_status', 'set_ad_status', 'delete_campaign', 'update_adset_bid', 'refresh_ad_text', 'appeal_ad'], true)) {
        apiError(400, 'Unsupported task_type');
    }

    $payload = $input['payload'] ?? [];
    if (!is_array($payload)) $payload = [];
    $createdBy = 'dashboard:' . (string)($me['username'] ?? $me['email'] ?? $me['id'] ?? 'user');
    $priority = (int)($input['priority'] ?? 200);
    $allowed = array_map('strval', $allowedBmIds);

    if (in_array($taskType, ['set_campaign_status', 'delete_campaign'], true)) {
        $campaignId = trim((string)($input['campaign_id'] ?? $input['id'] ?? ''));
        if ($campaignId === '') apiError(400, 'campaign_id required');

        $stmt = $db->prepare("
            SELECT
                c.id::text AS campaign_id,
                c.name AS campaign_name,
                c.status AS campaign_status,
                c.effective_status AS campaign_effective_status,
                c.ad_account_id::text AS account_id,
                aa.bm_id::text AS bm_id
            FROM public.campaigns c
            JOIN public.ad_accounts aa ON aa.id = c.ad_account_id
            WHERE c.id::text = :campaign_id
            LIMIT 1
        ");
        $stmt->execute([':campaign_id' => $campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$campaign) apiError(404, 'Campaign not found');
        if (!in_array((string)$campaign['bm_id'], $allowed, true)) apiError(403, 'BM access denied');

        if ($taskType === 'set_campaign_status') {
            $desiredStatus = strtoupper(trim((string)($input['desired_status'] ?? $input['status'] ?? $payload['desired_status'] ?? '')));
            if (!in_array($desiredStatus, ['ACTIVE', 'PAUSED'], true)) apiError(400, 'desired_status must be ACTIVE or PAUSED');

            $payload = array_merge($payload, [
                'desired_status' => $desiredStatus,
                'manual' => true,
                'source' => 'dashboard_toggle',
                'campaign_name' => $campaign['campaign_name'],
            ]);
        } else {
            $payload = array_merge($payload, [
                'manual' => true,
                'source' => 'dashboard_delete',
                'delete' => true,
                'campaign_name' => $campaign['campaign_name'],
            ]);
        }

        $insert = $db->prepare("
            INSERT INTO public.tasks
                (task_type, status, priority, bm_id, account_id, campaign_id, payload, created_by, max_attempts)
            VALUES
                (:task_type, 'pending', :priority, :bm_id, :account_id, :campaign_id,
                 CAST(:payload AS jsonb), :created_by, 3)
            RETURNING *
        ");
        $insert->execute([
            ':task_type' => $taskType,
            ':priority' => $priority,
            ':bm_id' => (string)$campaign['bm_id'],
            ':account_id' => (string)$campaign['account_id'],
            ':campaign_id' => $campaignId,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => $createdBy,
        ]);
        $row = $insert->fetch(PDO::FETCH_ASSOC);
        GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
            'before_state' => [
                'status' => $campaign['campaign_status'] ?? null,
                'effective_status' => $campaign['campaign_effective_status'] ?? null,
            ],
            'reason' => (string)($payload['reason'] ?? ($taskType === 'delete_campaign' ? 'Dashboard campaign delete' : 'Dashboard campaign status change')),
        ]);
        apiOk(['task' => formatDashboardTask($row)]);
    }

    if ($taskType === 'set_adset_status') {
        $adsetId = trim((string)($input['adset_id'] ?? $input['id'] ?? ''));
        if ($adsetId === '') apiError(400, 'adset_id required');

        $desiredStatus = strtoupper(trim((string)($input['desired_status'] ?? $input['status'] ?? $payload['desired_status'] ?? '')));
        if (!in_array($desiredStatus, ['ACTIVE', 'PAUSED'], true)) apiError(400, 'desired_status must be ACTIVE or PAUSED');

        $stmt = $db->prepare("
            SELECT
                s.id::text AS adset_id,
                s.name AS adset_name,
                s.status AS adset_status,
                s.effective_status AS adset_effective_status,
                s.campaign_id::text AS campaign_id,
                c.name AS campaign_name,
                s.ad_account_id::text AS account_id,
                aa.bm_id::text AS bm_id
            FROM public.ad_sets s
            JOIN public.campaigns c ON c.id = s.campaign_id
            JOIN public.ad_accounts aa ON aa.id = s.ad_account_id
            WHERE s.id::text = :adset_id
            LIMIT 1
        ");
        $stmt->execute([':adset_id' => $adsetId]);
        $adset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adset) apiError(404, 'Adset not found');
        if (!in_array((string)$adset['bm_id'], $allowed, true)) apiError(403, 'BM access denied');

        $payload = array_merge($payload, [
            'desired_status' => $desiredStatus,
            'manual' => true,
            'source' => 'dashboard_adset_toggle',
            'adset_name' => $adset['adset_name'],
            'campaign_name' => $adset['campaign_name'],
        ]);

        $insert = $db->prepare("
            INSERT INTO public.tasks
                (task_type, status, priority, bm_id, account_id, campaign_id, adset_id, payload, created_by, max_attempts)
            VALUES
                ('set_adset_status', 'pending', :priority, :bm_id, :account_id, :campaign_id, :adset_id,
                 CAST(:payload AS jsonb), :created_by, 3)
            RETURNING *
        ");
        $insert->execute([
            ':priority' => $priority,
            ':bm_id' => (string)$adset['bm_id'],
            ':account_id' => (string)$adset['account_id'],
            ':campaign_id' => (string)$adset['campaign_id'],
            ':adset_id' => $adsetId,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => $createdBy,
        ]);
        $row = $insert->fetch(PDO::FETCH_ASSOC);
        GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
            'before_state' => [
                'status' => $adset['adset_status'] ?? null,
                'effective_status' => $adset['adset_effective_status'] ?? null,
            ],
            'reason' => (string)($payload['reason'] ?? 'Dashboard adset status change'),
        ]);
        apiOk(['task' => formatDashboardTask($row)]);
    }

    if ($taskType === 'set_ad_status') {
        createAdStatusTask($db, $allowed, $createdBy, $priority, $input, $payload);
    }

    if ($taskType === 'appeal_ad') {
        createAdAppealTask($db, $allowed, $createdBy, $priority, $input, $payload);
    }

    if ($taskType === 'refresh_ad_text') {
        createAdTextRefreshTask($db, $allowed, $createdBy, $priority, $input, $payload);
    }

    $adsetId = trim((string)($input['adset_id'] ?? $payload['adset_id'] ?? $input['id'] ?? ''));
    if ($adsetId === '') apiError(400, 'adset_id required');
    $bidDeltaPct = $payload['bid_delta_pct'] ?? $payload['bidDeltaPct'] ?? $input['bid_delta_pct'] ?? $input['bidDeltaPct'] ?? null;
    $isDeltaTask = $bidDeltaPct !== null && $bidDeltaPct !== '';
    $bidAmount = $payload['bid_amount'] ?? $payload['bidAmount'] ?? $input['bid_amount'] ?? $input['bidAmount'] ?? null;
    if (!$isDeltaTask && ($bidAmount === null || $bidAmount === '' || !is_numeric($bidAmount) || (float)$bidAmount <= 0)) {
        apiError(400, 'bid_amount required');
    }

    $stmt = $db->prepare("
        SELECT
            s.id::text AS adset_id,
            s.name AS adset_name,
            s.status AS adset_status,
            s.effective_status AS adset_effective_status,
            s.campaign_id::text AS campaign_id,
            c.name AS campaign_name,
            s.ad_account_id::text AS account_id,
            aa.bm_id::text AS bm_id,
            s.bid_amount AS current_bid
        FROM public.ad_sets s
        JOIN public.campaigns c ON c.id = s.campaign_id
        JOIN public.ad_accounts aa ON aa.id = s.ad_account_id
        WHERE s.id::text = :adset_id
        LIMIT 1
    ");
    $stmt->execute([':adset_id' => $adsetId]);
    $adset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$adset) apiError(404, 'Adset not found');
    if (!in_array((string)$adset['bm_id'], $allowed, true)) apiError(403, 'BM access denied');

    $payload = array_merge($payload, [
        'current_bid' => $adset['current_bid'] !== null ? round((float)$adset['current_bid'], 2) : null,
        'manual' => true,
        'source' => 'dashboard_bid_popup',
        'adset_name' => $adset['adset_name'],
        'campaign_name' => $adset['campaign_name'],
    ]);
    if ($isDeltaTask) {
        $payload['bid_delta_pct'] = round((float)$bidDeltaPct, 2);
        $payload['bid_delta_dir'] = ((float)$bidDeltaPct >= 0) ? 'UP' : 'DOWN';
        $payload['bid_mode'] = 'delta';
    } else {
        $payload['bid_amount'] = round((float)$bidAmount, 2);
        $payload['bid_amount_cents'] = (int)round(((float)$bidAmount) * 100);
        $payload['bid_mode'] = 'absolute';
    }

    $insert = $db->prepare("
        INSERT INTO public.tasks
            (task_type, status, priority, bm_id, account_id, campaign_id, adset_id, payload, created_by, max_attempts)
        VALUES
            ('update_adset_bid', 'pending', :priority, :bm_id, :account_id, :campaign_id, :adset_id,
             CAST(:payload AS jsonb), :created_by, 3)
        RETURNING *
    ");
    $insert->execute([
        ':priority' => $priority,
        ':bm_id' => (string)$adset['bm_id'],
        ':account_id' => (string)$adset['account_id'],
        ':campaign_id' => (string)$adset['campaign_id'],
        ':adset_id' => $adsetId,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':created_by' => $createdBy,
    ]);
    $row = $insert->fetch(PDO::FETCH_ASSOC);
    GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
        'before_state' => [
            'status' => $adset['adset_status'] ?? null,
            'effective_status' => $adset['adset_effective_status'] ?? null,
            'bid_amount' => $adset['current_bid'] !== null ? round((float)$adset['current_bid'], 2) : null,
        ],
        'reason' => (string)($payload['reason'] ?? 'Dashboard adset bid update'),
    ]);
    apiOk(['task' => formatDashboardTask($row)]);
}

function createAdStatusTask(PDO $db, array $allowedBmIds, string $createdBy, int $priority, array $input, array $payload): void {
    $adId = trim((string)($input['ad_id'] ?? $payload['ad_id'] ?? $input['id'] ?? ''));
    if ($adId === '') apiError(400, 'ad_id required');

    $desiredStatus = strtoupper(trim((string)($input['desired_status'] ?? $input['status'] ?? $payload['desired_status'] ?? '')));
    if (!in_array($desiredStatus, ['ACTIVE', 'PAUSED'], true)) apiError(400, 'desired_status must be ACTIVE or PAUSED');

    $ad = fetchAdTaskTarget($db, $adId);
    if (!$ad) apiError(404, 'Ad not found');
    if (!in_array((string)$ad['bm_id'], $allowedBmIds, true)) apiError(403, 'BM access denied');

    $payload = array_merge($payload, [
        'desired_status' => $desiredStatus,
        'manual' => true,
        'source' => 'dashboard_ad_toggle',
        'ad_id' => $adId,
        'ad_name' => $ad['ad_name'],
        'creative_name' => $ad['ad_name'],
        'adset_name' => $ad['adset_name'],
        'campaign_name' => $ad['campaign_name'],
    ]);

    $row = insertDashboardAdStatusTask($db, [
        'task_type' => 'set_ad_status',
        'priority' => $priority,
        'bm_id' => (string)$ad['bm_id'],
        'account_id' => (string)$ad['account_id'],
        'campaign_id' => (string)$ad['campaign_id'],
        'adset_id' => (string)$ad['adset_id'],
        'ad_id' => $adId,
        'payload' => $payload,
        'created_by' => $createdBy,
        'max_attempts' => 3,
    ]);
    GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
        'before_state' => [
            'status' => $ad['ad_status'] ?? null,
            'effective_status' => $ad['ad_effective_status'] ?? null,
        ],
        'reason' => (string)($payload['reason'] ?? 'Dashboard ad status change'),
    ]);
    apiOk(['task' => formatDashboardTask($row)]);
}

function createAdAppealTask(PDO $db, array $allowedBmIds, string $createdBy, int $priority, array $input, array $payload): void {
    $adId = trim((string)($input['ad_id'] ?? $payload['ad_id'] ?? $input['id'] ?? ''));
    if ($adId === '') apiError(400, 'ad_id required');

    $ad = fetchAdTaskTarget($db, $adId);
    if (!$ad) apiError(404, 'Ad not found');
    if (!in_array((string)$ad['bm_id'], $allowedBmIds, true)) apiError(403, 'BM access denied');

    $status = strtoupper(trim((string)($ad['ad_effective_status'] ?? $ad['ad_status'] ?? '')));
    if ($status !== 'DISAPPROVED') apiError(409, 'Only disapproved ads can be appealed');
    if (hasOpenAdTask($db, $adId, ['set_ad_status', 'refresh_ad_text', 'appeal_ad'])) {
        apiError(409, 'Ad task already in progress');
    }

    $payload = array_merge($payload, [
        'manual' => true,
        'source' => 'dashboard_ad_appeal',
        'appeal_comment' => trim((string)($payload['appeal_comment'] ?? $input['appeal_comment'] ?? "I'm not sure which policy was violated.")),
        'one_time' => isset($payload['one_time']) ? (bool)$payload['one_time'] : true,
        'ad_id' => $adId,
        'ad_name' => $ad['ad_name'],
        'creative_name' => $ad['ad_name'],
        'adset_name' => $ad['adset_name'],
        'campaign_name' => $ad['campaign_name'],
    ]);

    $row = insertDashboardAdStatusTask($db, [
        'task_type' => 'appeal_ad',
        'priority' => $priority,
        'bm_id' => (string)$ad['bm_id'],
        'account_id' => (string)$ad['account_id'],
        'campaign_id' => (string)$ad['campaign_id'],
        'adset_id' => (string)$ad['adset_id'],
        'ad_id' => $adId,
        'payload' => $payload,
        'created_by' => $createdBy,
        'max_attempts' => 3,
    ]);
    GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
        'before_state' => [
            'status' => $ad['ad_status'] ?? null,
            'effective_status' => $ad['ad_effective_status'] ?? null,
        ],
        'reason' => (string)($payload['reason'] ?? 'Dashboard ad appeal'),
    ]);
    apiOk(['task' => formatDashboardTask($row)]);
}

function createAdTextRefreshTask(PDO $db, array $allowedBmIds, string $createdBy, int $priority, array $input, array $payload): void {
    $adId = trim((string)($input['ad_id'] ?? $payload['ad_id'] ?? $input['id'] ?? ''));
    if ($adId === '') apiError(400, 'ad_id required');

    $ad = fetchAdTaskTarget($db, $adId);
    if (!$ad) apiError(404, 'Ad not found');
    if (!in_array((string)$ad['bm_id'], $allowedBmIds, true)) apiError(403, 'BM access denied');

    $status = strtoupper(trim((string)($ad['ad_effective_status'] ?? $ad['ad_status'] ?? '')));
    if ($status !== 'DISAPPROVED') apiError(409, 'Only disapproved ads can be refreshed');
    if (hasOpenAdTask($db, $adId, ['set_ad_status', 'refresh_ad_text', 'appeal_ad'])) {
        apiError(409, 'Ad task already in progress');
    }

    $payload = array_merge($payload, [
        'manual' => true,
        'source' => 'dashboard_ad_text_refresh',
        'mode' => 'append_dot',
        'text_scope' => 'main_ad_text',
        'preserve_languages' => true,
        'ad_id' => $adId,
        'ad_name' => $ad['ad_name'],
        'creative_name' => $ad['ad_name'],
        'adset_name' => $ad['adset_name'],
        'campaign_name' => $ad['campaign_name'],
    ]);

    $row = insertDashboardAdStatusTask($db, [
        'task_type' => 'refresh_ad_text',
        'priority' => $priority,
        'bm_id' => (string)$ad['bm_id'],
        'account_id' => (string)$ad['account_id'],
        'campaign_id' => (string)$ad['campaign_id'],
        'adset_id' => (string)$ad['adset_id'],
        'ad_id' => $adId,
        'payload' => $payload,
        'created_by' => $createdBy,
        'max_attempts' => 3,
    ]);
    GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
        'before_state' => [
            'status' => $ad['ad_status'] ?? null,
            'effective_status' => $ad['ad_effective_status'] ?? null,
        ],
        'reason' => (string)($payload['reason'] ?? 'Append a dot to main ad text and resubmit'),
    ]);
    apiOk(['task' => formatDashboardTask($row)]);
}

function pauseCreativeAdsTasks(PDO $db, array $me, array $allowedBmIds, array $input): void {
    $creativeName = trim((string)($input['creative_name'] ?? $input['ad_name'] ?? $input['name'] ?? ''));
    if ($creativeName === '') apiError(400, 'creative_name required');

    $allowed = array_map('strval', $allowedBmIds);
    $createdBy = 'dashboard:' . (string)($me['username'] ?? $me['email'] ?? $me['id'] ?? 'user');
    $priority = (int)($input['priority'] ?? 210);
    $where = [
        'a.name = :creative_name',
        "COALESCE(a.status, '') != 'DELETED'",
        "COALESCE(s.status, '') != 'DELETED'",
        "COALESCE(c.status, '') != 'DELETED'",
        "(COALESCE(a.status, '') = 'ACTIVE' OR COALESCE(a.effective_status, '') = 'ACTIVE')",
    ];
    $params = [':creative_name' => $creativeName];
    $bmPh = [];
    foreach ($allowed as $i => $bmId) {
        $k = ":bm_{$i}";
        $bmPh[] = $k;
        $params[$k] = $bmId;
    }
    $where[] = 'aa.bm_id::text IN (' . implode(',', $bmPh) . ')';

    $geo = strtoupper(trim((string)($input['geo'] ?? '')));
    if ($geo !== '') {
        $geos = array_values(array_filter(array_map('trim', explode(',', $geo)), fn($g) => preg_match('/^[A-Z]{2}$/', $g)));
        if ($geos) {
            $geoPh = [];
            foreach ($geos as $i => $g) {
                $k = ":geo_{$i}";
                $geoPh[] = $k;
                $params[$k] = $g;
            }
            $where[] = "campaign_geo(c.name) IN (" . implode(',', $geoPh) . ")";
        }
    }
    foreach ([
        'bm_id' => 'aa.bm_id::text',
        'account_id' => 'aa.id::text',
        'campaign_id' => 'c.id::text',
        'adset_id' => 's.id::text',
    ] as $inputKey => $field) {
        $value = trim((string)($input[$inputKey] ?? ''));
        if ($value === '') continue;
        if ($inputKey === 'account_id') $value = normalizeDashboardAccountId($value);
        $param = ':' . $inputKey;
        $where[] = "{$field} = {$param}";
        $params[$param] = $value;
    }

    $stmt = $db->prepare("
        SELECT
            a.id::text AS ad_id,
            a.name AS ad_name,
            a.status AS ad_status,
            a.effective_status AS ad_effective_status,
            a.ad_set_id::text AS adset_id,
            s.name AS adset_name,
            a.campaign_id::text AS campaign_id,
            c.name AS campaign_name,
            a.ad_account_id::text AS account_id,
            aa.bm_id::text AS bm_id
        FROM public.ads a
        JOIN public.ad_sets s ON s.id = a.ad_set_id
        JOIN public.campaigns c ON c.id = a.campaign_id
        JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY aa.bm_id, a.ad_account_id, c.name, s.name, a.id
        LIMIT 5000
    ");
    $stmt->execute($params);
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$ads) apiOk(['count' => 0, 'tasks' => [], 'skipped_existing' => 0, 'message' => 'No active ads found for this creative']);

    $created = [];
    $skipped = 0;
    foreach ($ads as $ad) {
        if (hasOpenAdTask($db, (string)$ad['ad_id'], ['set_ad_status'])) {
            $skipped++;
            continue;
        }
        $payload = [
            'desired_status' => 'PAUSED',
            'manual' => true,
            'source' => 'dashboard_creo_pause',
            'creative_name' => $creativeName,
            'ad_id' => (string)$ad['ad_id'],
            'ad_name' => $ad['ad_name'],
            'adset_name' => $ad['adset_name'],
            'campaign_name' => $ad['campaign_name'],
            'reason' => 'Pause all active ads for creative ' . $creativeName,
        ];
        $row = insertDashboardAdStatusTask($db, [
            'task_type' => 'set_ad_status',
            'priority' => $priority,
            'bm_id' => (string)$ad['bm_id'],
            'account_id' => (string)$ad['account_id'],
            'campaign_id' => (string)$ad['campaign_id'],
            'adset_id' => (string)$ad['adset_id'],
            'ad_id' => (string)$ad['ad_id'],
            'payload' => $payload,
            'created_by' => $createdBy,
            'max_attempts' => 3,
        ]);
        GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
            'before_state' => [
                'status' => $ad['ad_status'] ?? null,
                'effective_status' => $ad['ad_effective_status'] ?? null,
            ],
            'reason' => $payload['reason'],
        ]);
        $created[] = formatDashboardTask($row);
    }

    apiOk([
        'count' => count($created),
        'tasks' => $created,
        'matched_ads' => count($ads),
        'skipped_existing' => $skipped,
    ]);
}

function fetchAdTaskTarget(PDO $db, string $adId): ?array {
    $stmt = $db->prepare("
        SELECT
            a.id::text AS ad_id,
            a.name AS ad_name,
            a.status AS ad_status,
            a.effective_status AS ad_effective_status,
            a.ad_set_id::text AS adset_id,
            s.name AS adset_name,
            a.campaign_id::text AS campaign_id,
            c.name AS campaign_name,
            a.ad_account_id::text AS account_id,
            aa.bm_id::text AS bm_id
        FROM public.ads a
        JOIN public.ad_sets s ON s.id = a.ad_set_id
        JOIN public.campaigns c ON c.id = a.campaign_id
        JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
        WHERE a.id::text = :ad_id
        LIMIT 1
    ");
    $stmt->execute([':ad_id' => $adId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hasOpenAdTask(PDO $db, string $adId, array $taskTypes): bool {
    if (!$taskTypes) return false;
    $ph = [];
    $params = [':ad_id' => $adId];
    foreach (array_values($taskTypes) as $i => $taskType) {
        $key = ":type_{$i}";
        $ph[] = $key;
        $params[$key] = $taskType;
    }
    $stmt = $db->prepare("
        SELECT 1
        FROM public.tasks
        WHERE task_type IN (" . implode(',', $ph) . ")
          AND status IN ('pending', 'running')
          AND ad_id = :ad_id
        LIMIT 1
    ");
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function insertDashboardAdStatusTask(PDO $db, array $task): array {
    $stmt = $db->prepare("
        INSERT INTO public.tasks
            (task_type, status, priority, bm_id, account_id, campaign_id, adset_id, ad_id, payload, created_by, max_attempts)
        VALUES
            (:task_type, 'pending', :priority, :bm_id, :account_id, :campaign_id, :adset_id, :ad_id,
             CAST(:payload AS jsonb), :created_by, :max_attempts)
        RETURNING *
    ");
    $stmt->execute([
        ':task_type' => $task['task_type'],
        ':priority' => $task['priority'],
        ':bm_id' => $task['bm_id'],
        ':account_id' => $task['account_id'],
        ':campaign_id' => $task['campaign_id'],
        ':adset_id' => $task['adset_id'],
        ':ad_id' => $task['ad_id'],
        ':payload' => json_encode($task['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':created_by' => $task['created_by'],
        ':max_attempts' => $task['max_attempts'],
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function normalizeDashboardAccountId(string $id): string {
    $id = trim($id);
    return $id !== '' && preg_match('/^\d+$/', $id) ? 'act_' . $id : $id;
}

function retryDashboardTask(PDO $db, array $allowedBmIds, array $input, bool $allowPending): void {
    $taskId = (int)($input['task_id'] ?? $input['id'] ?? 0);
    if ($taskId <= 0) apiError(400, 'task_id required');

    $allowed = array_map('strval', $allowedBmIds);
    $ph = [];
    $params = [':id' => $taskId];
    foreach ($allowed as $i => $bmId) {
        $key = ":bm_{$i}";
        $ph[] = $key;
        $params[$key] = $bmId;
    }
    $stmt = $db->prepare("
        SELECT *
        FROM public.tasks
        WHERE id = :id
          AND bm_id IN (" . implode(',', $ph) . ")
        LIMIT 1
    ");
    $stmt->execute($params);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) apiError(404, 'Task not found');
    $status = (string)($task['status'] ?? '');
    $createdAt = isset($task['created_at']) ? strtotime((string)$task['created_at']) : false;
    $ageSeconds = $createdAt ? max(0, time() - $createdAt) : null;
    $canRetryPending = $allowPending && $status === 'pending' && ($ageSeconds === null || $ageSeconds >= 90);
    if ($status !== 'failed' && !$canRetryPending) {
        if ($status === 'pending') {
            apiError(409, 'Task is still pending');
        }
        apiError(409, 'Only failed tasks can be retried');
    }

    $payload = $task['payload'] ?? [];
    if (is_string($payload) && $payload !== '') {
        $decoded = json_decode($payload, true);
        $payload = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($payload)) {
        $payload = [];
    }
    $payload['retry_of'] = (int)$task['id'];

    $priority = (int)($task['priority'] ?? 100);
    $priority = min(1000, max(0, $priority + 50));
    $maxAttempts = (int)($task['max_attempts'] ?? 3);

    $db->beginTransaction();
    try {
        if ($canRetryPending) {
            $cancel = $db->prepare("
                UPDATE public.tasks
                SET status = 'cancelled',
                    error = COALESCE(error, 'Superseded by retry'),
                    finished_at = COALESCE(finished_at, NOW()),
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");
            $cancel->execute([':id' => $taskId]);
            $cancelled = $cancel->fetch(PDO::FETCH_ASSOC);
            if (!$cancelled) {
                throw new RuntimeException('Task not found during cancellation');
            }
        }

        $insert = $db->prepare("
            INSERT INTO public.tasks
                (task_type, status, priority, bm_id, account_id, campaign_id, adset_id, ad_id,
                 payload, created_by, max_attempts, run_after)
            VALUES
                (:task_type, 'pending', :priority, :bm_id, :account_id, :campaign_id, :adset_id, :ad_id,
                 CAST(:payload AS jsonb), :created_by, :max_attempts, NOW())
            RETURNING *
        ");
        $insert->execute([
            ':task_type' => $task['task_type'],
            ':priority' => $priority,
            ':bm_id' => (string)($task['bm_id'] ?? ''),
            ':account_id' => (string)($task['account_id'] ?? ''),
            ':campaign_id' => $task['campaign_id'] ?? null,
            ':adset_id' => $task['adset_id'] ?? null,
            ':ad_id' => $task['ad_id'] ?? null,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => (string)($task['created_by'] ?? 'dashboard'),
            ':max_attempts' => $maxAttempts,
        ]);
        $row = $insert->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Retry task insert failed');
        }
        GlobalLogger::logTaskEvent($db, 'task_retried', 'pending', $row, [
            'reason' => $canRetryPending ? 'Dashboard retry for stale pending task' : 'Dashboard retry',
            'before_state' => formatDashboardTask($task),
            'after_state' => formatDashboardTask($row),
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    apiOk(['task' => formatDashboardTask($row)]);
}

function deleteDashboardTask(PDO $db, array $allowedBmIds, array $input): void {
    $taskId = (int)($input['task_id'] ?? $input['id'] ?? 0);
    if ($taskId <= 0) apiError(400, 'task_id required');

    $allowed = array_map('strval', $allowedBmIds);
    $ph = [];
    $params = [':id' => $taskId];
    foreach ($allowed as $i => $bmId) {
        $key = ":bm_{$i}";
        $ph[] = $key;
        $params[$key] = $bmId;
    }

    $stmt = $db->prepare("
        SELECT *
        FROM public.tasks
        WHERE id = :id
          AND bm_id IN (" . implode(',', $ph) . ")
        LIMIT 1
    ");
    $stmt->execute($params);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) apiError(404, 'Task not found');
    if (($task['status'] ?? '') === 'running') apiError(409, 'Running tasks cannot be deleted');

    GlobalLogger::logTaskEvent($db, 'task_deleted', 'cancelled', $task, [
        'reason' => 'Dashboard task deletion',
        'before_state' => formatDashboardTask($task),
    ]);

    $delete = $db->prepare("
        DELETE FROM public.tasks
        WHERE id = :id
        RETURNING *
    ");
    $delete->execute([':id' => $taskId]);
    apiOk(['task' => formatDashboardTask($delete->fetch(PDO::FETCH_ASSOC))]);
}

function ensureTasksDashboardSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.tasks (
            id BIGSERIAL PRIMARY KEY,
            task_type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            priority INTEGER NOT NULL DEFAULT 100,
            bm_id TEXT NOT NULL DEFAULT '',
            account_id TEXT NOT NULL DEFAULT '',
            campaign_id TEXT,
            adset_id TEXT,
            ad_id TEXT,
            payload JSONB NOT NULL DEFAULT '{}'::jsonb,
            result JSONB,
            error TEXT,
            idempotency_key TEXT,
            created_by TEXT NOT NULL DEFAULT 'system',
            locked_by TEXT,
            locked_at TIMESTAMPTZ,
            run_after TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            started_at TIMESTAMPTZ,
            finished_at TIMESTAMPTZ,
            CONSTRAINT tasks_type_chk CHECK (task_type IN (
                'set_campaign_status',
                'set_adset_status',
                'set_ad_status',
                'appeal_ad',
                'delete_campaign',
                'update_campaign_budget',
                'update_adset_budget',
                'update_adset_bid',
                'refresh_ad_text',
                'create_campaign'
            )),
            CONSTRAINT tasks_status_chk CHECK (status IN (
                'pending',
                'running',
                'done',
                'failed',
                'cancelled'
            ))
        );
        CREATE INDEX IF NOT EXISTS idx_tasks_poll
            ON public.tasks (status, run_after, priority DESC, created_at);
        CREATE INDEX IF NOT EXISTS idx_tasks_targets
            ON public.tasks (bm_id, account_id, campaign_id, adset_id);
        ALTER TABLE IF EXISTS public.tasks
            ADD COLUMN IF NOT EXISTS ad_id TEXT;
        CREATE INDEX IF NOT EXISTS idx_tasks_ad_target
            ON public.tasks (ad_id, status, task_type)
            WHERE ad_id IS NOT NULL;
        CREATE INDEX IF NOT EXISTS idx_tasks_type_status
            ON public.tasks (task_type, status, created_at DESC);
        ALTER TABLE IF EXISTS public.tasks
            DROP CONSTRAINT IF EXISTS tasks_type_chk;
            ALTER TABLE IF EXISTS public.tasks
                ADD CONSTRAINT tasks_type_chk CHECK (task_type IN (
                    'set_campaign_status',
                    'set_adset_status',
                    'set_ad_status',
                    'appeal_ad',
                    'delete_campaign',
                    'update_campaign_budget',
                    'update_adset_budget',
                    'update_adset_bid',
                    'refresh_ad_text',
                    'create_campaign'
                ));
    ");
}

function ensureAdsetBidFields(PDO $db): void {
    $db->exec("
        ALTER TABLE IF EXISTS ad_sets
            ADD COLUMN IF NOT EXISTS bid_amount NUMERIC(15,2),
            ADD COLUMN IF NOT EXISTS bid_strategy_mode TEXT;
    ");
}
