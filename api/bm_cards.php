<?php
// GET /api/bm_cards.php?period=7d|14d|30d
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$allowedBmIds = array_map('strval', $auth->allowedBmIds($me));
if (!$allowedBmIds) {
    apiOk(['rows' => [], 'period' => '14d']);
}

try {
$tz = appTimezoneName($me['display_tz'] ?? 'Europe/Chisinau');
$tzObj = appDateTimeZone($tz);
$now = new DateTime('now', $tzObj);

$period = (string)($_GET['period'] ?? '14d');
if ($period === 'yesterday_today') {
    $days = 2;
    $currentFrom = (clone $now)->modify('yesterday midnight');
    $currentTo = (clone $now);
} else {
    $days = match ($period) {
        '7d' => 7,
        '30d' => 30,
        default => 14,
    };
    $period = $days . 'd';
    $currentFrom = (clone $now)->modify('-' . ($days - 1) . ' days midnight');
    $currentTo = (clone $now);
}
$previousTo = (clone $currentFrom)->modify('-1 second');
$previousFrom = (clone $currentFrom)->modify('-' . $days . ' days');

$bmPh = [];
$bmParams = [];
$params = [
    ':cur_from' => $currentFrom->format('Y-m-d'),
    ':cur_to' => $currentTo->format('Y-m-d'),
    ':prev_from' => $previousFrom->format('Y-m-d'),
    ':prev_to' => $previousTo->format('Y-m-d'),
];
foreach ($allowedBmIds as $i => $bmId) {
    $key = ':bm_' . $i;
    $bmPh[] = $key;
    $params[$key] = $bmId;
    $bmParams[$key] = $bmId;
}
$bmIn = '(' . implode(',', $bmPh) . ')';

$bmStmt = $db->prepare("
        SELECT
            bm.id::text AS bm_id,
            bm.name AS bm_name,
        COUNT(DISTINCT aa.id) AS accounts_total,
        COUNT(DISTINCT aa.id) FILTER (WHERE aa.status = 1) AS accounts_active,
        COUNT(DISTINCT c.id) FILTER (WHERE aa.status = 1) AS campaigns_total,
        COUNT(DISTINCT c.id) FILTER (
            WHERE aa.status = 1
              AND c.status = 'ACTIVE'
              AND COALESCE(NULLIF(c.effective_status, ''), 'ACTIVE') = 'ACTIVE'
        ) AS campaigns_active
    FROM business_managers bm
    LEFT JOIN ad_accounts aa ON aa.bm_id = bm.id
    LEFT JOIN campaigns c ON c.ad_account_id = aa.id AND c.status != 'DELETED'
    WHERE bm.id::text IN {$bmIn}
    GROUP BY bm.id, bm.name
    HAVING COUNT(DISTINCT aa.id) FILTER (WHERE aa.status = 1) > 0
        OR COUNT(DISTINCT c.id) FILTER (
            WHERE aa.status = 1
              AND c.status = 'ACTIVE'
              AND COALESCE(NULLIF(c.effective_status, ''), 'ACTIVE') = 'ACTIVE'
        ) > 0
    ORDER BY bm.name
");
$bmStmt->execute($bmParams);
$rowsByBm = [];
foreach ($bmStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $bmId = (string)$row['bm_id'];
    $rowsByBm[$bmId] = [
        'bm_id' => $bmId,
        'bm_name' => (string)$row['bm_name'],
        'accounts_total' => (int)$row['accounts_total'],
        'accounts_active' => (int)$row['accounts_active'],
        'campaigns_total' => (int)$row['campaigns_total'],
        'campaigns_active' => (int)$row['campaigns_active'],
        'current' => emptyMetrics(),
        'previous' => emptyMetrics(),
        'delta' => ['spend_pct' => null, 'profit_pct' => null],
        'days' => [],
        'geos' => [],
        'signal' => 'No data',
        'priority' => 0,
    ];
}

$metricsStmt = $db->prepare("
        SELECT
            aa.bm_id::text AS bm_id,
            CASE WHEN i.date BETWEEN :metrics_cur_from AND :metrics_cur_to THEN 'current' ELSE 'previous' END AS bucket,
            SUM(i.spend) AS spend,
            SUM(i.delta) AS delta,
            SUM(i.impressions) AS impressions,
        SUM(i.clicks) AS clicks,
        SUM(i.leads) AS leads,
        SUM(i.regs) AS regs,
        SUM(i.deps) AS deps,
        SUM(i.revenue) AS revenue
    FROM insights_daily i
    JOIN ads a ON a.id = i.ad_id
    JOIN ad_accounts aa ON aa.id = a.ad_account_id
    WHERE aa.bm_id::text IN {$bmIn}
      AND i.date BETWEEN :metrics_prev_from AND :metrics_filter_to
    GROUP BY aa.bm_id, bucket
");
$metricsParams = $bmParams + [
    ':metrics_cur_from' => $params[':cur_from'],
    ':metrics_cur_to' => $params[':cur_to'],
    ':metrics_prev_from' => $params[':prev_from'],
    ':metrics_filter_to' => $params[':cur_to'],
];
$metricsStmt->execute($metricsParams);
foreach ($metricsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $bmId = (string)$row['bm_id'];
    if (!isset($rowsByBm[$bmId])) continue;
    $bucket = $row['bucket'] === 'previous' ? 'previous' : 'current';
    $rowsByBm[$bmId][$bucket] = normalizeMetrics($row);
}

$daysStmt = $db->prepare("
        SELECT
            aa.bm_id::text AS bm_id,
            i.date::text AS day,
            SUM(i.spend) AS spend,
            SUM(i.delta) AS delta,
            SUM(i.revenue) AS revenue,
        SUM(i.revenue) - SUM(i.spend) AS profit
    FROM insights_daily i
    JOIN ads a ON a.id = i.ad_id
    JOIN ad_accounts aa ON aa.id = a.ad_account_id
    WHERE aa.bm_id::text IN {$bmIn}
      AND i.date BETWEEN :cur_from AND :cur_to
    GROUP BY aa.bm_id, i.date
    ORDER BY i.date
");
$daysParams = $bmParams + [
    ':cur_from' => $params[':cur_from'],
    ':cur_to' => $params[':cur_to'],
];
$daysStmt->execute($daysParams);
foreach ($daysStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $bmId = (string)$row['bm_id'];
    if (!isset($rowsByBm[$bmId])) continue;
    $rowsByBm[$bmId]['days'][] = [
        'date' => (string)$row['day'],
        'spend' => round((float)$row['spend'], 4),
        'delta' => round((float)$row['delta'], 4),
        'revenue' => round((float)$row['revenue'], 4),
        'profit' => round((float)$row['profit'], 4),
    ];
}

$geoStmt = $db->prepare("
    WITH geo_rows AS (
        SELECT
            aa.bm_id::text AS bm_id,
            CASE
                WHEN campaign_geo(c.name) <> 'XX' THEN campaign_geo(c.name)
                ELSE 'XX'
            END AS geo,
            CASE WHEN i.date BETWEEN :geo_cur_from AND :geo_cur_to THEN 'current' ELSE 'previous' END AS bucket,
            SUM(i.spend) AS spend,
            SUM(i.delta) AS delta,
            SUM(i.revenue) AS revenue,
            SUM(i.deps) AS deps
        FROM insights_daily i
        JOIN ads a ON a.id = i.ad_id
        JOIN campaigns c ON c.id = a.campaign_id
        JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE aa.bm_id::text IN {$bmIn}
          AND i.date BETWEEN :geo_prev_from AND :geo_filter_to
        GROUP BY aa.bm_id, geo, bucket
    )
    SELECT
        bm_id,
        geo,
        SUM(spend) FILTER (WHERE bucket = 'current') AS spend,
        SUM(revenue) FILTER (WHERE bucket = 'current') AS revenue,
        SUM(deps) FILTER (WHERE bucket = 'current') AS deps,
        SUM(spend) FILTER (WHERE bucket = 'previous') AS prev_spend,
        SUM(revenue) FILTER (WHERE bucket = 'previous') AS prev_revenue
    FROM geo_rows
    GROUP BY bm_id, geo
");
$geoParams = $bmParams + [
    ':geo_cur_from' => $params[':cur_from'],
    ':geo_cur_to' => $params[':cur_to'],
    ':geo_prev_from' => $params[':prev_from'],
    ':geo_filter_to' => $params[':cur_to'],
];
$geoStmt->execute($geoParams);
$geosByBm = [];
foreach ($geoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $bmId = (string)$row['bm_id'];
    $spend = (float)($row['spend'] ?? 0);
    $revenue = (float)($row['revenue'] ?? 0);
    $profit = $revenue - $spend;
    $prevSpend = (float)($row['prev_spend'] ?? 0);
    $prevRevenue = (float)($row['prev_revenue'] ?? 0);
    $prevProfit = $prevRevenue - $prevSpend;
    $geosByBm[$bmId][] = [
        'geo' => (string)$row['geo'],
        'spend' => round($spend, 4),
        'delta' => round((float)($row['delta'] ?? 0), 4),
        'revenue' => round($revenue, 4),
        'profit' => round($profit, 4),
        'roi' => $spend > 0 ? round($profit / $spend * 100, 2) : null,
        'deps' => (int)($row['deps'] ?? 0),
        'spend_delta_pct' => pctDelta($spend, $prevSpend),
        'profit_delta_pct' => pctDelta($profit, $prevProfit),
    ];
}

foreach ($rowsByBm as $bmId => &$row) {
    finalizeMetrics($row['current']);
    finalizeMetrics($row['previous']);
    $row['delta'] = [
        'spend_pct' => pctDelta($row['current']['spend'], $row['previous']['spend']),
        'profit_pct' => pctDelta($row['current']['profit'], $row['previous']['profit']),
    ];
    $geos = $geosByBm[$bmId] ?? [];
    usort($geos, fn($a, $b) => abs((float)$b['profit']) <=> abs((float)$a['profit']));
    $row['geos'] = array_slice($geos, 0, 6);
    [$signal, $priority] = signalFor($row);
    $row['signal'] = $signal;
    $row['priority'] = $priority;
}
unset($row);

$rows = array_values($rowsByBm);
usort($rows, fn($a, $b) =>
    ((float)$b['current']['spend'] <=> (float)$a['current']['spend'])
    ?: strcmp((string)$a['bm_name'], (string)$b['bm_name'])
);

apiOk([
    'period' => $period,
    'days' => $days,
    'date_from' => $currentFrom->format('Y-m-d'),
    'date_to' => $currentTo->format('Y-m-d'),
    'prev_date_from' => $previousFrom->format('Y-m-d'),
    'prev_date_to' => $previousTo->format('Y-m-d'),
    'rows' => $rows,
]);
} catch (Throwable $e) {
    error_log('[bm_cards] ' . $e->getMessage());
    apiError(500, $e->getMessage());
}

function emptyMetrics(): array
{
    return ['spend' => 0.0, 'delta' => 0.0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0, 'regs' => 0, 'deps' => 0, 'revenue' => 0.0, 'profit' => 0.0, 'roi' => null];
}

function normalizeMetrics(array $row): array
{
    return [
        'spend' => round((float)($row['spend'] ?? 0), 4),
        'delta' => round((float)($row['delta'] ?? 0), 4),
        'impressions' => (int)($row['impressions'] ?? 0),
        'clicks' => (int)($row['clicks'] ?? 0),
        'leads' => (int)($row['leads'] ?? 0),
        'regs' => (int)($row['regs'] ?? 0),
        'deps' => (int)($row['deps'] ?? 0),
        'revenue' => round((float)($row['revenue'] ?? 0), 4),
        'profit' => 0.0,
        'roi' => null,
    ];
}

function finalizeMetrics(array &$m): void
{
    $m['profit'] = round((float)$m['revenue'] - (float)$m['spend'], 4);
    $m['roi'] = (float)$m['spend'] > 0 ? round($m['profit'] / (float)$m['spend'] * 100, 2) : null;
}

function pctDelta(float $current, float $previous): ?float
{
    if (abs($previous) < 0.00001) {
        return abs($current) < 0.00001 ? 0.0 : null;
    }
    return round(($current - $previous) / abs($previous) * 100, 2);
}

function signalFor(array $row): array
{
    $spendDelta = $row['delta']['spend_pct'];
    $profitDelta = $row['delta']['profit_pct'];
    $spend = (float)$row['current']['spend'];
    $profit = (float)$row['current']['profit'];

    if ($spend <= 0 && abs($profit) < 0.00001) return ['No data', 0];
    if ($profit < 0 && $spend > 0) return ['Loss', 80 + min(20, (int)round($spend / 100))];
    if ($spendDelta !== null && $profitDelta !== null && $spendDelta > 10 && $profitDelta < -5) return ['Risk', 100];
    if ($spendDelta !== null && $profitDelta !== null && $spendDelta > 5 && $profitDelta > 5) return ['Growing', 70];
    if ($spendDelta !== null && $profitDelta !== null && $spendDelta < -5 && $profitDelta > 5) return ['Recovering', 60];
    if ($spendDelta !== null && $profitDelta !== null && $spendDelta < -5 && $profitDelta < -5) return ['Cooling', 40];
    return [$profit >= 0 ? 'Stable' : 'Watch', $profit >= 0 ? 30 : 50];
}
