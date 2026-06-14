<?php
// api/ext/_bootstrap.php — shared bootstrap for extension endpoints
// Checks secret, decodes JSON body, returns 401 on error

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo '{"error":"Method not allowed"}'; exit; }

function extOk(array $data = []): never {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function extError(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Decode JSON body
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) extError(400, 'Invalid JSON body');

// Check secret
require_once __DIR__.'/../../lib/DB.php';
require_once __DIR__.'/../../lib/Timezone.php';
$cfg    = require  __DIR__.'/../../config/config.php';
$secret = $cfg['extension_secret'] ?? $cfg['keitaro_secret'] ?? '';

if (!$secret)                          extError(500, 'extension_secret not configured');
if (($body['secret'] ?? '') !== $secret) extError(401, 'Invalid secret');

$db = DB::getInstance();
