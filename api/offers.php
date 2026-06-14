<?php
// GET /api/offers.php?range=30d&group=offer|day|geo|creative

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
if (!$exists) {
    apiOk(['rows' => [], 'totals' => emptyOfferTotals(), 'installed' => false]);
}

$tz = appTimezoneName($me['display_tz'] ?? 'Europe/Chisinau');
$tzObj = appDateTimeZone($tz);
$now = new DateTime('now', $tzObj);
$range = $_GET['range'] ?? '30d';

switch ($range) {
    case 'today':
        $dtFrom = (clone $now)->modify('midnight');
        $dtTo = $now;
        break;
    case 'yesterday':
        $dtFrom = (clone $now)->modify('yesterday midnight');
        $dtTo = (clone $now)->modify('yesterday 23:59:59');
        break;
    case 'yesterday_today':
        $dtFrom = (clone $now)->modify('yesterday midnight');
        $dtTo = $now;
        break;
    case '3d':
        $dtFrom = (clone $now)->modify('-2 days midnight');
        $dtTo = $now;
        break;
    case '7d':
        $dtFrom = (clone $now)->modify('-6 days midnight');
        $dtTo = $now;
        break;
    case '14d':
        $dtFrom = (clone $now)->modify('-13 days midnight');
        $dtTo = $now;
        break;
    case 'this_week':
        $dtFrom = (clone $now)->modify('monday this week midnight');
        $dtTo = $now;
        break;
    case 'this_month':
        $dtFrom = (clone $now)->modify('first day of this month midnight');
        $dtTo = $now;
        break;
    case 'last_month':
        $dtFrom = (clone $now)->modify('first day of last month midnight');
        $dtTo = (clone $now)->modify('last day of last month 23:59:59');
        break;
    case '90d':
        $dtFrom = (clone $now)->modify('-89 days midnight');
        $dtTo = $now;
        break;
    case 'this_year':
        $dtFrom = (clone $now)->modify('first day of january this year midnight');
        $dtTo = $now;
        break;
    case 'all':
        $dtFrom = new DateTime('2000-01-01 00:00:00', $tzObj);
        $dtTo = $now;
        break;
    case 'custom':
        $dtFrom = new DateTime((string)($_GET['date_from'] ?? $now->format('Y-m-d')), $tzObj);
        $dtTo = new DateTime((string)($_GET['date_to'] ?? $now->format('Y-m-d')), $tzObj);
        break;
    case '30d':
    default:
        $range = '30d';
        $dtFrom = (clone $now)->modify('-29 days midnight');
        $dtTo = $now;
        break;
}

$dateFrom = $dtFrom->format('Y-m-d');
$dateTo = $dtTo->format('Y-m-d');
$group = $_GET['group'] ?? 'offer';

$groupMap = [
    'offer' => [
        'select' => "oi.offer_id, oi.offer_name, oi.affiliate_network",
        'group' => "oi.offer_id, oi.offer_name, oi.affiliate_network",
        'order' => "profit DESC NULLS LAST, revenue DESC",
    ],
    'day' => [
        'select' => "oi.date::text AS date",
        'group' => "oi.date",
        'order' => "oi.date ASC",
    ],
    'geo' => [
        'select' => "oi.geo",
        'group' => "oi.geo",
        'order' => "profit DESC NULLS LAST, revenue DESC",
    ],
    'creative' => [
        'select' => "oi.fb_ad_name, oi.fb_adset_name, oi.fb_campaign_name",
        'group' => "oi.fb_ad_name, oi.fb_adset_name, oi.fb_campaign_name",
        'order' => "profit DESC NULLS LAST, revenue DESC",
    ],
];
if (!isset($groupMap[$group])) {
    $group = 'offer';
}

$allowedBmIds = array_map('strval', $auth->allowedBmIds($me));
if (!$allowedBmIds) {
    apiOk(['rows' => [], 'totals' => emptyOfferTotals(), 'installed' => true]);
}

$params = [
    ':date_from' => $dateFrom,
    ':date_to' => $dateTo,
];
$where = ["oi.date BETWEEN :date_from AND :date_to"];

