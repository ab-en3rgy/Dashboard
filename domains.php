<?php
// domains.php - Domains and FP
// @version 1.0.2
require __DIR__.'/lib/DB.php';
require __DIR__.'/lib/Auth.php';

$db   = DB::getInstance();
$auth = new Auth($db);

$token = $_COOKIE['fb_ads_token'] ?? '';
if (!$token) { header('Location: /login.php'); exit; }
$me = $auth->check($token);
if (!$me) {
    setcookie('fb_ads_token', '', ['expires' => time()-3600, 'path' => '/']);
    header('Location: /login.php'); exit;
}

$isAdmin = ($me['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Domains & FP - FB Ads</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--surface:#fff;
  --border:#dddfe2;--border2:#ccd0d5;--border-light:#e4e6eb;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;
  --blue:#1877f2;--blue2:#166fe5;--blue-bg:#e7f0fd;
  --green:#31a24c;--green-bg:#e6f4ea;
  --red:#fa3e3e;--red-bg:#fce8e8;
  --shadow:0 1px 2px rgba(0,0,0,.08),0 1px 8px rgba(0,0,0,.05);
  --r:8px;--r2:6px;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh;display:flex;flex-direction:column}

/* TOPBAR */
.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-btn.primary:hover{background:var(--blue2)}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}

/* MAIN */
.main{flex:1;padding:20px;display:flex;flex-direction:column;gap:16px}

/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.toolbar h1{font-size:18px;font-weight:800;margin-right:4px}
.status-tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r2);padding:3px}
.status-tab{padding:4px 10px;border:none;border-radius:4px;background:transparent;cursor:pointer;font-size:12px;font-family:inherit;font-weight:700;color:var(--text2)}
.status-tab:hover{background:var(--bg)}
.status-tab.active{background:var(--blue);color:#fff}
.filter-group{display:flex;align-items:center;gap:5px}
.filter-label{font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap}
select.flt,input.flt{padding:5px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none;height:32px;transition:border-color .15s}
select.flt:focus,input.flt:focus{border-color:var(--blue)}
.ml-auto{margin-left:auto}

/* TABLE */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}
.tbl-scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:700px}
thead th{background:#f7f8fa;padding:9px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1.5px solid var(--border);white-space:nowrap}
td{padding:10px 14px;border-bottom:1px solid var(--border-light);vertical-align:middle;font-size:13px}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--bg)}

/* BM GROUP HEADER */
.bm-group-row td{background:#f0f4ff;font-weight:700;font-size:12px;color:var(--blue);padding:7px 14px;border-bottom:1px solid #d0dcf8;letter-spacing:.3px}

/* BADGES */
.bm-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:4px;background:#e8eaf6;color:#3949ab;font-weight:800;font-size:12px;letter-spacing:.5px}
.geo-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:4px;background:var(--blue-bg);color:var(--blue);font-weight:700;font-size:12px;letter-spacing:.5px}
.geo-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.geo-tag{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;background:#eef3ff;color:#2f5bd3;font-weight:700;font-size:11px;line-height:1}
.geo-note{font-size:11px;color:var(--text3);margin-top:4px}
.status-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:4px;font-weight:700;font-size:12px}
.status-active{background:var(--green-bg);color:var(--green)}
.status-banned{background:var(--red-bg);color:var(--red)}
.domain-cell{font-family:'SF Mono',Consolas,monospace;font-size:12px;color:var(--text)}
.url-cell{max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}
.url-cell a{color:var(--blue);text-decoration:none}
.url-cell a:hover{text-decoration:underline}
.fp-name{font-weight:600}
.dim{color:var(--text3);font-size:12px}

.action-btns{display:flex;gap:5px}
.btn-sm{padding:3px 10px;border:1.5px solid var(--border);border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;background:var(--surface);color:var(--text2);transition:all .12s;white-space:nowrap}
.btn-sm:hover{background:var(--bg)}
.btn-sm.edit:hover{border-color:var(--blue);color:var(--blue)}
.btn-sm.danger:hover{border-color:var(--red);color:var(--red);background:var(--red-bg)}

