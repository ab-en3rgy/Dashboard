<?php
// api/campaigns.php
// @version 1.0.12
// GET /api/campaigns.php?level=campaign&range=today
// GET /api/campaigns.php?level=campaign&account_id=act_123
// GET /api/campaigns.php?level=adset&campaign_id=123
// GET /api/campaigns.php?level=ad&adset_id=456

require __DIR__.'/_bootstrap.php';

ensureAdsetBidFields($db);
ensureCampaignAutoRuleFields($db);

// Geo filter: accepts "AR" or "AR,CL,GR" separated by commas
// Returns [sql_fragment, params_array]
function buildGeoFilter(string $geoParam, string $campAlias = 'c'): array {
    $geos = array_filter(array_map('trim', explode(',', strtoupper($geoParam))));
    if (empty($geos)) return ['', []];
    $conditions = [];
    $params = [];
    foreach ($geos as $i => $geo) {
        $p1 = ":geo_{$i}";
        $conditions[] = "campaign_geo({$campAlias}.name) = {$p1}";
        $params[$p1] = $geo;
    }
    return [' AND (' . implode(' OR ', $conditions) . ')', $params];
}

function statusFilterSql(string $alias, string $paramBase, string $value): array {
    if ($value === 'ACTIVE') {
        return [
            " AND {$alias}.status = :{$paramBase}_status_active AND {$alias}.effective_status = :{$paramBase}_effective_active",
            [":{$paramBase}_status_active" => 'ACTIVE', ":{$paramBase}_effective_active" => 'ACTIVE'],
        ];
    }
    return [
        " AND ({$alias}.status = :{$paramBase}_status OR {$alias}.effective_status = :{$paramBase}_eff)",
        [":{$paramBase}_status" => $value, ":{$paramBase}_eff" => $value],
    ];
}

function isIsoDate(string $value): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function campaignDateWindow(string $range, DateTime $now, DateTimeZone $tzObj, string $customDate = ''): array {
    if ($range === 'date') {
        $day = isIsoDate($customDate) ? $customDate : $now->format('Y-m-d');
        return [new DateTime($day . ' 00:00:00', $tzObj), new DateTime($day . ' 23:59:59', $tzObj)];
    }
    switch ($range) {
        case 'yesterday':
            return [(clone $now)->modify('yesterday midnight'), (clone $now)->modify('yesterday 23:59:59')];
        case 'yesterday_today':
            return [(clone $now)->modify('yesterday midnight'), $now];
        case '3d':
            return [(clone $now)->modify('-2 days midnight'), $now];
        case '7d':
            return [(clone $now)->modify('-6 days midnight'), $now];
        case '14d':
            return [(clone $now)->modify('-13 days midnight'), $now];
        case 'this_week':
            return [(clone $now)->modify('monday this week midnight'), $now];
        case 'this_month':
            return [(clone $now)->modify('first day of this month midnight'), $now];
        case 'last_month':
            return [(clone $now)->modify('first day of last month midnight'), (clone $now)->modify('last day of last month 23:59:59')];
        case '30d':
            return [(clone $now)->modify('-29 days midnight'), $now];
        case '90d':
            return [(clone $now)->modify('-89 days midnight'), $now];
        case 'this_year':
            return [(clone $now)->modify('first day of january this year midnight'), $now];
        case 'all':
            return [new DateTime('2020-01-01', $tzObj), $now];
        default:
            return [(clone $now)->modify('midnight'), $now];
    }
}

function campaignBaselineRange(string $range): string {
    return in_array($range, ['90d', 'this_year', 'all'], true) ? '90d' : '30d';
}

function costBaselineStats(array $row): array {
    $spend = (float)($row['spend'] ?? 0);
    $clicks = (int)($row['clicks'] ?? 0);
    $leads = (int)($row['leads'] ?? 0);
    $regs = (int)($row['regs'] ?? 0);
    $deps = (int)($row['deps'] ?? 0);
    return [
        'spend' => round($spend, 4),
        'clicks' => $clicks,
        'leads' => $leads,
        'regs' => $regs,
        'deps' => $deps,
        'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : 0,
        'cpl' => $leads > 0 ? round($spend / $leads, 4) : 0,
        'cpr' => $regs > 0 ? round($spend / $regs, 4) : 0,
        'cpd' => $deps > 0 ? round($spend / $deps, 4) : 0,
    ];
}

function costDiffPct(float $actual, float $baseline): ?float {
    if ($actual <= 0 || $baseline <= 0) return null;
    return round(($actual - $baseline) / $baseline * 100, 2);
}

