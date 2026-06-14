<?php
// api/monthly.php
// GET /api/monthly.php
// Daily statistics for a selected month from insights_daily

require __DIR__.'/_bootstrap.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk([]);

$tz    = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
$accId = $_GET['account_id'] ?? null;
$bmId  = $_GET['bm_id']      ?? null;

$tzObj  = appDateTimeZone($tz);
$now    = new DateTime('now', $tzObj);
$period = $_GET['period'] ?? 'current';
$listMonths = (string)($_GET['list'] ?? '') === '1';

if ($listMonths) {
    $dtFrom = new DateTime('1970-01-01 00:00:00', $tzObj);
    $dtTo   = $now;
} elseif ($period === 'previous') {
    $dtFrom = (clone $now)->modify('first day of last month midnight');
    $dtTo   = (clone $now)->modify('last day of last month midnight');
} elseif ($period === 'last30') {
    $dtFrom = (clone $now)->modify('-29 days midnight');
    $dtTo   = $now;
} elseif (preg_match('/^\d{4}-\d{2}$/', $period)) {
    $dtFrom = DateTime::createFromFormat('Y-m-d H:i:s', $period . '-01 00:00:00', $tzObj) ?: (clone $now);
    $dtTo   = (clone $dtFrom)->modify('last day of this month midnight');
} else {
    $dtFrom = (clone $now)->modify('first day of this month midnight');
    $dtTo   = $now;
}

$dateFrom = $dtFrom->format('Y-m-d');
$dateTo   = $dtTo->format('Y-m-d');

$bmParams = [];
$bmPh     = [];
foreach ($bmIds as $i => $v) {
    $key            = ":bm_{$i}";
    $bmPh[]         = $key;
    $bmParams[$key] = $v;
}
$bmInSql = '(' . implode(',', $bmPh) . ')';

$extraFilter = '';
$extraParams = [];

if ($bmId) {
    $ids = array_filter(array_map('trim', explode(',', $bmId)));
    if ($ids) {
        $ph = [];
        foreach ($ids as $j => $v) {
            $k               = ":flt_bm_{$j}";
            $ph[]            = $k;
            $extraParams[$k] = $v;
        }
        $extraFilter .= ' AND aa.bm_id IN (' . implode(',', $ph) . ')';
    }
}
if ($accId) {
    $extraFilter            .= ' AND a.ad_account_id = :flt_acc';
    $extraParams[':flt_acc'] = $accId;
}

// Geo filter: supports multiple geos separated by commas
$geo = $_GET['geo'] ?? null;
if ($geo) {
    $geos = array_filter(array_map('trim', explode(',', strtoupper($geo))));
    $geoConds = [];
    foreach ($geos as $i => $g) {
        $p1 = ":geo_{$i}_1"; $p2 = ":geo_{$i}_2"; $p3 = ":geo_{$i}_3";
        $geoConds[] = "(c.name ILIKE {$p1} OR c.name ILIKE {$p2} OR c.name ILIKE {$p3})";
        $extraParams[$p1] = '%\\_' . $g . '\\_%';
        $extraParams[$p2] = '%\\_' . $g;
        $extraParams[$p3] = '%\\_' . $g . ' %';
    }
    if ($geoConds) $extraFilter .= ' AND (' . implode(' OR ', $geoConds) . ')';
}

if ($listMonths) {
    $sql = "
            SELECT
                TO_CHAR(id.date, 'YYYY-MM') AS month_key,
                SUM(id.spend)               AS spend,
                SUM(id.delta)               AS delta,
                SUM(id.impressions)         AS impressions,
                SUM(id.clicks)              AS clicks,
                SUM(id.leads)               AS leads,
                SUM(id.regs)                AS regs,
                SUM(id.deps)                AS deps,
                SUM(id.revenue)             AS revenue
        FROM insights_daily id
        LEFT JOIN ads a          ON a.id  = id.ad_id
        LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
        LEFT JOIN campaigns c    ON c.id  = a.campaign_id
        WHERE id.date >= :date_from
          AND id.date <= :date_to
          AND (aa.bm_id IN {$bmInSql} OR aa.bm_id IS NULL)
          {$extraFilter}
        GROUP BY TO_CHAR(id.date, 'YYYY-MM')
        ORDER BY month_key DESC
    ";
} else {
    $sql = "
            SELECT
                id.date                 AS day,
                SUM(id.spend)           AS spend,
                SUM(id.delta)           AS delta,
                SUM(id.impressions)     AS impressions,
                SUM(id.clicks)          AS clicks,
                SUM(id.leads)           AS leads,
            SUM(id.regs)            AS regs,
            SUM(id.deps)            AS deps,
            SUM(id.revenue)         AS revenue
        FROM insights_daily id
        LEFT JOIN ads a          ON a.id  = id.ad_id
        LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
        LEFT JOIN campaigns c    ON c.id  = a.campaign_id
        WHERE id.date >= :date_from
          AND id.date <= :date_to
          AND (aa.bm_id IN {$bmInSql} OR aa.bm_id IS NULL)
          {$extraFilter}
        GROUP BY id.date
        ORDER BY id.date ASC
    ";
}

