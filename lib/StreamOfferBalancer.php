<?php
// lib/StreamOfferBalancer.php

declare(strict_types=1);

final class StreamOfferBalancer
{
    public const MODEL = 'safe_epc_blended_range_30d_90d';
    private const PRIOR_CLICKS = 30;
    private const MIN_QUALITY = 0.0;
    private const MIN_ACTIVE_SHARE = 1;
    private const MAX_TEST_RESERVE_WITH_PERFORMANCE = 25.0;
    private const DEFAULT_PERIOD_WEIGHTS = [
        'range' => 0.6,
        '30d' => 0.3,
        '90d' => 0.1,
    ];

    public static function calculateRecommendedWeights(array $offers, array $statsRange, array $stats30, array $stats90OrPeriods, ?array $periods = null): array
    {
        if ($periods === null) {
            $periods = $stats90OrPeriods;
            $stats90 = $stats30;
        } else {
            $stats90 = $stats90OrPeriods;
        }

        $rows = [];
        $totalsRange = self::emptyStats();
        $totals30 = self::emptyStats();
        $totals90 = self::emptyStats();
        foreach ($offers as $offer) {
            $id = (string)($offer['offer_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $current = (int)($offer['share'] ?? 0);
            $sRange = $statsRange[$id] ?? self::emptyStats();
            $s30 = $stats30[$id] ?? self::emptyStats();
            $s90 = $stats90[$id] ?? self::emptyStats();
            self::addStats($totalsRange, $sRange);
            self::addStats($totals30, $s30);
            self::addStats($totals90, $s90);
            $rows[$id] = [
                'offer_id' => $id,
                'offer_name' => (string)($offer['offer_name'] ?? ''),
                'current_share' => $current,
                'raw_target' => 0.0,
                'recommended_share' => $current,
                'delta' => 0,
                'mode' => $current > 0 ? 'test' : 'none',
                'reason' => '',
                'score' => 0.0,
                'rank' => null,
                'rank_excluded' => $current <= 0,
                'safe_epc' => 0.0,
                'safe_epc_quality' => 0.0,
                'confidence' => 0.0,
                'ranking_clicks' => 0.0,
                'test_weight' => 0.0,
                'weight_cap' => 0.0,
                'has_stats' => false,
                'has_outcome' => false,
                'periods' => [],
            ];
        }

        self::finishStats($totalsRange);
        self::finishStats($totals30);
        self::finishStats($totals90);
        $periodInputs = self::buildRankingPeriods($periods, $totalsRange, $totals30, $totals90);

        foreach ($rows as $id => &$row) {
            $ranking = self::blendRankingStats(
                $statsRange[$id] ?? self::emptyStats(),
                $stats30[$id] ?? self::emptyStats(),
                $stats90[$id] ?? self::emptyStats(),
                $periodInputs
            );
            $row['safe_epc'] = round($ranking['safe_epc'], 4);
            $row['confidence'] = round($ranking['confidence'], 4);
            $row['ranking_clicks'] = round($ranking['ranking_clicks'], 4);
            $row['has_stats'] = $ranking['has_stats'];
            $row['has_outcome'] = $ranking['has_outcome'];
            $row['periods'] = $ranking['periods'];
            $row['weight_cap'] = $row['current_share'] > 0 ? self::recommendedWeightCap($row) : 0.0;
        }
        unset($row);

        $bestSafeEpc = 0.0;
        foreach ($rows as $row) {
            if ((int)$row['current_share'] > 0 && !empty($row['has_outcome'])) {
                $bestSafeEpc = max($bestSafeEpc, (float)$row['safe_epc']);
            }
        }

        $rankableIds = [];
        $weightIds = [];
        foreach ($rows as $id => &$row) {
            if ((int)$row['current_share'] <= 0) {
                $row['mode'] = 'none';
                $row['reason'] = 'KT weight is 0. Included in Safe EPC baseline. Rank and weight are not calculated.';
                continue;
            }
            $quality = $bestSafeEpc > 0 ? (float)$row['safe_epc'] / $bestSafeEpc : 0.0;
            $qualityScore = (!empty($row['has_outcome']) && $quality >= self::MIN_QUALITY) ? pow($quality, 3) : 0.0;
            $score = $qualityScore * sqrt(max(0.0, (float)$row['confidence']));
            $row['safe_epc_quality'] = round($quality, 4);
            $row['score'] = round($score, 4);
            $row['test_weight'] = $score > 0 ? 0.0 : self::recommendedTestWeight($row);
            $row['mode'] = $score > 0 ? 'keep' : (((float)$row['test_weight'] > 0) ? 'test' : '');
            if ((float)$row['ranking_clicks'] >= 100.0 && (int)(($statsRange[$id]['deps'] ?? 0)) === 0 && empty($row['has_outcome'])) {
                $row['mode'] = 'cut';
            }
            $row['reason'] = $score > 0
                ? 'Blended Safe EPC rank: current range 60%, 30d 30%, 90d 10%, confidence-adjusted.'
                : (((float)$row['test_weight'] > 0) ? 'Active test reserve: below performance threshold but kept in rotation.' : 'No rankable score or test reserve.');
            if ($score > 0) {
                $rankableIds[] = $id;
            }
            if ($score > 0 || (float)$row['test_weight'] > 0) {
                $weightIds[] = $id;
            }
        }
        unset($row);

        $targets = self::allocateRecommendedWeights($rows, $weightIds);
        foreach ($rankableIds as $id) {
            $rows[$id]['rank'] = 0;
        }
        usort($rankableIds, static function (string $a, string $b) use ($rows): int {
            $scoreDiff = (float)$rows[$b]['score'] <=> (float)$rows[$a]['score'];
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }
            $depDiff = self::periodOutcomeCount($rows[$b], 'deps') <=> self::periodOutcomeCount($rows[$a], 'deps');
            if ($depDiff !== 0) {
                return $depDiff;
            }
            return (float)$rows[$b]['ranking_clicks'] <=> (float)$rows[$a]['ranking_clicks'];
        });
        foreach ($rankableIds as $i => $id) {
            $rows[$id]['rank'] = $i + 1;
        }

        $normalizedShares = self::normalizeRecommendedShares($rows, $targets);
        foreach ($rows as &$row) {
            $target = max(0.0, (float)($targets[$row['offer_id']] ?? 0.0));
            $current = (int)$row['current_share'];
            if ($current > 0 && $target < self::MIN_ACTIVE_SHARE) {
                $target = (float)self::MIN_ACTIVE_SHARE;
                $row['reason'] = trim((string)$row['reason'] . ' Protected minimum: active KT weight cannot be set to 0.');
            }
            $row['recommended_share'] = $current > 0
                ? max(self::MIN_ACTIVE_SHARE, (int)($normalizedShares[$row['offer_id']] ?? self::MIN_ACTIVE_SHARE))
                : max(0, (int)round($target));
            $row['delta'] = $row['recommended_share'] - $current;
            $row['raw_target'] = $current > 0 ? (float)$row['recommended_share'] : round($target, 4);
            if ($current > 0 && (float)$row['score'] > 0) {
                if ((int)$row['recommended_share'] > $current) {
                    $row['mode'] = 'scale';
                } elseif ((int)$row['recommended_share'] < $current) {
                    $row['mode'] = 'trim';
                } else {
                    $row['mode'] = 'keep';
                }
            } elseif ($current > 0 && (float)$row['test_weight'] > 0) {
                $row['mode'] = 'test';
            } elseif ($current > 0) {
                $row['mode'] = 'cut';
            }
        }
        unset($row);

        return [
            'offers' => $rows,
            'meta' => [
                'periods' => $periods,
                'period_weights' => self::DEFAULT_PERIOD_WEIGHTS,
                'prior_clicks' => self::PRIOR_CLICKS,
                'min_quality' => self::MIN_QUALITY,
                'totals_range' => $totalsRange,
                'totals_30d' => $totals30,
                'totals_90d' => $totals90,
                'ranked_offers' => count($rankableIds),
                'model' => self::MODEL,
                'model_note' => 'Blended Safe EPC: current range 60%, 30d 30%, 90d 10%; each period uses 30 prior clicks at period stream average EPC; quality penalty is quality^3 without a hard quality cutoff; active zero-KT-weight offers are excluded from rank/weight; current active offers have hard minimum weight 1; final active KT weights are normalized to 100.',
            ],
        ];
    }

    public static function emptyRecommendation(int $currentShare): array
    {
        return [
            'current_share' => $currentShare,
            'recommended_share' => $currentShare,
            'delta' => 0,
            'mode' => 'none',
            'reason' => '',
            'score' => 0.0,
            'rank' => null,
            'rank_excluded' => $currentShare <= 0,
            'safe_epc' => 0.0,
            'safe_epc_quality' => 0.0,
            'confidence' => 0.0,
            'raw_target' => (float)$currentShare,
            'test_weight' => 0.0,
            'weight_cap' => 0.0,
            'ranking_clicks' => 0.0,
            'periods' => [],
        ];
    }

    private static function buildRankingPeriods(array $periods, array $totalsRange, array $totals30, array $totals90): array
    {
        $rangePeriod = $periods['range'] ?? [];
        $rangeLabel = is_array($rangePeriod) ? (string)($rangePeriod['label'] ?? 'range') : (string)($rangePeriod ?: 'range');
        return [
            [
                'key' => 'range',
                'label' => $rangeLabel,
                'weight' => self::DEFAULT_PERIOD_WEIGHTS['range'],
                'totals' => $totalsRange,
            ],
            [
                'key' => '30d',
                'label' => '30d',
                'weight' => self::DEFAULT_PERIOD_WEIGHTS['30d'],
                'totals' => $totals30,
            ],
            [
                'key' => '90d',
                'label' => '90d',
                'weight' => self::DEFAULT_PERIOD_WEIGHTS['90d'],
                'totals' => $totals90,
            ],
        ];
    }

    private static function blendRankingStats(array $statsRange, array $stats30, array $stats90, array $periods): array
    {
        $statsByPeriod = [
            'range' => $statsRange,
            '30d' => $stats30,
            '90d' => $stats90,
        ];
        $safeEpc = 0.0;
        $confidence = 0.0;
        $rankingClicks = 0.0;
        $totalWeight = 0.0;
        $hasStats = false;
        $hasOutcome = false;
        $details = [];

        foreach ($periods as $period) {
            $key = (string)$period['key'];
            $stats = $statsByPeriod[$key] ?? self::emptyStats();
            $clicks = self::reportClicks($stats);
            $revenue = (float)($stats['revenue'] ?? 0);
            $regs = (int)($stats['regs'] ?? 0);
            $deps = (int)($stats['deps'] ?? 0);
            $periodHasStats = $clicks > 0 || $revenue > 0 || $regs > 0 || $deps > 0;
            $periodHasOutcome = $revenue > 0 || $regs > 0 || $deps > 0;
            $totalClicks = self::reportClicks($period['totals'] ?? []);
            $totalRevenue = (float)(($period['totals'] ?? [])['revenue'] ?? 0);
            $avgEpc = $totalClicks > 0 ? $totalRevenue / $totalClicks : 0.0;
            $periodSafeEpc = ($periodHasOutcome && $clicks + self::PRIOR_CLICKS > 0)
                ? ($revenue + $avgEpc * self::PRIOR_CLICKS) / ($clicks + self::PRIOR_CLICKS)
                : 0.0;
            $weight = (float)$period['weight'];
            $safeEpc += $weight * $periodSafeEpc;
            $confidence += $weight * ($clicks / ($clicks + self::PRIOR_CLICKS));
            $rankingClicks += $weight * $clicks;
            $totalWeight += $weight;
            $hasStats = $hasStats || $periodHasStats;
            $hasOutcome = $hasOutcome || $periodHasOutcome;
            $details[] = [
                'label' => (string)$period['label'],
                'weight' => $weight,
                'clicks' => $clicks,
                'revenue' => round($revenue, 4),
                'regs' => $regs,
                'deps' => $deps,
                'safe_epc' => round($periodSafeEpc, 4),
                'avg_epc' => round($avgEpc, 4),
                'has_outcome' => $periodHasOutcome,
            ];
        }

        return [
            'safe_epc' => $totalWeight > 0 ? $safeEpc / $totalWeight : 0.0,
            'confidence' => $totalWeight > 0 ? $confidence / $totalWeight : 0.0,
            'ranking_clicks' => $rankingClicks,
            'has_stats' => $hasStats,
            'has_outcome' => $hasOutcome,
            'periods' => $details,
        ];
    }

    private static function recommendedWeightCap(array $row): float
    {
        $share = max(0, (int)($row['current_share'] ?? 0));
        $clicks = (float)($row['ranking_clicks'] ?? 0);
        $hasOutcome = !empty($row['has_outcome']);
        if ($share <= 0) {
            return 0.0;
        }
        if (!$hasOutcome) {
            return $clicks >= 100.0 ? 0.0 : (float)min($share, 5);
        }
        if ($clicks < 30.0) {
            return 12.0;
        }
        if ($clicks < 50.0) {
            return 15.0;
        }
        return 100.0;
    }

    private static function recommendedTestWeight(array $row): float
    {
        $share = max(0, (int)($row['current_share'] ?? 0));
        if ($share <= 0) {
            return 0.0;
        }
        $clicks = (float)($row['ranking_clicks'] ?? 0);
        if (empty($row['has_outcome']) && $clicks >= 100.0) {
            return 0.0;
        }
        return (float)min($share, 5);
    }

    private static function allocateRecommendedWeights(array $rows, array $ids): array
    {
        $result = [];
        $performanceIds = array_values(array_filter($ids, static fn($id): bool => (float)($rows[$id]['score'] ?? 0) > 0 && (float)($rows[$id]['weight_cap'] ?? 0) > 0));
        $maxTestReserve = $performanceIds ? self::MAX_TEST_RESERVE_WITH_PERFORMANCE : 100.0;
        $testRows = [];
        $requestedTestReserve = 0.0;
        foreach ($ids as $id) {
            $testWeight = min((float)($rows[$id]['test_weight'] ?? 0), (float)($rows[$id]['weight_cap'] ?? 0));
            if ($testWeight > 0) {
                $testRows[$id] = $testWeight;
                $requestedTestReserve += $testWeight;
            }
        }
        $testScale = $requestedTestReserve > $maxTestReserve && $requestedTestReserve > 0
            ? $maxTestReserve / $requestedTestReserve
            : 1.0;
        $testReserve = 0.0;
        foreach ($testRows as $id => $testWeight) {
            $weight = $testWeight * $testScale;
            $result[$id] = $weight;
            $testReserve += $weight;
        }

        $remaining = max(0.0, 100.0 - $testReserve);
        $pool = array_values(array_filter($performanceIds, static fn($id): bool => (float)($rows[$id]['weight_cap'] ?? 0) > (float)($result[$id] ?? 0)));
        while ($pool && $remaining > 0.0001) {
            $scoreSum = array_sum(array_map(static fn($id): float => max(0.0, (float)($rows[$id]['score'] ?? 0)), $pool));
            if ($scoreSum <= 0) {
                break;
            }
            $nextPool = [];
            $used = 0.0;
            foreach ($pool as $id) {
                $current = (float)($result[$id] ?? 0);
                $proposed = $remaining * max(0.0, (float)$rows[$id]['score']) / $scoreSum;
                $capLeft = max(0.0, (float)$rows[$id]['weight_cap'] - $current);
                $add = min($proposed, $capLeft);
                $result[$id] = $current + $add;
                $used += $add;
                if ($add + 0.0001 < $capLeft) {
                    $nextPool[] = $id;
                }
            }
            if ($used <= 0.0001 || count($nextPool) === count($pool)) {
                break;
            }
            $remaining -= $used;
            $pool = $nextPool;
        }
        $allocated = array_sum($result);
        $leftover = max(0.0, 100.0 - $allocated);
        if ($leftover > 0.0001 && $performanceIds) {
            $scoreSum = array_sum(array_map(static fn($id): float => max(0.0, (float)($rows[$id]['score'] ?? 0)), $performanceIds));
            if ($scoreSum > 0) {
                foreach ($performanceIds as $id) {
                    $result[$id] = (float)($result[$id] ?? 0.0) + ($leftover * max(0.0, (float)$rows[$id]['score']) / $scoreSum);
                }
            }
        }

        foreach ($ids as $id) {
            $result[$id] ??= 0.0;
        }
        return $result;
    }

    private static function normalizeRecommendedShares(array $rows, array $targets): array
    {
        $basis = [];
        foreach ($rows as $id => $row) {
            if ((int)($row['current_share'] ?? 0) <= 0) {
                continue;
            }
            $basis[(string)$id] = max((float)self::MIN_ACTIVE_SHARE, (float)($targets[$id] ?? 0.0));
        }
        if (!$basis) {
            return [];
        }

        $basisSum = array_sum($basis);
        if ($basisSum <= 0.0) {
            foreach ($rows as $id => $row) {
                if ((int)($row['current_share'] ?? 0) > 0) {
                    $basis[(string)$id] = max((float)self::MIN_ACTIVE_SHARE, (float)($row['current_share'] ?? 0));
                }
            }
            $basisSum = array_sum($basis);
        }
        if ($basisSum <= 0.0) {
            return [];
        }

        $shares = [];
        $fractions = [];
        foreach ($basis as $id => $weight) {
            $raw = $weight / $basisSum * 100.0;
            $floor = max(self::MIN_ACTIVE_SHARE, (int)floor($raw));
            $shares[$id] = $floor;
            $fractions[$id] = $raw - floor($raw);
        }

        $remaining = 100 - array_sum($shares);
        if ($remaining > 0) {
            arsort($fractions);
            $ids = array_keys($fractions);
            while ($remaining > 0 && $ids) {
                foreach ($ids as $id) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $shares[$id]++;
                    $remaining--;
                }
            }
        } elseif ($remaining < 0) {
            asort($fractions);
            $ids = array_keys($fractions);
            while ($remaining < 0 && $ids) {
                $changed = false;
                foreach ($ids as $id) {
                    if ($remaining >= 0) {
                        break;
                    }
                    if ($shares[$id] <= self::MIN_ACTIVE_SHARE) {
                        continue;
                    }
                    $shares[$id]--;
                    $remaining++;
                    $changed = true;
                }
                if (!$changed) {
                    break;
                }
            }
        }

        return $shares;
    }

