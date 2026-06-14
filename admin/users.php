<?php
// public/admin/users.php — user management (admin only)
require __DIR__.'/../lib/DB.php';
require __DIR__.'/../lib/Auth.php';
require __DIR__.'/../lib/BmOptions.php';

$db   = DB::getInstance();
$auth = new Auth($db);
$me   = $auth->requireAdmin();

$db->exec("
    ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
    ALTER TABLE users ALTER COLUMN role SET DEFAULT 'user';
    UPDATE users SET role = 'user' WHERE role = 'buyer';
    ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'user'));
");

$errors  = [];
$success = '';

// List of all fb_accounts for checkboxes
$allFbAccounts = bmSelectorOptions($db);

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create user
    if ($action === 'create') {
        $username    = trim($_POST['username'] ?? '');
        $password    = $_POST['password'] ?? '';
        $role        = $_POST['role'] ?? 'user';
        $displayName = trim($_POST['display_name'] ?? '');
        $fbIds       = array_map('intval', $_POST['fb_accounts'] ?? []);

        if (!$username)             $errors[] = 'Enter username';
        if (strlen($password) < 6)  $errors[] = 'Password must be at least 6 characters';
        if (!in_array($role, ['admin','user'], true)) $errors[] = 'Invalid role';

        if (!$errors) {
            try {
                $newId = $auth->createUser($username, $password, $role, $displayName ?: $username, $me['id']);
                if ($role === 'user') $auth->setUserFbAccounts($newId, $fbIds);
                $success = 'User "' . $username . '" created';
            } catch (PDOException $e) {
                $errors[] = str_contains($e->getMessage(), 'unique') ? 'Username is already taken' : $e->getMessage();
            }
        }
    }

    // Update regular user access
    if ($action === 'update_accounts') {
        $uid   = (int)$_POST['user_id'];
        $fbIds = array_map('intval', $_POST['fb_accounts'] ?? []);
        $auth->setUserFbAccounts($uid, $fbIds);
        $success = 'Access updated';
    }

    // Change password
    if ($action === 'change_password') {
        $uid  = (int)$_POST['user_id'];
        $pass = $_POST['new_password'] ?? '';
        if (strlen($pass) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        } else {
            $auth->updatePassword($uid, $pass);
            $success = 'Password changed';
        }
    }

    // Activate / deactivate
    if ($action === 'toggle_active') {
        $uid    = (int)$_POST['user_id'];
        $active = (bool)(int)$_POST['active'];
        if ($uid !== $me['id']) {   // cannot block yourself
            $auth->setActive($uid, $active);
            $success = $active ? 'User activated' : 'User blocked';
        }
    }
}

$users = $auth->listUsers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>User management — FB Ads</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--blue:#0866ff;--blue2:#0059e6;--border:#dddfe2;--border2:#ccd0d5;--text:#1c1e21;--text2:#65676b;--text3:#8a8d91;--bg:#f0f2f5;--bg2:#fff;--green:#1d7d1d;--red:#c0392b}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);font-size:13px}

