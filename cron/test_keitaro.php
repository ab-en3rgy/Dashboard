#!/usr/bin/env php
<?php
// cron/test_keitaro.php - test mode without writing to the database
// Usage:
//   php test_keitaro.php [days_ago]             - example: php test_keitaro.php 7
//   php test_keitaro.php 2026-04-03            - exact date
//   php test_keitaro.php 2026-04-03 2026-04-05 - date range

declare(strict_types=1);

$cfg = require __DIR__.'/../config/config.php';
$kt  = $cfg['keitaro'] ?? [];
$url = rtrim($kt['url'] ?? '', '/');
$key = $kt['key'] ?? '';

if (!$url || !$key) {
    echo "Keitaro is not configured\n";
    exit(1);
}

$TZ    = 'Europe/Chisinau';
$tzObj = new DateTimeZone($TZ);
$arg1  = $argv[1] ?? '1';
$arg2  = $argv[2] ?? null;

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg1)) {
    $from = new DateTime($arg1.' 00:00', $tzObj);
    $to   = $arg2 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg2)
        ? new DateTime($arg2.' 23:59', $tzObj)
        : new DateTime($arg1.' 23:59', $tzObj);
    $label = $arg2 ? "{$arg1} -> {$arg2}" : $arg1;
} else {
    $days = max(1, (int)$arg1);
    $now  = new DateTime('now', $tzObj);
    $from = (clone $now)->modify("-{$days} days midnight");
    $to   = $now;
    $label = "{$days} d";
}

$line = str_repeat('=', 78);
echo $line."\n";
echo "  Keitaro Test | TZ: {$TZ} | {$label}\n";
echo "  URL: {$url}\n";
echo "  Window: ".$from->format('Y-m-d H:i')." -> ".$to->format('Y-m-d H:i')."\n";
echo $line."\n\n";

$payload = [
    'range'      => ['interval'=>'custom','from'=>$from->format('Y-m-d H:i'),'to'=>$to->format('Y-m-d H:i'),'timezone'=>$TZ],
    'measures'   => ['campaign_unique_clicks','regs','deposits','revenue'],
    'dimensions' => ['sub_id_1','sub_id_10','sub_id_11','sub_id_12','sub_id_13','sub_id_14','sub_id_15','datetime'],
    'filters'    => ['OR' => [
        ['name' => 'campaign_unique_clicks', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'conversions',            'operator' => 'GREATER_THAN', 'expression' => '0'],
    ]],
    'sort'       => [['name'=>'datetime','order'=>'asc']],
    'summary'    => false, 'limit'=>100000, 'offset'=>0,
];

$t0 = microtime(true);
$ch = curl_init("{$url}/admin_api/v1/report/build");
curl_setopt_array($ch, [
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode($payload),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>60,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json',"Api-Key: {$key}"],
]);
$resp = curl_exec($ch);
$ms   = round((microtime(true)-$t0)*1000);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "ERROR cURL: {$err}\n";
    exit(1);
}
if ($code !== 200) {
    echo "ERROR HTTP {$code}: ".substr($resp,0,300)."\n";
    exit(1);
}

$rows = json_decode($resp, true)['rows'] ?? [];
echo "HTTP {$code} ({$ms}ms) - rows from Keitaro: ".count($rows)."\n\n";

if ($rows) {
    echo "Raw (first 3):\n";
    foreach (array_slice($rows, 0, 3) as $i => $r) {
        echo "  [{$i}] ".json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
    }
    echo "\n";
}

$badRows     = [];
$skippedRows = [];
$byAdDate    = [];
$skipped     = 0;