function campaignGeoFromName(string $name): string {
    $parts = array_values(array_filter(array_map('trim', explode('_', strtoupper($name)))));
    if (!$parts) return 'XX';
    $reserved = ['BC', 'CBO', 'ABO', 'SLOT', 'CRASH'];
    foreach ($parts as $i => $part) {
        if (preg_match('/^[A-Z]{2}$/', $part) && !in_array($part, $reserved, true) && $i >= 3) {
            return $part;
        }
    }
    foreach ($parts as $part) {
        if (preg_match('/^[A-Z]{2}$/', $part) && !in_array($part, $reserved, true)) {
            return $part;
        }
    }
    return 'XX';
}


// List of bm_id values available to the user
$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk([]);

$level   = in_array($_GET['level'] ?? '', ['campaign','adset','ad']) ? $_GET['level'] : 'campaign';
$range   = $_GET['range']       ?? 'today';
$customDate = trim((string)($_GET['date'] ?? ''));
$accId   = $_GET['account_id']  ?? null;
$launchDate = trim((string)($_GET['launch_date'] ?? ''));
$launchMode = trim((string)($_GET['launch_mode'] ?? ''));
$campId  = $_GET['campaign_id'] ?? null;   // can be "id1,id2,id3"
$adsetId = $_GET['adset_id']    ?? null;   // can be "id1,id2,id3"
$bmFilter = $_GET['bm_id']      ?? null;   // GF BM filter
$adName   = $_GET['ad_name']    ?? null;   // filter by ad name (creative)
$accountIds = $_GET['account_ids'] ?? null; // filter by list of accounts (for Trends)
$geoParam = $_GET['geo'] ?? '';
$includeDeleted = ($_GET['include_deleted'] ?? '') === '1';
$wantCostBaseline = ($_GET['cost_baseline'] ?? '') === '1';

// Status filters (delivery)
$effStatus    = $_GET['effective_status'] ?? null;
$campStatus   = $_GET['campaign_status']  ?? null;
$adsetStatus  = $_GET['adset_status']     ?? null;
// account_status: ACTIVE=1, PAUSED=any non-active account status
$accountStatusRaw = $_GET['account_status'] ?? null;
$accountStatus = match($accountStatusRaw) { 'ACTIVE' => 'ACTIVE', 'PAUSED' => 'PAUSED', default => null };
$accountScope = $_GET['account_scope'] ?? null;

// Parse multiple IDs
$campIds  = $campId  ? array_filter(array_map('trim', explode(',', $campId)))  : [];
$adsetIds = $adsetId ? array_filter(array_map('trim', explode(',', $adsetId))) : [];
$tz      = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');

// Period
$tzObj = appDateTimeZone($tz);
$now   = new DateTime('now', $tzObj);
[$dtFrom, $dtTo] = campaignDateWindow($range, $now, $tzObj, $customDate);
$dateFrom = $dtFrom->format('Y-m-d');
$dateTo   = $dtTo->format('Y-m-d');

// Named placeholders for bm_id IN (...)
$bmPh = []; $bmParams = [];
foreach ($bmIds as $i => $v) {
    $k = ":bm_{$i}"; $bmPh[] = $k; $bmParams[$k] = $v;
}
$bmInSql = '('.implode(',', $bmPh).')';

