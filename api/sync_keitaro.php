<?php
// api/sync_keitaro.php
// POST /api/sync_keitaro.php
// Runs import from Keitaro for today (synchronously, waits for completion)
// Called from the frontend when refreshing the dashboard

require __DIR__.'/_bootstrap.php';
require_once __DIR__ . '/../lib/ApiSyncLogger.php';

$cfg = require __DIR__.'/../config/config.php';
$kt  = $cfg['keitaro'] ?? [];
$url = rtrim($kt['url'] ?? '', '/');
$key = $kt['key']      ?? '';
$insecureSsl = (bool)($kt['insecure_ssl'] ?? false);

ApiSyncLogger::ensureSchema($db);
$runId = 'api_sync_keitaro:' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(4));

if (!$url || !$key) {
    ApiSyncLogger::log($db, [
        'sync_log_id' => null,
        'request_type' => 'keitaro:report-build',
        'endpoint' => $url ? "{$url}/admin_api/v1/report/build" : '',
        'status' => 'skipped',
        'error_msg' => 'Keitaro url/key are empty',
        'raw_error' => ['reason' => 'Keitaro url/key are empty'],
    ]);
    apiOk(['skipped' => true, 'reason' => 'Keitaro not configured']);
}

$TZ  = 'Europe/Chisinau';
$now  = new DateTime('now', new DateTimeZone($TZ));
$from = (clone $now)->modify('midnight'); // today only

$fromStr = $from->format('Y-m-d H:i');
$toStr   = $now->format('Y-m-d H:i');

$payload = [
    'range' => [
        'interval' => 'custom',
        'from'     => $fromStr,
        'to'       => $toStr,
        'timezone' => $TZ,
    ],
    'measures'   => ['campaign_unique_clicks', 'regs', 'deposits', 'revenue'],
    'dimensions' => ['sub_id_1', 'sub_id_10', 'sub_id_11', 'sub_id_12', 'sub_id_13', 'sub_id_14', 'sub_id_15', 'datetime'],
    'filters'    => ['OR' => [
        ['name' => 'campaign_unique_clicks', 'operator' => 'GREATER_THAN', 'expression' => '0'],
        ['name' => 'conversions',            'operator' => 'GREATER_THAN', 'expression' => '0'],
    ]],
    'sort'       => [['name' => 'datetime', 'order' => 'asc']],
    'summary'    => false,
    'limit'      => 100000,
    'offset'     => 0,
];

$ch = curl_init("{$url}/admin_api/v1/report/build");
$requestType = 'keitaro:report-build';
$requestUrl  = "{$url}/admin_api/v1/report/build";
$startedAt = microtime(true);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        "Api-Key: {$key}",
    ],
]);
if ($insecureSsl) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
$durationMs = (int)round((microtime(true) - $startedAt) * 1000);
curl_close($ch);

if ($curlErr) {
    ApiSyncLogger::log($db, [
        'request_type' => $requestType,
        'endpoint' => $requestUrl,
        'status' => 'failed',
        'http_code' => $httpCode ?: null,
        'duration_ms' => $durationMs,
        'error_msg' => $curlErr,
        'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
        'raw_error' => [
            'curl_error' => $curlErr,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
        ],
    ]);
    apiError(502, "Keitaro cURL error: {$curlErr}");
}
if ($httpCode !== 200) {
    ApiSyncLogger::log($db, [
        'request_type' => $requestType,
        'endpoint' => $requestUrl,
        'status' => 'failed',
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'error_msg' => "HTTP {$httpCode}",
        'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
        'raw_error' => [
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
        ],
    ]);
    apiError(502, "Keitaro HTTP {$httpCode}: " . substr($response, 0, 200));
}

