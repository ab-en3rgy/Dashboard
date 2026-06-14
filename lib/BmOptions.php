<?php
declare(strict_types=1);

function bmSelectorOptions(PDO $db, ?array $allowedIds = null, bool $withFbAccountId = false): array
{
    $db->exec("
        ALTER TABLE business_managers
        ADD COLUMN IF NOT EXISTS auto_rules_cron_enabled BOOLEAN NOT NULL DEFAULT FALSE
    ");

    if ($allowedIds !== null) {
        $allowedIds = array_values(array_unique(array_filter(
            array_map(static fn($id): string => trim((string)$id), $allowedIds),
            static fn(string $id): bool => $id !== ''
        )));
        if (!$allowedIds) {
            return [];
        }
    }

    $params = [];
    $where = ['bm.is_active = TRUE'];
    if ($allowedIds !== null) {
        $where[] = 'bm.id::text IN (' . implode(',', array_fill(0, count($allowedIds), '?')) . ')';
        $params = $allowedIds;
    }

    $fbSelect = $withFbAccountId ? ', bm.fb_account_id' : '';
    $fbGroup = $withFbAccountId ? ', bm.fb_account_id' : '';

    $stmt = $db->prepare("
        SELECT
            bm.id::text AS id,
            bm.name{$fbSelect},
            COALESCE(bm.auto_rules_cron_enabled, FALSE) AS auto_rules_cron_enabled,
            COALESCE(SUM(i.revenue), 0) - COALESCE(SUM(i.spend), 0) AS profit_30d
        FROM business_managers bm
        LEFT JOIN ad_accounts aa ON aa.bm_id = bm.id
        LEFT JOIN ads a ON a.ad_account_id = aa.id
        LEFT JOIN insights_daily i
            ON i.ad_id = a.id
           AND i.date >= CURRENT_DATE - INTERVAL '29 days'
           AND i.date <= CURRENT_DATE
        WHERE " . implode(' AND ', $where) . "
        GROUP BY bm.id, bm.name, bm.auto_rules_cron_enabled{$fbGroup}
        ORDER BY profit_30d DESC, bm.name ASC, bm.id ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
