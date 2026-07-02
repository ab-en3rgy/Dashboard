<?php
// api/ext/accounts.php
// @version 1.0.3
// POST { secret, token_hint, bm_id, bm_name, accounts: [...] }
// bm_id - numeric Business Manager ID from FB
// Creates the BM if it does not exist, then upserts accounts

require __DIR__.'/_bootstrap.php';
require_once __DIR__ . '/../../lib/GlobalLogger.php';

$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS name_locked BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS launch_restricted BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS launch_status TEXT");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS launch_block_reason TEXT");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS launch_checked_at TIMESTAMPTZ");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS launch_source TEXT");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS launch_source_raw JSONB");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS disabled_date DATE");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS launch_restricted BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS launch_status TEXT");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS launch_block_reason TEXT");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS launch_checked_at TIMESTAMPTZ");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS launch_source TEXT");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS launch_source_raw JSONB");

$accounts = $body['accounts'] ?? [];
if (!is_array($accounts) || !count($accounts)) extError(400, 'accounts array required');

$bmId   = trim((string)($body['bm_id']   ?? ''));
$bmName = trim($body['bm_name']           ?? 'Unknown BM');
$fbtoolId = trim((string)($body['fbtool_id'] ?? ''));
$fbtoolAccountId = resolveFbtoolAccountId($db, $fbtoolId);
$bodyLaunch = normalizeLaunchFields($body);

// If bm_id is not provided, use the special "no BM" BM with id=0
// The extension must pass the real bm_id
if (!$bmId) {
    // Fallback: create a service BM
    $bmId   = '0';
    $bmName = 'Unknown BM (no bm_id provided)';
}

$normalizedAccountIds = [];
foreach ($accounts as $acc) {
    $candidateId = trim((string)($acc['id'] ?? ''));
    if ($candidateId === '') continue;
    if (!str_starts_with($candidateId, 'act_')) $candidateId = 'act_' . $candidateId;
    $normalizedAccountIds[] = $candidateId;
}
$existingAccounts = fetchExistingAccounts($db, $normalizedAccountIds);

// Upsert BM
$db->prepare("
    INSERT INTO business_managers (
        id, name, fbtool_account_id,
        launch_restricted, launch_status, launch_block_reason, launch_checked_at, launch_source, launch_source_raw,
        synced_at
    )
    VALUES (
        :id, :name, :fbtool_account_id,
        :launch_restricted, :launch_status, :launch_block_reason, :launch_checked_at, :launch_source, CAST(:launch_source_raw AS jsonb),
        NOW()
    )
    ON CONFLICT (id) DO UPDATE SET
        name      = CASE
            WHEN business_managers.name_locked THEN business_managers.name
            ELSE EXCLUDED.name
        END,
        fbtool_account_id = COALESCE(business_managers.fbtool_account_id, EXCLUDED.fbtool_account_id),
        launch_restricted = EXCLUDED.launch_restricted,
        launch_status = EXCLUDED.launch_status,
        launch_block_reason = EXCLUDED.launch_block_reason,
        launch_checked_at = EXCLUDED.launch_checked_at,
        launch_source = EXCLUDED.launch_source,
        launch_source_raw = EXCLUDED.launch_source_raw,
        synced_at = NOW(),
        updated_at = NOW()
")->execute([
    'id' => $bmId,
    'name' => $bmName,
    'fbtool_account_id' => $fbtoolAccountId,
    'launch_restricted' => boolToSqlBool($bodyLaunch['launch_restricted']),
    'launch_status' => $bodyLaunch['launch_status'],
    'launch_block_reason' => $bodyLaunch['launch_block_reason'],
    'launch_checked_at' => $bodyLaunch['launch_checked_at'],
    'launch_source' => $bodyLaunch['launch_source'],
    'launch_source_raw' => $bodyLaunch['launch_source_raw'],
]);