.tbl-msg{text-align:center;padding:56px 20px;color:var(--text3);font-size:14px}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal-box{background:var(--surface);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:min(520px,98vw);flex-shrink:0}
.modal-hdr{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-hdr h3{font-size:16px;font-weight:700;margin:0}
.modal-body{padding:20px}
.modal-footer{padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}

/* FORM */
.form-row{margin-bottom:13px}
.form-row label{display:block;font-size:11px;font-weight:700;color:var(--text2);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
.form-row input{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none;transition:border-color .15s}
.form-row input:focus{border-color:var(--blue)}
.form-row .hint{font-size:11px;color:var(--text3);margin-top:3px}
.form-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.err-msg{color:var(--red);font-size:13px;margin-bottom:10px;padding:8px 12px;background:var(--red-bg);border-radius:var(--r2)}

@media(max-width:600px){.form-2col{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php include __DIR__.'/_header.php'; ?>

<div class="main">

  <div class="toolbar">
    <h1>Domains & FP</h1>

    <div class="status-tabs">
      <button class="status-tab active" id="tabActive" onclick="setStatusTab('active')">Active</button>
      <button class="status-tab" id="tabBanned" onclick="setStatusTab('banned')">Banned</button>
    </div>

    <div class="filter-group">
      <span class="filter-label">BM</span>
      <select class="flt" id="fltBm" onchange="applyFilters()">
        <option value="">All</option>
      </select>
    </div>

    <div class="filter-group">
      <span class="filter-label">Geo</span>
      <select class="flt" id="fltGeo" onchange="applyFilters()">
        <option value="">All</option>
      </select>
    </div>

    <?php if ($isAdmin): ?>
    <button class="tb-btn" id="analyzeGeoBtn" onclick="analyzeGeoUsage()">Analyze Geo</button>
    <button class="tb-btn primary ml-auto" onclick="openModal()">+ Add</button>
    <?php else: ?>
    <div class="ml-auto"></div>
    <?php endif ?>
  </div>

  <div class="table-wrap">
    <div class="tbl-scroll">
      <table id="mainTbl">
        <thead>
          <tr>
            <th style="width:70px">BM</th>
            <th style="width:90px">Geo / Used</th>
            <th style="width:90px">Status</th>
            <th>Domain</th>
            <th>FP Name</th>
            <th style="width:220px">Delivery IDs</th>
            <?php if ($isAdmin): ?><th style="width:160px">Owner</th><th style="width:280px"></th><?php endif ?>
          </tr>
        </thead>
        <tbody id="tblBody">
          <tr><td colspan="<?= $isAdmin ? 8 : 6 ?>" class="tbl-msg">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- MODAL -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3 id="modalTitle">Add Record</h3>
      <button class="tb-btn" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="err-msg" id="formErr" style="display:none"></div>
      <input type="hidden" id="editId">
      <div class="form-2col">
        <div class="form-row">
          <label>BM <span style="color:var(--red)">*</span></label>
          <select id="fBm" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none"></select>
          <div class="hint">Select the Business Manager for this config</div>
        </div>
        <div class="form-row">
          <label>Geo <span style="color:var(--red)">*</span></label>
          <input id="fGeo" maxlength="2" placeholder="AR" oninput="this.value=this.value.toUpperCase()">
          <div class="hint">Used as a note; campaign launches will mark geo usage automatically.</div>
        </div>
      </div>
      <div class="form-row">
        <label>Domain</label>
        <input id="fDomain" maxlength="255" placeholder="example.com">
      </div>
      <div class="form-row">
        <label>FP Name</label>
        <input id="fFpName" maxlength="255" placeholder="Fan page name">
      </div>
      <div class="form-2col">
        <div class="form-row">
          <label>Page ID</label>
          <input id="fPageId" maxlength="255" placeholder="Facebook Page ID">
        </div>
        <div class="form-row">
          <label>Pixel ID</label>
          <input id="fPixelId" maxlength="255" placeholder="Facebook Pixel ID">
        </div>
      </div>
      <div class="form-row">
        <label>Status</label>
        <select id="fStatus" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none">
          <option value="active">Active</option>
          <option value="banned">Banned</option>
        </select>
      </div>
      <?php if ($isAdmin): ?>
      <div class="form-row">
        <label>Owner</label>
        <select id="fUser" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none"></select>
      </div>
      <?php endif ?>
      </div>
    <div class="modal-footer">
      <button class="tb-btn" onclick="closeModal()">Cancel</button>
      <button class="tb-btn primary" id="saveBtn" onclick="saveRecord()">Save</button>
    </div>
  </div>
</div>

<script>
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const CURRENT_USER_ID = <?= (int)$me['id'] ?>;
const COLS = IS_ADMIN ? 8 : 6;

let state = { bm:'', geo:'', status:'active', total:0 };
let users = [];
let bms = [];
function readURL() {
    const p = new URLSearchParams(location.search.slice(1));
    state.bm = p.get('bm') || '';
    state.geo = (p.get('geo') || '').toUpperCase();
    state.status = p.get('status') === 'banned' ? 'banned' : 'active';
}

function writeURL(opts={}) {
    const p = new URLSearchParams();
    p.set('status', state.status);
    if (state.bm) p.set('bm', state.bm);
    if (state.geo) p.set('geo', state.geo);
    const url = location.pathname + '?' + p.toString();
    if (url !== location.pathname + location.search) {
        history[opts.replace ? 'replaceState' : 'pushState'](null, '', url);
    }
}
let _groupByBm = true; // group by BM when no specific BM filter is selected

// ── FILTERS ───────────────────────────────────────────────
function applyFilters() {
    state.bm  = document.getElementById('fltBm').value;
    state.geo = document.getElementById('fltGeo').value;
    writeURL();
    load();
}

function setStatusTab(status) {
    state.status = status === 'banned' ? 'banned' : 'active';
    writeURL();
    load();
}

// ── LOAD ──────────────────────────────────────────────────
async function load() {
    document.getElementById('tblBody').innerHTML =
        `<tr><td colspan="${COLS}" class="tbl-msg">Loading...</td></tr>`;

    const p = new URLSearchParams({ action:'list', status:state.status });
    if (state.bm)  p.set('bm',  state.bm);
    if (state.geo) p.set('geo', state.geo);

    const res  = await fetch('/api/domains.php?' + p);
    const json = await res.json();
    if (!json.ok) {
        document.getElementById('tblBody').innerHTML =
            `<tr><td colspan="${COLS}" class="tbl-msg" style="color:var(--red)">${esc(json.error)}</td></tr>`;
        return;
    }

    state.total = json.total;
    users = json.users || [];
    bms = json.bms || [];
    renderStatusTabs(json.status_counts || {});

    // Fill dropdowns
    fillSel('fltBm',  json.bms,  state.bm);
    fillSel('fltGeo', json.geos, state.geo);

    // Fill BM select in modal
    fillBms();
    fillUsers();

    renderTable(json.data);
}

function fillUsers(selectedId=null) {
    if (!IS_ADMIN) return;
    const el = document.getElementById('fUser');
    if (!el) return;
    const fallback = selectedId || CURRENT_USER_ID;
    el.innerHTML = users.map(u => {
        const label = (u.name || u.username || u.id) + ' @' + (u.username || u.id);
        return `<option value="${esc(u.id)}" ${String(u.id)===String(fallback)?'selected':''}>${esc(label)}</option>`;
    }).join('');
}

function fillBms(selectedId=null) {
    const el = document.getElementById('fBm');
    if (!el) return;
    const fallback = selectedId || state.bm || '';
    el.innerHTML = '<option value="">Select a BM</option>' + bms.map(b => {
        const bmId = String(b.bm_id ?? b.id ?? '');
        const bmName = String(b.bm_name ?? b.name ?? bmId);
        const label = bmName + (bmId ? ' (' + bmId + ')' : '');
        return `<option value="${esc(bmId)}" ${bmId===String(fallback)?'selected':''}>${esc(label)}</option>`;
    }).join('');
}

function renderStatusTabs(counts) {
    const active = document.getElementById('tabActive');
    const banned = document.getElementById('tabBanned');
    if (!active || !banned) return;
    active.classList.toggle('active', state.status === 'active');
    banned.classList.toggle('active', state.status === 'banned');
    active.textContent = 'Active (' + (counts.active || 0) + ')';
    banned.textContent = 'Banned (' + (counts.banned || 0) + ')';
}

function fillSel(id, items, cur) {
    const el = document.getElementById(id); if (!el) return;
    el.innerHTML = '<option value="">All</option>' +
        items.map(v => {
            if (v && typeof v === 'object') {
                const val = String(v.bm_id ?? v.id ?? v.value ?? '');
                const label = String(v.bm_name ?? v.name ?? val);
                return `<option value="${esc(val)}" ${val===String(cur)?'selected':''}>${esc(label)}${val ? ' (' + esc(val) + ')' : ''}</option>`;
            }
            return `<option value="${esc(v)}" ${v===cur?'selected':''}>${esc(v)}</option>`;
        }).join('');
}

// ── RENDER TABLE ──────────────────────────────────────────
function renderTable(rows) {
    const tbody = document.getElementById('tblBody');
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="${COLS}" class="tbl-msg">No records</td></tr>`;
        return;
    }

    // Group by BM when no specific BM filter
    const grouped = !state.bm;
    let html = '';
    let lastBm = null;

    for (const r of rows) {
        const bmKey = r.bm_id || r.bm;
        if (grouped && bmKey !== lastBm) {
            lastBm = bmKey;
            html += `<tr class="bm-group-row"><td colspan="${COLS}">BM: ${esc(r.bm_name || r.bm_id || r.bm)}</td></tr>`;
        }
        const adm = IS_ADMIN ? `<td><div class="action-btns">
            <button class="btn-sm edit" onclick="event.stopPropagation();openModal(${JSON.stringify(r).replace(/"/g,'&quot;')})">✎</button>
            <button class="btn-sm js-duplicate-domain" data-id="${esc(r.id)}">Duplicate</button>
            <button class="btn-sm js-toggle-domain" data-id="${esc(r.id)}" data-status="${r.status === 'banned' ? 'active' : 'banned'}">${r.status === 'banned' ? 'Activate' : 'Ban'}</button>
            <button class="btn-sm danger js-delete-domain" data-id="${esc(r.id)}" data-label="${esc(r.domain || r.fp_name || r.id)}">Delete</button>
        </div></td>` : '';
        const status = r.status === 'banned' ? 'banned' : 'active';
        const fpGroupText = Array.isArray(r.fp_geo_group) && r.fp_geo_group.length ? ` <span class="dim">(${esc(r.fp_geo_group.join(', '))})</span>` : '';
        const usedGeos = Array.isArray(r.used_geos) ? r.used_geos.filter(g => /^[A-Z]{2}$/.test(String(g || '').trim())) : [];
        const usedGeoHtml = usedGeos.length
            ? `<div class="geo-tags">${usedGeos.map(g => `<span class="geo-tag">${esc(g)}</span>`).join('')}</div>`
            : `<div class="geo-note">No usage yet</div>`;
        html += `<tr>
            <td><span class="bm-badge">${esc(r.bm_name || r.bm_id || r.bm)}</span><div class="dim">${esc(r.bm_id || r.bm || '')}</div></td>
            <td><div><span class="geo-badge">${esc(r.geo || '—')}</span></div>${usedGeoHtml}</td>
            <td><span class="status-badge status-${status}">${status === 'banned' ? 'Banned' : 'Active'}</span></td>
            <td class="domain-cell">${esc(r.domain)||'—'}</td>
            <td class="fp-name">${esc(r.fp_name)||'—'}${fpGroupText}</td>
            <td><div style="display:flex;flex-direction:column;gap:2px"><span class="dim">Page ${esc(r.page_id || '—')}</span><span class="dim">Pixel ${esc(r.pixel_id || '—')}</span></div></td>
            ${IS_ADMIN ? `<td><span class="dim">${esc(r.owner_name || r.owner_username || '—')}</span></td>` : ''}

            ${adm}
        </tr>`;
    }
    tbody.innerHTML = html;
}

// ── PAGINATION ────────────────────────────────────────────

// ── MODAL ─────────────────────────────────────────────────
function openModal(row=null) {
    document.getElementById('editModal').classList.add('open');
    document.getElementById('formErr').style.display='none';
    document.getElementById('modalTitle').textContent = row ? 'Edit Record' : 'Add Record';
    document.getElementById('editId').value   = row?.id     || '';
    fillBms(row?.bm_id || row?.bm || state.bm || '');
    document.getElementById('fGeo').value     = row?.geo    || state.geo || '';
    document.getElementById('fDomain').value  = row?.domain  || '';
    document.getElementById('fFpName').value  = row?.fp_name || '';
    document.getElementById('fPageId').value   = row?.page_id || '';
    document.getElementById('fPixelId').value  = row?.pixel_id || '';
    document.getElementById('fStatus').value  = row?.status === 'banned' ? 'banned' : state.status;
    fillUsers(row?.user_id || CURRENT_USER_ID);
    document.getElementById('fBm').focus();
}
function closeModal() { document.getElementById('editModal').classList.remove('open'); }

async function saveRecord() {
    const id  = document.getElementById('editId').value;
    const body = {
        action:   id ? 'update' : 'create',
        id:       id ? +id : undefined,
        bm:       document.getElementById('fBm').value.trim(),
        geo:      document.getElementById('fGeo').value.trim().toUpperCase(),
        domain:   document.getElementById('fDomain').value.trim(),
        fp_name:  document.getElementById('fFpName').value.trim(),
        page_id:  document.getElementById('fPageId').value.trim(),
        pixel_id: document.getElementById('fPixelId').value.trim(),
        status:   document.getElementById('fStatus').value === 'banned' ? 'banned' : 'active',
    };
    if (IS_ADMIN) {
        body.user_id = +(document.getElementById('fUser').value || CURRENT_USER_ID);
    }
    const btn = document.getElementById('saveBtn');
    btn.disabled=true; btn.textContent='Saving...';
    try {
        const res  = await fetch('/api/domains.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
        const json = await res.json();
        if (!json.ok) {
            const el=document.getElementById('formErr');
            el.textContent=json.error; el.style.display='';
            return;
        }
        closeModal(); load();
    } catch(e) {
        const el=document.getElementById('formErr');
        el.textContent=e.message; el.style.display='';
    } finally {
        btn.disabled=false; btn.textContent='Save';
    }
}

async function analyzeGeoUsage() {
    if (!IS_ADMIN) return;
    const btn = document.getElementById('analyzeGeoBtn');
    if (!btn) return;

    const prevText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Analyzing...';
    try {
        const res = await fetch('/api/domains.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'analyze_geo_usage' })
        });
        const json = await res.json();
        if (!json.ok) {
            alert(json.error || 'Geo analysis error');
            return;
        }
        const message = [
            'Geo analysis complete.',
            'Checked logs: ' + (json.checked_logs || 0),
            'Matched logs: ' + (json.matched_logs || 0),
            'Inserted links: ' + (json.inserted || 0),
            'Updated FP: ' + (json.updated_fps || 0)
        ].join('\n');
        alert(message);
        load();
    } catch (e) {
        alert(e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = prevText;
    }
}

async function setRecordStatus(id, status) {
    if (!IS_ADMIN) return;
    try {
        const res = await fetch('/api/domains.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({ action:'set_status', id:+id, status })
        });
        const json = await res.json();
        if (!json.ok) {
            alert(json.error || 'Status change error');
            return;
        }
        load();
    } catch(e) {
        alert(e.message);
    }
}

async function duplicateRecord(id) {
    if (!IS_ADMIN) return;
    try {
        const res = await fetch('/api/domains.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({ action:'duplicate', id:+id })
        });
        const json = await res.json();
        if (!json.ok) {
            alert(json.error || 'Duplicate error');
            return;
        }
        load();
    } catch(e) {
        alert(e.message);
    }
}

async function deleteRecord(id, label) {
    if (!IS_ADMIN) return;
    if (!confirm('Delete record "' + label + '"?')) return;

    try {
        const res  = await fetch('/api/domains.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({ action:'delete', id:+id })
        });
        const json = await res.json();
        if (!json.ok) {
            alert(json.error || 'Delete error');
            return;
        }
        load();
    } catch(e) {
        alert(e.message);
    }
}

// ── UTILS ─────────────────────────────────────────────────
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

document.addEventListener('keydown', e => {
    if (e.key==='Escape') closeModal();
    if ((e.ctrlKey||e.metaKey)&&e.key==='Enter' && document.getElementById('editModal').classList.contains('open')) saveRecord();
});

document.addEventListener('click', e => {
    const btn = e.target.closest('.js-delete-domain');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    deleteRecord(btn.dataset.id, btn.dataset.label);
});

document.addEventListener('click', e => {
    const btn = e.target.closest('.js-toggle-domain');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    setRecordStatus(btn.dataset.id, btn.dataset.status);
});

// ── INIT ──────────────────────────────────────────────────
document.addEventListener('click', e => {
    const btn = e.target.closest('.js-duplicate-domain');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    duplicateRecord(btn.dataset.id);
});

readURL();
writeURL({replace:true});
load();
window.addEventListener('popstate', () => {
    readURL();
    load();
});
</script>
</body>
</html>
