<?php
// GET /api/geo_diff.php?bm_id=...
// GEO cost diff: today vs yesterday, 7D and 30D baselines.

require __DIR__ . '/_bootstrap.php';

$bmIds = array_map('strval', $auth->allowedBmIds($me));
if (!$bmIds) {
    apiOk([]);
}

$bmFilter = trim((string)($_GET['bm_id'] ?? ''));
if ($bmFilter !== '') {
    if (!in_array($bmFilter, $bmIds, true)) {
        apiError(403, 'BM is not allowed');
    }
    $bmIds = [$bmFilter];
}

$tz = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
$tzObj = appDateTimeZone($tz);
$now = new DateTimeImmutable('now', $tzObj);
$today = $now->format('Y-m-d');
$yesterday = $now->modify('-1 day')->format('Y-m-d');
$last7From = $now->modify('-6 days')->format('Y-m-d');
$last30From = $now->modify('-29 days')->format('Y-m-d');

$ph = [];
$params = [
    ':today' => $today,
    ':yesterday' => $yesterday,
    ':last7_from' => $last7From,
    ':last30_from' => $last30From,
];
foreach ($bmIds as $i => $id) {
    $key = ":bm_{$i}";
    $ph[] = $key;
    $params[$key] = $id;
}
$bmIn = '(' . implode(',', $ph) . ')';

$stmt = $db->prepare("
    SELECT
        COALESCE(NULLIF(campaign_geo(c.name), ''), 'XX') AS geo,
        COALESCE(SUM(i.spend) FILTER (WHERE i.date = :today), 0) AS spend_today,
        COALESCE(SUM(i.clicks) FILTER (WHERE i.date = :today), 0) AS clicks_today,
        COALESCE(SUM(i.leads) FILTER (WHERE i.date = :today), 0) AS leads_today,
        COALESCE(SUM(i.regs) FILTER (WHERE i.date = :today), 0) AS regs_today,
        COALESCE(SUM(i.deps) FILTER (WHERE i.date = :today), 0) AS deps_today,

        COALESCE(SUM(i.spend) FILTER (WHERE i.date = :yesterday), 0) AS spend_yesterday,
        COALESCE(SUM(i.clicks) FILTER (WHERE i.date = :yesterday), 0) AS clicks_yesterday,
        COALESCE(SUM(i.leads) FILTER (WHERE i.date = :yesterday), 0) AS leads_yesterday,
        COALESCE(SUM(i.regs) FILTER (WHERE i.date = :yesterday), 0) AS regs_yesterday,
        COALESCE(SUM(i.deps) FILTER (WHERE i.date = :yesterday), 0) AS deps_yesterday,

        COALESCE(SUM(i.spend) FILTER (WHERE i.date >= :last7_from AND i.date <= :today), 0) AS spend_7d,
        COALESCE(SUM(i.clicks) FILTER (WHERE i.date >= :last7_from AND i.date <= :today), 0) AS clicks_7d,
        COALESCE(SUM(i.leads) FILTER (WHERE i.date >= :last7_from AND i.date <= :today), 0) AS leads_7d,
        COALESCE(SUM(i.regs) FILTER (WHERE i.date >= :last7_from AND i.date <= :today), 0) AS regs_7d,
        COALESCE(SUM(i.deps) FILTER (WHERE i.date >= :last7_from AND i.date <= :today), 0) AS deps_7d,

        COALESCE(SUM(i.spend) FILTER (WHERE i.date >= :last30_from AND i.date <= :today), 0) AS spend_30d,
        COALESCE(SUM(i.clicks) FILTER (WHERE i.date >= :last30_from AND i.date <= :today), 0) AS clicks_30d,
        COALESCE(SUM(i.leads) FILTER (WHERE i.date >= :last30_from AND i.date <= :today), 0) AS leads_30d,
        COALESCE(SUM(i.regs) FILTER (WHERE i.date >= :last30_from AND i.date <= :today), 0) AS regs_30d,
        COALESCE(SUM(i.deps) FILTER (WHERE i.date >= :last30_from AND i.date <= :today), 0) AS deps_30d
    FROM insights_daily i
    JOIN ads a ON a.id = i.ad_id
    JOIN campaigns c ON c.id = a.campaign_id
    JOIN ad_accounts aa ON aa.id = a.ad_account_id
    WHERE i.date >= :last30_from
      AND i.date <= :today
      AND aa.bm_id IN {$bmIn}
    GROUP BY 1
    HAVING COALESCE(SUM(i.spend), 0) > 0
    ORDER BY spend_today DESC, geo
");
$stmt->execute($params);

$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $todayRow = payload($r, 'today', 1);
    $yesterdayRow = payload($r, 'yesterday', 1);
    $last7Row = payload($r, '7d', 7);
    $last30Row = payload($r, '30d', 30);
    $rows[] = [
        'geo' => $r['geo'],
        'today' => $todayRow,
        'yesterday' => $yesterdayRow,
        'last7' => $last7Row,
        'last30' => $last30Row,
        'diff_yesterday' => diffs($todayRow, $yesterdayRow),
        'diff_7d' => diffs($todayRow, $last7Row),
        'diff_30d' => diffs($todayRow, $last30Row),
    ];
}

apiOk($rows, [
    'date_today' => $today,
    'date_yesterday' => $yesterday,
    'date_7d_from' => $last7From,
    'date_7d_to' => $today,
    'date_30d_from' => $last30From,
    'date_30d_to' => $today,
]);

function payload(array $r, string $suffix, int $days): array
{
    $spend = (float)($r["spend_{$suffix}"] ?? 0);
    $clicks = (float)($r["clicks_{$suffix}"] ?? 0);
    $leads = (float)($r["leads_{$suffix}"] ?? 0);
    $regs = (float)($r["regs_{$suffix}"] ?? 0);
    $deps = (float)($r["deps_{$suffix}"] ?? 0);
    return [
        'spend' => round($spend, 4),
        'spend_daily' => round($spend / max(1, $days), 4),
        'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : null,
        'cpl' => $leads > 0 ? round($spend / $leads, 4) : null,
        'cpr' => $regs > 0 ? round($spend / $regs, 4) : null,
        'cpd' => $deps > 0 ? round($spend / $deps, 4) : null,
        'r2d' => $regs > 0 ? round($deps / $regs * 100, 4) : null,
    ];
}

function diffs(array $today, array $base): array
{
    return [
        'spend' => pctDiff($today['spend'], $base['spend_daily']),
        'cpc' => pctDiff($today['cpc'], $base['cpc']),
        'cpl' => pctDiff($today['cpl'], $base['cpl']),
        'cpr' => pctDiff($today['cpr'], $base['cpr']),
        'cpd' => pctDiff($today['cpd'], $base['cpd']),
        'r2d' => pctDiff($today['r2d'], $base['r2d']),
    ];
}

function pctDiff(mixed $value, mixed $base): ?float
{
    if ($value === null || $base === null || (float)$base == 0.0) {
        return null;
    }
    return round(((float)$value - (float)$base) / abs((float)$base) * 100, 2);
}
