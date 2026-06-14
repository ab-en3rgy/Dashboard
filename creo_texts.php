<?php
// creo_texts.php
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

$cfg       = require __DIR__.'/config/config.php';
$isAdmin   = ($me['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Creo Texts - FB Ads</title>
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
  --purple:#7c3aed;--purple-bg:#ede9fe;
  --shadow:0 1px 2px rgba(0,0,0,.08),0 1px 8px rgba(0,0,0,.05);
  --shadow-md:0 2px 16px rgba(0,0,0,.11);
  --r:8px;--r2:6px;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh;display:flex;flex-direction:column}

.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-btn.primary:hover{background:var(--blue2)}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}

.main{flex:1;padding:20px;display:flex;flex-direction:column;gap:16px}

/* PAGE TABS */
.page-tabs{display:flex;gap:0;border-bottom:2px solid var(--border)}
.page-tab{padding:9px 20px;font-size:14px;font-weight:600;color:var(--text2);cursor:pointer;border-bottom:2.5px solid transparent;margin-bottom:-2px;transition:all .12s;display:flex;align-items:center;gap:7px}
.page-tab:hover{color:var(--text);background:var(--bg)}
.page-tab.active{color:var(--blue);border-bottom-color:var(--blue)}
.page-tab .badge{display:inline-flex;align-items:center;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:700;background:var(--blue-bg);color:var(--blue)}
.page-tab.active-purple{color:var(--purple);border-bottom-color:var(--purple)}
.page-tab.active-purple .badge{background:var(--purple-bg);color:var(--purple)}

/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.filter-group{display:flex;align-items:center;gap:5px}
.filter-label{font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap}
select.flt,input.flt{padding:5px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none;height:32px;transition:border-color .15s}
select.flt:focus,input.flt:focus{border-color:var(--blue)}
input.flt{min-width:140px}
.ml-auto{margin-left:auto}

