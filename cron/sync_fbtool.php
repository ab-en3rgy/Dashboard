<?php
// Server-side FBTool -> dashboard sync.
// Usage:
//   php cron/sync_fbtool.php
//   php cron/sync_fbtool.php --days=3
//   php cron/sync_fbtool.php --date=2026-05-15
//   php cron/sync_fbtool.php --from=2026-05-14 --to=2026-05-15 --force-accounts

declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');

require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/Timezone.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';
require_once __DIR__ . '/../lib/ApiSyncLogger.php';

$cfg = require __DIR__ . '/../config/config.php';
$db  = DB::getInstance();
GlobalLogger::ensureSchema($db);
ApiSyncLogger::ensureSchema($db);
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS name_locked BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT");
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS disabled_date DATE");
$runId = 'sync_fbtool:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));

$displayTz = appDateTimeZone($cfg['display_tz'] ?? 'Europe/Chisinau');
date_default_timezone_set($displayTz->getName());

$fbtoolCfg = $cfg['fbtool'] ?? [];
$fbtoolKey = trim((string)($fbtoolCfg['key'] ?? getenv('FBTOOL_KEY') ?: ''));
$baseUrl   = rtrim((string)($fbtoolCfg['url'] ?? 'https://fbtool.pro/'), '/') . '/';
$tgToken   = trim((string)($cfg['telegram']['bot_token'] ?? ''));
$tgChatId  = trim((string)($cfg['telegram']['chat_id'] ?? ''));

if ($fbtoolKey === '') {
    fail('FBTool key is empty. Set config fbtool.key or FBTOOL_KEY.');
}

$opts = parseCliOptions($argv);
$forceAccounts = isset($opts['force-accounts']);
$skipAccounts  = isset($opts['skip-accounts']);

$lockFile = fopen(sys_get_temp_dir() . '/fbtool_sync.lock', 'c');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    fail('Another sync_fbtool.php process is already running.');
}

try {
    logLine('=== FBTool sync started ===');
    GlobalLogger::log($db, [
        'source' => 'cron/sync_fbtool',
        'actor' => 'cron',
        'event_type' => 'cron_started',
        'entity_type' => 'sync',
        'status' => 'running',
        'action' => 'sync_fbtool',
        'reason' => 'FBTool sync started',
        'payload' => [
            'force_accounts' => $forceAccounts,
            'skip_accounts' => $skipAccounts,
            'options' => $opts,
        ],
        'correlation_id' => $runId,
    ]);

    if ($skipAccounts) {
        $fbtoolIds = loadCachedFbtoolIds();
        if (!$fbtoolIds) {
            fail('--skip-accounts requested, but accounts cache is empty. Run without --skip-accounts once.');
        }
        $accountsTotal = 0;
        logLine('Using cached FBTool accounts: ' . count($fbtoolIds));
    } else {
        [$fbtoolIds, $accountsTotal] = syncAccountsCached($db, $baseUrl, $fbtoolKey, $fbtoolCfg, $forceAccounts);
    }

    if (!$fbtoolIds) {
        fail('No FBTool accounts returned.');
    }

    $dates = buildDateList($opts, $displayTz);
    logLine('Dates: ' . implode(', ', $dates));

    $totalAds = 0;
    $totalMetrics = 0;

    foreach ($fbtoolIds as $fbtoolId) {
        logLine("FBTool account: {$fbtoolId}");
        $adAccounts = fetchAdAccounts($db, $baseUrl, $fbtoolKey, $fbtoolId, $fbtoolCfg);
        if (!$adAccounts) {
            logLine("No ad accounts returned for FBTool id={$fbtoolId}, skipping.");
            continue;
        }
        logLine('Ad accounts to sync: ' . count($adAccounts));

        foreach ($dates as $idx => $date) {
            logLine(sprintf('Date %s (%d/%d)', $date, $idx + 1, count($dates)));

            foreach ($adAccounts as $accountIdx => $account) {
                $adAccountId = (string)($account['id'] ?? '');
                if ($adAccountId === '') {
                    continue;
                }

                logLine(sprintf(
                    'Ad account %s (%d/%d)',
                    $adAccountId,
                    $accountIdx + 1,
                    count($adAccounts)
                ));

                $raw = fetchFbtoolStats($db, $baseUrl, $fbtoolId, $date, $adAccountId, $fbtoolCfg);

                $parsed = parseStats($raw, $date);
                logLine(sprintf(
                    'Parsed %s: campaigns=%d adsets=%d ads=%d metrics=%d',
                    $adAccountId,
                    count($parsed['campaigns']),
                    count($parsed['adsets']),
                    count($parsed['ads']),
                    count($parsed['metrics'])
                ));

                upsertStructureByAccount($db, $parsed);
                $metricsUpserted = upsertInsights($db, $parsed['metrics']);

                $totalAds += count($parsed['ads']);
                $totalMetrics += $metricsUpserted;

                logSpendByAccount($parsed);
            }

            if ($idx < count($dates) - 1) {
                sleep(3);
            }
        }
    }

    logLine(sprintf(
        '=== FBTool sync OK: accounts=%d ads=%d metrics=%d ===',
        $accountsTotal,
        $totalAds,
        $totalMetrics
    ));
    GlobalLogger::log($db, [
        'source' => 'cron/sync_fbtool',
        'actor' => 'cron',
        'event_type' => 'cron_finished',
        'entity_type' => 'sync',
        'status' => 'done',
        'action' => 'sync_fbtool',
        'reason' => 'FBTool sync finished',
        'payload' => [
            'accounts_synced' => $accountsTotal,
            'ads' => $totalAds,
            'metrics' => $totalMetrics,
            'fbtool_accounts' => count($fbtoolIds),
            'dates' => $dates,
        ],
        'correlation_id' => $runId,
    ]);
} catch (Throwable $e) {
    fail($e->getMessage(), $e);
} finally {
    if (isset($lockFile) && is_resource($lockFile)) {
        flock($lockFile, LOCK_UN);
        fclose($lockFile);
    }
}

