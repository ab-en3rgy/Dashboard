<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DB.php';
require_once __DIR__ . '/lib/Auth.php';

$db = DB::getInstance();
$auth = new Auth($db);
$token = $_COOKIE['fb_ads_token'] ?? '';
$me = $token ? $auth->check($token) : null;
if (!$me) {
    header('Location: /login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Global Log</title>
<style>
:root{
  --bg:#f0f2f5;--surface:#fff;--border:#d8dde6;--soft:#f7f8fa;
  --text:#1c1e21;--text2:#65676b;--blue:#1877f2;
  --green:#15803d;--green-bg:#dcfce7;--red:#b91c1c;--red-bg:#fee2e2;
  --orange:#b45309;--orange-bg:#fef3c7;--gray:#475569;--gray-bg:#eef2f7;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:13px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}
.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:0;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2)}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-btn.alert-red{background:#d93025;border-color:#d93025;color:#fff}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}
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
table{width:100%;border-collapse:collapse;min-width:1180px}
th,td{border-bottom:1px solid #edf0f4;padding:8px 10px;text-align:left;vertical-align:top}
th{background:var(--soft);color:var(--text2);font-size:11px;text-transform:uppercase;white-space:nowrap}
tr:last-child td{border-bottom:0}
tr:hover td{background:#fbfcff}
.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.muted{color:var(--text2)}
.badge{display:inline-flex;align-items:center;min-height:22px;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:800;background:var(--gray-bg);color:var(--gray);white-space:nowrap}
.badge.done{background:var(--green-bg);color:var(--green)}
.badge.failed,.badge.error{background:var(--red-bg);color:var(--red)}
.badge.pending,.badge.running{background:var(--orange-bg);color:var(--orange)}
.entity{font-weight:700}
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
    <div class="title">Global Log</div>
    <div class="sub">Audit trail for campaign, adset, bid, delete, create, task, worker, and local-state actions</div>
  </div>

  <div class="panel">
    <div class="field"><label>Search</label><input id="q" type="search" placeholder="Campaign, task, error, reason"></div>
    <div class="field"><label>Event</label><select id="event_type"><option value="">All</option></select></div>
    <div class="field"><label>Status</label><select id="status"><option value="">All</option></select></div>
    <div class="field"><label>Entity</label><select id="entity_type"><option value="">All</option></select></div>
    <div class="field"><label>Source</label><input id="source" placeholder="dashboard"></div>
    <div class="field"><label>Task ID</label><input id="task_id" inputmode="numeric" placeholder="123"></div>
    <div class="field"><label>Campaign ID</label><input id="campaign_id" inputmode="numeric"></div>
    <div class="field"><label>Adset ID</label><input id="adset_id" inputmode="numeric"></div>
    <div class="field"><label>Limit</label><select id="limit"><option>250</option><option>500</option><option>1000</option></select></div>
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
          <th>Event</th>
          <th>Status</th>
          <th>Action</th>
          <th>Entity</th>
          <th>Task</th>
          <th>Source</th>
          <th>Actor</th>
          <th>BM / Account</th>
          <th>Reason / Error</th>
        </tr>
      </thead>
      <tbody id="rows"><tr><td colspan="10" class="muted">Loading...</td></tr></tbody>
    </table>
  </div>

  <div id="detail" class="detail">
    <div class="detail-head">
      <div class="detail-title" id="detailTitle">Event detail</div>
      <button class="secondary" id="closeDetail">Close</button>
    </div>
    <div class="detail-body">
      <div class="json-box"><h3>Before</h3><pre id="beforeJson"></pre></div>
      <div class="json-box"><h3>Desired</h3><pre id="desiredJson"></pre></div>
      <div class="json-box"><h3>After</h3><pre id="afterJson"></pre></div>
      <div class="json-box"><h3>Payload</h3><pre id="payloadJson"></pre></div>
      <div class="json-box"><h3>Result</h3><pre id="resultJson"></pre></div>
      <div class="json-box"><h3>Raw Row</h3><pre id="rawJson"></pre></div>
    </div>
  </div>
</div>
<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const eventOptions = ['decision','task_created','task_polled','task_started','fb_result','local_state_applied','task_failed','task_cancelled','task_retried','task_deleted','task_updated'];
const statusOptions = ['pending','running','done','failed','cancelled','info'];
const entityOptions = ['campaign','adset','ad','creative','task','sync','rule'];
let currentRows = [];

fillOptions('event_type', eventOptions);
fillOptions('status', statusOptions);
fillOptions('entity_type', entityOptions);

$('runBtn').addEventListener('click', loadLog);
$('clearBtn').addEventListener('click', () => {
  ['q','source','task_id','campaign_id','adset_id','event_type','status','entity_type'].forEach(id => { $(id).value = ''; });
  loadLog();
});
$('closeDetail').addEventListener('click', () => $('detail').classList.remove('open'));
['q','source','task_id','campaign_id','adset_id'].forEach(id => {
  $(id).addEventListener('keydown', e => { if (e.key === 'Enter') loadLog(); });
});
loadLog();

function fillOptions(id, values) {
  for (const value of values) {
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = value;
    $(id).appendChild(opt);
  }
}

async function loadLog() {
  $('runBtn').disabled = true;
  $('error').innerHTML = '';
  $('summary').textContent = 'Loading...';
  const p = new URLSearchParams();
  ['q','event_type','status','entity_type','source','task_id','campaign_id','adset_id','limit'].forEach(id => {
    const v = $(id).value.trim();
    if (v) p.set(id, v);
  });
  try {
    const res = await fetch('/api/global_log.php?' + p.toString(), {cache:'no-store'});
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    currentRows = json.data?.rows || [];
    renderRows(currentRows);
    $('summary').textContent = `${currentRows.length} events loaded`;
  } catch (e) {
    $('error').innerHTML = `<div class="error">${esc(e.message)}</div>`;
    $('rows').innerHTML = '<tr><td colspan="10" class="muted">Failed to load log.</td></tr>';
    $('summary').textContent = '';
  } finally {
    $('runBtn').disabled = false;
  }
}

function renderRows(rows) {
  if (!rows.length) {
    $('rows').innerHTML = '<tr><td colspan="10" class="muted">No log events found.</td></tr>';
    return;
  }
  $('rows').innerHTML = rows.map((r, i) => `
    <tr data-row="${i}">
      <td class="mono">${esc(formatTime(r.created_at))}<div class="muted">#${esc(r.id)}</div></td>
      <td>${esc(r.event_type)}</td>
      <td><span class="badge ${esc(r.status)}">${esc(r.status)}</span></td>
      <td>${esc(r.action)}</td>
      <td><div class="entity">${esc(r.entity_type)} ${esc(r.entity_id || '')}</div><div class="muted">${esc(r.campaign_name || r.adset_name || '')}</div></td>
      <td class="mono">${r.task_id ? '#' + esc(r.task_id) : ''}</td>
      <td>${esc(r.source)}</td>
      <td>${esc(r.actor)}</td>
      <td><div>${esc(r.bm_name || r.bm_id)}</div><div class="muted">${esc(r.account_name || r.account_id)}</div></td>
      <td><div>${esc(r.reason)}</div>${r.error ? `<div class="muted">${esc(r.error)}</div>` : ''}</td>
    </tr>
  `).join('');
  document.querySelectorAll('[data-row]').forEach(tr => {
    tr.addEventListener('click', () => openDetail(currentRows[Number(tr.dataset.row)]));
  });
}

function openDetail(row) {
  $('detailTitle').textContent = `#${row.id} ${row.event_type} ${row.action}`;
  $('beforeJson').textContent = pretty(row.before_state);
  $('desiredJson').textContent = pretty(row.desired_state);
  $('afterJson').textContent = pretty(row.after_state);
  $('payloadJson').textContent = pretty(row.payload);
  $('resultJson').textContent = pretty(row.result);
  $('rawJson').textContent = pretty(row);
  $('detail').classList.add('open');
  $('detail').scrollIntoView({behavior:'smooth', block:'start'});
}

function pretty(value) {
  if (value === null || value === undefined || value === '') return '';
  return JSON.stringify(value, null, 2);
}

function formatTime(value) {
  if (!value) return '';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
}
</script>
</body>
</html>