try {
    $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    ApiSyncLogger::log($db, [
        'request_type' => $requestType,
        'endpoint' => $requestUrl,
        'status' => 'failed',
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'error_msg' => 'Invalid JSON from Keitaro: ' . $e->getMessage(),
        'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
        'raw_error' => [
            'json_error' => $e->getMessage(),
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
        ],
    ]);
    apiError(502, 'Keitaro returned invalid JSON');
}

$rows = $decoded['rows'] ?? [];
ApiSyncLogger::log($db, [
    'request_type' => $requestType,
    'endpoint' => $requestUrl,
    'status' => 'ok',
    'http_code' => $httpCode,
    'duration_ms' => $durationMs,
    'rows_returned' => is_array($rows) ? count($rows) : 0,
    'response_preview' => ApiSyncLogger::preview((string)$response, 2000),
    'raw_error' => null,
]);

if (!$rows) {
    apiOk(['upserted' => 0, 'skipped' => 0, 'date' => $now->format('Y-m-d')]);
}

// Aggregate by (ad_id, date)
$agg     = [];
$skipped = 0;
$tzObj   = new DateTimeZone($TZ);

foreach ($rows as $row) {
    $adId  = trim((string)($row['sub_id_1'] ?? ''));
    $dtStr = trim((string)($row['datetime']  ?? ''));

    if (!$adId || !is_numeric($adId)) {
        $adId = '00000000000000';
    }

    if (!$dtStr) { $skipped++; continue; }

    $leads   = (int)  ($row['campaign_unique_clicks'] ?? 0);
    $regs    = (int)  ($row['regs']                   ?? 0);
    $deps    = (int)  ($row['deposits']               ?? 0);
    $revenue = (float)($row['revenue']                ?? 0);

    if ($leads === 0 && $regs === 0 && $deps === 0 && $revenue == 0.0) {
        $skipped++;
        continue;
    }

    try {
        $dt      = new DateTime($dtStr, $tzObj);
        $dateKey = $dt->format('Y-m-d');
    } catch (Exception $e) { $skipped++; continue; }

    $k = $adId.'|'.$dateKey;
    if (!isset($agg[$k])) {
        $agg[$k] = [
            'ad_id'     => $adId, 'date' => $dateKey,
            'leads'     => 0, 'regs' => 0, 'deps' => 0, 'revenue' => 0.0,
            'sub_id_10' => trim((string)($row['sub_id_10'] ?? '')),
            'sub_id_11' => trim((string)($row['sub_id_11'] ?? '')),
            'sub_id_12' => trim((string)($row['sub_id_12'] ?? '')),
            'sub_id_13' => trim((string)($row['sub_id_13'] ?? '')),
            'sub_id_14' => trim((string)($row['sub_id_14'] ?? '')),
            'sub_id_15' => trim((string)($row['sub_id_15'] ?? '')),
        ];
    }
    $agg[$k]['leads']   += $leads;
    $agg[$k]['regs']    += $regs;
    $agg[$k]['deps']    += $deps;
    $agg[$k]['revenue'] += $revenue;
}

$stmtCheckAd  = $db->prepare("SELECT COUNT(*) FROM ads WHERE id = ?");
$stmtGetAcc   = $db->prepare("SELECT ad_account_id FROM campaigns WHERE id = ? LIMIT 1");

function ktExtractAccIdFromCampName(string $name): ?string {
    foreach (explode('_', $name) as $part) {
        if (preg_match('/^\d{10,}$/', $part)) {
            return $part;
        }
    }
    return null;
}

