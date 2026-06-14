<?php
// public/admin/fb_accounts.php
// Manage FB social accounts and their BMs / ad accounts
require __DIR__.'/../lib/DB.php';
require __DIR__.'/../lib/Auth.php';
require __DIR__.'/../lib/BmOptions.php';

$db   = DB::getInstance();
$auth = new Auth($db);
$me   = $auth->requireAdmin();

$errors  = [];
$success = '';

// ── POST ACTIONS ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create FB account
    if ($action === 'create_fb') {
        $name    = trim($_POST['name']    ?? '');
        $token   = trim($_POST['token']   ?? '');
        $expires = trim($_POST['expires'] ?? '');

        if (!$name)  $errors[] = 'Enter account name';
        if (!$token) $errors[] = 'Paste long-lived token';

        if (!$errors) {
            try {
                $db->prepare("
                    INSERT INTO fb_accounts (name, access_token, token_expires_at)
                    VALUES (:n, :t, :e)
                ")->execute([
                    'n' => $name,
                    't' => $token,
                    'e' => $expires ?: null,
                ]);
                $success = 'Account "' . $name . '" added';
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    // Update FB account
    if ($action === 'update_fb') {
        $id      = (int)($_POST['fb_id'] ?? 0);
        $name    = trim($_POST['name']    ?? '');
        $token   = trim($_POST['token']   ?? '');
        $expires = trim($_POST['expires'] ?? '');

        if (!$name)  $errors[] = 'Enter name';
        if (!$token) $errors[] = 'Token cannot be empty';

        if (!$errors) {
            $db->prepare("
                UPDATE fb_accounts
                SET name = :n, access_token = :t, token_expires_at = :e, updated_at = NOW()
                WHERE id = :id
            ")->execute(['n' => $name, 't' => $token, 'e' => $expires ?: null, 'id' => $id]);
            $success = 'Account updated';
        }
    }

    // Activate / deactivate FB account
    if ($action === 'toggle_fb') {
        $id     = (int)$_POST['fb_id'];
        $active = (bool)(int)$_POST['active'];
        $db->prepare("UPDATE fb_accounts SET is_active = :a, updated_at = NOW() WHERE id = :id")
           ->execute(['a' => $active ? 'TRUE' : 'FALSE', 'id' => $id]);
        $success = $active ? 'Account activated' : 'Account deactivated';
    }

    // Add BM manually
    if ($action === 'create_bm') {
        $bmId    = trim($_POST['bm_id']    ?? '');
        $fbId    = (int)($_POST['fb_id']   ?? 0);
        $bmName  = trim($_POST['bm_name']  ?? '');

        if (!$bmId || !is_numeric($bmId)) $errors[] = 'Enter numeric BM ID';
        if (!$fbId)  $errors[] = 'Select FB account';
        if (!$bmName) $errors[] = 'Enter name BM';

        if (!$errors) {
            try {
                $db->prepare("
                    INSERT INTO business_managers (id, fb_account_id, name)
                    VALUES (:id, :fb, :n)
                    ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, fb_account_id = EXCLUDED.fb_account_id
                ")->execute(['id' => $bmId, 'fb' => $fbId, 'n' => $bmName]);
                $success = "BM #{$bmId} added";
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    // Add ad account manually
    if ($action === 'create_aa') {
        $aaId   = trim($_POST['aa_id']       ?? '');
        $fbId   = (int)($_POST['fb_id']      ?? 0);
        $bmId   = trim($_POST['bm_id']       ?? '') ?: null;
        $aaName = trim($_POST['aa_name']      ?? '');
        $tz     = trim($_POST['timezone']     ?? 'UTC');
        $cur    = trim($_POST['currency']     ?? 'USD');

        // Normalize act_
        if ($aaId && !str_starts_with($aaId, 'act_')) $aaId = 'act_'.$aaId;

        if (!$aaId)  $errors[] = 'Enter account ID (act_XXXX or just digits)';
        if (!$fbId)  $errors[] = 'Select FB account';
        if (!$aaName) $errors[] = 'Enter account name';

        if (!$errors) {
            try {
                $db->prepare("
                    INSERT INTO ad_accounts (id, fb_account_id, bm_id, name, timezone_name, currency)
                    VALUES (:id, :fb, :bm, :n, :tz, :cur)
                    ON CONFLICT (id) DO UPDATE SET
                        name = EXCLUDED.name, fb_account_id = EXCLUDED.fb_account_id,
                        bm_id = EXCLUDED.bm_id, timezone_name = EXCLUDED.timezone_name,
                        currency = EXCLUDED.currency
                ")->execute(['id'=>$aaId,'fb'=>$fbId,'bm'=>$bmId,'n'=>$aaName,'tz'=>$tz,'cur'=>$cur]);
                $success = "Account {$aaId} added";
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    // Delete ad account
    if ($action === 'delete_aa') {
        $aaId = $_POST['aa_id'] ?? '';
        $db->prepare("DELETE FROM ad_accounts WHERE id = ?")->execute([$aaId]);
        $success = "Account {$aaId} removed";
    }

    if ($errors || $success) {
        // Redirect + flash via GET to avoid resubmit
        // Keep POST; continue and show status
    }
}

// ── DATA ────────────────────────────────────────────────────
$fbAccounts = $db->query("
    SELECT
        fa.*,
        COUNT(DISTINCT bm.id)  AS bm_count,
        COUNT(DISTINCT aa.id)  AS aa_count,
        MAX(aa.synced_at)      AS last_synced
    FROM fb_accounts fa
    LEFT JOIN business_managers bm ON bm.fb_account_id = fa.id
    LEFT JOIN ad_accounts aa       ON aa.fb_account_id = fa.id
    GROUP BY fa.id
    ORDER BY fa.name
")->fetchAll();

$bms = $db->query("
    SELECT bm.*, fa.name AS fb_account_name,
           COUNT(aa.id) AS aa_count
    FROM business_managers bm
    JOIN fb_accounts fa ON fa.id = bm.fb_account_id
    LEFT JOIN ad_accounts aa ON aa.bm_id = bm.id
    GROUP BY bm.id, fa.name
    ORDER BY fa.name, bm.name
")->fetchAll();

$adAccounts = $db->query("
    SELECT aa.*, fa.name AS fb_account_name, bm.name AS bm_name
    FROM ad_accounts aa
    JOIN fb_accounts fa ON fa.id = aa.fb_account_id
    LEFT JOIN business_managers bm ON bm.id = aa.bm_id
    ORDER BY fa.name, aa.name
")->fetchAll();

// For selects
$fbList = $db->query("SELECT id, name FROM fb_accounts WHERE is_active = TRUE ORDER BY name")->fetchAll();
$bmList = bmSelectorOptions($db, null, true);

// Token: mask it — show the first 10 and last 4 characters
function maskToken(string $t): string {
    if (strlen($t) <= 14) return str_repeat('•', strlen($t));
    return substr($t, 0, 10) . str_repeat('•', max(0, strlen($t)-14)) . substr($t, -4);
}

function tokenExpiry(?string $ts): string {
    if (!$ts) return '∞ (long-lived)';
    $diff = strtotime($ts) - time();
    if ($diff < 0)    return '<span style="color:var(--red)">Expired ' . date('d.m.Y', strtotime($ts)) . '</span>';
    if ($diff < 86400*7) return '<span style="color:var(--orange)">Expires ' . date('d.m.Y', strtotime($ts)) . '</span>';
    return date('d.m.Y', strtotime($ts));
}

$TZ_OPTIONS = [
    'UTC','Europe/Kyiv','Europe/Moscow','Europe/London','Europe/Berlin',
    'America/New_York','America/Los_Angeles','America/Chicago',
    'Asia/Manila','Asia/Dubai','Asia/Bangkok','Asia/Singapore',
    'Australia/Sydney','Pacific/Auckland',
];
$CURRENCIES = ['USD','EUR','GBP','PHP','AED','THB','SGD','AUD','UAH','RUB','KZT'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FB Accounts — FB Ads Dashboard</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#0866ff;--blue2:#0059e6;--border:#dddfe2;--border2:#ccd0d5;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;
  --bg:#f0f2f5;--bg2:#fff;--bg3:#f7f8fa;
  --green:#1d7d1d;--red:#c0392b;--orange:#e67e22
}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);font-size:13px}

/* Topbar */
.topbar{height:40px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:12px;position:sticky;top:0;z-index:20}
.topbar-logo{font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px}
.topbar-logo svg{width:18px;height:18px;color:var(--blue)}
.nav-link{padding:4px 10px;border-radius:4px;font-size:12px;color:var(--text2);text-decoration:none}
.nav-link:hover{background:var(--bg);color:var(--text)}
.nav-link.active{background:#e7f3ff;color:var(--blue);font-weight:600}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text2)}
.btn-logout{padding:4px 10px;border:1px solid var(--border2);border-radius:4px;background:#fff;cursor:pointer;font-family:inherit;font-size:12px}

/* Layout */
.page{max-width:1200px;margin:20px auto;padding:0 16px;display:flex;flex-direction:column;gap:20px}

/* Tabs */
.page-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);background:#fff;border-radius:8px 8px 0 0;overflow:hidden}
.page-tab{padding:10px 18px;font-size:12px;cursor:pointer;color:var(--text2);border-bottom:2px solid transparent;white-space:nowrap;user-select:none;background:#fff}
.page-tab:hover{background:var(--bg);color:var(--text)}
.page-tab.active{color:var(--blue);font-weight:600;border-bottom-color:var(--blue)}
.tab-pane{display:none}
.tab-pane.active{display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start}

/* Card */
.card{background:#fff;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.card-hdr{padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between}
.card-body{padding:16px}

/* Form */
.field{margin-bottom:13px}
label.lbl{display:block;font-size:11px;font-weight:600;color:var(--text3);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
input[type=text],input[type=password],input[type=date],textarea,select{
  width:100%;padding:7px 9px;border:1px solid var(--border2);border-radius:4px;
  font-size:13px;font-family:inherit;outline:none;color:var(--text);background:#fff
}
input:focus,textarea:focus,select:focus{border-color:var(--blue);box-shadow:0 0 0 2px rgba(8,102,255,.1)}
textarea{resize:vertical;min-height:70px;font-size:12px}
.field-hint{font-size:11px;color:var(--text3);margin-top:3px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}

/* Buttons */
.btn{padding:7px 14px;border-radius:4px;border:none;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue2)}
.btn-sm{padding:3px 9px;font-size:11.5px;font-weight:500}
.btn-ghost{background:#fff;border:1px solid var(--border2);color:var(--text2)}
.btn-ghost:hover{background:var(--bg)}
.btn-danger{background:var(--red);color:#fff}
.btn-success{background:var(--green);color:#fff}
.inline-form{display:inline}

/* Alert */
.alert{padding:9px 12px;border-radius:4px;font-size:12.5px;margin-bottom:14px}
.alert-error{background:#fce8e8;border:1px solid #e8b4b0;color:var(--red)}
.alert-success{background:#e6f4ea;border:1px solid #a8d5b5;color:var(--green)}

/* Table */
.tbl{width:100%;border-collapse:collapse}
.tbl th{padding:7px 10px;text-align:left;font-size:10.5px;font-weight:600;color:var(--text3);border-bottom:1px solid var(--border2);white-space:nowrap;text-transform:uppercase;letter-spacing:.3px;background:var(--bg3)}
.tbl td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#f7f8fa}
.mono{font-family:'SF Mono',Consolas,monospace;font-size:11px}
.dim{color:var(--text3)}

/* Badge */
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.badge-on{background:#e6f4ea;color:var(--green)}
.badge-off{background:#f0f2f5;color:var(--text3)}

/* Token display */
.token-row{display:flex;align-items:center;gap:6px}
.token-masked{font-family:monospace;font-size:11px;color:var(--text3);letter-spacing:1px}
.token-copy{background:none;border:none;cursor:pointer;font-size:11px;color:var(--blue);padding:0;font-family:inherit}
.token-copy:hover{text-decoration:underline}

/* FB account card (list view) */
.fb-card{border:1px solid var(--border);border-radius:6px;background:#fff;margin-bottom:10px}
.fb-card-head{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;user-select:none}
.fb-card-head:hover{background:var(--bg3)}
.fb-avatar{width:32px;height:32px;border-radius:6px;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;flex-shrink:0}
.fb-info{flex:1;min-width:0}
.fb-name{font-size:13px;font-weight:600}
.fb-meta{font-size:11px;color:var(--text3);margin-top:1px}
.fb-card-body{border-top:1px solid var(--border);padding:12px 14px;display:none}
.fb-card-body.open{display:block}
.fb-card-actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}

/* Modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:50;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:8px;width:460px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:20px;max-height:90vh;overflow-y:auto}
.modal-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.modal-hdr h3{font-size:14px}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--text3);line-height:1}
.modal-close:hover{color:var(--text)}

/* Expand chevron */
.chevron{transition:transform .2s;font-size:12px;color:var(--text3)}
.chevron.open{transform:rotate(90deg)}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
    Ads Dashboard
  </div>
  <a href="/" class="nav-link">Dashboard</a>
  <a href="/admin/users.php" class="nav-link">Users</a>
  <a href="/admin/fb_accounts.php" class="nav-link active">FB Accounts</a>
  <div class="topbar-right">
    <span><?= htmlspecialchars($me['display_name']) ?> (admin)</span>
    <form method="post" action="/logout.php" class="inline-form">
      <button class="btn-logout" type="submit">Logout</button>
    </form>
  </div>
</div>

<div class="page">

  <?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif ?>

  <!-- TABS -->
  <div>
    <div class="page-tabs">
      <div class="page-tab active" onclick="showTab('fb')">FB Accounts (<?= count($fbAccounts) ?>)</div>
      <div class="page-tab" onclick="showTab('bm')">Business Managers (<?= count($bms) ?>)</div>
      <div class="page-tab" onclick="showTab('aa')">Ad Accounts (<?= count($adAccounts) ?>)</div>
    </div>

    <!-- ═══ TAB: FB ACCOUNTS ═══ -->
    <div class="tab-pane active" id="tab-fb" style="display:grid">

      <!-- Create form -->
      <div class="card">
        <div class="card-hdr">Add FB account</div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="create_fb">
            <div class="field">
              <label class="lbl">Name (for your notes)</label>
              <input type="text" name="name" placeholder="fb_account_main" required>
            </div>
            <div class="field">
              <label class="lbl">Long-lived token (60 days)</label>
              <textarea name="token" placeholder="EAAxxxxxx..." required></textarea>
              <div class="field-hint">
                Get it from: <a href="https://developers.facebook.com/tools/explorer/" target="_blank" style="color:var(--blue)">Graph API Explorer</a>
                → Permissions: ads_read, ads_management, business_management → Generate Token → Exchange for long-lived token
              </div>
            </div>
            <div class="field">
              <label class="lbl">Token expiration date (optional)</label>
              <input type="date" name="expires" min="<?= date('Y-m-d') ?>">
              <div class="field-hint">System will warn 7 days before expiration</div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Add account</button>
          </form>
        </div>
      </div>

      <!-- Account list -->
      <div>
        <?php if (!$fbAccounts): ?>
          <div style="padding:32px;text-align:center;color:var(--text3);background:#fff;border:1px solid var(--border);border-radius:8px">
            No FB accounts. Add the first one ->
          </div>
        <?php endif ?>

        <?php foreach ($fbAccounts as $fa):
          $avatarLetter = strtoupper(mb_substr($fa['name'], 0, 1));
          $colors = ['#0866ff','#e4a11b','#42b72a','#f02849','#8b5cf6','#06b6d4'];
          $color  = $colors[$fa['id'] % count($colors)];
          $tokenShort = maskToken($fa['access_token']);
        ?>
        <div class="fb-card">
          <div class="fb-card-head" onclick="toggleCard(<?= $fa['id'] ?>)">
            <div class="fb-avatar" style="background:<?= $color ?>"><?= $avatarLetter ?></div>
            <div class="fb-info">
              <div class="fb-name"><?= htmlspecialchars($fa['name']) ?></div>
              <div class="fb-meta">
                <?= (int)$fa['bm_count'] ?> BM · <?= (int)$fa['aa_count'] ?> accounts
                <?php if ($fa['last_synced']): ?>
                  · sync <?= date('d.m H:i', strtotime($fa['last_synced'])) ?>
                <?php endif ?>
              </div>
            </div>
            <span class="badge <?= $fa['is_active'] ? 'badge-on' : 'badge-off' ?>"><?= $fa['is_active'] ? 'Active' : 'Disabled' ?></span>
            <span class="chevron" id="chv-<?= $fa['id'] ?>">›</span>
          </div>

          <div class="fb-card-body" id="card-body-<?= $fa['id'] ?>">
            <!-- Token -->
            <div style="margin-bottom:10px">
              <div style="font-size:11px;font-weight:600;color:var(--text3);text-transform:uppercase;margin-bottom:4px">Token</div>
              <div class="token-row">
                <span class="token-masked" id="tok-<?= $fa['id'] ?>"><?= htmlspecialchars($tokenShort) ?></span>
                <button class="token-copy" onclick="copyToken(<?= $fa['id'] ?>, '<?= htmlspecialchars(addslashes($fa['access_token'])) ?>')">Copy</button>
              </div>
              <div style="font-size:11px;color:var(--text3);margin-top:2px">
                Expires: <?= tokenExpiry($fa['token_expires_at']) ?>
              </div>
            </div>

            <!-- Actions -->
            <div class="fb-card-actions">
              <button class="btn btn-ghost btn-sm" onclick="openEditModal(<?= $fa['id'] ?>, '<?= htmlspecialchars(addslashes($fa['name'])) ?>', '<?= htmlspecialchars(addslashes($fa['access_token'])) ?>', '<?= $fa['token_expires_at'] ? date('Y-m-d', strtotime($fa['token_expires_at'])) : '' ?>')">
                ✏ Edit
              </button>
              <form method="post" class="inline-form">
                <input type="hidden" name="action" value="toggle_fb">
                <input type="hidden" name="fb_id"  value="<?= $fa['id'] ?>">
                <input type="hidden" name="active"  value="<?= $fa['is_active'] ? '0' : '1' ?>">
                <button type="submit" class="btn btn-sm <?= $fa['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                  <?= $fa['is_active'] ? '⏸ Deactivate' : '▶ Activate' ?>
                </button>
              </form>
            </div>

            <!-- Accounts for this FB account -->
            <?php
              $accAccounts = array_filter($adAccounts, fn($a) => $a['fb_account_id'] == $fa['id']);
            ?>
            <?php if ($accAccounts): ?>
            <div style="margin-top:12px;border-top:1px solid var(--border);padding-top:10px">
              <div style="font-size:11px;font-weight:600;color:var(--text3);text-transform:uppercase;margin-bottom:6px">Ad Accounts</div>
              <table class="tbl" style="font-size:11.5px">
                <thead><tr>
                  <th>ID</th><th>Name</th><th>BM</th><th>Timezone</th><th>Currency</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($accAccounts as $aa): ?>
                <tr>
                  <td><span class="mono"><?= htmlspecialchars($aa['id']) ?></span></td>
                  <td><?= htmlspecialchars($aa['name']) ?></td>
                  <td><span class="dim"><?= htmlspecialchars($aa['bm_name'] ?? '—') ?></span></td>
                  <td><span class="dim"><?= htmlspecialchars($aa['timezone_name']) ?></span></td>
                  <td><?= htmlspecialchars($aa['currency']) ?></td>
                  <td><span class="badge <?= $aa['status']==1?'badge-on':'badge-off' ?>"><?= $aa['status']==1?'Active':'Off' ?></span></td>
                </tr>
                <?php endforeach ?>
                </tbody>
              </table>
            </div>
            <?php endif ?>
          </div>
        </div>
        <?php endforeach ?>
      </div>
    </div><!-- /tab-fb -->

    <!-- ═══ TAB: BUSINESS MANAGERS ═══ -->
    <div class="tab-pane" id="tab-bm">

      <!-- Create form BM -->
      <div class="card">
        <div class="card-hdr">Add Business Manager</div>
        <div class="card-body">
          <p style="font-size:12px;color:var(--text3);margin-bottom:12px">
            BMs are created automatically during sync. Add one manually only if you need to specify a BM before the first sync.
          </p>
          <form method="post">
            <input type="hidden" name="action" value="create_bm">
            <div class="field">
              <label class="lbl">FB account</label>
              <select name="fb_id" required>
                <option value="">-- select --</option>
                <?php foreach ($fbList as $fa): ?>
                  <option value="<?= $fa['id'] ?>"><?= htmlspecialchars($fa['name']) ?></option>
                <?php endforeach ?>
              </select>
            </div>
            <div class="row2">
              <div class="field">
                <label class="lbl">BM ID (numeric)</label>
                <input type="text" name="bm_id" placeholder="123456789012345">
              </div>
              <div class="field">
                <label class="lbl">Name BM</label>
                <input type="text" name="bm_name" placeholder="Main Agency BM">
              </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Add BM</button>
          </form>
        </div>
      </div>

      <!-- BM list -->
      <div class="card">
        <div class="card-hdr">All Business Managers (<?= count($bms) ?>)</div>
        <?php if (!$bms): ?>
          <div style="padding:24px;text-align:center;color:var(--text3)">
            BMs will appear after the first sync or add one manually
          </div>
        <?php else: ?>
        <table class="tbl">
          <thead><tr>
            <th>BM ID</th><th>Name</th><th>FB account</th><th>Accounts</th><th>Status</th>
          </tr></thead>
          <tbody>
          <?php foreach ($bms as $bm): ?>
          <tr>
            <td><span class="mono"><?= htmlspecialchars($bm['id']) ?></span></td>
            <td style="font-weight:500"><?= htmlspecialchars($bm['name']) ?></td>
            <td><span class="dim"><?= htmlspecialchars($bm['fb_account_name']) ?></span></td>
            <td><?= (int)$bm['aa_count'] ?></td>
            <td><span class="badge <?= $bm['is_active']?'badge-on':'badge-off' ?>"><?= $bm['is_active']?'Active':'Off' ?></span></td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        <?php endif ?>
      </div>
    </div><!-- /tab-bm -->

    <!-- ═══ TAB: AD ACCOUNTS ═══ -->
    <div class="tab-pane" id="tab-aa">

      <!-- Create account form -->
      <div class="card">
        <div class="card-hdr">Add ad account</div>
        <div class="card-body">
          <p style="font-size:12px;color:var(--text3);margin-bottom:12px">
            Accounts are created automatically during sync. Add one manually to run sync without a BM.
          </p>
          <form method="post">
            <input type="hidden" name="action" value="create_aa">
            <div class="field">
              <label class="lbl">FB account</label>
              <select name="fb_id" required id="aaFbSel" onchange="filterBmByFb(this.value)">
                <option value="">-- select --</option>
                <?php foreach ($fbList as $fa): ?>
                  <option value="<?= $fa['id'] ?>"><?= htmlspecialchars($fa['name']) ?></option>
                <?php endforeach ?>
              </select>
            </div>
            <div class="field">
              <label class="lbl">Business Manager (optional)</label>
              <select name="bm_id" id="aaBmSel">
                <option value="">-- no BM --</option>
                <?php foreach ($bmList as $bm): ?>
                  <option value="<?= htmlspecialchars($bm['id']) ?>" data-fb="<?= $bm['fb_account_id'] ?>">
                    <?= htmlspecialchars($bm['name']) ?> (<?= $bm['id'] ?>)
                  </option>
                <?php endforeach ?>
              </select>
            </div>
            <div class="field">
              <label class="lbl">Account ID</label>
              <input type="text" name="aa_id" placeholder="act_111111111111111 or just digits" required>
              <div class="field-hint">act_ will be added automatically</div>
            </div>
            <div class="field">
              <label class="lbl">Name</label>
              <input type="text" name="aa_name" placeholder="Account Main" required>
            </div>
            <div class="row2">
              <div class="field">
                <label class="lbl">Timezone</label>
                <select name="timezone">
                  <?php foreach ($TZ_OPTIONS as $tz): ?>
                    <option value="<?= $tz ?>" <?= $tz==='Europe/Kyiv'?'selected':'' ?>><?= $tz ?></option>
                  <?php endforeach ?>
                </select>
              </div>
              <div class="field">
                <label class="lbl">Currency</label>
                <select name="currency">
                  <?php foreach ($CURRENCIES as $cur): ?>
                    <option value="<?= $cur ?>" <?= $cur==='USD'?'selected':'' ?>><?= $cur ?></option>
                  <?php endforeach ?>
                </select>
              </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Add account</button>
          </form>
        </div>
      </div>

      <!-- Accounts list -->
      <div class="card">
        <div class="card-hdr">
          All accounts (<?= count($adAccounts) ?>)
          <input type="text" id="aaSearch" placeholder="Search..." oninput="filterAaTable(this.value)"
            style="width:180px;padding:4px 8px;border:1px solid var(--border2);border-radius:4px;font-size:12px">
        </div>
        <?php if (!$adAccounts): ?>
          <div style="padding:24px;text-align:center;color:var(--text3)">
            Accounts will appear after the first sync
          </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="tbl" id="aaTable">
          <thead><tr>
            <th>Account ID</th><th>Name</th><th>FB account</th><th>BM</th>
            <th>Timezone</th><th>Currency</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($adAccounts as $aa): ?>
          <tr data-search="<?= strtolower(htmlspecialchars($aa['id'].' '.$aa['name'].' '.$aa['fb_account_name'])) ?>">
            <td><span class="mono"><?= htmlspecialchars($aa['id']) ?></span></td>
            <td style="font-weight:500"><?= htmlspecialchars($aa['name']) ?></td>
            <td><span class="dim"><?= htmlspecialchars($aa['fb_account_name']) ?></span></td>
            <td><span class="dim"><?= htmlspecialchars($aa['bm_name'] ?? '—') ?></span></td>
            <td><span class="mono dim"><?= htmlspecialchars($aa['timezone_name']) ?></span></td>
            <td><?= htmlspecialchars($aa['currency']) ?></td>
            <td>
              <span class="badge <?= $aa['status']==1?'badge-on':'badge-off' ?>">
                <?= [1=>'Active',2=>'Disabled',3=>'Debt',7=>'Review',9=>'Grace'][$aa['status']] ?? $aa['status'] ?>
              </span>
            </td>
            <td>
              <form method="post" class="inline-form" onsubmit="return confirm('Delete account <?= htmlspecialchars(addslashes($aa['id'])) ?>?\nAll statistics will be removed by cascade.')">
                <input type="hidden" name="action" value="delete_aa">
                <input type="hidden" name="aa_id"  value="<?= htmlspecialchars($aa['id']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        </div>
        <?php endif ?>
      </div>
    </div><!-- /tab-aa -->

  </div><!-- /tabs container -->
</div><!-- /page -->

<!-- EDIT FB ACCOUNT MODAL -->
<div class="modal-bg" id="modalEdit">
  <div class="modal">
    <div class="modal-hdr">
      <h3>Edit FB account</h3>
      <button class="modal-close" onclick="closeModal()">×</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="update_fb">
      <input type="hidden" name="fb_id"  id="editFbId">
      <div class="field">
        <label class="lbl">Name</label>
        <input type="text" name="name" id="editName" required>
      </div>
      <div class="field">
        <label class="lbl">Long-lived token</label>
        <textarea name="token" id="editToken" required></textarea>
        <div class="field-hint">⚠ Replacing the token will apply immediately to the next sync</div>
      </div>
      <div class="field">
        <label class="lbl">Expiration date (optional)</label>
        <input type="date" name="expires" id="editExpires">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px">
        <button type="submit" class="btn btn-primary" style="flex:1">Save</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── TABS ──────────────────────────────────────────────────────
function showTab(name) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.page-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-'+name).classList.add('active');
  document.getElementById('tab-'+name).style.display = 'grid';
  event.target.classList.add('active');
  // hide others
  ['fb','bm','aa'].filter(n=>n!==name).forEach(n => {
    document.getElementById('tab-'+n).style.display = 'none';
  });
}

// ── FB ACCOUNT CARDS ──────────────────────────────────────────
function toggleCard(id) {
  const body = document.getElementById('card-body-'+id);
  const chv  = document.getElementById('chv-'+id);
  const open = body.classList.toggle('open');
  chv.classList.toggle('open', open);
}

// ── TOKEN COPY ────────────────────────────────────────────────
function copyToken(id, token) {
  navigator.clipboard.writeText(token).then(() => {
    const btn = event.target;
    btn.textContent = 'Copied ✓';
    setTimeout(() => btn.textContent = 'Copy', 1500);
  });
}

// ── EDIT MODAL ────────────────────────────────────────────────
function openEditModal(id, name, token, expires) {
  document.getElementById('editFbId').value    = id;
  document.getElementById('editName').value    = name;
  document.getElementById('editToken').value   = token;
  document.getElementById('editExpires').value = expires;
  document.getElementById('modalEdit').classList.add('open');
}
function closeModal() {
  document.getElementById('modalEdit').classList.remove('open');
}
document.getElementById('modalEdit').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeModal();
});

// ── BM FILTER BY FB ACCOUNT ───────────────────────────────────
function filterBmByFb(fbId) {
  const sel = document.getElementById('aaBmSel');
  [...sel.options].forEach(opt => {
    if (!opt.value) { opt.style.display = ''; return; }
    opt.style.display = (!fbId || opt.dataset.fb == fbId) ? '' : 'none';
  });
  sel.value = '';
}

// ── AD ACCOUNT SEARCH ─────────────────────────────────────────
function filterAaTable(q) {
  const lq = q.toLowerCase();
  document.querySelectorAll('#aaTable tbody tr').forEach(tr => {
    tr.style.display = !lq || tr.dataset.search.includes(lq) ? '' : 'none';
  });
}

// ── OPEN TAB FROM HASH ────────────────────────────────────────
const hash = location.hash.replace('#','');
if (['bm','aa'].includes(hash)) {
  document.querySelector('.tab-pane.active').classList.remove('active');
  document.querySelector('.page-tab.active').classList.remove('active');
  document.getElementById('tab-'+hash).classList.add('active');
  document.getElementById('tab-'+hash).style.display = 'grid';
  document.getElementById('tab-fb').style.display = 'none';
  document.querySelector(`.page-tab:nth-child(${hash==='bm'?2:3})`).classList.add('active');
}
</script>
</body>
</html>
