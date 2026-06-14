<?php
// api/totals.php
// GET /api/totals.php?range=today
// Returns aggregated data from insights_daily WITHOUT JOIN on ads/campaigns
// Used to compute orphan rows in Creatives and Geo

require __DIR__.'/_bootstrap.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk(['spend'=>0,'impressions'=>0,'clicks'=>0,'leads'=>0,'regs'=>0,'deps'=>0,'revenue'=>0]);

$tz      = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
$range   = $_GET['range']  ?? 'today';
$bmFilter = $_GET['bm_id'] ?? null; // optional BM filter

$tzObj = appDateTimeZone($tz);
$now   = new DateTime('now', $tzObj);

switch ($range) {
    case 'yesterday':
        $dtFrom = (clone $now)->modify('yesterday midnight');
        $dtTo   = (clone $now)->modify('yesterday 23:59:59');
        break;
    case 'yesterday_today':
        $dtFrom = (clone $now)->modify('yesterday midnight');
        $dtTo   = $now; break;
    case '3d':
        $dtFrom = (clone $now)->modify('-2 days midnight');
        $dtTo   = $now; break;
    case '7d':
        $dtFrom = (clone $now)->modify('-6 days midnight');
        $dtTo   = $now; break;
    case '14d':
        $dtFrom = (clone $now)->modify('-13 days midnight');
        $dtTo   = $now; break;
    case 'this_week':
        $dtFrom = (clone $now)->modify('monday this week midnight');
        $dtTo   = $now; break;
    case 'this_month':
        $dtFrom = (clone $now)->modify('first day of this month midnight');
        $dtTo   = $now; break;
    case 'last_month':
        $dtFrom = (clone $now)->modify('first day of last month midnight');
        $dtTo   = (clone $now)->modify('last day of last month 23:59:59'); break;
    case '30d':
        $dtFrom = (clone $now)->modify('-29 days midnight');
        $dtTo   = $now; break;
    case '90d':
        $dtFrom = (clone $now)->modify('-89 days midnight');
        $dtTo   = $now; break;
    case 'this_year':
        $dtFrom = (clone $now)->modify('first day of january this year midnight');
        $dtTo   = $now; break;
    case 'all':
        $dtFrom = new DateTime('2020-01-01', $tzObj);
        $dtTo   = $now; break;
    default: // today
        $dtFrom = (clone $now)->modify('midnight');
        $dtTo   = $now;
}

$dateFrom = $dtFrom->format('Y-m-d');
$dateTo   = $dtTo->format('Y-m-d');

// If bm_id is set, filter via JOIN with ads/ad_accounts
if ($bmFilter) {
    $row = $db->prepare("
        SELECT
            COALESCE(SUM(id.spend),       0) AS spend,
            COALESCE(SUM(id.delta),       0) AS delta,
            COALESCE(SUM(id.impressions), 0) AS impressions,
            COALESCE(SUM(id.clicks),      0) AS clicks,
            COALESCE(SUM(id.leads),       0) AS leads,
            COALESCE(SUM(id.regs),        0) AS regs,
            COALESCE(SUM(id.deps),        0) AS deps,
            COALESCE(SUM(id.revenue),     0) AS revenue
        FROM insights_daily id
        JOIN ads a           ON a.id  = id.ad_id
        JOIN ad_accounts aa  ON aa.id = a.ad_account_id
        WHERE id.date >= :date_from AND id.date <= :date_to
          AND aa.bm_id = :bm_id
    ");
    $row->execute([':date_from' => $dateFrom, ':date_to' => $dateTo, ':bm_id' => $bmFilter]);
} else {
    // Count ALL insights_daily rows without JOIN, including orphan rows
    $row = $db->prepare("
        SELECT
            COALESCE(SUM(spend),       0) AS spend,
            COALESCE(SUM(delta),       0) AS delta,
            COALESCE(SUM(impressions), 0) AS impressions,
            COALESCE(SUM(clicks),      0) AS clicks,
            COALESCE(SUM(leads),       0) AS leads,
            COALESCE(SUM(regs),        0) AS regs,
            COALESCE(SUM(deps),        0) AS deps,
            COALESCE(SUM(revenue),     0) AS revenue
        FROM insights_daily
        WHERE date >= :date_from AND date <= :date_to
    ");
    $row->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
}
$totals = $row->fetch(PDO::FETCH_ASSOC);

apiOk([
    'spend'       => (float)$totals['spend'],
    'delta'       => (float)$totals['delta'],
    'impressions' => (int)  $totals['impressions'],
    'clicks'      => (int)  $totals['clicks'],
    'leads'       => (int)  $totals['leads'],
    'regs'        => (int)  $totals['regs'],
    'deps'        => (int)  $totals['deps'],
    'revenue'     => (float)$totals['revenue'],
], [
    'range'     => $range,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
]);
