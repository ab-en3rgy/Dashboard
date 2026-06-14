<?php
// api/ext/get-accounts.php
// POST { secret }
// Returns FBTool accounts grouped with their ad account IDs for the extension.

require __DIR__.'/_bootstrap.php';

$db->exec("
    CREATE TABLE IF NOT EXISTS fbtool_accounts (
        id BIGSERIAL PRIMARY KEY,
        user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        fbtool_id VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(255),
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS disabled_date DATE");

$rows = $db->query("
    SELECT
        fta.id AS fbtool_account_id,
        fta.user_id,
        fta.fbtool_id,
        fta.name AS fbtool_name,
        fta.is_active,
        fta.updated_at,
        COALESCE(NULLIF(u.display_name, ''), u.username) AS user_name,
        bm.id::text AS bm_id,
        aa.id::text AS ad_account_id
    FROM fbtool_accounts fta
    JOIN users u ON u.id = fta.user_id
    LEFT JOIN business_managers bm ON bm.fbtool_account_id = fta.id
    LEFT JOIN ad_accounts aa ON aa.bm_id = bm.id
        AND (aa.disabled_date IS NULL OR aa.disabled_date > CURRENT_DATE - INTERVAL '3 days')
    ORDER BY user_name, fta.fbtool_id, bm.id, aa.id
")->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($rows as $row) {
    $fbtoolId = trim((string)($row['fbtool_id'] ?? ''));
    if ($fbtoolId === '') {
        continue;
    }

    if (!isset($groups[$fbtoolId])) {
        $groups[$fbtoolId] = [
            'fbtool_id' => $fbtoolId,
            'accounts' => [],
        ];
    }

    $accountId = trim((string)($row['ad_account_id'] ?? ''));
    if ($accountId !== '') {
        if (!str_starts_with($accountId, 'act_')) {
            $accountId = 'act_' . $accountId;
        }
        $groups[$fbtoolId]['accounts'][$accountId] = $accountId;
    }
}

$groupsOut = [];
foreach ($groups as $group) {
    $group['accounts'] = array_values($group['accounts']);
    if (!$group['accounts']) continue;
    $groupsOut[] = $group;
}

extOk([
    'accounts' => $groupsOut,
    'count' => count($groupsOut),
    'generated_at' => gmdate('c'),
]);