function parseCliOptions(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
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

function buildDateList(array $opts, DateTimeZone $tz): array
{
    if (!empty($opts['date'])) {
        validateDate((string)$opts['date']);
        return [(string)$opts['date']];
    }

    if (!empty($opts['from']) || !empty($opts['to'])) {
        $from = (string)($opts['from'] ?? $opts['to']);
        $to   = (string)($opts['to'] ?? $opts['from']);
        validateDate($from);
        validateDate($to);

        $start = new DateTimeImmutable($from, $tz);
        $end   = new DateTimeImmutable($to, $tz);
        if ($start > $end) {
            fail('--from must be <= --to.');
        }

        $dates = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }
        return $dates;
    }

    $days = max(0, (int)($opts['days'] ?? 1));
    $today = new DateTimeImmutable('now', $tz);
    $dates = [];
    for ($i = $days; $i >= 0; $i--) {
        $dates[] = $today->modify("-{$i} days")->format('Y-m-d');
    }
    return $dates;
}

function validateDate(string $date): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail("Invalid date: {$date}. Expected YYYY-MM-DD.");
    }
}

function syncAccountsCached(PDO $db, string $baseUrl, string $key, array $fbtoolCfg, bool $force): array
{
    $ttlHours = (int)($fbtoolCfg['accounts_cache_ttl_hours'] ?? 24);
    $ttl = max(1, $ttlHours) * 3600;
    $cacheFile = cacheFile();

    if (!$force && is_file($cacheFile)) {
        $cache = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cache) && isset($cache['ts'], $cache['fbtoolIds']) && time() - (int)$cache['ts'] < $ttl) {
            logLine('Accounts cache hit: ' . count($cache['fbtoolIds']) . ' FBTool accounts');
            return [$cache['fbtoolIds'], 0];
        }
    }

    logLine('Fetching FBTool accounts');
    $fbtoolIds = fetchFbtoolAccounts($db, $baseUrl, $key, $fbtoolCfg);
    if (!$fbtoolIds) {
        fail('get-accounts returned empty list.');
    }

    $accountsTotal = 0;
    foreach ($fbtoolIds as $fbtoolId) {
        logLine("Fetching ad accounts for FBTool id={$fbtoolId}");
        $accounts = fetchAdAccounts($db, $baseUrl, $key, $fbtoolId, $fbtoolCfg);
        $accountsTotal += upsertAccounts($db, $accounts);
    }

    ensureCacheDir();
    file_put_contents($cacheFile, json_encode([
        'ts' => time(),
        'fbtoolIds' => $fbtoolIds,
    ], JSON_UNESCAPED_SLASHES));

    logLine("Accounts synced: {$accountsTotal}");
    return [$fbtoolIds, $accountsTotal];
}

function loadCachedFbtoolIds(): array
{
    $cacheFile = cacheFile();
    if (!is_file($cacheFile)) {
        return [];
    }
    $cache = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($cache['fbtoolIds'] ?? null) ? $cache['fbtoolIds'] : [];
}

function cacheFile(): string
{
    return __DIR__ . '/../var/fbtool_accounts_cache.json';
}

