#!/usr/bin/env php
<?php
// cron/restore_orphan_structure.php
// Restores structure (campaigns/ad_sets/ads) from sub_id fields
// for orphan rows in insights_daily.
//
// Usage: php restore_orphan_structure.php [--dry-run]

declare(strict_types=1);

require __DIR__.'/../lib/DB.php';
require_once __DIR__ . '/../lib/GlobalLogger.php';
$db  = DB::getInstance();
GlobalLogger::ensureSchema($db);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$runId = 'restore_orphan_structure:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));

$ts = fn() => '['.date('Y-m-d H:i:s').'] ';
echo "\n".$ts().($dryRun ? "[DRY RUN] " : "")."=== restore_orphan_structure started ===\n";
GlobalLogger::log($db, [
    'source' => 'cron/restore_orphan_structure',
    'actor' => 'cron',
    'event_type' => 'cron_started',
    'entity_type' => 'sync',
    'status' => 'running',
    'action' => 'restore_orphan_structure',
    'reason' => 'Restore orphan structure started',
    'payload' => ['dry_run' => $dryRun],
    'correlation_id' => $runId,
]);

// Fetch all orphan rows with populated sub_id fields.
$rows = $db->query("
    SELECT DISTINCT
        id.ad_id,
        id.sub_id_10  AS campaign_id,
        id.sub_id_11  AS campaign_name,
        id.sub_id_12  AS adset_id,
        id.sub_id_13  AS adset_name,
        id.sub_id_15  AS ad_name,
        c.ad_account_id AS known_acc_id
    FROM insights_daily id
    LEFT JOIN ads a ON a.id = id.ad_id
    LEFT JOIN campaigns c ON c.id::text = id.sub_id_10
    WHERE a.id IS NULL
      AND id.ad_id != '0'
      AND id.ad_id != '00000000000000'
      AND id.sub_id_10 IS NOT NULL AND id.sub_id_10 != '' AND id.sub_id_10 != '{sub_id_2}'
      AND id.sub_id_12 IS NOT NULL AND id.sub_id_12 != '' AND id.sub_id_12 != '{sub_id_5}'
      AND id.sub_id_15 IS NOT NULL AND id.sub_id_15 != '' AND id.sub_id_15 != '{sub_id_7}'
")->fetchAll(PDO::FETCH_ASSOC);

echo $ts()."Orphan rows to restore: ".count($rows)."\n";
if (!$rows) {
    echo $ts()."Nothing to restore.\n";
    GlobalLogger::log($db, [
        'source' => 'cron/restore_orphan_structure',
        'actor' => 'cron',
        'event_type' => 'cron_finished',
        'entity_type' => 'sync',
        'status' => 'done',
        'action' => 'restore_orphan_structure',
        'reason' => 'No orphan structure rows to restore',
        'payload' => ['dry_run' => $dryRun, 'orphan_rows' => 0],
        'correlation_id' => $runId,
    ]);
    exit(0);
}

$camps = 0;
$adsets = 0;
$ads = 0;
$skipped = 0;

$stmtGetAcc = $db->prepare("SELECT ad_account_id FROM campaigns WHERE id = ? LIMIT 1");

function extractAccIdFromCampName(string $name): ?string {
    foreach (explode('_', $name) as $part) {
        if (preg_match('/^\d{10,}$/', $part)) {
            return $part;
        }
    }
    return null;
}

$defaultBmId = $db->query("SELECT id FROM business_managers LIMIT 1")->fetchColumn();
if (!$defaultBmId) {
    echo $ts()."ERROR No BM found in the database, cannot create accounts\n";
    GlobalLogger::log($db, [
        'source' => 'cron/restore_orphan_structure',
        'actor' => 'cron',
        'event_type' => 'cron_failed',
        'entity_type' => 'sync',
        'status' => 'failed',
        'action' => 'restore_orphan_structure',
        'reason' => 'No BM found in database',
        'payload' => ['dry_run' => $dryRun, 'orphan_rows' => count($rows)],
        'correlation_id' => $runId,
    ]);
    exit(1);
}

$stmtCamp = $db->prepare("
    INSERT INTO campaigns (id, ad_account_id, name, status, effective_status)
    VALUES (?, ?, ?, 'UNKNOWN', 'UNKNOWN')
    ON CONFLICT (id) DO NOTHING
");
$stmtAdset = $db->prepare("
    INSERT INTO ad_sets (id, campaign_id, ad_account_id, name, status, effective_status)
    VALUES (?, ?, ?, ?, 'UNKNOWN', 'UNKNOWN')
    ON CONFLICT (id) DO NOTHING
");
$stmtAd = $db->prepare("
    INSERT INTO ads (id, ad_set_id, campaign_id, ad_account_id, name, status, effective_status)
    VALUES (?, ?, ?, ?, ?, 'UNKNOWN', 'UNKNOWN')
    ON CONFLICT (id) DO NOTHING
");

foreach ($rows as $row) {
    $adId      = $row['ad_id'];
    $campId    = $row['campaign_id'];
    $campName  = $row['campaign_name'] ?: "Campaign {$campId}";
    $adsetId   = $row['adset_id'];
    $adsetName = $row['adset_name'] ?: "Adset {$adsetId}";
    $adName    = $row['ad_name'] ?: "Ad {$adId}";
    $accId     = $row['known_acc_id'];

    if (!$accId) {
        $stmtGetAcc->execute([$campId]);
        $accId = $stmtGetAcc->fetchColumn() ?: null;
    }
    if (!$accId && $campName) {
        $accId = extractAccIdFromCampName($campName);
    }
    if ($accId && !str_starts_with($accId, 'act_')) {
        $accId = 'act_'.$accId;
    }

    if (!$accId) {
        echo $ts()."WARN No account_id for campaign {$campId}, skipping ad {$adId}\n";
        $skipped++;
        continue;
    }

    $db->prepare("INSERT INTO ad_accounts (id, bm_id, name, status) VALUES (?, ?, ?, 1) ON CONFLICT (id) DO NOTHING")
        ->execute([$accId, $defaultBmId, "Account {$accId}"]);

    printf(
        "  %s  camp=%-20s adset=%-20s ad=%-20s acc=%s\n",
        $dryRun ? '[DRY]' : '    ',
        $campId,
        $adsetId,
        $adId,
        $accId
    );

    if (!$dryRun) {
        $stmtCamp->execute([$campId, $accId, $campName]);
        if ($stmtCamp->rowCount()) {
            $camps++;
        }

        $stmtAdset->execute([$adsetId, $campId, $accId, $adsetName]);
        if ($stmtAdset->rowCount()) {
            $adsets++;
        }

        $stmtAd->execute([$adId, $adsetId, $campId, $accId, $adName]);
        if ($stmtAd->rowCount()) {
            $ads++;
        }
    } else {
        $camps++;
        $adsets++;
        $ads++;
    }
}

echo $ts().($dryRun ? "[DRY RUN] " : "")."Created: {$camps} campaigns, {$adsets} adsets, {$ads} ads\n";
echo $ts()."Skipped (no account_id): {$skipped}\n";
echo $ts()."=== Done ===\n";
GlobalLogger::log($db, [
    'source' => 'cron/restore_orphan_structure',
    'actor' => 'cron',
    'event_type' => 'cron_finished',
    'entity_type' => 'sync',
    'status' => $skipped > 0 ? 'warning' : 'done',
    'action' => 'restore_orphan_structure',
    'reason' => $dryRun ? 'Restore orphan structure dry-run finished' : 'Restore orphan structure finished',
    'payload' => [
        'dry_run' => $dryRun,
        'orphan_rows' => count($rows),
        'created_campaigns' => $camps,
        'created_adsets' => $adsets,
        'created_ads' => $ads,
        'skipped_no_account_id' => $skipped,
    ],
    'correlation_id' => $runId,
]);
