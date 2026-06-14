<?php
// api/creative_geo_counts.php
// GET /api/creative_geo_counts.php?bm_id=...&account_id=...

require __DIR__.'/_bootstrap.php';

$bmIds = $auth->allowedBmIds($me);
if (!$bmIds) apiOk([]);

$bmFilter = trim((string)($_GET['bm_id'] ?? ''));
$geoFilter = strtoupper(trim((string)($_GET['geo'] ?? '')));
$accId = trim((string)($_GET['account_id'] ?? ''));
$accountScope = trim((string)($_GET['account_scope'] ?? ''));
$campId = trim((string)($_GET['campaign_id'] ?? ''));
$adsetId = trim((string)($_GET['adset_id'] ?? ''));
$adName = trim((string)($_GET['ad_name'] ?? ''));
$tz = appTimezoneName($me['display_tz'] ?? 'Europe/Chisinau');
$tzObj = appDateTimeZone($tz);
$now = new DateTime('now', $tzObj);
$dateFrom = (clone $now)->modify('-29 days midnight')->format('Y-m-d');
$dateTo = $now->format('Y-m-d');

$params = [];
$ph = [];
foreach ($bmIds as $i => $id) {
    $key = ":bm_{$i}";
    $ph[] = $key;
    $params[$key] = (string)$id;
}
$bmInSql = '(' . implode(',', $ph) . ')';

$where = "aa.bm_id IN {$bmInSql}";
if ($bmFilter !== '') {
    $where .= ' AND aa.bm_id = :bm_filter';
    $params[':bm_filter'] = $bmFilter;
}
if ($geoFilter !== '') {
    $geos = array_values(array_filter(array_map('trim', explode(',', $geoFilter)), fn($g) => preg_match('/^[A-Z]{2}$/', $g)));
    if ($geos) {
        $conds = [];
        foreach ($geos as $i => $g) {
            $p0 = ":geo_{$i}_0";
            $conds[] = "(campaign_geo(c.name) = {$p0})";
            $params[$p0] = $g;
        }
        $where .= ' AND (' . implode(' OR ', $conds) . ')';
    }
}
if ($accId !== '') {
    $where .= ' AND a.ad_account_id = :account_id';
    $params[':account_id'] = $accId;
} elseif ($accountScope === 'active') {
    $where .= ' AND aa.status = 1';
}
if ($campId !== '') {
    $where .= ' AND a.campaign_id::text = :campaign_id';
    $params[':campaign_id'] = $campId;
}
if ($adsetId !== '') {
    $where .= ' AND a.ad_set_id::text = :adset_id';
    $params[':adset_id'] = $adsetId;
}
if ($adName !== '') {
    $where .= ' AND a.name = :ad_name';
    $params[':ad_name'] = $adName;
}

try {
    $stmt = $db->prepare("
        WITH active_creatives AS (
            SELECT
                campaign_geo(c.name) AS geo,
                a.name::text AS creative_name,
                a.id AS ad_id
            FROM ads a
            JOIN campaigns c ON c.id = a.campaign_id
            JOIN ad_sets s ON s.id = a.ad_set_id
            JOIN ad_accounts aa ON aa.id = a.ad_account_id
            WHERE {$where}
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
            WHERE i.date >= :date_from
              AND i.date <= :date_to
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
        )
        SELECT
            t.geo,
            t.creatives_count,
            COALESCE(s.successful_creatives_count, 0) AS successful_creatives_count
        FROM creative_totals t
        LEFT JOIN successful_creatives s ON s.geo = t.geo
        ORDER BY t.geo
    ");
    $stmt->execute($params + [
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
    ]);
    $rows = array_map(static fn(array $r): array => [
        'geo' => (string)$r['geo'],
        'creatives_count' => (int)$r['creatives_count'],
        'successful_creatives_count' => (int)$r['successful_creatives_count'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    apiError(500, 'DB error: ' . $e->getMessage());
}

apiOk($rows, ['count' => count($rows)]);
