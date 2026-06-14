<?php
// public/logout.php
require __DIR__.'/lib/DB.php';
require __DIR__.'/lib/Auth.php';

$token = $_COOKIE['fb_ads_token'] ?? '';
$adminToken = $_COOKIE['fb_ads_admin_token'] ?? '';
if ($token || $adminToken) {
    $auth = new Auth(DB::getInstance());
    if ($token) {
        $auth->logout($token);
    }
    if ($adminToken && $adminToken !== $token) {
        $auth->logout($adminToken);
    }
}

setcookie('fb_ads_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
setcookie('fb_ads_admin_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Location: /login.php');
exit;