/* Topbar */
.topbar{height:52px;background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2)}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;background:var(--bg2);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-btn.primary:hover{background:var(--blue2)}
.tb-btn.alert-red{background:#d93025;border-color:#d93025;color:#fff}
.tb-btn.alert-red:hover{background:#b71c1c;border-color:#b71c1c}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}

/* Layout */
.container{max-width:1100px;margin:24px auto;padding:0 16px;display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start}

/* Cards */
.card{background:#fff;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.card-header{padding:14px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;color:var(--text)}
.card-body{padding:16px}

/* Form */
.field{margin-bottom:13px}
label{display:block;font-size:11.5px;font-weight:600;color:var(--text2);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
input[type=text],input[type=password],select{width:100%;padding:7px 9px;border:1px solid var(--border2);border-radius:4px;font-size:13px;font-family:inherit;outline:none;color:var(--text)}
input:focus,select:focus{border-color:var(--blue);box-shadow:0 0 0 2px rgba(8,102,255,.12)}
.role-select{display:flex;gap:6px}
.role-opt{flex:1;padding:7px;border:1px solid var(--border2);border-radius:4px;text-align:center;cursor:pointer;font-size:12px;font-weight:500;color:var(--text2);transition:all .1s}
.role-opt:hover{border-color:var(--blue)}
.role-opt.selected{background:#e7f3ff;border-color:var(--blue);color:var(--blue)}
.fb-checks{display:flex;flex-direction:column;gap:5px;max-height:180px;overflow-y:auto;border:1px solid var(--border2);border-radius:4px;padding:8px}
.fb-check{display:flex;align-items:center;gap:7px;font-size:12px;cursor:pointer;padding:3px 4px;border-radius:3px}
.fb-check:hover{background:var(--bg)}
.fb-check input{width:14px;height:14px;accent-color:var(--blue);cursor:pointer;flex-shrink:0}
.btn{padding:7px 14px;border-radius:4px;border:none;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue2)}
.btn-sm{padding:3px 9px;font-size:11.5px;font-weight:500}
.btn-danger{background:var(--red);color:#fff}
.btn-success{background:var(--green);color:#fff}
.btn-ghost{background:transparent;border:1px solid var(--border2);color:var(--text2)}
.btn-ghost:hover{background:var(--bg)}

/* Alert */
.alert{padding:9px 12px;border-radius:4px;font-size:12.5px;margin-bottom:16px}
.alert-error{background:#fce8e8;border:1px solid #e8b4b0;color:var(--red)}
.alert-success{background:#e6f4ea;border:1px solid #a8d5b5;color:var(--green)}

/* Users table */
.users-table{width:100%;border-collapse:collapse}
.users-table th{padding:8px 10px;text-align:left;font-size:11px;font-weight:600;color:var(--text3);border-bottom:1px solid var(--border2);white-space:nowrap;text-transform:uppercase;letter-spacing:.3px}
.users-table td{padding:10px;border-bottom:1px solid var(--border);vertical-align:top}
.users-table tr:last-child td{border-bottom:none}
.users-table tr:hover td{background:#f7f8fa}
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.badge-admin{background:#fff0e0;color:#b85c00}
.badge-user{background:#e7f3ff;color:var(--blue)}
.badge-off{background:#f0f2f5;color:var(--text3)}
.user-name{font-weight:600;font-size:13px}
.user-sub{font-size:11px;color:var(--text3);margin-top:2px}
.fb-tags{display:flex;flex-wrap:wrap;gap:3px;margin-top:4px}
.fb-tag{background:#f0f2f5;border-radius:3px;padding:1px 6px;font-size:11px;color:var(--text2)}
.actions-row{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}

/* Inline form */
.inline-form{display:inline}

/* Modal overlay */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:50;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:8px;width:360px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:20px}
.modal h3{font-size:14px;margin-bottom:14px}
.modal-close{float:right;background:none;border:none;font-size:18px;cursor:pointer;color:var(--text3);margin-top:-2px}

@media (max-width: 980px){
.container{grid-template-columns:1fr;max-width:760px}
.card-body{padding:14px}
.users-table th,.users-table td{padding:8px}
}

@media (max-width: 640px){
body{overflow:auto}
.container{margin:12px auto;padding:0 12px}
.card-header{padding:12px 14px}
.role-select{flex-direction:column}
.actions-row{flex-direction:column}
.actions-row .btn,.actions-row form{width:100%}
.actions-row form .btn{width:100%}
}
</style>
</head>
<body>

<?php include __DIR__.'/../_header.php'; ?>

<div class="container">

  <!-- Create form -->
  <div class="card">
    <div class="card-header">New user</div>
    <div class="card-body">

      <?php if ($errors): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
      <?php endif ?>
      <?php if ($success && !str_contains($success, 'updated') && !str_contains($success, 'password') && !str_contains($success, 'Password')): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif ?>

      <form method="post">
        <input type="hidden" name="action" value="create">

        <div class="field">
          <label>Username</label>
          <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="off">
        </div>

        <div class="field">
          <label>Name</label>
          <input type="text" name="display_name" value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
        </div>

        <div class="field">
          <label>Password</label>
          <input type="password" name="password" autocomplete="new-password">
        </div>

        <div class="field">
          <label>Role</label>
          <div class="role-select">
            <div class="role-opt <?= ($_POST['role'] ?? 'user') === 'user' ? 'selected' : '' ?>"
                 onclick="setRole('user')">Regular</div>
            <div class="role-opt <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>"
                 onclick="setRole('admin')">Admin</div>
          </div>
          <input type="hidden" name="role" id="role_input" value="<?= htmlspecialchars($_POST['role'] ?? 'user') ?>">
        </div>

        <div class="field" id="fb_accounts_field">
          <label>BM access for regular user</label>
          <div class="fb-checks">
            <?php foreach ($allFbAccounts as $fba): ?>
              <label class="fb-check">
                <input type="checkbox" name="fb_accounts[]" value="<?= $fba['id'] ?>"
                  <?= in_array($fba['id'], (array)($_POST['fb_accounts'] ?? [])) ? 'checked' : '' ?>>
                <?= htmlspecialchars($fba['name']) ?>
              </label>
            <?php endforeach ?>
            <?php if (!$allFbAccounts): ?>
              <span style="color:var(--text3);font-size:12px">No FB accounts in the system</span>
            <?php endif ?>
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%">Create user</button>
      </form>
    </div>
  </div>

  <!-- User list -->
  <div class="card">
    <div class="card-header">
      Users (<?= count($users) ?>)
      <?php if ($success): ?>
        <span style="font-weight:400;color:var(--green);font-size:12px;margin-left:8px">✓ <?= htmlspecialchars($success) ?></span>
      <?php endif ?>
    </div>
    <div style="overflow-x:auto">
      <table class="users-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>FB accounts</th>
            <th>Last login</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
          $fbIdsRaw = json_decode($u['bm_ids'] ?? $u['fb_account_ids'] ?? '[]', true) ?: [];
          $fbIds = array_map('strval', $fbIdsRaw);
          $userFbAccounts = array_filter($allFbAccounts, fn($a) => in_array((string)$a['id'], $fbIds, true));
        ?>
          <tr>
            <td>
              <div class="user-name"><?= htmlspecialchars($u['display_name'] ?: $u['username']) ?></div>
              <div class="user-sub">@<?= htmlspecialchars($u['username']) ?>
                <?php if (!$u['is_active']): ?>
                  <span style="color:var(--red)"> · blocked</span>
                <?php endif ?>
              </div>
            </td>
            <td>
              <span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Regular' ?></span>
            </td>
            <td>
              <?php if ($u['role'] === 'admin'): ?>
                <span style="font-size:11.5px;color:var(--text3)">All</span>
              <?php else: ?>
                <div class="fb-tags">
                  <?php foreach ($userFbAccounts as $fba): ?>
                    <span class="fb-tag"><?= htmlspecialchars($fba['name']) ?></span>
                  <?php endforeach ?>
                  <?php if (!$userFbAccounts): ?>
                    <span style="color:var(--red);font-size:11px">No access</span>
                  <?php endif ?>
                </div>
              <?php endif ?>
            </td>
            <td>
              <span style="font-size:11.5px;color:var(--text3)">
                <?= $u['last_login_at'] ? date('d.m.Y H:i', strtotime($u['last_login_at'])) : 'Never' ?>
              </span>
            </td>
            <td>
              <div class="actions-row">
                <?php if ($u['role'] !== 'admin'): ?>
                  <!-- Edit FB access -->
                  <button class="btn btn-ghost btn-sm"
                          onclick="openAccountsModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>', <?= json_encode($fbIds) ?>)">
                    Access
                  </button>
                <?php endif ?>

                <?php if ((int)$u['id'] !== (int)$me['id'] && !empty($u['is_active'])): ?>
                  <form method="post" action="/impersonate.php" class="inline-form">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Sign in as</button>
                  </form>
                <?php endif ?>

                <!-- Change password -->
                <button class="btn btn-ghost btn-sm"
                        onclick="openPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">
                  Password
                </button>

                <!-- Block/unblock; cannot apply to yourself -->
                <?php if ($u['id'] !== $me['id']): ?>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                      <?= $u['is_active'] ? 'Block' : 'Unblock' ?>
                    </button>
                  </form>
                <?php endif ?>
              </div>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: edit FB accounts -->
<div class="modal-bg" id="modal-accounts">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-accounts')">×</button>
    <h3>FB access: <span id="modal-accounts-name"></span></h3>
    <form method="post">
      <input type="hidden" name="action" value="update_accounts">
      <input type="hidden" name="user_id" id="modal-accounts-uid">
      <div class="fb-checks" style="max-height:240px">
        <?php foreach ($allFbAccounts as $fba): ?>
          <label class="fb-check">
            <input type="checkbox" class="modal-fba-cb" name="fb_accounts[]" value="<?= $fba['id'] ?>">
            <?= htmlspecialchars($fba['name']) ?>
          </label>
        <?php endforeach ?>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:12px">Save</button>
    </form>
  </div>
</div>

<!-- Modal: change password -->
<div class="modal-bg" id="modal-password">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('modal-password')">×</button>
    <h3>Password: <span id="modal-pass-name"></span></h3>
    <form method="post">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="user_id" id="modal-pass-uid">
      <div class="field">
        <label>New password</label>
        <input type="password" name="new_password" autocomplete="new-password" minlength="6">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Save</button>
    </form>
  </div>
</div>

<script>
function setRole(r){
  document.getElementById('role_input').value=r;
  document.querySelectorAll('.role-opt').forEach(el=>el.classList.remove('selected'));
  event.target.closest('.role-opt').classList.add('selected');
  document.getElementById('fb_accounts_field').style.display=r==='user'?'':'none';
}
// Hide FB selection if admin is selected immediately
document.addEventListener('DOMContentLoaded',()=>{
  const role=document.getElementById('role_input').value;
  document.getElementById('fb_accounts_field').style.display=role==='user'?'':'none';
});

function openAccountsModal(uid, name, fbIds){
  document.getElementById('modal-accounts-uid').value=uid;
  document.getElementById('modal-accounts-name').textContent=name;
  fbIds = fbIds.map(String);
  document.querySelectorAll('.modal-fba-cb').forEach(cb=>{
    cb.checked=fbIds.includes(cb.value);
  });
  document.getElementById('modal-accounts').classList.add('open');
}
function openPasswordModal(uid, name){
  document.getElementById('modal-pass-uid').value=uid;
  document.getElementById('modal-pass-name').textContent=name;
  document.getElementById('modal-password').classList.add('open');
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(el=>el.addEventListener('click',e=>{
  if(e.target===el)el.classList.remove('open');
}));
</script>
</body>
</html>