// ── Statistics from insights_daily ───────────────────────────────────────
function buildStats(PDO $db, string $aggCol, string $dateFrom, string $dateTo,
                    array $bmParams, string $bmInSql,
                    ?string $accId, array $campIds, array $adsetIds, ?string $accountScope,
                    bool $includeDeletedCampaigns = false): array
{
    global $now;
    $today = $now instanceof DateTimeInterface ? $now->format('Y-m-d') : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $extra = ''; $extraP = [];
    if ($accId)   { $extra .= ' AND a.ad_account_id = :acc_id';  $extraP[':acc_id'] = $accId; }
    elseif ($accountScope === 'active') { $extra .= ' AND aa.status = 1'; }
    if ($campIds) {
        $ph = implode(',', array_map(fn($i) => ":cid_{$i}", array_keys($campIds)));
        $extra .= " AND a.campaign_id IN ({$ph})";
        foreach ($campIds as $i => $v) $extraP[":cid_{$i}"] = $v;
    }
    if ($adsetIds) {
        $ph = implode(',', array_map(fn($i) => ":sid_{$i}", array_keys($adsetIds)));
        $extra .= " AND a.ad_set_id IN ({$ph})";
        foreach ($adsetIds as $i => $v) $extraP[":sid_{$i}"] = $v;
    }

    // Mapping aggCol -> sub_id field for orphan rows
    $orphanCol = match($aggCol) {
        'campaign_id' => 'sub_id_10',
        'ad_set_id'   => 'sub_id_12',
        'id'          => null, // for ads there is no reliable sub_id
        default       => null,
    };

    $orphanExtra = ''; $orphanExtraP = [];
    if ($accId)    { $orphanExtra .= ' AND id.sub_id_10 IS NOT NULL'; } // coarse filtering
    if ($campIds)  {
        $ph = implode(',', array_map(fn($i) => ":ocid_{$i}", array_keys($campIds)));
        $orphanExtra .= " AND id.sub_id_10 IN ({$ph})";
        foreach ($campIds as $i => $v) $orphanExtraP[":ocid_{$i}"] = $v;
    }
    if ($adsetIds) {
        $ph = implode(',', array_map(fn($i) => ":osid_{$i}", array_keys($adsetIds)));
        $orphanExtra .= " AND id.sub_id_12 IN ({$ph})";
        foreach ($adsetIds as $i => $v) $orphanExtraP[":osid_{$i}"] = $v;
    }

    // Main query: rows with structure
    $deletedStatsSql = $includeDeletedCampaigns ? '' : "
          AND COALESCE(a.status, '') != 'DELETED'
          AND COALESCE(s.status, '') != 'DELETED'
          AND COALESCE(c.status, '') != 'DELETED'
    ";
    $sql = "
        SELECT
            a.{$aggCol}::text AS entity_id,
            SUM(id.spend)            AS spend,
            SUM(CASE WHEN id.date = :today THEN id.spend ELSE 0 END) AS today_spend,
            SUM(id.delta)            AS delta,
            SUM(id.impressions)      AS impressions,
            SUM(id.clicks)           AS clicks,
            COUNT(DISTINCT id.ad_id) AS ad_count,
            SUM(id.leads)            AS leads,
            SUM(id.regs)             AS regs,
            SUM(id.deps)             AS deps,
            SUM(id.revenue)          AS revenue
        FROM insights_daily id
        JOIN ads a          ON a.id  = id.ad_id
        JOIN ad_sets s      ON s.id  = a.ad_set_id
        JOIN campaigns c    ON c.id  = a.campaign_id
        LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE id.date >= :date_from
          AND id.date <= :date_to
          AND (aa.bm_id IN {$bmInSql} OR aa.bm_id IS NULL)
          {$deletedStatsSql}
          AND a.{$aggCol} IS NOT NULL
          {$extra}
        GROUP BY a.{$aggCol}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([':date_from'=>$dateFrom,':date_to'=>$dateTo,':today'=>$today], $bmParams, $extraP));
    $result = array_column($stmt->fetchAll(), null, 'entity_id');

    // Orphan rows: rows without structure, with sub_id
    if ($orphanCol) {
        $orphanSql = "
            SELECT
                id.{$orphanCol}::text AS entity_id,
                SUM(id.spend)       AS spend,
                SUM(CASE WHEN id.date = :today THEN id.spend ELSE 0 END) AS today_spend,
                SUM(id.delta)       AS delta,
                SUM(id.impressions) AS impressions,
                SUM(id.clicks)      AS clicks,
                    0                   AS ad_count,
                SUM(id.leads)       AS leads,
                SUM(id.regs)        AS regs,
                SUM(id.deps)        AS deps,
                SUM(id.revenue)     AS revenue
            FROM insights_daily id
            LEFT JOIN ads a ON a.id = id.ad_id
            WHERE a.id IS NULL
              AND id.{$orphanCol} IS NOT NULL
              AND id.{$orphanCol} != ''
              AND id.{$orphanCol} NOT LIKE '{%}'
              AND id.date >= :date_from
              AND id.date <= :date_to
              {$orphanExtra}
            GROUP BY id.{$orphanCol}
        ";
        $orphanStmt = $db->prepare($orphanSql);
        $orphanStmt->execute(array_merge([':date_from'=>$dateFrom,':date_to'=>$dateTo,':today'=>$today], $orphanExtraP));
        foreach ($orphanStmt->fetchAll() as $oRow) {
            $eid = $oRow['entity_id'];
            if (isset($result[$eid])) {
                // Add to existing record
                foreach (['spend','today_spend','delta','impressions','clicks','leads','regs','deps','revenue'] as $k) {
                    $result[$eid][$k] = ($result[$eid][$k] ?? 0) + ($oRow[$k] ?? 0);
                }
            } else {
                $result[$eid] = $oRow;
            }
        }
    }

    return $result;
}