foreach ($rows as $row) {
    $adId  = trim((string)($row['sub_id_1'] ?? ''));
    $dtStr = trim((string)($row['datetime']  ?? ''));

    $l  = (int)($row['campaign_unique_clicks'] ?? 0);
    $r2 = (int)($row['regs'] ?? 0);
    $d  = (int)($row['deposits'] ?? 0);
    $rv = (float)($row['revenue'] ?? 0);
    $hasConversions = ($l > 0 || $r2 > 0 || $d > 0 || $rv > 0.0);

    if ((!$adId || !is_numeric($adId)) && count($badRows) < 100) {
        $badRows[] = $row;
    }

    if (!$adId || !is_numeric($adId)) {
        $adId = '00000000000000';
    }

    if (!$dtStr) {
        $skipped++;
        continue;
    }
    if (!$hasConversions) {
        $skipped++;
        if (count($skippedRows) < 100) {
            $skippedRows[] = $row;
        }
        continue;
    }

    try {
        $dt = new DateTime($dtStr, new DateTimeZone($TZ));
        $dateKey = $dt->format('Y-m-d');
    } catch (Exception $e) {
        $skipped++;
        continue;
    }

    $k = $adId.'|'.$dateKey;
    if (!isset($byAdDate[$k])) {
        $byAdDate[$k] = ['ad_id'=>$adId,'date'=>$dateKey,'leads'=>0,'regs'=>0,'deps'=>0,'revenue'=>0.0];
    }
    $byAdDate[$k]['leads']   += $l;
    $byAdDate[$k]['regs']    += $r2;
    $byAdDate[$k]['deps']    += $d;
    $byAdDate[$k]['revenue'] += $rv;
}