$stmtInsCamp  = $db->prepare("
    INSERT INTO campaigns (id, ad_account_id, name, status, effective_status)
    VALUES (?, ?, ?, 'UNKNOWN', 'UNKNOWN') ON CONFLICT (id) DO NOTHING
");
$stmtInsAdset = $db->prepare("
    INSERT INTO ad_sets (id, campaign_id, ad_account_id, name, status, effective_status)
    VALUES (?, ?, ?, ?, 'UNKNOWN', 'UNKNOWN') ON CONFLICT (id) DO NOTHING
");
$stmtInsAd    = $db->prepare("
    INSERT INTO ads (id, ad_set_id, campaign_id, ad_account_id, name, status, effective_status)
    VALUES (?, ?, ?, ?, ?, 'UNKNOWN', 'UNKNOWN') ON CONFLICT (id) DO NOTHING
");

$defaultBmId = $db->query("SELECT id FROM business_managers LIMIT 1")->fetchColumn() ?: null;
$autoCreated = 0;
foreach ($agg as &$item) {
    $adId    = $item['ad_id'];
    $campId  = $item['sub_id_10'] ?? '';
    $adsetId = $item['sub_id_12'] ?? '';
    $adName  = $item['sub_id_15'] ?? '';

    if (
        $adId === '00000000000000' ||
        !$campId ||
        !$adsetId ||
        str_contains($campId, '{') ||
        str_contains($adsetId, '{')
    ) {
        continue;
    }

    $stmtCheckAd->execute([$adId]);
    if ($stmtCheckAd->fetchColumn() > 0) {
        continue;
    }

    $stmtGetAcc->execute([$campId]);
    $accId = $stmtGetAcc->fetchColumn() ?: null;
    if (!$accId) {
        $campName2 = $item['sub_id_11'] ?? '';
        $accId = ktExtractAccIdFromCampName($campName2);
        if ($accId) {
            $accId = 'act_' . $accId;
        }
    }
    if (!$accId) {
        continue;
    }

    $campName    = $item['sub_id_11'] ?: "Campaign {$campId}";
    $adsetName   = $item['sub_id_13'] ?: "Adset {$adsetId}";
    $adNameClean = $adName && !str_contains($adName, '{') ? $adName : "Ad {$adId}";

    if ($defaultBmId) {
        $db->prepare("INSERT INTO ad_accounts (id, bm_id, name, status) VALUES (?, ?, ?, 1) ON CONFLICT (id) DO NOTHING")
            ->execute([$accId, $defaultBmId, "Account {$accId}"]);
    }

    $stmtInsCamp->execute([$campId, $accId, $campName]);
    $stmtInsAdset->execute([$adsetId, $campId, $accId, $adsetName]);
    $stmtInsAd->execute([$adId, $adsetId, $campId, $accId, $adNameClean]);
    $autoCreated++;
}
unset($item);

$stmt = $db->prepare("
    INSERT INTO insights_daily
        (ad_id, date, leads, regs, deps, revenue,
         sub_id_10, sub_id_11, sub_id_12, sub_id_13, sub_id_14, sub_id_15,
         kt_synced_at)
    VALUES
        (:ad_id, :date, :leads, :regs, :deps, :revenue,
         :sub_id_10, :sub_id_11, :sub_id_12, :sub_id_13, :sub_id_14, :sub_id_15,
         NOW())
    ON CONFLICT (ad_id, date) DO UPDATE SET
        leads        = EXCLUDED.leads,
        regs         = EXCLUDED.regs,
        deps         = EXCLUDED.deps,
        revenue      = EXCLUDED.revenue,
        sub_id_10    = EXCLUDED.sub_id_10,
        sub_id_11    = EXCLUDED.sub_id_11,
        sub_id_12    = EXCLUDED.sub_id_12,
        sub_id_13    = EXCLUDED.sub_id_13,
        sub_id_14    = EXCLUDED.sub_id_14,
        sub_id_15    = EXCLUDED.sub_id_15,
        kt_synced_at = NOW()
");

$upserted = 0;
$errors   = 0;
foreach ($agg as $item) {
    try {
        $stmt->execute($item);
        $upserted++;
    } catch (PDOException $e) {
        $errors++;
    }
}

apiOk([
    'upserted' => $upserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'auto_created_structures' => $autoCreated,
    'date'     => $now->format('Y-m-d'),
    'from'     => $fromStr,
    'to'       => $toStr,
]);