/* TABLE */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}
.tbl-scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{background:#f7f8fa;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1.5px solid var(--border);white-space:nowrap;position:sticky;top:0;z-index:5}
td{padding:10px 12px;border-bottom:1px solid var(--border-light);vertical-align:middle;font-size:13px}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--bg)}
.geo-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:4px;background:var(--blue-bg);color:var(--blue);font-weight:700;font-size:12px;letter-spacing:.5px}
.approach-badge{padding:2px 8px;border-radius:4px;background:#f0f0f0;color:var(--text2);font-size:12px;font-weight:600}
.text-trunc{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
.desc2-cell{max-width:280px;font-size:12px;color:var(--text2);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.action-btns{display:flex;gap:5px}
.btn-sm{padding:3px 10px;border:1.5px solid var(--border);border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;background:var(--surface);color:var(--text2);transition:all .12s;white-space:nowrap}
.btn-sm:hover{background:var(--bg)}
.btn-sm.edit:hover{border-color:var(--blue);color:var(--blue)}
.btn-sm.del:hover{border-color:var(--red);color:var(--red)}

/* PAGINATION */
.pagination{display:flex;align-items:center;gap:6px;padding:10px 14px;border-top:1px solid var(--border-light)}
.page-btn{padding:4px 9px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);transition:all .12s}
.page-btn:hover:not(:disabled){background:var(--bg);border-color:var(--blue);color:var(--blue)}
.page-btn:disabled{opacity:.4;cursor:default}
.page-btn.cur{background:var(--blue);color:#fff;border-color:var(--blue)}
.pag-info{margin-left:auto;font-size:12px;color:var(--text3)}
.tbl-msg{text-align:center;padding:56px 20px;color:var(--text3);font-size:14px}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal-box{background:var(--surface);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:min(560px,98vw);flex-shrink:0}
.modal-hdr{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-hdr h3{font-size:16px;font-weight:700;margin:0}
.modal-body{padding:20px}
.modal-footer{padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}

/* FORM */
.form-row{margin-bottom:13px}
.form-row label{display:block;font-size:11px;font-weight:700;color:var(--text2);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
.form-row input,.form-row textarea{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none;transition:border-color .15s;resize:vertical}
.form-row input:focus,.form-row textarea:focus{border-color:var(--blue)}
.form-row textarea{min-height:80px}
.form-row .hint{font-size:11px;color:var(--text3);margin-top:3px}
.form-2col{display:grid;grid-template-columns:80px 1fr;gap:12px}
.err-msg{color:var(--red);font-size:13px;margin-bottom:10px;padding:8px 12px;background:var(--red-bg);border-radius:var(--r2)}

/* KEYS */
.section-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);padding:16px 20px}
.section-card h2{font-size:15px;font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:8px}
.key-row{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border-light);font-size:13px}
.key-row:last-child{border-bottom:none}
.key-name{font-weight:600;flex:1}
.key-status{padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700}
.key-status.on{background:var(--green-bg);color:var(--green)}
.key-status.off{background:#f0f0f0;color:var(--text3)}
.key-date{font-size:11px;color:var(--text3);white-space:nowrap}
.key-flash{background:#fffde7;border:1px solid #ffe082;border-radius:var(--r2);padding:10px 14px;margin-bottom:14px;font-size:13px;word-break:break-all}
.key-flash strong{display:block;margin-bottom:4px}
.key-flash code{font-family:'SF Mono',Consolas,monospace;color:var(--blue);font-size:13px}

/* VIEW MODAL */
.view-grid{display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:13px}
.view-grid b{color:var(--text3);font-weight:600;white-space:nowrap;padding-top:1px}
.view-grid .val{white-space:pre-wrap;line-height:1.6;word-break:break-word}

@media(max-width:600px){.form-2col{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php include __DIR__.'/_header.php'; ?>

<div class="main">

  <!-- PAGE TABS -->
  <div class="page-tabs">
    <div class="page-tab active" id="ptab-headlines" onclick="showPane('headlines')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h10"/></svg>
      Headlines
      <span class="badge" id="badge-headlines">—</span>
    </div>
    <div class="page-tab" id="ptab-bodies" onclick="showPane('bodies')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 10h16M4 14h12"/></svg>
      Bodies
      <span class="badge" id="badge-bodies">—</span>
    </div>
    <?php if ($isAdmin): ?>
    <div class="page-tab" id="ptab-import" onclick="showPane('import')" style="margin-left:auto">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Import CSV
    </div>
    <?php endif ?>
  </div>

  <!-- ═══════ HEADLINES PANE ═══════ -->
  <div id="pane-headlines">
    <div class="toolbar">
      <div class="filter-group"><span class="filter-label">Geo</span>
        <select class="flt" id="h-fltGeo" onchange="load('headlines')"><option value="">All</option></select>
      </div>
      <div class="filter-group"><span class="filter-label">Approach</span>
        <select class="flt" id="h-fltApproach" onchange="load('headlines')"><option value="">All</option></select>
      </div>
      <?php if ($isAdmin): ?>
      <button class="tb-btn primary ml-auto" onclick="openModal('headlines')">+ Add</button>
      <?php else: ?><div class="ml-auto"></div><?php endif ?>
    </div>
    <div class="table-wrap">
      <div class="tbl-scroll">
        <table>
          <thead><tr>
            <th style="width:60px">Geo</th>
            <th style="width:130px">Approach</th>
            <th>Headline</th>
            <th style="width:90px">Created</th>
            <?php if ($isAdmin): ?><th style="width:80px"></th><?php endif ?>
          </tr></thead>
          <tbody id="h-tbody"><tr><td colspan="<?= $isAdmin?5:4 ?>" class="tbl-msg">Loading...</td></tr></tbody>
        </table>
      </div>
      <div class="pagination" id="h-pag" style="display:none"></div>
    </div>
  </div>

  <!-- ═══════ BODIES PANE ═══════ -->
  <div id="pane-bodies" style="display:none">
    <div class="toolbar">
      <div class="filter-group"><span class="filter-label">Geo</span>
        <select class="flt" id="b-fltGeo" onchange="load('bodies')"><option value="">All</option></select>
      </div>
      <div class="filter-group"><span class="filter-label">Approach</span>
        <select class="flt" id="b-fltApproach" onchange="load('bodies')"><option value="">All</option></select>
      </div>
      <?php if ($isAdmin): ?>
      <button class="tb-btn primary ml-auto" onclick="openModal('bodies')">+ Add</button>
      <?php else: ?><div class="ml-auto"></div><?php endif ?>
    </div>
    <div class="table-wrap">
      <div class="tbl-scroll">
        <table>
          <thead><tr>
            <th style="width:60px">Geo</th>
            <th style="width:130px">Approach</th>
            <th>Body 1</th>
            <th>Body 2</th>
            <th style="width:90px">Created</th>
            <?php if ($isAdmin): ?><th style="width:80px"></th><?php endif ?>
          </tr></thead>
          <tbody id="b-tbody"><tr><td colspan="<?= $isAdmin?6:5 ?>" class="tbl-msg">Loading...</td></tr></tbody>
        </table>
      </div>
      <div class="pagination" id="b-pag" style="display:none"></div>
    </div>
  </div>
</div>
  <!-- ═══════ IMPORT PANE ═══════ -->
  <?php if ($isAdmin): ?>
  <div id="pane-import" style="display:none">
    <div class="section-card">
      <h2 style="margin-bottom:16px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Import CSV
      </h2>
      <p style="font-size:12px;color:var(--text3);margin-bottom:14px">
        Each row = one record. Format: <code style="background:var(--bg);padding:1px 5px;border-radius:4px">"headline","body1","body2"</code><br>
        The headline is saved to the <b>Headlines</b> table, and body1+body2 go to <b>Bodies</b>.
      </p>
      <div style="display:grid;grid-template-columns:80px 1fr;gap:12px;margin-bottom:14px">
        <div class="form-row" style="margin:0">
          <label>Geo <span style="color:var(--red)">*</span></label>
          <input id="impGeo" maxlength="2" placeholder="AR" oninput="this.value=this.value.toUpperCase()" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;outline:none;transition:border-color .15s">
        </div>
        <div class="form-row" style="margin:0">
          <label>Approach</label>
          <input id="impApproach" maxlength="100" placeholder="pain, gain, story…" list="approachDL" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-family:inherit;outline:none;transition:border-color .15s">
        </div>
      </div>
      <div class="form-row" style="margin-bottom:14px">
        <label>CSV data</label>
        <textarea id="impCsv" rows="12" placeholder='"Exclusive App Access","The secret casinos...","Most platforms are designed..."' style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:12px;font-family:'SF Mono',Consolas,monospace;outline:none;resize:vertical;line-height:1.6;transition:border-color .15s"></textarea>
      </div>
      <div id="impResult" style="display:none;margin-bottom:14px"></div>
      <div style="display:flex;align-items:center;gap:12px">
        <button class="tb-btn primary" id="impBtn" onclick="runImport()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Import
        </button>
        <span id="impProgress" style="font-size:13px;color:var(--text3)"></span>
      </div>
    </div>
  </div>
  <?php endif ?>


<!-- ═══════ RECORD MODAL ═══════ -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3 id="modalTitle">Add</h3>
      <button class="tb-btn" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="err-msg" id="formErr" style="display:none"></div>
      <input type="hidden" id="editId">
      <input type="hidden" id="editTable">
      <div class="form-2col">
        <div class="form-row">
          <label>Geo <span style="color:var(--red)">*</span></label>
          <input id="fGeo" maxlength="2" placeholder="AR" oninput="this.value=this.value.toUpperCase()">
        </div>
        <div class="form-row">
          <label>Approach</label>
          <input id="fApproach" maxlength="100" placeholder="pain, gain…" list="approachDL">
          <datalist id="approachDL"></datalist>
        </div>
      </div>
      <!-- Headlines fields -->
      <div id="fieldsHeadlines">
        <div class="form-row">
          <label>Headline</label>
          <input id="fTitle" maxlength="250" placeholder="Headline text">
        </div>
      </div>
      <!-- Bodies fields -->
      <div id="fieldsBodies" style="display:none">
        <div class="form-row">
          <label>Body 1</label>
          <input id="fDesc1" maxlength="250" placeholder="Short description">
        </div>
        <div class="form-row">
          <label>Body 2</label>
          <textarea id="fDesc2" placeholder="Long description..."></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="tb-btn" onclick="closeModal()">Cancel</button>
      <button class="tb-btn primary" id="saveBtn" onclick="saveRecord()">Save</button>
    </div>
  </div>
</div>

<!-- ═══════ VIEW MODAL ═══════ -->
<div class="modal-overlay" id="viewModal" onclick="if(event.target===this)closeViewModal()">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3 id="viewTitle">Record</h3>
      <button class="tb-btn" onclick="closeViewModal()">✕</button>
    </div>
    <div class="modal-body" id="viewBody"></div>
  </div>
</div>
    <div class="modal-body">
      <div class="err-msg" id="keyErr" style="display:none"></div>
      <div class="form-row">
        <label>Name</label>
        <input id="kName" placeholder="Example: production bot">
      </div>
    </div>
    <div class="modal-footer">
      <button class="tb-btn" onclick="closeKeyModal()">Cancel</button>
      <button class="tb-btn primary" onclick="createKey()">Create</button>
    </div>
  </div>
</div>

<script>
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const H_COLS = IS_ADMIN ? 5 : 4;
const B_COLS = IS_ADMIN ? 6 : 5;

const state = {
    headlines: { geo:'', approach:'', page:1, per:50, total:0 },
    bodies:    { geo:'', approach:'', page:1, per:50, total:0 },
};
let currentPane = 'headlines';
let approachCache = { headlines:[], bodies:[] };

// ── PANE SWITCH ──────────────────────────────────────────
function showPane(p) {
    ['headlines','bodies','import'].forEach(k => {
        const tab  = document.getElementById('ptab-'+k);
        const pane = document.getElementById('pane-'+k);
        if (tab)  tab.classList.toggle('active', k===p);
        if (pane) pane.style.display = k===p ? '' : 'none';
    });
    currentPane = p;
    if (!['headlines','bodies'].includes(p)) return;
    load(p);
}

// ── LOAD ─────────────────────────────────────────────────
async function load(table) {
    const s   = state[table];
    s.geo      = document.getElementById(table[0]+'-fltGeo').value;
    s.approach = document.getElementById(table[0]+'-fltApproach').value;

    const tbody = document.getElementById(table[0]+'-tbody');
    tbody.innerHTML = `<tr><td colspan="${table==='headlines'?H_COLS:B_COLS}" class="tbl-msg">Loading...</td></tr>`;

    const p = new URLSearchParams({ action:'list', table, page:s.page, per:s.per });
    if (s.geo)      p.set('geo',      s.geo);
    if (s.approach) p.set('approach', s.approach);

    const res  = await fetch('/api/creo_texts.php?' + p);
    const json = await res.json();
    if (!json.ok) { tbody.innerHTML=`<tr><td colspan="${table==='headlines'?H_COLS:B_COLS}" class="tbl-msg" style="color:var(--red)">${esc(json.error)}</td></tr>`; return; }

    s.total = json.total;
    s.page  = json.page;

    // Update badge
    document.getElementById('badge-'+table).textContent = json.total;

    // Fill filter dropdowns (preserve selection)
    fillSel(table[0]+'-fltGeo',      json.geos,       s.geo);
    fillSel(table[0]+'-fltApproach', json.approaches, s.approach);
    approachCache[table] = json.approaches;
    updateApproachDL();

    table === 'headlines' ? renderHeadlines(json.data) : renderBodies(json.data);
    renderPag(table, json.total, json.page, json.per);
}

function fillSel(id, items, cur) {
    const el = document.getElementById(id); if (!el) return;
    el.innerHTML = '<option value="">All</option>' +
        items.map(v => `<option value="${esc(v)}" ${v===cur?'selected':''}>${esc(v)}</option>`).join('');
}

function updateApproachDL() {
    const all = [...new Set([...approachCache.headlines, ...approachCache.bodies])].sort();
    document.getElementById('approachDL').innerHTML = all.map(a=>`<option value="${esc(a)}">`).join('');
}

// ── RENDER HEADLINES ──────────────────────────────────────
function renderHeadlines(rows) {
    const tbody = document.getElementById('h-tbody');
    if (!rows.length) { tbody.innerHTML=`<tr><td colspan="${H_COLS}" class="tbl-msg">No records</td></tr>`; return; }
    tbody.innerHTML = rows.map(r => {
        const dt = fmtDate(r.created_at);
        const adm = IS_ADMIN ? `<td><div class="action-btns">
            <button class="btn-sm edit" onclick="event.stopPropagation();openModal('headlines',${JSON.stringify(r).replace(/"/g,'&quot;')})">✎</button>
            <button class="btn-sm del"  onclick="event.stopPropagation();del('headlines',${r.id},'${esc(r.geo)}')">🗑</button>
        </div></td>` : '';
        return `<tr style="cursor:pointer" onclick="viewRow('headlines',${JSON.stringify(r).replace(/"/g,'&quot;')})">
            <td><span class="geo-badge">${esc(r.geo)}</span></td>
            <td><span class="approach-badge">${esc(r.approach)||'—'}</span></td>
            <td><span class="text-trunc" title="${esc(r.title)}">${esc(r.title)||'—'}</span></td>
            <td style="color:var(--text3);font-size:11px;white-space:nowrap">${dt}</td>
            ${adm}
        </tr>`;
    }).join('');
}

// ── RENDER BODIES ─────────────────────────────────────────
function renderBodies(rows) {
    const tbody = document.getElementById('b-tbody');
    if (!rows.length) { tbody.innerHTML=`<tr><td colspan="${B_COLS}" class="tbl-msg">No records</td></tr>`; return; }
    tbody.innerHTML = rows.map(r => {
        const dt = fmtDate(r.created_at);
        const adm = IS_ADMIN ? `<td><div class="action-btns">
            <button class="btn-sm edit" onclick="event.stopPropagation();openModal('bodies',${JSON.stringify(r).replace(/"/g,'&quot;')})">✎</button>
            <button class="btn-sm del"  onclick="event.stopPropagation();del('bodies',${r.id},'${esc(r.geo)}')">🗑</button>
        </div></td>` : '';
        return `<tr style="cursor:pointer" onclick="viewRow('bodies',${JSON.stringify(r).replace(/"/g,'&quot;')})">
            <td><span class="geo-badge">${esc(r.geo)}</span></td>
            <td><span class="approach-badge">${esc(r.approach)||'—'}</span></td>
            <td><span class="text-trunc" title="${esc(r.desc1)}">${esc(r.desc1)||'—'}</span></td>
            <td><div class="desc2-cell">${esc(r.desc2)||'—'}</div></td>
            <td style="color:var(--text3);font-size:11px;white-space:nowrap">${dt}</td>
            ${adm}
        </tr>`;
    }).join('');
}

// ── PAGINATION ────────────────────────────────────────────
function renderPag(table, total, page, per) {
    const el = document.getElementById(table[0]+'-pag');
    if (!total) { el.style.display='none'; return; }
    el.style.display = 'flex';
    const pages = Math.ceil(total/per);
    const start = Math.max(1,page-2), end = Math.min(pages,page+2);
    let btns = '';
    if (start>1) btns+=`<button class="page-btn" onclick="goPage('${table}',1)">1</button>${start>2?'<span style="color:var(--text3)">…</span>':''}`;
    for (let i=start;i<=end;i++) btns+=`<button class="page-btn ${i===page?'cur':''}" onclick="goPage('${table}',${i})">${i}</button>`;
    if (end<pages) btns+=`${end<pages-1?'<span style="color:var(--text3)">…</span>':''}<button class="page-btn" onclick="goPage('${table}',${pages})">${pages}</button>`;
    el.innerHTML=`<button class="page-btn" onclick="goPage('${table}',${page-1})" ${page<=1?'disabled':''}>‹</button>${btns}<button class="page-btn" onclick="goPage('${table}',${page+1})" ${page>=pages?'disabled':''}>›</button><span class="pag-info">${total} records · page ${page}/${pages}</span>`;
}
function goPage(table, p) { state[table].page=p; load(table); }

// ── MODAL ─────────────────────────────────────────────────
function openModal(table, row=null) {
    document.getElementById('editModal').classList.add('open');
    document.getElementById('formErr').style.display='none';
    document.getElementById('editTable').value = table;
    document.getElementById('editId').value    = row?.id || '';
    document.getElementById('modalTitle').textContent = (row?'Edit':'Add') + (table==='headlines'?' headline':' body');
    document.getElementById('fGeo').value      = row?.geo      || '';
    document.getElementById('fApproach').value = row?.approach || '';
    document.getElementById('fieldsHeadlines').style.display = table==='headlines' ? '' : 'none';
    document.getElementById('fieldsBodies').style.display    = table==='bodies'    ? '' : 'none';
    if (table==='headlines') document.getElementById('fTitle').value = row?.title || '';
    else { document.getElementById('fDesc1').value=row?.desc1||''; document.getElementById('fDesc2').value=row?.desc2||''; }
    document.getElementById('fGeo').focus();
}
function closeModal() { document.getElementById('editModal').classList.remove('open'); }

async function saveRecord() {
    const table = document.getElementById('editTable').value;
    const id    = document.getElementById('editId').value;
    const body  = {
        action:   id ? 'update' : 'create',
        table,
        id:       id ? +id : undefined,
        geo:      document.getElementById('fGeo').value.trim().toUpperCase(),
        approach: document.getElementById('fApproach').value.trim(),
    };
    if (table==='headlines') body.title = document.getElementById('fTitle').value.trim();
    else { body.desc1=document.getElementById('fDesc1').value.trim(); body.desc2=document.getElementById('fDesc2').value.trim(); }

    const btn=document.getElementById('saveBtn');
    btn.disabled=true; btn.textContent='Saving...';
    try {
        const res  = await fetch('/api/creo_texts.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
        const json = await res.json();
        if (!json.ok) { document.getElementById('formErr').textContent=json.error; document.getElementById('formErr').style.display=''; return; }
        closeModal(); load(table);
    } catch(e) { document.getElementById('formErr').textContent=e.message; document.getElementById('formErr').style.display=''; }
    finally { btn.disabled=false; btn.textContent='Save'; }
}

async function del(table, id, geo) {
    if (!confirm(`Delete record #${id} (${geo})?`)) return;
    const res  = await fetch('/api/creo_texts.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete',table,id})});
    const json = await res.json();
    if (!json.ok) alert(json.error); else load(table);
}

// ── VIEW ──────────────────────────────────────────────────
function viewRow(table, r) {
    document.getElementById('viewTitle').textContent = `${r.geo}${r.approach?' · '+r.approach:''}`;
    let html = '<div class="view-grid">';
    html += `<b>Geo</b><span><span class="geo-badge">${esc(r.geo)}</span></span>`;
    html += `<b>Approach</b><span class="val">${esc(r.approach)||'—'}</span>`;
    if (table==='headlines') {
        html += `<b>Headline</b><span class="val">${esc(r.title)||'—'}</span>`;
    } else {
        html += `<b>Body 1</b><span class="val">${esc(r.desc1)||'—'}</span>`;
        html += `<b>Body 2</b><span class="val">${esc(r.desc2)||'—'}</span>`;
    }
    html += '</div>';
    document.getElementById('viewBody').innerHTML = html;
    document.getElementById('viewModal').classList.add('open');
}
function closeViewModal() { document.getElementById('viewModal').classList.remove('open'); }

// ── UTILS ─────────────────────────────────────────────────
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmtDate = s => s ? new Date(s).toLocaleDateString('en-GB',{day:'2-digit',month:'2-digit',year:'2-digit'}) : '—';

document.addEventListener('keydown', e => {
    if (e.key==='Escape') { closeModal(); closeViewModal(); closeKeyModal(); }
    if ((e.ctrlKey||e.metaKey)&&e.key==='Enter' && document.getElementById('editModal').classList.contains('open')) saveRecord();
});


// ── IMPORT CSV ────────────────────────────────────────────
function parseCSV(text) {
    const rows = [];
    const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    for (const line of lines) {
        // Simple RFC-4180 parser for quoted fields
        const fields = [];
        let cur = '', inQ = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') {
                if (inQ && line[i+1] === '"') { cur += '"'; i++; }
                else inQ = !inQ;
            } else if (ch === ',' && !inQ) {
                fields.push(cur); cur = '';
            } else {
                cur += ch;
            }
        }
        fields.push(cur);
        if (fields.length >= 3) rows.push(fields);
    }
    return rows;
}

async function runImport() {
    const geo      = document.getElementById('impGeo').value.trim().toUpperCase();
    const approach = document.getElementById('impApproach').value.trim();
    const csv      = document.getElementById('impCsv').value.trim();

    const resEl  = document.getElementById('impResult');
    const progEl = document.getElementById('impProgress');
    const btn    = document.getElementById('impBtn');

    resEl.style.display = 'none';
    if (!geo || !/^[A-Z]{2}$/.test(geo)) { showImpErr('Enter a valid geo code (2 letters)'); return; }
    if (!csv) { showImpErr('Paste CSV data'); return; }

    const rows = parseCSV(csv);
    if (!rows.length) { showImpErr('Failed to parse CSV - check the format'); return; }

    btn.disabled = true;
    let ok = 0, fail = 0, errors = [];

    for (let i = 0; i < rows.length; i++) {
        progEl.textContent = `Processing ${i+1} / ${rows.length}…`;
        const [title, desc1, desc2] = rows[i].map(s => s.trim());

        // Insert headline
        const r1 = await fetch('/api/creo_texts.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action:'create', table:'headlines', geo, approach, title })
        }).then(r => r.json()).catch(() => ({ok:false,error:'network'}));

        // Insert body
        const r2 = await fetch('/api/creo_texts.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action:'create', table:'bodies', geo, approach, desc1, desc2 })
        }).then(r => r.json()).catch(() => ({ok:false,error:'network'}));

        if (r1.ok && r2.ok) {
            ok++;
        } else {
            fail++;
            errors.push(`Row ${i+1}: ${!r1.ok ? 'headline - '+r1.error : ''} ${!r2.ok ? 'body - '+r2.error : ''}`.trim());
        }
    }

    btn.disabled = false;
    progEl.textContent = '';

    resEl.style.display = '';
    if (fail === 0) {
        resEl.innerHTML = `<div style="padding:10px 14px;background:var(--green-bg);border:1px solid #b7dfbe;border-radius:var(--r2);color:var(--green);font-weight:600">✓ Imported ${ok} records (${ok} headlines + ${ok} bodies)</div>`;
        load('headlines'); load('bodies');
    } else {
        resEl.innerHTML = `<div style="padding:10px 14px;background:var(--red-bg);border:1px solid #f5c0c0;border-radius:var(--r2)">
            <div style="font-weight:700;color:var(--red);margin-bottom:6px">✓ ${ok} successful, ✗ ${fail} errors</div>
            <div style="font-size:12px;color:var(--text2)">${errors.map(e=>esc(e)).join('<br>')}</div>
        </div>`;
        if (ok > 0) { load('headlines'); load('bodies'); }
    }
}

function showImpErr(msg) {
    const el = document.getElementById('impResult');
    el.style.display = '';
    el.innerHTML = `<div style="padding:10px 14px;background:var(--red-bg);border:1px solid #f5c0c0;border-radius:var(--r2);color:var(--red);font-weight:600">${esc(msg)}</div>`;
}

// ── INIT ──────────────────────────────────────────────────
load('headlines');
load('bodies');
</script>
</body>
</html>
