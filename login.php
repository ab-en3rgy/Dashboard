<?php
// public/login.php
ob_start(); // buffer output to avoid "headers already sent"

require __DIR__.'/lib/DB.php';
require __DIR__.'/lib/Auth.php';

$db   = DB::getInstance();
$auth = new Auth($db);

// Already logged in?
$token = $_COOKIE['fb_ads_token'] ?? '';
if ($token && $auth->check($token)) {
    header('Location: /index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $newToken = $auth->login($username, $password, $ip, $ua);

    if ($newToken) {
        setcookie('fb_ads_token', $newToken, [
            'expires'  => time() + Auth::COOKIE_LIFETIME_SECONDS,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' => true,  // enable on HTTPS
        ]);
        header('Location: /index.php');
        exit;
    }

    $error = 'Invalid login or password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FB Ads Dashboard - Sign In</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh}
.card{background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);padding:32px;width:360px}
.logo{display:flex;align-items:center;gap:8px;margin-bottom:24px;justify-content:center}
.logo svg{width:28px;height:28px;color:#0866ff}
.logo span{font-size:18px;font-weight:700;color:#1c1e21}
h2{font-size:14px;color:#65676b;text-align:center;margin-bottom:24px;font-weight:400}
label{display:block;font-size:12px;font-weight:600;color:#1c1e21;margin-bottom:4px}
input{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:4px;font-size:14px;font-family:inherit;outline:none;transition:border-color .1s}
input:focus{border-color:#0866ff;box-shadow:0 0 0 2px rgba(8,102,255,.15)}
.field{margin-bottom:16px}
.btn{width:100%;padding:10px;background:#0866ff;color:#fff;border:none;border-radius:4px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:4px}
.btn:hover{background:#0059e6}
.error{background:#fce8e8;border:1px solid #e8b4b0;color:#c0392b;padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:16px}

@media (max-width: 640px){
body{min-height:100dvh;padding:12px}
.card{width:min(100%, 420px);padding:24px}
}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
    <span>Ads Dashboard</span>
  </div>
  <h2>Sign in to your account</h2>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif ?>
  <form method="post">
    <div class="field">
      <label>Username</label>
      <input type="text" name="username" autocomplete="username" autofocus
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password">
    </div>
    <button type="submit" class="btn">Sign In</button>
  </form>
</div>
</body>
</html>
