<?php
$root = getenv('FBADS_ROOT') ?: getcwd();
$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$blocked = ['lib', 'config', 'cron', 'sql'];
foreach ($blocked as $prefix) {
    if ($uriPath === '/' . $prefix || str_starts_with($uriPath, '/' . $prefix . '/')) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}

$abs = $root . $uriPath;
if ($uriPath !== '/' && is_file($abs)) {
    return false;
}

if ($method === 'OPTIONS') {
    if (str_starts_with($uriPath, '/api/ext/')) {
        require $root . '/api/ext/cors.php';
        return true;
    }
    http_response_code(204);
    return true;
}

if (preg_match('#^/api/ext/([a-z_]+)$#', $uriPath, $m)) {
    $file = $root . '/api/ext/' . $m[1] . '.php';
    if (is_file($file)) {
        require $file;
        return true;
    }
}

if (preg_match('#^/api/([a-z_]+)$#', $uriPath, $m)) {
    $file = $root . '/api/' . $m[1] . '.php';
    if (is_file($file)) {
        require $file;
        return true;
    }
}

if (str_ends_with($uriPath, '.php')) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

require $root . '/index.php';
return true;
