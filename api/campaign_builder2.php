<?php
// api/campaign_builder2.php
// @version 1.0.2
// Separate inventory-first Campaign Builder for launch readiness.

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CreativeGeoRank.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';

const CAMPAIGN_BUILDER2_DEFAULT_URL_PARAMS = 'sub_id_1={{ad.id}}&sub_id_2={{campaign.id}}&sub_id_3=14886&sub_id_4={{campaign.name}}&sub_id_5={{adset.id}}&sub_id_6={{adset.name}}&sub_id_7={{ad.name}}&sub_id_8={{placement}}&pixel={pixel}';

$allowedBmIds = array_values(array_filter(array_map('strval', $auth->allowedBmIds($me))));
if (!$allowedBmIds) {
    apiOk([
        'filters' => ['bms' => [], 'geos' => []],
        'summary' => builder2EmptySummary(),
        'rows' => [],
        'creatives' => [],
        'defaults' => builder2Defaults(''),
    ]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string)($_GET['action'] ?? 'inventory')));
$body = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) apiError(400, 'Invalid JSON body');
    $action = strtolower(trim((string)($body['action'] ?? $action ?: 'create')));
}

[$bmInSql, $bmParams] = builder2BmInSql($allowedBmIds);

if ($method === 'GET' && $action === 'inventory') {
    $geo = strtoupper(trim((string)($_GET['geo'] ?? '')));
    if ($geo !== '' && !preg_match('/^[A-Z]{2}$/', $geo)) apiError(400, 'geo must be 2 letters');
    apiOk(fetchBuilder2Inventory($db, $me, $allowedBmIds, $bmInSql, $bmParams, $geo));
}

if ($method === 'POST' && $action === 'create') {
    ensureBuilder2TasksSchema($db);
    GlobalLogger::ensureSchema($db);
    createBuilder2Tasks($db, $me, $allowedBmIds, $bmInSql, $bmParams, $body);
}

apiError(404, 'Unknown action');

function fetchBuilder2Inventory(PDO $db, array $me, array $allowedBmIds, string $bmInSql, array $bmParams, string $geo): array
{
    $bms = fetchBuilder2Bms($db, $bmInSql, $bmParams);
    $geos = fetchBuilder2Geos($db, $me, $allowedBmIds, $bmInSql, $bmParams);
    $accounts = fetchBuilder2Accounts($db, $bmInSql, $bmParams);
    $activeGeoCounts = fetchBuilder2ActiveGeoCounts($db, $bmInSql, $bmParams, $geo);
    $pendingTasks = fetchBuilder2PendingTasks($db, $bmInSql, $bmParams, $geo);
    $creativeRows = $geo !== '' ? fetchBuilder2GeoCreatives($db, $bmInSql, $bmParams, $geo, $me) : [];
    $defaults = builder2Defaults($geo);

    $rows = [];
    foreach ($accounts as $account) {
        $accountId = (string)$account['account_id'];
        $activeForGeo = $geo !== '' ? (int)($activeGeoCounts[$accountId][$geo] ?? 0) : 0;
        $pendingForGeo = $geo !== '' ? (int)($pendingTasks[$accountId] ?? 0) : 0;
        $readiness = builder2Readiness($account, $geo, $activeForGeo, $pendingForGeo);
        $rows[] = [
            'account_id' => $accountId,
            'account_name' => (string)$account['account_name'],
            'account_status' => (int)$account['account_status'],
            'bm_id' => (string)$account['bm_id'],
            'bm_name' => (string)$account['bm_name'],
            'eligible_account' => (bool)$account['eligible_account'],
            'account_block_reason' => (string)$account['account_block_reason'],
            'active_geo_count' => $activeForGeo,
            'pending_create_count' => $pendingForGeo,
            'active_geos' => array_keys($activeGeoCounts[$accountId] ?? []),
            'ready' => $readiness['ready'],
            'status_key' => $readiness['status_key'],
            'status_label' => $readiness['status_label'],
            'block_reason' => $readiness['block_reason'],
            'warnings' => $readiness['warnings'],
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $rank = ['ready' => 0, 'warn' => 1, 'blocked' => 2, 'idle' => 3];
        $ra = $rank[$a['status_key']] ?? 9;
        $rb = $rank[$b['status_key']] ?? 9;
        if ($ra !== $rb) return $ra <=> $rb;
        return [(string)$a['bm_name'], (string)$a['account_name'], (string)$a['account_id']]
            <=> [(string)$b['bm_name'], (string)$b['account_name'], (string)$b['account_id']];
    });

    $summary = builder2SummarizeRows($rows, $creativeRows);
    return [
        'filters' => ['bms' => $bms, 'geos' => $geos],
        'summary' => $summary,
        'rows' => $rows,
        'creatives' => $creativeRows,
        'defaults' => $defaults,
    ];
}

