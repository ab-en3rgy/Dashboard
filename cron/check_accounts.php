#!/usr/bin/env php
<?php
// cron/check_accounts.php
// Checks remaining balance on FBTool ad accounts and sends a Telegram alert when the balance is low.
//
// Logic:
//   Only accounts with account_status = 1 are checked.
//   spend_cap <= 50000 cents ($500) -> alert when remaining balance < 20000 cents ($200)
//   spend_cap >  50000 cents ($500) -> alert when remaining balance < 50000 cents ($500)
//
// Cron: */30 * * * * php /var/www/html/cron/check_accounts.php >> /var/log/fb-ads-check.log 2>&1

declare(strict_types=1);

require __DIR__.'/../lib/DB.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';
$cfg = require __DIR__.'/../config/config.php';
$db  = DB::getInstance();
GlobalLogger::ensureSchema($db);
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS name_locked BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS disabled_date DATE");

$fbtoolKey = $cfg['fbtool']['key']         ?? '';
$tgToken   = $cfg['telegram']['bot_token'] ?? '';
$tgChatId  = $cfg['telegram']['chat_id']   ?? '';
$runId = 'check_accounts:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));

if (!$fbtoolKey) {
    GlobalLogger::log($db, [
        'source' => 'cron/check_accounts',
        'actor' => 'cron',
        'event_type' => 'cron_failed',
        'entity_type' => 'sync',
        'status' => 'failed',
        'action' => 'check_accounts',
        'reason' => 'FBTool key is not configured',
        'correlation_id' => $runId,
    ]);
    echo "[ERROR] fbtool.key is not configured in config.php\n";
    exit(1);
}

$ts = fn() => '['.date('Y-m-d H:i:s').'] ';

echo "\n".$ts()."=== check_accounts started ===\n";

// HTTP helper for FBTool API requests
function fbtoolGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $resp    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException("cURL error: {$curlErr}");
    }
    if ($code !== 200) {
        throw new RuntimeException("HTTP {$code}: ".substr($resp, 0, 200));
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON response");
    }

    return $data;
}

$fbtoolIds = array_values(array_unique(array_filter(array_map(
    static fn($id) => trim((string)$id),
    (array)($cfg['fbtool']['account_ids'] ?? [])
))));

echo $ts()."FBTool accounts from config: ".count($fbtoolIds)."\n";

if (!$fbtoolIds) {
    GlobalLogger::log($db, [
        'source' => 'cron/check_accounts',
        'actor' => 'cron',
        'event_type' => 'cron_failed',
        'entity_type' => 'sync',
        'status' => 'failed',
        'action' => 'check_accounts',
        'reason' => 'fbtool.account_ids is empty',
        'correlation_id' => $runId,
    ]);
    echo $ts()."ERROR fbtool.account_ids is empty in config.php\n";
    exit(1);
}

GlobalLogger::log($db, [
    'source' => 'cron/check_accounts',
    'actor' => 'cron',
    'event_type' => 'cron_started',
    'entity_type' => 'sync',
    'status' => 'running',
    'action' => 'check_accounts',
    'reason' => 'FBTool account balance check started',
    'payload' => ['fbtool_accounts' => count($fbtoolIds)],
    'correlation_id' => $runId,
]);

// Step 2: get-adaccounts for each FBTool account ID
$alerts  = [];
$total   = 0;
$seenIds = [];
$missingCount = null;
$telegramSent = 0;
$telegramFailed = 0;
$fbtoolAccountMap = loadFbtoolAccountIdMap($db);
$fbtoolAccountMap = [];

printf(
    "\n  %-40s %-20s %-30s %10s %10s %10s  %s\n",
    'Name',
    'account_id',
    'BM',
    'spent',
    'cap',
    'left',
    'status'
);
echo "  ".str_repeat('-', 130)."\n";

