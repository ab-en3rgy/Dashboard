<?php
// GET /api/offers_debug.php?days=7
// Admin-only diagnostics for the parallel Keitaro offer analytics contour.

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if (($me['role'] ?? '') !== 'admin') {
    apiError(403, 'Admin only');
}

$exists = $db->query("SELECT to_regclass('public.offer_insights_daily')")->fetchColumn();
if (!$exists) {
    apiOk([
        'installed' => false,
        'message' => 'offer_insights_daily does not exist yet',
    ]);
}

$days = max(1, min(365, (int)($_GET['days'] ?? 7)));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-d', strtotime($dateTo . " -" . ($days - 1) . " days"))));

$params = [
    ':date_from' => $dateFrom,
    ':date_to' => $dateTo,
];

$summaryStmt = $db->prepare("
    WITH rows AS (
        SELECT *
        FROM offer_insights_daily
        WHERE date BETWEEN :date_from AND :date_to
    ),
    ad_days AS (
        SELECT date, ad_id, MAX(source_ad_spend) AS source_ad_spend
        FROM rows
        GROUP BY date, ad_id
    )
    SELECT
        (SELECT COUNT(*) FROM rows) AS rows,
        (SELECT COUNT(DISTINCT offer_id) FROM rows) AS offers,
        (SELECT COUNT(DISTINCT ad_id) FROM rows) AS ads,
        (SELECT COALESCE(SUM(clicks),0) FROM rows) AS clicks,
        (SELECT COALESCE(SUM(regs),0) FROM rows) AS regs,
        (SELECT COALESCE(SUM(deps),0) FROM rows) AS deps,
        (SELECT COALESCE(SUM(conversions),0) FROM rows) AS conversions,
        (SELECT COALESCE(SUM(revenue),0) FROM rows) AS revenue,
        (SELECT COALESCE(SUM(allocated_spend),0) FROM rows) AS allocated_spend,
        (SELECT COALESCE(SUM(source_ad_spend),0) FROM ad_days) AS source_ad_spend,
        (SELECT COUNT(*) FROM rows WHERE NOT matched_ad) AS unmatched_rows,
        (SELECT COALESCE(SUM(revenue),0) FROM rows WHERE NOT matched_ad) AS unmatched_revenue,
        (SELECT MAX(synced_at) FROM rows) AS last_synced_at
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$sourceSpend = (float)($summary['source_ad_spend'] ?? 0);
$allocatedSpend = (float)($summary['allocated_spend'] ?? 0);
$summary['spend_allocated_pct'] = $sourceSpend > 0 ? round($allocatedSpend / $sourceSpend * 100, 2) : null;

$topStmt = $db->prepare("
    SELECT
        offer_id,
        offer_name,
        affiliate_network,
        SUM(clicks) AS clicks,
        SUM(regs) AS regs,
        SUM(deps) AS deps,
        SUM(conversions) AS conversions,
        SUM(revenue) AS revenue,
        SUM(allocated_spend) AS spend,
        SUM(revenue) - SUM(allocated_spend) AS profit,
        CASE WHEN SUM(allocated_spend) > 0
             THEN (SUM(revenue) - SUM(allocated_spend)) / SUM(allocated_spend) * 100
             ELSE NULL
        END AS roi
    FROM offer_insights_daily
    WHERE date BETWEEN :date_from AND :date_to
    GROUP BY offer_id, offer_name, affiliate_network
    ORDER BY profit DESC NULLS LAST, revenue DESC
    LIMIT 20
");
$topStmt->execute($params);
$topOffers = $topStmt->fetchAll(PDO::FETCH_ASSOC);

$dayStmt = $db->prepare("
    SELECT
        date::text,
        SUM(clicks) AS clicks,
        SUM(regs) AS regs,
        SUM(deps) AS deps,
        SUM(revenue) AS revenue,
        SUM(allocated_spend) AS spend,
        SUM(revenue) - SUM(allocated_spend) AS profit
    FROM offer_insights_daily
    WHERE date BETWEEN :date_from AND :date_to
    GROUP BY date
    ORDER BY date
");
$dayStmt->execute($params);
$daysOut = $dayStmt->fetchAll(PDO::FETCH_ASSOC);

apiOk([
    'installed' => true,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'summary' => $summary,
    'top_offers' => $topOffers,
    'days' => $daysOut,
]);