function builder2Readiness(array $account, string $geo, int $activeForGeo, int $pendingForGeo): array
{
    $warnings = [];
    if ($geo === '') {
        return ['ready' => false, 'status_key' => 'idle', 'status_label' => 'Choose GEO', 'block_reason' => 'Choose a GEO to calculate launch readiness.', 'warnings' => []];
    }
    if (!$account['eligible_account']) {
        return ['ready' => false, 'status_key' => 'blocked', 'status_label' => 'Blocked', 'block_reason' => (string)$account['account_block_reason'], 'warnings' => []];
    }
    if ($activeForGeo > 0) {
        return ['ready' => false, 'status_key' => 'blocked', 'status_label' => 'Has GEO', 'block_reason' => 'Account already has an active campaign on this GEO.', 'warnings' => []];
    }
    if ($pendingForGeo > 0) {
        return ['ready' => false, 'status_key' => 'blocked', 'status_label' => 'Pending', 'block_reason' => 'A create campaign task is already pending or running for this GEO.', 'warnings' => []];
    }
    return [
        'ready' => true,
        'status_key' => $warnings ? 'warn' : 'ready',
        'status_label' => 'Ready',
        'block_reason' => '',
        'warnings' => $warnings,
    ];
}

function builder2SummarizeRows(array $rows, array $creativeRows): array
{
    $summary = builder2EmptySummary();
    $summary['accounts_total'] = count($rows);
    $summary['creatives_total'] = count($creativeRows);
    foreach ($creativeRows as $creative) {
        if ((int)($creative['rank'] ?? 0) > 0 && (int)$creative['rank'] <= 10) $summary['creatives_ranked']++;
    }
    foreach ($rows as $row) {
        if ($row['ready']) $summary['ready_accounts']++;
        if ($row['status_key'] === 'blocked') $summary['blocked_accounts']++;
        if ((int)$row['pending_create_count'] > 0) $summary['pending_tasks'] += (int)$row['pending_create_count'];
        if ((int)$row['active_geo_count'] > 0) $summary['active_geo_accounts']++;
    }
    return $summary;
}

function builder2EmptySummary(): array
{
    return [
        'accounts_total' => 0,
        'ready_accounts' => 0,
        'blocked_accounts' => 0,
        'active_geo_accounts' => 0,
        'pending_tasks' => 0,
        'creatives_total' => 0,
        'creatives_ranked' => 0,
    ];
}

