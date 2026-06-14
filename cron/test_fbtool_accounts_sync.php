<?php
// Test FBTool per-account ajax/get-statistics flow without writing to the database.
// Usage:
//   php cron/test_fbtool_accounts_sync.php --fbtool-id=18898025 --date=2026-06-03
//   php cron/test_fbtool_accounts_sync.php --fbtool-id=18898025 --date=2026-06-03 --limit=5

declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');

require __DIR__ . '/../lib/Timezone.php';

$cfg = require __DIR__ . '/../config/config.php';

$displayTz = appDateTimeZone($cfg['display_tz'] ?? 'Europe/Chisinau');
date_default_timezone_set($displayTz->getName());

$fbtoolCfg = $cfg['fbtool'] ?? [];
$fbtoolKey = trim((string)($fbtoolCfg['key'] ?? getenv('FBTOOL_KEY') ?: ''));
$baseUrl   = rtrim((string)($fbtoolCfg['url'] ?? 'https://fbtool.pro/'), '/') . '/';

if ($fbtoolKey === '') {
    fail('FBTool key is empty. Set config fbtool.key or FBTOOL_KEY.');
}

$opts = parseCliOptions($argv);
$fbtoolId = trim((string)($opts['fbtool-id'] ?? '18898025'));
$date = trim((string)($opts['date'] ?? date('Y-m-d')));
$currency = trim((string)($opts['currency'] ?? 'USD'));
$adAccountStatus = trim((string)($opts['adaccount-status'] ?? 'active'));
$limit = max(0, (int)($opts['limit'] ?? 0));

if ($fbtoolId === '') {
    fail('--fbtool-id is required.');
}
validateDate($date);

$accounts = fetchAdAccounts($baseUrl, $fbtoolKey, $fbtoolId, $fbtoolCfg);
if (!$accounts) {
    fail('No ad accounts returned.');
}

if ($limit > 0) {
    $accounts = array_slice($accounts, 0, $limit);
}

foreach ($accounts as $account) {
    $accountId = (string)($account['id'] ?? '');
    $url = buildAjaxStatisticsUrl($baseUrl, $fbtoolId, $date, $currency, $adAccountStatus, $accountId);

    try {
        [$body, $meta] = httpTextGet($url, browserHeaders($baseUrl, $fbtoolCfg, true), 90);

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON: ' . substr($body, 0, 300));
        }

        $summary = summarizeStatisticsRows($json);
        logLine(sprintf(
            'Spend %s: $%.2f | Impressions %d | Clicks %d | Rows %d',
            $accountId,
            $summary['spend'],
            $summary['impressions'],
            $summary['clicks'],
            $summary['rows']
        ));
    } catch (Throwable $e) {
        logLine(sprintf('ERROR %s: %s', $accountId, $e->getMessage()));
    }
}

function parseCliOptions(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $opts[$k] = $v;
        } else {
            $opts[$arg] = true;
        }
    }
    return $opts;
}

function validateDate(string $date): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail("Invalid date: {$date}. Expected YYYY-MM-DD.");
    }
}

function fetchAdAccounts(string $baseUrl, string $key, string $fbtoolId, array $fbtoolCfg): array
{
    $url = $baseUrl . 'api/get-adaccounts?' . http_build_query([
        'key' => $key,
        'account' => $fbtoolId,
    ]);

    $json = httpJsonGet($url, browserHeaders($baseUrl, $fbtoolCfg), 90);
    if (!is_array($json['data'] ?? null)) {
        return [];
    }

    $accounts = [];
    foreach ($json['data'] as $acc) {
        if (!is_array($acc)) {
            continue;
        }
        $rawId = trim((string)($acc['account_id'] ?? ''));
        if ($rawId === '') {
            continue;
        }

        $id = str_starts_with($rawId, 'act_') ? $rawId : 'act_' . $rawId;
        $accounts[] = [
            'id' => $id,
            'name' => (string)($acc['name'] ?? $id),
            'status' => (int)($acc['account_status'] ?? 1),
            'currency' => (string)($acc['currency'] ?? 'USD'),
        ];
    }
    return $accounts;
}

function buildAjaxStatisticsUrl(
    string $baseUrl,
    string $fbtoolId,
    string $date,
    string $currency,
    string $adAccountStatus,
    string $accountId
): string {
    return $baseUrl . 'ajax/get-statistics?' . http_build_query([
        'id' => $fbtoolId,
        'dates' => "{$date} - {$date}",
        'status' => 'all',
        'currency' => $currency,
        'adaccount_status' => $adAccountStatus,
        'ad_account_id' => $accountId,
    ]);
}

function summarizeStatisticsRows(array $json): array
{
    $rows = is_array($json[0]['rows'] ?? null) ? $json[0]['rows'] : [];
    $spend = 0.0;
    $impressions = 0;
    $clicks = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $spend += (float)($row['spend'] ?? 0);
        $impressions += (int)($row['impressions'] ?? 0);
        $clicks += (int)($row['clicks'] ?? 0);
    }

    return [
        'rows' => count($rows),
        'spend' => round($spend, 2),
        'impressions' => $impressions,
        'clicks' => $clicks,
    ];
}

function browserHeaders(string $baseUrl, array $fbtoolCfg, bool $ajax = false): array
{
    $ua = (string)($fbtoolCfg['user_agent'] ?? getenv('FBTOOL_USER_AGENT') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    $cookieHeader = buildCookieHeader((string)($fbtoolCfg['cookie_header'] ?? getenv('FBTOOL_COOKIE') ?: ''));

    $headers = [
        'User-Agent: ' . $ua,
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Referer: ' . $baseUrl,
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
    ];

    if ($ajax) {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    if ($cookieHeader !== '') {
        $headers[] = 'Cookie: ' . $cookieHeader;
    }

    return $headers;
}

function buildCookieHeader(string $raw): string
{
    $cookies = [];
    foreach (explode(';', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !str_contains($part, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $part, 2);
        $cookies[trim($name)] = trim($value);
    }

    $cookies['statistics_mode'] = '3acf6762b832cfcbb1f05e0e581d7d9d9792d23635c5e3e8010d8c826c062bd1a%3A2%3A%7Bi%3A0%3Bs%3A15%3A%22statistics_mode%22%3Bi%3A1%3Bs%3A3%3A%22ads%22%3B%7D';
    $cookies['ad_status'] = '654233790f455972c207a9d4092f5ed64e09114c214276bf47e3ba0dd6b600b4a%3A2%3A%7Bi%3A0%3Bs%3A9%3A%22ad_status%22%3Bi%3A1%3Bs%3A3%3A%22all%22%3B%7D';

    $pairs = [];
    foreach ($cookies as $name => $value) {
        if ($name !== '') {
            $pairs[] = $name . '=' . $value;
        }
    }
    return implode('; ', $pairs);
}

function httpJsonGet(string $url, array $headers, int $timeout): array
{
    [$body, $meta] = httpTextGet($url, $headers, $timeout);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException(sprintf(
            'Invalid JSON response (len=%d, type=%s): %s',
            strlen($body),
            $meta['content_type'] !== '' ? $meta['content_type'] : 'unknown',
            substr($body, 0, 300)
        ));
    }
    return $json;
}

function httpTextGet(string $url, array $headers, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    $effectiveUrl = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("cURL error: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP {$code}: " . substr((string)$body, 0, 300));
    }

    return [
        (string)$body,
        [
            'code' => $code,
            'content_type' => $contentType,
            'effective_url' => $effectiveUrl,
        ],
    ];
}

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL);
    exit(1);
}
