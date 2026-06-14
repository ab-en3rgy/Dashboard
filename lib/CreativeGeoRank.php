<?php

declare(strict_types=1);

function creativeGeoEmptyStats(): array
{
    return [
        'spend' => 0.0,
        'delta' => 0.0,
        'impressions' => 0,
        'clicks' => 0,
        'leads' => 0,
        'regs' => 0,
        'deps' => 0,
        'revenue' => 0.0,
    ];
}

function creativeGeoFinalizeStats(array $stats): array
{
    $spend = (float)($stats['spend'] ?? 0);
    $revenue = (float)($stats['revenue'] ?? 0);
    $impressions = (int)($stats['impressions'] ?? 0);
    $clicks = (int)($stats['clicks'] ?? 0);
    $leads = (int)($stats['leads'] ?? 0);
    $regs = (int)($stats['regs'] ?? 0);
    $deps = (int)($stats['deps'] ?? 0);

    $stats['profit'] = $revenue - $spend;
    $stats['roi'] = $spend > 0 ? ($stats['profit'] / $spend * 100) : 0.0;
    $stats['ctr'] = $impressions > 0 ? ($clicks / $impressions * 100) : 0.0;
    $stats['cpm'] = $impressions > 0 ? ($spend / $impressions * 1000) : 0.0;
    $stats['cpc'] = $clicks > 0 ? ($spend / $clicks) : 0.0;
    $stats['cpl'] = $leads > 0 ? ($spend / $leads) : 0.0;
    $stats['cpr'] = $regs > 0 ? ($spend / $regs) : null;
    $stats['cpd'] = $deps > 0 ? ($spend / $deps) : 0.0;

    return $stats;
}

function creativeGeoHasRankStats(array $stats): bool
{
    foreach (['spend', 'clicks', 'leads', 'regs', 'deps', 'revenue'] as $key) {
        if ((float)($stats[$key] ?? 0) > 0) return true;
    }
    return false;
}

function creativeGeoRoi(array $stats): ?float
{
    $spend = (float)($stats['spend'] ?? 0);
    $revenue = (float)($stats['revenue'] ?? 0);
    if ($spend > 0) return ($revenue - $spend) / $spend * 100;
    return $revenue > 0 ? 100.0 : null;
}

function creativeGeoCpr(array $stats): ?float
{
    $regs = (int)($stats['regs'] ?? 0);
    return $regs > 0 ? (float)($stats['spend'] ?? 0) / $regs : null;
}

function creativeGeoCpl(array $stats): ?float
{
    $leads = (int)($stats['leads'] ?? 0);
    return $leads > 0 ? (float)($stats['spend'] ?? 0) / $leads : null;
}

function creativeGeoRegRate(array $stats): ?float
{
    $clicks = (int)($stats['clicks'] ?? 0);
    return $clicks > 0 ? (float)($stats['regs'] ?? 0) / $clicks : null;
}

function creativeGeoLeadRate(array $stats): ?float
{
    $clicks = (int)($stats['clicks'] ?? 0);
    return $clicks > 0 ? (float)($stats['leads'] ?? 0) / $clicks : null;
}

function creativeGeoClamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function creativeGeoRatioFactor(?float $base, ?float $value, bool $inverse = true): float
{
    if (!$base || !$value || $base <= 0 || $value <= 0) return 1.0;
    return creativeGeoClamp($inverse ? $base / $value : $value / $base, 0.25, 2.0);
}

function creativeGeoAvgPayout(array $stats30, array $stats3): float
{
    $deps30 = (float)($stats30['deps'] ?? 0);
    if ($deps30 > 0.0) {
        return (float)($stats30['revenue'] ?? 0) / $deps30;
    }
    $deps3 = (float)($stats3['deps'] ?? 0);
    if ($deps3 > 0.0) {
        return (float)($stats3['revenue'] ?? 0) / $deps3;
    }
    return 0.0;
}

function creativeGeoEvidenceConfidence(
    array $stats,
    float $avgPayout,
    float $depTarget,
    float $regTarget,
    float $spendTargetPayouts,
    float $clickTarget
): float {
    $deps = (float)($stats['deps'] ?? 0);
    $regs = (float)($stats['regs'] ?? 0);
    $spend = (float)($stats['spend'] ?? 0);
    $clicks = (float)($stats['clicks'] ?? 0);
    $depConf = $depTarget > 0 ? min(1.0, $deps / $depTarget) : 0.0;
    $regConf = $regTarget > 0 ? min(1.0, $regs / $regTarget) : 0.0;
    $spendTarget = $avgPayout > 0.0
        ? max(1.0, $avgPayout * $spendTargetPayouts)
        : max(20.0, $clickTarget * 0.35);
    $spendConf = $spendTarget > 0.0 ? min(1.0, $spend / $spendTarget) : 0.0;
    $clickConf = $clickTarget > 0.0 ? min(1.0, $clicks / $clickTarget) : 0.0;
    return creativeGeoClamp(max($depConf, $regConf * 0.9, $spendConf * 0.8, $clickConf * 0.45), 0.0, 1.0);
}