function buildCostBaselineByGeo(
    PDO $db,
    string $dateFrom,
    string $dateTo,
    array $bmParams,
    string $bmInSql,
    ?string $accId,
    ?string $accountScope,
    ?string $bmFilter,
    ?string $accountIds,
    string $geoParam
): array {
    $extra = '';
    $params = array_merge([':date_from' => $dateFrom, ':date_to' => $dateTo], $bmParams);

    if ($geoParam !== '') {
        [$geoSql, $geoParams] = buildGeoFilter($geoParam, 'c');
        $extra .= $geoSql;
        $params += $geoParams;
    }
    if ($bmFilter) {
        $extra .= ' AND aa.bm_id = :baseline_bm_filter';
        $params[':baseline_bm_filter'] = $bmFilter;
    }
    if ($accId) {
        $extra .= ' AND a.ad_account_id = :baseline_acc_id';
        $params[':baseline_acc_id'] = $accId;
    } elseif ($accountScope === 'active') {
        $extra .= ' AND aa.status = 1';
    }
    if ($accountIds) {
        $ids = array_filter(array_map('trim', explode(',', $accountIds)));
        if ($ids) {
            $ph = implode(',', array_map(fn($i) => ":baseline_accid_{$i}", array_keys($ids)));
            $extra .= " AND aa.id IN ({$ph})";
            foreach ($ids as $i => $v) $params[":baseline_accid_{$i}"] = $v;
        }
    }

    $sql = "
        SELECT
            COALESCE(NULLIF(campaign_geo(c.name), ''), 'XX') AS geo,
            SUM(id.spend)       AS spend,
            SUM(id.clicks)      AS clicks,
            SUM(id.leads)       AS leads,
            SUM(id.regs)        AS regs,
            SUM(id.deps)        AS deps
        FROM public.insights_daily id
        JOIN public.ads a ON a.id = id.ad_id
        JOIN public.ad_sets s ON s.id = a.ad_set_id
        JOIN public.campaigns c ON c.id = a.campaign_id
        JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
        WHERE id.date >= :date_from
          AND id.date <= :date_to
          AND aa.bm_id IN {$bmInSql}
          {$extra}
        GROUP BY 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string)$row['geo']] = costBaselineStats($row);
    }
    return $out;
}

$aggCol = match($level) { 'adset'=>'ad_set_id', 'ad'=>'id', default=>'campaign_id' };
$stats  = buildStats(
    $db,
    $aggCol,
    $dateFrom,
    $dateTo,
    $bmParams,
    $bmInSql,
    $accId,
    $campIds,
    $adsetIds,
    $accountScope,
    $level === 'campaign' && $includeDeleted
);
$costBaselineByGeo = [];
if ($wantCostBaseline && $level === 'campaign') {
    [$baselineFrom, $baselineTo] = campaignDateWindow(campaignBaselineRange($range), $now, $tzObj);
    $costBaselineByGeo = buildCostBaselineByGeo(
        $db,
        $baselineFrom->format('Y-m-d'),
        $baselineTo->format('Y-m-d'),
        $bmParams,
        $bmInSql,
        $accId,
        $accountScope,
        $bmFilter,
        $accountIds,
        (string)$geoParam
    );
}

// ── Main selection ───────────────────────────────────────────────────
$rows = [];

