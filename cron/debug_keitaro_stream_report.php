#!/usr/bin/env php
<?php
// Read-only Keitaro stream report diagnostic.
// Usage:
//   php cron/debug_keitaro_stream_report.php 4468
//   php cron/debug_keitaro_stream_report.php 4468 yesterday
//   php cron/debug_keitaro_stream_report.php 4468 2026-05-29
//   php cron/debug_keitaro_stream_report.php 4468 today wide

declare(strict_types=1);

$cfg = require __DIR__ . '/../config/config.php';
$kt = $cfg['keitaro'] ?? [];
$url = rtrim((string)($kt['url'] ?? ''), '/');
$key = (string)($kt['key'] ?? '');
$tz = $cfg['display_tz'] ?? 'Europe/Chisinau';

if ($url === '' || $key === '') {
    fwrite(STDERR, "Keitaro url/key are empty\n");
    exit(1);
}

$streamId = trim((string)($argv[1] ?? ''));
if ($streamId === '' || !preg_match('/^\d+$/', $streamId)) {
    fwrite(STDERR, "Usage: php cron/debug_keitaro_stream_report.php <stream_id> [today|yesterday|YYYY-MM-DD]\n");
    exit(1);
}

$rangeArg = trim((string)($argv[2] ?? 'today'));
$mode = trim((string)($argv[3] ?? 'stream'));
$range = ['interval' => 'today', 'timezone' => $tz];
if ($rangeArg === 'yesterday') {
    $range = ['interval' => 'yesterday', 'timezone' => $tz];
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeArg)) {
    $range = [
        'interval' => 'custom',
        'from' => $rangeArg . ' 00:00',
        'to' => $rangeArg . ' 23:59',
        'timezone' => $tz,
    ];
} elseif ($rangeArg !== '' && $rangeArg !== 'today') {
    fwrite(STDERR, "Unsupported range '{$rangeArg}'. Use today, yesterday, or YYYY-MM-DD.\n");
    exit(1);
}

if ($mode === 'wide') {
    $payload = [
        'range' => $range,
        'measures' => ['campaign_unique_clicks', 'regs', 'deposits', 'conversions', 'revenue'],
        'dimensions' => [
            'datetime',
            'stream_id',
            'offer_id',
            'offer',
            'affiliate_network',
            'campaign_id',
            'campaign',
            'country',
            'sub_id_1',
            'sub_id_10',
            'sub_id_11',
            'sub_id_12',
            'sub_id_13',
            'sub_id_15',
        ],
        'filters' => ['AND' => [
            ['name' => 'stream_id', 'operator' => 'EQUALS', 'expression' => (int)$streamId],
            ['OR' => [
                ['name' => 'campaign_unique_clicks', 'operator' => 'GREATER_THAN', 'expression' => '0'],
                ['name' => 'regs', 'operator' => 'GREATER_THAN', 'expression' => '0'],
                ['name' => 'conversions', 'operator' => 'GREATER_THAN', 'expression' => '0'],
                ['name' => 'revenue', 'operator' => 'GREATER_THAN', 'expression' => '0'],
            ]],
        ]],
        'sort' => [['name' => 'datetime', 'order' => 'asc']],
        'summary' => true,
        'extended' => true,
        'limit' => 100000,
        'offset' => 0,
    ];
} else {
    $payload = [
        'measures' => ['campaign_unique_clicks', 'regs', 'deposits', 'revenue'],
        'filters' => ['AND' => [
            ['name' => 'stream_id', 'operator' => 'EQUALS', 'expression' => (int)$streamId],
        ]],
        'sort' => [['name' => 'campaign_unique_clicks', 'order' => 'desc']],
        'range' => $range,
        'limit' => 100,
        'dimensions' => ['offer_id'],
        'offset' => 0,
        'summary' => true,
        'extended' => true,
    ];
}

$started = microtime(true);
$ch = curl_init($url . '/admin_api/v1/report/build');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Api-Key: ' . $key,
    ],
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);
$ms = (int)round((microtime(true) - $started) * 1000);

if ($curlErr !== '') {
    fwrite(STDERR, "cURL error: {$curlErr}\n");
    exit(1);
}
if ($httpCode !== 200) {
    fwrite(STDERR, "HTTP {$httpCode}: " . substr((string)$response, 0, 1000) . "\n");
    exit(1);
}

$json = json_decode((string)$response, true);
if (!is_array($json)) {
    fwrite(STDERR, "Invalid JSON response\n");
    exit(1);
}

$rows = is_array($json['rows'] ?? null) ? $json['rows'] : [];
$summary = is_array($json['summary'] ?? null) ? $json['summary'] : [];
$totals = ['campaign_unique_clicks' => 0, 'regs' => 0, 'deposits' => 0, 'revenue' => 0.0];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $totals['campaign_unique_clicks'] += (int)($row['campaign_unique_clicks'] ?? 0);
    $totals['regs'] += (int)($row['regs'] ?? 0);
    $totals['deposits'] += (int)($row['deposits'] ?? 0);
    $totals['revenue'] += (float)($row['revenue'] ?? 0);
}

echo "Keitaro stream report diagnostic\n";
echo "URL: {$url}\n";
echo "Stream ID: {$streamId}\n";
echo "Mode: {$mode}\n";
echo "Range: " . json_encode($range, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "HTTP: {$httpCode} ({$ms}ms), rows: " . count($rows) . "\n\n";

echo "Payload:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "Summary from Keitaro:\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

if ($mode === 'wide') {
    echo "First raw rows:\n";
    foreach (array_slice($rows, 0, 5) as $i => $row) {
        echo '[' . $i . '] ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo "\n";
}

printf("%-10s %-18s %10s %8s %8s %12s\n", 'stream_id', 'offer_id', 'clicks', 'regs', 'deps', 'revenue');
printf("%'-72s\n", '');
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    printf(
        "%-10s %-18s %10d %8d %8d %12.2f\n",
        (string)($row['stream_id'] ?? ''),
        (string)($row['offer_id'] ?? ''),
        (int)($row['campaign_unique_clicks'] ?? 0),
        (int)($row['regs'] ?? 0),
        (int)($row['deposits'] ?? 0),
        (float)($row['revenue'] ?? 0)
    );
}
printf("%'-72s\n", '');
printf(
    "%-10s %-18s %10d %8d %8d %12.2f\n",
    '',
    'TOTAL rows',
    $totals['campaign_unique_clicks'],
    $totals['regs'],
    $totals['deposits'],
    $totals['revenue']
);
