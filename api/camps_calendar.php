<?php
// api/camps_calendar.php
// @version 1.0.0
// GET /api/camps_calendar.php?geo=IT

require __DIR__.'/_bootstrap.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk(['rows' => []]);

$bmParams = [];
$bmPh = [];
foreach ($bmIds as $i => $v) {
    $k = ":bm_{$i}";
    $bmPh[] = $k;
    $bmParams[$k] = $v;
}
$bmInSql = '(' . implode(',', $bmPh) . ')';

$params = $bmParams;
$cfg = require __DIR__ . '/../config/config.php';
$tz = appTimezoneName($me['display_tz'] ?? $cfg['display_tz'] ?? 'Europe/Chisinau');
$params[':tz'] = $tz;

$geoParam = strtoupper(trim((string)($_GET['geo'] ?? '')));
$geoSql = '';
if ($geoParam !== '') {
    $geos = array_values(array_unique(array_filter(array_map(
        static fn($v) => preg_match('/^[A-Z]{2}$/', $v) ? $v : '',
        array_map('trim', explode(',', $geoParam))
    ))));
    if ($geos) {
        $geoPh = [];
        foreach ($geos as $i => $geo) {
            $k = ":geo_{$i}";
            $geoPh[] = $k;
            $params[$k] = $geo;
        }
        $geoSql = ' AND geo IN (' . implode(',', $geoPh) . ')';
    }
}

try {
    $sql = "
        WITH ad_stats AS (
            SELECT
                a.campaign_id,
                COUNT(DISTINCT a.id) AS ads_count,
                COALESCE(SUM(i.impressions), 0) AS impressions,
                COALESCE(SUM(i.clicks), 0) AS clicks,
                COALESCE(SUM(i.spend), 0) AS spend,
                COALESCE(SUM(i.delta), 0) AS delta,
                COALESCE(SUM(i.leads), 0) AS leads,
                COALESCE(SUM(i.regs), 0) AS regs,
                COALESCE(SUM(i.deps), 0) AS deps,
                COALESCE(SUM(i.revenue), 0) AS revenue,
                MIN(i.date) AS first_stat_date
            FROM ads a
            LEFT JOIN insights_daily i ON i.ad_id = a.id
            GROUP BY a.campaign_id
        ),
        campaign_rows AS (
            SELECT
                c.id::text AS campaign_id,
                c.name AS campaign_name,
                campaign_geo(c.name) AS geo,
                c.status,
                c.effective_status,
                COALESCE(
                    (c.created_time AT TIME ZONE :tz)::date,
                    ad_stats.first_stat_date,
                    (c.synced_at AT TIME ZONE :tz)::date
                ) AS launch_date,
                COALESCE(ad_stats.ads_count, 0) AS ads_count,
                COALESCE(ad_stats.impressions, 0) AS impressions,
                COALESCE(ad_stats.clicks, 0) AS clicks,
                COALESCE(ad_stats.spend, 0) AS spend,
                COALESCE(ad_stats.delta, 0) AS delta,
                COALESCE(ad_stats.leads, 0) AS leads,
                COALESCE(ad_stats.regs, 0) AS regs,
                COALESCE(ad_stats.deps, 0) AS deps,
                COALESCE(ad_stats.revenue, 0) AS revenue
            FROM campaigns c
            JOIN ad_accounts aa ON aa.id = c.ad_account_id
            LEFT JOIN ad_stats ON ad_stats.campaign_id = c.id
            WHERE aa.bm_id IN {$bmInSql}
        ),
        clean AS (
            SELECT *
            FROM campaign_rows
            WHERE launch_date IS NOT NULL
              AND geo ~ '^[A-Z]{2}$'
              {$geoSql}
        )
        SELECT
            launch_date::text AS launch_date,
            COUNT(*) AS campaigns_count,
            COUNT(*) FILTER (
                WHERE COALESCE(status, '') = 'ACTIVE'
                   OR COALESCE(effective_status, '') = 'ACTIVE'
            ) AS campaigns_active_count,
            COUNT(DISTINCT geo) AS geos_count,
            STRING_AGG(DISTINCT geo, ',' ORDER BY geo) AS geos,
            SUM(ads_count) AS ads_count,
            SUM(impressions) AS impressions,
            SUM(clicks) AS clicks,
            SUM(spend) AS spend,
            SUM(delta) AS delta,
            SUM(leads) AS leads,
            SUM(regs) AS regs,
            SUM(deps) AS deps,
            SUM(revenue) AS revenue
        FROM clean
        GROUP BY launch_date
        ORDER BY launch_date DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $impressions = (int)$r['impressions'];
        $clicks = (int)$r['clicks'];
        $spend = (float)$r['spend'];
        $leads = (int)$r['leads'];
        $regs = (int)$r['regs'];
        $deps = (int)$r['deps'];
        $revenue = (float)$r['revenue'];
        $profit = $revenue - $spend;
        $rows[] = [
            'launch_date' => (string)$r['launch_date'],
            'campaigns_count' => (int)$r['campaigns_count'],
            'campaigns_active_count' => (int)$r['campaigns_active_count'],
            'geos_count' => (int)$r['geos_count'],
            'geos' => (string)($r['geos'] ?? ''),
            'ads_count' => (int)$r['ads_count'],
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => round($spend, 2),
            'delta' => round((float)$r['delta'], 2),
            'leads' => $leads,
            'regs' => $regs,
            'deps' => $deps,
            'revenue' => round($revenue, 2),
            'profit' => round($profit, 2),
            'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : 0,
            'cpl' => $leads > 0 ? round($spend / $leads, 4) : 0,
            'cpr' => $regs > 0 ? round($spend / $regs, 4) : 0,
            'cpd' => $deps > 0 ? round($spend / $deps, 4) : 0,
            'r2d' => $regs > 0 ? round($deps / $regs * 100, 2) : 0,
            'roi' => $spend > 0 ? round($profit / $spend * 100, 2) : 0,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 4) : 0,
            'cpm' => $impressions > 0 ? round($spend / $impressions * 1000, 4) : 0,
        ];
    }

    apiOk(['rows' => $rows]);
} catch (Throwable $e) {
    apiError(500, $e->getMessage());
}
