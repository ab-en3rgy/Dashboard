<?php
declare(strict_types=1);

class ApiSyncLogger
{
    private static bool $schemaReady = false;

    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaReady) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS public.fb_request_log (
                id BIGSERIAL PRIMARY KEY,
                sync_log_id BIGINT REFERENCES public.sync_log(id) ON DELETE SET NULL,
                ts TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                request_type VARCHAR(30) NOT NULL,
                endpoint TEXT NOT NULL,
                batch_size SMALLINT NOT NULL DEFAULT 1,
                http_code SMALLINT,
                duration_ms INT,
                rows_returned INT NOT NULL DEFAULT 0,
                attempt SMALLINT NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'ok',
                error_code INT,
                error_msg TEXT,
                response_preview TEXT,
                raw_error JSONB
            )
        ");
        $db->exec("ALTER TABLE public.fb_request_log ADD COLUMN IF NOT EXISTS response_preview TEXT");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rlog_sync ON public.fb_request_log(sync_log_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rlog_ts ON public.fb_request_log(ts DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rlog_status ON public.fb_request_log(status) WHERE status != 'ok'");

        self::$schemaReady = true;
    }

    public static function log(PDO $db, array $event): ?int
    {
        try {
            self::ensureSchema($db);
            $stmt = $db->prepare("
                INSERT INTO public.fb_request_log
                    (sync_log_id, ts, request_type, endpoint, batch_size, http_code, duration_ms,
                     rows_returned, attempt, status, error_code, error_msg, response_preview, raw_error)
                VALUES
                    (:sync_log_id, NOW(), :request_type, :endpoint, :batch_size, :http_code, :duration_ms,
                     :rows_returned, :attempt, :status, :error_code, :error_msg, :response_preview, CAST(:raw_error AS jsonb))
                RETURNING id
            ");
            $stmt->execute([
                ':sync_log_id' => isset($event['sync_log_id']) && $event['sync_log_id'] !== '' ? (int)$event['sync_log_id'] : null,
                ':request_type' => self::text($event['request_type'] ?? 'request'),
                ':endpoint' => self::text($event['endpoint'] ?? ''),
                ':batch_size' => max(1, (int)($event['batch_size'] ?? 1)),
                ':http_code' => isset($event['http_code']) && $event['http_code'] !== '' ? (int)$event['http_code'] : null,
                ':duration_ms' => isset($event['duration_ms']) && $event['duration_ms'] !== '' ? (int)$event['duration_ms'] : null,
                ':rows_returned' => max(0, (int)($event['rows_returned'] ?? 0)),
                ':attempt' => max(1, (int)($event['attempt'] ?? 1)),
                ':status' => self::text($event['status'] ?? 'ok'),
                ':error_code' => isset($event['error_code']) && $event['error_code'] !== '' ? (int)$event['error_code'] : null,
                ':error_msg' => self::nullableText($event['error_msg'] ?? null),
                ':response_preview' => self::nullableText($event['response_preview'] ?? null),
                ':raw_error' => self::json($event['raw_error'] ?? null),
            ]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('ApiSyncLogger write failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function preview(string $value, int $limit = 2000): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length <= $limit) {
            return $value;
        }
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit) . "\n... [truncated]"
            : substr($value, 0, $limit) . "\n... [truncated]";
    }

    private static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }

    private static function text(mixed $value): string
    {
        return trim((string)$value);
    }

    private static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }
}
