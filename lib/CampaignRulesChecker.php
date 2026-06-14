<?php
declare(strict_types=1);

require_once __DIR__ . '/Timezone.php';
require_once __DIR__ . '/../api/_geo_metrics_logic.php';

class CampaignRulesChecker
{
    private PDO $db;
    private array $cfg;
    private string $tz;

    public function __construct(PDO $db, array $cfg)
    {
        $this->db = $db;
        $this->cfg = $cfg;
        $this->tz = appTimezoneName($cfg['display_tz'] ?? 'Europe/Chisinau');
    }

    public function run(array $options = []): array
    {
        $skipCpc = array_key_exists('skip_cpc', $options)
            ? (bool)$options['skip_cpc']
            : (bool)($this->cfg['auto_rules']['skip_cpc'] ?? true);
        $bmIds = $options['bm_ids'] ?? $this->fetchAllBmIds();
        $bmIds = array_values(array_filter(array_map('strval', $bmIds), fn($v) => $v !== ''));
        $periods = $this->periods();

        if (!$bmIds) {
            return [
                'ok' => true,
                'stats' => $this->emptyStats(),
                'verdicts' => [],
                'periods' => $periods,
                'rules_source' => 'FbAutoRules dry-run: DB dynamic limits with all-GEO fallback',
                'auto_rules_v2' => $this->shadowConfig(),
            ];
        }

        [$bmInSql, $bmParams] = $this->buildNamedIn('bm', $bmIds);
        [$metricBmInSql, $metricBmParams] = $this->buildNamedIn('metric_bm', $this->fetchAllBmIds());
        $metricsData = geoCalcResult($this->db, $metricBmInSql, $metricBmParams, $this->tz);
        $campaigns = $this->fetchCampaigns($bmInSql, $bmParams);
        $stats1d = $this->fetchCampaignStats($periods['today']['from'], $periods['today']['to'], $bmInSql, $bmParams);
        $statsYesterday = $this->fetchCampaignStats($periods['yesterday']['from'], $periods['yesterday']['to'], $bmInSql, $bmParams);
        $stats7d = $this->fetchCampaignStats($periods['last7']['from'], $periods['last7']['to'], $bmInSql, $bmParams);
        $stats30d = $this->fetchCampaignStats($periods['last30']['from'], $periods['last30']['to'], $bmInSql, $bmParams);
        $statsAlltime = $this->fetchCampaignStats($periods['alltime']['from'], $periods['alltime']['to'], $bmInSql, $bmParams);

        $verdicts = [];
        foreach ($campaigns as $campaign) {
            if (!preg_match('/^MLR_/i', $campaign['name'])) {
                continue;
            }
            $verdict = $this->computeVerdict(
                $campaign,
                $stats1d[$campaign['id']] ?? $this->emptyMetricRow(),
                $statsYesterday[$campaign['id']] ?? $this->emptyMetricRow(),
                $stats7d[$campaign['id']] ?? $this->emptyMetricRow(),
                $stats30d[$campaign['id']] ?? $this->emptyMetricRow(),
                $statsAlltime[$campaign['id']] ?? $this->emptyMetricRow(),
                $metricsData,
                $skipCpc
            );
            $verdicts[] = $this->decorateVerdict($verdict);
        }
        usort($verdicts, function (array $a, array $b): int {
            $spendCmp = ((float)($b['sort_spend_2d'] ?? 0)) <=> ((float)($a['sort_spend_2d'] ?? 0));
            if ($spendCmp !== 0) {
                return $spendCmp;
            }
            $todayCmp = ((float)($b['data_1d']['spend'] ?? 0)) <=> ((float)($a['data_1d']['spend'] ?? 0));
            if ($todayCmp !== 0) {
                return $todayCmp;
            }
            $activeCmp = ($this->isActiveVerdict($b) <=> $this->isActiveVerdict($a));
            return $activeCmp !== 0 ? $activeCmp : strcmp((string)$a['campaign_name'], (string)$b['campaign_name']);
        });

        $stats = $this->emptyStats();
        foreach ($verdicts as $v) {
            $key = strtolower($v['verdict']);
            if ($v['verdict'] === 'HOLD_STOP') {
                $key = 'hold_stop';
            }
            if (isset($stats[$key])) {
                $stats[$key]++;
            }
            if ($v['should_change']) {
                $stats['changes']++;
            }
            if ($this->isV2LiveChange($v)) {
                $stats['v2_changes']++;
            }
        }
        $stats['total'] = count($verdicts);

        return [
            'ok' => true,
            'stats' => $stats,
            'verdicts' => $verdicts,
            'periods' => $periods,
            'rules_source' => 'FbAutoRules dry-run: DB dynamic limits with all-GEO fallback',
            'skip_cpc' => $skipCpc,
            'auto_rules_v2' => $this->shadowConfig(),
        ];
    }

    private function isActiveVerdict(array $verdict): int
    {
        return (($verdict['fb_status'] ?? '') === 'ACTIVE' && !empty($verdict['account_active'])) ? 1 : 0;
    }

    private function isV2LiveChange(array $verdict): bool
    {
        if (empty($verdict['candidate_should_change'])) {
            return false;
        }
        $desired = strtoupper(trim((string)($verdict['candidate_desired_status'] ?? '')));
        if (!in_array($desired, ['ACTIVE', 'PAUSED'], true)) {
            return false;
        }
        $candidate = strtoupper(trim((string)($verdict['candidate_verdict'] ?? '')));
        return !in_array($candidate, ['', 'DISABLED', 'NO_GEO', 'NO_RULES', 'MANUAL_STOP', 'IGNORED_STATUS'], true);
    }

    private function normalizedStatus(mixed $value): string
    {
        return strtoupper(trim((string)($value ?? '')));
    }

    private function realCampaignStatus(array $campaign): string
    {
        $status = $this->normalizedStatus($campaign['status'] ?? '');
        $effective = $this->normalizedStatus($campaign['effective_status'] ?? '');

        if ($status === 'MANUAL_STOP' || $effective === 'MANUAL_STOP') {
            return 'MANUAL_STOP';
        }
        if (in_array($status, ['ARCHIVED', 'DELETED'], true)) {
            return $status;
        }
        if ($effective !== '' && $effective !== 'ACTIVE') {
            return $effective;
        }
        if ($status !== '' && $status !== 'ACTIVE') {
            return $status;
        }
        return ($status === 'ACTIVE' && ($effective === '' || $effective === 'ACTIVE')) ? 'ACTIVE' : ($effective ?: $status);
    }

    private function shouldIgnoreStatus(string $status): bool
    {
        return in_array($status, ['ARCHIVED', 'DELETED', 'WITH_ISSUES', 'DISAPPROVED', 'PENDING_REVIEW'], true);
    }

