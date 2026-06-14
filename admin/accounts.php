<?php
// @version 1.4.321
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/Auth.php';
require __DIR__ . '/../lib/BmOptions.php';

$db = DB::getInstance();
$auth = new Auth($db);
$me = $auth->requireAdmin();
$db->exec("ALTER TABLE ad_accounts ADD COLUMN IF NOT EXISTS disabled_date DATE");

$db->exec("
    CREATE TABLE IF NOT EXISTS fbtool_accounts (
        id BIGSERIAL PRIMARY KEY,
        user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        fbtool_id VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(255),
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS auto_rules_cron_enabled BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS name_locked BOOLEAN NOT NULL DEFAULT FALSE");
$db->exec("ALTER TABLE business_managers ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT");

$errors = [];
$success = '';
$tab = $_GET['tab'] ?? 'bms';
if (!in_array($tab, ['bms', 'cabinets'], true)) {
    $tab = 'bms';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_fbtool_account') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fbtoolId = trim((string)($_POST['fbtool_id'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $isActive = !empty($_POST['is_active']);

        if ($userId <= 0) {
            $errors[] = 'Select dashboard user';
        }
        if ($fbtoolId === '') {
            $errors[] = 'Enter FBTool ID';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO fbtool_accounts (user_id, fbtool_id, name, is_active, updated_at)
                    VALUES (:user_id, :fbtool_id, :name, :is_active, NOW())
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'fbtool_id' => $fbtoolId,
                    'name' => $name !== '' ? $name : null,
                    'is_active' => $isActive,
                ]);
                $success = 'FBTool account created';
                $tab = 'bms';
            } catch (PDOException $e) {
                $errors[] = str_contains(strtolower($e->getMessage()), 'unique')
                    ? 'FBTool ID is already attached'
                    : $e->getMessage();
            }
        }
    }

    if ($action === 'update_fbtool_account') {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $fbtoolId = trim((string)($_POST['fbtool_id'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $isActive = !empty($_POST['is_active']);

        if ($id <= 0) {
            $errors[] = 'FBTool account is not specified';
        }
        if ($userId <= 0) {
            $errors[] = 'Select dashboard user';
        }
        if ($fbtoolId === '') {
            $errors[] = 'Enter FBTool ID';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare("
                    UPDATE fbtool_accounts
                    SET user_id = :user_id,
                        fbtool_id = :fbtool_id,
                        name = :name,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $id,
                    'user_id' => $userId,
                    'fbtool_id' => $fbtoolId,
                    'name' => $name !== '' ? $name : null,
                    'is_active' => $isActive,
                ]);
                $success = 'FBTool account saved';
                $tab = 'bms';
            } catch (PDOException $e) {
                $errors[] = str_contains(strtolower($e->getMessage()), 'unique')
                    ? 'FBTool ID is already attached'
                    : $e->getMessage();
            }
        }
    }

    if ($action === 'update_bm_row') {
        $bmId = trim((string)($_POST['bm_id'] ?? ''));
        $bmName = trim((string)($_POST['bm_name'] ?? ''));
        $nameLocked = !empty($_POST['name_locked']);
        $isActive = !empty($_POST['is_active']);
        $cronEnabled = !empty($_POST['auto_rules_cron_enabled']);
        $fbtoolAccountIdRaw = trim((string)($_POST['fbtool_account_id'] ?? ''));
        $fbtoolAccountId = $fbtoolAccountIdRaw !== '' ? (int)$fbtoolAccountIdRaw : null;

        if ($bmId === '' || !is_numeric($bmId)) {
            $errors[] = 'Select BM';
        }
        if ($bmName === '') {
            $errors[] = 'Enter BM name';
        }
        $bmNameLength = function_exists('mb_strlen') ? mb_strlen($bmName) : strlen($bmName);
        if ($bmNameLength > 255) {
            $errors[] = 'BM name must not exceed 255 characters';
        }

        if (!$errors) {
            $stmt = $db->prepare("
                UPDATE business_managers
                SET name = :name,
                    name_locked = :name_locked,
                    is_active = :is_active,
                    auto_rules_cron_enabled = :cron_enabled,
                    fbtool_account_id = :fbtool_account_id,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING name
            ");
            $stmt->execute([
                'name' => $bmName,
                'name_locked' => $nameLocked ? 'TRUE' : 'FALSE',
                'is_active' => $isActive ? 'TRUE' : 'FALSE',
                'cron_enabled' => $cronEnabled ? 'TRUE' : 'FALSE',
                'fbtool_account_id' => $fbtoolAccountId,
                'id' => $bmId,
            ]);
            $savedName = $stmt->fetchColumn();
            if (!$savedName) {
                $errors[] = 'BM not found';
            } else {
                $success = 'BM saved: ' . $savedName;
                $tab = 'bms';
            }
        }
    }

    if ($action === 'update_ad_account') {
        $aaId = trim((string)($_POST['aa_id'] ?? ''));
        $bmId = trim((string)($_POST['bm_id'] ?? ''));
        $status = (int)($_POST['status'] ?? 0);
        $status = $status === 1 ? 1 : 2;
        $returnTab = $_POST['return_tab'] ?? 'bms';
        if (!in_array($returnTab, ['bms', 'cabinets'], true)) {
            $returnTab = 'bms';
        }

        if ($aaId === '') {
            $errors[] = 'Account is not specified';
        }
        if ($bmId === '' || !is_numeric($bmId)) {
            $errors[] = 'Select BM';
        }
        if (!$errors) {
            $bmExists = $db->prepare("SELECT 1 FROM business_managers WHERE id = :id");
            $bmExists->execute(['id' => $bmId]);
            if (!$bmExists->fetchColumn()) {
                $errors[] = 'BM not found';
            }
        }

        if (!$errors) {
            $stmt = $db->prepare("
                UPDATE ad_accounts
                SET bm_id = :bm_id,
                    status = :status::smallint,
                    disabled_date = CASE
                        WHEN :status::smallint = 1 THEN NULL
                        WHEN ad_accounts.status = 1 AND :status::smallint <> 1 THEN CURRENT_DATE
                        ELSE ad_accounts.disabled_date
                    END
                WHERE id = :id
                RETURNING name
            ");
            $stmt->execute(['bm_id' => $bmId, 'status' => $status, 'id' => $aaId]);
            $aaName = $stmt->fetchColumn();
            if (!$aaName) {
                $errors[] = 'Account not found';
            } else {
                $success = 'Account updated: ' . $aaName;
                $tab = $returnTab;
            }
        }
    }

    if ($action === 'update_ad_accounts_bulk') {
        $returnTab = $_POST['return_tab'] ?? 'cabinets';
        if (!in_array($returnTab, ['bms', 'cabinets'], true)) {
            $returnTab = 'cabinets';
        }
        $rowsInput = $_POST['rows'] ?? null;
        $changes = [];

        if (!is_array($rowsInput) || !$rowsInput) {
            $errors[] = 'No accounts submitted';
        } else {
            foreach ($rowsInput as $row) {
                $aaId = trim((string)($row['aa_id'] ?? ''));
                if ($aaId === '') {
                    continue;
                }
                $bmId = trim((string)($row['bm_id'] ?? ''));
                $status = (int)($row['status'] ?? 0);
                $status = $status === 1 ? 1 : 2;
                $originalBmId = trim((string)($row['original_bm_id'] ?? ''));
                $originalStatus = (int)($row['original_status'] ?? 0);
                $originalStatus = $originalStatus === 1 ? 1 : 2;

                if ($bmId === '' || !is_numeric($bmId)) {
                    $errors[] = 'Select BM for account ' . $aaId;
                    continue;
                }
                if ($bmId === $originalBmId && $status === $originalStatus) {
                    continue;
                }

                $changes[] = [
                    'aa_id' => $aaId,
                    'bm_id' => $bmId,
                    'status' => $status,
                ];
            }
        }

        if (!$errors && !$changes) {
            $success = 'No account changes to save';
            $tab = $returnTab;
        }

        if (!$errors && $changes) {
            $bmExists = $db->prepare("SELECT 1 FROM business_managers WHERE id = :id");
            foreach ($changes as $change) {
                $bmExists->execute(['id' => $change['bm_id']]);
                if (!$bmExists->fetchColumn()) {
                    $errors[] = 'BM not found for account ' . $change['aa_id'];
                }
            }
        }

        if (!$errors && $changes) {
            $stmt = $db->prepare("
                UPDATE ad_accounts
                SET bm_id = :bm_id,
                    status = :status::smallint,
                    disabled_date = CASE
                        WHEN :status::smallint = 1 THEN NULL
                        WHEN ad_accounts.status = 1 AND :status::smallint <> 1 THEN CURRENT_DATE
                        ELSE ad_accounts.disabled_date
                    END
                WHERE id = :id
                RETURNING name
            ");

            try {
                $db->beginTransaction();
                foreach ($changes as $change) {
                    $stmt->execute([
                        'bm_id' => $change['bm_id'],
                        'status' => $change['status'],
                        'id' => $change['aa_id'],
                    ]);
                    $aaName = $stmt->fetchColumn();
                    if (!$aaName) {
                        throw new RuntimeException('Account not found: ' . $change['aa_id']);
                    }
                }
                $db->commit();
                $success = count($changes) === 1 ? '1 account updated' : count($changes) . ' accounts updated';
                $tab = $returnTab;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$range = $_GET['range'] ?? 'today';
$tz = 'Europe/Kyiv';
$tzObj = new DateTimeZone($tz);
$now = new DateTime('now', $tzObj);
switch ($range) {
    case 'yesterday':
        $dtFrom = (clone $now)->modify('yesterday midnight');
        $dtTo = (clone $now)->modify('yesterday 23:59:59');
        break;
    case '7d':
        $dtFrom = (clone $now)->modify('-6 days midnight');
        $dtTo = $now;
        break;
    case '30d':
        $dtFrom = (clone $now)->modify('-29 days midnight');
        $dtTo = $now;
        break;
    default:
        $dtFrom = (clone $now)->modify('midnight');
        $dtTo = $now;
}
$dateFrom = $dtFrom->format('Y-m-d');
$dateTo = $dtTo->format('Y-m-d');

$bmsStmt = $db->prepare("
    SELECT
        bm.id,
        bm.name,
        bm.name_locked,
        bm.is_active,
        bm.auto_rules_cron_enabled,
        bm.synced_at,
        bm.updated_at,
        bm.token_expires_at,
        bm.fbtool_account_id,
        CASE WHEN bm.access_token IS NOT NULL AND bm.access_token != '' THEN TRUE ELSE FALSE END AS has_token,
        COUNT(DISTINCT aa.id) AS account_count,
        COUNT(DISTINCT aa.id) FILTER (WHERE aa.status = 1) AS active_account_count,
        COALESCE(SUM(s.spend), 0) AS spend,
        COALESCE(SUM(s.impressions), 0) AS impressions,
        COALESCE(SUM(s.clicks), 0) AS clicks
    FROM business_managers bm
    LEFT JOIN ad_accounts aa ON aa.bm_id = bm.id
    LEFT JOIN (
        SELECT
            a.ad_account_id,
            SUM(id.spend) AS spend,
            SUM(id.impressions) AS impressions,
            SUM(id.clicks) AS clicks
        FROM insights_daily id
        JOIN ads a ON a.id = id.ad_id
        WHERE id.date >= :date_from AND id.date <= :date_to
        GROUP BY a.ad_account_id
    ) s ON s.ad_account_id = aa.id
    GROUP BY bm.id
    ORDER BY spend DESC NULLS LAST, bm.name
");
$bmsStmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
$bms = $bmsStmt->fetchAll(PDO::FETCH_ASSOC);

$bmOptions = bmSelectorOptions($db);

$adAccounts = $db->query("
    SELECT
        aa.*,
        bm.name AS bm_name
    FROM ad_accounts aa
    JOIN business_managers bm ON bm.id = aa.bm_id
    ORDER BY bm.name, aa.name
")->fetchAll(PDO::FETCH_ASSOC);

$RANGES = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days'];

function fmtMoney(float $v, string $sym = '$'): string {
    return $sym . number_format($v, 2, '.', ' ');
}
function fmtNum(int $v): string {
    return number_format($v, 0, '.', ' ');
}
function ago(?string $ts): string {
    if (!$ts) return '-';
    $d = time() - strtotime($ts);
    if ($d < 60) return $d . 's';
    if ($d < 3600) return round($d / 60) . 'm';
    if ($d < 86400) return round($d / 3600) . 'h';
    return round($d / 86400) . 'd';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accounts Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--blue:#0866ff;--border:#dddfe2;--border2:#ccd0d5;--text:#1c1e21;--text2:#65676b;--text3:#8a8d91;--bg:#f0f2f5;--bg2:#fff;--bg3:#f7f8fa;--green:#1d7d1d;--red:#c0392b;--orange:#e67e22}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);font-size:13px}
.topbar{height:52px;background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2)}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;background:var(--bg2);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-btn.alert-red{background:#d93025;border-color:#d93025;color:#fff}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}
.page{max-width:1480px;margin:20px auto;padding:0 16px;display:flex;flex-direction:column;gap:16px}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tab{padding:7px 12px;border:1px solid var(--border);border-radius:6px;background:#fff;color:var(--text2);text-decoration:none;font-weight:600}
.tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
.range-tabs{display:flex;gap:4px;background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px;width:fit-content}
.range-tab{padding:4px 12px;border-radius:4px;font-size:12px;color:var(--text2);cursor:pointer;text-decoration:none;white-space:nowrap}
.range-tab.active{background:var(--blue);color:#fff;font-weight:600}
.range-tab:hover:not(.active){background:var(--bg)}
.card{background:#fff;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.card-hdr{padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:12px}
.card-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.card-body{padding:16px}
.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;align-items:end}
.tbl{width:100%;border-collapse:collapse}
.tbl th{padding:7px 10px;text-align:left;font-size:11px;font-weight:600;color:var(--text3);border-bottom:1px solid var(--border2);background:var(--bg3);text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.tbl td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12.5px;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#f7f8fa}
.tbl tr.row-dirty td{background:#edf4ff}
.tbl .num{text-align:right;font-variant-numeric:tabular-nums}
.badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600}
.badge-on{background:#e6f4ea;color:var(--green)}
.badge-off{background:#f0f2f5;color:var(--text3)}
.badge-warn{background:#fff3e0;color:var(--orange)}
.alert{padding:9px 12px;border-radius:4px;font-size:12.5px}
.alert-error{background:#fce8e8;border:1px solid #e8b4b0;color:var(--red)}
.alert-success{background:#e6f4ea;border:1px solid #a8d5b5;color:var(--green)}
input[type=text],select{width:100%;padding:7px 9px;border:1px solid var(--border2);border-radius:4px;font-size:13px;font-family:inherit;outline:none;color:var(--text)}
input:focus,select:focus{border-color:var(--blue)}
.btn{padding:6px 10px;border-radius:4px;border:none;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:4px}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:#0059e6}
.mono{font-family:monospace;font-size:11px}
.dim{color:var(--text3)}
.search-input{min-width:260px;max-width:340px}
.switch{position:relative;display:inline-flex;width:38px;height:22px;align-items:center}
.switch input{position:absolute;opacity:0;width:0;height:0}
.switch span{position:absolute;inset:0;background:#d8dde6;border-radius:999px;cursor:pointer;transition:.15s}
.switch span:before{content:"";position:absolute;width:18px;height:18px;left:2px;top:2px;background:#fff;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.15s}
.switch input:checked + span{background:var(--blue)}
.switch input:checked + span:before{transform:translateX(16px)}
.row-form{display:flex;align-items:center;gap:8px}
.lock{display:inline-flex;align-items:center;gap:4px;color:var(--text2);font-size:11px}
.lock input{width:auto}
@media (max-width:1100px){.grid{grid-template-columns:1fr 1fr}.page{padding:0 10px}}
</style>
</head>
<body>
<?php include __DIR__ . '/../_header.php'; ?>

<div class="page">
  <?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <div class="section-head">
    <h2 style="font-size:16px;font-weight:700">Accounts Admin</h2>
    <div class="tabs">
      <a class="tab <?= $tab === 'bms' ? 'active' : '' ?>" href="?tab=bms&range=<?= urlencode($range) ?>">BMs</a>
      <a class="tab <?= $tab === 'cabinets' ? 'active' : '' ?>" href="?tab=cabinets&range=<?= urlencode($range) ?>">Ad Accounts</a>
    </div>
  </div>

  <?php if ($tab === 'bms' || $tab === 'cabinets'): ?>
    <div class="range-tabs">
      <?php foreach ($RANGES as $r => $label): ?>
        <a href="?tab=<?= urlencode($tab) ?>&range=<?= $r ?>" class="range-tab <?= $range === $r ? 'active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'bms'): ?>
    <div class="card">
      <div class="card-hdr">
        BM Summary
        <span class="dim"><?= htmlspecialchars($RANGES[$range]) ?> · <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?></span>
      </div>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead>
            <tr>
              <th>BM</th>
              <th>Status</th>
              <th>Auto-rules cron</th>
              <th>Ad Accounts</th>
              <th class="num">Spend</th>
              <th class="num">Impressions</th>
              <th class="num">Clicks</th>
              <th class="num">CTR</th>
              <th class="num">CPM</th>
              <th>Sync</th>
              <th>Token</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php
            $totSpend = 0.0;
            $totImpr = 0;
            $totClicks = 0;
            foreach ($bms as $bm):
                $totSpend += (float)$bm['spend'];
                $totImpr += (int)$bm['impressions'];
                $totClicks += (int)$bm['clicks'];
                $ctr = (int)$bm['impressions'] > 0 ? (float)$bm['clicks'] / (float)$bm['impressions'] * 100 : 0;
                $cpm = (int)$bm['impressions'] > 0 ? (float)$bm['spend'] / (float)$bm['impressions'] * 1000 : 0;
                $bmFormId = 'bm-form-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$bm['id']);
          ?>
            <tr>
                <form id="<?= htmlspecialchars($bmFormId) ?>" method="post">
                  <input type="hidden" name="action" value="update_bm_row">
                  <input type="hidden" name="bm_id" value="<?= htmlspecialchars((string)$bm['id']) ?>">
                  <input type="hidden" name="fbtool_account_id" value="<?= htmlspecialchars((string)($bm['fbtool_account_id'] ?? '')) ?>">
                </form>
                <td>
                  <input type="text" name="bm_name" value="<?= htmlspecialchars($bm['name']) ?>" maxlength="255" form="<?= htmlspecialchars($bmFormId) ?>">
                  <div class="mono dim"><?= htmlspecialchars((string)$bm['id']) ?></div>
                  <label class="lock">
                    <input type="checkbox" name="name_locked" value="1" <?= !empty($bm['name_locked']) ? 'checked' : '' ?> form="<?= htmlspecialchars($bmFormId) ?>">
                    lock
                  </label>
                </td>
                <td>
                  <label class="switch">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($bm['is_active']) ? 'checked' : '' ?> form="<?= htmlspecialchars($bmFormId) ?>">
                    <span></span>
                  </label>
                </td>
                <td>
                  <label class="switch">
                    <input type="checkbox" name="auto_rules_cron_enabled" value="1" <?= !empty($bm['auto_rules_cron_enabled']) ? 'checked' : '' ?> form="<?= htmlspecialchars($bmFormId) ?>">
                    <span></span>
                  </label>
                </td>
                <td>
                  <span style="font-weight:600"><?= (int)$bm['active_account_count'] ?></span>
                  <span class="dim">/<?= (int)$bm['account_count'] ?></span>
                </td>
                <td class="num"><?= fmtMoney((float)$bm['spend']) ?></td>
                <td class="num"><?= fmtNum((int)$bm['impressions']) ?></td>
                <td class="num"><?= fmtNum((int)$bm['clicks']) ?></td>
                <td class="num"><?= number_format($ctr, 2) ?>%</td>
                <td class="num"><?= fmtMoney($cpm) ?></td>
                <td><span class="dim"><?= ago($bm['synced_at']) ?> ago</span></td>
                <td>
                  <?php if ($bm['has_token']): ?>
                    <?php if ($bm['token_expires_at'] && strtotime((string)$bm['token_expires_at']) < time() + 86400 * 7): ?>
                      <span class="badge badge-warn">Expiring</span>
                    <?php else: ?>
                      <span class="badge badge-on">Yes</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge badge-off">No</span>
                  <?php endif; ?>
                </td>
                <td><button type="submit" class="btn btn-primary" form="<?= htmlspecialchars($bmFormId) ?>">Save</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4">Total: <?= count($bms) ?> BMs</td>
              <td class="num"><?= fmtMoney($totSpend) ?></td>
              <td class="num"><?= fmtNum($totImpr) ?></td>
              <td class="num"><?= fmtNum($totClicks) ?></td>
              <td class="num"><?= $totImpr > 0 ? number_format($totClicks / $totImpr * 100, 2) . '%' : '-' ?></td>
              <td class="num"><?= $totImpr > 0 ? fmtMoney($totSpend / $totImpr * 1000) : '-' ?></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'cabinets'): ?>
    <div class="card">
      <div class="card-hdr">
        <span>Ad Accounts (<span id="cabinetsVisibleCount"><?= count($adAccounts) ?></span>)</span>
        <div class="card-tools">
          <input type="text" id="cabinetsSearch" class="search-input" placeholder="Search account name/ID or BM">
        </div>
      </div>
      <div style="overflow-x:auto">
        <form method="post" id="adAccountsBulkForm">
          <input type="hidden" name="action" value="update_ad_accounts_bulk">
          <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
          <table class="tbl">
          <thead>
            <tr>
              <th>Account</th>
              <th>BM</th>
              <th>Status</th>
              <th>Disabled date</th>
              <th>Timezone</th>
              <th>Currency</th>
              <th class="num">Spent</th>
              <th class="num">Balance</th>
              <th>Sync</th>
              <th>Manage</th>
            </tr>
          </thead>
            <tbody>
            <?php foreach ($adAccounts as $aaIndex => $aa): ?>
              <?php $rowSearch = trim(implode(' ', [(string)$aa['name'], (string)$aa['id'], (string)$aa['bm_name'], (string)$aa['bm_id']])); ?>
            <tr data-cabinets-row data-search="<?= htmlspecialchars($rowSearch) ?>">
              <td>
                <input type="hidden" name="rows[<?= (int)$aaIndex ?>][aa_id]" value="<?= htmlspecialchars($aa['id']) ?>">
                <div style="font-weight:500"><?= htmlspecialchars($aa['name']) ?></div>
                <div class="mono dim"><?= htmlspecialchars($aa['id']) ?></div>
              </td>
              <td><?= htmlspecialchars($aa['bm_name']) ?></td>
              <td>
                <span class="badge <?= (int)$aa['status'] === 1 ? 'badge-on' : 'badge-off' ?>">
                  <?= htmlspecialchars(([1 => 'Active', 2 => 'Off', 3 => 'Debt', 7 => 'Review', 9 => 'Grace'][(int)$aa['status']] ?? (string)$aa['status'])) ?>
                </span>
              </td>
              <td><span class="mono dim"><?= !empty($aa['disabled_date']) ? htmlspecialchars((string)$aa['disabled_date']) : '-' ?></span></td>
              <td><span class="mono dim"><?= htmlspecialchars($aa['timezone_name']) ?></span></td>
              <td><?= htmlspecialchars($aa['currency']) ?></td>
              <td class="num"><?= fmtMoney((float)$aa['amount_spent']) ?></td>
              <td class="num"><?= fmtMoney((float)$aa['balance']) ?></td>
              <td><span class="dim"><?= ago($aa['synced_at']) ?> ago</span></td>
              <td>
                <div class="row-form">
                  <input type="hidden" name="rows[<?= (int)$aaIndex ?>][original_bm_id]" value="<?= htmlspecialchars((string)$aa['bm_id']) ?>">
                  <input type="hidden" name="rows[<?= (int)$aaIndex ?>][original_status]" value="<?= (int)$aa['status'] === 1 ? '1' : '2' ?>">
                  <select name="rows[<?= (int)$aaIndex ?>][bm_id]" style="min-width:220px" data-track-dirty data-original-value="<?= htmlspecialchars((string)$aa['bm_id']) ?>">
                    <?php foreach ($bmOptions as $opt): ?>
                      <option value="<?= htmlspecialchars($opt['id']) ?>" <?= (string)$opt['id'] === (string)$aa['bm_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($opt['name']) ?> (<?= htmlspecialchars($opt['id']) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <select name="rows[<?= (int)$aaIndex ?>][status]" data-track-dirty data-original-value="<?= (int)$aa['status'] === 1 ? '1' : '2' ?>">
                    <option value="1" <?= (int)$aa['status'] === 1 ? 'selected' : '' ?>>Active</option>
                    <option value="2" <?= (int)$aa['status'] !== 1 ? 'selected' : '' ?>>Off</option>
                  </select>
                  <button type="submit" class="btn btn-primary">Save</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
              <tr id="cabinetsEmptySearch" style="display:none">
                <td colspan="10" style="text-align:center;padding:24px;color:var(--text3)">No matching ad accounts</td>
              </tr>
            </tbody>
          </table>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
<script>
function syncCabinetDirtyState(row) {
  const inputs = row.querySelectorAll('[data-track-dirty]');
  const dirty = Array.from(inputs).some((input) => String(input.value) !== String(input.dataset.originalValue || ''));
  row.classList.toggle('row-dirty', dirty);
}

function filterCabinetRows() {
  const search = document.getElementById('cabinetsSearch');
  const rows = Array.from(document.querySelectorAll('[data-cabinets-row]'));
  const emptyRow = document.getElementById('cabinetsEmptySearch');
  const visibleCount = document.getElementById('cabinetsVisibleCount');
  if (!rows.length || !search) return;

  const query = String(search.value || '').trim().toLowerCase();
  let visible = 0;
  rows.forEach((row) => {
    const haystack = String(row.dataset.search || '').toLowerCase();
    const show = !query || haystack.includes(query);
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  if (visibleCount) visibleCount.textContent = String(visible);
  if (emptyRow) emptyRow.style.display = visible ? 'none' : '';
}

function initCabinetTools() {
  const search = document.getElementById('cabinetsSearch');
  const rows = Array.from(document.querySelectorAll('[data-cabinets-row]'));
  rows.forEach((row) => {
    row.querySelectorAll('[data-track-dirty]').forEach((input) => {
      input.addEventListener('change', () => syncCabinetDirtyState(row));
    });
    syncCabinetDirtyState(row);
  });
  if (search) {
    search.addEventListener('input', filterCabinetRows);
    filterCabinetRows();
  }
}

initCabinetTools();
</script>
</body>
</html>
