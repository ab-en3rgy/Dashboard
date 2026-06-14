<?php
// api/ext/insights.php
// POST { secret, rows: [{ad_id, date, impressions, clicks, spend, cpc, ctr, cpm, frequency}] }
//
// date - DATE string in YYYY-MM-DD format (account TZ date)
// Do NOT touch conversions (leads/regs/deps/revenue) — sync_keitaro.php fills them

require __DIR__.'/_bootstrap.php';

$rows = $body['rows'] ?? [];
if (!is_array($rows) || !count($rows)) extError(400, 'rows array required');

if (count($rows) > 5000) extError(400, 'Too many rows (max 5000 per request)');

$existing = fetchExistingInsightSpends($db, $rows);

$sql = "
    INSERT INTO insights_daily
        (ad_id, date, impressions, clicks, spend, delta, cpc, ctr, cpm, frequency, fb_synced_at)
    VALUES
        (:ad_id, :date, :impr, :clicks, :spend, :delta, :cpc, :ctr, :cpm, :freq, NOW())
    ON CONFLICT (ad_id, date) DO UPDATE SET
        impressions  = EXCLUDED.impressions,
        clicks       = EXCLUDED.clicks,
        spend        = EXCLUDED.spend,
        delta        = EXCLUDED.delta,
        cpc          = EXCLUDED.cpc,
        ctr          = EXCLUDED.ctr,
        cpm          = EXCLUDED.cpm,
        frequency    = EXCLUDED.frequency,
        fb_synced_at = NOW()
        -- Do NOT touch leads/regs/deps/revenue — Keitaro sync fills them
";

$stmt     = $db->prepare($sql);
$upserted = 0;
$skipped  = 0;
$errors   = 0;

foreach ($rows as $row) {
    $adId = trim((string)($row['ad_id'] ?? ''));
    $date = trim((string)($row['date']  ?? ''));

    if (!$adId || !$date) { $skipped++; continue; }

    // Validate date format YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }

    try {
        $spend = (float)($row['spend'] ?? 0);
        $delta = round($spend - (float)($existing[$date][$adId] ?? 0), 4);
        $stmt->execute([
            'ad_id'  => $adId,
            'date'   => $date,
            'impr'   => (int)(  $row['impressions'] ?? 0),
            'clicks' => (int)(  $row['clicks']      ?? 0),
            'spend'  => $spend,
            'delta'  => $delta,
            'cpc'    => (float)($row['cpc']         ?? 0),
            'ctr'    => (float)($row['ctr']         ?? 0),
            'cpm'    => (float)($row['cpm']         ?? 0),
            'freq'   => (float)($row['frequency']   ?? 0),
        ]);
        $upserted++;
    } catch (PDOException $e) {
        error_log("ext/insights: ad_id={$adId} date={$date} err=" . $e->getMessage());
        $errors++;
    }
}

extOk([
    'upserted' => $upserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'total'    => count($rows),
]);

function fetchExistingInsightSpends(PDO $db, array $rows): array {
    $byDate = [];
    foreach ($rows as $row) {
        $date = trim((string)($row['date'] ?? ''));
        $adId = trim((string)($row['ad_id'] ?? ''));
        if ($date === '' || $adId === '') continue;
        $byDate[$date][] = $adId;
    }
    $out = [];
    foreach ($byDate as $date => $adIds) {
        $adIds = array_values(array_unique($adIds));
        if (!$adIds) continue;
        $ph = [];
        $params = [':date' => $date];
        foreach ($adIds as $i => $adId) {
            $key = ":ad_{$i}";
            $ph[] = $key;
            $params[$key] = $adId;
        }
        $stmt = $db->prepare("
            SELECT ad_id::text AS ad_id, spend
            FROM insights_daily
            WHERE date = :date
              AND ad_id IN (" . implode(',', $ph) . ")
        ");
        $stmt->execute($params);
        $out[$date] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$date][(string)$row['ad_id']] = (float)$row['spend'];
        }
    }
    return $out;
}
