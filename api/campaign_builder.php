<?php
// api/campaign_builder.php
// @version 1.0.12
// Session-auth API for the New Campaign task generator.

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CreativeGeoRank.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';

const CAMPAIGN_BUILDER_DEFAULT_URL_PARAMS = 'sub_id_1={{ad.id}}&sub_id_2={{campaign.id}}&sub_id_3=14886&sub_id_4={{campaign.name}}&sub_id_5={{adset.id}}&sub_id_6={{adset.name}}&sub_id_7={{ad.name}}&sub_id_8={{placement}}&pixel={pixel}';

$bmIds = array_values(array_filter(array_map('strval', $auth->allowedBmIds($me))));
if (!$bmIds) {
    apiOk(['rows' => [], 'geo' => null, 'defaults' => [], 'creatives' => [], 'fps' => [], 'accounts' => []]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string)($_GET['action'] ?? 'summary')));
$body = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) apiError(400, 'Invalid JSON body');
    $action = strtolower(trim((string)($body['action'] ?? $action ?: 'create')));
}

if (!($method === 'GET' && $action === 'summary')) {
    ensureCampaignBuilderTasksSchema($db);
    GlobalLogger::ensureSchema($db);
    ensureDomainsFpSchema($db);
}

[$bmInSql, $bmParams] = builderBmInSql($bmIds);

if ($method === 'GET' && $action === 'summary') {
    apiOk(['rows' => fetchGeoSummaryRows($db, $bmInSql, $bmParams, $me)]);
}

