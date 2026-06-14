<?php
// fanpage_data.php — data manager for api/ext/fp_setup.php
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
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Admin only';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fanpage Data — FB Ads</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--surface:#fff;--border:#dddfe2;--border2:#ccd0d5;--border-light:#e4e6eb;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;--blue:#1877f2;--blue2:#166fe5;--blue-bg:#e7f0fd;
  --green:#31a24c;--green-bg:#e6f4ea;--red:#fa3e3e;--red-bg:#fce8e8;
  --shadow:0 1px 2px rgba(0,0,0,.08),0 1px 8px rgba(0,0,0,.05);--r:8px;--r2:6px;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh;display:flex;flex-direction:column}
/* TOPBAR */
.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none;white-space:nowrap}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px;flex-shrink:0}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px;white-space:nowrap}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}
.tb-updated{font-size:12px;color:var(--text3);white-space:nowrap}
.tb-btn.alert-red{border-color:var(--red);color:var(--red);background:var(--red-bg)}
.main{flex:1;padding:20px;display:flex;flex-direction:column;gap:16px}
.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.toolbar h1{font-size:18px;font-weight:800;margin-right:6px}
.tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r2);padding:3px}
.tab{padding:5px 12px;border:0;border-radius:4px;background:transparent;cursor:pointer;font:700 12px inherit;color:var(--text2)}
.tab.active{background:var(--blue);color:#fff}
.filter-group{display:flex;align-items:center;gap:5px}
.filter-label{font-size:12px;font-weight:700;color:var(--text3);white-space:nowrap}
.flt{height:32px;padding:5px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font:13px inherit;color:var(--text);background:var(--surface);outline:none}
.flt:focus{border-color:var(--blue)}
.ml-auto{margin-left:auto}
.tb-btn{padding:6px 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:700;color:var(--text2);text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.tb-btn.primary:hover{background:var(--blue2)}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}
.tbl-scroll{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:860px}
thead th{background:#f7f8fa;padding:9px 14px;text-align:left;font-size:11px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1.5px solid var(--border);white-space:nowrap}
td{padding:10px 14px;border-bottom:1px solid var(--border-light);vertical-align:top;font-size:13px}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--bg)}
.geo-badge{display:inline-flex;padding:2px 8px;border-radius:4px;background:var(--blue-bg);color:var(--blue);font-weight:800;font-size:12px;letter-spacing:.5px}
.status-badge{display:inline-flex;padding:2px 8px;border-radius:4px;font-weight:800;font-size:12px}
.status-active{background:var(--green-bg);color:var(--green)}
.status-banned{background:var(--red-bg);color:var(--red)}
.status-select{height:28px;padding:3px 8px;border:1.5px solid var(--border);border-radius:5px;background:var(--surface);font:700 12px inherit;color:var(--text2);outline:none}
.status-select:focus{border-color:var(--blue)}
.url-cell{max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:'SF Mono',Consolas,monospace;font-size:12px}
.url-cell a{color:var(--blue);text-decoration:none}
.url-cell a:hover{text-decoration:underline}
.name-cell{font-weight:700}
.text-cell{white-space:pre-wrap;max-width:620px;line-height:1.45}
.dim{color:var(--text3);font-size:12px}
.actions{display:flex;gap:5px;justify-content:flex-end}
.btn-sm{padding:3px 10px;border:1.5px solid var(--border);border-radius:5px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;background:var(--surface);color:var(--text2);white-space:nowrap}
.btn-sm:hover{background:var(--bg)}
.btn-sm.edit:hover{border-color:var(--blue);color:var(--blue)}
.btn-sm.danger:hover{border-color:var(--red);color:var(--red);background:var(--red-bg)}
.tbl-msg{text-align:center;padding:56px 20px;color:var(--text3);font-size:14px}
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding:42px 16px;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal-box{background:var(--surface);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:min(620px,98vw);flex-shrink:0}
.modal-hdr{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-hdr h3{font-size:16px;font-weight:800}
.modal-body{padding:20px}
.modal-footer{padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.form-row{margin-bottom:13px}
.form-row label{display:block;font-size:11px;font-weight:800;color:var(--text2);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
.form-row input,.form-row select,.form-row textarea{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none}
.form-row textarea{min-height:120px;resize:vertical;line-height:1.45}
.form-row input:focus,.form-row select:focus,.form-row textarea:focus{border-color:var(--blue)}
.form-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.err-msg{display:none;color:var(--red);font-size:13px;margin-bottom:10px;padding:8px 12px;background:var(--red-bg);border-radius:var(--r2)}
@media(max-width:720px){.form-2col{grid-template-columns:1fr}.ml-auto{margin-left:0}}
</style>
</head>
<body>
<?php include __DIR__ . '/_header.php'; ?>

<div class="main">
  <div class="toolbar">
    <h1>Fanpage Data</h1>
    <div class="tabs">
      <button class="tab active" id="tabUrls" onclick="setTab('urls')">URL</button>
      <button class="tab" id="tabStopWords" onclick="setTab('stop_words')">Stop Words</button>
    </div>
    <div class="filter-group" id="geoFilter">
      <span class="filter-label">GEO</span>
      <select class="flt" id="fltGeo" onchange="applyFilters()"><option value="">All</option></select>
    </div>
    <div class="filter-group" id="statusFilter">
      <span class="filter-label">Status</span>
      <select class="flt" id="fltStatus" onchange="applyFilters()">
        <option value="all">All</option>
        <option value="active">Active</option>
        <option value="banned">Banned</option>
      </select>
    </div>
    <div class="filter-group">
      <span class="filter-label">Search</span>
      <input class="flt" id="fltQ" placeholder="URL, name, text" oninput="applyFiltersDebounced()">
    </div>
    <button class="tb-btn primary ml-auto" onclick="openCreateModal()">+ Add</button>
  </div>

  <div class="table-wrap">
    <div class="tbl-scroll">
      <table>
        <thead id="tblHead"></thead>
        <tbody id="tblBody"><tr><td class="tbl-msg">Loading...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3 id="modalTitle">Add</h3>
      <button class="tb-btn" onclick="closeModal()">×</button>
    </div>
    <div class="modal-body">
      <div class="err-msg" id="formErr"></div>
      <input type="hidden" id="editId">
      <div id="urlForm">
        <div class="form-row">
          <label>FP URL</label>
          <input id="fFpUrl" maxlength="2048" placeholder="https://facebook.com/...">
        </div>
      </div>
      <div id="stopWordsForm" style="display:none">
        <div class="form-row">
          <label>GEO</label>
          <input id="fSwGeo" maxlength="2" placeholder="AR" oninput="this.value=this.value.toUpperCase()">
        </div>
        <div class="form-row">
          <label>Stop Words</label>
          <textarea id="fStopWords" placeholder="One word or phrase per line"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="tb-btn" onclick="closeModal()">Cancel</button>
      <button class="tb-btn primary" id="saveBtn" onclick="saveRecord()">Save</button>
    </div>
  </div>
</div>

<script>
let state = { tab:'urls', geo:'', status:'all', q:'' };
let rows = [];
let timer = null;

function readURL() {
    const p = new URLSearchParams(location.search.slice(1));
    state.tab = p.get('tab') === 'stop_words' ? 'stop_words' : 'urls';
    state.geo = (p.get('geo') || '').toUpperCase();
    state.status = ['all','active','banned'].includes(p.get('status')) ? p.get('status') : 'all';
    state.q = p.get('q') || '';
}

function writeURL(opts={}) {
    const p = new URLSearchParams();
    p.set('tab', state.tab);
    if (state.geo) p.set('geo', state.geo);
    if (state.tab === 'urls' && state.status !== 'all') p.set('status', state.status);
    if (state.q) p.set('q', state.q);
    const url = location.pathname + '?' + p.toString();
    if (url !== location.pathname + location.search) history[opts.replace ? 'replaceState' : 'pushState'](null, '', url);
}

function syncControls() {
    document.getElementById('tabUrls').classList.toggle('active', state.tab === 'urls');
    document.getElementById('tabStopWords').classList.toggle('active', state.tab === 'stop_words');
    document.getElementById('geoFilter').style.display = state.tab === 'urls' ? 'none' : '';
    document.getElementById('statusFilter').style.display = state.tab === 'urls' ? '' : 'none';
    document.getElementById('fltStatus').value = state.status;
    document.getElementById('fltQ').value = state.q;
}

function setTab(tab) {
    state.tab = tab === 'stop_words' ? 'stop_words' : 'urls';
    state.geo = '';
    state.status = 'all';
    writeURL();
    load();
}

function applyFilters() {
    state.geo = document.getElementById('fltGeo').value;
    state.status = document.getElementById('fltStatus').value;
    state.q = document.getElementById('fltQ').value.trim();
    writeURL({replace:true});
    load();
}

function applyFiltersDebounced() {
    clearTimeout(timer);
    timer = setTimeout(applyFilters, 250);
}

async function load() {
    syncControls();
    renderHead();
    document.getElementById('tblBody').innerHTML = `<tr><td colspan="${state.tab === 'urls' ? 4 : 4}" class="tbl-msg">Loading...</td></tr>`;
    const p = new URLSearchParams({action:'list', type:state.tab});
    if (state.geo) p.set('geo', state.geo);
    if (state.q) p.set('q', state.q);
    if (state.tab === 'urls') p.set('status', state.status);
    try {
        const res = await fetch('/api/fanpage_data.php?' + p.toString());
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        rows = json.data || [];
        fillGeo(json.geos || []);
        state.tab === 'urls' ? renderUrls(json.status_counts || {}) : renderStopWords();
    } catch (e) {
        document.getElementById('tblBody').innerHTML = `<tr><td colspan="${state.tab === 'urls' ? 4 : 4}" class="tbl-msg" style="color:var(--red)">Error: ${esc(e.message)}</td></tr>`;
    }
}

function fillGeo(geos) {
    const el = document.getElementById('fltGeo');
    el.innerHTML = '<option value="">All</option>' + geos.map(g => `<option value="${escAttr(g)}" ${g===state.geo?'selected':''}>${esc(g)}</option>`).join('');
}

function renderHead() {
    document.getElementById('tblHead').innerHTML = state.tab === 'urls'
        ? `<tr><th>URL</th><th style="width:120px">Status</th><th style="width:150px">Updated</th><th style="width:130px"></th></tr>`
        : `<tr><th style="width:70px">GEO</th><th>Stop Words</th><th style="width:150px">Updated</th><th style="width:150px"></th></tr>`;
}

function renderUrls(counts) {
    const activeLabel = counts.active ? `Active: ${counts.active}` : 'Active';
    const bannedLabel = counts.banned ? `Banned: ${counts.banned}` : 'Banned';
    document.querySelector('#fltStatus option[value="active"]').textContent = activeLabel;
    document.querySelector('#fltStatus option[value="banned"]').textContent = bannedLabel;
    if (!rows.length) {
        document.getElementById('tblBody').innerHTML = '<tr><td colspan="4" class="tbl-msg">No URLs</td></tr>';
        return;
    }
    document.getElementById('tblBody').innerHTML = rows.map(r => {
        const status = r.status === 'banned' ? 'banned' : 'active';
        const rowJson = escAttr(JSON.stringify(r));
        return `<tr>
            <td class="url-cell"><a href="${escAttr(r.fp_url)}" target="_blank" rel="noopener">${esc(r.fp_url)}</a></td>
            <td>
                <select class="status-select" onchange="setUrlStatus(${+r.id}, this.value)" aria-label="Status URL">
                    <option value="active" ${status === 'active' ? 'selected' : ''}>Active</option>
                    <option value="banned" ${status === 'banned' ? 'selected' : ''}>Banned</option>
                </select>
            </td>
            <td><span class="dim">${fmtDate(r.updated_at)}</span></td>
            <td><div class="actions">
                <button class="btn-sm edit" data-row="${rowJson}" onclick="openUrlModal(JSON.parse(this.dataset.row))">Edit</button>
                <button class="btn-sm danger" data-label="${escAttr(r.fp_url)}" onclick="deleteRecord('urls', ${+r.id}, this.dataset.label)">Delete</button>
            </div></td>
        </tr>`;
    }).join('');
}

function renderStopWords() {
    if (!rows.length) {
        document.getElementById('tblBody').innerHTML = '<tr><td colspan="4" class="tbl-msg">No stop words</td></tr>';
        return;
    }
    document.getElementById('tblBody').innerHTML = rows.map(r => {
        const rowJson = escAttr(JSON.stringify(r));
        return `<tr>
            <td><span class="geo-badge">${esc(r.geo)}</span></td>
            <td class="text-cell">${esc(r.stop_words) || '<span class="dim">—</span>'}</td>
            <td><span class="dim">${fmtDate(r.updated_at)}</span></td>
            <td><div class="actions">
                <button class="btn-sm edit" data-row="${rowJson}" onclick="openStopWordsModal(JSON.parse(this.dataset.row))">Edit</button>
                <button class="btn-sm danger" data-label="${escAttr(r.geo)}" onclick="deleteRecord('stop_words', ${+r.id}, this.dataset.label)">Delete</button>
            </div></td>
        </tr>`;
    }).join('');
}

function openCreateModal() {
    state.tab === 'urls' ? openUrlModal(null) : openStopWordsModal(null);
}

function openUrlModal(row=null) {
    document.getElementById('editModal').classList.add('open');
    document.getElementById('modalTitle').textContent = row ? 'Edit URL' : 'Add URL';
    document.getElementById('urlForm').style.display = '';
    document.getElementById('stopWordsForm').style.display = 'none';
    document.getElementById('formErr').style.display = 'none';
    document.getElementById('editId').value = row?.id || '';
    document.getElementById('fFpUrl').value = row?.fp_url || '';
    setTimeout(() => document.getElementById('fFpUrl').focus(), 0);
}

function openStopWordsModal(row=null) {
    document.getElementById('editModal').classList.add('open');
    document.getElementById('modalTitle').textContent = row ? 'Edit Stop Words' : 'Add Stop Words';
    document.getElementById('urlForm').style.display = 'none';
    document.getElementById('stopWordsForm').style.display = '';
    document.getElementById('formErr').style.display = 'none';
    document.getElementById('editId').value = row?.id || '';
    document.getElementById('fSwGeo').value = row?.geo || state.geo || '';
    document.getElementById('fStopWords').value = row?.stop_words || '';
    setTimeout(() => document.getElementById('fSwGeo').focus(), 0);
}

function closeModal() {
    document.getElementById('editModal').classList.remove('open');
}

async function saveRecord() {
    const id = document.getElementById('editId').value;
    const body = state.tab === 'urls'
        ? {
            type:'urls', action:id ? 'update' : 'create', id:id ? +id : undefined,
            status:'active',
            fp_url:document.getElementById('fFpUrl').value.trim(),
        }
        : {
            type:'stop_words', action:id ? 'update' : 'create', id:id ? +id : undefined,
            geo:document.getElementById('fSwGeo').value.trim().toUpperCase(),
            stop_words:document.getElementById('fStopWords').value.trim(),
        };
    await post(body, () => { closeModal(); load(); });
}

async function setUrlStatus(id, status) {
    await post({type:'urls', action:'set_status', id, status}, load);
}

async function deleteRecord(type, id, label) {
    if (!confirm('Delete "' + label + '"?')) return;
    await post({type, action:'delete', id}, load);
}

async function post(body, onOk) {
    const btn = document.getElementById('saveBtn');
    const err = document.getElementById('formErr');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
    try {
        const res = await fetch('/api/fanpage_data.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        onOk && onOk();
    } catch (e) {
        if (document.getElementById('editModal').classList.contains('open')) {
            err.textContent = e.message;
            err.style.display = '';
        } else {
            alert(e.message);
        }
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    }
}

function fmtDate(v) {
    if (!v) return '—';
    return String(v).replace('T', ' ').replace(/\.\d+.*$/, '').replace(/\+\d\d:?(\d\d)?$/, '');
}

const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const escAttr = s => esc(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;');

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && document.getElementById('editModal').classList.contains('open')) saveRecord();
});

readURL();
writeURL({replace:true});
load();
window.addEventListener('popstate', () => { readURL(); load(); });
</script>
</body>
</html>
