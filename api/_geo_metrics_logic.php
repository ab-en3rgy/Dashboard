<?php
// api/_geo_metrics_logic.php
// Shared geo metrics calculation logic
// Included from geo_metrics.php and ext/geo_metrics.php

const TARGET_ROI = 30.0;

function geoExtractGeo(string $name): ?string {
    $parts = explode('_', $name);
    if (count($parts) >= 2) return strtoupper($parts[1]);
    return null;
}

function geoFetchPeriod(PDO $db, string $dateFrom, string $dateTo, string $bmInSql, array $bmParams): array {
    $sql = "
        SELECT
            c.name                    AS campaign_name,
            COALESCE(SUM(i.spend),       0) AS spend,
            COALESCE(SUM(i.delta),       0) AS delta,
            COALESCE(SUM(i.clicks),      0) AS clicks,
            COALESCE(SUM(i.leads),       0) AS leads,
            COALESCE(SUM(i.regs),        0) AS regs,
            COALESCE(SUM(i.deps),        0) AS deps,
            COALESCE(SUM(i.revenue),     0) AS revenue
        FROM insights_daily i
        JOIN ads a          ON a.id  = i.ad_id
        JOIN campaigns c    ON c.id  = a.campaign_id
        JOIN ad_accounts aa ON aa.id = a.ad_account_id
        WHERE i.date >= :date_from
          AND i.date <= :date_to
          AND aa.bm_id IN {$bmInSql}
        GROUP BY c.name
        ORDER BY SUM(i.spend) DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $dateFrom, ':date_to' => $dateTo], $bmParams));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function geoAggregateByGeo(array $rows): array {
    $geos = [];
    foreach ($rows as $r) {
        $geo = geoExtractGeo($r['campaign_name']);
        if (!$geo) continue;
        if (!isset($geos[$geo])) {
            $geos[$geo] = ['spend'=>0,'delta'=>0,'clicks'=>0,'leads'=>0,'regs'=>0,'deps'=>0,'revenue'=>0];
        }
        foreach (['spend','delta','clicks','leads','regs','deps','revenue'] as $k) {
            $geos[$geo][$k] += (float)$r[$k];
        }
    }
    return $geos;
}

function geoCalcMetrics(array $g, float $targetRoi): array {
    $spend = $g['spend']; $revenue = $g['revenue'];
    $clicks = $g['clicks']; $leads = $g['leads']; $regs = $g['regs']; $deps = $g['deps'];

    $roix = $spend > 0 ? ($revenue - $spend) / $spend * 100 : 0;

    $cpc = $clicks > 0 ? round($spend / $clicks, 4) : 0;
    $cpl = $leads  > 0 ? round($spend / $leads,  4) : 0;
    $c2l = $clicks > 0 ? round($leads / $clicks * 100, 4) : null;
    $cpr = $regs   > 0 ? round($spend / $regs,   4) : 0;
    $cpd = $deps   > 0 ? round($spend / $deps,   4) : 0;
    $r2d = $regs   > 0 ? round($deps / $regs * 100, 4) : null;

    // Metricy = Metricx * (ROIy+100) / (ROIx+100)
    $mult = ($roix + 100) > 0 ? ($roix + 100) / ($targetRoi + 100) : 1;

    return [
        'cpc' => round($cpc * $mult, 4),
        'cpl' => round($cpl * $mult, 4),
        'c2l' => $c2l,
        'cpr' => round($cpr * $mult, 4),
        'cpd' => round($cpd * $mult, 4),
        'r2d' => $r2d,
    ];
}

function geoEmptyTotals(): array {
    return ['spend'=>0,'delta'=>0,'clicks'=>0,'leads'=>0,'regs'=>0,'deps'=>0,'revenue'=>0];
}

function geoSumTotals(array $geos): array {
    $totals = geoEmptyTotals();
    foreach ($geos as $g) {
        foreach (['spend','delta','clicks','leads','regs','deps','revenue'] as $k) {
            $totals[$k] += (float)($g[$k] ?? 0);
        }
    }
    return $totals;
}

function geoRawPayout(array $g): ?float {
    $deps = (float)($g['deps'] ?? 0);
    if ($deps <= 0) return null;
    return (float)($g['revenue'] ?? 0) / $deps;
}

function geoBlendedPayout(array $g7d, array $g30d): float {
    $payout7d = geoRawPayout($g7d);
    $payout30d = geoRawPayout($g30d);
    if ($payout7d === null && $payout30d === null) return 0;
    if ($payout7d === null) return round((float)$payout30d, 2);
    if ($payout30d === null) return round((float)$payout7d, 2);
    return round($payout7d * 0.7 + $payout30d * 0.3, 2);
}

