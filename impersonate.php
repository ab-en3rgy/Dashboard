<?php
// public/impersonate.php
require __DIR__.'/lib/DB.php';
require __DIR__.'/lib/Auth.php';

$db = DB::getInstance();
$auth = new Auth($db);

function setAuthCookie(string $name, string $value, int $expires): void {
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearAuthCookie(string $name): void {
    setAuthCookie($name, '', time() - 3600);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($action === 'start') {
    $currentToken = $_COOKIE['fb_ads_token'] ?? '';
    $currentUser = $currentToken ? $auth->check($currentToken) : null;
    if (!$currentUser || $currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $adminToken = $_COOKIE['fb_ads_admin_token'] ?? $currentToken;
    $adminUser = $adminToken ? $auth->check($adminToken) : null;
    if (!$adminUser || $adminUser['role'] !== 'admin') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        http_response_code(400);
        echo 'user_id required';
        exit;
    }

    $targetToken = $auth->createSessionForUser($targetUserId, $ip, $ua);
    if (!$targetToken) {
        http_response_code(404);
        echo 'User not found or inactive';
        exit;
    }

    setAuthCookie('fb_ads_admin_token', $adminToken, time() + Auth::COOKIE_LIFETIME_SECONDS);
    setAuthCookie('fb_ads_token', $targetToken, time() + Auth::COOKIE_LIFETIME_SECONDS);
    header('Location: /index.php');
    exit;
}

if ($action === 'stop') {
    $adminToken = $_COOKIE['fb_ads_admin_token'] ?? '';
    $adminUser = $adminToken ? $auth->check($adminToken) : null;
    if (!$adminUser || $adminUser['role'] !== 'admin') {
        clearAuthCookie('fb_ads_admin_token');
        header('Location: /login.php');
        exit;
    }

    $currentToken = $_COOKIE['fb_ads_token'] ?? '';
    if ($currentToken && $currentToken !== $adminToken) {
        $auth->logout($currentToken);
    }

    setAuthCookie('fb_ads_token', $adminToken, time() + Auth::COOKIE_LIFETIME_SECONDS);
    clearAuthCookie('fb_ads_admin_token');
    header('Location: /admin/users.php');
    exit;
}

http_response_code(400);
echo 'Unknown action';
