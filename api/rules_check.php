<?php
// GET /api/rules_check.php?bm_id=...&only_changes=1&skip_cpc=1
// Dry-run campaign rules check. Does not change campaign statuses.

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/../lib/CampaignRulesChecker.php';
require __DIR__ . '/../lib/BmOptions.php';
require __DIR__ . '/../lib/AutoRuleTasks.php';

$cfg = require __DIR__ . '/../config/config.php';
ensureRulesCheckMetaSchema($db);
$allowedBmIds = array_map('strval', $auth->allowedBmIds($me));
if (!$allowedBmIds) {
    apiOk([
        'stats' => ['total'=>0,'stop'=>0,'start'=>0,'ok'=>0,'hold_stop'=>0,'no_geo'=>0,'no_rules'=>0,'ignored_status'=>0,'manual_stop'=>0,'changes'=>0,'v2_changes'=>0],
        'verdicts' => [],
    ]);
}

$bmIds = $allowedBmIds;
$bmFilter = trim((string)($_GET['bm_id'] ?? ''));
if ($bmFilter !== '') {
    if (!in_array($bmFilter, $allowedBmIds, true)) {
        apiError(403, 'BM is not allowed');
    }
    $bmIds = [$bmFilter];
}

$bms = bmSelectorOptions($db, $allowedBmIds);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    createRulesCheckTasks($db, $cfg, $me, $allowedBmIds, $bms);
}

if (!empty($_GET['list_bms'])) {
    apiOk([
        'stats' => ['total'=>0,'stop'=>0,'start'=>0,'ok'=>0,'hold_stop'=>0,'no_geo'=>0,'no_rules'=>0,'ignored_status'=>0,'manual_stop'=>0,'changes'=>0,'v2_changes'=>0],
        'verdicts' => [],
        'bms' => $bms,
    ]);
}

$checker = new CampaignRulesChecker($db, $cfg);
$checkOptions = ['bm_ids' => $bmIds];
if (array_key_exists('skip_cpc', $_GET)) {
    $checkOptions['skip_cpc'] = filter_var($_GET['skip_cpc'], FILTER_VALIDATE_BOOLEAN);
}
$result = $checker->run($checkOptions);
$result['bm_cron_enabled'] = fetchRulesCheckBmCronState($db, $bmIds);
attachRulesCheckTasks($db, $result['verdicts']);

if (!empty($_GET['only_changes'])) {
    $result['verdicts'] = array_values(array_filter($result['verdicts'], fn($v) => AutoRuleTasks::liveSignal($v, $result) !== null));
}

$result['bms'] = $bms;

apiOk($result);

function createRulesCheckTasks(PDO $db, array $cfg, array $me, array $allowedBmIds, array $bms): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) apiError(400, 'Invalid JSON body');

    $action = strtolower(trim((string)($input['action'] ?? 'create_tasks')));
    if (!in_array($action, ['create_tasks', 'enqueue_tasks'], true)) {
        apiError(400, 'Unsupported action');
    }

    $bmId = trim((string)($input['bm_id'] ?? ''));
    if ($bmId === '') apiError(400, 'bm_id required');
    if (!in_array($bmId, $allowedBmIds, true)) apiError(403, 'BM is not allowed');

    $checker = new CampaignRulesChecker($db, $cfg);
    $checkOptions = ['bm_ids' => [$bmId]];
    if (array_key_exists('skip_cpc', $input)) {
        $checkOptions['skip_cpc'] = filter_var($input['skip_cpc'], FILTER_VALIDATE_BOOLEAN);
    }
    $result = $checker->run($checkOptions);

    $createdBy = 'dashboard:rules_check:' . (string)($me['username'] ?? $me['id'] ?? 'user');
    $queue = AutoRuleTasks::enqueue($db, $result['verdicts'], $result['periods'], $result, $createdBy);

    $result['queue'] = $queue;
    $result['bm_cron_enabled'] = fetchRulesCheckBmCronState($db, [$bmId]);
    attachRulesCheckTasks($db, $result['verdicts']);

    if (!empty($input['only_changes'])) {
        $result['verdicts'] = array_values(array_filter($result['verdicts'], fn($v) => AutoRuleTasks::liveSignal($v, $result) !== null));
    }

    $result['bms'] = $bms;
    apiOk($result);
}

function ensureRulesCheckMetaSchema(PDO $db): void {
    $db->exec("
        ALTER TABLE business_managers
        ADD COLUMN IF NOT EXISTS auto_rules_cron_enabled BOOLEAN NOT NULL DEFAULT FALSE
    ");
}

function fetchRulesCheckBmCronState(PDO $db, array $bmIds): array {
    if (!$bmIds) return [];
    $ph = [];
    $params = [];
    foreach (array_values($bmIds) as $i => $id) {
        $key = ":bm_{$i}";
        $ph[] = $key;
        $params[$key] = (string)$id;
    }
    $stmt = $db->prepare("
        SELECT id::text, auto_rules_cron_enabled
        FROM business_managers
        WHERE id::text IN (" . implode(',', $ph) . ")
    ");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string)$row['id']] = filter_var($row['auto_rules_cron_enabled'], FILTER_VALIDATE_BOOLEAN);
    }
    return $out;
}

function attachRulesCheckTasks(PDO $db, array &$verdicts): void {
    $campaignIds = [];
    foreach ($verdicts as $v) {
        $id = trim((string)($v['campaign_id'] ?? ''));
        if ($id !== '') $campaignIds[$id] = true;
    }
    if (!$campaignIds) return;

    $exists = $db->query("SELECT to_regclass('public.tasks')")->fetchColumn();
    if (!$exists) {
        foreach ($verdicts as &$v) {
            $v['auto_rule_task'] = null;
        }
        unset($v);
        return;
    }

    $ids = array_keys($campaignIds);
    $ph = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $key = ":campaign_{$i}";
        $ph[] = $key;
        $params[$key] = $id;
    }

    $stmt = $db->prepare("
        SELECT DISTINCT ON (campaign_id)
            id,
            status,
            campaign_id,
            payload,
            error,
            created_at,
            updated_at,
            finished_at
        FROM public.tasks
        WHERE task_type = 'set_campaign_status'
          AND campaign_id IN (" . implode(',', $ph) . ")
          AND payload->>'source' = 'auto_rules_cron'
        ORDER BY campaign_id, created_at DESC
    ");
    $stmt->execute($params);
    $tasks = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = json_decode((string)($row['payload'] ?? '{}'), true);
        $row['payload'] = is_array($payload) ? $payload : [];
        $tasks[(string)$row['campaign_id']] = $row;
    }

    foreach ($verdicts as &$v) {
        $v['auto_rule_task'] = $tasks[(string)($v['campaign_id'] ?? '')] ?? null;
    }
    unset($v);
}
