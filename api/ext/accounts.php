<?php
// api/ext/accounts.php
// POST { secret, token_hint, bm_id, bm_name, accounts: [...] }
// bm_id - numeric Business Manager ID from FB
// Creates the BM if it does not exist, then upserts accounts

require __DIR__.'/_bootstrap.php';

$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS name_locked BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS disabled_date DATE");

$accounts = $body['accounts'] ?? [];
if (!is_array($accounts) || !count($accounts)) extError(400, 'accounts array required');

$bmId   = trim((string)($body['bm_id']   ?? ''));
$bmName = trim($body['bm_name']           ?? 'Unknown BM');
$fbtoolId = trim((string)($body['fbtool_id'] ?? ''));
$fbtoolAccountId = resolveFbtoolAccountId($db, $fbtoolId);

// If bm_id is not provided, use the special "no BM" BM with id=0
// The extension must pass the real bm_id
if (!$bmId) {
    // Fallback: create a service BM
    $bmId   = '0';
    $bmName = 'Unknown BM (no bm_id provided)';
}

// Upsert BM
$db->prepare("
    INSERT INTO business_managers (id, name, fbtool_account_id, synced_at)
    VALUES (:id, :name, :fbtool_account_id, NOW())
    ON CONFLICT (id) DO UPDATE SET
        name      = CASE
            WHEN business_managers.name_locked THEN business_managers.name
            ELSE EXCLUDED.name
        END,
        fbtool_account_id = COALESCE(business_managers.fbtool_account_id, EXCLUDED.fbtool_account_id),
        synced_at = NOW(),
        updated_at = NOW()
")->execute(['id' => $bmId, 'name' => $bmName, 'fbtool_account_id' => $fbtoolAccountId]);

// Upsert accounts
$stmt = $db->prepare("
    INSERT INTO ad_accounts (id, bm_id, name, status, disabled_date, timezone_name, currency, spend_cap, amount_spent, balance, synced_at)
    VALUES (:id, :bm_id, :name, :status, :disabled_date, :tz, :cur, :cap, :spent, :bal, NOW())
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
        synced_at    = NOW()
");

$upserted = 0;
foreach ($accounts as $acc) {
    $id = trim((string)($acc['id'] ?? ''));
    if (!$id) continue;
    if (!str_starts_with($id, 'act_')) $id = 'act_'.$id;

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