function ensureCacheDir(): void
{
    $dir = dirname(cacheFile());
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function fetchFbtoolAccounts(PDO $db, string $baseUrl, string $key, array $fbtoolCfg): array
{
    $url = $baseUrl . 'api/get-accounts?' . http_build_query(['key' => $key]);
    $json = httpJsonGet($db, 'fbtool:get-accounts', $url, browserHeaders($baseUrl, $fbtoolCfg), 60);

    $ids = [];
    foreach ($json as $item) {
        if (is_array($item) && !empty($item['id'])) {
            $ids[] = (string)$item['id'];
        }
    }
    return array_values(array_unique($ids));
}

function fetchAdAccounts(PDO $db, string $baseUrl, string $key, string $fbtoolId, array $fbtoolCfg): array
{
    $url = $baseUrl . 'api/get-adaccounts?' . http_build_query([
        'key' => $key,
        'account' => $fbtoolId,
    ]);
    $json = httpJsonGet($db, 'fbtool:get-adaccounts', $url, browserHeaders($baseUrl, $fbtoolCfg), 90);

    if (!is_array($json['data'] ?? null)) {
        return [];
    }

    $accounts = [];
    foreach ($json['data'] as $acc) {
        if (!is_array($acc)) {
            continue;
        }
        $rawId = trim((string)($acc['account_id'] ?? ''));
        if ($rawId === '') {
            continue;
        }

        $bm = $acc['viewable_business'] ?? [];
        if (!is_array($bm) || empty($bm['id'])) {
            continue;
        }

        $id = str_starts_with($rawId, 'act_') ? $rawId : 'act_' . $rawId;
        $accounts[$id] = [
            'id' => $id,
            'name' => (string)($acc['name'] ?? $id),
            'currency' => (string)($acc['currency'] ?? 'USD'),
            'timezone_name' => (string)($acc['timezone_name'] ?? 'UTC'),
            'amount_spent' => ((float)($acc['amount_spent'] ?? 0)) / 100,
            'spend_cap' => !empty($acc['spend_cap']) ? ((float)$acc['spend_cap']) / 100 : null,
            'status' => (int)($acc['account_status'] ?? 1),
            'bm_id' => (string)$bm['id'],
            'bm_name' => (string)($bm['name'] ?? ('BM ' . $bm['id'])),
            'fbtool_id' => $fbtoolId,
        ];
    }

    logLine('Ad accounts returned: ' . count($accounts));
    return array_values($accounts);
}

function upsertAccounts(PDO $db, array $accounts): int
{
    if (!$accounts) {
        return 0;
    }

    $fbtoolAccountMap = loadFbtoolAccountIdMap($db);

    $byBm = [];
    foreach ($accounts as $acc) {
        $bmId = (string)($acc['bm_id'] ?? '0');
        $byBm[$bmId]['name'] = (string)($acc['bm_name'] ?? 'Unknown BM');
        $fbtoolId = trim((string)($acc['fbtool_id'] ?? ''));
        $byBm[$bmId]['fbtool_account_id'] = $fbtoolId !== '' ? ($fbtoolAccountMap[$fbtoolId] ?? null) : null;
        $byBm[$bmId]['accounts'][] = $acc;
    }

    $bmStmt = $db->prepare("
        INSERT INTO business_managers (id, name, fbtool_account_id, synced_at)
        VALUES (:id, :name, :fbtool_account_id, NOW())
        ON CONFLICT (id) DO UPDATE SET
            name = CASE
                WHEN business_managers.name_locked THEN business_managers.name
                ELSE EXCLUDED.name
            END,
            fbtool_account_id = COALESCE(business_managers.fbtool_account_id, EXCLUDED.fbtool_account_id),
            synced_at = NOW(),
            updated_at = NOW()
    ");

    $accStmt = $db->prepare("
        INSERT INTO ad_accounts (id, bm_id, name, status, disabled_date, timezone_name, currency, spend_cap, amount_spent, balance, synced_at)
        VALUES (:id, :bm_id, :name, :status, :disabled_date, :tz, :cur, :cap, :spent, :bal, NOW())
        ON CONFLICT (id) DO UPDATE SET
            bm_id = CASE WHEN ad_accounts.bm_id IS NOT NULL THEN ad_accounts.bm_id ELSE EXCLUDED.bm_id END,
            name = EXCLUDED.name,
            status = EXCLUDED.status,
            disabled_date = CASE
                WHEN EXCLUDED.status = 1 THEN NULL
                WHEN ad_accounts.status = 1 AND EXCLUDED.status <> 1 THEN COALESCE(EXCLUDED.disabled_date, CURRENT_DATE)
                ELSE ad_accounts.disabled_date
            END,
            timezone_name = EXCLUDED.timezone_name,
            currency = EXCLUDED.currency,
            spend_cap = EXCLUDED.spend_cap,
            amount_spent = EXCLUDED.amount_spent,
            balance = EXCLUDED.balance,
            synced_at = NOW()
    ");

    $count = 0;
    foreach ($byBm as $bmId => $group) {
        $bmStmt->execute([
            'id' => $bmId,
            'name' => $group['name'],
            'fbtool_account_id' => $group['fbtool_account_id'] ?? null,
        ]);
        foreach ($group['accounts'] as $acc) {
            $beforeStmt = $db->prepare("SELECT status, disabled_date, spend_cap, amount_spent, balance FROM ad_accounts WHERE id = :id LIMIT 1");
            $beforeStmt->execute(['id' => $acc['id']]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $accStmt->execute([
                'id' => $acc['id'],
                'bm_id' => $bmId,
                'name' => $acc['name'] ?: $acc['id'],
                'status' => (int)($acc['status'] ?? 1),
                'disabled_date' => (int)($acc['status'] ?? 1) === 1 ? null : date('Y-m-d'),
                'tz' => $acc['timezone_name'] ?? 'UTC',
                'cur' => $acc['currency'] ?? 'USD',
                'cap' => $acc['spend_cap'] ?? null,
                'spent' => (float)($acc['amount_spent'] ?? 0),
                'bal' => (float)($acc['balance'] ?? 0),
            ]);
            $beforeStatus = $before !== null ? (int)($before['status'] ?? 1) : null;
            $afterStatus = (int)($acc['status'] ?? 1);
            if ($beforeStatus !== null && $beforeStatus !== $afterStatus) {
                global $runId;
                GlobalLogger::log($db, [
                    'source' => 'cron/sync_fbtool',
                    'actor' => 'cron',
                    'event_type' => 'account_status_changed',
                    'entity_type' => 'account',
                    'entity_id' => (string)$acc['id'],
                    'bm_id' => (string)$bmId,
                    'account_id' => (string)$acc['id'],
                    'status' => $afterStatus === 1 ? 'done' : 'warning',
                    'action' => 'account_status_changed',
                    'reason' => 'FBTool sync updated account status',
                    'before_state' => [
                        'status' => $beforeStatus,
                        'disabled_date' => $before['disabled_date'] ?? null,
                        'spend_cap' => $before['spend_cap'] ?? null,
                        'amount_spent' => $before['amount_spent'] ?? null,
                        'balance' => $before['balance'] ?? null,
                    ],
                    'after_state' => [
                        'status' => $afterStatus,
                        'disabled_date' => $afterStatus === 1 ? null : date('Y-m-d'),
                        'spend_cap' => $acc['spend_cap'] ?? null,
                        'amount_spent' => (float)($acc['amount_spent'] ?? 0),
                        'balance' => (float)($acc['balance'] ?? 0),
                    ],
                    'payload' => [
                        'account_name' => $acc['name'] ?? '',
                        'fbtool_id' => $acc['fbtool_id'] ?? '',
                    ],
                    'correlation_id' => $runId ?? null,
                ]);
            }
            $count++;
        }
    }
    return $count;
}

function loadFbtoolAccountIdMap(PDO $db): array
{
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

function fetchFbtoolStats(PDO $db, string $baseUrl, string $fbtoolId, string $date, string $adAccountId, array $fbtoolCfg): array
{
    $url = $baseUrl . 'ajax/get-statistics?' . http_build_query([
        'id' => $fbtoolId,
        'dates' => "{$date} - {$date}",
        'status' => 'all',
        'currency' => 'USD',
        'adaccount_status' => 'active',
        'ad_account_id' => $adAccountId,
    ]);

    $headers = browserHeaders($baseUrl, $fbtoolCfg, true);
    try {
        $json = httpJsonGet($db, 'fbtool:get-statistics', $url, $headers, 90);
    } catch (Throwable $e) {
        throw new RuntimeException("get-statistics request failed: {$url} :: {$e->getMessage()}", 0, $e);
    }

    $rowsCount = is_array($json[0]['rows'] ?? null) ? count($json[0]['rows']) : 0;
    $baseName = (string)($json[0]['info']['base_name'] ?? '');
    logLine("get-statistics {$adAccountId} rows={$rowsCount} base_name={$baseName}");

    return $json;
}

function parseStats(array $json, string $date): array
{
    $rows = is_array($json[0]['rows'] ?? null) ? $json[0]['rows'] : [];
    $campaigns = [];
    $adsets = [];
    $ads = [];
    $metrics = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $adId = trim((string)($row['id'] ?? ''));
        $accId = trim((string)($row['ad_account_id'] ?? ''));
        $campId = trim((string)($row['campaign_id'] ?? ''));
        $adsetId = trim((string)($row['adset_id'] ?? ''));

        if ($adId === '' || $accId === '' || $campId === '' || $adsetId === '') {
            continue;
        }

        $fullAccId = str_starts_with($accId, 'act_') ? $accId : 'act_' . $accId;

        if (!isset($campaigns[$campId])) {
            $campaigns[$campId] = [
                'id' => $campId,
                'ad_account_id' => $fullAccId,
                'name' => (string)($row['campaign_name'] ?? $campId),
                'status' => (string)($row['campaign_status'] ?? 'PAUSED'),
                'effective_status' => (string)($row['campaign_effective_status'] ?? $row['campaign_status'] ?? 'PAUSED'),
                'objective' => $row['campaign_objective'] ?? null,
                'daily_budget' => isset($row['campaign_daily_budget']) ? (float)$row['campaign_daily_budget'] : null,
                'lifetime_budget' => isset($row['campaign_lifetime_budget']) ? (float)$row['campaign_lifetime_budget'] : null,
                'created_time' => $row['campaign_created_time'] ?? null,
                'updated_time' => $row['campaign_updated_time'] ?? null,
            ];
        }

        if (!isset($adsets[$adsetId])) {
            $adsets[$adsetId] = [
                'id' => $adsetId,
                'campaign_id' => $campId,
                'ad_account_id' => $fullAccId,
                'name' => (string)($row['adset_name'] ?? $adsetId),
                'status' => (string)($row['adset_status'] ?? 'PAUSED'),
                'effective_status' => (string)($row['adset_effective_status'] ?? $row['adset_status'] ?? 'PAUSED'),
                'daily_budget' => isset($row['adset_daily_budget']) ? (float)$row['adset_daily_budget'] : null,
                'lifetime_budget' => isset($row['adset_lifetime_budget']) ? (float)$row['adset_lifetime_budget'] : null,
                'created_time' => $row['adset_created_time'] ?? null,
                'updated_time' => $row['adset_updated_time'] ?? null,
            ];
        }

        if (!isset($ads[$adId])) {
            $ads[$adId] = [
                'id' => $adId,
                'adset_id' => $adsetId,
                'campaign_id' => $campId,
                'ad_account_id' => $fullAccId,
                'name' => (string)($row['name'] ?? $adId),
                'status' => (string)($row['status'] ?? 'PAUSED'),
                'effective_status' => (string)($row['effective_status'] ?? $row['status'] ?? 'PAUSED'),
                'created_time' => $row['created_time'] ?? null,
                'updated_time' => $row['updated_time'] ?? null,
            ];
        }

        $impressions = (int)($row['impressions'] ?? 0);
        $clicks = (int)($row['clicks'] ?? 0);
        $spend = (float)($row['spend'] ?? 0);
        $cpc = (float)($row['cplc'] ?? 0);
        $ctr = $impressions > 0 ? $clicks / $impressions * 100 : 0;
        $cpm = $impressions > 0 ? $spend / $impressions * 1000 : 0;

        $metrics[] = [
            'ad_id' => $adId,
            'ad_account_id' => $fullAccId,
            'date' => $date,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => $spend,
            'cpc' => $cpc,
            'ctr' => round($ctr, 4),
            'cpm' => round($cpm, 4),
            'frequency' => 0,
        ];
    }

    return [
        'campaigns' => array_values($campaigns),
        'adsets' => array_values($adsets),
        'ads' => array_values($ads),
        'metrics' => $metrics,
    ];
}

function normalizeStructureCreatedTime(mixed $value): ?string
{
    if ($value === null) return null;
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '0' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
        return null;
    }
    return $raw;
}

function fallbackCreatedTime(mixed $value): string
{
    return normalizeStructureCreatedTime($value) ?? gmdate('c');
}

function upsertStructureByAccount(PDO $db, array $parsed): void
{
    $byAccount = [];
    foreach ($parsed['campaigns'] as $item) {
        $byAccount[$item['ad_account_id']]['campaigns'][] = $item;
    }
    foreach ($parsed['adsets'] as $item) {
        $byAccount[$item['ad_account_id']]['adsets'][] = $item;
    }
    foreach ($parsed['ads'] as $item) {
        $byAccount[$item['ad_account_id']]['ads'][] = $item;
    }

    foreach ($byAccount as $adAccountId => $struct) {
        upsertStructure($db, $adAccountId, $struct['campaigns'] ?? [], $struct['adsets'] ?? [], $struct['ads'] ?? []);
    }
}

function upsertStructure(PDO $db, string $adAccountId, array $campaigns, array $adsets, array $ads): void
{
    $defaultBmId = $db->query('SELECT id FROM business_managers LIMIT 1')->fetchColumn();
    if ($defaultBmId) {
        $db->prepare("
            INSERT INTO ad_accounts (id, bm_id, name, status)
            VALUES (?, ?, ?, 1)
            ON CONFLICT (id) DO NOTHING
        ")->execute([$adAccountId, $defaultBmId, "Account {$adAccountId}"]);
    }

    if ($campaigns) {
        $stmt = $db->prepare("
            INSERT INTO campaigns
                (id, ad_account_id, name, status, effective_status, objective,
                 daily_budget, lifetime_budget, created_time, updated_time, synced_at)
            VALUES
                (:id, :acc, :name, :status, :eff, :obj, :db, :lb, :ct, :ut, NOW())
            ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                status = CASE
                    WHEN campaigns.status = 'DELETED' OR campaigns.effective_status = 'DELETED' THEN 'DELETED'
                    WHEN campaigns.status = 'MANUAL_STOP' THEN campaigns.status
                    ELSE EXCLUDED.status
                END,
                effective_status = CASE
                    WHEN campaigns.status = 'DELETED' OR campaigns.effective_status = 'DELETED' THEN 'DELETED'
                    WHEN campaigns.status = 'MANUAL_STOP' OR campaigns.effective_status = 'MANUAL_STOP' THEN 'MANUAL_STOP'
                    ELSE EXCLUDED.effective_status
                END,
                objective = EXCLUDED.objective,
                daily_budget = EXCLUDED.daily_budget,
                lifetime_budget = EXCLUDED.lifetime_budget,
                created_time = COALESCE(campaigns.created_time, EXCLUDED.created_time, NOW()),
                updated_time = EXCLUDED.updated_time,
                synced_at = NOW()
        ");
        foreach ($campaigns as $c) {
            $stmt->execute([
                'id' => (string)$c['id'],
                'acc' => $adAccountId,
                'name' => $c['name'] ?? '',
                'status' => $c['status'] ?? 'PAUSED',
                'eff' => $c['effective_status'] ?? null,
                'obj' => $c['objective'] ?? null,
                'db' => isset($c['daily_budget']) ? (float)$c['daily_budget'] / 100 : null,
                'lb' => isset($c['lifetime_budget']) ? (float)$c['lifetime_budget'] / 100 : null,
                'ct' => fallbackCreatedTime($c['created_time'] ?? null),
                'ut' => $c['updated_time'] ?? null,
            ]);
        }
    }

    if ($adsets) {
        $stmt = $db->prepare("
            INSERT INTO ad_sets
                (id, campaign_id, ad_account_id, name, status, effective_status,
                 daily_budget, lifetime_budget, created_time, updated_time, synced_at)
            VALUES
                (:id, :camp, :acc, :name, :status, :eff, :db, :lb, :ct, :ut, NOW())
            ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                status = EXCLUDED.status,
                effective_status = EXCLUDED.effective_status,
                daily_budget = EXCLUDED.daily_budget,
                lifetime_budget = EXCLUDED.lifetime_budget,
                updated_time = EXCLUDED.updated_time,
                synced_at = NOW()
        ");
        foreach ($adsets as $s) {
            $stmt->execute([
                'id' => (string)$s['id'],
                'camp' => (string)$s['campaign_id'],
                'acc' => $adAccountId,
                'name' => $s['name'] ?? '',
                'status' => $s['status'] ?? 'PAUSED',
                'eff' => $s['effective_status'] ?? null,
                'db' => isset($s['daily_budget']) ? (float)$s['daily_budget'] / 100 : null,
                'lb' => isset($s['lifetime_budget']) ? (float)$s['lifetime_budget'] / 100 : null,
                'ct' => $s['created_time'] ?? null,
                'ut' => $s['updated_time'] ?? null,
            ]);
        }
    }

    if ($ads) {
        $stmt = $db->prepare("
            INSERT INTO ads
                (id, ad_set_id, campaign_id, ad_account_id, name,
                 status, effective_status, created_time, updated_time, synced_at)
            VALUES
                (:id, :adset, :camp, :acc, :name, :status, :eff, :ct, :ut, NOW())
            ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                status = EXCLUDED.status,
                effective_status = EXCLUDED.effective_status,
                updated_time = EXCLUDED.updated_time,
                synced_at = NOW()
        ");
        foreach ($ads as $a) {
            $adName = (string)($a['name'] ?? '');
            if (!preg_match('/\.(mp4|png|jpg)$/i', $adName)) {
                $adName .= '.mp4';
            }
            $stmt->execute([
                'id' => (string)$a['id'],
                'adset' => (string)$a['adset_id'],
                'camp' => (string)($a['campaign_id'] ?? 0),
                'acc' => $adAccountId,
                'name' => $adName,
                'status' => $a['status'] ?? 'PAUSED',
                'eff' => $a['effective_status'] ?? null,
                'ct' => $a['created_time'] ?? null,
                'ut' => $a['updated_time'] ?? null,
            ]);
        }
    }
}

function upsertInsights(PDO $db, array $rows): int
{
    if (!$rows) {
        return 0;
    }

    $existing = fetchExistingInsightSpends($db, $rows);

    $stmt = $db->prepare("
        INSERT INTO insights_daily
            (ad_id, date, impressions, clicks, spend, delta, cpc, ctr, cpm, frequency, fb_synced_at)
        VALUES
            (:ad_id, :date, :impr, :clicks, :spend, :delta, :cpc, :ctr, :cpm, :freq, NOW())
        ON CONFLICT (ad_id, date) DO UPDATE SET
            impressions = EXCLUDED.impressions,
            clicks = EXCLUDED.clicks,
            spend = EXCLUDED.spend,
            delta = EXCLUDED.delta,
            cpc = EXCLUDED.cpc,
            ctr = EXCLUDED.ctr,
            cpm = EXCLUDED.cpm,
            frequency = EXCLUDED.frequency,
            fb_synced_at = NOW()
    ");

    $count = 0;
    foreach ($rows as $row) {
        if (empty($row['ad_id']) || empty($row['date'])) {
            continue;
        }
        $adId = (string)$row['ad_id'];
        $date = (string)$row['date'];
        $newSpend = (float)($row['spend'] ?? 0);
        $oldSpend = (float)($existing[$date][$adId] ?? 0);
        $stmt->execute([
            'ad_id' => $adId,
            'date' => $date,
            'impr' => (int)($row['impressions'] ?? 0),
            'clicks' => (int)($row['clicks'] ?? 0),
            'spend' => $newSpend,
            'delta' => round($newSpend - $oldSpend, 4),
            'cpc' => (float)($row['cpc'] ?? 0),
            'ctr' => (float)($row['ctr'] ?? 0),
            'cpm' => (float)($row['cpm'] ?? 0),
            'freq' => (float)($row['frequency'] ?? 0),
        ]);
        $count++;
    }
    return $count;
}

function fetchExistingInsightSpends(PDO $db, array $rows): array
{
    $byDate = [];
    foreach ($rows as $row) {
        $date = trim((string)($row['date'] ?? ''));
        $adId = trim((string)($row['ad_id'] ?? ''));
        if ($date === '' || $adId === '') {
            continue;
        }
        $byDate[$date][] = $adId;
    }
    if (!$byDate) {
        return [];
    }

    $out = [];
    foreach ($byDate as $date => $adIds) {
        $adIds = array_values(array_unique($adIds));
        $ph = [];
        $params = [':date' => $date];
        foreach ($adIds as $i => $adId) {
            $key = ":ad_{$i}";
            $ph[] = $key;
            $params[$key] = $adId;
        }
        $stmt = $db->prepare("
            SELECT ad_id::text AS ad_id, spend
            FROM insights_daily
            WHERE date = :date
              AND ad_id IN (" . implode(',', $ph) . ")
        ");
        $stmt->execute($params);
        $out[$date] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$date][(string)$row['ad_id']] = (float)$row['spend'];
        }
    }

    return $out;
}

function browserHeaders(string $baseUrl, array $fbtoolCfg, bool $ajax = false): array
{
    $ua = (string)($fbtoolCfg['user_agent'] ?? getenv('FBTOOL_USER_AGENT') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    $cookieHeader = buildCookieHeader((string)($fbtoolCfg['cookie_header'] ?? getenv('FBTOOL_COOKIE') ?: ''));

    $headers = [
        'User-Agent: ' . $ua,
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Referer: ' . $baseUrl,
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
    ];

    if ($ajax) {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    if ($cookieHeader !== '') {
        $headers[] = 'Cookie: ' . $cookieHeader;
    }

    return $headers;
}

function buildCookieHeader(string $raw): string
{
    $cookies = [];
    foreach (explode(';', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !str_contains($part, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $part, 2);
        $cookies[trim($name)] = trim($value);
    }

    $cookies['statistics_mode'] = '3acf6762b832cfcbb1f05e0e581d7d9d9792d23635c5e3e8010d8c826c062bd1a%3A2%3A%7Bi%3A0%3Bs%3A15%3A%22statistics_mode%22%3Bi%3A1%3Bs%3A3%3A%22ads%22%3B%7D';
    $cookies['ad_status'] = '654233790f455972c207a9d4092f5ed64e09114c214276bf47e3ba0dd6b600b4a%3A2%3A%7Bi%3A0%3Bs%3A9%3A%22ad_status%22%3Bi%3A1%3Bs%3A3%3A%22all%22%3B%7D';

    $pairs = [];
    foreach ($cookies as $name => $value) {
        if ($name !== '') {
            $pairs[] = $name . '=' . $value;
        }
    }
    return implode('; ', $pairs);
}

function httpJsonGet(PDO $db, string $requestType, string $url, array $headers, int $timeout): array
{
    $startedAt = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    curl_close($ch);

    if ($err) {
        ApiSyncLogger::log($db, [
            'request_type' => $requestType,
            'endpoint' => $url,
            'status' => 'failed',
            'http_code' => $code ?: null,
            'duration_ms' => $durationMs,
            'error_msg' => $err,
            'response_preview' => ApiSyncLogger::preview((string)$body, 2000),
            'raw_error' => [
                'curl_error' => $err,
                'http_code' => $code,
                'duration_ms' => $durationMs,
                'content_type' => $contentType,
            ],
        ]);
        throw new RuntimeException("cURL error: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        ApiSyncLogger::log($db, [
            'request_type' => $requestType,
            'endpoint' => $url,
            'status' => 'failed',
            'http_code' => $code,
            'duration_ms' => $durationMs,
            'error_msg' => "HTTP {$code}",
            'response_preview' => ApiSyncLogger::preview((string)$body, 2000),
            'raw_error' => [
                'http_code' => $code,
                'duration_ms' => $durationMs,
                'content_type' => $contentType,
            ],
        ]);
        throw new RuntimeException("HTTP {$code}: " . substr((string)$body, 0, 300));
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        ApiSyncLogger::log($db, [
            'request_type' => $requestType,
            'endpoint' => $url,
            'status' => 'failed',
            'http_code' => $code,
            'duration_ms' => $durationMs,
            'error_msg' => 'Invalid JSON',
            'response_preview' => ApiSyncLogger::preview((string)$body, 2000),
            'raw_error' => [
                'http_code' => $code,
                'duration_ms' => $durationMs,
                'content_type' => $contentType,
                'json_error' => json_last_error_msg(),
            ],
        ]);
        $bodyStr = (string)$body;
        throw new RuntimeException(sprintf(
            'Invalid JSON response (len=%d, type=%s): %s',
            strlen($bodyStr),
            $contentType !== '' ? $contentType : 'unknown',
            substr($bodyStr, 0, 300)
        ));
    }
    ApiSyncLogger::log($db, [
        'request_type' => $requestType,
        'endpoint' => $url,
        'status' => 'ok',
        'http_code' => $code,
        'duration_ms' => $durationMs,
        'rows_returned' => is_array($json['data'] ?? null) ? count($json['data']) : 0,
        'response_preview' => ApiSyncLogger::preview((string)$body, 2000),
        'raw_error' => null,
    ]);
    return $json;
}

function logSpendByAccount(array $parsed): void
{
    if (!$parsed['metrics']) {
        return;
    }

    $spendByAcc = [];
    foreach ($parsed['metrics'] as $row) {
        $acc = (string)($row['ad_account_id'] ?? '?');
        $spendByAcc[$acc] = ($spendByAcc[$acc] ?? 0) + (float)($row['spend'] ?? 0);
    }
    arsort($spendByAcc);

    foreach ($spendByAcc as $acc => $spend) {
        if ($spend > 0) {
            logLine(sprintf('Spend %s: $%.2f', $acc, $spend));
        }
    }
}

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function notifySyncFbtoolError(string $message): void
{
    global $tgToken, $tgChatId;

    if ($tgToken === '' || $tgChatId === '') {
        return;
    }
    if (stripos($message, 'Another sync_fbtool.php process is already running.') !== false) {
        return;
    }

    $hostname = gethostname() ?: php_uname('n') ?: 'unknown-host';
    $text = "<b>FBTool sync error</b>\n" .
        "<code>{$hostname}</code>\n" .
        htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $ch = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $tgChatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function fail(string $message, ?Throwable $e = null): never
{
    global $db, $runId;
    if (isset($db) && $db instanceof PDO) {
        GlobalLogger::log($db, [
            'source' => 'cron/sync_fbtool',
            'actor' => 'cron',
            'event_type' => 'cron_failed',
            'entity_type' => 'sync',
            'status' => 'failed',
            'action' => 'sync_fbtool',
            'reason' => 'FBTool sync failed',
            'error' => $message,
            'payload' => [
                'exception' => $e ? get_class($e) : null,
            ],
            'correlation_id' => $runId ?? null,
        ]);
    }
    notifySyncFbtoolError($message);
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL);
    if ($e) {
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