function fetchBuilder2Bms(PDO $db, string $bmInSql, array $params): array
{
    $stmt = $db->prepare("
        SELECT id::text AS bm_id, name AS bm_name
        FROM public.business_managers
        WHERE id::text IN {$bmInSql}
        ORDER BY name ASC, id ASC
    ");
    $stmt->execute($params);
    return array_map(static fn(array $row): array => [
        'bm_id' => (string)$row['bm_id'],
        'bm_name' => (string)$row['bm_name'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function fetchBuilder2Geos(PDO $db, array $me, array $allowedBmIds, string $bmInSql, array $params): array
{
    $geos = [];
    $stmt = $db->prepare("
        SELECT DISTINCT campaign_geo(c.name) AS geo
        FROM public.campaigns c
        JOIN public.ad_accounts aa ON aa.id = c.ad_account_id
        WHERE aa.bm_id::text IN {$bmInSql}
          AND campaign_geo(c.name) ~ '^[A-Z]{2}$'
        ORDER BY geo
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $geo) {
        $geo = strtoupper(trim((string)$geo));
        if (preg_match('/^[A-Z]{2}$/', $geo)) $geos[$geo] = true;
    }

    $fpRows = fetchBuilder2FpConfigs($db, $me, $allowedBmIds, '');
    foreach ($fpRows as $row) {
        $geo = strtoupper(trim((string)$row['geo']));
        if (preg_match('/^[A-Z]{2}$/', $geo)) $geos[$geo] = true;
        foreach (($row['used_geos'] ?? []) as $usedGeo) {
            $usedGeo = strtoupper(trim((string)$usedGeo));
            if (preg_match('/^[A-Z]{2}$/', $usedGeo)) $geos[$usedGeo] = true;
        }
    }
    $out = array_keys($geos);
    sort($out);
    return $out;
}

function fetchBuilder2Accounts(PDO $db, string $bmInSql, array $params): array
{
    $stmt = $db->prepare("
        SELECT
            aa.id::text AS account_id,
            aa.name AS account_name,
            aa.status AS account_status,
            bm.id::text AS bm_id,
            bm.name AS bm_name,
            CASE
                WHEN aa.status <> 1 THEN 0
                WHEN bm.is_active IS DISTINCT FROM TRUE THEN 0
                ELSE 1
            END AS eligible_account,
            CASE
                WHEN aa.status <> 1 THEN 'Account is not active'
                WHEN bm.is_active IS DISTINCT FROM TRUE THEN 'BM is inactive'
                ELSE ''
            END AS account_block_reason
        FROM public.ad_accounts aa
        JOIN public.business_managers bm ON bm.id = aa.bm_id
        WHERE aa.bm_id::text IN {$bmInSql}
        ORDER BY bm.name ASC, aa.name ASC, aa.id ASC
    ");
    $stmt->execute($params);
    return array_map(static fn(array $row): array => [
        'account_id' => (string)$row['account_id'],
        'account_name' => (string)$row['account_name'],
        'account_status' => (int)$row['account_status'],
        'bm_id' => (string)$row['bm_id'],
        'bm_name' => (string)$row['bm_name'],
        'eligible_account' => (bool)$row['eligible_account'],
        'account_block_reason' => (string)$row['account_block_reason'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function fetchBuilder2ActiveGeoCounts(PDO $db, string $bmInSql, array $params, string $geo): array
{
    $sql = "
        SELECT
            c.ad_account_id::text AS account_id,
            campaign_geo(c.name) AS geo,
            COUNT(*) AS cnt
        FROM public.campaigns c
        JOIN public.ad_accounts aa ON aa.id = c.ad_account_id
        WHERE aa.bm_id::text IN {$bmInSql}
          AND campaign_geo(c.name) ~ '^[A-Z]{2}$'
          AND COALESCE(c.status, '') NOT IN ('MANUAL_STOP', 'ARCHIVED', 'DELETED')
          AND COALESCE(c.effective_status, '') NOT IN ('MANUAL_STOP', 'ARCHIVED', 'DELETED')
          AND (
              COALESCE(c.effective_status, '') = 'ACTIVE'
              OR COALESCE(c.status, '') = 'ACTIVE'
          )
    ";
    if ($geo !== '') {
        $sql .= " AND campaign_geo(c.name) = :geo";
        $params[':geo'] = $geo;
    }
    $sql .= " GROUP BY c.ad_account_id, campaign_geo(c.name)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $map[(string)$row['account_id']][(string)$row['geo']] = (int)$row['cnt'];
    }
    return $map;
}

function fetchBuilder2PendingTasks(PDO $db, string $bmInSql, array $params, string $geo): array
{
    if (!$db->query("SELECT to_regclass('public.tasks')")->fetchColumn()) return [];
    $sql = "
        SELECT account_id::text AS account_id, COUNT(*) AS cnt
        FROM public.tasks
        WHERE task_type = 'create_campaign'
          AND status IN ('pending', 'running')
          AND bm_id IN {$bmInSql}
    ";
    if ($geo !== '') {
        $sql .= " AND UPPER(COALESCE(payload->>'geo', '')) = :task_geo";
        $params[':task_geo'] = $geo;
    }
    $sql .= " GROUP BY account_id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $map[(string)$row['account_id']] = (int)$row['cnt'];
    }
    return $map;
}

function fetchBuilder2GeoCreatives(PDO $db, string $bmInSql, array $params, string $geo, array $me): array
{
    $hasCreativeInfo = (bool)$db->query("SELECT to_regclass('public.creative_info')")->fetchColumn();
    $stats3 = fetchBuilder2CreativeWindowStats($db, $bmInSql, $params, $geo, builder2DateWindow($me, 3), $hasCreativeInfo);
    $stats30 = fetchBuilder2CreativeWindowStats($db, $bmInSql, $params, $geo, builder2DateWindow($me, 30), $hasCreativeInfo);
    $rankMap = rankCreativeGeoStats($stats3, $stats30);
    $catalogRows = $hasCreativeInfo ? fetchBuilder2CatalogCreatives($db, $geo) : [];
    $out = [];
    foreach ($stats30 as $key => $row) {
        $stats = $row['stats'];
        $rank = $rankMap[$key] ?? null;
        $out[] = [
            'creative_name' => (string)$row['name'],
            'ads_count' => (int)$row['ads_count'],
            'bm_count' => (int)$row['bm_count'],
            'last_seen' => (string)$row['last_seen'],
            'launch_date' => (string)($row['launch_date'] ?? ''),
            'spend' => round((float)$stats['spend'], 2),
            'revenue' => round((float)$stats['revenue'], 2),
            'profit' => round((float)$stats['profit'], 2),
            'roi' => round((float)$stats['roi'], 2),
            'regs' => (int)$stats['regs'],
            'deps' => (int)$stats['deps'],
            'rank' => $rank['rank'] ?? null,
            'rank_score' => $rank['score'] ?? null,
            'source' => 'stats',
        ];
    }
    foreach ($catalogRows as $row) {
        $name = (string)$row['creative_name'];
        $key = $geo . '||' . $name;
        if (isset($stats30[$key])) continue;
        $out[] = [
            'creative_name' => $name,
            'ads_count' => 0,
            'bm_count' => 0,
            'last_seen' => '',
            'launch_date' => (string)($row['launch_date'] ?? ''),
            'spend' => 0.0,
            'revenue' => 0.0,
            'profit' => 0.0,
            'roi' => 0.0,
            'regs' => 0,
            'deps' => 0,
            'rank' => null,
            'rank_score' => null,
            'source' => 'catalog',
        ];
    }
    usort($out, static function (array $a, array $b): int {
        $rankA = isset($a['rank']) ? (int)$a['rank'] : PHP_INT_MAX;
        $rankB = isset($b['rank']) ? (int)$b['rank'] : PHP_INT_MAX;
        if ($rankA !== $rankB) return $rankA <=> $rankB;
        return [(float)$b['profit'], (float)$b['spend'], (string)$a['creative_name']]
            <=> [(float)$a['profit'], (float)$a['spend'], (string)$b['creative_name']];
    });
    return array_slice($out, 0, 80);
}

function fetchBuilder2CreativeWindowStats(PDO $db, string $bmInSql, array $params, string $geo, array $window, bool $hasCreativeInfo): array
{
    $launchDateSelect = $hasCreativeInfo ? "MAX(ci.launch_date)::text AS launch_date," : "''::text AS launch_date,";
    $creativeInfoJoin = $hasCreativeInfo ? "LEFT JOIN public.creative_info ci ON ci.creative_name = a.name" : "";
    $stmt = $db->prepare("
        SELECT
            a.name::text AS creative_name,
            COUNT(DISTINCT a.id) AS ads_count,
            COUNT(DISTINCT aa.bm_id) AS bm_count,
            {$launchDateSelect}
            MAX(i.date)::text AS last_seen,
            SUM(i.impressions) AS impressions,
            SUM(i.clicks) AS clicks,
            SUM(i.spend) AS spend,
            SUM(i.delta) AS delta,
            SUM(i.leads) AS leads,
            SUM(i.regs) AS regs,
            SUM(i.deps) AS deps,
            SUM(i.revenue) AS revenue
        FROM public.insights_daily i
        JOIN public.ads a ON a.id = i.ad_id
        JOIN public.campaigns c ON c.id = a.campaign_id
        JOIN public.ad_sets s ON s.id = a.ad_set_id
        JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
        {$creativeInfoJoin}
        WHERE aa.bm_id::text IN {$bmInSql}
          AND campaign_geo(c.name) = :geo
          AND i.date >= :date_from
          AND i.date <= :date_to
          AND COALESCE(c.status, '') != 'DELETED'
          AND COALESCE(s.status, '') != 'DELETED'
          AND COALESCE(a.status, '') != 'DELETED'
          AND a.name IS NOT NULL
          AND a.name <> ''
        GROUP BY a.name
    ");
    $stmt->execute($params + [
        ':geo' => $geo,
        ':date_from' => $window['from'],
        ':date_to' => $window['to'],
    ]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $stats = creativeGeoFinalizeStats([
            'spend' => (float)$row['spend'],
            'delta' => (float)$row['delta'],
            'impressions' => (int)$row['impressions'],
            'clicks' => (int)$row['clicks'],
            'leads' => (int)$row['leads'],
            'regs' => (int)$row['regs'],
            'deps' => (int)$row['deps'],
            'revenue' => (float)$row['revenue'],
        ]);
        $name = (string)$row['creative_name'];
        $out[$geo . '||' . $name] = [
            'geo' => $geo,
            'name' => $name,
            'ads_count' => (int)$row['ads_count'],
            'bm_count' => (int)$row['bm_count'],
            'launch_date' => (string)($row['launch_date'] ?? ''),
            'last_seen' => (string)$row['last_seen'],
            'stats' => $stats,
        ];
    }
    return $out;
}

function fetchBuilder2CatalogCreatives(PDO $db, string $geo): array
{
    if (!$db->query("SELECT to_regclass('public.creative_info')")->fetchColumn()) return [];
    $stmt = $db->prepare("
        SELECT creative_name, launch_date::text AS launch_date
        FROM public.creative_info
        WHERE LOWER(creative_name) LIKE LOWER(:prefix)
        ORDER BY created_at DESC, creative_name ASC
        LIMIT 80
    ");
    $stmt->execute([':prefix' => strtolower($geo) . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function createBuilder2Tasks(PDO $db, array $me, array $allowedBmIds, string $bmInSql, array $bmParams, array $body): never
{
    $geo = strtoupper(trim((string)($body['geo'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $geo)) apiError(400, 'geo must be 2 letters');
    $accountIds = normalizeBuilder2StringList($body['account_ids'] ?? []);
    $creativeNames = normalizeBuilder2StringList($body['creative_names'] ?? []);
    if (!$accountIds) apiError(400, 'Select at least one account');
    if (!$creativeNames) apiError(400, 'Select at least one creative');

    $inventory = fetchBuilder2Inventory($db, $me, $allowedBmIds, $bmInSql, $bmParams, $geo);
    $rowsByAccount = [];
    foreach ($inventory['rows'] as $row) {
        $rowsByAccount[(string)$row['account_id']] = $row;
    }

    $payloadSettings = [
        'adsets_num' => clampBuilder2Int($body['adsets_num'] ?? 1, 1, 50),
        'ads_num' => clampBuilder2Int($body['ads_num'] ?? 1, 1, 50),
        'daily_budget' => moneyBuilder2Number($body['daily_budget'] ?? null),
        'bid_amount' => moneyBuilder2Number($body['bid_amount'] ?? null),
        'bid_strategy_mode' => normalizeBuilder2BidStrategy((string)($body['bid_strategy_mode'] ?? 'bidcap')),
        'random_bid_cap' => !empty($body['random_bid_cap']),
        'bid_spread_pct' => clampBuilder2Int($body['bid_spread_pct'] ?? 20, 0, 100),
        'use_languages' => !empty($body['use_languages']),
        'use_target_geos' => !empty($body['use_target_geos']),
        'no_text' => !empty($body['no_text']),
        'approach' => trim((string)($body['approach'] ?? 'rtp98')) ?: 'rtp98',
        'text_geo' => strtoupper(trim((string)($body['text_geo'] ?? ''))),
        'url_params' => trim((string)($body['url_params'] ?? CAMPAIGN_BUILDER2_DEFAULT_URL_PARAMS)) ?: CAMPAIGN_BUILDER2_DEFAULT_URL_PARAMS,
        'pixel_mode' => strtolower(trim((string)($body['pixel_mode'] ?? 'auto'))) === 'manual' ? 'manual' : 'auto',
        'dest_url' => normalizeBuilder2Url((string)($body['dest_url'] ?? '')),
        'page_id' => trim((string)($body['page_id'] ?? '')),
        'pixel_id' => trim((string)($body['pixel_id'] ?? '')),
    ];
    if ($payloadSettings['daily_budget'] === null || $payloadSettings['daily_budget'] <= 0) apiError(400, 'daily_budget required');
    if ($payloadSettings['bid_strategy_mode'] !== 'auto' && ($payloadSettings['bid_amount'] === null || $payloadSettings['bid_amount'] <= 0)) {
        apiError(400, 'bid_amount required unless strategy is auto');
    }
    if ($payloadSettings['dest_url'] === '' || !filter_var($payloadSettings['dest_url'], FILTER_VALIDATE_URL)) apiError(400, 'dest_url required');
    if ($payloadSettings['page_id'] === '') apiError(400, 'page_id required');
    if ($payloadSettings['pixel_mode'] === 'manual' && $payloadSettings['pixel_id'] === '') apiError(400, 'pixel_id required for manual pixel mode');

    $insert = $db->prepare("
        INSERT INTO public.tasks
            (task_type, status, priority, bm_id, account_id, payload, created_by, max_attempts)
        VALUES
            ('create_campaign', 'pending', :priority, :bm_id, :account_id, CAST(:payload AS jsonb), :created_by, 3)
        RETURNING *
    ");
    $createdBy = 'dashboard:' . (string)($me['username'] ?? $me['id'] ?? 'user');
    $created = [];
    $skipped = [];
    $usedFpIds = [];

    $db->beginTransaction();
    try {
        foreach ($accountIds as $accountId) {
            $row = $rowsByAccount[$accountId] ?? null;
            if (!$row || empty($row['ready'])) {
                $skipped[] = ['account_id' => $accountId, 'reason' => $row['block_reason'] ?? 'Account is not ready'];
                continue;
            }
            $payload = $payloadSettings + [
                'geo' => $geo,
                'dest_url' => $payloadSettings['dest_url'],
                'page_id' => $payloadSettings['page_id'],
                'pixel_id' => $payloadSettings['pixel_mode'] === 'manual' ? $payloadSettings['pixel_id'] : null,
                'bm_id' => (string)$row['bm_id'],
                'bm_label' => (string)$row['bm_id'],
                'bm_name' => (string)$row['bm_name'],
                'account_id' => $accountId,
                'account_name' => (string)$row['account_name'],
                'fbtool_id' => (string)$row['fbtool_id'],
                'chosen_videos' => array_map(static fn(string $name): array => ['id' => $name, 'title' => $name, 'filename' => $name], $creativeNames),
                'manual' => true,
                'source' => 'dashboard_campaign_builder2',
            ];
            $insert->execute([
                ':priority' => 220,
                ':bm_id' => (string)$row['bm_id'],
                ':account_id' => $accountId,
                ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by' => $createdBy,
            ]);
            $task = $insert->fetch(PDO::FETCH_ASSOC);
            GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $task, ['reason' => 'Dashboard Campaign Builder 2 task']);
            $created[] = [
                'id' => (int)$task['id'],
                'bm_id' => (string)$task['bm_id'],
                'account_id' => (string)$task['account_id'],
                'status' => (string)$task['status'],
                'created_at' => (string)$task['created_at'],
            ];
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError(500, 'Task creation failed: ' . $e->getMessage());
    }

    if (!$created) apiError(409, 'No ready accounts were queued');
    apiOk(['created' => $created, 'skipped' => $skipped], ['count' => count($created)]);
}

function ensureBuilder2TasksSchema(PDO $db): void
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
            ad_id TEXT,
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
            finished_at TIMESTAMPTZ
        );
        ALTER TABLE IF EXISTS public.tasks ADD COLUMN IF NOT EXISTS ad_id TEXT;
        ALTER TABLE IF EXISTS public.tasks DROP CONSTRAINT IF EXISTS tasks_type_chk;
        ALTER TABLE IF EXISTS public.tasks ADD CONSTRAINT tasks_type_chk CHECK (task_type IN (
            'set_campaign_status',
            'set_adset_status',
            'set_ad_status',
            'delete_campaign',
            'update_campaign_budget',
            'update_adset_budget',
            'update_adset_bid',
            'create_campaign'
        ));
        ALTER TABLE IF EXISTS public.tasks DROP CONSTRAINT IF EXISTS tasks_status_chk;
        ALTER TABLE IF EXISTS public.tasks ADD CONSTRAINT tasks_status_chk CHECK (status IN ('pending', 'running', 'done', 'failed', 'cancelled'));
        CREATE INDEX IF NOT EXISTS idx_tasks_poll ON public.tasks (status, run_after, priority DESC, created_at);
        CREATE INDEX IF NOT EXISTS idx_tasks_targets ON public.tasks (bm_id, account_id, campaign_id, adset_id);
        CREATE INDEX IF NOT EXISTS idx_tasks_type_status ON public.tasks (task_type, status, created_at DESC);
    ");
}

function builder2Defaults(string $geo): array
{
    $rule = [];
    if ($geo !== '') {
        $path = __DIR__ . '/../config/geo_rules.json';
        $raw = is_file($path) ? file_get_contents($path) : '';
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json) && is_string($raw)) $json = json_decode(preg_replace('/,\s*([}\]])/', '$1', $raw), true);
        $rules = is_array($json['geos'] ?? null) ? $json['geos'] : [];
        $rule = is_array($rules[$geo] ?? null) ? $rules[$geo] : [];
    }
    $targetGeos = [];
    foreach (($rule['target_geos'] ?? []) as $item) {
        $item = strtoupper(trim((string)$item));
        if (preg_match('/^[A-Z]{2}$/', $item) && !in_array($item, $targetGeos, true)) $targetGeos[] = $item;
    }
    return [
        'daily_budget' => isset($rule['budget']) && is_numeric((string)$rule['budget']) ? round((float)$rule['budget'], 2) : 10.0,
        'bid_amount' => isset($rule['bid']) && is_numeric((string)$rule['bid']) ? round((float)$rule['bid'], 2) : 1.0,
        'bid_strategy_mode' => 'bidcap',
        'adsets_num' => 1,
        'ads_num' => 1,
        'text_geo' => strtoupper(trim((string)($rule['text_geo'] ?? ''))),
        'target_geos' => $targetGeos,
        'use_target_geos' => !empty($targetGeos),
        'use_languages' => true,
        'no_text' => true,
        'approach' => 'rtp98',
        'random_bid_cap' => false,
        'bid_spread_pct' => 20,
        'pixel_mode' => 'auto',
        'url_params' => CAMPAIGN_BUILDER2_DEFAULT_URL_PARAMS,
    ];
}

function builder2DateWindow(array $me, int $days): array
{
    $tz = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
    $now = new DateTime('now', appDateTimeZone($tz));
    $from = (clone $now)->modify('-' . max(0, $days - 1) . ' days midnight');
    return ['from' => $from->format('Y-m-d'), 'to' => $now->format('Y-m-d')];
}

function builder2BmInSql(array $bmIds): array
{
    $ph = [];
    $params = [];
    foreach (array_values($bmIds) as $i => $bmId) {
        $key = ":bm_{$i}";
        $ph[] = $key;
        $params[$key] = (string)$bmId;
    }
    return ['(' . implode(',', $ph) . ')', $params];
}

function normalizeBuilder2StringList(mixed $value): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        $item = trim((string)$item);
        if ($item !== '' && !in_array($item, $out, true)) $out[] = $item;
    }
    return $out;
}

function normalizeBuilder2Url(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    return $url;
}

function clampBuilder2Int(mixed $value, int $min, int $max): int
{
    return max($min, min($max, (int)$value));
}

function moneyBuilder2Number(mixed $value): ?float
{
    if ($value === null || $value === '') return null;
    if (!is_numeric((string)$value)) return null;
    return round((float)$value, 2);
}

function normalizeBuilder2BidStrategy(string $mode): string
{
    $mode = strtolower(trim($mode));
    return match ($mode) {
        'bidcap', 'bid_cap', 'bid cap' => 'bidcap',
        'costcap', 'cost_cap', 'cost cap' => 'costcap',
        'auto', 'lowest_cost', 'lowest cost' => 'auto',
        default => 'bidcap',
    };
}