function creativeGeoDaysSince(?string $date): ?int
{
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }
    try {
        $last = new DateTimeImmutable($date);
        $today = new DateTimeImmutable('today');
    } catch (Throwable) {
        return null;
    }
    return (int)$last->diff($today)->format('%r%a');
}

function creativeGeoFreshnessFactor(
    ?string $lastSeen,
    float $recentSpend,
    float $recentLeads,
    float $recentRegs,
    float $recentDeps,
    float $avgPayout
): float {
    $factor = 1.0;
    $daysSince = creativeGeoDaysSince($lastSeen);
    if ($daysSince !== null) {
        if ($daysSince <= 1) {
            $factor += 0.08;
        } elseif ($daysSince <= 3) {
            $factor += 0.04;
        } elseif ($daysSince > 14) {
            $factor -= 0.12;
        } elseif ($daysSince > 7) {
            $factor -= 0.06;
        }
    }
    if ($recentSpend > 0.0) {
        if ($recentDeps > 0.0) {
            $factor += 0.08;
        } elseif ($recentRegs > 0.0) {
            $factor += 0.04;
        } elseif ($recentLeads > 0.0) {
            $factor += 0.02;
        } elseif ($avgPayout > 0.0 && $recentSpend >= max(10.0, $avgPayout * 0.5)) {
            $factor -= 0.06;
        }
    }
    return creativeGeoClamp($factor, 0.80, 1.16);
}