    private function computeVerdict(
        array $campaign,
        array $today,
        array $yesterday,
        array $last7,
        array $last30,
        array $alltime,
        array $metricsData,
        bool $skipCpc
    ): array {
        $geo = $this->extractGeo($campaign['name']);
        $geoMetrics = $geo ? ($metricsData['geos'][$geo] ?? null) : null;
        $fallbackMetrics = $metricsData['all_geos'] ?? null;
        $status = $this->realCampaignStatus($campaign);
        $accountActive = (int)$campaign['account_status'] === 1;
        if (!$accountActive && $status === 'ACTIVE') {
            $status = 'PAUSED';
        }

        $base = [
            'campaign_id' => $campaign['id'],
            'campaign_name' => $campaign['name'],
            'geo' => $geo,
            'fb_status' => $status,
            'account_id' => $campaign['ad_account_id'],
            'account_name' => $campaign['account_name'],
            'account_active' => $accountActive,
            'bm_id' => (string)$campaign['bm_id'],
            'bm_name' => $campaign['bm_name'],
            'data_1d' => $this->metricPayload($today),
            'data_yesterday' => $this->metricPayload($yesterday),
            'data_7d' => $this->metricPayload($last7),
            'data_30d' => $this->metricPayload($last30),
            'data_alltime' => $this->metricPayload($alltime),
            'sort_spend_2d' => round((float)($today['spend'] ?? 0) + (float)($yesterday['spend'] ?? 0), 4),
            'shadow_payout' => $geoMetrics ? round((float)($geoMetrics['payout'] ?? 0), 4) : null,
            'metrics_source' => 'none',
            'metrics_source_reason' => '',
            'limits_db_1d' => [],
            'limits_db_30d' => [],
            'signal_db' => null,
            'used_algo' => null,
            'should_change' => false,
            'desired_status' => null,
            'reason' => '',
        ];

        if (!$geo) {
            return array_merge($base, [
                'verdict' => 'NO_GEO',
                'reason' => 'GEO not found in campaign name',
            ]);
        }

        if ($status === 'MANUAL_STOP') {
            return array_merge($base, [
                'verdict' => 'MANUAL_STOP',
                'desired_status' => 'PAUSED',
                'should_change' => false,
                'reason' => 'Campaign was stopped manually; auto rules must not start it',
            ]);
        }

        if ($this->shouldIgnoreStatus($status)) {
            return array_merge($base, [
                'verdict' => 'IGNORED_STATUS',
                'desired_status' => null,
                'should_change' => false,
                'reason' => "Campaign status {$status} is excluded from auto rules",
            ]);
        }

        $rules = $metricsData['rules'] ?? [];
        $hasGeoAnalysisData = $this->canUseAlgoA($geoMetrics);
        $hasFallbackAnalysisData = !$hasGeoAnalysisData && $this->canUseAlgoA($fallbackMetrics);
        $analysisMetrics = $hasGeoAnalysisData ? $geoMetrics : ($hasFallbackAnalysisData ? $fallbackMetrics : null);
        $metricsSource = $hasGeoAnalysisData ? 'geo' : ($hasFallbackAnalysisData ? 'all_geos_fallback' : 'none');
        $profitableGeoMetrics = $geo ? ($metricsData['profitable_geos'][$geo] ?? null) : null;
        $profitableFallbackMetrics = $metricsData['profitable_all_geos'] ?? null;
        $v2Baseline = $this->canUseAlgoA($profitableGeoMetrics)
            ? $profitableGeoMetrics
            : ($this->canUseAlgoA($profitableFallbackMetrics)
                ? $profitableFallbackMetrics
                : $analysisMetrics);
        $v2BaselineSource = $this->canUseAlgoA($profitableGeoMetrics)
            ? 'profitable_geo_30d'
            : ($this->canUseAlgoA($profitableFallbackMetrics)
                ? 'profitable_all_geos_30d'
                : $metricsSource);
        $metricsSourceReason = match ($metricsSource) {
            'geo' => "Using DB metrics for GEO {$geo}",
            'all_geos_fallback' => "GEO {$geo} has insufficient DB metrics; using averaged DB metrics across all GEOs",
            default => "No DB analysis data for GEO {$geo} and no all-GEO fallback metrics",
        };
        $dbSignal = null;
        $limitsDb1d = [];
        $limitsDb30d = [];

        if ($analysisMetrics) {
            $limitsDb1d = $this->buildDynamicLimits($analysisMetrics, (float)$today['spend'], $rules, '1D');
            $limitsDb30d = $this->buildDynamicLimits($analysisMetrics, (float)$last30['spend'], $rules, '30D');
            $dbSignal = $this->evaluateSignal($status, $accountActive, $today, $last30, $limitsDb1d, $limitsDb30d, $skipCpc);
        }

        $primary = $dbSignal;
        if (!$primary) {
            return array_merge($base, [
                'verdict' => 'NO_RULES',
                'metrics_source' => $metricsSource,
                'metrics_source_reason' => $metricsSourceReason,
                'reason' => $hasGeoAnalysisData
                    ? "No DB limits verdict for GEO {$geo}"
                    : $metricsSourceReason,
            ]);
        }

        return array_merge($base, [
            'verdict' => $primary['verdict'],
            'desired_status' => $primary['desired_status'],
            'should_change' => $primary['should_change'],
            'reason' => $this->combinedReason($dbSignal, $metricsSourceReason),
            'used_algo' => $metricsSource === 'all_geos_fallback' ? 'DB_ALL_GEOS_FALLBACK' : 'DB_A',
            'metrics_source' => $metricsSource,
            'metrics_source_reason' => $metricsSourceReason,
            'shadow_payout' => round((float)($analysisMetrics['payout'] ?? 0), 4),
            'v2_baseline' => $v2Baseline ?: [],
            'v2_baseline_source' => $v2BaselineSource,
            'limits_db_1d' => $limitsDb1d,
            'limits_db_30d' => $limitsDb30d,
            'limits_1d' => $limitsDb1d,
            'limits_30d' => $limitsDb30d,
            'violation_1d' => $primary['violation_1d'],
            'violation_30d' => $primary['violation_30d'],
            'signal_db' => $dbSignal,
        ]);
    }

    private function evaluateSignal(
        string $status,
        bool $accountActive,
        array $today,
        array $last30,
        array $limits1d,
        array $limits30d,
        bool $skipCpc
    ): array {
        $violation1d = $this->checkViolation($today, $limits1d, '1D', $skipCpc);
        $violation30d = $this->checkViolation($last30, $limits30d, '30D', $skipCpc);
        $violation = $violation1d ?: $violation30d;
        $base = [
            'violation_1d' => $violation1d,
            'violation_30d' => $violation30d,
            'start_reason' => null,
            'start_block' => null,
        ];

        if ($status === 'ACTIVE') {
            if ($violation) {
                $reason = $this->violationText($violation1d, $violation30d);
                return array_merge($base, [
                    'verdict' => 'STOP',
                    'desired_status' => 'PAUSED',
                    'should_change' => $accountActive,
                    'reason' => $this->actionReason($reason, $accountActive),
                ]);
            }
            return array_merge($base, [
                'verdict' => 'OK',
                'desired_status' => 'ACTIVE',
                'should_change' => false,
                'reason' => 'No 1D/30D violations',
            ]);
        }

        if ($violation) {
            return array_merge($base, [
                'verdict' => 'HOLD_STOP',
                'desired_status' => 'PAUSED',
                'should_change' => false,
                'reason' => 'Keep paused: ' . $this->violationText($violation1d, $violation30d),
            ]);
        }

        $start30d = $this->checkStartCondition($last30, $limits30d, '30D', $skipCpc);
        if (!$start30d['ok']) {
            return array_merge($base, [
                'verdict' => 'HOLD_STOP',
                'desired_status' => 'PAUSED',
                'should_change' => false,
                'start_block' => '30D: ' . $start30d['reason'],
                'reason' => 'Keep paused: 30D ' . $start30d['reason'],
            ]);
        }

        return array_merge($base, [
            'verdict' => 'START',
            'desired_status' => 'ACTIVE',
            'should_change' => $accountActive,
            'start_reason' => '30D: ' . $start30d['reason'],
            'reason' => $this->actionReason('No 1D/30D violations, can start; 30D ' . $start30d['reason'], $accountActive),
        ]);
    }

    private function combinedReason(?array $dbSignal, string $metricsSourceReason = ''): string
    {
        if ($dbSignal) {
            $reason = 'DB: ' . $dbSignal['verdict'] . ' - ' . $dbSignal['reason'];
            return $metricsSourceReason !== '' ? $reason . ' | ' . $metricsSourceReason : $reason;
        }
        return 'No limits';
    }

    private function actionReason(string $reason, bool $accountActive): string
    {
        if (!$accountActive) {
            return $reason . '; action blocked: ad account is not active';
        }
        return $reason;
    }

    private function decorateVerdict(array $verdict): array
    {
        $reason = trim((string)($verdict['reason'] ?? ''));
        $verdict['reason_short'] = $this->reasonShort($reason);
        $verdict['reason_detail'] = $this->reasonDetail($verdict);
        $verdict['signal_level'] = $this->signalLevel($verdict);
        $verdict['why_now'] = $this->whyNow($verdict);
        $verdict = array_merge($verdict, $this->shadowCandidate($verdict));
        $verdict = $this->applyRestartHysteresis($verdict);
        return $verdict;
    }

