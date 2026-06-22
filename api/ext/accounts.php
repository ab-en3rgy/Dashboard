<?php
// api/ext/accounts.php
// @version 1.0.1
// POST { secret, token_hint, bm_id, bm_name, accounts: [...] }
// bm_id - numeric Business Manager ID from FB
// Creates the BM if it does not exist, then upserts accounts

require __DIR__.'/_bootstrap.php';

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
    'launch_restricted' => $bodyLaunch['launch_restricted'],
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
    $accLaunch = array_replace($bodyLaunch, normalizeLaunchFields($acc));

    $stmt->execute([
        'id'     => $id,
        'bm_id'  => $bmId,
        'name'   => trim($acc['name'] ?? $id),
        'status' => (int)($acc['status'] ?? 1),
        'disabled_date' => (int)($acc['status'] ?? 1) === 1 ? null : date('Y-m-d'),
        'tz'     => $acc['timezone_name'] ?? 'UTC',
        'cur'    => $acc['currency']      ?? 'USD',
        'cap'    => isset($acc['spend_cap'])    ? (float)$acc['spend_cap']    : null,
        'spent'  => (float)($acc['amount_spent'] ?? 0),
        'bal'    => (float)($acc['balance']      ?? 0),
        'launch_restricted' => $accLaunch['launch_restricted'],
        'launch_status' => $accLaunch['launch_status'],
        'launch_block_reason' => $accLaunch['launch_block_reason'],
        'launch_checked_at' => $accLaunch['launch_checked_at'],
        'launch_source' => $accLaunch['launch_source'],
        'launch_source_raw' => $accLaunch['launch_source_raw'],
    ]);
    $upserted++;
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
