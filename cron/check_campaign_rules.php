#!/usr/bin/env php
<?php
// Campaign rules checker and task enqueuer.
//
// Usage:
//   php cron/check_campaign_rules.php
//   php cron/check_campaign_rules.php --only-changes
//   php cron/check_campaign_rules.php --bm_id=123
//   php cron/check_campaign_rules.php --skip-cpc
//   php cron/check_campaign_rules.php --use-cpc
//   php cron/check_campaign_rules.php --dry-run

declare(strict_types=1);

require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/CampaignRulesChecker.php';
require __DIR__ . '/../lib/AutoRuleTasks.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';

$cfg = require __DIR__ . '/../config/config.php';
$db = DB::getInstance();
GlobalLogger::ensureSchema($db);
$opts = parseOptions($argv);
$runId = 'check_campaign_rules:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));

$bmIds = null;
if (!empty($opts['bm_id'])) {
    $bmIds = array_filter(array_map('trim', explode(',', (string)$opts['bm_id'])));
} else {
    ensureAutoRulesCronColumn($db);
    $bmIds = fetchAutoRulesCronBmIds($db);
}

GlobalLogger::log($db, [
    'source' => 'cron/check_campaign_rules',
    'actor' => 'cron',
    'event_type' => 'cron_started',
    'entity_type' => 'rule',
    'status' => 'running',
    'action' => 'check_campaign_rules',
    'reason' => 'Campaign rules cron started',
    'payload' => [
        'bm_ids' => $bmIds,
        'only_changes' => isset($opts['only-changes']),
        'dry_run' => isset($opts['dry-run']),
        'skip_cpc' => isset($opts['skip-cpc']) ? true : (isset($opts['use-cpc']) ? false : null),
    ],
    'correlation_id' => $runId,
]);

$checker = new CampaignRulesChecker($db, $cfg);
$checkOptions = ['bm_ids' => $bmIds];
if (isset($opts['skip-cpc'])) {
    $checkOptions['skip_cpc'] = true;
} elseif (isset($opts['use-cpc'])) {
    $checkOptions['skip_cpc'] = false;
}
$result = $checker->run($checkOptions);

$onlyChanges = isset($opts['only-changes']);
$dryRun = isset($opts['dry-run']);
$ts = fn() => '[' . date('Y-m-d H:i:s') . '] ';
$savedVerdicts = $dryRun ? 0 : AutoRuleTasks::saveLastVerdicts($db, $result['verdicts'], $result);
$queueResult = $dryRun
    ? ['created' => 0, 'requeued_failed' => 0, 'skipped_existing' => 0, 'cancelled_conflicts' => 0, 'errors' => [], 'by_campaign' => []]
    : AutoRuleTasks::enqueue($db, $result['verdicts'], $result['periods'], $result);

