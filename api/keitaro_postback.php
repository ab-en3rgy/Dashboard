<?php
// api/keitaro_postback.php
// Accepts Keitaro postbacks on conversion and writes directly into insights_daily
//
// Setup in Keitaro:
//   Postback URL: https://your-domain.com/api/keitaro_postback.php
//   Method: GET
//   Params: ad_id={subid}&type=lead&date={date}&secret=YOUR_SECRET&revenue={revenue}
//
// date - conversion date in the account timezone (YYYY-MM-DD); if not provided, today UTC

header('Content-Type: application/json');

require_once __DIR__.'/../lib/DB.php';
$cfg = require __DIR__.'/../config/config.php';

// Get parameters (GET or POST JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $adId    = (int)(  $body['ad_id']   ?? 0);
    $type    = (string)($body['type']   ?? '');
    $date    = (string)($body['date']   ?? '');
    $secret  = (string)($body['secret'] ?? '');
    $revenue = (float)($body['revenue'] ?? 0);
} else {
    $adId    = (int)(  $_GET['ad_id']   ?? 0);
    $type    = (string)($_GET['type']   ?? '');
    $date    = (string)($_GET['date']   ?? '');
    $secret  = (string)($_GET['secret'] ?? '');
    $revenue = (float)($_GET['revenue'] ?? 0);
}

// Verification
$expectedSecret = $cfg['extension_secret'] ?? '';
if ($expectedSecret && $secret !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid secret']);
    exit;
}

// Validation
$validTypes = ['lead', 'reg', 'dep'];
if (!$adId || !in_array($type, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid params', 'ad_id' => $adId, 'type' => $type]);
    exit;
}

// Date: use request parameter or current UTC
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = gmdate('Y-m-d');
}

// Check that the ad exists
$db   = DB::getInstance();
$stmt = $db->prepare("SELECT id FROM ads WHERE id = ?");
$stmt->execute([$adId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => "ad_id {$adId} not found"]);
    exit;
}

// Increment the required counter in insights_daily
$col = match($type) { 'reg' => 'regs', 'dep' => 'deps', default => 'leads' };

$db->prepare("
    INSERT INTO insights_daily (ad_id, date, {$col}, revenue, kt_synced_at)
    VALUES (:ad_id, :date, 1, :rev, NOW())
    ON CONFLICT (ad_id, date) DO UPDATE SET
        {$col}       = insights_daily.{$col} + 1,
        revenue      = insights_daily.revenue + EXCLUDED.revenue,
        kt_synced_at = NOW()
")->execute([
    'ad_id' => $adId,
    'date'  => $date,
    'rev'   => $revenue,
]);

http_response_code(200);
echo json_encode(['ok' => true, 'ad_id' => $adId, 'type' => $type, 'date' => $date]);