if ($method === 'GET' && $action === 'geo') {
    $geo = strtoupper(trim((string)($_GET['geo'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $geo)) apiError(400, 'geo must be 2 letters');

    $defaults = geoRuleDefaults($geo);
    $creatives = fetchGeoCreatives($db, $bmInSql, $bmParams, $geo, $me);
    $fps = fetchGeoFps($db, $me, $bmIds, $geo);

    apiOk([
        'geo' => $geo,
        'defaults' => $defaults,
        'creatives' => $creatives,
        'fps' => $fps,
    ], [
        'creatives_count' => count($creatives),
        'fps_count' => count($fps),
    ]);
}

if ($method === 'GET' && in_array($action, ['fp', 'config'], true)) {
    $fpId = (int)($_GET['fp_id'] ?? $_GET['config_id'] ?? 0);
    if ($fpId <= 0) apiError(400, 'fp_id required');
    $fp = fetchFpById($db, $me, $bmIds, $fpId);
    if (!$fp) apiError(404, 'Fan page not found');
    $accounts = fetchBmEligibleAccounts($db, (string)$fp['bm_id'], (string)$fp['geo']);
    apiOk([
        'fp' => $fp,
        'accounts' => $accounts,
    ], [
        'accounts_count' => count($accounts),
    ]);
}

if ($method === 'POST' && $action === 'create') {
    createCampaignBuilderTasks($db, $me, $bmIds, $body);
}

apiError(404, 'Unknown action');

function ensureCampaignBuilderTasksSchema(PDO $db): void
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
        ALTER TABLE IF EXISTS public.tasks
            DROP CONSTRAINT IF EXISTS tasks_type_chk;
        ALTER TABLE IF EXISTS public.tasks
            ADD CONSTRAINT tasks_type_chk CHECK (task_type IN (
                'set_campaign_status',
                'set_adset_status',
                'set_ad_status',
                'delete_campaign',
                'update_campaign_budget',
                'update_adset_budget',
                'update_adset_bid',
                'create_campaign'
            ));
        ALTER TABLE IF EXISTS public.tasks
            DROP CONSTRAINT IF EXISTS tasks_status_chk;
        ALTER TABLE IF EXISTS public.tasks
            ADD CONSTRAINT tasks_status_chk CHECK (status IN (
                'pending', 'running', 'done', 'failed', 'cancelled'
            ));
        CREATE INDEX IF NOT EXISTS idx_tasks_poll
            ON public.tasks (status, run_after, priority DESC, created_at);
        CREATE INDEX IF NOT EXISTS idx_tasks_targets
            ON public.tasks (bm_id, account_id, campaign_id, adset_id);
        CREATE INDEX IF NOT EXISTS idx_tasks_type_status
            ON public.tasks (task_type, status, created_at DESC);
    ");
}

function ensureDomainsFpSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.domains_fp (
            id          bigserial       PRIMARY KEY,
            user_id     int             REFERENCES public.users(id) ON DELETE SET NULL,
            bm          varchar(20)     NOT NULL,
            geo         char(2)         NOT NULL,
            domain      varchar(255)    NOT NULL DEFAULT '',
            fp_name     varchar(255)    NOT NULL DEFAULT '',
            page_id     varchar(255)    NOT NULL DEFAULT '',
            pixel_id    varchar(255)    NOT NULL DEFAULT '',
            used_geos   jsonb           NOT NULL DEFAULT '[]'::jsonb,
            fp_url      varchar(2048)   NOT NULL DEFAULT '',
            status      varchar(10)     NOT NULL DEFAULT 'active',
            created_at  timestamptz     NOT NULL DEFAULT now(),
            updated_at  timestamptz     NOT NULL DEFAULT now()
        )
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS status varchar(10) NOT NULL DEFAULT 'active'
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS user_id int REFERENCES public.users(id) ON DELETE SET NULL
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS page_id varchar(255) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS pixel_id varchar(255) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS used_geos jsonb NOT NULL DEFAULT '[]'::jsonb
    ");
    $db->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                WHERE conname = 'domains_fp_status_chk'
            ) THEN
                ALTER TABLE public.domains_fp
                    ADD CONSTRAINT domains_fp_status_chk
                    CHECK (status IN ('active', 'banned'));
            END IF;
        END $$;
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dfp_bm     ON public.domains_fp (bm)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dfp_geo    ON public.domains_fp (geo)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dfp_bm_geo ON public.domains_fp (bm, geo)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dfp_status ON public.domains_fp (status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dfp_user   ON public.domains_fp (user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dfp_used_geos ON public.domains_fp USING GIN (used_geos)");
}

function builderBmInSql(array $bmIds): array
{
    $ph = [];
    $params = [];
    foreach ($bmIds as $i => $bmId) {
        $key = ":bm_{$i}";
        $ph[] = $key;
        $params[$key] = (string)$bmId;
    }
    return ['(' . implode(',', $ph) . ')', $params];
}

function fetchGeoSummaryRows(PDO $db, string $bmInSql, array $params, array $me): array
{
    $window = builderDateWindow($me, 7);
    $creativeWindow = builderDateWindow($me, 30);
    $sql = "
        WITH campaign_stats AS (
            SELECT
                a.campaign_id::text AS campaign_id,
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
            JOIN public.ad_sets s ON s.id = a.ad_set_id
            JOIN public.campaigns c ON c.id = a.campaign_id
            LEFT JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
            WHERE (aa.bm_id IN {$bmInSql} OR aa.bm_id IS NULL)
              AND i.date >= :date_from
              AND i.date <= :date_to
              AND COALESCE(a.status, '') != 'DELETED'
              AND COALESCE(s.status, '') != 'DELETED'
              AND a.campaign_id IS NOT NULL
            GROUP BY a.campaign_id
        ),
        base AS (
            SELECT
                campaign_geo(c.name) AS geo,
                c.id::text AS campaign_id,
                aa.status AS account_status,
                CASE
                    WHEN COALESCE(aa.status, 1) != 1 THEN 0
                    WHEN COALESCE(c.status, '') IN ('MANUAL_STOP', 'ARCHIVED', 'DELETED') THEN 0
                    WHEN COALESCE(c.effective_status, '') IN ('MANUAL_STOP', 'ARCHIVED', 'DELETED') THEN 0
                    WHEN COALESCE(c.effective_status, '') <> '' AND COALESCE(c.effective_status, '') <> 'ACTIVE' THEN 0
                    WHEN COALESCE(c.status, '') <> '' AND COALESCE(c.status, '') <> 'ACTIVE' THEN 0
                    WHEN COALESCE(c.effective_status, '') = 'ACTIVE' OR COALESCE(c.status, '') = 'ACTIVE' THEN 1
                    ELSE 0
                END AS is_active,
                COALESCE(cs.impressions, 0) AS impressions,
                COALESCE(cs.clicks, 0) AS clicks,
                COALESCE(cs.spend, 0) AS spend,
                COALESCE(cs.delta, 0) AS delta,
                COALESCE(cs.leads, 0) AS leads,
                COALESCE(cs.regs, 0) AS regs,
                COALESCE(cs.deps, 0) AS deps,
                COALESCE(cs.revenue, 0) AS revenue
            FROM public.campaigns c
            JOIN public.ad_accounts aa ON aa.id = c.ad_account_id
            JOIN public.business_managers bm ON bm.id = aa.bm_id
            LEFT JOIN campaign_stats cs ON cs.campaign_id = c.id::text
            WHERE aa.bm_id IN {$bmInSql}
        ),
        active_creatives AS (
            SELECT
                campaign_geo(c.name) AS geo,
                a.name::text AS creative_name,
                a.id AS ad_id
            FROM public.ads a
            JOIN public.campaigns c ON c.id = a.campaign_id
            JOIN public.ad_sets s ON s.id = a.ad_set_id
            JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
            WHERE aa.bm_id IN {$bmInSql}
              AND COALESCE(c.status, '') != 'DELETED'
              AND COALESCE(s.status, '') != 'DELETED'
              AND COALESCE(a.status, '') != 'DELETED'
              AND a.name IS NOT NULL
              AND a.name <> ''
        ),
        creative_totals AS (
            SELECT geo, COUNT(DISTINCT creative_name) AS creatives_count
            FROM active_creatives
            WHERE geo ~ '^[A-Z]{2}$'
            GROUP BY geo
        ),
        creative_stats_30d AS (
            SELECT
                ac.geo,
                ac.creative_name,
                COALESCE(SUM(i.spend), 0) AS spend,
                COALESCE(SUM(i.revenue), 0) AS revenue
            FROM active_creatives ac
            JOIN public.insights_daily i ON i.ad_id = ac.ad_id
            WHERE i.date >= :creative_date_from
              AND i.date <= :creative_date_to
              AND ac.geo ~ '^[A-Z]{2}$'
            GROUP BY ac.geo, ac.creative_name
        ),
        successful_creatives AS (
            SELECT
                geo,
                COUNT(*) FILTER (
                    WHERE spend > 0
                      AND ((revenue - spend) / spend * 100.0) > 30
                ) AS successful_creatives_count
            FROM creative_stats_30d
            GROUP BY geo
        ),
        geo_summary AS (
            SELECT
                geo,
                COUNT(DISTINCT CASE WHEN COALESCE(account_status, 1) = 1 THEN campaign_id END) AS campaigns_total,
                COUNT(DISTINCT CASE WHEN is_active = 1 THEN campaign_id END) AS campaigns_active,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(spend) AS spend,
                SUM(delta) AS delta,
                SUM(leads) AS leads,
                SUM(regs) AS regs,
                SUM(deps) AS deps,
                SUM(revenue) AS revenue
            FROM base
            WHERE geo ~ '^[A-Z]{2}$'
            GROUP BY geo
        )
        SELECT
            gs.*,
            COALESCE(ct.creatives_count, 0) AS creatives_count,
            COALESCE(sc.successful_creatives_count, 0) AS successful_creatives_count
        FROM geo_summary gs
        LEFT JOIN creative_totals ct ON ct.geo = gs.geo
        LEFT JOIN successful_creatives sc ON sc.geo = gs.geo
        ORDER BY (COALESCE(gs.revenue, 0) - COALESCE(gs.spend, 0)) DESC, gs.geo ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params + [
        ':date_from' => $window['from'],
        ':date_to' => $window['to'],
        ':creative_date_from' => $creativeWindow['from'],
        ':creative_date_to' => $creativeWindow['to'],
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function (array $row): array {
        $spend = (float)$row['spend'];
        $revenue = (float)$row['revenue'];
        $profit = $revenue - $spend;
        $clicks = (int)$row['clicks'];
        $leads = (int)$row['leads'];
        return [
            'geo' => (string)$row['geo'],
            'campaigns_total' => (int)$row['campaigns_total'],
            'campaigns_active' => (int)$row['campaigns_active'],
            'creatives_count' => (int)$row['creatives_count'],
            'successful_creatives_count' => (int)$row['successful_creatives_count'],
            'impressions' => (int)$row['impressions'],
            'clicks' => $clicks,
            'spend' => round($spend, 2),
            'delta' => round((float)$row['delta'], 2),
            'leads' => $leads,
            'regs' => (int)$row['regs'],
            'deps' => (int)$row['deps'],
            'revenue' => round($revenue, 2),
            'profit' => round($profit, 2),
            'roi' => $spend > 0 ? round($profit / $spend * 100, 2) : 0.0,
            'c2l' => $clicks > 0 ? round($leads / $clicks * 100, 2) : 0.0,
        ];
    }, $rows);
}

function fetchGeoCreatives(PDO $db, string $bmInSql, array $params, string $geo, array $me): array
{
    $stats3 = fetchGeoCreativeWindowStats($db, $bmInSql, $params, $geo, builderDateWindow($me, 3));
    $stats30 = fetchGeoCreativeWindowStats($db, $bmInSql, $params, $geo, builderDateWindow($me, 30));
    $rankMap = rankCreativeGeoStats($stats3, $stats30);
    $catalogRows = fetchCatalogCreativeRows($db, $geo);

    $out = [];
    foreach ($stats30 as $key => $row) {
        $rank = $rankMap[$key] ?? null;
        $stats = $row['stats'];
        $out[] = [
            'creative_name' => (string)$row['name'],
            'ads_count' => (int)$row['ads_count'],
            'last_seen' => (string)$row['last_seen'],
            'launch_date' => (string)($row['launch_date'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'impressions' => (int)$stats['impressions'],
            'clicks' => (int)$stats['clicks'],
            'spend' => round((float)$stats['spend'], 2),
            'delta' => round((float)$stats['delta'], 2),
            'leads' => (int)$stats['leads'],
            'regs' => (int)$stats['regs'],
            'deps' => (int)$stats['deps'],
            'revenue' => round((float)$stats['revenue'], 2),
            'profit' => round((float)$stats['profit'], 2),
            'roi' => round((float)$stats['roi'], 2),
            'c2l' => ((int)$stats['clicks']) > 0 ? round((float)$stats['leads'] / (int)$stats['clicks'] * 100, 2) : 0.0,
            'rank' => $rank['rank'] ?? null,
            'rank_score' => $rank['score'] ?? null,
        ];
    }
    foreach ($catalogRows as $row) {
        $name = (string)$row['creative_name'];
        $key = $geo . '||' . $name;
        if (isset($stats30[$key])) {
            continue;
        }
        $out[] = [
            'creative_name' => $name,
            'ads_count' => 0,
            'last_seen' => '',
            'launch_date' => (string)($row['launch_date'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'impressions' => 0,
            'clicks' => 0,
            'spend' => 0.0,
            'delta' => 0.0,
            'leads' => 0,
            'regs' => 0,
            'deps' => 0,
            'revenue' => 0.0,
            'profit' => 0.0,
            'roi' => 0.0,
            'c2l' => 0.0,
            'rank' => null,
            'rank_score' => null,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $rankA = isset($a['rank']) ? (int)$a['rank'] : PHP_INT_MAX;
        $rankB = isset($b['rank']) ? (int)$b['rank'] : PHP_INT_MAX;
        if ($rankA !== $rankB) return $rankA <=> $rankB;
        return [$b['profit'], $b['spend'], $b['revenue'], $a['creative_name']]
            <=> [$a['profit'], $a['spend'], $a['revenue'], $b['creative_name']];
    });
    return $out;
}

function fetchCatalogCreativeRows(PDO $db, string $geo): array
{
    if (!$db->query("SELECT to_regclass('public.creative_info')")->fetchColumn()) {
        return [];
    }
    $stmt = $db->prepare("
        SELECT
            ci.creative_name,
            ci.launch_date::text AS launch_date,
            ci.created_at::text AS created_at
        FROM public.creative_info ci
        WHERE LOWER(ci.creative_name) LIKE LOWER(:creative_prefix)
        ORDER BY ci.created_at DESC, ci.creative_name ASC
    ");
    $stmt->execute([':creative_prefix' => strtolower($geo) . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function builderDateWindow(array $me, int $days): array
{
    $tz = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
    $tzObj = appDateTimeZone($tz);
    $now = new DateTime('now', $tzObj);
    $from = (clone $now)->modify('-' . max(0, $days - 1) . ' days midnight');
    return [
        'from' => $from->format('Y-m-d'),
        'to' => $now->format('Y-m-d'),
    ];
}

function fetchGeoCreativeWindowStats(PDO $db, string $bmInSql, array $params, string $geo, array $window): array
{
    $sql = "
        WITH base AS (
            SELECT
                a.name::text AS creative_name,
                COUNT(DISTINCT a.id) AS ads_count,
                MAX(ci.launch_date)::text AS launch_date,
                MAX(ci.created_at)::text AS created_at,
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
            JOIN public.ad_sets s ON s.id = a.ad_set_id
            JOIN public.campaigns c ON c.id = a.campaign_id
            JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
            LEFT JOIN public.creative_info ci ON ci.creative_name = a.name
            WHERE aa.bm_id IN {$bmInSql}
              AND i.date >= :date_from
              AND i.date <= :date_to
              AND campaign_geo(c.name) = :geo
              AND COALESCE(a.status, '') != 'DELETED'
              AND a.name IS NOT NULL
              AND a.name <> ''
            GROUP BY a.name
        )
        SELECT *
        FROM base
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params + [
        ':geo' => $geo,
        ':date_from' => $window['from'],
        ':date_to' => $window['to'],
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
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
        $key = $geo . '||' . $name;
        $out[$key] = [
            'geo' => $geo,
            'name' => $name,
            'ads_count' => (int)$row['ads_count'],
            'launch_date' => (string)($row['launch_date'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'last_seen' => (string)$row['last_seen'],
            'stats' => $stats,
        ];
    }
    return $out;
}

function fetchGeoAvailableAccounts(PDO $db, string $bmInSql, array $params, string $geo): array
{
    $sql = "
        SELECT
            aa.id::text AS account_id,
            aa.name AS account_name,
            aa.status AS account_status,
            bm.id::text AS bm_id,
            bm.name AS bm_name,
            COALESCE(fta.fbtool_id, '') AS fbtool_id,
            CASE
                WHEN aa.status <> 1 THEN 0
                WHEN bm.is_active IS DISTINCT FROM TRUE THEN 0
                WHEN COALESCE(fta.fbtool_id, '') = '' THEN 0
                ELSE 1
            END AS eligible,
            CASE
                WHEN aa.status <> 1 THEN 'Account inactive'
                WHEN bm.is_active IS DISTINCT FROM TRUE THEN 'BM inactive'
                WHEN COALESCE(fta.fbtool_id, '') = '' THEN 'BM not synced'
                ELSE ''
            END AS eligibility_reason
        FROM public.ad_accounts aa
        JOIN public.business_managers bm ON bm.id = aa.bm_id
        LEFT JOIN public.fbtool_accounts fta ON fta.id = bm.fbtool_account_id
        WHERE aa.bm_id IN {$bmInSql}
        ORDER BY eligible DESC, bm.name ASC, aa.name ASC, aa.id ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map(static function (array $row): array {
        return [
            'account_id' => (string)$row['account_id'],
            'account_name' => (string)$row['account_name'],
            'account_status' => (int)$row['account_status'],
            'bm_id' => (string)$row['bm_id'],
            'bm_name' => (string)$row['bm_name'],
            'fbtool_id' => (string)$row['fbtool_id'],
            'eligible' => (bool)$row['eligible'],
            'eligibility_reason' => (string)$row['eligibility_reason'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function fetchBmEligibleAccounts(PDO $db, string $bmId, string $geo): array
{
    $stmt = $db->prepare("
        SELECT
            aa.id::text AS account_id,
            aa.name AS account_name,
            aa.status AS account_status,
            bm.id::text AS bm_id,
            bm.name AS bm_name,
            COALESCE(fta.fbtool_id, '') AS fbtool_id,
            CASE
                WHEN aa.status <> 1 THEN 0
                WHEN bm.is_active IS DISTINCT FROM TRUE THEN 0
                WHEN COALESCE(fta.fbtool_id, '') = '' THEN 0
                ELSE 1
            END AS eligible,
            CASE
                WHEN aa.status <> 1 THEN 'Account inactive'
                WHEN bm.is_active IS DISTINCT FROM TRUE THEN 'BM inactive'
                WHEN COALESCE(fta.fbtool_id, '') = '' THEN 'BM not synced'
                ELSE ''
            END AS eligibility_reason
        FROM public.ad_accounts aa
        JOIN public.business_managers bm ON bm.id = aa.bm_id
        LEFT JOIN public.fbtool_accounts fta ON fta.id = bm.fbtool_account_id
        WHERE aa.bm_id::text = :bm_id
        ORDER BY eligible DESC, aa.name ASC, aa.id ASC
    ");
    $stmt->execute([':bm_id' => $bmId]);
    return array_map(static function (array $row): array {
        return [
            'account_id' => (string)$row['account_id'],
            'account_name' => (string)$row['account_name'],
            'account_status' => (int)$row['account_status'],
            'bm_id' => (string)$row['bm_id'],
            'bm_name' => (string)$row['bm_name'],
            'fbtool_id' => (string)$row['fbtool_id'],
            'eligible' => (bool)$row['eligible'],
            'eligibility_reason' => (string)$row['eligibility_reason'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function fetchGeoFps(PDO $db, array $me, array $allowedBmIds, string $geo): array
{
    $params = [':geo' => $geo];
    $where = [];
    if (($me['role'] ?? '') !== 'admin') {
        $where[] = 'd.user_id = :user_id';
        $params[':user_id'] = (int)$me['id'];
    }

    if (!$allowedBmIds) return [];

    $stmt = $db->prepare("
        SELECT d.id, d.bm, d.geo, d.used_geos, d.domain, d.fp_name, d.page_id, d.pixel_id,
               bm.id::text AS bm_id,
               bm.name AS bm_name,
               COALESCE(fta.fbtool_id, '') AS fbtool_id,
               d.status AS fp_status,
               CASE
                   WHEN d.geo = :geo THEN 1
                   WHEN COALESCE(d.used_geos, '[]'::jsonb) ? :geo THEN 1
                   ELSE 0
               END AS geo_match
        FROM public.domains_fp d
        LEFT JOIN public.business_managers bm ON bm.id::text = d.bm
        LEFT JOIN public.fbtool_accounts fta ON fta.id = bm.fbtool_account_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY geo_match DESC, COALESCE(bm.name, d.bm), d.id
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    $accountsCache = [];
    foreach ($rows as $row) {
        $bmId = trim((string)($row['bm_id'] ?? ''));
        $bmName = trim((string)($row['bm_name'] ?? ''));
        if ($bmId === '') {
            $legacyBm = legacyBuilderBmLookup($db, $allowedBmIds, (string)($row['bm'] ?? ''));
            if (!$legacyBm) continue;
            $bmId = (string)$legacyBm['bm_id'];
            $bmName = (string)$legacyBm['bm_name'];
        }
        $cacheKey = $bmId . '|' . $geo;
        if (!array_key_exists($cacheKey, $accountsCache)) {
            $accountsCache[$cacheKey] = fetchBmEligibleAccounts($db, $bmId, $geo);
        }
        $accounts = $accountsCache[$cacheKey];
        $accountsCount = count($accounts);
        $bmLabel = $bmName !== '' ? $bmName : $bmId;
        $out[] = [
            'id' => (int)$row['id'],
            'bm_id' => $bmId,
            'bm_name' => $bmName,
            'geo' => (string)$row['geo'],
            'used_geos' => decodeUsedGeos($row['used_geos'] ?? '[]'),
            'fp_status' => (string)($row['fp_status'] ?? 'active'),
            'eligible' => (bool)($row['geo_match'] ?? 0) && (string)($row['fp_status'] ?? 'active') === 'active',
            'eligibility_reason' => (bool)($row['geo_match'] ?? 0)
                ? ((string)($row['fp_status'] ?? 'active') === 'active' ? '' : 'FP is not active')
                : 'FP geo does not match',
            'domain' => (string)$row['domain'],
            'fp_name' => (string)$row['fp_name'],
            'page_id' => (string)($row['page_id'] ?? ''),
            'pixel_id' => (string)($row['pixel_id'] ?? ''),
            'title' => $bmLabel . ' | ' . $row['geo'] . ' | ' . $row['domain'] . ' | ' . $row['fp_name']
                . (($used = decodeUsedGeos($row['used_geos'] ?? '[]')) ? ' | Used: ' . implode(', ', $used) : '')
                . ((string)($row['page_id'] ?? '') !== '' ? ' | Page ' . $row['page_id'] : '')
                . ((string)($row['pixel_id'] ?? '') !== '' ? ' | Pixel ' . $row['pixel_id'] : ''),
            'geo_match' => (int)($row['geo_match'] ?? 0) === 1,
            'accounts_count' => $accountsCount,
            'fbtool_id' => (string)($row['fbtool_id'] ?? ''),
        ];
    }
    return $out;
}

function fetchFpById(PDO $db, array $me, array $allowedBmIds, int $fpId): ?array
{
    if (!$allowedBmIds) return null;

    $ph = [];
    $params = [':id' => $fpId, ':status' => 'active'];
    foreach (array_values($allowedBmIds) as $i => $bmId) {
        $key = ":bm_{$i}";
        $ph[] = $key;
        $params[$key] = (string)$bmId;
    }

    $where = ['d.id = :id', 'd.status = :status', 'd.bm IN (' . implode(',', $ph) . ')'];
    if (($me['role'] ?? '') !== 'admin') {
        $where[] = 'd.user_id = :user_id';
        $params[':user_id'] = (int)$me['id'];
    }

    $stmt = $db->prepare("
        SELECT d.id, d.bm, d.geo, d.used_geos, d.domain, d.fp_name, d.page_id, d.pixel_id,
               bm.id::text AS bm_id,
               bm.name AS bm_name,
               COALESCE(fta.fbtool_id, '') AS fbtool_id
        FROM public.domains_fp d
        LEFT JOIN public.business_managers bm ON bm.id::text = d.bm
        LEFT JOIN public.fbtool_accounts fta ON fta.id = bm.fbtool_account_id
        WHERE " . implode(' AND ', $where) . "
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $bmId = trim((string)($row['bm_id'] ?? ''));
    $bmName = trim((string)($row['bm_name'] ?? ''));
    if ($bmId === '') {
        $legacyBm = legacyBuilderBmLookup($db, $allowedBmIds, (string)($row['bm'] ?? ''));
        if (!$legacyBm) return null;
        $bmId = (string)$legacyBm['bm_id'];
        $bmName = (string)$legacyBm['bm_name'];
    }

    $bmLabel = $bmName !== '' ? $bmName : $bmId;
    return [
        'id' => (int)$row['id'],
        'bm_id' => $bmId,
        'bm_name' => $bmName,
        'geo' => (string)$row['geo'],
        'used_geos' => decodeUsedGeos($row['used_geos'] ?? '[]'),
        'domain' => (string)$row['domain'],
        'fp_name' => (string)$row['fp_name'],
        'page_id' => (string)($row['page_id'] ?? ''),
        'pixel_id' => (string)($row['pixel_id'] ?? ''),
        'title' => $bmLabel . ' | ' . $row['geo'] . ' | ' . $row['domain'] . ' | ' . $row['fp_name'],
        'accounts_count' => 0,
        'fbtool_id' => (string)($row['fbtool_id'] ?? ''),
    ];
}

function legacyBuilderBmLookup(PDO $db, array $allowedBmIds, string $legacyBm): ?array
{
    $legacyBm = trim($legacyBm);
    if ($legacyBm === '' || !$allowedBmIds) return null;

    $ph = [];
    $params = [];
    foreach (array_values($allowedBmIds) as $i => $bmId) {
        $key = ":bm_{$i}";
        $ph[] = $key;
        $params[$key] = (string)$bmId;
    }
    $stmt = $db->prepare("
        SELECT
            bm.id::text AS bm_id,
            bm.name AS bm_name
        FROM public.business_managers bm
        WHERE bm.id::text IN (" . implode(',', $ph) . ")
          AND bm.is_active = TRUE
        ORDER BY bm.name ASC, bm.id ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = deriveBmShortNumber((string)$row['bm_name'], (string)$row['bm_id']);
        if ($key === $legacyBm) return $row;
    }
    return null;
}

function geoRuleDefaults(string $geo): array
{
    $path = __DIR__ . '/../config/geo_rules.json';
    $raw = is_file($path) ? file_get_contents($path) : '';
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($json) && is_string($raw)) {
        $json = json_decode(preg_replace('/,\s*([}\]])/', '$1', $raw), true);
    }
    $geoRules = is_array($json['geos'] ?? null) ? $json['geos'] : [];
    $rule = is_array($geoRules[$geo] ?? null) ? $geoRules[$geo] : [];

    $targetGeos = [];
    foreach (($rule['target_geos'] ?? []) as $item) {
        $item = strtoupper(trim((string)$item));
        if (preg_match('/^[A-Z]{2}$/', $item) && !in_array($item, $targetGeos, true)) {
            $targetGeos[] = $item;
        }
    }

    return [
        'daily_budget' => isset($rule['budget']) && is_numeric((string)$rule['budget']) ? round((float)$rule['budget'], 2) : 10.0,
        'bid_amount' => isset($rule['bid']) && is_numeric((string)$rule['bid']) ? round((float)$rule['bid'], 2) : 1.0,
        'bid_strategy_mode' => 'bidcap',
        'text_geo' => strtoupper(trim((string)($rule['text_geo'] ?? ''))),
        'target_geos' => $targetGeos,
        'use_target_geos' => !empty($targetGeos),
        'use_languages' => true,
        'no_text' => true,
        'approach' => 'rtp98',
        'adsets_num' => 1,
        'ads_num' => 1,
        'random_bid_cap' => false,
        'bid_spread_pct' => 20,
        'url_params' => CAMPAIGN_BUILDER_DEFAULT_URL_PARAMS,
    ];
}

function createCampaignBuilderTasks(PDO $db, array $me, array $allowedBmIds, array $body): never
{
    $geo = strtoupper(trim((string)($body['geo'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $geo)) apiError(400, 'geo must be 2 letters');
    $fpId = (int)($body['fp_id'] ?? $body['config_id'] ?? 0);
    if ($fpId <= 0) apiError(400, 'fp_id required');
    $fp = fetchFpById($db, $me, $allowedBmIds, $fpId);
    if (!$fp) apiError(404, 'Fan page not found');

    $accountIds = normalizeStringList($body['account_ids'] ?? []);
    $creativeNames = normalizeStringList($body['creative_names'] ?? []);
    if (!$accountIds) apiError(400, 'Select at least one account');
    if (!$creativeNames) apiError(400, 'Select at least one creative');

    $destUrl = normalizeUrl((string)($fp['domain'] ?? ''));
    if ($destUrl === '' || !filter_var($destUrl, FILTER_VALIDATE_URL)) apiError(400, 'Fan page domain must be a valid URL');

    $payloadBase = [
        'geo' => $geo,
        'adsets_num' => clampInt($body['adsets_num'] ?? 1, 1, 50),
        'ads_num' => clampInt($body['ads_num'] ?? 1, 1, 50),
        'dest_url' => $destUrl,
        'url_params' => trim((string)($body['url_params'] ?? CAMPAIGN_BUILDER_DEFAULT_URL_PARAMS)) ?: CAMPAIGN_BUILDER_DEFAULT_URL_PARAMS,
        'page_id' => trim((string)($body['page_id'] ?? $fp['page_id'] ?? '')) ?: null,
        'pixel_id' => trim((string)($body['pixel_id'] ?? $fp['pixel_id'] ?? '')) ?: null,
        'pixel_mode' => strtolower(trim((string)($body['pixel_mode'] ?? 'auto'))) === 'manual' ? 'manual' : 'auto',
        'fp_id' => $fpId,
        'fp_name' => (string)$fp['fp_name'],
        'fp_label' => (string)$fp['title'],
        'daily_budget' => moneyNumber($body['daily_budget'] ?? null),
        'bid_amount' => moneyNumber($body['bid_amount'] ?? null),
        'bid_strategy_mode' => normalizeBidStrategy((string)($body['bid_strategy_mode'] ?? 'bidcap')),
        'random_bid_cap' => !empty($body['random_bid_cap']),
        'bid_spread_pct' => clampInt($body['bid_spread_pct'] ?? 20, 0, 100),
        'use_languages' => !empty($body['use_languages']),
        'use_target_geos' => !empty($body['use_target_geos']),
        'no_text' => !empty($body['no_text']),
        'approach' => trim((string)($body['approach'] ?? 'rtp98')) ?: 'rtp98',
        'text_geo' => strtoupper(trim((string)($body['text_geo'] ?? ''))),
        'chosen_videos' => array_map(static function (string $name): array {
            return [
                'id' => $name,
                'title' => $name,
                'filename' => $name,
            ];
        }, $creativeNames),
        'manual' => true,
        'source' => 'dashboard_campaign_builder',
    ];

    if ($payloadBase['daily_budget'] === null || $payloadBase['daily_budget'] <= 0) {
        apiError(400, 'daily_budget required');
    }
    if ($payloadBase['bid_strategy_mode'] !== 'auto' && ($payloadBase['bid_amount'] === null || $payloadBase['bid_amount'] <= 0)) {
        apiError(400, 'bid_amount required unless strategy is auto');
    }

    $availableAccounts = fetchAvailableAccountsMapForBm($db, (string)$fp['bm_id'], $geo, $accountIds);
    $createdBy = 'dashboard:' . (string)($me['username'] ?? $me['id'] ?? 'user');
    $priority = 200;
    $insert = $db->prepare("
        INSERT INTO public.tasks
            (task_type, status, priority, bm_id, account_id, payload, created_by, max_attempts)
        VALUES
            ('create_campaign', 'pending', :priority, :bm_id, :account_id, CAST(:payload AS jsonb), :created_by, 3)
        RETURNING *
    ");

    $created = [];
    $skipped = [];
    $db->beginTransaction();
    try {
        foreach ($accountIds as $accountId) {
            $account = $availableAccounts[$accountId] ?? null;
            if (!$account) {
                $skipped[] = $accountId;
                continue;
            }
            $payload = $payloadBase;
            $payload['bm_id'] = (string)$account['bm_id'];
            $payload['account_id'] = $accountId;
            $payload['bm_label'] = (string)$fp['bm_id'];
            $payload['account_name'] = (string)$account['account_name'];
            $payload['bm_name'] = (string)$account['bm_name'];
            $payload['fbtool_id'] = (string)$account['fbtool_id'];
            $insert->execute([
                ':priority' => $priority,
                ':bm_id' => (string)$account['bm_id'],
                ':account_id' => $accountId,
                ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by' => $createdBy,
            ]);
            $row = $insert->fetch(PDO::FETCH_ASSOC);
            GlobalLogger::logTaskEvent($db, 'task_created', 'pending', $row, [
                'reason' => 'Dashboard Campaign Builder task',
            ]);
            $created[] = [
                'id' => $row['id'] ?? null,
                'bm_id' => $row['bm_id'] ?? null,
                'account_id' => $row['account_id'] ?? null,
                'status' => $row['status'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError(500, 'Task creation failed: ' . $e->getMessage());
    }

    if (!$created) apiError(409, 'No eligible accounts left for this fan page');
    markDomainsFpGeoUsage($db, $fpId, $geo);

    apiOk([
        'created' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'bm_id' => (string)$row['bm_id'],
            'account_id' => (string)$row['account_id'],
            'status' => (string)$row['status'],
            'created_at' => (string)$row['created_at'],
        ], $created),
        'skipped_account_ids' => array_values($skipped),
    ], [
        'count' => count($created),
    ]);
}

function decodeUsedGeos(mixed $raw): array
{
    if (is_array($raw)) {
        $values = $raw;
    } else {
        $values = json_decode((string)$raw, true);
        if (!is_array($values)) {
            $values = [];
        }
    }

    $out = [];
    foreach ($values as $value) {
        $geo = strtoupper(trim((string)$value));
        if (preg_match('/^[A-Z]{2}$/', $geo) && !in_array($geo, $out, true)) {
            $out[] = $geo;
        }
    }
    sort($out);
    return $out;
}

function markDomainsFpGeoUsage(PDO $db, int $fpId, string $geo): void
{
    if ($fpId <= 0 || !preg_match('/^[A-Z]{2}$/', $geo)) {
        return;
    }

    $stmt = $db->prepare("SELECT used_geos FROM public.domains_fp WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $fpId]);
    $usedGeos = decodeUsedGeos($stmt->fetchColumn());
    if (in_array($geo, $usedGeos, true)) {
        return;
    }

    $usedGeos[] = $geo;
    sort($usedGeos);
    $update = $db->prepare("
        UPDATE public.domains_fp
        SET used_geos = :used_geos,
            updated_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':id' => $fpId,
        ':used_geos' => json_encode($usedGeos, JSON_UNESCAPED_SLASHES),
    ]);
}

function fetchAvailableAccountsMap(PDO $db, array $allowedBmIds, string $geo, array $accountIds): array
{
    $bmPh = [];
    $params = [];
    foreach (array_values($allowedBmIds) as $i => $bmId) {
        $key = ":bm_{$i}";
        $bmPh[] = $key;
        $params[$key] = (string)$bmId;
    }
    $accPh = [];
    foreach (array_values($accountIds) as $i => $accountId) {
        $key = ":acc_{$i}";
        $accPh[] = $key;
        $params[$key] = $accountId;
    }
    $sql = "
        SELECT
            aa.id::text AS account_id,
            aa.name AS account_name,
            bm.id::text AS bm_id,
            bm.name AS bm_name,
            COALESCE(fta.fbtool_id, '') AS fbtool_id
        FROM public.ad_accounts aa
        JOIN public.business_managers bm ON bm.id = aa.bm_id
        LEFT JOIN public.fbtool_accounts fta ON fta.id = bm.fbtool_account_id
        WHERE aa.bm_id IN (" . implode(',', $bmPh) . ")
          AND aa.id::text IN (" . implode(',', $accPh) . ")
          AND aa.status = 1
          AND bm.is_active = TRUE
          AND COALESCE(fta.fbtool_id, '') <> ''
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $map[(string)$row['account_id']] = $row;
    }
    return $map;
}

function fetchAvailableAccountsMapForBm(PDO $db, string $bmId, string $geo, array $accountIds): array
{
    $params = [':bm_id' => $bmId];
    $accPh = [];
    foreach (array_values($accountIds) as $i => $accountId) {
        $key = ":acc_{$i}";
        $accPh[] = $key;
        $params[$key] = $accountId;
    }
    $sql = "
        SELECT
            aa.id::text AS account_id,
            aa.name AS account_name,
            bm.id::text AS bm_id,
            bm.name AS bm_name,
            COALESCE(fta.fbtool_id, '') AS fbtool_id
        FROM public.ad_accounts aa
        JOIN public.business_managers bm ON bm.id = aa.bm_id
        LEFT JOIN public.fbtool_accounts fta ON fta.id = bm.fbtool_account_id
        WHERE aa.bm_id::text = :bm_id
          AND aa.id::text IN (" . implode(',', $accPh) . ")
          AND aa.status = 1
          AND bm.is_active = TRUE
          AND COALESCE(fta.fbtool_id, '') <> ''
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $map[(string)$row['account_id']] = $row;
    }
    return $map;
}

function normalizeStringList(mixed $value): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        $item = trim((string)$item);
        if ($item !== '' && !in_array($item, $out, true)) {
            $out[] = $item;
        }
    }
    return $out;
}

function normalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function clampInt(mixed $value, int $min, int $max): int
{
    return max($min, min($max, (int)$value));
}

function moneyNumber(mixed $value): ?float
{
    if ($value === null || $value === '') return null;
    if (!is_numeric((string)$value)) return null;
    return round((float)$value, 2);
}

function nullableString(mixed $value): ?string
{
    $s = trim((string)$value);
    return $s === '' ? null : $s;
}

function normalizeBidStrategy(string $mode): string
{
    $mode = strtolower(trim($mode));
    return match ($mode) {
        'bidcap', 'bid_cap', 'bid cap' => 'bidcap',
        'costcap', 'cost_cap', 'cost cap' => 'costcap',
        'auto', 'lowest_cost', 'lowest cost' => 'auto',
        default => 'bidcap',
    };
}

function deriveBmShortNumber(string $bmName, string $bmId): string
{
    if (preg_match('/(\d+)(?!.*\d)/', $bmName, $m)) {
        return $m[1];
    }
    if (preg_match('/(\d{1,4})(?!.*\d)/', $bmId, $m)) {
        return $m[1];
    }
    return '0';
}