    private function shadowConfig(): array
    {
        $cfg = $this->cfg['auto_rules_v2'] ?? [];
        return [
            'live_policy' => 'v1',
            'comparison_policy' => 'v2',
            'enabled' => filter_var($cfg['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'shadow_only' => filter_var($cfg['shadow_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'act_on_pause_today' => filter_var($cfg['act_on_pause_today'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'act_on_tomorrow_restart' => filter_var($cfg['act_on_tomorrow_restart'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'act_on_hold_stop' => filter_var($cfg['act_on_hold_stop'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'intraday_baseline_tolerance' => max(1.0, (float)($cfg['intraday_baseline_tolerance'] ?? 1.05)),
            'strict_profit_payouts' => max(1.0, (float)($cfg['strict_profit_payouts'] ?? 5.0)),
            'test_stop_payouts' => max(0.0, (float)($cfg['test_stop_payouts'] ?? 0.5)),
            'restart_hysteresis_hours' => max(0.0, (float)($cfg['restart_hysteresis_hours'] ?? 6.0)),
            'no_click_expected_clicks' => max(1.0, (float)($cfg['no_click_expected_clicks'] ?? 3.0)),
            'min_intraday_spend_ratio' => max(0.0, (float)($cfg['min_intraday_spend_ratio'] ?? 0.5)),
            'balanced_mode' => filter_var($cfg['balanced_mode'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'v1_stop_guard' => filter_var($cfg['v1_stop_guard'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'breathe_payouts' => max(0.5, (float)($cfg['breathe_payouts'] ?? 2.0)),
            'high_confidence_payouts' => max(1.0, (float)($cfg['high_confidence_payouts'] ?? 3.0)),
            'protect_min_p_profit' => max(0, min(100, (int)($cfg['protect_min_p_profit'] ?? 90))),
            'start_min_p_profit' => max(0, min(100, (int)($cfg['start_min_p_profit'] ?? 80))),
            'stop_on_v1_stop_after_payouts' => max(0.0, (float)($cfg['stop_on_v1_stop_after_payouts'] ?? 1.0)),
            'review_min_p_profit' => max(0, min(100, (int)($cfg['review_min_p_profit'] ?? 65))),
            'high_confidence_min_p_profit' => max(0, min(100, (int)($cfg['high_confidence_min_p_profit'] ?? 80))),
        ];
    }

    private function applyRestartHysteresis(array $verdict): array
    {
        $cfg = $this->shadowConfig();
        $hours = (float)($cfg['restart_hysteresis_hours'] ?? 6.0);
        if ($hours <= 0 || empty($verdict['candidate_should_change'])) {
            return $verdict;
        }
        if (strtoupper((string)($verdict['candidate_desired_status'] ?? '')) !== 'ACTIVE') {
            return $verdict;
        }

        $campaignId = trim((string)($verdict['campaign_id'] ?? ''));
        if ($campaignId === '') {
            return $verdict;
        }

        $pause = $this->latestAutoRulePauseToday($campaignId);
        if (!$pause) {
            return $verdict;
        }

        $pausedAt = new DateTimeImmutable((string)$pause['paused_at']);
        $restartAfter = $pausedAt->modify('+' . $hours . ' hours');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($now >= $restartAfter) {
            $verdict['auto_rules_restart_hysteresis'] = [
                'blocked' => false,
                'hours' => $hours,
                'paused_at' => $pausedAt->format(DATE_ATOM),
                'restart_after' => $restartAfter->format(DATE_ATOM),
                'pause_task_id' => (int)($pause['id'] ?? 0),
            ];
            return $verdict;
        }

        $original = [
            'candidate_verdict' => $verdict['candidate_verdict'] ?? null,
            'candidate_action' => $verdict['candidate_action'] ?? null,
            'candidate_level' => $verdict['candidate_level'] ?? null,
            'candidate_desired_status' => $verdict['candidate_desired_status'] ?? null,
            'candidate_should_change' => (bool)($verdict['candidate_should_change'] ?? false),
            'candidate_reason' => $verdict['candidate_reason'] ?? '',
        ];
        $localPausedAt = $pausedAt->setTimezone(appDateTimeZone($this->tz));
        $localRestartAfter = $restartAfter->setTimezone(appDateTimeZone($this->tz));

        $verdict['candidate_verdict'] = 'START_DELAYED';
        $verdict['candidate_action'] = 'WAIT';
        $verdict['candidate_level'] = 'restart_hysteresis';
        $verdict['candidate_desired_status'] = null;
        $verdict['candidate_should_change'] = false;
        $verdict['restart_policy'] = 'hysteresis';
        $verdict['candidate_reason'] = sprintf(
            'Restart hysteresis: auto rules paused this campaign today at %s; auto restart is allowed after %s.',
            $localPausedAt->format('Y-m-d H:i:s T'),
            $localRestartAfter->format('Y-m-d H:i:s T')
        );
        $verdict['auto_rules_restart_hysteresis'] = [
            'blocked' => true,
            'hours' => $hours,
            'paused_at' => $pausedAt->format(DATE_ATOM),
            'restart_after' => $restartAfter->format(DATE_ATOM),
            'pause_task_id' => (int)($pause['id'] ?? 0),
            'original' => $original,
        ];
        return $verdict;
    }

    private function latestAutoRulePauseToday(string $campaignId): ?array
    {
        $localStart = new DateTimeImmutable('today', appDateTimeZone($this->tz));
        $utcStart = $localStart->setTimezone(new DateTimeZone('UTC'));
        try {
            $stmt = $this->db->prepare("
                SELECT
                    id,
                    COALESCE(finished_at, updated_at, created_at) AS paused_at
                FROM public.tasks
                WHERE task_type = 'set_campaign_status'
                  AND campaign_id = :campaign_id
                  AND status = 'done'
                  AND payload->>'source' = 'auto_rules_cron'
                  AND payload->>'desired_status' = 'PAUSED'
                  AND COALESCE(finished_at, updated_at, created_at) >= :today_start
                ORDER BY COALESCE(finished_at, updated_at, created_at) DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':today_start' => $utcStart->format('Y-m-d H:i:sP'),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function shadowCandidate(array $verdict): array
    {
        $cfg = $this->shadowConfig();
        $base = [
            'candidate_verdict' => 'DISABLED',
            'candidate_action' => 'NO_ACTION',
            'candidate_level' => 'shadow',
            'candidate_desired_status' => null,
            'candidate_should_change' => false,
            'candidate_reason' => 'Auto rules v2 shadow evaluation is disabled.',
            'restart_policy' => 'none',
            'potential_score' => null,
            'candidate_score_breakdown' => [],
            'baseline_diff' => [],
            'shadow_only' => $cfg['shadow_only'],
        ];

        if (!$cfg['enabled']) {
            return $base;
        }

        $status = strtoupper((string)($verdict['fb_status'] ?? ''));
        $currentVerdict = strtoupper((string)($verdict['verdict'] ?? ''));
        if (in_array($currentVerdict, ['NO_GEO', 'NO_RULES', 'MANUAL_STOP', 'IGNORED_STATUS'], true)) {
            return array_merge($base, [
                'candidate_verdict' => $currentVerdict,
                'candidate_level' => 'eligibility',
                'candidate_reason' => 'V2 keeps this campaign informational because the current rules verdict is ' . $currentVerdict . '.',
                'restart_policy' => $currentVerdict === 'MANUAL_STOP' ? 'manual_only' : 'none',
            ]);
        }

        $today = $verdict['data_1d'] ?? [];
        $last7 = $verdict['data_7d'] ?? [];
        $last30 = $verdict['data_30d'] ?? [];
        $alltime = $verdict['data_alltime'] ?? $last30;
        $baseline = $verdict['v2_baseline'] ?? [];
        $payout = $this->shadowPayout($verdict);
        $targetCpd = $this->shadowTargetCpd($verdict, $payout);
        $isActive = $status === 'ACTIVE';
        $model = $this->bayesianProfitModel($today, $last7, $last30, $alltime, $baseline, $payout, $targetCpd);
        $pProfit = (int)$model['p_profit'];
        $baselineDiff = $this->v2BaselineDiff($today, $baseline);

        $todayStop = $this->todayEmergencyStopReason($today, $baseline, $payout, (float)$cfg['min_intraday_spend_ratio'], $targetCpd, (float)$cfg['no_click_expected_clicks']);
        if ($isActive && $todayStop !== null) {
            return $this->v2Decision($base, 'PAUSE_TODAY', 'PAUSE', 'intraday_guard', 'PAUSED', true, $todayStop, 'restart_tomorrow', $pProfit, $model, $baselineDiff);
        }

        $alltimeSpend = (float)($alltime['spend'] ?? 0);
        $alltimeProfit = (float)($alltime['profit'] ?? ((float)($alltime['revenue'] ?? 0) - $alltimeSpend));
        $payoutMultiple = $payout > 0 ? $alltimeSpend / $payout : 0.0;
        $breathePayouts = (float)($cfg['breathe_payouts'] ?? 2.0);
        $reviewPayouts = (float)($cfg['high_confidence_payouts'] ?? 3.0);
        $strictPayouts = max($reviewPayouts, (float)($cfg['strict_profit_payouts'] ?? 5.0));
        $testStopPayouts = min($reviewPayouts, max(0.0, (float)($cfg['test_stop_payouts'] ?? 0.5)));
        $stage = $payout <= 0
            ? 'no_payout'
            : ($payoutMultiple >= $strictPayouts
                ? 'strict'
                : ($payoutMultiple >= $reviewPayouts
                    ? 'high_confidence'
                    : ($payoutMultiple >= $breathePayouts ? 'review' : 'test')));
        $last7Profit = $this->periodHasProfitableDeps($last7);
        $alltimeProfitPositive = $alltimeProfit > 0;
        $todayRed = $this->periodHasMeaningfulNegativeSpend($today, $payout, (float)$cfg['min_intraday_spend_ratio']);
        $v1Stop = $currentVerdict === 'STOP';
        $v1HoldStop = $currentVerdict === 'HOLD_STOP';

        if (!empty($cfg['balanced_mode']) && !empty($cfg['v1_stop_guard'])) {
            if ($isActive && $v1Stop && $todayRed && !$last7Profit) {
                return $this->v2Decision($base, 'PAUSE_TODAY', 'PAUSE', 'v1_stop_guard', 'PAUSED', true, 'Balanced guard: V1 is STOP, today is negative after meaningful spend, and 7D does not override with profitable deps.', 'restart_tomorrow', $pProfit, $model, $baselineDiff);
            }
            if ($isActive && $v1Stop && $payout > 0 && $payoutMultiple >= (float)$cfg['stop_on_v1_stop_after_payouts'] && !$last7Profit && !$alltimeProfitPositive) {
                return $this->v2Decision($base, 'STOP', 'PAUSE', 'v1_stop_guard', 'PAUSED', true, sprintf('Balanced guard: V1 is STOP after %.2f payouts and there is no 7D/all-time profit override.', $payoutMultiple), 'hold_stop', $pProfit, $model, $baselineDiff);
            }
            if (!$isActive && ($v1Stop || $v1HoldStop) && !$last7Profit && !$alltimeProfitPositive && $pProfit < (int)$cfg['start_min_p_profit']) {
                return $this->v2Decision($base, 'HOLD_STOP', 'HOLD_STOP', 'v1_stop_guard', 'PAUSED', false, sprintf('Balanced guard: V1 is %s and p_profit %d%% is below the start threshold without a profit override.', $currentVerdict, $pProfit), 'hold_stop', $pProfit, $model, $baselineDiff);
            }
        }

        if ($stage === 'strict') {
            if ($alltimeProfit > 0) {
                return $isActive
                    ? $this->v2Decision($base, 'PROTECT', 'PROTECT', 'strict_profit', null, false, sprintf('All-time spend is %.2f payouts and ROI is positive, so v2 would protect this campaign.', $payoutMultiple), 'none', $pProfit, $model, $baselineDiff)
                    : $this->v2Decision($base, 'START', 'START', 'strict_profit', 'ACTIVE', true, sprintf('All-time spend is %.2f payouts and ROI is positive, so v2 would start this campaign.', $payoutMultiple), 'restart_tomorrow', $pProfit, $model, $baselineDiff);
            }
            return $this->v2Decision($base, $isActive ? 'STOP' : 'HOLD_STOP', $isActive ? 'PAUSE' : 'HOLD_STOP', 'strict_profit', 'PAUSED', $isActive, sprintf('All-time spend is %.2f payouts and ROI is not positive; after %.1f payouts v2 requires profitable ROI.', $payoutMultiple, $strictPayouts), 'hold_stop', $pProfit, $model, $baselineDiff);
        }

        if ($stage === 'high_confidence') {
            if (!$last7Profit && !$alltimeProfitPositive) {
                return $this->v2Decision($base, $isActive ? 'STOP' : 'HOLD_STOP', $isActive ? 'PAUSE' : 'HOLD_STOP', 'balanced_high_confidence', 'PAUSED', $isActive, sprintf('Balanced mode: all-time spend is %.2f payouts; after %.1f payouts V2 requires real 7D or all-time profit, not only potential.', $payoutMultiple, $reviewPayouts), 'hold_stop', $pProfit, $model, $baselineDiff);
            }
            if ($pProfit < (int)$cfg['high_confidence_min_p_profit']) {
                return $this->v2Decision($base, $isActive ? 'WATCH' : 'HOLD_STOP', $isActive ? 'WATCH' : 'HOLD_STOP', 'balanced_high_confidence', $isActive ? null : 'PAUSED', false, sprintf('Balanced mode: profit exists, but p_profit %d%% is below the high-confidence threshold %d%%.', $pProfit, (int)$cfg['high_confidence_min_p_profit']), $isActive ? 'none' : 'hold_stop', $pProfit, $model, $baselineDiff);
            }
            return $isActive
                ? $this->v2Decision($base, 'PROTECT', 'PROTECT', 'balanced_high_confidence', null, false, sprintf('Balanced mode: %.2f payouts with profit override and p_profit %d%%, so V2 protects.', $payoutMultiple, $pProfit), 'none', $pProfit, $model, $baselineDiff)
                : $this->v2Decision($base, 'START', 'START', 'balanced_high_confidence', 'ACTIVE', true, sprintf('Balanced mode: %.2f payouts with profit override and p_profit %d%%, so V2 starts.', $payoutMultiple, $pProfit), 'restart_tomorrow', $pProfit, $model, $baselineDiff);
        }

        if ($stage === 'review') {
            if ($pProfit < (int)$cfg['review_min_p_profit'] && !$last7Profit && !$alltimeProfitPositive) {
                return $this->v2Decision($base, $isActive ? 'STOP' : 'HOLD_STOP', $isActive ? 'PAUSE' : 'HOLD_STOP', 'bayes_review', 'PAUSED', $isActive, sprintf('p_profit is %d%% after %.2f payouts, below the review threshold.', $pProfit, $payoutMultiple), 'hold_stop', $pProfit, $model, $baselineDiff);
            }
            if ($pProfit >= (int)$cfg['protect_min_p_profit'] || $last7Profit || $alltimeProfitPositive) {
                if (!$isActive && $pProfit < (int)$cfg['start_min_p_profit']) {
                    return $this->v2Decision($base, 'WATCH', 'WATCH', 'bayes_review', null, false, sprintf('p_profit is %d%% after %.2f payouts; V2 will not start below the %d%% start threshold.', $pProfit, $payoutMultiple, (int)$cfg['start_min_p_profit']), 'restart_tomorrow', $pProfit, $model, $baselineDiff);
                }
                return $isActive
                    ? $this->v2Decision($base, 'PROTECT', 'PROTECT', 'bayes_review', null, false, sprintf('p_profit is %d%% after %.2f payouts, so v2 would keep testing.', $pProfit, $payoutMultiple), 'none', $pProfit, $model, $baselineDiff)
                    : $this->v2Decision($base, 'START', 'START', 'bayes_review', 'ACTIVE', true, sprintf('p_profit is %d%% after %.2f payouts, so v2 would restart.', $pProfit, $payoutMultiple), 'restart_tomorrow', $pProfit, $model, $baselineDiff);
            }
            return $this->v2Decision($base, 'WATCH', 'WATCH', 'bayes_review', null, false, sprintf('p_profit is %d%% after %.2f payouts; v2 would keep this under review.', $pProfit, $payoutMultiple), $isActive ? 'none' : 'restart_tomorrow', $pProfit, $model, $baselineDiff);
        }

        if ($payout > 0 && $payoutMultiple >= $testStopPayouts && $pProfit < 35) {
            return $this->v2Decision($base, $isActive ? 'STOP' : 'HOLD_STOP', $isActive ? 'PAUSE' : 'HOLD_STOP', 'bayes_test', 'PAUSED', $isActive, sprintf('p_profit is %d%% after %.2f payouts with weak funnel evidence, so v2 would stop the test.', $pProfit, $payoutMultiple), 'hold_stop', $pProfit, $model, $baselineDiff);
        }
        $testProtectThreshold = !empty($cfg['balanced_mode']) ? (int)$cfg['protect_min_p_profit'] : 55;
        $testStartThreshold = !empty($cfg['balanced_mode']) ? (int)$cfg['start_min_p_profit'] : 55;
        if ($isActive && ($pProfit >= $testProtectThreshold || $last7Profit || $alltimeProfitPositive)) {
            if ($todayRed && !$last7Profit && !$alltimeProfitPositive) {
                return $this->v2Decision($base, 'WATCH', 'WATCH', 'bayes_test', null, false, sprintf('p_profit is %d%%, but today is negative after meaningful spend; balanced mode watches instead of protecting.', $pProfit), 'none', $pProfit, $model, $baselineDiff);
            }
            return $this->v2Decision($base, 'PROTECT', 'PROTECT', 'bayes_test', null, false, sprintf('p_profit is %d%% under %.1f payouts, so v2 would let this campaign breathe.', $pProfit, $reviewPayouts), 'none', $pProfit, $model, $baselineDiff);
        }
        if (!$isActive && $pProfit >= $testStartThreshold && ($last7Profit || $alltimeProfitPositive || !$v1HoldStop)) {
            return $isActive
                ? $this->v2Decision($base, 'PROTECT', 'PROTECT', 'bayes_test', null, false, sprintf('p_profit is %d%% under %.1f payouts, so v2 would let this campaign breathe.', $pProfit, $reviewPayouts), 'none', $pProfit, $model, $baselineDiff)
                : $this->v2Decision($base, 'START', 'START', 'bayes_test', 'ACTIVE', true, sprintf('p_profit is %d%% under %.1f payouts, so v2 would start this campaign.', $pProfit, $reviewPayouts), 'restart_tomorrow', $pProfit, $model, $baselineDiff);
        }

        return $this->v2Decision($base, 'WATCH', 'WATCH', 'bayes_test', null, false, sprintf('p_profit is %d%% under %.1f payouts; v2 would keep collecting data.', $pProfit, $reviewPayouts), $isActive ? 'none' : 'restart_tomorrow', $pProfit, $model, $baselineDiff);
    }

    private function periodHasProfitableDeps(array $data): bool
    {
        $spend = (float)($data['spend'] ?? 0);
        $revenue = (float)($data['revenue'] ?? 0);
        $profit = (float)($data['profit'] ?? ($revenue - $spend));
        return $spend > 0 && $profit > 0 && (float)($data['deps'] ?? 0) > 0;
    }

    private function periodHasMeaningfulNegativeSpend(array $data, float $payout, float $minSpendRatio): bool
    {
        $spend = (float)($data['spend'] ?? 0);
        $revenue = (float)($data['revenue'] ?? 0);
        $profit = (float)($data['profit'] ?? ($revenue - $spend));
        $minSpend = $payout > 0 ? max(1.0, $minSpendRatio * $payout) : 10.0;
        return $spend >= $minSpend && $profit < 0;
    }

    private function v2Decision(array $base, string $verdict, string $action, string $level, ?string $desiredStatus, bool $shouldChange, string $reason, string $restartPolicy, int $pProfit, array $model, array $baselineDiff): array
    {
        return array_merge($base, [
            'candidate_verdict' => $verdict,
            'candidate_action' => $action,
            'candidate_level' => $level,
            'candidate_desired_status' => $desiredStatus,
            'candidate_should_change' => $shouldChange,
            'candidate_reason' => $reason,
            'restart_policy' => $restartPolicy,
            'potential_score' => $pProfit,
            'candidate_score_breakdown' => $model,
            'baseline_diff' => $baselineDiff,
        ]);
    }

    private function todayEmergencyStopReason(array $today, array $baseline, float $payout, float $minSpendRatio, float $targetCpd, float $minExpectedClicks): ?string
    {
        $spend = (float)($today['spend'] ?? 0);
        $roi = (float)($today['roi'] ?? 0);
        $clicks = (float)($today['clicks'] ?? 0);
        $leads = (float)($today['leads'] ?? 0);
        $regs = (float)($today['regs'] ?? 0);
        $deps = (float)($today['deps'] ?? 0);
        $revenue = (float)($today['revenue'] ?? 0);
        $profit = (float)($today['profit'] ?? ($revenue - $spend));
        $cpc = (float)($today['cpc'] ?? 0);
        $cpl = $today['cpl'] ?? null;
        $cpr = $today['cpr'] ?? null;
        $cpd = $today['cpd'] ?? null;
        $c2l = $today['c2l'] ?? null;
        $baselineCpc = (float)($baseline['cpc'] ?? 0);
        $baselineCpl = $baseline['cpl'] ?? null;
        $baselineCpr = $baseline['cpr'] ?? null;
        $baselineC2l = $baseline['c2l'] ?? null;
        $minSpend = $payout > 0 ? max(1.0, $minSpendRatio * $payout) : 10.0;

        if ($spend > 0 && $clicks <= 0 && $baselineCpc > 0) {
            $expectedClicks = $spend / $baselineCpc;
            if ($expectedClicks >= $minExpectedClicks) {
                return sprintf('Today emergency stop: spend %.2f with 0 clicks; profitable baseline CPC %.2f implies about %.1f expected clicks, above the %.1f-click guard.', $spend, $baselineCpc, $expectedClicks, $minExpectedClicks);
            }
        }

        if ($spend < $minSpend) {
            return null;
        }

        if ($profit > 0 && $deps > 0) {
            return null;
        }

        if ($deps > 0) {
            if ($targetCpd > 0 && $cpd !== null && (float)$cpd > 1.5 * $targetCpd && $roi < 0) {
                return sprintf('Today emergency stop: spend %.2f reached the intraday threshold %.2f, today has deps but CPD %.2f is above target %.2f and ROI is negative.', $spend, $minSpend, (float)$cpd, $targetCpd);
            }
            return null;
        }

        if ($regs > 0) {
            if ($baselineCpr !== null && (float)$baselineCpr > 0 && $cpr !== null && (float)$cpr >= 3.0 * (float)$baselineCpr && $roi < 0) {
                return sprintf('Today emergency stop: spend %.2f reached the intraday threshold %.2f, today has regs but CPR %.2f is at least 3x worse than profitable baseline %.2f.', $spend, $minSpend, (float)$cpr, (float)$baselineCpr);
            }
            return null;
        }

        if ($leads > 0) {
            if ($baselineCpl !== null && (float)$baselineCpl > 0 && $cpl !== null && (float)$cpl >= 3.0 * (float)$baselineCpl && $roi < 0) {
                return sprintf('Today emergency stop: spend %.2f reached the intraday threshold %.2f, today has leads but CPL %.2f is at least 3x worse than profitable baseline %.2f.', $spend, $minSpend, (float)$cpl, (float)$baselineCpl);
            }
            return null;
        }

        if ($baselineCpc > 0 && $cpc > 0 && $cpc >= 3.0 * $baselineCpc) {
            return sprintf('Today emergency stop: spend %.2f reached the intraday threshold %.2f, today has no leads/regs/deps, and CPC %.2f is at least 3x worse than profitable baseline %.2f.', $spend, $minSpend, $cpc, $baselineCpc);
        }
        if ($baselineC2l !== null && (float)$baselineC2l > 0 && $clicks >= 10 && $c2l !== null && (float)$c2l <= (float)$baselineC2l / 3.0) {
            return sprintf('Today emergency stop: spend %.2f reached the intraday threshold %.2f, today has no leads/regs/deps, and C2L %.2f%% is at least 3x worse than profitable baseline %.2f%%.', $spend, $minSpend, (float)$c2l, (float)$baselineC2l);
        }
        if ($roi < 0 && !$this->todayBeatsBaselineBy25($today, $baseline)) {
            return sprintf('Today emergency stop: spend %.2f reached the intraday threshold %.2f, ROI is negative, and current metrics are not 25%% better than the profitable 30D baseline.', $spend, $minSpend);
        }
        return null;
    }

    private function todayBeatsBaselineBy25(array $today, array $baseline): bool
    {
        $checks = [];
        foreach ([['cpd', 'cpd'], ['cpr', 'cpr'], ['cpl', 'cpl'], ['cpc', 'cpc']] as [$metric, $baseMetric]) {
            $actual = $today[$metric] ?? null;
            $base = $baseline[$baseMetric] ?? null;
            if ($actual !== null && $base !== null && (float)$actual > 0 && (float)$base > 0) {
                $checks[] = (float)$actual <= 0.75 * (float)$base;
                break;
            }
        }
        if (($today['c2l'] ?? null) !== null && ($baseline['c2l'] ?? null) !== null && (float)$baseline['c2l'] > 0) {
            $checks[] = (float)$today['c2l'] >= 1.25 * (float)$baseline['c2l'];
        }
        return $checks && !in_array(false, $checks, true);
    }

    private function bayesianProfitModel(array $today, array $last7, array $last30, array $alltime, array $baseline, float $payout, float $targetCpd): array
    {
        $p = 50;
        $notes = [];
        $prior = 'neutral';

        if ((float)($baseline['payout'] ?? 0) > 0 && (float)($baseline['cpc'] ?? 0) > 0) {
            $p += 5;
            $prior = 'profitable baseline available';
            $notes[] = 'prior +5: profitable baseline available';
        }

        $p += $this->probabilityEvidenceDelta($last7, $baseline, $targetCpd, $payout, '7d', $notes);
        $p += (int)round($this->probabilityEvidenceDelta($last30, $baseline, $targetCpd, $payout, '30d', $notes) * 0.5);
        $p += (int)round($this->probabilityEvidenceDelta($today, $baseline, $targetCpd, $payout, 'today', $notes) * 0.35);

        $alltimeSpend = (float)($alltime['spend'] ?? 0);
        $alltimeProfit = (float)($alltime['profit'] ?? ((float)($alltime['revenue'] ?? 0) - $alltimeSpend));
        $payoutMultiple = $payout > 0 ? $alltimeSpend / $payout : 0.0;
        if ($payoutMultiple >= 3.0 && $alltimeProfit <= 0) {
            $p -= 15;
            $notes[] = 'all-time spend >= 3 payouts without positive profit -15';
        } elseif ($alltimeProfit > 0) {
            $p += 10;
            $notes[] = 'all-time profit positive +10';
        }

        $p = max(0, min(100, $p));
        return [
            'model' => 'bayesian-style probability',
            'p_profit' => $p,
            'score' => $p,
            'prior' => $prior,
            'baseline_source' => 'profitable 30D baseline',
            'payout_multiple' => round($payoutMultiple, 4),
            'notes' => $notes,
        ];
    }

    private function probabilityEvidenceDelta(array $data, array $baseline, float $targetCpd, float $payout, string $label, array &$notes): int
    {
        $delta = 0;
        $spend = (float)($data['spend'] ?? 0);
        $clicks = (float)($data['clicks'] ?? 0);
        $leads = (float)($data['leads'] ?? 0);
        $regs = (float)($data['regs'] ?? 0);
        $deps = (float)($data['deps'] ?? 0);
        $revenue = (float)($data['revenue'] ?? 0);
        $profit = (float)($data['profit'] ?? ($revenue - $spend));
        if ($spend <= 0) {
            $notes[] = "{$label}: no spend, neutral";
            return 0;
        }

        if ($profit > 0) {
            $delta += 18;
            $notes[] = "{$label}: profit positive +18";
        } elseif ($payout > 0 && $spend >= $payout && $revenue <= 0) {
            $delta -= 15;
            $notes[] = "{$label}: >=1 payout spend with no revenue -15";
        }

        if ($clicks <= 0) {
            $baselineCpc = (float)($baseline['cpc'] ?? 0);
            if ($baselineCpc > 0) {
                $expectedClicks = $spend / $baselineCpc;
                if ($expectedClicks >= 8.0) {
                    $delta -= 30;
                    $notes[] = sprintf('%s: spend with 0 clicks despite %.1f expected clicks -30', $label, $expectedClicks);
                } elseif ($expectedClicks >= 3.0) {
                    $delta -= 12;
                    $notes[] = sprintf('%s: spend with 0 clicks despite %.1f expected clicks -12', $label, $expectedClicks);
                }
            }
            return $delta;
        }

        if ($deps > 0) {
            $delta += $deps >= 3 ? 18 : 12;
            $notes[] = "{$label}: deps present +" . ($deps >= 3 ? '18' : '12');
            $delta += $this->metricCompareDelta($data, $baseline, 'cpd', false, $label, $notes);
            if (($data['cpd'] ?? null) !== null && $targetCpd > 0) {
                if ((float)$data['cpd'] <= $targetCpd) {
                    $delta += 10;
                    $notes[] = "{$label}: CPD at/below target +10";
                } else {
                    $delta -= 12;
                    $notes[] = "{$label}: CPD above target -12";
                }
            }
        } elseif ($regs > 0) {
            $delta += 7;
            $notes[] = "{$label}: regs present +7";
            $delta += $this->metricCompareDelta($data, $baseline, 'cpr', false, $label, $notes);
            $delta += $this->metricCompareDelta($data, $baseline, 'r2d', true, $label, $notes);
        } elseif ($leads > 0) {
            $delta += 4;
            $notes[] = "{$label}: leads present +4";
            $delta += $this->metricCompareDelta($data, $baseline, 'cpl', false, $label, $notes);
            $delta += $this->metricCompareDelta($data, $baseline, 'c2l', true, $label, $notes);
        } elseif ($payout > 0 && $spend >= $payout) {
            $delta -= 15;
            $notes[] = "{$label}: no funnel after payout spend -15";
            $delta += $this->metricCompareDelta($data, $baseline, 'cpc', false, $label, $notes);
            $delta += $this->metricCompareDelta($data, $baseline, 'c2l', true, $label, $notes);
        } elseif ($clicks > 0) {
            $delta += $this->metricCompareDelta($data, $baseline, 'cpc', false, $label, $notes);
            $delta += $this->metricCompareDelta($data, $baseline, 'c2l', true, $label, $notes);
        }
        return $delta;
    }

    private function metricCompareDelta(array $data, array $baseline, string $metric, bool $higherIsBetter, string $label, array &$notes): int
    {
        $actual = $data[$metric] ?? null;
        $base = $baseline[$metric] ?? null;
        if ($actual === null || $base === null || (float)$base <= 0) {
            return 0;
        }
        if ($higherIsBetter ? (float)$actual < 0 : (float)$actual <= 0) {
            return 0;
        }
        $ratio = (float)$actual / (float)$base;
        if ($higherIsBetter) {
            if ($ratio >= 1.25) {
                $notes[] = "{$label}: {$metric} 25%+ better +8";
                return 8;
            }
            if ($ratio <= 1 / 3) {
                $notes[] = "{$label}: {$metric} 3x worse -25";
                return -25;
            }
            if ($ratio <= 0.67) {
                $notes[] = "{$label}: {$metric} weak -10";
                return -10;
            }
            return 0;
        }
        if ($ratio <= 0.75) {
            $notes[] = "{$label}: {$metric} 25%+ better +8";
            return 8;
        }
        if ($metric === 'cpc' && $ratio >= 3.0) {
            $notes[] = "{$label}: CPC 3x worse -25";
            return -25;
        }
        if ($ratio >= 1.5) {
            $notes[] = "{$label}: {$metric} expensive -10";
            return -10;
        }
        return 0;
    }

    private function v2BaselineDiff(array $today, array $baseline): array
    {
        $out = [];
        foreach (['cpc', 'cpl', 'cpr', 'cpd', 'c2l'] as $metric) {
            $actual = $today[$metric] ?? null;
            $base = $baseline[$metric] ?? null;
            $higherIsBetter = in_array($metric, ['c2l'], true);
            if ($actual === null || $base === null || (float)$base <= 0) {
                continue;
            }
            if ($higherIsBetter ? (float)$actual < 0 : (float)$actual <= 0) {
                continue;
            }
            $ratio = (float)$actual / (float)$base;
            $miss = $higherIsBetter ? $ratio < 0.75 : $ratio > 1.25;
            $out[$metric] = [
                'actual' => round((float)$actual, 4),
                'baseline' => round((float)$base, 4),
                'ratio' => round($ratio, 4),
                'miss' => $miss,
                'diff_pct' => round(($ratio - 1.0) * 100, 2),
            ];
        }
        return $out;
    }

    private function shadowPayout(array $verdict): float
    {
        $payout = (float)($verdict['shadow_payout'] ?? 0);
        if ($payout > 0) {
            return $payout;
        }
        foreach (['limits_db_30d', 'limits_db_1d'] as $bucket) {
            $limit = (float)($verdict[$bucket]['MAXDEP30D'] ?? $verdict[$bucket]['MAXDEP1D'] ?? 0);
            if ($limit > 0) {
                return $limit;
            }
        }
        return 0.0;
    }

    private function shadowTargetCpd(array $verdict, float $payout): float
    {
        foreach (['limits_db_30d', 'limits_db_1d'] as $bucket) {
            $limit = (float)($verdict[$bucket]['MAXDEP30D'] ?? $verdict[$bucket]['MAXDEP1D'] ?? 0);
            if ($limit > 0) {
                return $limit;
            }
        }
        return $payout;
    }

    private function reasonShort(string $reason): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
        $text = preg_replace('/^DB:\s*/i', '', $text) ?? $text;
        if (strlen($text) > 140) {
            $cut = strrpos(substr($text, 0, 140), ' ');
            $text = $cut !== false ? substr($text, 0, $cut) . '...' : substr($text, 0, 140) . '...';
        }
        return $text !== '' ? $text : 'No explanation';
    }

    private function reasonDetail(array $verdict): string
    {
        $parts = [];
        $reason = trim((string)($verdict['reason'] ?? ''));
        if ($reason !== '') {
            $parts[] = $reason;
        }
        $startReason = trim((string)($verdict['start_reason'] ?? ''));
        if ($startReason !== '' && $startReason !== $reason) {
            $parts[] = 'Start gate: ' . $startReason;
        }
        $startBlock = trim((string)($verdict['start_block'] ?? ''));
        if ($startBlock !== '') {
            $parts[] = 'Blocked by: ' . $startBlock;
        }
        $v1d = $verdict['violation_1d'] ?? null;
        $v30d = $verdict['violation_30d'] ?? null;
        if (is_array($v1d) && !empty($v1d['metric'])) {
            $parts[] = '1D ' . $v1d['metric'] . ': ' . (string)($v1d['reason'] ?? '');
        }
        if (is_array($v30d) && !empty($v30d['metric'])) {
            $parts[] = '30D ' . $v30d['metric'] . ': ' . (string)($v30d['reason'] ?? '');
        }
        return implode(' | ', array_values(array_filter($parts, static fn($v) => trim((string)$v) !== '')));
    }

    private function signalLevel(array $verdict): string
    {
        $name = strtoupper(trim((string)($verdict['verdict'] ?? '')));
        return match ($name) {
            'STOP', 'HOLD_STOP', 'MANUAL_STOP' => 'high',
            'START', 'OK' => 'medium',
            default => 'low',
        };
    }

    private function whyNow(array $verdict): string
    {
        $name = strtoupper(trim((string)($verdict['verdict'] ?? '')));
        return match ($name) {
            'STOP' => 'The active campaign crossed a limit now, so pausing it reduces further loss before the next check.',
            'HOLD_STOP' => 'The object is still paused and restart conditions are not satisfied yet.',
            'START' => '1D is clean and the 30D restart gate is open, so it can be restarted now.',
            'OK' => 'No current violation is detected, so no action is needed now.',
            'MANUAL_STOP' => 'Manual stop must be respected, so auto rules do not restart this campaign.',
            'NO_GEO' => 'The campaign name has no GEO token, so rules cannot attribute it safely.',
            'NO_RULES' => 'There are no DB limits for this GEO, so there is no basis for action.',
            'IGNORED_STATUS' => 'This campaign status is excluded from auto rules, so the verdict stays informational.',
            default => 'The current metrics triggered this verdict during the latest dry-run check.',
        };
    }

    private function violationText(?array $v1d, ?array $v30d): string
    {
        $parts = [];
        if ($v1d) {
            $parts[] = '1D ' . $v1d['metric'] . ': ' . $v1d['reason'];
        }
        if ($v30d) {
            $parts[] = '30D ' . $v30d['metric'] . ': ' . $v30d['reason'];
        }
        return implode('; ', $parts);
    }

    private function checkViolation(array $d, array $limits, string $sfx, bool $skipCpc): ?array
    {
        if (!$limits) {
            return null;
        }

        $spend = (float)$d['spend'];
        $deps = (float)$d['deps'];
        $regs = (float)$d['regs'];
        $leads = (float)$d['leads'];
        $fbCpc = (float)$d['cpc'];

        $maxDep = $limits["MAXDEP{$sfx}"] ?? null;
        $maxReg = $limits["MAXREG{$sfx}"] ?? null;
        $maxLead = $limits["MAXLEAD{$sfx}"] ?? null;
        $maxCpc = $skipCpc ? null : ($limits["MAXCPC{$sfx}"] ?? null);

        if ($spend == 0.0) {
            return null;
        }

        $lowC2lViolation = $this->lowC2lTodayViolation($d, $limits, $sfx);
        if ($lowC2lViolation) {
            return $lowC2lViolation;
        }

        if ($maxDep) {
            if ($deps > 0) {
                $cpd = $spend / $deps;
                if ($cpd > $maxDep) {
                    return $this->violation('CPD', $cpd, $maxDep, sprintf('%.2f / %.0f deps = %.2f', $spend, $deps, $cpd));
                }
                return null;
            }
            if ($spend > $maxDep) {
                return $this->violation('CPD', $spend, $maxDep, sprintf('spend %.2f > %.2f with 0 deps', $spend, $maxDep));
            }
        }

        if ($maxReg && $deps == 0.0) {
            if ($regs > 0) {
                $cpr = $spend / $regs;
                if ($cpr > $maxReg) {
                    return $this->violation('CPR', $cpr, $maxReg, sprintf('%.2f / %.0f regs = %.2f', $spend, $regs, $cpr));
                }
                return null;
            }
            if ($spend > $maxReg) {
                return $this->violation('CPR', $spend, $maxReg, sprintf('spend %.2f > %.2f with 0 regs', $spend, $maxReg));
            }
        }

        if ($maxLead && $deps == 0.0 && $regs == 0.0) {
            if ($leads > 0) {
                $cpl = $spend / $leads;
                if ($cpl > $maxLead) {
                    return $this->violation('CPL', $cpl, $maxLead, sprintf('%.2f / %.0f leads = %.2f', $spend, $leads, $cpl));
                }
                return null;
            }
            if ($spend > $maxLead) {
                return $this->violation('CPL', $spend, $maxLead, sprintf('spend %.2f > %.2f with 0 leads', $spend, $maxLead));
            }
        }

        if ($maxCpc && $deps == 0.0 && $regs == 0.0 && $leads == 0.0) {
            if ($fbCpc > 0) {
                if ($fbCpc > $maxCpc) {
                    return $this->violation('CPC', $fbCpc, $maxCpc, sprintf('fbCpc %.2f > %.2f', $fbCpc, $maxCpc));
                }
            } elseif ($spend > $maxCpc) {
                return $this->violation('CPC', $spend, $maxCpc, sprintf('spend %.2f > %.2f with 0 clicks', $spend, $maxCpc));
            }
        }

        return null;
    }

    private function checkStartCondition(array $d, array $limits, string $sfx, bool $skipCpc): array
    {
        if (!$limits) {
            return ['ok' => false, 'reason' => 'no limits'];
        }

        $spend = (float)$d['spend'];
        $deps = (float)$d['deps'];
        $regs = (float)$d['regs'];
        $leads = (float)$d['leads'];
        $fbCpc = (float)$d['cpc'];

        $maxDep = $limits["MAXDEP{$sfx}"] ?? null;
        $maxReg = $limits["MAXREG{$sfx}"] ?? null;
        $maxLead = $limits["MAXLEAD{$sfx}"] ?? null;
        $maxCpc = $skipCpc ? null : ($limits["MAXCPC{$sfx}"] ?? null);

        if ($spend == 0.0) {
            return ['ok' => true, 'reason' => 'spend=0, no violations'];
        }

        if ($deps > 0 && $maxDep) {
            $cpd = $spend / $deps;
            return $cpd < $maxDep
                ? ['ok' => true, 'reason' => sprintf('CPD=%.2f < %.2f', $cpd, $maxDep)]
                : ['ok' => false, 'reason' => sprintf('CPD=%.2f >= %.2f', $cpd, $maxDep)];
        }

        if ($regs > 0 && $deps == 0.0 && $maxReg) {
            $cpr = $spend / $regs;
            $spendOk = !$maxDep || $spend < $maxDep;
            return ($cpr < $maxReg && $spendOk)
                ? ['ok' => true, 'reason' => sprintf('CPR=%.2f < %.2f', $cpr, $maxReg)]
                : ['ok' => false, 'reason' => sprintf('CPR=%.2f or spend>=maxDep', $cpr)];
        }

        if ($leads > 0 && $regs == 0.0 && $deps == 0.0 && $maxLead) {
            $cpl = $spend / $leads;
            $spendOk = !$maxReg || $spend < $maxReg;
            return ($cpl < $maxLead && $spendOk)
                ? ['ok' => true, 'reason' => sprintf('CPL=%.2f < %.2f', $cpl, $maxLead)]
                : ['ok' => false, 'reason' => sprintf('CPL=%.2f or spend>=maxReg', $cpl)];
        }

        if ($leads == 0.0 && $regs == 0.0 && $deps == 0.0 && $maxCpc) {
            $spendOk = !$maxLead || $spend < $maxLead;
            return ($fbCpc < $maxCpc && $spendOk)
                ? ['ok' => true, 'reason' => sprintf('CPC=%.2f < %.2f', $fbCpc, $maxCpc)]
                : ['ok' => false, 'reason' => sprintf('CPC=%.2f or spend>=maxLead', $fbCpc)];
        }

        return ['ok' => false, 'reason' => 'no events to evaluate'];
    }

    private function lowC2lTodayViolation(array $d, array $limits, string $sfx): ?array
    {
        if ($sfx !== '1D') {
            return null;
        }

        $cfg = $this->cfg['auto_rules'] ?? [];
        $enabled = filter_var($cfg['low_c2l_today_guard_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return null;
        }

        $spend = (float)($d['spend'] ?? 0);
        $clicks = (float)($d['clicks'] ?? 0);
        $leads = (float)($d['leads'] ?? 0);
        $regs = (float)($d['regs'] ?? 0);
        $deps = (float)($d['deps'] ?? 0);
        $c2l = $d['c2l'] ?? null;
        $baselineC2l = (float)($limits["BASEC2L{$sfx}"] ?? 0);

        if ($spend <= 0 || $clicks <= 0 || $regs > 0 || $deps > 0) {
            return null;
        }
        if ($baselineC2l <= 0) {
            return null;
        }

        $minClicks = max(1.0, (float)($cfg['low_c2l_today_min_clicks'] ?? 10.0));
        $badFactor = max(1.0, (float)($cfg['low_c2l_today_bad_factor'] ?? 3.0));
        $actualC2l = $c2l === null ? ($clicks > 0 ? $leads / $clicks * 100.0 : null) : (float)$c2l;

        if ($actualC2l === null || $clicks < $minClicks) {
            return null;
        }
        if ($actualC2l > $baselineC2l / $badFactor) {
            return null;
        }

        return $this->violation(
            'LOW_C2L_TODAY',
            $actualC2l,
            $baselineC2l / $badFactor,
            sprintf(
                'today C2L %.2f%% is %.1fx+ worse than baseline %.2f%% after %.0f clicks with 0 leads/regs/deps',
                $actualC2l,
                $badFactor,
                $baselineC2l,
                $clicks
            )
        );
    }

    private function violation(string $metric, float $actual, float $limit, string $reason): array
    {
        return [
            'metric' => $metric,
            'actual' => round($actual, 4),
            'limit' => round($limit, 4),
            'reason' => $reason,
        ];
    }

    private function buildDynamicLimits(array $geoMetrics, float $spend, array $rules, string $sfx): array
    {
        $payout = (float)$geoMetrics['payout'];
        $mult = $sfx === '1D'
            ? max(0.0, (float)($this->cfg['auto_rules']['multiplier_1d'] ?? 0.8))
            : $this->getMultiplier($spend, $payout, $rules);
        return [
            "MAXCPC{$sfx}" => round((float)$geoMetrics['cpc'] * $mult, 4),
            "MAXLEAD{$sfx}" => round((float)$geoMetrics['cpl'] * $mult, 4),
            "MAXREG{$sfx}" => round((float)$geoMetrics['cpr'] * $mult, 4),
            "MAXDEP{$sfx}" => round((float)$geoMetrics['cpd'] * $mult, 4),
            "BASEC2L{$sfx}" => round((float)($geoMetrics['c2l'] ?? 0), 4),
            "PAYOUT{$sfx}" => round($payout, 4),
            "MULT{$sfx}" => $mult,
        ];
    }

    private function getMultiplier(float $spend, float $payout, array $rules): float
    {
        if ($payout <= 0 || !$rules) {
            return 1.0;
        }
        $ratio = $spend / $payout;
        foreach ($rules as $r) {
            $min = (float)($r['spend_min'] ?? 0);
            $max = $r['spend_max'] ?? null;
            if ($ratio >= $min && ($max === null || $ratio < (float)$max)) {
                return (float)($r['multiplier'] ?? 1);
            }
        }
        $last = end($rules);
        return (float)($last['multiplier'] ?? 1);
    }

    private function canUseAlgoA(?array $geoMetrics): bool
    {
        if (!$geoMetrics) {
            return false;
        }
        foreach (['cpc', 'cpl', 'cpr', 'cpd', 'payout'] as $k) {
            if ((float)($geoMetrics[$k] ?? 0) <= 0) {
                return false;
            }
        }
        return true;
    }

    private function fetchCampaigns(string $bmInSql, array $bmParams): array
    {
        $stmt = $this->db->prepare("
            SELECT
                c.id::text,
                c.name,
                c.status,
                c.effective_status,
                c.ad_account_id,
                aa.name AS account_name,
                aa.status AS account_status,
                aa.timezone_name,
                bm.id AS bm_id,
                bm.name AS bm_name
            FROM campaigns c
            JOIN ad_accounts aa ON aa.id = c.ad_account_id
            JOIN business_managers bm ON bm.id = aa.bm_id
            WHERE c.status != 'DELETED'
              AND c.name ~* '^MLR_'
              AND COALESCE(aa.status, 0) = 1
              AND aa.bm_id IN {$bmInSql}
            ORDER BY c.name
        ");
        $stmt->execute($bmParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchCampaignStats(string $dateFrom, string $dateTo, string $bmInSql, array $bmParams): array
    {
        $stmt = $this->db->prepare("
            SELECT
                a.campaign_id::text AS campaign_id,
                COALESCE(SUM(i.spend),0) AS spend,
                COALESCE(SUM(i.clicks),0) AS clicks,
                COALESCE(SUM(i.leads),0) AS leads,
                COALESCE(SUM(i.regs),0) AS regs,
                COALESCE(SUM(i.deps),0) AS deps,
                COALESCE(SUM(i.revenue),0) AS revenue
            FROM insights_daily i
            JOIN ads a ON a.id = i.ad_id
            LEFT JOIN ad_accounts aa ON aa.id = a.ad_account_id
            WHERE i.date >= :date_from
              AND i.date <= :date_to
              AND (aa.bm_id IN {$bmInSql} OR aa.bm_id IS NULL)
              AND a.campaign_id IS NOT NULL
            GROUP BY a.campaign_id
        ");
        $stmt->execute(array_merge([':date_from' => $dateFrom, ':date_to' => $dateTo], $bmParams));
        $rows = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'campaign_id');

        $orphan = $this->db->prepare("
            SELECT
                i.sub_id_10::text AS campaign_id,
                COALESCE(SUM(i.spend),0) AS spend,
                COALESCE(SUM(i.clicks),0) AS clicks,
                COALESCE(SUM(i.leads),0) AS leads,
                COALESCE(SUM(i.regs),0) AS regs,
                COALESCE(SUM(i.deps),0) AS deps,
                COALESCE(SUM(i.revenue),0) AS revenue
            FROM insights_daily i
            LEFT JOIN ads a ON a.id = i.ad_id
            WHERE a.id IS NULL
              AND i.sub_id_10 IS NOT NULL
              AND i.sub_id_10 != ''
              AND i.sub_id_10 NOT LIKE '{%}'
              AND i.date >= :date_from
              AND i.date <= :date_to
            GROUP BY i.sub_id_10
        ");
        $orphan->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        foreach ($orphan->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string)$row['campaign_id'];
            if (!isset($rows[$id])) {
                $rows[$id] = $row;
                continue;
            }
            foreach (['spend', 'clicks', 'leads', 'regs', 'deps', 'revenue'] as $k) {
                $rows[$id][$k] = (float)($rows[$id][$k] ?? 0) + (float)($row[$k] ?? 0);
            }
        }

        foreach ($rows as &$row) {
            $row = $this->normalizeMetricRow($row);
        }
        unset($row);
        return $rows;
    }

    private function normalizeMetricRow(array $row): array
    {
        $spend = (float)($row['spend'] ?? 0);
        $clicks = (float)($row['clicks'] ?? 0);
        $row['spend'] = $spend;
        $row['clicks'] = $clicks;
        $row['leads'] = (float)($row['leads'] ?? 0);
        $row['regs'] = (float)($row['regs'] ?? 0);
        $row['deps'] = (float)($row['deps'] ?? 0);
        $row['revenue'] = (float)($row['revenue'] ?? 0);
        $row['cpc'] = $clicks > 0 ? $spend / $clicks : 0;
        return $row;
    }

    private function metricPayload(array $row): array
    {
        $row = $this->normalizeMetricRow($row);
        $deps = $row['deps'];
        $regs = $row['regs'];
        $leads = $row['leads'];
        $clicks = $row['clicks'];
        $profit = $row['revenue'] - $row['spend'];
        return [
            'spend' => round($row['spend'], 4),
            'clicks' => round($clicks, 4),
            'leads' => round($leads, 4),
            'regs' => round($regs, 4),
            'deps' => round($deps, 4),
            'revenue' => round($row['revenue'], 4),
            'profit' => round($profit, 4),
            'roi' => $row['spend'] > 0 ? round($profit / $row['spend'] * 100, 4) : 0,
            'cpc' => round($row['cpc'], 4),
            'cpl' => $leads > 0 ? round($row['spend'] / $leads, 4) : null,
            'c2l' => $clicks > 0 ? round($leads / $clicks * 100, 4) : null,
            'cpr' => $regs > 0 ? round($row['spend'] / $regs, 4) : null,
            'cpd' => $deps > 0 ? round($row['spend'] / $deps, 4) : null,
            'r2d' => $regs > 0 ? round($deps / $regs * 100, 4) : null,
        ];
    }

    private function emptyMetricRow(): array
    {
        return ['spend' => 0, 'clicks' => 0, 'leads' => 0, 'regs' => 0, 'deps' => 0, 'revenue' => 0, 'cpc' => 0];
    }

    private function emptyStats(): array
    {
        return ['total' => 0, 'stop' => 0, 'start' => 0, 'ok' => 0, 'hold_stop' => 0, 'no_geo' => 0, 'no_rules' => 0, 'ignored_status' => 0, 'manual_stop' => 0, 'changes' => 0, 'v2_changes' => 0];
    }

    private function periods(): array
    {
        $tz = appDateTimeZone($this->tz);
        $now = new DateTimeImmutable('now', $tz);
        return [
            'today' => [
                'from' => $now->format('Y-m-d'),
                'to' => $now->format('Y-m-d'),
                'label' => '1D',
            ],
            'yesterday' => [
                'from' => $now->modify('-1 day')->format('Y-m-d'),
                'to' => $now->modify('-1 day')->format('Y-m-d'),
                'label' => 'Yesterday',
            ],
            'last7' => [
                'from' => $now->modify('-6 days')->format('Y-m-d'),
                'to' => $now->format('Y-m-d'),
                'label' => '7D',
            ],
            'last30' => [
                'from' => $now->modify('-29 days')->format('Y-m-d'),
                'to' => $now->format('Y-m-d'),
                'label' => '30D',
            ],
            'alltime' => [
                'from' => '1970-01-01',
                'to' => $now->format('Y-m-d'),
                'label' => 'All time',
            ],
        ];
    }

    private function extractGeo(string $name): ?string
    {
        if (preg_match('/^MLR_([A-Z]{2,3})_/i', $name, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    private function fetchAllBmIds(): array
    {
        return $this->db->query('SELECT id::text FROM business_managers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function buildNamedIn(string $prefix, array $values): array
    {
        $ph = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $key = ":{$prefix}_{$i}";
            $ph[] = $key;
            $params[$key] = $value;
        }
        return ['(' . implode(',', $ph) . ')', $params];
    }
}