    private static function periodOutcomeCount(array $row, string $key): int
    {
        $sum = 0;
        foreach (($row['periods'] ?? []) as $period) {
            $sum += (int)($period[$key] ?? 0);
        }
        return $sum;
    }

    public static function emptyStats(): array
    {
        return [
            'offer_name' => '',
            'affiliate_network' => '',
            'clicks' => 0,
            'leads' => 0,
            'regs' => 0,
            'deps' => 0,
            'conversions' => 0,
            'revenue' => 0.0,
            'spend' => 0.0,
            'profit' => 0.0,
            'roi' => null,
            'cpl' => null,
            'cpr' => null,
            'cpd' => null,
        ];
    }

    public static function normalizeStats(array $row): array
    {
        foreach (['clicks', 'leads', 'regs', 'deps', 'conversions'] as $key) {
            $row[$key] = (int)($row[$key] ?? 0);
        }
        foreach (['revenue', 'spend', 'profit', 'roi', 'cpl', 'cpr', 'cpd'] as $key) {
            $row[$key] = !array_key_exists($key, $row) || $row[$key] === null ? null : round((float)$row[$key], 4);
        }
        return array_merge(self::emptyStats(), $row);
    }

    public static function addStats(array &$total, array $row): void
    {
        foreach (['clicks', 'leads', 'regs', 'deps', 'conversions'] as $key) {
            $total[$key] += (int)($row[$key] ?? 0);
        }
        foreach (['revenue', 'spend', 'profit'] as $key) {
            $total[$key] += (float)($row[$key] ?? 0);
        }
    }