foreach ($fbtoolIds as $fbtoolId) {
    $adaccUrl = "https://fbtool.pro/api/get-adaccounts?key={$fbtoolKey}&account={$fbtoolId}";
    try {
        $adaccs = fbtoolGet($adaccUrl);
    } catch (RuntimeException $e) {
        echo $ts()."WARN get-adaccounts (id={$fbtoolId}): ".$e->getMessage()."\n";
        GlobalLogger::log($db, [
            'source' => 'cron/check_accounts',
            'actor' => 'cron',
            'event_type' => 'cron_warning',
            'entity_type' => 'sync',
            'status' => 'warning',
            'action' => 'fbtool_get_adaccounts',
            'reason' => 'FBTool get-adaccounts failed',
            'payload' => ['fbtool_id' => $fbtoolId],
            'error' => $e->getMessage(),
            'correlation_id' => $runId,
        ]);
        $fbtoolAccountMap[] = ['fbtool_id' => $fbtoolId, 'accounts' => []];
        continue;
    }

    // Response may be { data: [...] } or a plain array.
    $cabinets = [];
    if (isset($adaccs['data']) && is_array($adaccs['data'])) {
        $cabinets = $adaccs['data'];
    } else {
        foreach ($adaccs as $v) {
            if (is_array($v) && isset($v['account_id'])) {
                $cabinets[] = $v;
            }
        }
    }

    $fbtoolActIds = [];

    foreach ($cabinets as $cab) {
        $name        = (string)($cab['name'] ?? '-');
        $accountId   = (string)($cab['account_id'] ?? '');
        $amountSpent = (float)($cab['amount_spent'] ?? 0); // cents
        $spendCapRaw = $cab['spend_cap'] ?? null;
        $spendCap    = $spendCapRaw !== null ? (float)$spendCapRaw : null;
        $status      = (int)($cab['account_status'] ?? 1);
        $currency    = (string)($cab['currency'] ?? 'USD');
        $tzName      = (string)($cab['timezone_name'] ?? 'UTC');
        $statusLabel = match ($status) {
            1 => 'Active',
            2 => 'Off',
            3 => 'Debt',
            7 => 'Review',
            9 => 'Grace',
            default => "status:{$status}",
        };

        // Upsert BM from viewable_business.
        $vb = $cab['viewable_business'] ?? $cab['business'] ?? null;
        $bmName = null;
        if ($vb && isset($vb['id'], $vb['name'])) {
            $bmId   = (string)$vb['id'];
            $bmName = (string)$vb['name'];
            $db->prepare("
                INSERT INTO business_managers (id, name, fbtool_account_id)
                VALUES (?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET
                    name       = CASE
                        WHEN business_managers.name_locked THEN business_managers.name
                        ELSE EXCLUDED.name
                    END,
                    fbtool_account_id = COALESCE(business_managers.fbtool_account_id, EXCLUDED.fbtool_account_id),
                    updated_at = NOW()
            ")->execute([$bmId, $bmName, $fbtoolAccountMap[$fbtoolId] ?? null]);
        } else {
            $bmId = $db->query("SELECT id FROM business_managers LIMIT 1")->fetchColumn() ?: null;
        }

        $accPk = '';
        if ($accountId) {
            $accPk = str_starts_with($accountId, 'act_') ? $accountId : 'act_'.$accountId;
            $fbtoolActIds[] = $accPk;
        }

        // Upsert account.
        if ($accPk && $bmId) {
            $seenIds[] = $accPk;
            $beforeAccountStmt = $db->prepare("
                SELECT status, disabled_date, spend_cap, amount_spent, balance
                FROM ad_accounts
                WHERE id = ?
                LIMIT 1
            ");
            $beforeAccountStmt->execute([$accPk]);
            $beforeAccount = $beforeAccountStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $db->prepare("
                INSERT INTO ad_accounts
                    (id, bm_id, name, status, disabled_date, currency, timezone_name,
                     spend_cap, amount_spent, synced_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON CONFLICT (id) DO UPDATE SET
                    bm_id         = EXCLUDED.bm_id,
                    name          = EXCLUDED.name,
                    status        = EXCLUDED.status,
                    disabled_date = CASE
                        WHEN EXCLUDED.status = 1 THEN NULL
                        WHEN ad_accounts.status = 1 AND EXCLUDED.status <> 1 THEN COALESCE(EXCLUDED.disabled_date, CURRENT_DATE)
                        ELSE ad_accounts.disabled_date
                    END,
                    currency      = EXCLUDED.currency,
                    timezone_name = EXCLUDED.timezone_name,
                    spend_cap     = EXCLUDED.spend_cap,
                    amount_spent  = EXCLUDED.amount_spent,
                    synced_at     = NOW()
            ")->execute([
                $accPk,
                $bmId,
                $name,
                $status,
                $status === 1 ? null : date('Y-m-d'),
                $currency,
                $tzName,
                $spendCap !== null ? $spendCap / 100 : null,
                $amountSpent / 100,
            ]);
            $beforeStatus = $beforeAccount !== null ? (int)($beforeAccount['status'] ?? 1) : null;
            if ($beforeStatus !== null && $beforeStatus !== $status) {
                GlobalLogger::log($db, [
                    'source' => 'cron/check_accounts',
                    'actor' => 'cron',
                    'event_type' => 'account_status_changed',
                    'entity_type' => 'account',
                    'entity_id' => $accPk,
                    'bm_id' => (string)$bmId,
                    'account_id' => $accPk,
                    'status' => $status === 1 ? 'done' : 'warning',
                    'action' => 'account_status_changed',
                    'reason' => 'FBTool account status changed during balance check',
                    'before_state' => [
                        'status' => $beforeStatus,
                        'disabled_date' => $beforeAccount['disabled_date'] ?? null,
                        'spend_cap' => $beforeAccount['spend_cap'] ?? null,
                        'amount_spent' => $beforeAccount['amount_spent'] ?? null,
                        'balance' => $beforeAccount['balance'] ?? null,
                    ],
                    'after_state' => [
                        'status' => $status,
                        'disabled_date' => $status === 1 ? null : date('Y-m-d'),
                        'spend_cap' => $spendCap !== null ? $spendCap / 100 : null,
                        'amount_spent' => $amountSpent / 100,
                    ],
                    'payload' => [
                        'account_name' => $name,
                        'status_label' => $statusLabel,
                        'fbtool_id' => $fbtoolId,
                    ],
                    'correlation_id' => $runId,
                ]);
            }
        }

        $spent = $amountSpent / 100;
        $cap   = $spendCap !== null ? $spendCap / 100 : null;
        $bmLabel = isset($bmId, $bmName) && $bmName !== null ? "{$bmName} ({$bmId})" : '-';

        $total++;

        if ($status !== 1) {
            printf(
                "  %-40s %-20s %-30s %10.2f %10s %10s  %s (skip)\n",
                mb_substr($name, 0, 39),
                $accountId,
                mb_substr($bmLabel, 0, 29),
                $spent,
                $cap !== null ? number_format($cap, 2) : '-',
                '-',
                $statusLabel
            );
            continue;
        }

        if ($cap === null || $cap <= 0) {
            printf(
                "  %-40s %-20s %-30s %10.2f %10s %10s  no cap\n",
                mb_substr($name, 0, 39),
                $accountId,
                mb_substr($bmLabel, 0, 29),
                $spent,
                '-',
                '-'
            );
            continue;
        }

        $diff    = $cap - $spent;
        $diffRaw = $spendCap - $amountSpent; // cents
        $needAlert = $spendCap <= 50000 ? $diffRaw < 20000 : $diffRaw < 50000;

        $statusStr = $needAlert ? 'ALERT' : 'ok';

        printf(
            "  %-40s %-20s %-30s %10.2f %10.2f %10.2f  %s\n",
            mb_substr($name, 0, 39),
            $accountId,
            mb_substr($bmLabel, 0, 29),
            $spent,
            $cap,
            $diff,
            $statusStr
        );

        if ($needAlert) {
            $alerts[] = [
                'name'       => $name,
                'account_id' => $accountId,
                'spent'      => $spent,
                'cap'        => $cap,
                'diff'       => $diff,
            ];
            GlobalLogger::log($db, [
                'source' => 'cron/check_accounts',
                'actor' => 'cron',
                'event_type' => 'account_balance_low',
                'entity_type' => 'account',
                'entity_id' => $accPk ?: $accountId,
                'bm_id' => isset($bmId) ? (string)$bmId : '',
                'account_id' => $accPk ?: (str_starts_with($accountId, 'act_') ? $accountId : 'act_' . $accountId),
                'status' => 'alert',
                'action' => 'account_balance_low',
                'reason' => 'Account balance is below top-up threshold',
                'payload' => [
                    'account_name' => $name,
                    'fbtool_id' => $fbtoolId,
                    'spent' => $spent,
                    'cap' => $cap,
                    'left' => $diff,
                    'threshold' => $spendCap <= 50000 ? 200.0 : 500.0,
                ],
                'correlation_id' => $runId,
            ]);
        }
    }

    $fbtoolAccountMap[] = [
        'fbtool_id' => $fbtoolId,
        'accounts' => array_values(array_unique($fbtoolActIds)),
    ];
}

$cacheDir = __DIR__.'/../var';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
file_put_contents(
    $cacheDir.'/fbtool_accounts_map.json',
    json_encode([
        'ts' => time(),
        'accounts' => $fbtoolAccountMap,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);
echo $ts()."FBTool accounts map cache updated: ".count($fbtoolAccountMap)." group(s)\n";

echo "  ".str_repeat('-', 130)."\n";
echo $ts()."Total accounts: {$total}, alerts: ".count($alerts)."\n";

// Step 2b: do not deactivate accounts missing in FBTool.
// If an account disappears temporarily or is reassigned manually,
// its status and BM are managed from /admin/bm.php.
if ($seenIds) {
    $inPh = implode(',', array_fill(0, count($seenIds), '?'));
    $stmtFind = $db->prepare("
        SELECT COUNT(*) FROM ad_accounts
        WHERE status = 1
          AND id NOT IN ($inPh)
    ");
    $stmtFind->execute($seenIds);
    $missingCount = (int)$stmtFind->fetchColumn();
    echo $ts()."Accounts missing in FBTool: {$missingCount} - statuses are unchanged\n";
    GlobalLogger::log($db, [
        'source' => 'cron/check_accounts',
        'actor' => 'cron',
        'event_type' => 'account_missing_in_fbtool',
        'entity_type' => 'sync',
        'status' => $missingCount > 0 ? 'warning' : 'done',
        'action' => 'account_missing_in_fbtool',
        'reason' => 'Active dashboard accounts not present in current FBTool response',
        'payload' => ['missing_count' => $missingCount],
        'correlation_id' => $runId,
    ]);
}

// Step 3: send Telegram notifications.
if (!$alerts) {
    echo $ts()."No alerts, not sending Telegram messages\n";
    echo $ts()."=== Done ===\n";
    GlobalLogger::log($db, [
        'source' => 'cron/check_accounts',
        'actor' => 'cron',
        'event_type' => 'cron_finished',
        'entity_type' => 'sync',
        'status' => 'done',
        'action' => 'check_accounts',
        'reason' => 'FBTool account balance check finished',
        'payload' => [
            'accounts_total' => $total,
            'alerts' => count($alerts),
            'missing_count' => $missingCount,
            'telegram_sent' => 0,
            'telegram_failed' => 0,
            'telegram_status' => 'not_needed',
        ],
        'correlation_id' => $runId,
    ]);
    exit(0);
}

if (!$tgToken || !$tgChatId) {
    echo $ts()."WARN Telegram is not configured (bot_token / chat_id in config.php)\n";
    echo $ts()."=== Done (without Telegram) ===\n";
    GlobalLogger::log($db, [
        'source' => 'cron/check_accounts',
        'actor' => 'cron',
        'event_type' => 'cron_finished',
        'entity_type' => 'sync',
        'status' => 'warning',
        'action' => 'check_accounts',
        'reason' => 'Balance alerts found, but Telegram is not configured',
        'payload' => [
            'accounts_total' => $total,
            'alerts' => count($alerts),
            'missing_count' => $missingCount,
            'telegram_sent' => 0,
            'telegram_failed' => 0,
            'telegram_status' => 'not_configured',
        ],
        'correlation_id' => $runId,
    ]);
    exit(0);
}

foreach ($alerts as $a) {
    $diff1000 = ceil($a['diff'] / 1000) * 1000; // round up to the nearest thousand
    $msg = sprintf(
        "Need top-up\n<b>%s</b> <code>%s</code> +%s",
        htmlspecialchars($a['name']),
        $a['account_id'],
        number_format($diff1000, 0, '.', ' ')
    );

    $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
    $ch = curl_init($tgUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'    => $tgChatId,
            'text'       => $msg,
            'parse_mode' => 'HTML',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $tgResp = curl_exec($ch);
    $tgCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $tgData = json_decode($tgResp, true);
    if ($tgCode === 200 && ($tgData['ok'] ?? false)) {
        echo $ts()."OK Telegram sent: {$a['name']} ({$a['account_id']})\n";
        $telegramSent++;
    } else {
        echo $ts()."ERROR Telegram failed for {$a['account_id']}: ".substr($tgResp, 0, 200)."\n";
        $telegramFailed++;
    }
}

echo $ts()."=== Done ===\n";
GlobalLogger::log($db, [
    'source' => 'cron/check_accounts',
    'actor' => 'cron',
    'event_type' => 'cron_finished',
    'entity_type' => 'sync',
    'status' => $telegramFailed > 0 ? 'warning' : 'done',
    'action' => 'check_accounts',
    'reason' => 'FBTool account balance check finished',
    'payload' => [
        'accounts_total' => $total,
        'alerts' => count($alerts),
        'missing_count' => $missingCount,
        'telegram_sent' => $telegramSent,
        'telegram_failed' => $telegramFailed,
        'telegram_status' => $telegramFailed > 0 ? 'partial_failure' : 'sent',
    ],
    'correlation_id' => $runId,
]);

function loadFbtoolAccountIdMap(PDO $db): array {
    $tableExists = $db->query("SELECT to_regclass('public.fbtool_accounts')")->fetchColumn();
    if (!$tableExists) {
        return [];
    }

    $rows = $db->query("SELECT fbtool_id, id FROM fbtool_accounts")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $fbtoolId = trim((string)($row['fbtool_id'] ?? ''));
        if ($fbtoolId !== '') {
            $map[$fbtoolId] = (int)$row['id'];
        }
    }
    return $map;
}