// Upsert accounts
$stmt = $db->prepare("
    INSERT INTO ad_accounts (
        id, bm_id, name, status, disabled_date, timezone_name, currency, spend_cap, amount_spent, balance,
        launch_restricted, launch_status, launch_block_reason, launch_checked_at, launch_source, launch_source_raw,
        synced_at
    )
    VALUES (
        :id, :bm_id, :name, :status, :disabled_date, :tz, :cur, :cap, :spent, :bal,
        :launch_restricted, :launch_status, :launch_block_reason, :launch_checked_at, :launch_source, CAST(:launch_source_raw AS jsonb),
        NOW()
    )
    ON CONFLICT (id) DO UPDATE SET
        bm_id        = CASE WHEN ad_accounts.bm_id IS NOT NULL THEN ad_accounts.bm_id ELSE EXCLUDED.bm_id END,
        name         = EXCLUDED.name,
        status       = EXCLUDED.status,
        disabled_date = CASE
            WHEN EXCLUDED.status = 1 THEN NULL
            WHEN ad_accounts.status = 1 AND EXCLUDED.status <> 1 THEN COALESCE(EXCLUDED.disabled_date, CURRENT_DATE)
            ELSE ad_accounts.disabled_date
        END,
        timezone_name= EXCLUDED.timezone_name,
        currency     = EXCLUDED.currency,
        spend_cap    = EXCLUDED.spend_cap,
        amount_spent = EXCLUDED.amount_spent,
        balance      = EXCLUDED.balance,
        launch_restricted = EXCLUDED.launch_restricted,
        launch_status = EXCLUDED.launch_status,
        launch_block_reason = EXCLUDED.launch_block_reason,
        launch_checked_at = EXCLUDED.launch_checked_at,
        launch_source = EXCLUDED.launch_source,
        launch_source_raw = EXCLUDED.launch_source_raw,
        synced_at    = NOW()
");

$upserted = 0;
foreach ($accounts as $acc) {
    $id = trim((string)($acc['id'] ?? ''));
    if (!$id) continue;
    if (!str_starts_with($id, 'act_')) $id = 'act_'.$id;
    $previous = $existingAccounts[$id] ?? null;
    $nextStatus = (int)($acc['status'] ?? 1);
    $accLaunch = array_replace($bodyLaunch, normalizeLaunchFields($acc));

    $stmt->execute([
        'id'     => $id,
        'bm_id'  => $bmId,
        'name'   => trim($acc['name'] ?? $id),
        'status' => $nextStatus,
        'disabled_date' => $nextStatus === 1 ? null : date('Y-m-d'),
        'tz'     => $acc['timezone_name'] ?? 'UTC',
        'cur'    => $acc['currency']      ?? 'USD',
        'cap'    => isset($acc['spend_cap'])    ? (float)$acc['spend_cap']    : null,
        'spent'  => (float)($acc['amount_spent'] ?? 0),
        'bal'    => (float)($acc['balance']      ?? 0),
        'launch_restricted' => boolToSqlBool($accLaunch['launch_restricted']),
        'launch_status' => $accLaunch['launch_status'],
        'launch_block_reason' => $accLaunch['launch_block_reason'],
        'launch_checked_at' => $accLaunch['launch_checked_at'],
        'launch_source' => $accLaunch['launch_source'],
        'launch_source_raw' => $accLaunch['launch_source_raw'],
    ]);
    $upserted++;

    if ($nextStatus !== 1 && shouldLogAccountBan($previous, $nextStatus)) {
        logAccountBanEvent($db, [
            'bm_id' => $bmId,
            'bm_name' => $bmName,
            'account_id' => $id,
            'account_name' => trim((string)($acc['name'] ?? $id)),
            'previous' => $previous,
            'launch' => $accLaunch,
        ]);
    }

    $existingAccounts[$id] = [
        'id' => $id,
        'name' => trim((string)($acc['name'] ?? $id)),
        'status' => $nextStatus,
        'bm_id' => $bmId,
    ];
}

$activeIds = $db->query("SELECT id FROM ad_accounts WHERE status = 1")->fetchAll(PDO::FETCH_COLUMN);

extOk(['upserted' => $upserted, 'active_ids' => $activeIds, 'bm_id' => $bmId]);

function resolveFbtoolAccountId(PDO $db, string $fbtoolId): ?int {
    if ($fbtoolId === '') {
        return null;
    }
    $tableExists = $db->query("SELECT to_regclass('public.fbtool_accounts')")->fetchColumn();
    if (!$tableExists) {
        return null;
    }
    $stmt = $db->prepare("SELECT id FROM fbtool_accounts WHERE fbtool_id = :fbtool_id LIMIT 1");
    $stmt->execute(['fbtool_id' => $fbtoolId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function normalizeLaunchFields(array $row): array
{
    $source = trim((string)($row['launch_source'] ?? $row['launchSource'] ?? ''));
    $status = strtolower(trim((string)($row['launch_status'] ?? $row['launchStatus'] ?? '')));
    $reason = trim((string)($row['launch_block_reason'] ?? $row['launchBlockReason'] ?? ''));
    $checkedAt = trim((string)($row['launch_checked_at'] ?? $row['launchCheckedAt'] ?? ''));
    $restricted = $row['launch_restricted'] ?? $row['launchRestricted'] ?? null;
    $raw = $row['launch_source_raw'] ?? $row['launchSourceRaw'] ?? null;

    if ($restricted === null) {
        $restricted = in_array($status, ['blocked', 'restricted', 'unknown'], true) || $reason !== '';
    } else {
        $restricted = filter_var($restricted, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($restricted === null) {
            $restricted = in_array(strtolower(trim((string)$restricted)), ['1', 'true', 'yes', 'on'], true);
        }
    }

    if ($status === '') {
        $status = $restricted ? 'restricted' : 'ready';
    }
    if ($checkedAt === '') {
        $checkedAt = gmdate('c');
    }

    return [
        'launch_restricted' => (bool)$restricted,
        'launch_status' => $status,
        'launch_block_reason' => $reason !== '' ? $reason : null,
        'launch_checked_at' => $checkedAt,
        'launch_source' => $source !== '' ? $source : null,
        'launch_source_raw' => normalizeLaunchRaw($raw),
    ];
}

function normalizeLaunchRaw(mixed $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $trimmed;
        }
        return json_encode($trimmed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function boolToSqlBool(bool $value): string
{
    return $value ? 'TRUE' : 'FALSE';
}

function fetchExistingAccounts(PDO $db, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter(array_map('strval', $accountIds))));
    if (!$accountIds) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($accountIds as $i => $accountId) {
        $key = ':account_' . $i;
        $placeholders[] = $key;
        $params[$key] = $accountId;
    }

    $stmt = $db->prepare("
        SELECT id, bm_id::text AS bm_id, name, status, disabled_date
        FROM ad_accounts
        WHERE id IN (" . implode(',', $placeholders) . ")
    ");
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(string)$row['id']] = $row;
    }
    return $rows;
}

function shouldLogAccountBan(?array $previous, int $nextStatus): bool
{
    if ($nextStatus === 1) {
        return false;
    }
    if ($previous === null) {
        return true;
    }
    return (int)($previous['status'] ?? 1) === 1;
}

function logAccountBanEvent(PDO $db, array $context): void
{
    $snapshot = fetchAccountBanSnapshot($db, (string)$context['account_id']);
    $bmId = (string)($context['bm_id'] ?? ($snapshot['bm_id'] ?? ''));
    $accountId = (string)($context['account_id'] ?? '');
    $launch = $context['launch'] ?? [];
    $previous = $context['previous'] ?? null;

    $payload = [
        'disabled_date' => $snapshot['disabled_date'] ?? gmdate('Y-m-d'),
        'currency' => $snapshot['currency'] ?? 'USD',
        'balance' => isset($snapshot['balance']) ? (float)$snapshot['balance'] : null,
        'amount_spent' => isset($snapshot['amount_spent']) ? (float)$snapshot['amount_spent'] : null,
        'spend_cap' => isset($snapshot['spend_cap']) ? (float)$snapshot['spend_cap'] : null,
        'launch_status' => $snapshot['launch_status'] ?? ($launch['launch_status'] ?? null),
        'launch_restricted' => isset($snapshot['launch_restricted']) ? (bool)$snapshot['launch_restricted'] : (bool)($launch['launch_restricted'] ?? false),
        'launch_block_reason' => $snapshot['launch_block_reason'] ?? ($launch['launch_block_reason'] ?? null),
        'campaigns' => [
            'active_count' => (int)($snapshot['campaigns_active'] ?? 0),
            'total_count' => (int)($snapshot['campaigns_total'] ?? 0),
            'top' => $snapshot['top_campaigns'] ?? [],
        ],
        'stats_7d' => [
            'spend' => (float)($snapshot['spend_7d'] ?? 0),
            'deps' => (int)($snapshot['deps_7d'] ?? 0),
            'revenue' => (float)($snapshot['revenue_7d'] ?? 0),
        ],
        'stats_30d' => [
            'spend' => (float)($snapshot['spend_30d'] ?? 0),
            'deps' => (int)($snapshot['deps_30d'] ?? 0),
            'revenue' => (float)($snapshot['revenue_30d'] ?? 0),
        ],
    ];

    $reason = trim((string)($payload['launch_block_reason'] ?? ''));
    if ($reason === '') {
        $reason = 'Account status changed to banned.';
    }

    GlobalLogger::log($db, [
        'source' => 'sync_extension',
        'actor' => 'api/ext/accounts',
        'event_type' => 'account_banned',
        'entity_type' => 'account',
        'entity_id' => $accountId,
        'bm_id' => $bmId,
        'account_id' => $accountId,
        'status' => 'warn',
        'action' => 'status_changed_to_banned',
        'reason' => $reason,
        'before_state' => [
            'status' => $previous !== null ? (int)($previous['status'] ?? 1) : null,
            'name' => $previous['name'] ?? null,
        ],
        'after_state' => [
            'status' => (int)($snapshot['status'] ?? 0),
            'name' => $snapshot['account_name'] ?? ($context['account_name'] ?? $accountId),
            'disabled_date' => $payload['disabled_date'],
        ],
        'payload' => $payload,
        'result' => [
            'bm_name' => $snapshot['bm_name'] ?? ($context['bm_name'] ?? ''),
            'account_name' => $snapshot['account_name'] ?? ($context['account_name'] ?? $accountId),
        ],
    ]);
}

function fetchAccountBanSnapshot(PDO $db, string $accountId): array
{
    $stmt = $db->prepare("
        SELECT
            aa.id AS account_id,
            aa.name AS account_name,
            aa.status,
            aa.disabled_date,
            aa.currency,
            aa.balance,
            aa.amount_spent,
            aa.spend_cap,
            aa.launch_status,
            aa.launch_restricted,
            aa.launch_block_reason,
            bm.id::text AS bm_id,
            bm.name AS bm_name,
            COALESCE(camp.campaigns_total, 0) AS campaigns_total,
            COALESCE(camp.campaigns_active, 0) AS campaigns_active,
            COALESCE(stats.spend_7d, 0) AS spend_7d,
            COALESCE(stats.deps_7d, 0) AS deps_7d,
            COALESCE(stats.revenue_7d, 0) AS revenue_7d,
            COALESCE(stats.spend_30d, 0) AS spend_30d,
            COALESCE(stats.deps_30d, 0) AS deps_30d,
            COALESCE(stats.revenue_30d, 0) AS revenue_30d
        FROM ad_accounts aa
        LEFT JOIN business_managers bm ON bm.id = aa.bm_id
        LEFT JOIN LATERAL (
            SELECT
                COUNT(*) FILTER (WHERE COALESCE(c.status, '') <> 'DELETED') AS campaigns_total,
                COUNT(*) FILTER (
                    WHERE c.status = 'ACTIVE'
                      AND COALESCE(NULLIF(c.effective_status, ''), 'ACTIVE') = 'ACTIVE'
                ) AS campaigns_active
            FROM campaigns c
            WHERE c.ad_account_id = aa.id
        ) camp ON TRUE
        LEFT JOIN LATERAL (
            SELECT
                COALESCE(SUM(CASE WHEN id.date >= CURRENT_DATE - INTERVAL '6 days' THEN id.spend ELSE 0 END), 0) AS spend_7d,
                COALESCE(SUM(CASE WHEN id.date >= CURRENT_DATE - INTERVAL '6 days' THEN id.deps ELSE 0 END), 0) AS deps_7d,
                COALESCE(SUM(CASE WHEN id.date >= CURRENT_DATE - INTERVAL '6 days' THEN id.revenue ELSE 0 END), 0) AS revenue_7d,
                COALESCE(SUM(id.spend), 0) AS spend_30d,
                COALESCE(SUM(id.deps), 0) AS deps_30d,
                COALESCE(SUM(id.revenue), 0) AS revenue_30d
            FROM insights_daily id
            JOIN ads a ON a.id = id.ad_id
            WHERE a.ad_account_id = aa.id
              AND id.date >= CURRENT_DATE - INTERVAL '29 days'
        ) stats ON TRUE
        WHERE aa.id = :account_id
        LIMIT 1
    ");
    $stmt->execute([':account_id' => $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $row['top_campaigns'] = fetchBanTopCampaigns($db, $accountId);
    return $row;
}

function fetchBanTopCampaigns(PDO $db, string $accountId): array
{
    $stmt = $db->prepare("
        SELECT
            c.id,
            c.name,
            c.status,
            c.effective_status,
            CASE
                WHEN c.status = 'ACTIVE'
                 AND COALESCE(NULLIF(c.effective_status, ''), 'ACTIVE') = 'ACTIVE'
                THEN TRUE ELSE FALSE
            END AS is_active,
            COALESCE(SUM(id.spend), 0) AS spend_30d,
            COALESCE(SUM(id.deps), 0) AS deps_30d
        FROM campaigns c
        LEFT JOIN ads a ON a.campaign_id = c.id
        LEFT JOIN insights_daily id
            ON id.ad_id = a.id
           AND id.date >= CURRENT_DATE - INTERVAL '29 days'
        WHERE c.ad_account_id = :account_id
          AND COALESCE(c.status, '') <> 'DELETED'
        GROUP BY c.id, c.name, c.status, c.effective_status
        ORDER BY is_active DESC, spend_30d DESC, c.name ASC
        LIMIT 3
    ");
    $stmt->execute([':account_id' => $accountId]);

    return array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'effective_status' => (string)($row['effective_status'] ?? ''),
            'is_active' => !empty($row['is_active']),
            'spend_30d' => (float)($row['spend_30d'] ?? 0),
            'deps_30d' => (int)($row['deps_30d'] ?? 0),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
