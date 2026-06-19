<?php
// @version 1.0.1
require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Auth.php';

$db = DB::getInstance();
$auth = new Auth($db);

$token = $_COOKIE['fb_ads_token'] ?? '';
if (!$token) { header('Location: /login.php'); exit; }
$me = $auth->check($token);
if (!$me) {
    setcookie('fb_ads_token', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: /login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campaign Builder 2 - FB Ads</title>
<meta name="color-scheme" content="light dark">
<script>
(function () {
  const key = 'fb_ads_theme';
  const stored = localStorage.getItem(key);
  const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  const theme = stored === 'dark' || stored === 'light' ? stored : (prefersDark ? 'dark' : 'light');
  document.documentElement.dataset.theme = theme;
  document.documentElement.style.colorScheme = theme;
})();
</script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--surface:#fff;--surface2:#f8fafc;--border:#dddfe2;--border2:#ccd0d5;--border-light:#e4e6eb;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;--blue:#1877f2;--blue2:#166fe5;--blue-bg:#e7f0fd;
  --green:#31a24c;--green-bg:#e6f4ea;--amber:#b7791f;--amber-bg:#fff7e6;--red:#fa3e3e;--red-bg:#fce8e8;
  --shadow:0 1px 2px rgba(0,0,0,.08),0 1px 8px rgba(0,0,0,.05);--r:8px;--r2:6px;
}
:root[data-theme="dark"]{
  --bg:#0b1220;--surface:#111827;--surface2:#0f172a;--border:#243047;--border2:#334155;--border-light:#1f2a3d;
  --text:#e5e7eb;--text2:#cbd5e1;--text3:#94a3b8;--blue:#60a5fa;--blue2:#3b82f6;--blue-bg:rgba(96,165,250,.14);
  --green:#4ade80;--green-bg:rgba(74,222,128,.12);--amber:#fbbf24;--amber-bg:rgba(251,191,36,.12);--red:#f87171;--red-bg:rgba(248,113,113,.12);
  --shadow:0 1px 2px rgba(0,0,0,.28),0 1px 12px rgba(0,0,0,.22);
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh}
.main{padding:18px;display:flex;flex-direction:column;gap:14px}
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.toolbar h1{font-size:20px;font-weight:800}
.subtle{color:var(--text3);font-size:12px}
.flt{height:34px;padding:6px 10px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);color:var(--text);font:inherit;font-size:13px;outline:none}
.flt:focus{border-color:var(--blue)}
.btn{height:34px;padding:0 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);color:var(--text2);font:inherit;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;text-decoration:none;white-space:nowrap}
.btn:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-bg)}
.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.btn.primary:hover{background:var(--blue2)}
.btn:disabled{opacity:.55;cursor:not-allowed}
.layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:14px;align-items:start}
.left{display:flex;flex-direction:column;gap:14px;min-width:0}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}
.panel-h{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
.panel-h h2{font-size:14px;font-weight:800}
.panel-body{padding:14px}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px}
.metric{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;min-width:0}
.metric span{display:block;color:var(--text3);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.metric strong{display:block;margin-top:4px;font-size:22px;font-weight:800;line-height:1}
.metric.ready strong{color:var(--green)}
.metric.warn strong{color:var(--amber)}
.metric.bad strong{color:var(--red)}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:1020px}
thead th{position:sticky;top:0;z-index:1;background:var(--surface2);padding:9px 10px;text-align:left;font-size:11px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.35px;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:10px;border-bottom:1px solid var(--border-light);vertical-align:middle;font-size:13px}
tr:hover td{background:var(--surface2)}
.cell-title{font-weight:750;color:var(--text);line-height:1.2;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cell-sub{font-size:11.5px;color:var(--text3);margin-top:2px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:5px;font-size:11.5px;font-weight:800;white-space:nowrap}
.badge.ready{background:var(--green-bg);color:var(--green)}
.badge.warn{background:var(--amber-bg);color:var(--amber)}
.badge.blocked{background:var(--red-bg);color:var(--red)}
.badge.idle{background:var(--blue-bg);color:var(--blue)}
.mono{font-family:'SF Mono',Consolas,monospace;font-size:12px}
.reason{color:var(--text2);font-size:12px;max-width:260px;line-height:1.3}
.check-cell{width:38px;text-align:center}
.check-cell input,.creative-row input{width:16px;height:16px;accent-color:var(--blue)}
.side{position:sticky;top:76px;display:flex;flex-direction:column;gap:14px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-row{display:flex;flex-direction:column;gap:4px}
.form-row.full{grid-column:1/-1}
label{font-size:11px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.3px}
input,select,textarea{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font:inherit;font-size:13px;background:var(--surface);color:var(--text);outline:none}
textarea{min-height:78px;resize:vertical;font-family:'SF Mono',Consolas,monospace;font-size:12px}
input:focus,select:focus,textarea:focus{border-color:var(--blue)}
.creative-list{display:flex;flex-direction:column;gap:6px;max-height:360px;overflow:auto;padding-right:2px}
.creative-row{display:grid;grid-template-columns:20px minmax(0,1fr);gap:8px;padding:8px;border:1px solid var(--border-light);border-radius:var(--r2);background:var(--surface2)}
.creative-name{font-weight:750;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.creative-meta{font-size:11px;color:var(--text3);margin-top:3px;display:flex;gap:8px;flex-wrap:wrap}
.note{font-size:12px;color:var(--text2);line-height:1.45}
.empty{padding:36px 14px;text-align:center;color:var(--text3);font-size:13px}
.toast{display:none;padding:10px 12px;border-radius:var(--r2);font-size:13px;line-height:1.35}
.toast.show{display:block}
.toast.ok{background:var(--green-bg);color:var(--green)}
.toast.err{background:var(--red-bg);color:var(--red)}
[data-theme="dark"] thead th{background:#0f172a}
@media(max-width:1100px){.layout{grid-template-columns:1fr}.side{position:static}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.main{padding:12px}.summary{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.toolbar{align-items:stretch}.toolbar h1{width:100%}.flt,.btn{width:100%;justify-content:center}}
</style>
</head>
<body>
<?php include __DIR__ . '/_header.php'; ?>
<div class="main">
  <div class="toolbar">
    <h1>Campaign Builder 2</h1>
    <select class="flt" id="geoSelect" onchange="setGeo(this.value)">
      <option value="">Choose GEO</option>
    </select>
    <select class="flt" id="statusFilter" onchange="renderRows()">
      <option value="">All statuses</option>
      <option value="ready">Ready only</option>
      <option value="warn">Ready with warnings</option>
      <option value="blocked">Blocked</option>
    </select>
    <input class="flt" id="searchInput" type="search" placeholder="Search account or BM" oninput="renderRows()">
    <button class="btn" type="button" onclick="loadInventory()">Refresh</button>
    <span class="subtle" id="loadState">Loading...</span>
  </div>

  <div class="summary" id="summary"></div>

  <div class="layout">
    <div class="left">
      <section class="panel">
        <div class="panel-h">
          <h2>Launch Inventory</h2>
          <span class="subtle" id="rowCount">0 accounts</span>
        </div>
        <div class="table-wrap" id="rowsWrap">
          <div class="empty">Loading inventory...</div>
        </div>
      </section>
    </div>

    <aside class="side">
      <section class="panel">
        <div class="panel-h"><h2>Queue Setup</h2><span class="subtle" id="selectionCount">0 selected</span></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="form-row">
              <label>Daily Budget</label>
              <input id="dailyBudget" type="number" min="1" step="0.01">
            </div>
            <div class="form-row">
              <label>Bid Amount</label>
              <input id="bidAmount" type="number" min="0" step="0.01">
            </div>
            <div class="form-row">
              <label>Bid Strategy</label>
              <select id="bidStrategy">
                <option value="bidcap">Bid Cap</option>
                <option value="costcap">Cost Cap</option>
                <option value="auto">Auto</option>
              </select>
            </div>
            <div class="form-row">
              <label>Pixel Mode</label>
              <select id="pixelMode">
                <option value="auto">Auto pixel</option>
                <option value="manual">Configured pixel</option>
              </select>
            </div>
            <div class="form-row">
              <label>Landing URL</label>
              <input id="destUrl" type="text" placeholder="https://example.com">
            </div>
            <div class="form-row">
              <label>Page ID</label>
              <input id="pageId" type="text" placeholder="Facebook Page ID">
            </div>
            <div class="form-row">
              <label>Pixel ID</label>
              <input id="pixelId" type="text" placeholder="Optional in auto mode">
            </div>
            <div class="form-row">
              <label>Ad Sets</label>
              <input id="adsetsNum" type="number" min="1" step="1">
            </div>
            <div class="form-row">
              <label>Ads per Ad Set</label>
              <input id="adsNum" type="number" min="1" step="1">
            </div>
            <div class="form-row">
              <label>Approach</label>
              <input id="approach" type="text">
            </div>
            <div class="form-row">
              <label>Text GEO</label>
              <input id="textGeo" type="text" maxlength="2">
            </div>
            <div class="form-row full">
              <label>URL Params</label>
              <textarea id="urlParams"></textarea>
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
            <button class="btn primary" id="queueBtn" type="button" onclick="queueSelected()" disabled>Queue Ready Accounts</button>
            <button class="btn" type="button" onclick="selectReady(true)">Select ready</button>
            <button class="btn" type="button" onclick="clearSelection()">Clear</button>
          </div>
          <div class="toast" id="toast"></div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-h">
          <h2>Creative Pack</h2>
          <div style="display:flex;gap:6px">
            <button class="btn" type="button" onclick="selectTopCreatives(5)">Top 5</button>
            <button class="btn" type="button" onclick="setAllCreatives(false)">Clear</button>
          </div>
        </div>
        <div class="panel-body">
          <div class="creative-list" id="creativeList"><div class="empty">Choose a GEO to load creatives.</div></div>
        </div>
      </section>
    </aside>
  </div>
</div>

<script>
const API = 'api/campaign_builder2.php';
const state = {
  geo: new URLSearchParams(location.search).get('geo') || '',
  rows: [],
  creatives: [],
  defaults: {},
  filters: { bms: [], geos: [] },
  selectedAccounts: new Set(),
  selectedCreatives: new Set(),
};

function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}
function money(v) {
  const n = Number(v || 0);
  return n ? '$' + n.toFixed(2) : '-';
}
function num(v) {
  return Number(v || 0).toLocaleString('en-US');
}
function showToast(text, ok = true) {
  const el = document.getElementById('toast');
  el.textContent = text;
  el.className = 'toast show ' + (ok ? 'ok' : 'err');
}
function syncUrl() {
  const url = new URL(location.href);
  if (state.geo) url.searchParams.set('geo', state.geo); else url.searchParams.delete('geo');
  history.replaceState(null, '', url);
}
function setGeo(geo) {
  state.geo = String(geo || '').toUpperCase();
  state.selectedAccounts.clear();
  state.selectedCreatives.clear();
  syncUrl();
  loadInventory();
}
async function loadInventory() {
  const label = document.getElementById('loadState');
  label.textContent = 'Loading...';
  try {
    const params = new URLSearchParams({ action: 'inventory' });
    if (state.geo) params.set('geo', state.geo);
    const res = await fetch(API + '?' + params.toString());
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || 'Inventory API error');
    const data = json.data || {};
    state.rows = data.rows || [];
    state.creatives = data.creatives || [];
    state.defaults = data.defaults || {};
    state.filters = data.filters || { bms: [], geos: [] };
    fillGeoOptions();
    fillDefaults();
    renderSummary(data.summary || {});
    renderRows();
    renderCreatives();
    label.textContent = state.geo ? `GEO ${state.geo}` : 'Choose GEO';
  } catch (err) {
    label.textContent = 'Error';
    document.getElementById('rowsWrap').innerHTML = `<div class="empty">Error: ${esc(err.message)}</div>`;
    showToast(err.message, false);
  }
}
function fillGeoOptions() {
  const el = document.getElementById('geoSelect');
  const geos = Array.isArray(state.filters.geos) ? state.filters.geos : [];
  const current = state.geo;
  el.innerHTML = '<option value="">Choose GEO</option>' + geos.map(g => `<option value="${esc(g)}" ${g === current ? 'selected' : ''}>${esc(g)}</option>`).join('');
  if (current && !geos.includes(current)) el.innerHTML += `<option value="${esc(current)}" selected>${esc(current)}</option>`;
}
function fillDefaults() {
  const d = state.defaults || {};
  document.getElementById('dailyBudget').value = d.daily_budget ?? 10;
  document.getElementById('bidAmount').value = d.bid_amount ?? 1;
  document.getElementById('bidStrategy').value = d.bid_strategy_mode || 'bidcap';
  document.getElementById('pixelMode').value = d.pixel_mode || 'auto';
  document.getElementById('adsetsNum').value = d.adsets_num ?? 1;
  document.getElementById('adsNum').value = d.ads_num ?? 1;
  document.getElementById('approach').value = d.approach || 'rtp98';
  document.getElementById('textGeo').value = d.text_geo || state.geo || '';
  document.getElementById('urlParams').value = d.url_params || '';
  document.getElementById('destUrl').value = d.dest_url || '';
  document.getElementById('pageId').value = d.page_id || '';
  document.getElementById('pixelId').value = d.pixel_id || '';
}
function renderSummary(s) {
  const cards = [
    ['Ready accounts', s.ready_accounts, 'ready'],
    ['Blocked accounts', s.blocked_accounts, 'bad'],
    ['Active on GEO', s.active_geo_accounts, ''],
    ['Pending tasks', s.pending_tasks, 'bad'],
    ['Creatives', s.creatives_total, ''],
    ['Ranked creatives', s.creatives_ranked, 'ready'],
    ['All accounts', s.accounts_total, ''],
  ];
  document.getElementById('summary').innerHTML = cards.map(([label, value, cls]) => `
    <div class="metric ${cls}">
      <span>${esc(label)}</span>
      <strong>${num(value)}</strong>
    </div>
  `).join('');
}
function filteredRows() {
  const status = document.getElementById('statusFilter').value;
  const q = document.getElementById('searchInput').value.trim().toLowerCase();
  return state.rows.filter(row => {
    if (status === 'ready' && !row.ready) return false;
    if (status === 'warn' && row.status_key !== 'warn') return false;
    if (status === 'blocked' && row.status_key !== 'blocked') return false;
    if (!q) return true;
    const hay = [row.account_name,row.account_id,row.bm_name,row.bm_id,row.block_reason].join(' ').toLowerCase();
    return hay.includes(q);
  });
}
function renderRows() {
  const rows = filteredRows();
  document.getElementById('rowCount').textContent = `${rows.length} account${rows.length === 1 ? '' : 's'}`;
  if (!rows.length) {
    document.getElementById('rowsWrap').innerHTML = '<div class="empty">No accounts match the current filters.</div>';
    updateSelectionUi();
    return;
  }
  document.getElementById('rowsWrap').innerHTML = `
    <table>
      <thead><tr>
        <th class="check-cell"><input type="checkbox" onchange="toggleVisible(this.checked)"></th>
        <th>Status</th><th>BM</th><th>Account</th><th>Active GEO</th><th>Reason</th>
      </tr></thead>
      <tbody>
        ${rows.map(rowHtml).join('')}
      </tbody>
    </table>
  `;
  updateSelectionUi();
}
function rowHtml(row) {
  const checked = state.selectedAccounts.has(String(row.account_id)) ? 'checked' : '';
  const disabled = row.ready ? '' : 'disabled';
  const cls = row.status_key === 'ready' ? 'ready' : (row.status_key === 'warn' ? 'warn' : (row.status_key === 'idle' ? 'idle' : 'blocked'));
  const reason = row.block_reason || (Array.isArray(row.warnings) && row.warnings.length ? row.warnings.join(' ') : 'Ready to queue.');
  return `
    <tr>
      <td class="check-cell"><input type="checkbox" value="${esc(row.account_id)}" ${checked} ${disabled} onchange="toggleAccount('${esc(row.account_id)}', this.checked)"></td>
      <td><span class="badge ${cls}">${esc(row.status_label)}</span></td>
      <td><div class="cell-title">${esc(row.bm_name || row.bm_id)}</div><div class="cell-sub mono">${esc(row.bm_id)}</div></td>
      <td><div class="cell-title">${esc(row.account_name || row.account_id)}</div><div class="cell-sub mono">${esc(row.account_id)}</div></td>
      <td>${Number(row.active_geo_count || 0) ? '<span class="badge blocked">' + num(row.active_geo_count) + '</span>' : '<span class="badge ready">0</span>'}</td>
      <td><div class="reason">${esc(reason)}</div></td>
    </tr>
  `;
}
function toggleAccount(id, checked) {
  if (checked) state.selectedAccounts.add(String(id)); else state.selectedAccounts.delete(String(id));
  updateSelectionUi();
}
function toggleVisible(checked) {
  for (const row of filteredRows()) {
    if (!row.ready) continue;
    if (checked) state.selectedAccounts.add(String(row.account_id));
    else state.selectedAccounts.delete(String(row.account_id));
  }
  renderRows();
}
function selectReady(checked) {
  for (const row of state.rows) {
    if (!row.ready) continue;
    if (checked) state.selectedAccounts.add(String(row.account_id));
    else state.selectedAccounts.delete(String(row.account_id));
  }
  renderRows();
}
function clearSelection() {
  state.selectedAccounts.clear();
  renderRows();
}
function renderCreatives() {
  const wrap = document.getElementById('creativeList');
  if (!state.geo) {
    wrap.innerHTML = '<div class="empty">Choose a GEO to load creatives.</div>';
    updateSelectionUi();
    return;
  }
  if (!state.creatives.length) {
    wrap.innerHTML = '<div class="empty">No creatives found for this GEO.</div>';
    updateSelectionUi();
    return;
  }
  wrap.innerHTML = state.creatives.map(c => {
    const name = String(c.creative_name || '');
    const checked = state.selectedCreatives.has(name) ? 'checked' : '';
    const rank = c.rank ? '#' + c.rank : c.source;
    return `
      <label class="creative-row">
        <input type="checkbox" value="${esc(name)}" ${checked} onchange="toggleCreative(this.value, this.checked)">
        <div>
          <div class="creative-name">${esc(name)}</div>
          <div class="creative-meta">
            <span>${esc(rank)}</span><span>${money(c.profit)} profit</span><span>${Number(c.roi || 0).toFixed(1)}% ROI</span><span>${num(c.bm_count)} BM</span>
          </div>
        </div>
      </label>
    `;
  }).join('');
  updateSelectionUi();
}
function toggleCreative(name, checked) {
  if (checked) state.selectedCreatives.add(String(name)); else state.selectedCreatives.delete(String(name));
  updateSelectionUi();
}
function selectTopCreatives(limit) {
  state.selectedCreatives.clear();
  for (const row of state.creatives.slice(0, limit)) state.selectedCreatives.add(String(row.creative_name || ''));
  renderCreatives();
}
function setAllCreatives(checked) {
  state.selectedCreatives.clear();
  if (checked) for (const row of state.creatives) state.selectedCreatives.add(String(row.creative_name || ''));
  renderCreatives();
}
function updateSelectionUi() {
  const ac = state.selectedAccounts.size;
  const cr = state.selectedCreatives.size;
  document.getElementById('selectionCount').textContent = `${ac} account${ac === 1 ? '' : 's'} | ${cr} creative${cr === 1 ? '' : 's'}`;
  document.getElementById('queueBtn').disabled = !state.geo || ac === 0 || cr === 0;
}
function payload() {
  return {
    action: 'create',
    geo: state.geo,
    account_ids: Array.from(state.selectedAccounts),
    creative_names: Array.from(state.selectedCreatives),
    dest_url: document.getElementById('destUrl').value,
    page_id: document.getElementById('pageId').value,
    pixel_id: document.getElementById('pixelMode').value === 'manual' ? document.getElementById('pixelId').value : '',
    daily_budget: document.getElementById('dailyBudget').value,
    bid_amount: document.getElementById('bidAmount').value,
    bid_strategy_mode: document.getElementById('bidStrategy').value,
    pixel_mode: document.getElementById('pixelMode').value,
    adsets_num: document.getElementById('adsetsNum').value,
    ads_num: document.getElementById('adsNum').value,
    approach: document.getElementById('approach').value,
    text_geo: document.getElementById('textGeo').value,
    url_params: document.getElementById('urlParams').value,
    use_languages: true,
    use_target_geos: Array.isArray(state.defaults.target_geos) && state.defaults.target_geos.length > 0,
    no_text: true,
    random_bid_cap: false,
    bid_spread_pct: 20,
  };
}
async function queueSelected() {
  const btn = document.getElementById('queueBtn');
  btn.disabled = true;
  showToast('Queueing tasks...', true);
  try {
    const res = await fetch(API, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload())
    });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error(json.error || 'Task creation failed');
    const skipped = json.data?.skipped?.length || 0;
    showToast(`Queued ${json.count || 0} task${json.count === 1 ? '' : 's'}${skipped ? ', skipped ' + skipped : ''}.`, true);
    state.selectedAccounts.clear();
    await loadInventory();
  } catch (err) {
    showToast(err.message, false);
    updateSelectionUi();
  }
}

loadInventory();
</script>
</body>
</html>