echo PHP_EOL . $ts() . "=== campaign rules check started ===" . PHP_EOL;
echo $ts() . "Rules: {$result['rules_source']}" . PHP_EOL;
if (empty($opts['bm_id'])) {
    echo $ts() . "BM filter: auto_rules_cron_enabled=true (" . count($bmIds ?? []) . " BM)" . PHP_EOL;
}
echo $ts() . sprintf(
    "Periods: 1D %s..%s, 7D %s..%s, 30D %s..%s",
    $result['periods']['today']['from'],
    $result['periods']['today']['to'],
    $result['periods']['last7']['from'],
    $result['periods']['last7']['to'],
    $result['periods']['last30']['from'],
    $result['periods']['last30']['to']
) . PHP_EOL;
if (isset($result['auto_rules_v2'])) {
    echo $ts() . sprintf(
        "Auto rules live policy: v1 active; v2 comparison enabled=%s shadow_only=%s test_stop_payouts=%s strict_profit_payouts=%s restart_hysteresis_hours=%s",
        !empty($result['auto_rules_v2']['enabled']) ? 'yes' : 'no',
        !empty($result['auto_rules_v2']['shadow_only']) ? 'yes' : 'no',
        (string)($result['auto_rules_v2']['test_stop_payouts'] ?? '-'),
        (string)($result['auto_rules_v2']['strict_profit_payouts'] ?? '-'),
        (string)($result['auto_rules_v2']['restart_hysteresis_hours'] ?? '-')
    ) . PHP_EOL;
}
echo $ts() . sprintf(
    "Stats: total=%d V1_STOP=%d V1_START=%d V1_OK=%d V1_HOLD_STOP=%d V1_NO_GEO=%d V1_NO_RULES=%d V1_IGNORED=%d V1_live_changes=%d V2_comparison_changes=%d",
    $result['stats']['total'],
    $result['stats']['stop'],
    $result['stats']['start'],
    $result['stats']['ok'],
    $result['stats']['hold_stop'],
    $result['stats']['no_geo'],
    $result['stats']['no_rules'],
    $result['stats']['ignored_status'] ?? 0,
    $result['stats']['changes'],
    $result['stats']['v2_changes'] ?? 0
) . PHP_EOL;
if ($dryRun) {
    echo $ts() . "Saved verdicts: dry-run, campaign snapshot not updated" . PHP_EOL;
    echo $ts() . "Task queue: dry-run, no tasks created" . PHP_EOL;
} else {
    echo $ts() . "Saved verdicts: {$savedVerdicts}" . PHP_EOL;
    echo $ts() . sprintf(
        "Task queue: created=%d requeued_failed=%d skipped_existing=%d cancelled_conflicts=%d errors=%d",
        $queueResult['created'],
        $queueResult['requeued_failed'] ?? 0,
        $queueResult['skipped_existing'],
        $queueResult['cancelled_conflicts'],
        count($queueResult['errors'])
    ) . PHP_EOL;
}

printf(
    PHP_EOL . "%-12s %-10s %-7s %-8s %-13s %-10s %-12s %s\n",
    'LIVE',
    'V2',
    'GEO',
    'STATUS',
    'ACTION',
    'SPEND1D',
    'SPEND30D',
    'CAMPAIGN / REASON'
);
echo str_repeat('-', 150) . PHP_EOL;

foreach ($result['verdicts'] as $v) {
    $liveSignal = AutoRuleTasks::liveSignal($v, $result);
    if ($onlyChanges && $liveSignal === null) {
        continue;
    }

    $action = $liveSignal !== null
        ? (($liveSignal['desired_status'] ?? '') === 'PAUSED' ? 'PAUSE' : 'START')
        : 'NO_CHANGE';
    $queueState = $queueResult['by_campaign'][(string)$v['campaign_id']] ?? null;

    printf(
        "%-12s %-10s %-7s %-8s %-13s %10.2f %12.2f %s [%s]\n",
        $v['verdict'],
        $v['candidate_verdict'] ?? '-',
        $v['geo'] ?: '-',
        $v['fb_status'],
        $action,
        (float)($v['data_1d']['spend'] ?? 0),
        (float)($v['data_30d']['spend'] ?? 0),
        $v['campaign_name'],
        $v['campaign_id']
    );
    echo "    1D stats: " . formatStats($v['data_1d'] ?? []) . PHP_EOL;
    echo "    1D DB limits: " . formatLimits($v['limits_db_1d'] ?? [], '1D') . PHP_EOL;
    echo "    7D stats: " . formatStats($v['data_7d'] ?? []) . PHP_EOL;
    echo "    30D stats: " . formatStats($v['data_30d'] ?? []) . PHP_EOL;
    echo "    30D DB limits: " . formatLimits($v['limits_db_30d'] ?? [], '30D') . PHP_EOL;
    echo "    DB signal: " . formatSignal($v['signal_db'] ?? null) . PHP_EOL;
    if (!empty($v['metrics_source_reason'])) {
        echo "    Metrics source: " . $v['metrics_source_reason'] . PHP_EOL;
    }
    echo "    Reason: " . $v['reason'] . PHP_EOL;
    if (isset($v['candidate_verdict'])) {
        echo "    V2 compare: " . formatShadowCandidate($v) . PHP_EOL;
    }
    if ($queueState) {
        echo "    Task: " . $queueState . PHP_EOL;
    }
}