if ($level === 'campaign') {
    $where = "AND aa.bm_id IN {$bmInSql}"; $params = $bmParams;
    if ($geoParam !== '') { [$geoSql, $geoParams] = buildGeoFilter((string)$geoParam, 'c'); $where .= $geoSql; $params += $geoParams; }
    if ($bmFilter) { $where .= ' AND bm.id = :bm_filter'; $params[':bm_filter'] = $bmFilter; }
    if ($accId) { $where .= ' AND aa.id = :acc_id'; $params[':acc_id'] = $accId; }
    if ($launchDate !== '') {
        $where .= match ($launchMode) {
            'after' => ' AND c.created_time::date >= CAST(:launch_date AS date)',
            'before' => ' AND c.created_time::date <= CAST(:launch_date AS date)',
            default => ' AND c.created_time::date = CAST(:launch_date AS date)',
        };
        $params[':launch_date'] = $launchDate;
    }
    if ($campIds) { $ph=implode(',',array_map(fn($i)=>":cid_{$i}",array_keys($campIds)));$where.=" AND c.id IN ({$ph})";foreach($campIds as $i=>$v)$params[":cid_{$i}"]=$v; }
    if ($effStatus) { [$sqlStatus, $statusParams] = statusFilterSql('c', 'eff', $effStatus); $where .= $sqlStatus; $params += $statusParams; }
    if (!$accId && $accountScope === 'active') { $where .= ' AND aa.status = 1'; }
    if ($effStatus === 'ACTIVE' || $accountStatus === 'ACTIVE') { $where .= ' AND aa.status = 1'; }
    elseif ($accountStatus === 'PAUSED') { $where .= ' AND aa.status != 1'; }
    if ($adName) {
        $where .= ' AND EXISTS (SELECT 1 FROM ads ax WHERE ax.campaign_id = c.id AND ax.name = :ad_name_filter)';
        $params[':ad_name_filter'] = $adName;
    }
    if ($accountIds) {
        $ids = array_filter(array_map('trim', explode(',', $accountIds)));
        if ($ids) {
            $ph = implode(',', array_map(fn($i) => ":accid_{$i}", array_keys($ids)));
            $where .= " AND aa.id IN ({$ph})";
            foreach ($ids as $i => $v) $params[":accid_{$i}"] = $v;
        }
    }

    $campaignDeletedSql = $includeDeleted ? '' : "c.status != 'DELETED' AND ";
    $stmt = $db->prepare("
        SELECT c.id::text, c.name, c.status, c.effective_status, c.objective,
               c.daily_budget, c.lifetime_budget, c.updated_time,
               c.auto_rule_verdict, c.auto_rule_payload,
               c.ad_account_id,
               aa.name AS account_name, aa.currency, aa.timezone_name, aa.status AS account_status,
               bm.id   AS bm_id, bm.name AS bm_name,
               COALESCE(ascnt.adsets_active, 0) AS adsets_active,
               COALESCE(ascnt.adsets_total, 0) AS adsets_total,
               CASE WHEN c.status = 'MANUAL_STOP' OR c.effective_status = 'MANUAL_STOP'
                    THEN 'manual_stop' ELSE NULL END AS manual_status
        FROM campaigns c
        JOIN ad_accounts aa      ON aa.id  = c.ad_account_id
        JOIN business_managers bm ON bm.id = aa.bm_id
        LEFT JOIN (
            SELECT
                s.campaign_id::text AS campaign_id,
                COUNT(*) AS adsets_total,
                COUNT(*) FILTER (
                    WHERE s.status = 'ACTIVE'
                      AND (s.effective_status = 'ACTIVE' OR s.effective_status IS NULL OR s.effective_status = '')
                      AND c2.status = 'ACTIVE'
                      AND (c2.effective_status = 'ACTIVE' OR c2.effective_status IS NULL OR c2.effective_status = '')
                      AND aa2.status = 1
                ) AS adsets_active
            FROM ad_sets s
            JOIN campaigns c2    ON c2.id = s.campaign_id
            JOIN ad_accounts aa2 ON aa2.id = s.ad_account_id
            WHERE s.status != 'DELETED'
            GROUP BY s.campaign_id
        ) ascnt ON ascnt.campaign_id = c.id::text
        WHERE {$campaignDeletedSql} 1=1 {$where}
        ORDER BY c.name
        LIMIT 5000
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

} elseif ($level === 'adset') {
    $where = "AND aa.bm_id IN {$bmInSql}"; $params = $bmParams;
    if ($geoParam !== '') { [$geoSql, $geoParams] = buildGeoFilter((string)$geoParam, 'c'); $where .= $geoSql; $params += $geoParams; }
    if ($bmFilter) { $where .= ' AND bm.id = :bm_filter'; $params[':bm_filter'] = $bmFilter; }
    if ($campIds) { $ph=implode(',',array_map(fn($i)=>":cid_{$i}",array_keys($campIds)));$where.=" AND s.campaign_id IN ({$ph})";foreach($campIds as $i=>$v)$params[":cid_{$i}"]=$v; }
    if ($accId)  { $where .= ' AND aa.id = :acc_id'; $params[':acc_id'] = $accId; }
    if ($campStatus) { [$sqlStatus, $statusParams] = statusFilterSql('c', 'camp', $campStatus); $where .= $sqlStatus; $params += $statusParams; }
    if ($effStatus)  { [$sqlStatus, $statusParams] = statusFilterSql('s', 'eff', $effStatus); $where .= $sqlStatus; $params += $statusParams; }
    if (!$accId && $accountScope === 'active') { $where .= ' AND aa.status = 1'; }
    if ($effStatus === 'ACTIVE' || $accountStatus === 'ACTIVE') { $where .= ' AND aa.status = 1'; }
    elseif ($accountStatus === 'PAUSED') { $where .= ' AND aa.status != 1'; }

    $stmt = $db->prepare("
        SELECT s.id::text, s.name, s.status, s.effective_status,
               s.daily_budget, s.lifetime_budget, s.updated_time,
               s.bid_amount, s.bid_strategy_mode,
               s.campaign_id::text, s.ad_account_id,
               aa.name AS account_name, aa.currency, aa.timezone_name, aa.status AS account_status,
               bm.id   AS bm_id, bm.name AS bm_name,
               c.name  AS campaign_name,
               c.daily_budget AS campaign_daily_budget,
               c.lifetime_budget AS campaign_lifetime_budget,
               c.status AS campaign_status,
               c.effective_status AS campaign_effective_status,
               COALESCE(adc.ads_active, 0) AS ads_active,
               COALESCE(adc.ads_total, 0) AS ads_total
        FROM ad_sets s
        JOIN campaigns c          ON c.id  = s.campaign_id
        JOIN ad_accounts aa       ON aa.id = s.ad_account_id
        JOIN business_managers bm ON bm.id = aa.bm_id
        LEFT JOIN (
            SELECT
                a.ad_set_id::text AS ad_set_id,
                COUNT(*) AS ads_total,
                COUNT(*) FILTER (
                    WHERE a.status = 'ACTIVE'
                      AND (a.effective_status = 'ACTIVE' OR a.effective_status IS NULL OR a.effective_status = '')
                      AND s2.status = 'ACTIVE'
                      AND (s2.effective_status = 'ACTIVE' OR s2.effective_status IS NULL OR s2.effective_status = '')
                      AND c2.status = 'ACTIVE'
                      AND (c2.effective_status = 'ACTIVE' OR c2.effective_status IS NULL OR c2.effective_status = '')
                      AND aa2.status = 1
                ) AS ads_active
            FROM ads a
            JOIN ad_sets s2      ON s2.id = a.ad_set_id
            JOIN campaigns c2    ON c2.id = a.campaign_id
            JOIN ad_accounts aa2 ON aa2.id = a.ad_account_id
            WHERE a.status != 'DELETED'
            GROUP BY a.ad_set_id
        ) adc ON adc.ad_set_id = s.id::text
        WHERE s.status != 'DELETED'
          AND c.status != 'DELETED' {$where}
        ORDER BY s.name
        LIMIT 5000
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

} else { // ad
    $where = "AND aa.bm_id IN {$bmInSql}"; $params = $bmParams;
    if ($geoParam !== '') { [$geoSql, $geoParams] = buildGeoFilter((string)$geoParam, 'c'); $where .= $geoSql; $params += $geoParams; }
    if ($bmFilter) { $where .= ' AND bm.id = :bm_filter'; $params[':bm_filter'] = $bmFilter; }
    if ($adsetIds) { $ph=implode(',',array_map(fn($i)=>":sid_{$i}",array_keys($adsetIds)));$where.=" AND a.ad_set_id IN ({$ph})";foreach($adsetIds as $i=>$v)$params[":sid_{$i}"]=$v; }
    if ($campIds)  { $ph=implode(',',array_map(fn($i)=>":cid_{$i}",array_keys($campIds)));$where.=" AND a.campaign_id IN ({$ph})";foreach($campIds as $i=>$v)$params[":cid_{$i}"]=$v; }
    if ($accId)    { $where .= ' AND aa.id = :acc_id'; $params[':acc_id'] = $accId; }
    if ($campStatus)  { [$sqlStatus, $statusParams] = statusFilterSql('c', 'camp', $campStatus); $where .= $sqlStatus; $params += $statusParams; }
    if ($adsetStatus) { [$sqlStatus, $statusParams] = statusFilterSql('s', 'adset', $adsetStatus); $where .= $sqlStatus; $params += $statusParams; }
    if ($effStatus)   { [$sqlStatus, $statusParams] = statusFilterSql('a', 'eff', $effStatus); $where .= $sqlStatus; $params += $statusParams; }
    if (!$accId && $accountScope === 'active') { $where .= ' AND aa.status = 1'; }
    if ($effStatus === 'ACTIVE' || $accountStatus === 'ACTIVE') { $where .= ' AND aa.status = 1'; }
    elseif ($accountStatus === 'PAUSED') { $where .= ' AND aa.status != 1'; }

    $stmt = $db->prepare("
        SELECT a.id::text, a.name, a.status, a.effective_status,
               a.ad_set_id::text, a.campaign_id::text, a.ad_account_id,
               aa.name AS account_name, aa.currency, aa.timezone_name, aa.status AS account_status,
               bm.id   AS bm_id, bm.name AS bm_name,
               c.name  AS campaign_name,
               c.daily_budget AS campaign_daily_budget,
               c.lifetime_budget AS campaign_lifetime_budget,
               c.status AS campaign_status, c.effective_status AS campaign_effective_status,
               s.name AS adset_name, s.status AS adset_status, s.effective_status AS adset_effective_status
        FROM ads a
        JOIN ad_sets s            ON s.id  = a.ad_set_id
        JOIN campaigns c          ON c.id  = a.campaign_id
        JOIN ad_accounts aa       ON aa.id = a.ad_account_id
        JOIN business_managers bm ON bm.id = aa.bm_id
        WHERE a.status != 'DELETED'
          AND s.status != 'DELETED'
          AND c.status != 'DELETED' {$where}
        ORDER BY a.name
        LIMIT 10000
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// ── Merge statistics ─────────────────────────────────────────────────
$result = array_map(function(array $r) use ($stats, $level, $costBaselineByGeo): array {
    $id     = $r['id'];
    $s      = $stats[$id] ?? [];
    $spend  = (float)($s['spend']       ?? 0);
    $impr   = (int)  ($s['impressions'] ?? 0);
    $clicks = (int)  ($s['clicks']      ?? 0);

    $out = [
        'id'               => $id,  // string id — JS loses precision on 64-bit numbers
        'name'             => $r['name'],
        'status'           => $r['status'],
        'effective_status' => $r['effective_status'],
        'account_id'       => $r['ad_account_id'],
        'account_name'     => $r['account_name'],
        'account_status'   => (int)($r['account_status'] ?? 1),
        'account_is_active'=> (int)($r['account_status'] ?? 1) === 1,
        'bm_id'            => (string)$r['bm_id'],
        'bm_name'          => $r['bm_name'],
        'currency'         => $r['currency'],
        'timezone'         => $r['timezone_name'],
        'daily_budget'     => array_key_exists('daily_budget', $r) && $r['daily_budget'] !== null ? (float)$r['daily_budget'] : null,
        'lifetime_budget'  => array_key_exists('lifetime_budget', $r) && $r['lifetime_budget'] !== null ? (float)$r['lifetime_budget'] : null,
        'bid_amount'       => array_key_exists('bid_amount', $r) && $r['bid_amount'] !== null ? (float)$r['bid_amount'] : null,
        'bid_strategy_mode' => array_key_exists('bid_strategy_mode', $r) ? $r['bid_strategy_mode'] : null,
        'stats'            => [
            'spend'       => $spend,
            'today_spend' => (float)($s['today_spend'] ?? 0),
            'delta'       => (float)($s['delta'] ?? 0),
            'impressions' => $impr,
            'clicks'      => $clicks,
            'ctr'         => $impr   > 0 ? round($clicks / $impr   * 100,  4) : 0,
            'cpm'         => $impr   > 0 ? round($spend  / $impr   * 1000, 4) : 0,
            'cpc'         => $clicks > 0 ? round($spend  / $clicks,        4) : 0,
            'ad_count'    => (int)($s['ad_count'] ?? 0),
            'leads'       => (int)($s['leads']    ?? 0),
            'regs'        => (int)($s['regs']     ?? 0),
            'deps'        => (int)($s['deps']     ?? 0),
            'revenue'     => (float)($s['revenue'] ?? 0),
            'profit'      => round((float)($s['revenue'] ?? 0) - $spend, 4),
            'roi'         => $spend > 0 ? round(((float)($s['revenue'] ?? 0) - $spend) / $spend * 100, 2) : 0,
            'cpl'         => (int)($s['leads'] ?? 0) > 0 ? round($spend / (int)$s['leads'], 4) : 0,
            'cpr'         => (int)($s['regs']  ?? 0) > 0 ? round($spend / (int)$s['regs'],  4) : 0,
            'cpd'         => (int)($s['deps']  ?? 0) > 0 ? round($spend / (int)$s['deps'],  4) : 0,
        ],
    ];

    if ($level === 'campaign' && $costBaselineByGeo) {
        $baseline = $costBaselineByGeo[campaignGeoFromName((string)$r['name'])] ?? null;
        $out['stats']['cost_baseline'] = $baseline;
        $out['stats']['cost_diff_pct'] = $baseline ? [
            'cpc' => costDiffPct((float)$out['stats']['cpc'], (float)$baseline['cpc']),
            'cpl' => costDiffPct((float)$out['stats']['cpl'], (float)$baseline['cpl']),
            'cpr' => costDiffPct((float)$out['stats']['cpr'], (float)$baseline['cpr']),
            'cpd' => costDiffPct((float)$out['stats']['cpd'], (float)$baseline['cpd']),
        ] : null;
    }

    if ($level === 'campaign') {
        $out['adsets_active'] = (int)($r['adsets_active'] ?? 0);
        $out['adsets_total'] = (int)($r['adsets_total'] ?? 0);
        $payload = null;
        $payloadRaw = $r['auto_rule_payload'] ?? null;
        if (is_string($payloadRaw) && $payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            $payload = json_last_error() === JSON_ERROR_NONE ? $decoded : $payloadRaw;
        } elseif (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        }
        $out['auto_rule_verdict'] = $r['auto_rule_verdict'] ?? null;
        $out['auto_rule_payload'] = $payload;
    }

    if ($level === 'adset' || $level === 'ad') {
        $out['campaign_id']   = $r['campaign_id'];
        $out['campaign_name'] = $r['campaign_name'];
        $out['campaign_daily_budget'] = array_key_exists('campaign_daily_budget', $r) && $r['campaign_daily_budget'] !== null ? (float)$r['campaign_daily_budget'] : null;
        $out['campaign_lifetime_budget'] = array_key_exists('campaign_lifetime_budget', $r) && $r['campaign_lifetime_budget'] !== null ? (float)$r['campaign_lifetime_budget'] : null;
        $out['campaign_status'] = $r['campaign_status'] ?? null;
        $out['campaign_effective_status'] = $r['campaign_effective_status'] ?? null;
    }
    if ($level === 'adset') {
        $out['ads_active'] = (int)($r['ads_active'] ?? 0);
        $out['ads_total'] = (int)($r['ads_total'] ?? 0);
    }
    if ($level === 'ad') {
        $out['adset_id']   = $r['ad_set_id'];
        $out['adset_name'] = $r['adset_name'];
        $out['adset_status'] = $r['adset_status'] ?? null;
        $out['adset_effective_status'] = $r['adset_effective_status'] ?? null;
    }
    if (isset($r['objective'])) $out['objective'] = $r['objective'];
    if (array_key_exists('manual_status', $r)) {
        $out['manual_status'] = $r['manual_status'] ?: null;
    }

    return $out;
}, $rows);

// Totals
$totals = array_reduce($result, function(array $t, array $r): array {
    $t['spend']       += $r['stats']['spend'];
    $t['today_spend'] += $r['stats']['today_spend'];
    $t['delta']       += $r['stats']['delta'];
    $t['impressions'] += $r['stats']['impressions'];
    $t['clicks']      += $r['stats']['clicks'];
    $t['leads']       += $r['stats']['leads'];
    $t['regs']        += $r['stats']['regs'];
    $t['deps']        += $r['stats']['deps'];
    $t['revenue']     += $r['stats']['revenue'];
    return $t;
}, ['spend'=>0,'today_spend'=>0,'delta'=>0,'impressions'=>0,'clicks'=>0,'leads'=>0,'regs'=>0,'deps'=>0,'revenue'=>0]);

$totals['profit'] = round($totals['revenue'] - $totals['spend'], 4);
$totals['roi']    = $totals['spend'] > 0 ? round($totals['profit'] / $totals['spend'] * 100, 2) : 0;
$totals['ctr']    = $totals['impressions'] > 0 ? round($totals['clicks'] / $totals['impressions'] * 100,  4) : 0;
$totals['cpm']    = $totals['impressions'] > 0 ? round($totals['spend']  / $totals['impressions'] * 1000, 4) : 0;
$totals['cpc']    = $totals['clicks']      > 0 ? round($totals['spend']  / $totals['clicks'],             4) : 0;
$totals['cpl']    = $totals['leads']       > 0 ? round($totals['spend']  / $totals['leads'],              4) : 0;
$totals['cpr']    = $totals['regs']        > 0 ? round($totals['spend']  / $totals['regs'],               4) : 0;
$totals['cpd']    = $totals['deps']        > 0 ? round($totals['spend']  / $totals['deps'],               4) : 0;

apiOk($result, [
    'level'     => $level,
    'range'     => $range,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'tz'        => $tz,
    'count'     => count($result),
    'totals'    => $totals,
]);

function ensureAdsetBidFields(PDO $db): void {
    $db->exec("
        ALTER TABLE IF EXISTS ad_sets
            ADD COLUMN IF NOT EXISTS bid_amount NUMERIC(15,2),
            ADD COLUMN IF NOT EXISTS bid_strategy_mode TEXT;
    ");
}

function ensureCampaignAutoRuleFields(PDO $db): void {
    $db->exec("
        ALTER TABLE IF EXISTS campaigns
            ADD COLUMN IF NOT EXISTS auto_rule_verdict TEXT,
            ADD COLUMN IF NOT EXISTS auto_rule_payload JSONB;
    ");
}