function geoCalcResult(PDO $db, string $bmInSql, array $bmParams, string $tz): array {
    $tzObj = appDateTimeZone($tz);
    $now   = new DateTime('now', $tzObj);

    $date7d  = (clone $now)->modify('-6 days midnight')->format('Y-m-d');
    $date30d = (clone $now)->modify('-29 days midnight')->format('Y-m-d');
    $dateTo  = $now->format('Y-m-d');

    $rows7d  = geoFetchPeriod($db, $date7d, $dateTo, $bmInSql, $bmParams);
    $rows30d = geoFetchPeriod($db, $date30d, $dateTo, $bmInSql, $bmParams);
    $geos7d = geoAggregateByGeo($rows7d);
    $geos30d = geoAggregateByGeo($rows30d);
    $profitableRows7d = array_values(array_filter($rows7d, static function (array $row): bool {
        return (float)($row['spend'] ?? 0) > 0 && (float)($row['revenue'] ?? 0) > (float)($row['spend'] ?? 0);
    }));
    $profitableRows30d = array_values(array_filter($rows30d, static function (array $row): bool {
        return (float)($row['spend'] ?? 0) > 0 && (float)($row['revenue'] ?? 0) > (float)($row['spend'] ?? 0);
    }));
    $profitableGeos7d = geoAggregateByGeo($profitableRows7d);
    $profitableGeos30d = geoAggregateByGeo($profitableRows30d);
    $allGeos = array_keys($geos30d);
    $allGeoTotals7d = geoSumTotals($geos7d);
    $allGeoTotals = geoSumTotals($geos30d);
    $profitableAllGeoTotals7d = geoSumTotals($profitableGeos7d);
    $profitableAllGeoTotals = geoSumTotals($profitableGeos30d);

    $result = [];
    foreach ($allGeos as $geo) {
        $g7d = $geos7d[$geo] ?? geoEmptyTotals();
        $g30d = $geos30d[$geo] ?? geoEmptyTotals();

        $metrics    = geoCalcMetrics($g30d, TARGET_ROI);
        $avg_payout = geoBlendedPayout($g7d, $g30d);

        $result[$geo] = array_merge($metrics, [
            'clicks' => (int)($g30d['clicks'] ?? 0),
            'leads' => (int)($g30d['leads'] ?? 0),
            'payout' => $avg_payout,
            'delta' => round((float)($g30d['delta'] ?? 0), 4),
        ]);
    }
    $profitableResult = [];
    foreach (array_keys($profitableGeos30d) as $geo) {
        $g7d = $profitableGeos7d[$geo] ?? geoEmptyTotals();
        $g30d = $profitableGeos30d[$geo] ?? geoEmptyTotals();
        $metrics = geoCalcMetrics($g30d, TARGET_ROI);
        $avg_payout = geoBlendedPayout($g7d, $g30d);
        $profitableResult[$geo] = array_merge($metrics, [
            'clicks' => (int)($g30d['clicks'] ?? 0),
            'leads' => (int)($g30d['leads'] ?? 0),
            'payout' => $avg_payout,
            'delta' => round((float)($g30d['delta'] ?? 0), 4),
        ]);
    }

    // Sort by 30d spend descending
    uksort($result, function ($a, $b) use ($geos30d) {
        return ($geos30d[$b]['spend'] ?? 0) <=> ($geos30d[$a]['spend'] ?? 0);
    });

    $rulesFile = __DIR__ . '/../config/bid_rules.json';
    $rules = file_exists($rulesFile) ? json_decode(file_get_contents($rulesFile), true) : [];
    $allGeoMetrics = geoCalcMetrics($allGeoTotals, TARGET_ROI);
    $allGeoPayout = geoBlendedPayout($allGeoTotals7d, $allGeoTotals);
    $profitableAllGeoMetrics = geoCalcMetrics($profitableAllGeoTotals, TARGET_ROI);
    $profitableAllGeoPayout = geoBlendedPayout($profitableAllGeoTotals7d, $profitableAllGeoTotals);

    return [
        'geos' => $result,
        'profitable_geos' => $profitableResult,
        'all_geos' => array_merge($allGeoMetrics, [
            'clicks' => (int)($allGeoTotals['clicks'] ?? 0),
            'leads' => (int)($allGeoTotals['leads'] ?? 0),
            'payout' => $allGeoPayout,
            'delta' => round((float)($allGeoTotals['delta'] ?? 0), 4),
        ]),
        'profitable_all_geos' => array_merge($profitableAllGeoMetrics, [
            'clicks' => (int)($profitableAllGeoTotals['clicks'] ?? 0),
            'leads' => (int)($profitableAllGeoTotals['leads'] ?? 0),
            'payout' => $profitableAllGeoPayout,
            'delta' => round((float)($profitableAllGeoTotals['delta'] ?? 0), 4),
        ]),
        'rules' => $rules,
        'period' => ['from' => $date30d, 'to' => $dateTo, 'label' => '30D', 'payout_blend' => '70% 7D / 30% 30D'],
    ];
}