echo $ts() . "=== Done ===" . PHP_EOL;
GlobalLogger::log($db, [
    'source' => 'cron/check_campaign_rules',
    'actor' => 'cron',
    'event_type' => 'cron_finished',
    'entity_type' => 'rule',
    'status' => count($queueResult['errors'] ?? []) > 0 ? 'warning' : 'done',
    'action' => 'check_campaign_rules',
    'reason' => $dryRun ? 'Campaign rules dry-run finished' : 'Campaign rules cron finished',
    'payload' => [
        'dry_run' => $dryRun,
        'bm_ids' => $bmIds,
        'stats' => $result['stats'] ?? [],
        'saved_verdicts' => $savedVerdicts,
        'queue' => [
            'created' => $queueResult['created'] ?? 0,
            'requeued_failed' => $queueResult['requeued_failed'] ?? 0,
            'skipped_existing' => $queueResult['skipped_existing'] ?? 0,
            'cancelled_conflicts' => $queueResult['cancelled_conflicts'] ?? 0,
            'errors' => $queueResult['errors'] ?? [],
        ],
        'periods' => $result['periods'] ?? [],
    ],
    'correlation_id' => $runId,
]);

function parseOptions(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) continue;
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $opts[$k] = $v;
        } else {
            $opts[$arg] = true;
        }
    }
    return $opts;
}