function rankCreativeGeoStats(array $stats3, array $stats30): array
{
    $keys = array_values(array_unique(array_merge(array_keys($stats3), array_keys($stats30))));
    $byGeo = [];

    foreach ($keys as $key) {
        $base = $stats3[$key] ?? $stats30[$key] ?? null;
        if (!$base) continue;
        $s3 = creativeGeoFinalizeStats(($stats3[$key]['stats'] ?? creativeGeoEmptyStats()));
        $s30 = creativeGeoFinalizeStats(($stats30[$key]['stats'] ?? creativeGeoEmptyStats()));
        if (!creativeGeoHasRankStats($s3) && !creativeGeoHasRankStats($s30)) continue;
        $geo = (string)($base['geo'] ?? 'XX');
        $byGeo[$geo][] = [
            'key' => $key,
            'geo' => $geo,
            'name' => (string)($base['name'] ?? ''),
            'last_seen' => (string)($base['last_seen'] ?? ($stats30[$key]['last_seen'] ?? $stats3[$key]['last_seen'] ?? '')),
            's3' => $s3,
            's30' => $s30,
        ];
    }

    $result = [];
    foreach ($byGeo as $geo => $items) {
        $total3 = creativeGeoEmptyStats();
        $total30 = creativeGeoEmptyStats();
        foreach ($items as $item) {
            foreach (array_keys($total3) as $key) {
                $total3[$key] += (float)($item['s3'][$key] ?? 0);
                $total30[$key] += (float)($item['s30'][$key] ?? 0);
            }
        }
        $total3 = creativeGeoFinalizeStats($total3);
        $total30 = creativeGeoFinalizeStats($total30);
        $geoRoi3 = creativeGeoRoi($total3) ?? 0.0;
        $geoRoi30 = creativeGeoRoi($total30) ?? $geoRoi3;
        $geoAvgRoi = $geoRoi3 * 0.7 + $geoRoi30 * 0.3;
        $geoAvgPayout = creativeGeoAvgPayout($total30, $total3);
        $geoCpr3 = creativeGeoCpr($total3);
        $geoCpr30 = creativeGeoCpr($total30);
        $geoReg3 = creativeGeoRegRate($total3);
        $geoReg30 = creativeGeoRegRate($total30);
        $geoCpl3 = creativeGeoCpl($total3);
        $geoCpl30 = creativeGeoCpl($total30);
        $geoLead3 = creativeGeoLeadRate($total3);
        $geoLead30 = creativeGeoLeadRate($total30);

        $rows = [];
        foreach ($items as $item) {
            $s3 = $item['s3'];
            $s30 = $item['s30'];
            $deps3 = (float)($s3['deps'] ?? 0);
            $deps30 = (float)($s30['deps'] ?? 0);
            $regs3 = (float)($s3['regs'] ?? 0);
            $regs30 = (float)($s30['regs'] ?? 0);
            $leads3 = (float)($s3['leads'] ?? 0);
            $leads30 = (float)($s30['leads'] ?? 0);
            $conf3 = creativeGeoEvidenceConfidence($s3, $geoAvgPayout, 2.0, 4.0, 1.0, 60.0);
            $conf30 = creativeGeoEvidenceConfidence($s30, $geoAvgPayout, 6.0, 10.0, 3.0, 160.0);
            $roi3 = creativeGeoRoi($s3);
            $roi30 = creativeGeoRoi($s30);
            $confWeight = 0.75 * $conf3 + 0.25 * $conf30;
            $roiPart = (($roi3 ?? 0.0) * 0.75 * $conf3) + (($roi30 ?? 0.0) * 0.25 * $conf30);
            $roiBlend = $roiPart + $geoAvgRoi * max(0.0, 1.0 - $confWeight);
            $profitBlend = max(0.0, ((float)($s3['profit'] ?? 0) * 0.6) + ((float)($s30['profit'] ?? 0) * 0.4));
            $cprFactor = 0.65 * creativeGeoRatioFactor($geoCpr3, creativeGeoCpr($s3), true)
                + 0.35 * creativeGeoRatioFactor($geoCpr30, creativeGeoCpr($s30), true);
            $regFactor = 0.65 * creativeGeoRatioFactor($geoReg3, creativeGeoRegRate($s3), false)
                + 0.35 * creativeGeoRatioFactor($geoReg30, creativeGeoRegRate($s30), false);
            $cplFactor = 0.65 * creativeGeoRatioFactor($geoCpl3, creativeGeoCpl($s3), true)
                + 0.35 * creativeGeoRatioFactor($geoCpl30, creativeGeoCpl($s30), true);
            $leadFactor = 0.65 * creativeGeoRatioFactor($geoLead3, creativeGeoLeadRate($s3), false)
                + 0.35 * creativeGeoRatioFactor($geoLead30, creativeGeoLeadRate($s30), false);
            $regsBlend = 0.65 * $regs3 + 0.35 * $regs30;
            $leadsBlend = 0.65 * $leads3 + 0.35 * $leads30;
            $profitSignal = ((float)($s3['profit'] ?? 0) * 0.75) + ((float)($s30['profit'] ?? 0) * 0.25);
            $totalDeps = $deps3 + $deps30;
            $totalRegs = $regs3 + $regs30;
            $totalLeads = $leads3 + $leads30;
            $efficiencyFactor = $totalRegs > 0
                ? (0.70 * $cprFactor + 0.30 * $regFactor)
                : (0.70 * $cplFactor + 0.30 * $leadFactor);
            $preOutcomeFactor = 0.65 * $cplFactor + 0.35 * $leadFactor;
            $freshnessFactor = creativeGeoFreshnessFactor(
                (string)($item['last_seen'] ?? ''),
                (float)($s3['spend'] ?? 0),
                $leads3,
                $regs3,
                $deps3,
                $geoAvgPayout
            );
            $hasPositiveEconomy = $totalDeps > 0 && ($profitSignal > 0 || $roiBlend > 0);
            $hasValidatedRegs = $totalRegs > 0 && ($efficiencyFactor >= 0.90 || $roiBlend > -10.0);
            $hasPromisingLeads = $totalLeads > 0 && ($preOutcomeFactor >= 0.90 || $roiBlend > -5.0);
            $rankBucket = $hasPositiveEconomy ? 0 : ($totalDeps > 0 ? 1 : ($hasValidatedRegs ? 2 : ($hasPromisingLeads ? 3 : 4)));
            $roiScore = max(0.0, ($roiBlend + 35.0) / 135.0);
            $confidenceFactor = 0.45 + 0.55 * $confWeight;
            $profitFactor = 1.0 + log(1.0 + $profitBlend) / 14.0;
            $signalMass = log(1.0 + max(0.0, $regsBlend) + 0.35 * max(0.0, $leadsBlend));
            $score = max(0.0, $roiBlend + 35.0)
                * max(0.50, $efficiencyFactor)
                * $confidenceFactor
                * $freshnessFactor
                * $profitFactor;
            $testScore = (
                0.26 * $preOutcomeFactor
                + 0.22 * $leadFactor
                + 0.18 * $confWeight
                + 0.18 * $roiScore
                + 0.16 * min(1.0, $signalMass / 2.0)
            ) * $freshnessFactor;
            $rows[] = [
                'key' => $item['key'],
                'rank_bucket' => $rankBucket,
                'score' => $score,
                'test_score' => $testScore,
                'confidence' => $confWeight,
                'freshness' => $freshnessFactor,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $bucket = (int)($a['rank_bucket'] ?? 0) <=> (int)($b['rank_bucket'] ?? 0);
            if ($bucket !== 0) return $bucket;
            $scoreA = (float)($a['score'] ?: $a['test_score'] ?: 0);
            $scoreB = (float)($b['score'] ?: $b['test_score'] ?: 0);
            return $scoreB <=> $scoreA;
        });

        foreach ($rows as $idx => $row) {
            $result[$row['key']] = [
                'rank' => $idx + 1,
                'share' => null,
                'score' => (float)($row['score'] ?: $row['test_score'] ?: 0),
                'confidence' => (float)($row['confidence'] ?? 0),
                'freshness' => (float)($row['freshness'] ?? 1),
                'bucket' => (int)($row['rank_bucket'] ?? 0),
            ];
        }
    }

    return $result;
}