if ($badRows) {
    $badWithConv = array_filter($badRows, fn($r) =>
        ($r['campaign_unique_clicks'] ?? 0) > 0 ||
        ($r['regs'] ?? 0) > 0 ||
        ($r['deposits'] ?? 0) > 0 ||
        ($r['revenue'] ?? 0) > 0
    );
    $badLeads = array_sum(array_column($badRows, 'campaign_unique_clicks'));
    $badRegs  = array_sum(array_column($badRows, 'regs'));
    $badDeps  = array_sum(array_column($badRows, 'deposits'));
    $badRev   = array_sum(array_column($badRows, 'revenue'));

    $totalBad = 0;
    foreach ($rows as $r) {
        $id = trim((string)($r['sub_id_1'] ?? ''));
        if (!$id || !is_numeric($id)) {
            $totalBad++;
        }
    }

    echo $line."\n";
    echo "  WARN ROWS WITHOUT NUMERIC sub_id_1: total {$totalBad}, shown ".count($badRows)."\n";
    echo "  Of these, with conversions: ".count($badWithConv)." rows - {$badLeads} leads / {$badRegs} regs / {$badDeps} deps / ".number_format($badRev, 2)." revenue\n";
    echo $line."\n";
    printf(
        "  %-20s %-10s %-10s %-10s %-10s %-10s %-10s %-16s %5s %5s %5s %8s\n",
        'sub_id_1', 'sub_id_10', 'sub_id_11', 'sub_id_12', 'sub_id_13', 'sub_id_14', 'sub_id_15', 'datetime',
        'L', 'R', 'D', 'rev'
    );
    echo "  ".str_repeat('-', 140)."\n";
    foreach (array_slice($badRows, 0, 100) as $r) {
        printf(
            "  %-22s %-16s %-16s %-16s %-16s %-16s %-16s %5d %5d %5d %8.2f\n",
            substr((string)($r['sub_id_1'] ?? '-'), 0, 20),
            substr((string)($r['sub_id_10'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_11'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_12'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_13'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_14'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_15'] ?? '-'), 0, 10),
            substr((string)($r['datetime'] ?? '-'), 0, 16),
            (int)($r['campaign_unique_clicks'] ?? 0),
            (int)($r['regs'] ?? 0),
            (int)($r['deposits'] ?? 0),
            (float)($r['revenue'] ?? 0)
        );
    }
    if ($totalBad > 100) {
        echo "  ... more ".($totalBad - 100)." rows\n";
    }
    echo "\n";
} else {
    echo "OK All rows with conversions have numeric sub_id_1\n\n";
}

$byDay = [];
foreach ($byAdDate as $item) {
    $date = $item['date'];
    if (!isset($byDay[$date])) {
        $byDay[$date] = ['leads'=>0,'regs'=>0,'deps'=>0,'revenue'=>0.0];
    }
    $byDay[$date]['leads']   += $item['leads'];
    $byDay[$date]['regs']    += $item['regs'];
    $byDay[$date]['deps']    += $item['deps'];
    $byDay[$date]['revenue'] += $item['revenue'];
}
ksort($byDay);

echo $line."\n";
echo "  DAILY STATISTICS (only rows with numeric sub_id_1)\n";
echo $line."\n";
printf("  %-12s %8s %8s %8s %12s\n", 'Date', 'Leads', 'Regs', 'Deps', 'Revenue');
echo "  ".str_repeat('-', 54)."\n";
$tl=$tr=$td=$trv=0;
foreach ($byDay as $date => $d) {
    printf("  %-12s %8d %8d %8d %12.2f\n", $date, $d['leads'], $d['regs'], $d['deps'], $d['revenue']);
    $tl+=$d['leads']; $tr+=$d['regs']; $td+=$d['deps']; $trv+=$d['revenue'];
}
echo "  ".str_repeat('-', 54)."\n";
printf("  %-12s %8d %8d %8d %12.2f\n", 'TOTAL ('.count($byDay).'d)', $tl, $tr, $td, $trv);

echo "\n";
echo $line."\n";
echo "  DETAIL BY ADS\n";
echo $line."\n";
printf("  %-20s %-12s %6s %6s %6s %10s\n", 'ad_id', 'date', 'Leads', 'Regs', 'Deps', 'Revenue');
echo "  ".str_repeat('-', 66)."\n";
usort($byAdDate, fn($a, $b) => $a['date'] <=> $b['date'] ?: $a['ad_id'] <=> $b['ad_id']);
$tl2=$tr2=$td2=$trv2=0;
foreach ($byAdDate as $item) {
    printf(
        "  %-20s %-12s %6d %6d %6d %10.2f\n",
        $item['ad_id'],
        $item['date'],
        $item['leads'],
        $item['regs'],
        $item['deps'],
        $item['revenue']
    );
    $tl2+=$item['leads']; $tr2+=$item['regs']; $td2+=$item['deps']; $trv2+=$item['revenue'];
}
echo "  ".str_repeat('-', 66)."\n";
printf("  %-20s %-12s %6d %6d %6d %10.2f\n", 'TOTAL ('.count($byAdDate).')', '', $tl2, $tr2, $td2, $trv2);
echo "\n  Skipped (zeros or non-numeric sub_id_1): {$skipped}\n";
echo $line."\n";

if ($skippedRows) {
    echo "\n";
    echo $line."\n";
    echo "  SKIPPED ROWS (zeros) - first ".count($skippedRows)." of {$skipped}\n";
    echo $line."\n";
    printf(
        "  %-20s %-10s %-10s %-10s %-10s %-10s %-10s %-16s %5s %5s %5s %8s\n",
        'sub_id_1', 'sub_id_10', 'sub_id_11', 'sub_id_12', 'sub_id_13', 'sub_id_14', 'sub_id_15', 'datetime',
        'L', 'R', 'D', 'rev'
    );
    echo "  ".str_repeat('-', 136)."\n";
    foreach ($skippedRows as $r) {
        printf(
            "  %-20s %-16s %-16s %-16s %-16s %-16s %-16s %5d %5d %5d %8.2f\n",
            substr((string)($r['sub_id_1'] ?? '-'), 0, 20),
            substr((string)($r['sub_id_10'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_11'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_12'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_13'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_14'] ?? '-'), 0, 10),
            substr((string)($r['sub_id_15'] ?? '-'), 0, 10),
            substr((string)($r['datetime'] ?? '-'), 0, 16),
            (int)($r['campaign_unique_clicks'] ?? 0),
            (int)($r['regs'] ?? 0),
            (int)($r['deposits'] ?? 0),
            (float)($r['revenue'] ?? 0)
        );
    }
    echo $line."\n";
}
