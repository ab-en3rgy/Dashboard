<?php
// lib/GlobalLogger.php - append-only audit log for dashboard business actions.

class GlobalLogger
{
    private static bool $schemaReady = false;

    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaReady) return;

        $db->exec("
            CREATE TABLE IF NOT EXISTS public.global_log (
                id BIGSERIAL PRIMARY KEY,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                source TEXT NOT NULL DEFAULT 'system',
                actor TEXT NOT NULL DEFAULT '',
                event_type TEXT NOT NULL,
                entity_type TEXT NOT NULL DEFAULT 'task',
                entity_id TEXT,
                bm_id TEXT NOT NULL DEFAULT '',
                account_id TEXT NOT NULL DEFAULT '',
                campaign_id TEXT,
                adset_id TEXT,
                ad_id TEXT,
                task_id BIGINT,
                status TEXT NOT NULL DEFAULT 'info',
                action TEXT NOT NULL DEFAULT '',
                reason TEXT NOT NULL DEFAULT '',
                before_state JSONB,
                desired_state JSONB,
                after_state JSONB,
                payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                result JSONB,
                error TEXT,
                correlation_id TEXT
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_global_log_created ON public.global_log (created_at DESC, id DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_global_log_task ON public.global_log (task_id) WHERE task_id IS NOT NULL");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_global_log_campaign ON public.global_log (campaign_id, created_at DESC) WHERE campaign_id IS NOT NULL");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_global_log_adset ON public.global_log (adset_id, created_at DESC) WHERE adset_id IS NOT NULL");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_global_log_bm_account ON public.global_log (bm_id, account_id, created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_global_log_event_status ON public.global_log (event_type, status, created_at DESC)");

        self::$schemaReady = true;
    }

    public static function log(PDO $db, array $event): ?int
    {
        try {
            self::ensureSchema($db);
            $payload = self::jsonParam($event['payload'] ?? []);
            $before = self::nullableJsonParam($event['before_state'] ?? null);
            $desired = self::nullableJsonParam($event['desired_state'] ?? null);
            $after = self::nullableJsonParam($event['after_state'] ?? null);
            $result = self::nullableJsonParam($event['result'] ?? null);

            $stmt = $db->prepare("
                INSERT INTO public.global_log
                    (source, actor, event_type, entity_type, entity_id, bm_id, account_id,
                     campaign_id, adset_id, ad_id, task_id, status, action, reason,
                     before_state, desired_state, after_state, payload, result, error, correlation_id)
                VALUES
                    (:source, :actor, :event_type, :entity_type, :entity_id, :bm_id, :account_id,
                     :campaign_id, :adset_id, :ad_id, :task_id, :status, :action, :reason,
                     CAST(:before_state AS jsonb), CAST(:desired_state AS jsonb), CAST(:after_state AS jsonb),
                     CAST(:payload AS jsonb), CAST(:result AS jsonb), :error, :correlation_id)
                RETURNING id
            ");
            $stmt->execute([
                ':source' => self::text($event['source'] ?? 'system'),
                ':actor' => self::text($event['actor'] ?? ''),
                ':event_type' => self::text($event['event_type'] ?? 'event'),
                ':entity_type' => self::text($event['entity_type'] ?? 'task'),
                ':entity_id' => self::nullableText($event['entity_id'] ?? null),
                ':bm_id' => self::text($event['bm_id'] ?? ''),
                ':account_id' => self::text($event['account_id'] ?? ''),
                ':campaign_id' => self::nullableText($event['campaign_id'] ?? null),
                ':adset_id' => self::nullableText($event['adset_id'] ?? null),
                ':ad_id' => self::nullableText($event['ad_id'] ?? null),
                ':task_id' => isset($event['task_id']) && $event['task_id'] !== '' ? (int)$event['task_id'] : null,
                ':status' => self::text($event['status'] ?? 'info'),
                ':action' => self::text($event['action'] ?? ''),
                ':reason' => self::text($event['reason'] ?? ''),
                ':before_state' => $before,
                ':desired_state' => $desired,
                ':after_state' => $after,
                ':payload' => $payload,
                ':result' => $result,
                ':error' => self::nullableText($event['error'] ?? null),
                ':correlation_id' => self::nullableText($event['correlation_id'] ?? null),
            ]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('GlobalLog write failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function logTaskEvent(PDO $db, string $eventType, string $status, array $taskRow, array $extra = []): ?int
    {
        $payload = self::decodeJsonish($taskRow['payload'] ?? []);
        $result = array_key_exists('result', $extra) ? $extra['result'] : self::decodeJsonish($taskRow['result'] ?? null);
        $error = $extra['error'] ?? ($taskRow['error'] ?? null);
        $taskType = (string)($taskRow['task_type'] ?? '');
        $meta = self::taskMeta($taskType, is_array($payload) ? $payload : []);

        return self::log($db, array_merge([
            'source' => self::sourceFromTask($taskRow, is_array($payload) ? $payload : []),
            'actor' => (string)($taskRow['created_by'] ?? ''),
            'event_type' => $eventType,
            'entity_type' => $meta['entity_type'],
            'entity_id' => self::entityId($taskRow, $meta['entity_type']),
            'bm_id' => (string)($taskRow['bm_id'] ?? ''),
            'account_id' => (string)($taskRow['account_id'] ?? ''),
            'campaign_id' => self::nullableText($taskRow['campaign_id'] ?? null),
            'adset_id' => self::nullableText($taskRow['adset_id'] ?? null),
            'ad_id' => self::nullableText($payload['ad_id'] ?? null),
            'task_id' => $taskRow['id'] ?? null,
            'status' => $status,
            'action' => $meta['action'],
            'reason' => (string)($extra['reason'] ?? ($payload['reason'] ?? '')),
            'desired_state' => $meta['desired_state'],
            'payload' => $payload,
            'result' => $result,
            'error' => $error,
            'correlation_id' => self::nullableText($taskRow['idempotency_key'] ?? null),
        ], $extra));
    }

    public static function taskMeta(string $taskType, array $payload): array
    {
        $desiredStatus = strtoupper(trim((string)($payload['desired_status'] ?? '')));
        return match ($taskType) {
            'set_campaign_status' => [
                'entity_type' => 'campaign',
                'action' => $desiredStatus !== '' ? 'set_campaign_status_' . strtolower($desiredStatus) : 'set_campaign_status',
                'desired_state' => ['status' => $desiredStatus],
            ],
            'set_adset_status' => [
                'entity_type' => 'adset',
                'action' => $desiredStatus !== '' ? 'set_adset_status_' . strtolower($desiredStatus) : 'set_adset_status',
                'desired_state' => ['status' => $desiredStatus],
            ],
            'delete_campaign' => [
                'entity_type' => 'campaign',
                'action' => 'delete_campaign',
                'desired_state' => ['status' => 'DELETED'],
            ],
            'update_adset_bid' => [
                'entity_type' => 'adset',
                'action' => 'update_adset_bid',
                'desired_state' => [
                    'bid_mode' => $payload['bid_mode'] ?? null,
                    'bid_amount' => $payload['bid_amount'] ?? null,
                    'bid_delta_pct' => $payload['bid_delta_pct'] ?? null,
                ],
            ],
            'create_campaign' => [
                'entity_type' => 'campaign',
                'action' => 'create_campaign',
                'desired_state' => [
                    'geo' => $payload['geo'] ?? null,
                    'daily_budget' => $payload['daily_budget'] ?? null,
                    'bid_amount' => $payload['bid_amount'] ?? null,
                    'bid_strategy_mode' => $payload['bid_strategy_mode'] ?? null,
                    'adsets_num' => $payload['adsets_num'] ?? null,
                    'ads_num' => $payload['ads_num'] ?? null,
                ],
            ],
            default => [
                'entity_type' => 'task',
                'action' => $taskType,
                'desired_state' => [],
            ],
        };
    }

    public static function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $k = is_string($key) ? strtolower($key) : $key;
                if (is_string($k) && preg_match('/(token|secret|password|passwd|cookie|authorization|access_token)/', $k)) {
                    $out[$key] = '[redacted]';
                    continue;
                }
                $out[$key] = self::scrub($item);
            }
            return $out;
        }
        return $value;
    }

    public static function decodeJsonish(mixed $value): mixed
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        return $value;
    }

    private static function sourceFromTask(array $taskRow, array $payload): string
    {
        $source = trim((string)($payload['source'] ?? ''));
        if ($source !== '') return $source;
        $createdBy = trim((string)($taskRow['created_by'] ?? ''));
        if (str_starts_with($createdBy, 'dashboard:')) return 'dashboard';
        if (str_starts_with($createdBy, 'cron:')) return 'cron';
        if ($createdBy !== '') return $createdBy;
        return 'task_queue';
    }

    private static function entityId(array $taskRow, string $entityType): ?string
    {
        if ($entityType === 'campaign') return self::nullableText($taskRow['campaign_id'] ?? null);
        if ($entityType === 'adset') return self::nullableText($taskRow['adset_id'] ?? null);
        return isset($taskRow['id']) ? (string)$taskRow['id'] : null;
    }

    private static function jsonParam(mixed $value): string
    {
        $json = json_encode(self::scrub($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }

    private static function nullableJsonParam(mixed $value): ?string
    {
        if ($value === null) return null;
        return self::jsonParam($value);
    }

    private static function text(mixed $value): string
    {
        return trim((string)$value);
    }

    private static function nullableText(mixed $value): ?string
    {
        if ($value === null) return null;
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }
}
