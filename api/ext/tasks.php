<?php
// api/ext/tasks.php
// @version 1.0.5
// POST { secret, action, ... }
// Actions:
// - schema
// - enqueue { task:{...} } or { tasks:[...] }
// - poll { worker_id, limit, active_account_ids?, bm_id?, account_id?, task_types? } returns matching tasks without DB locks
// - get { task_id }
// - list { status?, task_type?, bm_id?, account_id?, limit? }
// - manual_stops { bm_id?, account_id?, account_ids?, campaign_id? }
// - update { task_id, status, result?, error?, worker_id? }

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../lib/GlobalLogger.php';

const TASK_TYPES = [
    'set_campaign_status',
    'set_adset_status',
    'set_ad_status',
    'appeal_ad',
    'delete_campaign',
    'update_campaign_budget',
    'update_adset_budget',
    'update_adset_bid',
    'refresh_ad_text',
    'create_campaign',
];

const TASK_STATUSES = ['pending', 'running', 'done', 'failed', 'cancelled'];

ensureTasksSchema($db);
ensureTaskWorkersSchema($db);
GlobalLogger::ensureSchema($db);
$db->exec("
    ALTER TABLE IF EXISTS ad_sets
        ADD COLUMN IF NOT EXISTS bid_amount NUMERIC(15,2),
        ADD COLUMN IF NOT EXISTS bid_strategy_mode TEXT;
");

$action = strtolower(trim((string)($body['action'] ?? 'schema')));

try {
    switch ($action) {
        case 'schema':
            extOk(['schema' => taskSchema()]);

        case 'enqueue':
            $items = $body['tasks'] ?? null;
            if ($items === null && isset($body['task'])) $items = [$body['task']];
            if (!is_array($items) || !$items) extError(400, 'task or tasks array required');
            $created = [];
            foreach ($items as $item) {
                if (!is_array($item)) extError(400, 'Each task must be an object');
                $task = normalizeTask($item);
                $created[] = insertTask($db, $task);
            }
            extOk(['count' => count($created), 'tasks' => $created]);

        case 'register':
        case 'heartbeat':
            $worker = upsertWorker($db, $body, $action === 'heartbeat');
            extOk(['worker' => $worker]);

        case 'poll':
            $limit = clampInt($body['limit'] ?? 10, 1, 100);
            $workerId = trim((string)($body['worker_id'] ?? 'external-worker'));
            $rows = pollTasks($db, $body, $workerId, $limit);
            $out = ['count' => count($rows), 'tasks' => array_map('formatTaskRow', $rows)];
            if (boolInput($body['debug'] ?? $body['diagnostics'] ?? false)) {
                $out['diagnostics'] = pollDiagnostics($db, $body, $workerId);
            }
            extOk($out);

        case 'get':
            $taskId = (int)($body['task_id'] ?? 0);
            if ($taskId <= 0) extError(400, 'task_id required');
            $task = fetchTask($db, $taskId);
            if (!$task) extError(404, 'Task not found');
            extOk(['task' => formatTaskRow($task)]);

        case 'list':
            $rows = listTasks($db, $body);
            extOk(['count' => count($rows), 'tasks' => array_map('formatTaskRow', $rows)]);

        case 'workers':
            $workers = listTaskWorkers($db, $body);
            extOk(['count' => count($workers), 'workers' => $workers]);

        case 'manual_stops':
            $rows = listManualStops($db, $body);
            extOk(['count' => count($rows), 'campaigns' => $rows]);

        case 'update':
            $taskId = (int)($body['task_id'] ?? 0);
            if ($taskId <= 0) extError(400, 'task_id required');
            $status = strtolower(trim((string)($body['status'] ?? $body['task_status'] ?? $body['state'] ?? '')));
            if (!in_array($status, TASK_STATUSES, true)) extError(400, 'Invalid status');
            $task = updateTask($db, $taskId, $status, $body);
            extOk(['task' => formatTaskRow($task)]);

        default:
            extError(400, 'Unknown action');
    }
} catch (Throwable $e) {
    extError(500, $e->getMessage());
}

function ensureTasksSchema(PDO $db): void {
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
        CREATE UNIQUE INDEX IF NOT EXISTS idx_tasks_idempotency_key
            ON public.tasks (idempotency_key)
            WHERE idempotency_key IS NOT NULL AND idempotency_key <> '';
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

function ensureTaskWorkersSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.task_workers (
            worker_id TEXT PRIMARY KEY,
            worker_label TEXT NOT NULL DEFAULT '',
            profile_name TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'online',
            last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            last_poll_at TIMESTAMPTZ,
            last_poll_count INTEGER NOT NULL DEFAULT 0,
            active_account_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
            bm_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
            account_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
            token_updated_at TIMESTAMPTZ,
            meta JSONB NOT NULL DEFAULT '{}'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_task_workers_last_seen
            ON public.task_workers (last_seen_at DESC, updated_at DESC);
        CREATE INDEX IF NOT EXISTS idx_task_workers_status
            ON public.task_workers (status, last_seen_at DESC);
    ");
}

function taskSchema(): array {
    return [
        'task_types' => TASK_TYPES,
        'statuses' => TASK_STATUSES,
        'actions' => ['schema', 'enqueue', 'poll', 'get', 'list', 'workers', 'register', 'heartbeat', 'manual_stops', 'update'],
        'common_fields' => [
            'task_type', 'bm_id', 'account_id', 'campaign_id', 'adset_id', 'ad_id',
            'payload', 'priority', 'run_after', 'idempotency_key', 'created_by',
        ],
        'payloads' => [
            'set_campaign_status' => ['desired_status' => 'ACTIVE|PAUSED'],
            'set_adset_status' => ['desired_status' => 'ACTIVE|PAUSED'],
            'set_ad_status' => ['desired_status' => 'ACTIVE|PAUSED'],
            'appeal_ad' => [
                'appeal_comment' => 'appeal text',
                'one_time' => 'boolean',
                'source' => 'dashboard_ad_appeal',
            ],
            'delete_campaign' => ['delete' => 'true'],
            'update_campaign_budget' => ['daily_budget' => 'USD number', 'daily_budget_cents' => 'optional integer'],
            'update_adset_budget' => ['daily_budget' => 'USD number', 'daily_budget_cents' => 'optional integer'],
            'update_adset_bid' => [
                'bid_amount' => 'USD number',
                'bid_amount_cents' => 'optional integer',
                'bid_delta_pct' => 'decimal percent like 0.10 or -0.20',
            ],
            'refresh_ad_text' => [
                'mode' => 'append_dot',
                'text_scope' => 'main_ad_text',
                'preserve_languages' => 'boolean',
            ],
            'update_adset_bid_delta' => ['bid_delta_pct' => 'decimal percent like 0.10 or -0.20'],
            'create_campaign' => [
                'geo' => '2-letter country code',
                'adsets_num' => 'integer, default 3',
                'ads_num' => 'integer, default 1',
                'dest_url' => 'landing URL',
                'url_params' => 'Meta URL tags',
                'page_id' => 'Facebook Page ID',
                'pixel_id' => 'optional, worker can detect',
                'bm_label' => 'BM short label used in campaign name',
                'daily_budget' => 'USD number',
                'bid_amount' => 'USD number',
                'bid_strategy_mode' => 'bidcap|costcap|auto',
                'random_bid_cap' => 'boolean',
                'bid_spread_pct' => 'integer',
                'use_languages' => 'boolean',
                'use_target_geos' => 'boolean',
                'no_text' => 'boolean',
                'approach' => 'creative text approach',
                'text_geo' => 'optional text GEO override',
                'chosen_videos' => 'array from mlrMetaAdsCreator export',
            ],
            'register' => [
                'worker_id' => 'stable executor id',
                'worker_label' => 'human readable profile label',
                'profile_name' => 'browser profile name',
                'bm_ids' => 'array of BM ids',
                'account_ids' => 'array of account ids',
                'active_account_ids' => 'array of active account ids',
                'token_updated_at' => 'timestamp',
            ],
        ],
    ];
}

function normalizeTask(array $input): array {
    $type = normalizeTaskType((string)($input['task_type'] ?? $input['type'] ?? ''));
    if (!in_array($type, TASK_TYPES, true)) extError(400, 'Invalid task_type');

    $payload = $input['payload'] ?? [];
    if (!is_array($payload)) extError(400, 'payload must be object');

    $task = [
        'task_type' => $type,
        'priority' => clampInt($input['priority'] ?? 100, 0, 1000),
        'bm_id' => trim((string)($input['bm_id'] ?? $payload['bm_id'] ?? '')),
        'account_id' => normalizeAccountId((string)($input['account_id'] ?? $payload['account_id'] ?? '')),
        'campaign_id' => nullableString($input['campaign_id'] ?? $payload['campaign_id'] ?? null),
        'adset_id' => nullableString($input['adset_id'] ?? $payload['adset_id'] ?? null),
        'ad_id' => nullableString($input['ad_id'] ?? $payload['ad_id'] ?? null),
        'payload' => normalizePayload($type, $payload),
        'run_after' => nullableString($input['run_after'] ?? null),
        'idempotency_key' => nullableString($input['idempotency_key'] ?? null),
        'created_by' => trim((string)($input['created_by'] ?? 'external')),
        'max_attempts' => clampInt($input['max_attempts'] ?? 3, 1, 20),
    ];

    validateTask($task);
    return $task;
}

function normalizeTaskType(string $type): string {
    $type = strtolower(trim($type));
    return match ($type) {
        'campaign_status', 'set_status', 'set_campaign_status' => 'set_campaign_status',
        'adset_status', 'set_adset_status' => 'set_adset_status',
        'ad_status', 'set_ad_status' => 'set_ad_status',
        'appeal', 'ad_appeal', 'appeal_ad' => 'appeal_ad',
        'campaign_delete', 'delete', 'delete_campaign' => 'delete_campaign',
        'campaign_budget', 'update_campaign_budget' => 'update_campaign_budget',
        'adset_budget', 'update_adset_budget' => 'update_adset_budget',
        'adset_bid', 'update_adset_bid' => 'update_adset_bid',
        'ad_text_refresh', 'edit_ad_text', 'refresh_ad_text' => 'refresh_ad_text',
        'campaign_create', 'create_campaign' => 'create_campaign',
        default => $type,
    };
}

function normalizePayload(string $type, array $payload): array {
    if (in_array($type, ['set_campaign_status', 'set_adset_status', 'set_ad_status'], true)) {
        $payload['desired_status'] = strtoupper(trim((string)($payload['desired_status'] ?? $payload['status'] ?? '')));
    }

    if (in_array($type, ['update_campaign_budget', 'update_adset_budget'], true)) {
        normalizeMoneyPayload($payload, 'daily_budget', 'daily_budget_cents');
    }

    if ($type === 'update_adset_bid') {
        normalizeMoneyPayload($payload, 'bid_amount', 'bid_amount_cents');
        if (isset($payload['bid_delta_pct']) || isset($payload['bidDeltaPct'])) {
            $payload['bid_delta_pct'] = (float)($payload['bid_delta_pct'] ?? $payload['bidDeltaPct']);
            if (!isset($payload['bid_mode'])) $payload['bid_mode'] = 'delta';
        }
    }

    if ($type === 'refresh_ad_text') {
        $payload['mode'] = strtolower(trim((string)($payload['mode'] ?? $payload['refresh_mode'] ?? 'append_dot')));
        if ($payload['mode'] === '') $payload['mode'] = 'append_dot';
        $payload['text_scope'] = strtolower(trim((string)($payload['text_scope'] ?? $payload['textScope'] ?? 'main_ad_text')));
        if ($payload['text_scope'] === '') $payload['text_scope'] = 'main_ad_text';
        $payload['preserve_languages'] = (bool)($payload['preserve_languages'] ?? $payload['preserveLanguages'] ?? true);
        unset($payload['refresh_mode'], $payload['textScope'], $payload['preserveLanguages']);
    }

    if ($type === 'appeal_ad') {
        $payload['appeal_comment'] = trim((string)($payload['appeal_comment'] ?? $payload['appealComment'] ?? "I'm not sure which policy was violated."));
        $payload['one_time'] = (bool)($payload['one_time'] ?? $payload['oneTime'] ?? true);
        $payload['source'] = (string)($payload['source'] ?? 'dashboard_ad_appeal');
        unset($payload['appealComment'], $payload['oneTime']);
    }

    if ($type === 'create_campaign') {
        $payload['geo'] = strtoupper(trim((string)($payload['geo'] ?? '')));
        $payload['adsets_num'] = clampInt($payload['adsets_num'] ?? $payload['adsetsNum'] ?? 3, 1, 50);
        $payload['ads_num'] = clampInt($payload['ads_num'] ?? $payload['adsNum'] ?? 1, 1, 50);
        $payload['daily_budget'] = moneyNumber($payload['daily_budget'] ?? $payload['dailyBudget'] ?? null);
        $payload['bid_amount'] = moneyNumber($payload['bid_amount'] ?? $payload['bidAmount'] ?? null);
        $payload['bid_strategy_mode'] = normalizeBidStrategy((string)($payload['bid_strategy_mode'] ?? $payload['bidStrategyMode'] ?? 'bidcap'));
        $payload['random_bid_cap'] = (bool)($payload['random_bid_cap'] ?? $payload['randomBidCap'] ?? false);
        $payload['bid_spread_pct'] = clampInt($payload['bid_spread_pct'] ?? $payload['bidSpreadPct'] ?? 20, 0, 100);
        $payload['use_languages'] = (bool)($payload['use_languages'] ?? $payload['useLanguages'] ?? true);
        $payload['use_target_geos'] = (bool)($payload['use_target_geos'] ?? $payload['useTargetGeos'] ?? false);
        $payload['no_text'] = (bool)($payload['no_text'] ?? $payload['noText'] ?? true);
        $payload['dest_url'] = trim((string)($payload['dest_url'] ?? $payload['destUrl'] ?? ''));
        $payload['url_params'] = (string)($payload['url_params'] ?? $payload['urlParams'] ?? '');
        $payload['page_id'] = nullableString($payload['page_id'] ?? $payload['pageId'] ?? null);
        $payload['pixel_id'] = nullableString($payload['pixel_id'] ?? $payload['pixelId'] ?? null);
        $payload['bm_label'] = (string)($payload['bm_label'] ?? $payload['bmLabel'] ?? $payload['bm_num'] ?? $payload['bmNum'] ?? '');
        $payload['approach'] = (string)($payload['approach'] ?? 'rtp98');
        $payload['text_geo'] = (string)($payload['text_geo'] ?? $payload['textGeo'] ?? '');
        if (!isset($payload['chosen_videos']) && isset($payload['chosenVideos'])) {
            $payload['chosen_videos'] = $payload['chosenVideos'];
        }
        unset(
            $payload['adsetsNum'], $payload['adsNum'], $payload['dailyBudget'], $payload['bidAmount'],
            $payload['bidStrategyMode'], $payload['randomBidCap'], $payload['bidSpreadPct'],
            $payload['useLanguages'], $payload['useTargetGeos'], $payload['noText'],
            $payload['destUrl'], $payload['urlParams'], $payload['pageId'], $payload['pixelId'],
            $payload['bmLabel'], $payload['bm_num'], $payload['bmNum'], $payload['textGeo'], $payload['chosenVideos']
        );
    }

    return $payload;
}

function normalizeMoneyPayload(array &$payload, string $moneyKey, string $centsKey): void {
    $money = moneyNumber($payload[$moneyKey] ?? null);
    $cents = isset($payload[$centsKey]) ? (int)$payload[$centsKey] : null;
    if ($money === null && $cents !== null) $money = $cents / 100;
    if ($money !== null) {
        $payload[$moneyKey] = $money;
        $payload[$centsKey] = (int)round($money * 100);
    }
}

function validateTask(array $task): void {
    $type = $task['task_type'];
    $payload = $task['payload'];
    if ($task['bm_id'] === '') extError(400, 'bm_id required');
    if ($task['account_id'] === '') extError(400, 'account_id required');

    if (in_array($type, ['set_campaign_status', 'delete_campaign', 'update_campaign_budget'], true) && !$task['campaign_id']) {
        extError(400, 'campaign_id required');
    }
    if (in_array($type, ['set_adset_status', 'update_adset_budget', 'update_adset_bid'], true) && !$task['adset_id']) {
        extError(400, 'adset_id required');
    }
    if (in_array($type, ['set_ad_status', 'refresh_ad_text', 'appeal_ad'], true) && !$task['ad_id']) {
        extError(400, 'ad_id required');
    }

    if (in_array($type, ['set_campaign_status', 'set_adset_status', 'set_ad_status'], true) && !in_array($payload['desired_status'] ?? '', ['ACTIVE', 'PAUSED'], true)) {
        extError(400, 'desired_status must be ACTIVE or PAUSED');
    }
    if ($type === 'delete_campaign' && empty($payload['delete'])) {
        $payload['delete'] = true;
    }
    if (in_array($type, ['update_campaign_budget', 'update_adset_budget'], true) && empty($payload['daily_budget_cents'])) {
        extError(400, 'daily_budget required');
    }
    if ($type === 'update_adset_bid') {
        $hasAbsolute = !empty($payload['bid_amount_cents']);
        $hasDelta = isset($payload['bid_delta_pct']) && $payload['bid_delta_pct'] !== null && $payload['bid_delta_pct'] !== '';
        if (!$hasAbsolute && !$hasDelta) {
            extError(400, 'bid_amount or bid_delta_pct required');
        }
    }
    if ($type === 'refresh_ad_text' && ($payload['mode'] ?? '') !== 'append_dot') {
        extError(400, 'refresh_ad_text payload.mode must be append_dot');
    }
    if ($type === 'appeal_ad' && !in_array(($payload['one_time'] ?? true), [true, 1, '1', 'true'], true)) {
        extError(400, 'appeal_ad payload.one_time must be true');
    }
    if ($type === 'create_campaign') {
        foreach (['geo', 'dest_url', 'page_id', 'daily_budget', 'bid_strategy_mode'] as $key) {
            if (empty($payload[$key])) extError(400, "create_campaign payload.{$key} required");
        }
        if (($payload['bid_strategy_mode'] ?? '') !== 'auto' && empty($payload['bid_amount'])) {
            extError(400, 'create_campaign payload.bid_amount required');
        }
        if (!preg_match('/^[A-Z]{2}$/', $payload['geo'])) extError(400, 'create_campaign payload.geo must be 2-letter country code');
    }
}

function insertTask(PDO $db, array $task): array {
    $stmt = $db->prepare("
        INSERT INTO public.tasks
            (task_type, status, priority, bm_id, account_id, campaign_id, adset_id, ad_id,
             payload, idempotency_key, created_by, run_after, max_attempts)
        VALUES
            (:task_type, 'pending', :priority, :bm_id, :account_id, :campaign_id, :adset_id, :ad_id,
             CAST(:payload AS jsonb), :idempotency_key, :created_by, COALESCE(CAST(:run_after AS timestamptz), NOW()), :max_attempts)
        ON CONFLICT (idempotency_key) WHERE idempotency_key IS NOT NULL AND idempotency_key <> ''
        DO UPDATE SET updated_at = NOW()
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
        ':idempotency_key' => $task['idempotency_key'],
        ':created_by' => $task['created_by'],
        ':run_after' => $task['run_after'],
        ':max_attempts' => $task['max_attempts'],
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    GlobalLogger::logTaskEvent($db, 'task_created', (string)($row['status'] ?? 'pending'), $row, [
        'reason' => 'External enqueue',
    ]);
    return formatTaskRow($row);
}

function pollTasks(PDO $db, array $input, string $workerId, int $limit): array {
    $where = ['run_after <= NOW()', 'attempts < max_attempts'];
    $params = [];
    $where[] = "NOT (
        task_type = 'set_campaign_status'
        AND payload->>'source' = 'auto_rules_cron'
        AND NOT EXISTS (
            SELECT 1
            FROM public.campaigns c
            WHERE c.id::text = public.tasks.campaign_id
              AND COALESCE(c.status, '') NOT IN ('DELETED', 'ARCHIVED')
              AND COALESCE(c.effective_status, '') NOT IN ('DELETED', 'ARCHIVED')
        )
    )";

    addOptionalFilter($where, $params, 'bm_id', $input['bm_id'] ?? null);
    addOptionalFilter($where, $params, 'account_id', isset($input['account_id']) ? normalizeAccountId((string)$input['account_id']) : null);
    addAccountIdsFilter($where, $params, $input['active_account_ids'] ?? $input['account_ids'] ?? null);
    addTaskTypesFilter($where, $params, $input['task_types'] ?? $input['task_type'] ?? null);
    addStatusesFilter($where, $params, normalizeStatusList($input['statuses'] ?? null));

    $sql = "
        WITH picked AS (
            SELECT id
            FROM public.tasks
            WHERE " . implode(' AND ', $where) . "
            ORDER BY priority DESC, created_at ASC
            FOR UPDATE SKIP LOCKED
            LIMIT {$limit}
        )
        UPDATE public.tasks t
        SET status = 'running',
            locked_by = COALESCE(CAST(:worker_id AS text), locked_by),
            locked_at = NOW(),
            started_at = COALESCE(started_at, NOW()),
            attempts = attempts + 1,
            updated_at = NOW()
        FROM picked
        WHERE t.id = picked.id
        RETURNING t.*
    ";
    $db->beginTransaction();
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [':worker_id' => $workerId]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        upsertWorker($db, [
            'worker_id' => $workerId,
            'status' => 'online',
            'last_poll_count' => count($rows),
            'last_poll_at' => gmdate('c'),
            'meta' => [
                'poll' => [
                    'task_types' => $input['task_types'] ?? $input['task_type'] ?? null,
                    'bm_id' => $input['bm_id'] ?? null,
                    'account_id' => $input['account_id'] ?? null,
                    'active_account_ids' => $input['active_account_ids'] ?? $input['account_ids'] ?? null,
                ],
            ],
        ], true);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    foreach ($rows as $row) {
        GlobalLogger::logTaskEvent($db, 'task_polled', (string)($row['status'] ?? 'pending'), $row, [
            'actor' => $workerId,
            'reason' => 'Worker poll',
            'payload' => [
                'worker_id' => $workerId,
                'task_payload' => GlobalLogger::decodeJsonish($row['payload'] ?? []),
            ],
        ]);
    }
    return $rows;
}

function upsertWorker(PDO $db, array $input, bool $isHeartbeat = false): array {
    $workerId = trim((string)($input['worker_id'] ?? ''));
    if ($workerId === '') extError(400, 'worker_id required');
    $workerLabel = trim((string)($input['worker_label'] ?? $input['label'] ?? ''));
    $profileName = trim((string)($input['profile_name'] ?? $input['profile'] ?? ''));
    $status = trim((string)($input['status'] ?? 'online'));
    if (!in_array($status, ['online', 'offline', 'busy'], true)) $status = 'online';
    $lastPollCount = clampInt($input['last_poll_count'] ?? 0, 0, 1000);
    $lastPollAt = nullableString($input['last_poll_at'] ?? null);
    $tokenUpdatedAt = nullableString($input['token_updated_at'] ?? null);
    $activeAccountIds = normalizeAccountIdList($input['active_account_ids'] ?? $input['account_ids'] ?? []);
    $bmIds = normalizeTextList($input['bm_ids'] ?? $input['bm_id'] ?? []);
    $accountIds = normalizeAccountIdList($input['account_ids'] ?? []);
    $meta = $input['meta'] ?? [];
    if (!is_array($meta)) $meta = [];
    if ($isHeartbeat && $workerLabel === '' && isset($meta['worker_label'])) $workerLabel = trim((string)$meta['worker_label']);

    $stmt = $db->prepare("
        INSERT INTO public.task_workers
            (worker_id, worker_label, profile_name, status, last_seen_at, last_poll_at, last_poll_count,
             active_account_ids, bm_ids, account_ids, token_updated_at, meta, created_at, updated_at)
        VALUES
            (:worker_id, :worker_label, :profile_name, :status, NOW(), CAST(:last_poll_at AS timestamptz), :last_poll_count,
             CAST(:active_account_ids AS jsonb), CAST(:bm_ids AS jsonb), CAST(:account_ids AS jsonb),
             CAST(:token_updated_at AS timestamptz), CAST(:meta AS jsonb), NOW(), NOW())
        ON CONFLICT (worker_id) DO UPDATE SET
            worker_label = EXCLUDED.worker_label,
            profile_name = EXCLUDED.profile_name,
            status = EXCLUDED.status,
            last_seen_at = NOW(),
            last_poll_at = COALESCE(EXCLUDED.last_poll_at, public.task_workers.last_poll_at),
            last_poll_count = CASE WHEN EXCLUDED.last_poll_count >= 0 THEN EXCLUDED.last_poll_count ELSE public.task_workers.last_poll_count END,
            active_account_ids = EXCLUDED.active_account_ids,
            bm_ids = EXCLUDED.bm_ids,
            account_ids = EXCLUDED.account_ids,
            token_updated_at = COALESCE(EXCLUDED.token_updated_at, public.task_workers.token_updated_at),
            meta = CASE
                WHEN EXCLUDED.meta = '{}'::jsonb THEN public.task_workers.meta
                ELSE public.task_workers.meta || EXCLUDED.meta
            END,
            updated_at = NOW()
        RETURNING *
    ");
    $stmt->execute([
        ':worker_id' => $workerId,
        ':worker_label' => $workerLabel,
        ':profile_name' => $profileName,
        ':status' => $status,
        ':last_poll_at' => $lastPollAt,
        ':last_poll_count' => $lastPollCount,
        ':active_account_ids' => json_encode($activeAccountIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':bm_ids' => json_encode($bmIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':account_ids' => json_encode($accountIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':token_updated_at' => $tokenUpdatedAt,
        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return formatTaskWorkerRow($row);
}

function listTaskWorkers(PDO $db, array $input): array {
    $limit = clampInt($input['limit'] ?? 100, 1, 500);
    $stmt = $db->prepare("
        SELECT *
        FROM public.task_workers
        ORDER BY last_seen_at DESC, updated_at DESC, worker_id
        LIMIT {$limit}
    ");
    $stmt->execute();
    $rows = array_map('formatTaskWorkerRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    return $rows;
}

function formatTaskWorkerRow(array $row): array {
    foreach (['active_account_ids', 'bm_ids', 'account_ids', 'meta'] as $field) {
        if (array_key_exists($field, $row) && is_string($row[$field]) && $row[$field] !== '') {
            $decoded = json_decode($row[$field], true);
            $row[$field] = $decoded !== null ? $decoded : $row[$field];
        }
    }
    return $row;
}

function normalizeTextList(mixed $value): array {
    if ($value === null || $value === '') return [];
    $items = is_array($value) ? $value : explode(',', (string)$value);
    $items = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $items))));
    return array_values(array_filter($items, fn($v) => $v !== ''));
}

function normalizeAccountIdList(mixed $value): array {
    if ($value === null || $value === '') return [];
    $items = is_array($value) ? $value : explode(',', (string)$value);
    $items = array_map(fn($id) => normalizeAccountId((string)$id), $items);
    $items = array_values(array_unique(array_filter($items)));
    return $items;
}

function pollDiagnostics(PDO $db, array $input, string $workerId): array {
    $where = ['1=1'];
    $params = [];
    addOptionalFilter($where, $params, 'bm_id', $input['bm_id'] ?? null);
    addOptionalFilter($where, $params, 'account_id', isset($input['account_id']) ? normalizeAccountId((string)$input['account_id']) : null);
    addAccountIdsFilter($where, $params, $input['active_account_ids'] ?? $input['account_ids'] ?? null);
    addTaskTypesFilter($where, $params, $input['task_types'] ?? $input['task_type'] ?? null);
    $baseWhere = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT status, task_type, COUNT(*) AS cnt
        FROM public.tasks
        WHERE {$baseWhere}
        GROUP BY status, task_type
        ORDER BY status, task_type
    ");
    $stmt->execute($params);
    $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pendingStmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM public.tasks
        WHERE {$baseWhere}
          AND status = 'pending'
          AND run_after <= NOW()
          AND attempts < max_attempts
    ");
    $pendingStmt->execute($params);

    $recentStmt = $db->prepare("
        SELECT id, status, task_type, bm_id, account_id, campaign_id, locked_by, locked_at,
               run_after, attempts, max_attempts, created_at, updated_at, error
        FROM public.tasks
        WHERE {$baseWhere}
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $recentStmt->execute($params);

    return [
        'worker_id' => $workerId,
        'server_time' => gmdate('c'),
        'filters' => [
            'task_types' => $input['task_types'] ?? $input['task_type'] ?? null,
            'bm_id' => $input['bm_id'] ?? null,
            'account_id' => $input['account_id'] ?? null,
            'active_account_ids' => $input['active_account_ids'] ?? $input['account_ids'] ?? null,
            'statuses' => $input['statuses'] ?? null,
        ],
        'counts' => $counts,
        'eligible_pending' => (int)$pendingStmt->fetchColumn(),
        'recent' => array_map('formatTaskRow', $recentStmt->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

function fetchTask(PDO $db, int $taskId): ?array {
    $stmt = $db->prepare("SELECT * FROM public.tasks WHERE id = :id");
    $stmt->execute([':id' => $taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function listTasks(PDO $db, array $input): array {
    $where = ['1=1'];
    $params = [];
    addOptionalFilter($where, $params, 'status', $input['status'] ?? null);
    addOptionalFilter($where, $params, 'bm_id', $input['bm_id'] ?? null);
    addOptionalFilter($where, $params, 'account_id', isset($input['account_id']) ? normalizeAccountId((string)$input['account_id']) : null);
    addTaskTypesFilter($where, $params, $input['task_types'] ?? $input['task_type'] ?? null);
    $limit = clampInt($input['limit'] ?? 100, 1, 500);
    $stmt = $db->prepare("
        SELECT *
        FROM public.tasks
        WHERE " . implode(' AND ', $where) . "
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateTask(PDO $db, int $taskId, string $status, array $input): array {
    $hasResult = array_key_exists('result', $input) || array_key_exists('result_json', $input);
    if (array_key_exists('result', $input)) {
        $result = json_encode($input['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $result = nullableString($input['result_json'] ?? null);
    }
    if ($hasResult && (!$result || json_decode($result, true) === null && json_last_error() !== JSON_ERROR_NONE)) {
        extError(400, 'Invalid result JSON');
    }
    $error = nullableString($input['error'] ?? $input['error_text'] ?? $input['message'] ?? null);
    $workerId = nullableString($input['worker_id'] ?? null);
    $before = fetchTask($db, $taskId);

    $stmt = $db->prepare("
        UPDATE public.tasks
        SET status = CAST(:status AS text),
            result = CASE
                WHEN CAST(:has_result AS integer) = 1 THEN CAST(:result_json AS jsonb)
                ELSE result
            END,
            error = CAST(:error AS text),
            locked_by = COALESCE(CAST(:worker_id AS text), locked_by),
            locked_at = CASE
                WHEN CAST(:status_lock AS text) IN ('done','failed','cancelled') THEN NULL
                ELSE locked_at
            END,
            finished_at = CASE
                WHEN CAST(:status_finish AS text) IN ('done','failed','cancelled') THEN NOW()
                ELSE finished_at
            END,
            updated_at = NOW()
        WHERE id = CAST(:id AS bigint)
        RETURNING *
    ");
    $stmt->execute([
        ':id' => $taskId,
        ':status' => $status,
        ':status_lock' => $status,
        ':status_finish' => $status,
        ':has_result' => $hasResult ? 1 : 0,
        ':result_json' => $result,
        ':error' => $error,
        ':worker_id' => $workerId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) extError(404, 'Task not found');
    $resultForLog = $hasResult ? GlobalLogger::decodeJsonish($result) : GlobalLogger::decodeJsonish($row['result'] ?? null);
    $eventType = match ($status) {
        'done' => 'fb_result',
        'failed' => 'task_failed',
        'cancelled' => 'task_cancelled',
        'running' => 'task_started',
        default => 'task_updated',
    };
    GlobalLogger::logTaskEvent($db, $eventType, $status, $row, [
        'actor' => $workerId ?: (string)($row['locked_by'] ?? ''),
        'reason' => 'Worker update',
        'before_state' => $before ? [
            'status' => $before['status'] ?? null,
            'attempts' => $before['attempts'] ?? null,
            'locked_by' => $before['locked_by'] ?? null,
        ] : null,
        'after_state' => [
            'status' => $row['status'] ?? null,
            'attempts' => $row['attempts'] ?? null,
            'locked_by' => $row['locked_by'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
        ],
        'result' => $resultForLog,
        'error' => $error,
    ]);
    applyDeletedCampaignMarker($db, $row, $input);
    if ($status === 'done') {
        applyManualCampaignStatus($db, $row, $input);
        applyManualAdsetStatus($db, $row, $input);
        applyManualAdStatus($db, $row, $input);
        applyManualCampaignDelete($db, $row, $input);
        applyManualAdsetBid($db, $row, $input);
    }
    return $row;
}

function listManualStops(PDO $db, array $input): array {
    $where = ["(c.status = 'MANUAL_STOP' OR c.effective_status = 'MANUAL_STOP')"];
    $params = [];
    addOptionalFilter($where, $params, 'aa.bm_id', $input['bm_id'] ?? null);
    addOptionalFilter($where, $params, 'c.ad_account_id', isset($input['account_id']) ? normalizeAccountId((string)$input['account_id']) : null);
    addOptionalFilter($where, $params, 'c.id::text', $input['campaign_id'] ?? null);

    $accountIdsRaw = $input['account_ids'] ?? null;
    if ($accountIdsRaw !== null && $accountIdsRaw !== '') {
        $accountIds = is_array($accountIdsRaw) ? $accountIdsRaw : explode(',', (string)$accountIdsRaw);
        $accountIds = array_values(array_filter(array_map(fn($id) => normalizeAccountId((string)$id), $accountIds)));
        if ($accountIds) {
            $ph = [];
            foreach ($accountIds as $i => $id) {
                $key = ":account_id_{$i}";
                $ph[] = $key;
                $params[$key] = $id;
            }
            $where[] = 'c.ad_account_id IN (' . implode(',', $ph) . ')';
        }
    }

    $limit = clampInt($input['limit'] ?? 5000, 1, 20000);
    $stmt = $db->prepare("
        SELECT
            c.id::text AS campaign_id,
            aa.bm_id::text AS bm_id,
            c.ad_account_id::text AS account_id,
            'manual_stop' AS status,
            c.updated_time,
            c.synced_at
        FROM public.campaigns c
        JOIN public.ad_accounts aa ON aa.id = c.ad_account_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.updated_time DESC NULLS LAST, c.id
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function applyManualCampaignStatus(PDO $db, array $taskRow, array $input): void {
    if (($taskRow['task_type'] ?? '') !== 'set_campaign_status') return;

    $payload = decodeJsonish($taskRow['payload'] ?? []);
    $result = array_key_exists('result', $input) ? $input['result'] : decodeJsonish($taskRow['result'] ?? []);
    if (!is_array($result) && array_key_exists('result_json', $input)) $result = decodeJsonish($input['result_json']);
    if (!is_array($payload)) $payload = [];
    if (!is_array($result)) $result = [];
    $success = $result['success'] ?? $result['fb_success'] ?? null;
    if ($success !== null && $success !== true) return;

    $desired = strtoupper(trim((string)($payload['desired_status'] ?? '')));
    $campaignId = nullableString($taskRow['campaign_id'] ?? null);
    if (!$campaignId || !in_array($desired, ['ACTIVE', 'PAUSED'], true)) return;

    $isManual = !empty($payload['manual']) || ($payload['source'] ?? '') === 'dashboard_toggle';
    $localStatus = ($desired === 'PAUSED' && $isManual) ? 'MANUAL_STOP' : $desired;
    $stmt = $db->prepare("
        UPDATE public.campaigns
        SET status = :status,
            effective_status = :status,
            updated_time = NOW(),
            synced_at = NOW()
        WHERE id::text = :campaign_id
    ");
    $stmt->execute([
        ':status' => $localStatus,
        ':campaign_id' => $campaignId,
    ]);
    GlobalLogger::logTaskEvent($db, 'local_state_applied', 'done', $taskRow, [
        'reason' => 'Local campaign status updated',
        'after_state' => [
            'status' => $localStatus,
            'effective_status' => $localStatus,
        ],
        'result' => $result,
    ]);
}

function applyDeletedCampaignMarker(PDO $db, array $taskRow, array $input): void {
    if (($taskRow['task_type'] ?? '') !== 'set_campaign_status') return;

    $campaignId = nullableString($taskRow['campaign_id'] ?? null);
    if (!$campaignId) return;

    $errorText = strtolower(trim((string)($input['error'] ?? $input['error_text'] ?? $input['message'] ?? ($taskRow['error'] ?? ''))));
    $result = array_key_exists('result', $input) ? $input['result'] : decodeJsonish($taskRow['result'] ?? []);
    if (!is_array($result) && array_key_exists('result_json', $input)) $result = decodeJsonish($input['result_json']);
    $resultText = strtolower(is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '' : (string)$result);
    if ($errorText === '' && $resultText === '') return;

    if (strpos($errorText, 'deleted') === false && strpos($resultText, 'deleted') === false) {
        return;
    }

    $stmt = $db->prepare("
        UPDATE public.campaigns
        SET status = 'DELETED',
            effective_status = 'DELETED',
            updated_time = NOW(),
            synced_at = NOW()
        WHERE id::text = :campaign_id
    ");
    $stmt->execute([
        ':campaign_id' => $campaignId,
    ]);
    GlobalLogger::logTaskEvent($db, 'local_state_applied', 'done', $taskRow, [
        'reason' => 'Local deleted campaign marker applied',
        'after_state' => [
            'status' => 'DELETED',
            'effective_status' => 'DELETED',
        ],
    ]);
}

function applyManualAdsetStatus(PDO $db, array $taskRow, array $input): void {
    if (($taskRow['task_type'] ?? '') !== 'set_adset_status') return;

    $payload = decodeJsonish($taskRow['payload'] ?? []);
    $result = array_key_exists('result', $input) ? $input['result'] : decodeJsonish($taskRow['result'] ?? []);
    if (!is_array($result) && array_key_exists('result_json', $input)) $result = decodeJsonish($input['result_json']);
    if (!is_array($payload)) $payload = [];
    if (!is_array($result)) $result = [];
    $success = $result['success'] ?? $result['fb_success'] ?? null;
    if ($success !== null && $success !== true) return;

    $desired = strtoupper(trim((string)($payload['desired_status'] ?? '')));
    $adsetId = nullableString($taskRow['adset_id'] ?? null);
    if (!$adsetId || !in_array($desired, ['ACTIVE', 'PAUSED'], true)) return;

    $stmt = $db->prepare("
        UPDATE public.ad_sets
        SET status = :status,
            effective_status = :status,
            updated_time = NOW(),
            synced_at = NOW()
        WHERE id::text = :adset_id
    ");
    $stmt->execute([
        ':status' => $desired,
        ':adset_id' => $adsetId,
    ]);
    GlobalLogger::logTaskEvent($db, 'local_state_applied', 'done', $taskRow, [
        'reason' => 'Local adset status updated',
        'after_state' => [
            'status' => $desired,
            'effective_status' => $desired,
        ],
        'result' => $result,
    ]);
}

function applyManualAdStatus(PDO $db, array $taskRow, array $input): void {
    if (($taskRow['task_type'] ?? '') !== 'set_ad_status') return;

    $payload = decodeJsonish($taskRow['payload'] ?? []);
    $result = array_key_exists('result', $input) ? $input['result'] : decodeJsonish($taskRow['result'] ?? []);
    if (!is_array($result) && array_key_exists('result_json', $input)) $result = decodeJsonish($input['result_json']);
    if (!is_array($payload)) $payload = [];
    if (!is_array($result)) $result = [];
    $success = $result['success'] ?? $result['fb_success'] ?? null;
    if ($success !== null && $success !== true) return;

    $desired = strtoupper(trim((string)($payload['desired_status'] ?? '')));
    $adId = nullableString($taskRow['ad_id'] ?? ($payload['ad_id'] ?? null));
    if (!$adId || !in_array($desired, ['ACTIVE', 'PAUSED'], true)) return;

    $stmt = $db->prepare("
        UPDATE public.ads
        SET status = :status,
            effective_status = :status,
            updated_time = NOW(),
            synced_at = NOW()
        WHERE id::text = :ad_id
    ");
    $stmt->execute([
        ':status' => $desired,
        ':ad_id' => $adId,
    ]);
    GlobalLogger::logTaskEvent($db, 'local_state_applied', 'done', $taskRow, [
        'reason' => 'Local ad status updated',
        'after_state' => [
            'status' => $desired,
            'effective_status' => $desired,
        ],
        'result' => $result,
    ]);
}

function applyManualCampaignDelete(PDO $db, array $taskRow, array $input): void {
    if (($taskRow['task_type'] ?? '') !== 'delete_campaign') return;

    $result = array_key_exists('result', $input) ? $input['result'] : decodeJsonish($taskRow['result'] ?? []);
    if (!is_array($result) && array_key_exists('result_json', $input)) $result = decodeJsonish($input['result_json']);
    if (!is_array($result)) $result = [];
    $success = $result['success'] ?? $result['fb_success'] ?? null;
    if ($success !== null && $success !== true) return;

    $campaignId = nullableString($taskRow['campaign_id'] ?? null);
    if (!$campaignId) return;

    $stmt = $db->prepare("
        UPDATE public.campaigns
        SET status = 'DELETED',
            effective_status = 'DELETED',
            updated_time = NOW(),
            synced_at = NOW()
        WHERE id::text = :campaign_id
    ");
    $stmt->execute([
        ':campaign_id' => $campaignId,
    ]);
    GlobalLogger::logTaskEvent($db, 'local_state_applied', 'done', $taskRow, [
        'reason' => 'Local campaign delete marker applied',
        'after_state' => [
            'status' => 'DELETED',
            'effective_status' => 'DELETED',
        ],
        'result' => $result,
    ]);
}

function applyManualAdsetBid(PDO $db, array $taskRow, array $input): void {
    if (($taskRow['task_type'] ?? '') !== 'update_adset_bid') return;

    $payload = decodeJsonish($taskRow['payload'] ?? []);
    $result = array_key_exists('result', $input) ? $input['result'] : decodeJsonish($taskRow['result'] ?? []);
    if (!is_array($result) && array_key_exists('result_json', $input)) $result = decodeJsonish($input['result_json']);
    if (!is_array($payload)) $payload = [];
    if (!is_array($result)) $result = [];
    $success = $result['success'] ?? $result['fb_success'] ?? null;
    if ($success !== null && $success !== true) return;

    $adsetId = nullableString($taskRow['adset_id'] ?? null);
    if (!$adsetId) return;

    $bid = $payload['bid_amount'] ?? $payload['bidAmount'] ?? null;
    if ($bid === null || $bid === '') {
        $bid = $result['final_bid'] ?? $result['bid_amount'] ?? $result['new_bid'] ?? null;
    }
    if ($bid === null || $bid === '') return;

    $stmt = $db->prepare("
        UPDATE public.ad_sets
        SET bid_amount = :bid_amount,
            updated_time = NOW(),
            synced_at = NOW()
        WHERE id::text = :adset_id
    ");
    $stmt->execute([
        ':bid_amount' => (float)$bid,
        ':adset_id' => $adsetId,
    ]);
    GlobalLogger::logTaskEvent($db, 'local_state_applied', 'done', $taskRow, [
        'reason' => 'Local adset bid updated',
        'after_state' => [
            'bid_amount' => (float)$bid,
        ],
        'result' => $result,
    ]);
}

function decodeJsonish(mixed $value): mixed {
    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
    return $value;
}

function addOptionalFilter(array &$where, array &$params, string $field, mixed $value): void {
    if ($value === null || $value === '') return;
    $key = ':' . preg_replace('/[^a-z0-9_]+/i', '_', $field);
    $where[] = "{$field} = {$key}";
    $params[$key] = (string)$value;
}

function addTaskTypesFilter(array &$where, array &$params, mixed $value): void {
    if ($value === null || $value === '') return;
    $types = is_array($value) ? $value : explode(',', (string)$value);
    $types = array_values(array_filter(array_map(fn($t) => normalizeTaskType((string)$t), $types)));
    $types = array_values(array_filter($types, fn($t) => in_array($t, TASK_TYPES, true)));
    if (!$types) return;
    $ph = [];
    foreach ($types as $i => $type) {
        $k = ":task_type_{$i}";
        $ph[] = $k;
        $params[$k] = $type;
    }
    $where[] = 'task_type IN (' . implode(',', $ph) . ')';
}

function addStatusesFilter(array &$where, array &$params, array $statuses): void {
    $statuses = array_values(array_filter($statuses, fn($status) => in_array($status, TASK_STATUSES, true)));
    if (!$statuses) return;
    $ph = [];
    foreach ($statuses as $i => $status) {
        $key = ":status_{$i}";
        $ph[] = $key;
        $params[$key] = $status;
    }
    $where[] = 'status IN (' . implode(',', $ph) . ')';
}

function addAccountIdsFilter(array &$where, array &$params, mixed $value): void {
    if ($value === null || $value === '') return;
    $ids = is_array($value) ? $value : explode(',', (string)$value);
    $ids = array_values(array_unique(array_filter(array_map(fn($id) => normalizeAccountId((string)$id), $ids))));
    if (!$ids) {
        $where[] = '1=0';
        return;
    }
    $ph = [];
    foreach ($ids as $i => $id) {
        $key = ":active_account_id_{$i}";
        $ph[] = $key;
        $params[$key] = $id;
    }
    $where[] = 'account_id IN (' . implode(',', $ph) . ')';
}

function normalizeStatusList(mixed $value): array {
    if ($value === null || $value === '') return ['pending'];
    $items = is_array($value) ? $value : explode(',', (string)$value);
    $items = array_map(fn($status) => strtolower(trim((string)$status)), $items);
    $items = array_values(array_unique(array_filter($items, fn($status) => in_array($status, TASK_STATUSES, true))));
    return $items ?: ['pending'];
}

function boolInput(mixed $value): bool {
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value !== 0;
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function formatTaskRow(array $row): array {
    foreach (['payload', 'result'] as $field) {
        if (array_key_exists($field, $row) && is_string($row[$field]) && $row[$field] !== '') {
            $decoded = json_decode($row[$field], true);
            $row[$field] = is_array($decoded) ? $decoded : $row[$field];
        }
    }
    if (isset($row['id'])) $row['id'] = (int)$row['id'];
    foreach (['priority', 'attempts', 'max_attempts'] as $field) {
        if (isset($row[$field])) $row[$field] = (int)$row[$field];
    }
    return $row;
}

function normalizeAccountId(string $id): string {
    $id = trim($id);
    if ($id !== '' && preg_match('/^\d+$/', $id)) return 'act_' . $id;
    return $id;
}

function nullableString(mixed $value): ?string {
    if ($value === null) return null;
    $str = trim((string)$value);
    return $str === '' ? null : $str;
}

function clampInt(mixed $value, int $min, int $max): int {
    $n = (int)$value;
    return max($min, min($max, $n));
}

function moneyNumber(mixed $value): ?float {
    if ($value === null || $value === '') return null;
    $n = (float)str_replace(',', '.', (string)$value);
    return $n > 0 ? round($n, 4) : null;
}

function normalizeBidStrategy(string $value): string {
    $value = strtolower(trim($value));
    return in_array($value, ['bidcap', 'costcap', 'auto'], true) ? $value : 'bidcap';
}