$params = array_merge(
    [':date_from' => $dateFrom, ':date_to' => $dateTo],
    $bmParams,
    $extraParams
);

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    apiError(500, 'DB error: ' . $e->getMessage());
}

if ($listMonths) {
    $data = array_map(function(array $r) use ($tzObj): array {
        $monthKey = $r['month_key'];
        $start = DateTime::createFromFormat('Y-m-d H:i:s', $monthKey . '-01 00:00:00', $tzObj) ?: new DateTime($monthKey . '-01');
        $end = (clone $start)->modify('last day of this month');
        $spend  = (float)$r['spend'];
        $delta  = (float)$r['delta'];
        $impr   = (int)  $r['impressions'];
        $clicks = (int)  $r['clicks'];
        $leads  = (int)  $r['leads'];
        $regs   = (int)  $r['regs'];
        $deps   = (int)  $r['deps'];
        $rev    = (float)$r['revenue'];
        $profit = $rev - $spend;
        return [
            'month_key'   => $monthKey,
            'month_label'  => $start->format('F Y'),
            'date_from'    => $start->format('Y-m-d'),
            'date_to'      => $end->format('Y-m-d'),
            'impressions'  => $impr,
            'clicks'       => $clicks,
            'cpc'          => $clicks > 0 ? round($spend / $clicks, 4) : 0,
            'leads'        => $leads,
            'regs'         => $regs,
            'deps'         => $deps,
            'spend'        => round($spend, 2),
            'delta'        => round($delta, 2),
            'revenue'      => round($rev, 2),
            'profit'       => round($profit, 2),
            'roi'          => $spend > 0 ? round($profit / $spend * 100, 2) : 0,
            'ctr'          => $impr > 0 ? round($clicks / $impr * 100, 4) : 0,
            'cpm'          => $impr > 0 ? round($spend / $impr * 1000, 4) : 0,
            'cpl'          => $leads > 0 ? round($spend / $leads, 2) : 0,
            'cpr'          => $regs > 0 ? round($spend / $regs, 2) : 0,
            'cpd'          => $deps > 0 ? round($spend / $deps, 2) : 0,
        ];
    }, $rows);
} else {
    $data = array_map(function(array $r): array {
    $spend  = (float)$r['spend'];
    $delta  = (float)$r['delta'];
    $impr   = (int)  $r['impressions'];
    $clicks = (int)  $r['clicks'];
    $leads  = (int)  $r['leads'];
    $regs   = (int)  $r['regs'];
    $deps   = (int)  $r['deps'];
    $rev    = (float)$r['revenue'];
    $profit = $rev - $spend;
    return [
        'day'         => $r['day'],
        'impressions' => $impr,
        'clicks'      => $clicks,
            'cpc'         => $clicks > 0 ? round($spend / $clicks, 4) : 0,
        'leads'       => $leads,
        'regs'        => $regs,
        'deps'        => $deps,
        'spend'       => round($spend,  2),
        'delta'       => round($delta,  2),
        'revenue'     => round($rev,    2),
        'profit'      => round($profit, 2),
        'roi'         => $spend > 0 ? round($profit / $spend * 100, 2) : 0,
        'ctr'         => $impr  > 0 ? round($clicks / $impr  * 100,  4) : 0,
        'cpm'         => $impr  > 0 ? round($spend  / $impr  * 1000, 4) : 0,
        'cpl'         => $leads > 0 ? round($spend  / $leads, 2) : 0,
        'cpr'         => $regs  > 0 ? round($spend  / $regs,  2) : 0,
        'cpd'         => $deps  > 0 ? round($spend  / $deps,  2) : 0,
    ];
    }, $rows);
}

if ($listMonths) {
    apiOk($data, [
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'tz'        => $tz,
        'count'     => count($data),
        'mode'      => 'months',
    ]);
}

apiOk($data, [
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'tz'        => $tz,
    'count'     => count($data),
]);
