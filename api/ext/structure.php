<?php
// api/ext/structure.php
// @version 1.0.1
// POST { secret, ad_account_id, campaigns: [...], adsets: [...], ads: [...] }
// Upsert structure for one account

require __DIR__.'/_bootstrap.php';

function normalizeStructureCreatedTime(mixed $value): ?string {
    if ($value === null) return null;
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '0' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
        return null;
    }
    return $raw;
}

function fallbackCreatedTime(mixed $value): string {
    return normalizeStructureCreatedTime($value) ?? gmdate('c');
}

function encodeStructureJson(mixed $value): ?string {
    if ($value === null) return null;
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
    if (!is_array($value) && !is_object($value)) return null;
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function flattenStructureReasonCandidates(mixed $value, array &$bucket): void {
    if ($value === null) return;
    if (is_string($value) || is_numeric($value) || is_bool($value)) {
        $text = trim((string)$value);
        if ($text !== '') $bucket[] = $text;
        return;
    }
    if (!is_array($value)) return;

    $preferredKeys = [
        'message', 'messages', 'summary', 'error_summary', 'description',
        'error_description', 'body', 'title', 'reason', 'text',
        'policy_name', 'policy_names', 'recommendation', 'details'
    ];
    foreach ($preferredKeys as $key) {
        if (array_key_exists($key, $value)) {
            flattenStructureReasonCandidates($value[$key], $bucket);
        }
    }
    foreach ($value as $item) {
        if (is_array($item)) flattenStructureReasonCandidates($item, $bucket);
    }
}

function extractDisapprovalReason(array $ad): ?string {
    $candidates = [];
    flattenStructureReasonCandidates($ad['ad_review_feedback'] ?? null, $candidates);
    flattenStructureReasonCandidates($ad['issues_info'] ?? null, $candidates);

    $seen = [];
    $parts = [];
    foreach ($candidates as $candidate) {
        $text = preg_replace('/\s+/u', ' ', trim((string)$candidate));
        if ($text === '' || strlen($text) < 4) continue;
        $normalizedKey = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        if (isset($seen[$normalizedKey])) continue;
        $seen[$normalizedKey] = true;
        $parts[] = $text;
        if (count($parts) >= 3) break;
    }

    if (!$parts) return null;
    $reason = implode(' | ', $parts);
    return function_exists('mb_substr') ? mb_substr($reason, 0, 1000, 'UTF-8') : substr($reason, 0, 1000);
}

function ensureCampaignStub(PDO $db, string $campaignId, string $adAccountId): void {
    if ($campaignId === '') return;
    $stmt = $db->prepare("
        INSERT INTO campaigns (id, ad_account_id, name, status, effective_status, objective, synced_at)
        VALUES (:id, :acc, :name, 'PAUSED', NULL, NULL, NOW())
        ON CONFLICT (id) DO NOTHING
    ");
    $stmt->execute([
        'id' => $campaignId,
        'acc' => $adAccountId,
        'name' => "Campaign $campaignId",
    ]);
}

function ensureAdSetStub(PDO $db, string $adSetId, string $campaignId, string $adAccountId): void {
    if ($adSetId === '' || $campaignId === '') return;
    ensureCampaignStub($db, $campaignId, $adAccountId);
    $stmt = $db->prepare("
        INSERT INTO ad_sets (id, campaign_id, ad_account_id, name, status, effective_status, synced_at)
        VALUES (:id, :camp, :acc, :name, 'PAUSED', NULL, NOW())
        ON CONFLICT (id) DO NOTHING
    ");
    $stmt->execute([
        'id' => $adSetId,
        'camp' => $campaignId,
        'acc' => $adAccountId,
        'name' => "Ad set $adSetId",
    ]);
}

function isMissingAdSetForeignKey(\Throwable $e): bool {
    $msg = $e->getMessage();
    return str_contains($msg, 'ads_ad_set_id_fkey') || str_contains($msg, 'violates foreign key constraint');
}

try {

$adAccountId = trim($body['ad_account_id'] ?? '');
if (!$adAccountId) extError(400, 'ad_account_id required');
if (!str_starts_with($adAccountId, 'act_')) $adAccountId = 'act_' . $adAccountId;

$campaigns = $body['campaigns'] ?? [];
$adsets    = $body['adsets']    ?? [];
$ads       = $body['ads']       ?? [];

$counts = ['campaigns' => 0, 'adsets' => 0, 'ads' => 0];

$db->exec("
    ALTER TABLE IF EXISTS ad_sets
        ADD COLUMN IF NOT EXISTS bid_amount NUMERIC(15,2),
        ADD COLUMN IF NOT EXISTS bid_strategy_mode TEXT
");

$db->exec("
    ALTER TABLE IF EXISTS ads
        ADD COLUMN IF NOT EXISTS review_feedback_json JSONB,
        ADD COLUMN IF NOT EXISTS issues_info_json JSONB,
        ADD COLUMN IF NOT EXISTS disapproval_reason TEXT,
        ADD COLUMN IF NOT EXISTS review_checked_at TIMESTAMPTZ
");

// ── Auto-create account if it does not exist ─────────────────────────
// Use the first available BM as owner
$defaultBmId = $db->query("SELECT id FROM business_managers LIMIT 1")->fetchColumn();
if ($defaultBmId) {
    $db->prepare("
        INSERT INTO ad_accounts (id, bm_id, name, status)
        VALUES (?, ?, ?, 1)
        ON CONFLICT (id) DO NOTHING
    ")->execute([$adAccountId, $defaultBmId, "Account $adAccountId"]);
}

// ── Campaigns ──────────────────────────────────────────────────────────
if ($campaigns) {
    $stmt = $db->prepare("
        INSERT INTO campaigns
            (id, ad_account_id, name, status, effective_status, objective,
             daily_budget, lifetime_budget, created_time, updated_time, synced_at)
        VALUES
            (:id, :acc, :name, :status, :eff, :obj, :db, :lb, :ct, :ut, NOW())
        ON CONFLICT (id) DO UPDATE SET
            name             = EXCLUDED.name,
            status           = CASE
                WHEN campaigns.status = 'DELETED' OR campaigns.effective_status = 'DELETED' THEN 'DELETED'
                WHEN campaigns.status = 'MANUAL_STOP' THEN campaigns.status
                ELSE EXCLUDED.status
            END,
            effective_status = CASE
                WHEN campaigns.status = 'DELETED' OR campaigns.effective_status = 'DELETED' THEN 'DELETED'
                WHEN campaigns.status = 'MANUAL_STOP' OR campaigns.effective_status = 'MANUAL_STOP' THEN 'MANUAL_STOP'
                ELSE EXCLUDED.effective_status
            END,
            objective        = EXCLUDED.objective,
            daily_budget     = EXCLUDED.daily_budget,
            lifetime_budget  = EXCLUDED.lifetime_budget,
            created_time     = COALESCE(campaigns.created_time, EXCLUDED.created_time, NOW()),
            updated_time     = EXCLUDED.updated_time,
            synced_at        = NOW()
    ");

    foreach ($campaigns as $c) {
        if (empty($c['id'])) continue;
        $stmt->execute([
            'id'     => (string)$c['id'],
            'acc'    => $adAccountId,
            'name'   => $c['name']             ?? '',
            'status' => $c['status']            ?? 'PAUSED',
            'eff'    => $c['effective_status']  ?? null,
            'obj'    => $c['objective']         ?? null,
            'db'     => isset($c['daily_budget'])    ? (float)$c['daily_budget']    / 100 : null,
            'lb'     => isset($c['lifetime_budget'])  ? (float)$c['lifetime_budget']  / 100 : null,
            'ct'     => fallbackCreatedTime($c['created_time'] ?? null),
            'ut'     => $c['updated_time']      ?? null,
        ]);
        $counts['campaigns']++;
    }
}

// ── Ad sets ───────────────────────────────────────────────────────────
if ($adsets) {
    $stmt = $db->prepare("
        INSERT INTO ad_sets
            (id, campaign_id, ad_account_id, name, status, effective_status,
             daily_budget, lifetime_budget, bid_amount, bid_strategy_mode, created_time, updated_time, synced_at)
        VALUES
            (:id, :camp, :acc, :name, :status, :eff, :db, :lb, :bid, :bid_strategy, :ct, :ut, NOW())
        ON CONFLICT (id) DO UPDATE SET
            name              = EXCLUDED.name,
            status            = EXCLUDED.status,
            effective_status  = EXCLUDED.effective_status,
            daily_budget      = EXCLUDED.daily_budget,
            lifetime_budget   = EXCLUDED.lifetime_budget,
            bid_amount        = COALESCE(EXCLUDED.bid_amount, ad_sets.bid_amount),
            bid_strategy_mode = COALESCE(EXCLUDED.bid_strategy_mode, ad_sets.bid_strategy_mode),
            updated_time      = EXCLUDED.updated_time,
            synced_at         = NOW()
    ");

    foreach ($adsets as $s) {
        if (empty($s['id']) || empty($s['campaign_id'])) continue;
        $rawBid = $s['adset_bid_amount'] ?? $s['bid_amount'] ?? $s['bid'] ?? null;
        $rawBidCents = $s['adset_bid_amount_cents'] ?? $s['bid_amount_cents'] ?? null;
        $bidAmount = null;
        if ($rawBidCents !== null && $rawBidCents !== '') {
            $bidAmount = (float)$rawBidCents / 100;
        } elseif ($rawBid !== null && $rawBid !== '') {
            $bidAmount = (float)$rawBid;
            if ($bidAmount > 1000) $bidAmount = $bidAmount / 100;
        }

        $stmt->execute([
            'id'           => (string)$s['id'],
            'camp'         => (string)$s['campaign_id'],
            'acc'          => $adAccountId,
            'name'         => $s['name']             ?? '',
            'status'       => $s['status']            ?? 'PAUSED',
            'eff'          => $s['effective_status']  ?? null,
            'db'           => isset($s['daily_budget'])    ? (float)$s['daily_budget']    / 100 : null,
            'lb'           => isset($s['lifetime_budget']) ? (float)$s['lifetime_budget'] / 100 : null,
            'bid'          => $bidAmount,
            'bid_strategy' => $s['adset_bid_strategy_mode'] ?? $s['bid_strategy_mode'] ?? null,
            'ct'           => $s['created_time']      ?? null,
            'ut'           => $s['updated_time']      ?? null,
        ]);
        $counts['adsets']++;
    }
}

// ── Ads ────────────────────────────────────────────────────────
if ($ads) {
    $stmt = $db->prepare("
        INSERT INTO ads
            (id, ad_set_id, campaign_id, ad_account_id, name,
             status, effective_status, created_time, updated_time,
             review_feedback_json, issues_info_json, disapproval_reason, review_checked_at, synced_at)
        VALUES
            (:id, :adset, :camp, :acc, :name, :status, :eff, :ct, :ut, CAST(:review_feedback_json AS jsonb),
             CAST(:issues_info_json AS jsonb), :disapproval_reason, NOW(), NOW())
        ON CONFLICT (id) DO UPDATE SET
            name             = EXCLUDED.name,
            status           = EXCLUDED.status,
            effective_status = EXCLUDED.effective_status,
            updated_time     = EXCLUDED.updated_time,
            review_feedback_json = EXCLUDED.review_feedback_json,
            issues_info_json = EXCLUDED.issues_info_json,
            disapproval_reason = EXCLUDED.disapproval_reason,
            review_checked_at = EXCLUDED.review_checked_at,
            synced_at        = NOW()
    ");

    foreach ($ads as $a) {
        if (empty($a['id']) || empty($a['adset_id'])) continue;
        $adName = (string)($a['name'] ?? '');
        if (!preg_match('/\.(mp4|png|jpg)$/i', $adName)) {
            $adName .= '.mp4';
        }
        $adId    = (string)$a['id'];
        $adSetId = (string)$a['adset_id'];
        $campId  = (string)($a['campaign_id'] ?? 0);
        $reviewFeedbackJson = encodeStructureJson($a['ad_review_feedback'] ?? null);
        $issuesInfoJson = encodeStructureJson($a['issues_info'] ?? null);
        $disapprovalReason = extractDisapprovalReason($a);
        try {
            $stmt->execute([
                'id'     => $adId,
                'adset'  => $adSetId,
                'camp'   => $campId,
                'acc'    => $adAccountId,
                'name'   => $adName,
                'status' => $a['status']            ?? 'PAUSED',
                'eff'    => $a['effective_status']  ?? null,
                'ct'     => $a['created_time']      ?? null,
                'ut'     => $a['updated_time']      ?? null,
                'review_feedback_json' => $reviewFeedbackJson,
                'issues_info_json' => $issuesInfoJson,
                'disapproval_reason' => $disapprovalReason,
            ]);
        } catch (\Throwable $e) {
            if (!isMissingAdSetForeignKey($e)) {
                throw $e;
            }
            ensureAdSetStub($db, $adSetId, $campId, $adAccountId);
            $stmt->execute([
                'id'     => $adId,
                'adset'  => $adSetId,
                'camp'   => $campId,
                'acc'    => $adAccountId,
                'name'   => $adName,
                'status' => $a['status']            ?? 'PAUSED',
                'eff'    => $a['effective_status']  ?? null,
                'ct'     => $a['created_time']      ?? null,
                'ut'     => $a['updated_time']      ?? null,
                'review_feedback_json' => $reviewFeedbackJson,
                'issues_info_json' => $issuesInfoJson,
                'disapproval_reason' => $disapprovalReason,
            ]);
        }
        $counts['ads']++;
    }
}

extOk([
    'ad_account_id' => $adAccountId,
    'upserted'      => $counts,
]);

} catch (\Throwable $e) {
    error_log('[structure.php] ' . $e->getMessage() . ' | acc=' . ($adAccountId??'?'));
    extError(500, $e->getMessage());
}
