<?php
declare(strict_types=1);

require_once __DIR__ . '/GlobalLogger.php';

class AutoRuleTasks
{
    public static function enqueue(PDO $db, array $verdicts, array $periods, array $runMeta, string $createdBy = 'cron:auto_rules'): array
    {
        self::ensureSchema($db);
        GlobalLogger::ensureSchema($db);
        self::normalizeOpenAccountIds($db);

        $out = [
            'created' => 0,
            'requeued_failed' => 0,
            'skipped_existing' => 0,
            'cancelled_conflicts' => 0,
            'errors' => [],
            'by_campaign' => [],
        ];

        foreach ($verdicts as $v) {
            $live = self::liveSignal($v, $runMeta);
            if ($live === null) continue;

            $desired = $live['desired_status'];
            $campaignId = trim((string)($v['campaign_id'] ?? ''));
            if ($campaignId === '' || !in_array($desired, ['ACTIVE', 'PAUSED'], true)) continue;
            if (($v['candidate_verdict'] ?? '') === 'MANUAL_STOP' || ($v['fb_status'] ?? '') === 'MANUAL_STOP') continue;

            try {
                $cancelled = self::cancelOpposite($db, $campaignId, $desired);
                $out['cancelled_conflicts'] += $cancelled;

                $latestDone = self::findLatestDone($db, $campaignId);
                if ($latestDone && strtoupper((string)($latestDone['desired_status'] ?? '')) === $desired) {
                    $out['skipped_existing']++;
                    $out['by_campaign'][$campaignId] = sprintf('SKIP latest done #%d %s', (int)$latestDone['id'], $desired);
                    continue;
                }

                $requeued = self::requeueStaleFailed($db, $campaignId, $desired);
                if ($requeued) {
                    $out['requeued_failed']++;
                    $out['by_campaign'][$campaignId] = sprintf('REQUEUED #%d %s', (int)$requeued['id'], $desired);
                    continue;
                }

                $existing = self::findOpen($db, $campaignId, $desired);
                if ($existing) {
                    $out['skipped_existing']++;
                    $out['by_campaign'][$campaignId] = sprintf('SKIP existing #%d %s', (int)$existing['id'], (string)$existing['status']);
                    continue;
                }

                $payload = self::buildPayload($v, $desired, $periods, $runMeta, $live);
                $task = self::insert($db, $v, $desired, $payload, $createdBy);
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

    public static function liveSignal(array $v, array $runMeta = []): ?array
    {
        if (empty($v['should_change'])) {
            return null;
        }
        $desired = strtoupper(trim((string)($v['desired_status'] ?? '')));
        if (!in_array($desired, ['ACTIVE', 'PAUSED'], true)) {
            return null;
        }

        $verdict = strtoupper(trim((string)($v['verdict'] ?? '')));
        if (in_array($verdict, ['', 'NO_GEO', 'NO_RULES', 'MANUAL_STOP', 'IGNORED_STATUS'], true)) {
            return null;
        }
        if (($v['fb_status'] ?? '') === 'MANUAL_STOP') {
            return null;
        }

        return [
            'source' => 'auto_rules_v1',
            'verdict' => $verdict,
            'action' => $desired === 'PAUSED' ? 'PAUSE' : 'START',
            'desired_status' => $desired,
            'reason' => (string)($v['reason'] ?? ''),
            'level' => (string)($v['signal_level'] ?? ''),
            'restart_policy' => $desired === 'PAUSED' ? 'v1_pause' : 'v1_start',
            'potential_score' => null,
        ];
    }

    public static function ensureCampaignVerdictSchema(PDO $db): void
    {
        $db->exec("
            ALTER TABLE IF EXISTS public.campaigns
                ADD COLUMN IF NOT EXISTS auto_rule_verdict TEXT,
                ADD COLUMN IF NOT EXISTS auto_rule_payload JSONB
        ");
    }

    public static function saveLastVerdicts(PDO $db, array $verdicts, array $runMeta = []): int
    {
        self::ensureCampaignVerdictSchema($db);
        $stmt = $db->prepare("
            UPDATE public.campaigns
            SET auto_rule_verdict = :verdict,
                auto_rule_payload = CAST(:payload AS jsonb)
            WHERE id = :campaign_id
        ");

        $saved = 0;
        foreach ($verdicts as $v) {
            $campaignId = trim((string)($v['campaign_id'] ?? ''));
            if ($campaignId === '') {
                continue;
            }
            $payload = self::buildLastVerdictPayload($v, $runMeta);
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':verdict' => (string)($payload['active']['verdict'] ?? 'NO_DATA'),
                ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $saved += $stmt->rowCount() > 0 ? 1 : 0;
        }
        return $saved;
    }

    private static function buildLastVerdictPayload(array $v, array $runMeta): array
    {
        $live = self::liveSignal($v, $runMeta);
        return [
            'checked_at' => date(DATE_ATOM),
            'policy_version' => 'v1',
            'rules_source' => $runMeta['rules_source'] ?? '',
            'periods' => $runMeta['periods'] ?? [],
            'auto_rules_v2' => $runMeta['auto_rules_v2'] ?? [],
            'campaign' => [
                'id' => $v['campaign_id'] ?? null,
                'name' => $v['campaign_name'] ?? '',
                'geo' => $v['geo'] ?? '',
                'fb_status' => $v['fb_status'] ?? '',
                'account_active' => (bool)($v['account_active'] ?? false),
                'bm_id' => $v['bm_id'] ?? '',
                'account_id' => $v['account_id'] ?? '',
            ],
            'active' => [
                'source' => $live['source'] ?? 'auto_rules_v1',
                'verdict' => $v['verdict'] ?? null,
                'action' => $live['action'] ?? null,
                'desired_status' => $v['desired_status'] ?? null,
                'should_change' => (bool)($v['should_change'] ?? false),
                'reason' => $v['reason'] ?? '',
                'reason_short' => $v['reason_short'] ?? '',
                'signal_level' => $v['signal_level'] ?? '',
                'live_signal' => $live,
            ],
            'v1' => [
                'verdict' => $v['verdict'] ?? null,
                'desired_status' => $v['desired_status'] ?? null,
                'should_change' => (bool)($v['should_change'] ?? false),
                'reason' => $v['reason'] ?? '',
                'reason_short' => $v['reason_short'] ?? '',
                'signal_level' => $v['signal_level'] ?? '',
            ],
            'v2' => [
                'verdict' => $v['candidate_verdict'] ?? null,
                'action' => $v['candidate_action'] ?? null,
                'desired_status' => $v['candidate_desired_status'] ?? null,
                'should_change' => (bool)($v['candidate_should_change'] ?? false),
                'reason' => $v['candidate_reason'] ?? '',
                'level' => $v['candidate_level'] ?? '',
                'restart_policy' => $v['restart_policy'] ?? '',
                'potential_score' => $v['potential_score'] ?? null,
                'score_breakdown' => $v['candidate_score_breakdown'] ?? [],
                'baseline_diff' => $v['baseline_diff'] ?? [],
                'hysteresis' => $v['auto_rules_restart_hysteresis'] ?? null,
                'comparison_only' => true,
            ],
            'metrics' => [
                'today' => $v['data_1d'] ?? [],
                'yesterday' => $v['data_yesterday'] ?? [],
                'last7' => $v['data_7d'] ?? [],
                'last30' => $v['data_30d'] ?? [],
                'alltime' => $v['data_alltime'] ?? [],
                'source' => $v['metrics_source'] ?? '',
                'source_reason' => $v['metrics_source_reason'] ?? '',
            ],
            'limits' => [
                'db_1d' => $v['limits_db_1d'] ?? [],
                'db_30d' => $v['limits_db_30d'] ?? [],
            ],
            'violations' => [
                '1d' => $v['violation_1d'] ?? null,
                '30d' => $v['violation_30d'] ?? null,
            ],
        ];
    }

    public static function ensureSchema(PDO $db): void
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
            ALTER TABLE IF EXISTS public.tasks
                DROP CONSTRAINT IF EXISTS tasks_type_chk;
            ALTER TABLE IF EXISTS public.tasks
                ADD CONSTRAINT tasks_type_chk CHECK (task_type IN (
                    'set_campaign_status',
                'set_adset_status',
                'set_ad_status',
                'delete_campaign',
                'update_campaign_budget',
                'update_adset_budget',
                'update_adset_bid',
                'refresh_ad_text',
                'create_campaign'
            ));
        ");
    }

    private static function findOpen(PDO $db, string $campaignId, string $desired): ?array
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

    private static function requeueStaleFailed(PDO $db, string $campaignId, string $desired): ?array
    {
        $stmt = $db->prepare("
            UPDATE public.tasks
            SET status = 'pending',
                result = NULL,
                error = NULL,
                locked_by = NULL,
                locked_at = NULL,
                run_after = NOW(),
                finished_at = NULL,
                updated_at = NOW()
            WHERE id = (
                SELECT id
                FROM public.tasks
                WHERE task_type = 'set_campaign_status'
                  AND campaign_id = :campaign_id
                  AND status = 'failed'
                  AND payload->>'source' = 'auto_rules_cron'
                  AND payload->>'desired_status' = :desired
                  AND COALESCE(finished_at, updated_at, created_at) <= NOW() - INTERVAL '30 minutes'
                ORDER BY COALESCE(finished_at, updated_at, created_at) DESC, id DESC
                LIMIT 1
            )
            RETURNING *
        ");
        $stmt->execute([
            ':campaign_id' => $campaignId,
            ':desired' => $desired,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            GlobalLogger::logTaskEvent($db, 'task_retried', 'pending', $row, [
                'reason' => 'Auto-rules stale failed task requeued',
            ]);
        }
        return $row ?: null;
    }

    private static function findLatestDone(PDO $db, string $campaignId): ?array
    {
        $stmt = $db->prepare("
            SELECT
                id,
                status,
                payload->>'desired_status' AS desired_status,
                payload->>'source' AS source,
                finished_at,
                updated_at
            FROM public.tasks
            WHERE task_type = 'set_campaign_status'
              AND campaign_id = :campaign_id
              AND status = 'done'
              AND payload->>'desired_status' IN ('ACTIVE', 'PAUSED')
            ORDER BY COALESCE(finished_at, updated_at, created_at) DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([':campaign_id' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function cancelOpposite(PDO $db, string $campaignId, string $desired): int
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
            RETURNING *
        ");
        $stmt->execute([
            ':campaign_id' => $campaignId,
            ':desired' => $desired,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            GlobalLogger::logTaskEvent($db, 'task_cancelled', 'cancelled', $row, [
                'reason' => 'Cancelled by newer auto-rules verdict',
            ]);
        }
        return count($rows);
    }

    private static function buildPayload(array $v, string $desired, array $periods, array $runMeta, array $live): array
    {
        $reason = (string)($live['reason'] ?? '');
        return [
            'desired_status' => $desired,
            'manual' => false,
            'source' => 'auto_rules_cron',
            'decision_source' => $live['source'] ?? 'auto_rules_v1',
            'policy_version' => 'v1',
            'action' => $live['action'] ?? ($desired === 'PAUSED' ? 'PAUSE' : 'START'),
            'verdict' => $live['verdict'] ?? null,
            'reason' => $reason,
            'reason_short' => self::shortReason($reason),
            'reason_detail' => $reason,
            'signal_level' => $live['level'] ?? '',
            'why_now' => $reason,
            'checked_at' => date(DATE_ATOM),
            'campaign_name' => $v['campaign_name'] ?? '',
            'geo' => $v['geo'] ?? '',
            'fb_status_before' => $v['fb_status'] ?? '',
            'account_active' => (bool)($v['account_active'] ?? false),
            'account_name' => $v['account_name'] ?? '',
            'bm_name' => $v['bm_name'] ?? '',
            'rules_source' => $runMeta['rules_source'] ?? '',
            'used_algo' => $v['used_algo'] ?? null,
            'metrics_source' => $v['metrics_source'] ?? '',
            'metrics_source_reason' => $v['metrics_source_reason'] ?? '',
            'skip_cpc' => (bool)($runMeta['skip_cpc'] ?? false),
            'auto_rules_v2' => $runMeta['auto_rules_v2'] ?? [],
            'v1_active' => [
                'verdict' => $v['verdict'] ?? null,
                'desired_status' => $v['desired_status'] ?? null,
                'should_change' => (bool)($v['should_change'] ?? false),
                'reason' => $v['reason'] ?? '',
                'reason_short' => $v['reason_short'] ?? '',
                'reason_detail' => $v['reason_detail'] ?? '',
                'signal_level' => $v['signal_level'] ?? '',
                'why_now' => $v['why_now'] ?? '',
            ],
            'v2_compare' => [
                'comparison_only' => true,
                'candidate_verdict' => $v['candidate_verdict'] ?? null,
                'candidate_action' => $v['candidate_action'] ?? null,
                'candidate_level' => $v['candidate_level'] ?? null,
                'candidate_desired_status' => $v['candidate_desired_status'] ?? null,
                'candidate_should_change' => (bool)($v['candidate_should_change'] ?? false),
                'candidate_reason' => $v['candidate_reason'] ?? '',
                'restart_policy' => $v['restart_policy'] ?? '',
                'potential_score' => $v['potential_score'] ?? null,
                'baseline_diff' => $v['baseline_diff'] ?? [],
            ],
            'periods' => $periods,
            'metrics' => [
                'today' => $v['data_1d'] ?? [],
                'yesterday' => $v['data_yesterday'] ?? [],
                'last7' => $v['data_7d'] ?? [],
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

    private static function shortReason(string $reason): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
        if (strlen($text) > 140) {
            $cut = strrpos(substr($text, 0, 140), ' ');
            $text = $cut !== false ? substr($text, 0, $cut) . '...' : substr($text, 0, 140) . '...';
        }
        return $text !== '' ? $text : 'No explanation';
    }

    private static function insert(PDO $db, array $v, string $desired, array $payload, string $createdBy): array
    {
        $stmt = $db->prepare("
            INSERT INTO public.tasks
                (task_type, status, priority, bm_id, account_id, campaign_id, payload, created_by, max_attempts)
            VALUES
                ('set_campaign_status', 'pending', :priority, :bm_id, :account_id, :campaign_id,
                 CAST(:payload AS jsonb), :created_by, 3)
            RETURNING *
        ");
        $stmt->execute([
            ':priority' => $desired === 'PAUSED' ? 160 : 140,
            ':bm_id' => (string)($v['bm_id'] ?? ''),
            ':account_id' => self::normalizeAccountId((string)($v['account_id'] ?? '')),
            ':campaign_id' => (string)($v['campaign_id'] ?? ''),
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => $createdBy,
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

    private static function normalizeAccountId(string $id): string
    {
        $id = trim($id);
        if ($id !== '' && preg_match('/^\d+$/', $id)) return 'act_' . $id;
        return $id;
    }

    private static function normalizeOpenAccountIds(PDO $db): void
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
}