    public static function finishStats(array &$row): void
    {
        $row['revenue'] = round((float)$row['revenue'], 4);
        $row['spend'] = round((float)$row['spend'], 4);
        $row['profit'] = round((float)$row['profit'], 4);
        $row['roi'] = $row['spend'] > 0 ? round($row['profit'] / $row['spend'] * 100, 4) : null;
        $row['cpl'] = $row['leads'] > 0 ? round($row['spend'] / $row['leads'], 4) : null;
        $row['cpr'] = $row['regs'] > 0 ? round($row['spend'] / $row['regs'], 4) : null;
        $row['cpd'] = $row['deps'] > 0 ? round($row['spend'] / $row['deps'], 4) : null;
    }

    public static function reportClicks(array $row): int
    {
        $leads = (int)($row['leads'] ?? 0);
        return $leads > 0 ? $leads : (int)($row['clicks'] ?? 0);
    }

    public static function applyGeoSpend(array &$stats, float $geoSpend): void
    {
        self::applyGeoTotals($stats, ['spend' => $geoSpend, 'clicks' => 0]);
    }

    public static function applyGeoTotals(array &$stats, array $geoTotals): void
    {
        $geoSpend = (float)($geoTotals['spend'] ?? 0);
        $geoClicks = (int)($geoTotals['clicks'] ?? 0);
        $totalLeads = 0;
        $totalAllocatedSpend = 0.0;
        foreach ($stats as $row) {
            $totalLeads += (int)($row['leads'] ?? 0);
            $totalAllocatedSpend += max(0.0, (float)($row['spend'] ?? 0));
        }

        $clickAlloc = [];
        if ($totalLeads > 0 && $geoClicks > 0) {
            $fractions = [];
            $allocated = 0;
            foreach ($stats as $key => $row) {
                $raw = $geoClicks * ((int)($row['leads'] ?? 0) / $totalLeads);
                $base = (int)floor($raw);
                $clickAlloc[$key] = $base;
                $fractions[$key] = $raw - $base;
                $allocated += $base;
            }
            arsort($fractions);
            $remaining = $geoClicks - $allocated;
            foreach (array_keys($fractions) as $key) {
                if ($remaining-- <= 0) {
                    break;
                }
                $clickAlloc[$key]++;
            }
        }

        foreach ($stats as $key => &$row) {
            $leads = (int)($row['leads'] ?? 0);
            $clicks = $clickAlloc[$key] ?? 0;
            $allocatedSpend = max(0.0, (float)($row['spend'] ?? 0));
            if ($totalAllocatedSpend > 0) {
                $spend = round($geoSpend * ($allocatedSpend / $totalAllocatedSpend), 4);
            } elseif ($totalLeads > 0) {
                $spend = round($geoSpend * ($leads / $totalLeads), 4);
            } else {
                $spend = 0.0;
            }
            $revenue = (float)($row['revenue'] ?? 0);
            $regs = (int)($row['regs'] ?? 0);
            $deps = (int)($row['deps'] ?? 0);
            $profit = round($revenue - $spend, 4);

            $row['clicks'] = $clicks;
            $row['spend'] = $spend;
            $row['profit'] = $profit;
            $row['roi'] = $spend > 0 ? round($profit / $spend * 100, 4) : null;
            $row['cpl'] = $leads > 0 ? round($spend / $leads, 4) : null;
            $row['cpr'] = $regs > 0 ? round($spend / $regs, 4) : null;
            $row['cpd'] = $deps > 0 ? round($spend / $deps, 4) : null;
        }
        unset($row);
    }

    public static function applyGeoClicks(array &$stats, array $geoTotals): void
    {
        $geoClicks = (int)($geoTotals['clicks'] ?? 0);
        $totalLeads = 0;
        foreach ($stats as $row) {
            $totalLeads += (int)($row['leads'] ?? 0);
        }

        $clickAlloc = [];
        if ($totalLeads > 0 && $geoClicks > 0) {
            $fractions = [];
            $allocated = 0;
            foreach ($stats as $key => $row) {
                $raw = $geoClicks * ((int)($row['leads'] ?? 0) / $totalLeads);
                $base = (int)floor($raw);
                $clickAlloc[$key] = $base;
                $fractions[$key] = $raw - $base;
                $allocated += $base;
            }
            arsort($fractions);
            $remaining = $geoClicks - $allocated;
            foreach (array_keys($fractions) as $key) {
                if ($remaining-- <= 0) {
                    break;
                }
                $clickAlloc[$key]++;
            }
        }

        foreach ($stats as $key => &$row) {
            $row['clicks'] = $clickAlloc[$key] ?? 0;
        }
        unset($row);
    }

}
