<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DB.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/ApiSyncLogger.php';

$db = DB::getInstance();
$auth = new Auth($db);
$token = $_COOKIE['fb_ads_token'] ?? '';
$me = $token ? $auth->check($token) : null;
if (!$me) {
    header('Location: /login.php');
    exit;
}
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

ApiSyncLogger::ensureSchema($db);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API Sync Logs</title>
<style>
:root{
  --bg:#f0f2f5;--surface:#fff;--border:#d8dde6;--soft:#f7f8fa;
  --text:#1c1e21;--text2:#65676b;--blue:#1877f2;
  --green:#15803d;--green-bg:#dcfce7;--red:#b91c1c;--red-bg:#fee2e2;
  --orange:#b45309;--orange-bg:#fef3c7;--gray:#475569;--gray-bg:#eef2f7;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:13px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}
.topbar{
  height:auto;
  min-height:52px;
  background:var(--surface);
  border-bottom:1px solid var(--border);
  display:flex;
  align-items:flex-start;
  padding:10px 16px;
  gap:12px;
  flex-wrap:wrap;
  box-shadow:0 1px 4px rgba(0,0,0,.07);
  position:sticky;
  top:0;
  z-index:200;
}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:0;text-decoration:none;flex-shrink:0}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff;display:block}
.tb-sep{width:1px;height:22px;background:var(--border);margin:0 2px;flex-shrink:0}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);flex-wrap:wrap}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-btn.alert-red{background:#d93025;border-color:#d93025;color:#fff}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}
.tb-updated{font-size:12px;color:var(--text2);white-space:nowrap}
.tb-right .tb-btn, .tb-right .tb-user{flex-shrink:0}
.topbar a{white-space:nowrap}
.topbar form{margin:0}
@media (max-width: 1280px){
  .topbar{padding:10px 12px}
  .tb-sep{display:none}
  .tb-btn{padding:5px 10px;font-size:12px}
}
@media (max-width: 680px){
  .tb-logo{width:100%}
  .tb-right{width:100%;margin-left:0}
  .tb-user{width:100%;justify-content:flex-start}
  .tb-btn{width:100%;justify-content:center}
}
.wrap{padding:16px;display:grid;gap:12px}
.title{font-size:16px;font-weight:800}
.sub{color:var(--text2);font-size:12px}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap}
.field{display:grid;gap:4px}
.field label{font-size:11px;font-weight:800;text-transform:uppercase;color:var(--text2)}
input,select,button{height:34px;border:1px solid var(--border);border-radius:6px;background:#fff;padding:0 10px;font:inherit}
button{border-color:var(--blue);background:var(--blue);color:#fff;font-weight:800;cursor:pointer}
button.secondary{background:#fff;color:var(--text2);border-color:var(--border)}
button:disabled{opacity:.55;cursor:default}
.summary{color:var(--text2)}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:auto}
table{width:100%;border-collapse:collapse;min-width:1280px}
th,td{border-bottom:1px solid #edf0f4;padding:8px 10px;text-align:left;vertical-align:top}
th{background:var(--soft);color:var(--text2);font-size:11px;text-transform:uppercase;white-space:nowrap}
tr:last-child td{border-bottom:0}
tr:hover td{background:#fbfcff}
.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.muted{color:var(--text2)}
.badge{display:inline-flex;align-items:center;min-height:22px;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:800;background:var(--gray-bg);color:var(--gray);white-space:nowrap}
.badge.ok{background:var(--green-bg);color:var(--green)}
.badge.failed,.badge.error{background:var(--red-bg);color:var(--red)}
.badge.pending,.badge.running{background:var(--orange-bg);color:var(--orange)}
.detail{background:var(--surface);border:1px solid var(--border);border-radius:8px;display:none}
.detail.open{display:block}
.detail-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-bottom:1px solid var(--border)}
.detail-title{font-weight:800}
.detail-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:12px}
.json-box{border:1px solid var(--border);border-radius:8px;overflow:hidden;background:#fff}
.json-box h3{margin:0;padding:8px 10px;background:var(--soft);font-size:11px;text-transform:uppercase;color:var(--text2)}
pre{margin:0;padding:10px;max-height:320px;overflow:auto;font:12px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap;word-break:break-word}
.empty,.error{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:18px;color:var(--text2)}
.error{border-color:#fecaca;background:var(--red-bg);color:var(--red)}
@media (max-width:900px){
  .detail-body{grid-template-columns:1fr}
}
</style>
</head>
<body>
<?php include __DIR__ . '/_header.php'; ?>
<div class="wrap">
  <div>
    <div class="title">API Sync Logs</div>
    <div class="sub">HTTP request journal for FBTool and Keitaro syncs, including response previews and transport errors</div>
  </div>

  <div class="panel">
    <div class="field"><label>Search</label><input id="q" type="search" placeholder="Endpoint, request, error, preview"></div>
    <div class="field"><label>Request</label><input id="request_type" placeholder="fbtool:get-accounts"></div>
    <div class="field"><label>Status</label><select id="status"><option value="">All</option><option>ok</option><option>failed</option></select></div>
    <div class="field"><label>HTTP Code</label><input id="http_code" inputmode="numeric" placeholder="200"></div>
    <div class="field"><label>Endpoint</label><input id="endpoint" placeholder="https://..."></div>
    <div class="field"><label>Limit</label><select id="limit"><option>100</option><option>200</option><option>500</option></select></div>
    <button id="runBtn">Refresh</button>
    <button class="secondary" id="clearBtn">Clear</button>
  </div>

  <div id="summary" class="summary"></div>
  <div id="error"></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Request</th>
          <th>Endpoint</th>
          <th>Status</th>
          <th>HTTP</th>
          <th>Duration</th>
          <th>Rows</th>
          <th>Attempt</th>
          <th>Sync</th>
          <th>Preview / Error</th>
        </tr>
      </thead>
      <tbody id="rows"><tr><td colspan="10" class="muted">Loading...</td></tr></tbody>
    </table>
  </div>

  <div id="detail" class="detail">
    <div class="detail-head">
      <div class="detail-title" id="detailTitle">Request detail</div>
      <button class="secondary" id="closeDetail">Close</button>
    </div>
    <div class="detail-body">
      <div class="json-box"><h3>Response Preview</h3><pre id="previewJson"></pre></div>
      <div class="json-box"><h3>Raw Error</h3><pre id="errorJson"></pre></div>
      <div class="json-box"><h3>Raw Row</h3><pre id="rawJson"></pre></div>
    </div>
  </div>
</div>
<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let currentRows = [];

$('runBtn').addEventListener('click', loadLog);
$('clearBtn').addEventListener('click', () => {
  ['q','request_type','status','http_code','endpoint'].forEach(id => { $(id).value = ''; });
  $('limit').value = '200';
  loadLog();
});
$('closeDetail').addEventListener('click', () => $('detail').classList.remove('open'));
['q','request_type','http_code','endpoint'].forEach(id => {
  $(id).addEventListener('keydown', e => { if (e.key === 'Enter') loadLog(); });
});
loadLog();

async function loadLog() {
  $('runBtn').disabled = true;
  $('error').innerHTML = '';
  $('summary').textContent = 'Loading...';
  const p = new URLSearchParams();
  ['q','request_type','status','http_code','endpoint','limit'].forEach(id => {
    const v = String($(id).value ?? '').trim();
    if (v) p.set(id, v);
  });
  try {
    const res = await fetch('/api/api_sync_logs.php?' + p.toString(), {cache:'no-store'});
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    currentRows = json.data?.rows || [];
    renderRows(currentRows);
    $('summary').textContent = `${currentRows.length} requests loaded`;
  } catch (e) {
    $('error').innerHTML = `<div class="error">${esc(e.message)}</div>`;
    $('rows').innerHTML = '<tr><td colspan="10" class="muted">Failed to load request log.</td></tr>';
    $('summary').textContent = '';
  } finally {
    $('runBtn').disabled = false;
  }
}

function renderRows(rows) {
  if (!rows.length) {
    $('rows').innerHTML = '<tr><td colspan="10" class="muted">No request logs found.</td></tr>';
    return;
  }
  $('rows').innerHTML = rows.map((r, i) => `
    <tr data-row="${i}">
      <td class="mono">${esc(formatTime(r.ts))}<div class="muted">#${esc(r.id)}</div></td>
      <td>${esc(r.request_type)}</td>
      <td class="mono">${esc(shorten(r.endpoint, 70))}</td>
      <td><span class="badge ${esc(r.status)}">${esc(r.status)}</span></td>
      <td class="mono">${r.http_code ?? ''}</td>
      <td class="mono">${r.duration_ms ?? ''} ms</td>
      <td class="mono">${r.rows_returned ?? ''}</td>
      <td class="mono">${r.attempt ?? ''}</td>
      <td class="mono">${r.sync_log_id ?? ''}</td>
      <td><div>${esc(shorten(r.response_preview || r.error_msg || '', 90))}</div></td>
    </tr>
  `).join('');
  document.querySelectorAll('[data-row]').forEach(tr => {
    tr.addEventListener('click', () => openDetail(currentRows[Number(tr.dataset.row)]));
  });
}

function openDetail(row) {
  $('detailTitle').textContent = `#${row.id} ${row.request_type}`;
  $('previewJson').textContent = pretty(row.response_preview);
  $('errorJson').textContent = pretty(row.raw_error || row.error_msg);
  $('rawJson').textContent = pretty(row);
  $('detail').classList.add('open');
  $('detail').scrollIntoView({behavior:'smooth', block:'start'});
}

function pretty(value) {
  if (value === null || value === undefined || value === '') return '';
  if (typeof value === 'string') return value;
  return JSON.stringify(value, null, 2);
}

function formatTime(value) {
  if (!value) return '';
  return String(value).replace('T', ' ').replace(/\.\d+Z?$/, '');
}

function shorten(value, limit) {
  const str = String(value ?? '');
  return str.length > limit ? str.slice(0, limit - 1) + '...' : str;
}
</script>
</body>
</html>