function ensureAutoRulesCronColumn(PDO $db): void
{
    $db->exec("
        ALTER TABLE business_managers
        ADD COLUMN IF NOT EXISTS auto_rules_cron_enabled BOOLEAN NOT NULL DEFAULT FALSE
    ");
}

function fetchAutoRulesCronBmIds(PDO $db): array
{
    return array_map('strval', $db->query("
        SELECT id::text
        FROM business_managers
        WHERE is_active = TRUE
          AND auto_rules_cron_enabled = TRUE
        ORDER BY name, id
    ")->fetchAll(PDO::FETCH_COLUMN));
}

function enqueueAutoRuleTasks(PDO $db, array $verdicts, array $periods, array $runMeta): array
{
    ensureTasksCronSchema($db);
    normalizeOpenAutoRuleAccountIds($db);

    $out = [
        'created' => 0,
        'skipped_existing' => 0,
        'cancelled_conflicts' => 0,
        'errors' => [],
        'by_campaign' => [],
    ];

    foreach ($verdicts as $v) {
        if (empty($v['should_change'])) {
            continue;
        }

        $desired = strtoupper(trim((string)($v['desired_status'] ?? '')));
        $campaignId = trim((string)($v['campaign_id'] ?? ''));
        if ($campaignId === '' || !in_array($desired, ['ACTIVE', 'PAUSED'], true)) {
            continue;
        }
        if (($v['verdict'] ?? '') === 'MANUAL_STOP' || ($v['fb_status'] ?? '') === 'MANUAL_STOP') {
            continue;
        }

        try {
            $cancelled = cancelOppositeAutoRuleTasks($db, $campaignId, $desired);
            $out['cancelled_conflicts'] += $cancelled;

            $existing = findOpenAutoRuleTask($db, $campaignId, $desired);
            if ($existing) {
                $out['skipped_existing']++;
                $out['by_campaign'][$campaignId] = sprintf('SKIP existing #%d %s', (int)$existing['id'], (string)$existing['status']);
                continue;
            }

            $payload = buildAutoRuleTaskPayload($v, $desired, $periods, $runMeta);
            $task = insertAutoRuleTask($db, $v, $desired, $payload);
            $out['created']++;
            $out['by_campaign'][$campaignId] = sprintf('CREATED #%d %s', (int)$task['id'], $desired);
        } catch (Throwable $e) {
            $out['errors'][] = [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ];
            $out['by_campaign'][$campaignId] = 'ERROR ' . $e->getMessage();
        }
    }

    return $out;
}

function ensureTasksCronSchema(PDO $db): void
{
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
        CREATE INDEX IF NOT EXISTS idx_tasks_type_status
            ON public.tasks (task_type, status, created_at DESC);
    ");
}

function findOpenAutoRuleTask(PDO $db, string $campaignId, string $desired): ?array
{
    $stmt = $db->prepare("
        SELECT id, status
        FROM public.tasks
        WHERE task_type = 'set_campaign_status'
          AND campaign_id = :campaign_id
          AND status IN ('pending', 'running', 'failed')
          AND payload->>'source' = 'auto_rules_cron'
          AND payload->>'desired_status' = :desired
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':campaign_id' => $campaignId,
        ':desired' => $desired,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cancelOppositeAutoRuleTasks(PDO $db, string $campaignId, string $desired): int
{
    $stmt = $db->prepare("
        UPDATE public.tasks
        SET status = 'cancelled',
            error = COALESCE(error, 'Cancelled by newer auto-rules verdict'),
            finished_at = COALESCE(finished_at, NOW()),
            updated_at = NOW()
        WHERE task_type = 'set_campaign_status'
          AND campaign_id = :campaign_id
          AND status IN ('pending', 'running')
          AND payload->>'source' = 'auto_rules_cron'
          AND COALESCE(payload->>'desired_status', '') <> :desired
    ");
    $stmt->execute([
        ':campaign_id' => $campaignId,
        ':desired' => $desired,
    ]);
    return $stmt->rowCount();
}

function buildAutoRuleTaskPayload(array $v, string $desired, array $periods, array $runMeta): array
{
    return [
        'desired_status' => $desired,
        'manual' => false,
        'source' => 'auto_rules_cron',
        'action' => $desired === 'PAUSED' ? 'PAUSE' : 'START',
        'verdict' => $v['verdict'] ?? null,
        'reason' => $v['reason'] ?? '',
        'checked_at' => date(DATE_ATOM),
        'campaign_name' => $v['campaign_name'] ?? '',
        'geo' => $v['geo'] ?? '',
        'fb_status_before' => $v['fb_status'] ?? '',
        'account_active' => (bool)($v['account_active'] ?? false),
        'account_name' => $v['account_name'] ?? '',
        'bm_name' => $v['bm_name'] ?? '',
        'rules_source' => $runMeta['rules_source'] ?? '',
        'used_algo' => $v['used_algo'] ?? null,
        'skip_cpc' => (bool)($runMeta['skip_cpc'] ?? false),
        'periods' => $periods,
        'metrics' => [
            'today' => $v['data_1d'] ?? [],
            'yesterday' => $v['data_yesterday'] ?? [],
            'last30' => $v['data_30d'] ?? [],
        ],
        'limits' => [
            'db_1d' => $v['limits_db_1d'] ?? [],
            'db_30d' => $v['limits_db_30d'] ?? [],
        ],
        'signals' => [
            'db' => $v['signal_db'] ?? null,
        ],
        'violations' => [
            '1d' => $v['violation_1d'] ?? null,
            '30d' => $v['violation_30d'] ?? null,
        ],
    ];
}

function insertAutoRuleTask(PDO $db, array $v, string $desired, array $payload): array
{
    $stmt = $db->prepare("
        INSERT INTO public.tasks
            (task_type, status, priority, bm_id, account_id, campaign_id, payload, created_by, max_attempts)
        VALUES
            ('set_campaign_status', 'pending', :priority, :bm_id, :account_id, :campaign_id,
             CAST(:payload AS jsonb), 'cron:auto_rules', 3)
        RETURNING *
    ");
    $stmt->execute([
        ':priority' => $desired === 'PAUSED' ? 160 : 140,
        ':bm_id' => (string)($v['bm_id'] ?? ''),
        ':account_id' => normalizeCronAccountId((string)($v['account_id'] ?? '')),
        ':campaign_id' => (string)($v['campaign_id'] ?? ''),
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0];
    GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
        'before_state' => [
            'status' => $v['fb_status'] ?? null,
            'effective_status' => $v['fb_effective_status'] ?? null,
        ],
        'reason' => (string)($payload['reason'] ?? 'Auto-rules verdict'),
    ]);
    return $row;
}

function normalizeCronAccountId(string $id): string
{
    $id = trim($id);
    if ($id !== '' && preg_match('/^\d+$/', $id)) {
        return 'act_' . $id;
    }
    return $id;
}

function normalizeOpenAutoRuleAccountIds(PDO $db): void
{
    $db->exec("
        UPDATE public.tasks
        SET account_id = 'act_' || account_id,
            updated_at = NOW()
        WHERE task_type = 'set_campaign_status'
          AND status IN ('pending', 'running', 'failed')
          AND payload->>'source' = 'auto_rules_cron'
          AND account_id ~ '^[0-9]+$'
    ");
}

function formatStats(array $d): string
{
    return sprintf(
        'spend=%.2f clicks=%.0f cpc=%.2f leads=%.0f cpl=%s regs=%.0f cpr=%s deps=%.0f cpd=%s revenue=%.2f',
        (float)($d['spend'] ?? 0),
        (float)($d['clicks'] ?? 0),
        (float)($d['cpc'] ?? 0),
        (float)($d['leads'] ?? 0),
        isset($d['cpl']) ? sprintf('%.2f', (float)$d['cpl']) : '-',
        (float)($d['regs'] ?? 0),
        isset($d['cpr']) ? sprintf('%.2f', (float)$d['cpr']) : '-',
        (float)($d['deps'] ?? 0),
        isset($d['cpd']) ? sprintf('%.2f', (float)$d['cpd']) : '-',
        (float)($d['revenue'] ?? 0)
    );
}

function formatLimits(array $limits, string $sfx): string
{
    if (!$limits) {
        return '-';
    }

    $parts = [];
    foreach ([
        "MAXCPC{$sfx}" => 'CPC',
        "MAXLEAD{$sfx}" => 'CPL',
        "MAXREG{$sfx}" => 'CPR',
        "MAXDEP{$sfx}" => 'CPD',
        "MULT{$sfx}" => 'MULT',
    ] as $key => $label) {
        if (array_key_exists($key, $limits)) {
            $parts[] = sprintf('%s=%.4f', $label, (float)$limits[$key]);
        }
    }

    return $parts ? implode(' ', $parts) : '-';
}

function formatSignal(?array $signal): string
{
    if (!$signal) {
        return 'no limits';
    }

    $action = !empty($signal['should_change'])
        ? (($signal['desired_status'] ?? '') === 'PAUSED' ? 'PAUSE' : 'START')
        : 'NO_CHANGE';

    return sprintf(
        '%s action=%s desired=%s reason=%s',
        $signal['verdict'] ?? '-',
        $action,
        $signal['desired_status'] ?? '-',
        $signal['reason'] ?? ''
    );
}

function formatShadowCandidate(array $v): string
{
    $parts = [
        (string)($v['candidate_verdict'] ?? 'UNKNOWN'),
        (string)($v['candidate_level'] ?? 'shadow'),
        'action=' . (string)($v['candidate_action'] ?? 'NO_ACTION'),
        'restart=' . (string)($v['restart_policy'] ?? 'none'),
    ];
    if (array_key_exists('potential_score', $v) && $v['potential_score'] !== null) {
        $parts[] = 'score=' . (int)$v['potential_score'];
    }
    $reason = trim((string)($v['candidate_reason'] ?? ''));
    if ($reason !== '') {
        $parts[] = $reason;
    }
    return implode(' | ', $parts);
}
