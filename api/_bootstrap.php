<?php
// api/_bootstrap.php — include at the start of every API endpoint
// Returns: $db, $auth, $me
// On auth error, immediately returns 401 JSON

require_once __DIR__.'/../lib/DB.php';
require_once __DIR__.'/../lib/Auth.php';
require_once __DIR__.'/../lib/Timezone.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Disable API response caching
header('Cache-Control: no-store');

function apiError(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function apiOk(mixed $data, array $meta = []): never {
    echo json_encode(array_merge(['ok' => true], $meta, ['data' => $data]),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_BIGINT_AS_STRING);
    exit;
}

// GET only (or POST for mutations)
$method = $_SERVER['REQUEST_METHOD'];

$db   = DB::getInstance();
$auth = new Auth($db);

$token = $_COOKIE['fb_ads_token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
if (!$token) apiError(401, 'Unauthorized');

$me = $auth->check($token);
if (!$me)   apiError(401, 'Session expired');

// Helper: IN filter by fb_account_id with role awareness
function allowedFbIds(Auth $auth, array $me): array {
    return $auth->allowedBmIds($me);
}

function inClause(array $ids): array {
    if (!$ids) return ['1=0', []];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return ["({$ph})", $ids];
}