if (!empty($_GET['bm_id'])) {
    $bmId = trim((string)$_GET['bm_id']);
    if (!in_array($bmId, $allowedBmIds, true)) {
        apiError(403, 'BM is not allowed');
    }
    $where[] = 'aa.bm_id::text = :bm_filter';
    $params[':bm_filter'] = $bmId;
} elseif (($me['role'] ?? '') !== 'admin') {
    $bmPh = [];
    foreach ($allowedBmIds as $i => $id) {
        $key = ":bm{$i}";
        $bmPh[] = $key;
        $params[$key] = $id;
    }
    $where[] = 'aa.bm_id::text IN (' . implode(',', $bmPh) . ')';
}

$filterMap = [
    'offer_id' => 'oi.offer_id',
    'geo' => 'oi.geo',
    'account_id' => 'aa.id',
    'campaign_id' => 'oi.fb_campaign_id',
    'adset_id' => 'oi.fb_adset_id',
    'ad_id' => 'oi.ad_id',
];
foreach ($filterMap as $param => $column) {
    if (!empty($_GET[$param])) {
        $values = array_values(array_filter(array_map('trim', explode(',', (string)$_GET[$param]))));
        if (!$values) {
            continue;
        }
        $ph = [];
        foreach ($values as $i => $value) {
            $key = ":{$param}_{$i}";
            $ph[] = $key;
            $params[$key] = $value;
        }
        $where[] = "{$column} IN (" . implode(',', $ph) . ")";
    }
}

$whereSql = implode(' AND ', $where);
$select = $groupMap[$group]['select'];
$groupBy = $groupMap[$group]['group'];
$orderBy = $groupMap[$group]['order'];

$metricSql = "
    SUM(oi.clicks) AS clicks,
    SUM(oi.regs) AS regs,
    SUM(oi.deps) AS deps,
    SUM(oi.conversions) AS conversions,
    SUM(oi.revenue) AS revenue,
    SUM(oi.allocated_spend) AS spend,
    SUM(oi.revenue) - SUM(oi.allocated_spend) AS profit,
    CASE WHEN SUM(oi.allocated_spend) > 0
         THEN (SUM(oi.revenue) - SUM(oi.allocated_spend)) / SUM(oi.allocated_spend) * 100
         ELSE NULL
    END AS roi,
    CASE WHEN SUM(oi.clicks) > 0 THEN SUM(oi.allocated_spend) / SUM(oi.clicks) ELSE NULL END AS cpl,
    CASE WHEN SUM(oi.regs) > 0 THEN SUM(oi.allocated_spend) / SUM(oi.regs) ELSE NULL END AS cpr,
    CASE WHEN SUM(oi.deps) > 0 THEN SUM(oi.allocated_spend) / SUM(oi.deps) ELSE NULL END AS cpd
";

$stmt = $db->prepare("
    SELECT {$select}, {$metricSql}
    FROM offer_insights_daily oi
    LEFT JOIN ads a ON a.id::text = oi.ad_id
    LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
    WHERE {$whereSql}
    GROUP BY {$groupBy}
    ORDER BY {$orderBy}
    LIMIT 1000
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalsStmt = $db->prepare("
    SELECT {$metricSql}
    FROM offer_insights_daily oi
    LEFT JOIN ads a ON a.id::text = oi.ad_id
    LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
    WHERE {$whereSql}
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: emptyOfferTotals();

apiOk([
    'installed' => true,
    'group' => $group,
    'range' => $range,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'rows' => array_map('normalizeOfferRow', $rows),
    'totals' => normalizeOfferRow($totals),
]);

function emptyOfferTotals(): array
{
    return [
        'clicks' => 0,
        'regs' => 0,
        'deps' => 0,
        'conversions' => 0,
        'revenue' => 0,
        'spend' => 0,
        'profit' => 0,
        'roi' => null,
        'cpl' => null,
        'cpr' => null,
        'cpd' => null,
    ];
}

function normalizeOfferRow(array $row): array
{
    foreach (['clicks', 'regs', 'deps', 'conversions'] as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = (int)$row[$key];
        }
    }
    foreach (['revenue', 'spend', 'profit', 'roi', 'cpl', 'cpr', 'cpd'] as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = $row[$key] === null ? null : round((float)$row[$key], 4);
        }
    }
    return $row;
}
