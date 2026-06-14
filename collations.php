<?php
// collations.php
// @version 1.0.5
require __DIR__.'/lib/DB.php';
require __DIR__.'/lib/Auth.php';

$db   = DB::getInstance();
$auth = new Auth($db);

$token = $_COOKIE['fb_ads_token'] ?? '';
if (!$token) { header('Location: /login.php'); exit; }
$me = $auth->check($token);
if (!$me) {
    setcookie('fb_ads_token', '', ['expires' => time()-3600, 'path' => '/']);
    header('Location: /login.php');
    exit;
}
$isAdmin = ($me['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Collations - Ads Dashboard</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--surface:#fff;
  --border:#dddfe2;--border2:#ccd0d5;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;
  --blue:#1877f2;--blue2:#166fe5;
  --green:#31a24c;--red:#fa3e3e;
  --r:8px;--r2:6px;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh}
.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2)}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);transition:all .15s;text-decoration:none;display:inline-block}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-user{display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
.content{padding:24px;max-width:1100px;margin:0 auto}
.btn{padding:8px 20px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn.green{background:#28a745}
.upload-box{background:var(--surface);border:2px dashed var(--border2);border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:border-color .2s}
.upload-box:hover{border-color:var(--blue)}
.upload-box input[type=file]{display:none}
.upload-box label{cursor:pointer;font-size:14px;color:var(--text2)}
.upload-box label b{color:var(--blue)}
.result{margin-top:16px;background:var(--surface);border-radius:10px;padding:16px;font-family:monospace;font-size:13px;white-space:pre-wrap;max-height:300px;overflow:auto;border:1px solid var(--border)}
.file-name{margin-top:8px;font-size:13px;color:var(--text)}
.ftab{padding:5px 14px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);font-size:13px;font-weight:600;cursor:pointer;color:var(--text2);font-family:inherit}
.ftab.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.month-select{height:31px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);font-size:13px;font-weight:600;color:var(--text2);font-family:inherit;padding:0 10px;outline:none}
.month-select:focus{border-color:var(--blue)}
.rtbl{width:100%;border-collapse:collapse;font-size:13px}
.rtbl th{padding:10px 14px;text-align:left;font-size:12px;font-weight:600;color:var(--text3);border-bottom:2px solid var(--border);cursor:pointer;white-space:nowrap;user-select:none;background:var(--surface);position:sticky;top:0}
.rtbl th:hover{color:var(--blue)}
.rtbl th.r,.rtbl td.r{text-align:right}
.rtbl td{padding:9px 14px;border-bottom:1px solid var(--bg)}
.rtbl tr:hover td{background:#f9f9f9}
.rtbl .total-row td{font-weight:700;background:var(--bg);border-top:2px solid var(--border)}
.report-context{display:none;align-items:center;gap:10px;margin-bottom:12px;padding:10px 12px;background:var(--surface);border:1px solid var(--border);border-radius:10px}
.report-context-title{font-size:13px;font-weight:700;color:var(--text)}
.report-context-x{border:none;background:transparent;color:var(--text3);font-size:18px;line-height:1;cursor:pointer;padding:0 4px}
.report-context-x:hover{color:var(--red)}
.report-breakdowns{margin-left:auto;display:flex;gap:4px}
.report-name-cell{display:flex;align-items:center;gap:7px;min-width:180px}
.report-name-main{min-width:0;overflow:hidden;text-overflow:ellipsis}
.report-row-links{display:inline-flex;align-items:center;gap:3px;flex-shrink:0}
.report-row-link{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 5px;border:1px solid var(--border);border-radius:5px;background:#f7f8fa;color:var(--text2);font-size:10px;font-weight:800;line-height:1;text-decoration:none}
.report-row-link:hover{border-color:var(--blue);color:var(--blue);background:#eef5ff}
</style>
</head>
<body>
<?php include __DIR__.'/_header.php'; ?>

<div class="content" style="margin-top:8px">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap">
    <h2 style="font-size:18px">Reports</h2>
    <select id="reportMonthSelect" class="month-select" onchange="setReportMonth(this.value)"></select>
    <span id="reportMonth" style="font-size:12px;color:var(--text3)"></span>
    <?php if ($isAdmin): ?>
    <button class="ftab" onclick="openNicknames()" style="margin-left:auto">Buyer nicknames</button>
    <?php endif ?>
  </div>
  <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
    <select id="reportGroupSelect" class="month-select" onchange="setReportGroup(this.value)">
      <option value="geo">Geo</option>
      <option value="buyer">Buyer</option>
      <option value="brand">Brand</option>
    </select>
    <select id="reportGeoSelect" class="month-select" onchange="setReportFilter('country', this.value)">
      <option value="">All geos</option>
    </select>
    <select id="reportBuyerSelect" class="month-select" onchange="setReportFilter('tracking_code', this.value)">
      <option value="">All buyers</option>
    </select>
    <select id="reportBrandSelect" class="month-select" onchange="setReportFilter('brand_name', this.value)">
      <option value="">All brands</option>
    </select>
    <button class="ftab" onclick="clearAllReportFilters()">Clear filters</button>
  </div>
  <div style="background:var(--surface);border-radius:10px;overflow:auto;border:1px solid var(--border)">
    <div id="reportTbl"></div>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- NICKNAMES MODAL -->
<div id="nicknamesModal" onclick="if(event.target===this)closeNicknames()" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding-top:60px">
  <div style="background:var(--surface);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.2);width:min(600px,95vw);max-height:80vh;display:flex;flex-direction:column">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <h3 style="margin:0;font-size:16px;font-weight:700">Buyer nicknames</h3>
      <div style="display:flex;gap:8px">
        <button class="btn" onclick="saveNicknames()">Save</button>
        <button class="ftab" onclick="closeNicknames()">Close</button>
      </div>
    </div>
    <div style="overflow:auto;flex:1;padding:16px">
      <div style="font-size:12px;color:var(--text3);margin-bottom:12px">Enter nicknames for each tracking code. The report will display "Nickname (code)".</div>
      <div id="nicknamesList"></div>
    </div>
  </div>
</div>
<?php endif ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let selectedFiles = [];
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

// DOM init - delayed until the page loads
document.addEventListener('DOMContentLoaded', async () => {
    const fileInput  = document.getElementById('fileInput');
    const dropZone   = document.getElementById('dropZone');
    const fileList   = document.getElementById('fileList');
    if (dropZone) {
        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor='#1877f2'; });
        dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor=''; });
        dropZone.addEventListener('drop', e => {
            e.preventDefault(); dropZone.style.borderColor='';
            handleFiles([...e.dataTransfer.files], fileList);
        });
    }
    if (fileInput) fileInput.addEventListener('change', () => handleFiles([...fileInput.files], fileList));
    readReportURL();
    await loadReportMonths();
    syncReportControls();
    writeReportURL({replace:true});
    loadReport();
    window.addEventListener('popstate', () => {
        readReportURL();
        syncReportControls();
        loadReport();
    });
});

function handleFiles(files, fileList) {
    selectedFiles = files.filter(f => f.name.match(/\.(xlsx|xls|csv)$/i));
    if (!selectedFiles.length) return;
    if (fileList) fileList.innerHTML = selectedFiles.map(f =>
        `<div class="file-name">📄 ${f.name} <span style="color:#888;font-size:12px">(${(f.size/1024).toFixed(1)} KB)</span></div>`
    ).join('');
    parseAndImport();
}

// Parse file name — two formats:
// 1. collation_Source_DD-MM-YYYY_DD-MM-YYYY
// 2. collation_source_YYYY-MM-DD_YYYY-MM-DD (and variants with extra symbols)
function parseFileName(name) {
    // Format 1: MM-DD-YYYY (05-01-2026 = May 1)
    let m = name.match(/collation[_-]([^_]+)_(\d{2}-\d{2}-\d{4})_(\d{2}-\d{2}-\d{4})/i);
    if (m) {
        const [, source, dateFrom, dateTo] = m;
        const [mm, dd, yyyy] = dateFrom.split('-');
        return { source, dateFrom, dateTo, month: `${yyyy}-${mm}` };
    }
    // Format 2: YYYY-MM-DD
    m = name.match(/collation[_-](.+?)_(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})/i);
    if (m) {
        const [, source, dateFrom, dateTo] = m;
        const [yyyy, mm] = dateFrom.split('-');
        return { source: source.replace(/_+$/, ''), dateFrom, dateTo, month: `${yyyy}-${mm}` };
    }
    return null;
}

// Mapping Excel headers -> our fields (exact + aliases)
const COL_MAP = {
    // trackingCode
    'trackingcode': 'trackingCode', 'tracking code': 'trackingCode',
    'tracking_code': 'trackingCode', 'buyer': 'trackingCode',
    // brandName
    'brandname': 'brandName', 'brand name': 'brandName',
    'brand': 'brandName', 'brand': 'brandName',
    // country
    'country': 'country', '\u0433\u0435\u043e': 'country', 'geo': 'country',
    // firstDepositCount
    'firstdepositcount': 'firstDepositCount', 'first deposit count': 'firstDepositCount',
    'first deposit': 'firstDepositCount', 'deposits': 'firstDepositCount',
    'deps': 'firstDepositCount', 'deps': 'firstDepositCount',
    'total deposits': 'totalDeposits',
    'totaldeposits': 'totalDeposits',
    'qftd': 'firstDepositCount', // CSV format
    // partnerId
    'partnerid': 'partnerId', 'partner id': 'partnerId', 'partner_id': 'partnerId',
    // M1 fields
    'sum_m1deposits': 'sumM1Deposits',
    'sum m1deposits': 'sumM1Deposits',
    'sum_m1_deposits': 'sumM1Deposits',
    'sum m1 deposits': 'sumM1Deposits',
    'summ1deposits': 'sumM1Deposits',
    'm1 deposits': 'sumM1Deposits',
    'sum_m1marketingspend': 'sumM1MarketingSpend',
    'sum m1marketingspend': 'sumM1MarketingSpend',
    'sum_m1_marketing_spend': 'sumM1MarketingSpend',
    'sum m1 marketing spend': 'sumM1MarketingSpend',
    'summ1marketingspend': 'sumM1MarketingSpend',
    'm1 marketing spend': 'sumM1MarketingSpend',
    'total commission': 'totalCommission',
    'totalcommission': 'totalCommission',
    'kpi': 'kpi',
};

function mapHeader(h) {
    return COL_MAP[String(h).toLowerCase().trim().replace(/\s+/g, ' ')] || null;
}

function numCell(v) {
    if (v === null || v === undefined || v === '') return 0;
    if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
    let s = String(v).trim();
    if (!s) return 0;
    s = s.replace(/\s/g, '').replace(/[$€£₽%]/g, '');
    if (s.includes(',') && s.includes('.')) {
        const lastComma = s.lastIndexOf(',');
        const lastDot = s.lastIndexOf('.');
        s = lastComma > lastDot
            ? s.replace(/\./g, '').replace(',', '.')
            : s.replace(/,/g, '');
    }
    else s = s.replace(',', '.');
    const n = parseFloat(s);
    return Number.isFinite(n) ? n : 0;
}

function percentRatioCell(v) {
    const n = numCell(v);
    if (!n) return 0;

    // Excel stores 25% as 0.25, even though the screen shows 25%.
    if (typeof v === 'number' && Math.abs(n) <= 1) return n;

    const s = String(v ?? '').trim();
    if (s.includes('%')) return n / 100;

    // For CSV/manual input, "0.25" is also treated as 25%, and "25" is 25%.
    return Math.abs(n) <= 1 ? n : n / 100;
}

function addParseLog(line) {
    if (!window._parseLog) window._parseLog = [];
    window._parseLog.push(line);
}

function escapeHtml(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function findDataSheet(wb) {
    // First look for the exact sheet name
    const TARGET = 'oas_by_brand_geo_tc';
    if (wb.SheetNames.includes(TARGET)) return { sheet: wb.Sheets[TARGET], name: TARGET };
    // Otherwise — search by substring
    const found = wb.SheetNames.find(n => n.toLowerCase().includes('geo_tc') || n.toLowerCase().includes('brand_geo'));
    if (found) return { sheet: wb.Sheets[found], name: found };
    // Fallback — 3rd sheet or 1st sheet
    const name = wb.SheetNames[2] || wb.SheetNames[0];
    return { sheet: wb.Sheets[name], name };
}

// CSV parsing -> rows array like sheet_to_json
function parseCSV(text) {
    const lines = text.split(/\r?\n/).filter(l => l.trim());
    return lines.map(line => {
        const cells = []; let cur = ''; let inQ = false;
        for (let i = 0; i < line.length; i++) {
            const c = line[i];
            if (c === '"') { inQ = !inQ; }
            else if (c === ',' && !inQ) { cells.push(cur.trim()); cur = ''; }
            else cur += c;
        }
        cells.push(cur.trim());
        return cells;
    });
}

// ── NICKNAMES MODAL ───────────────────────────────────────────
async function openNicknames() {
    if (!IS_ADMIN) return;
    document.getElementById('nicknamesModal').style.display = 'flex';
    document.getElementById('nicknamesList').innerHTML = '<div style="color:var(--text3)">Loading...</div>';
    try {
        const j = await fetchJson('/api/baer_names.php');
        if (!j.ok) throw new Error(j.error||'API error');
        const rows = j.data || [];
        if (!rows.length) {
            document.getElementById('nicknamesList').innerHTML = '<div style="color:var(--text3)">No tracking code in the database</div>';
            return;
        }
        let html = '<table style="width:100%;border-collapse:collapse">';
        html += '<thead><tr><th style="text-align:left;padding:6px 8px;font-size:12px;color:var(--text3);border-bottom:1px solid var(--border)">Tracking Code</th><th style="text-align:left;padding:6px 8px;font-size:12px;color:var(--text3);border-bottom:1px solid var(--border)">Nickname</th></tr></thead><tbody>';
        for (const row of rows) {
            html += `<tr style="border-bottom:1px solid var(--bg)">
                <td style="padding:6px 8px;font-size:13px;color:var(--text2);font-family:monospace">${row.tracking_code}</td>
                <td style="padding:4px 8px"><input type="text" data-code="${row.tracking_code.replace(/"/g,'&quot;')}" value="${(row.nickname||'').replace(/"/g,'&quot;')}" placeholder="Enter nickname..." style="width:100%;padding:5px 8px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-family:inherit;background:var(--surface)"></td>
            </tr>`;
        }
        html += '</tbody></table>';
        document.getElementById('nicknamesList').innerHTML = html;
    } catch(e) {
        document.getElementById('nicknamesList').innerHTML = `<div style="color:red">Error: ${e.message}</div>`;
    }
}

function closeNicknames() {
    document.getElementById('nicknamesModal').style.display = 'none';
}

async function saveNicknames() {
    if (!IS_ADMIN) return;
    const inputs = document.querySelectorAll('#nicknamesList input[data-code]');
    const names = {};
    inputs.forEach(inp => { names[inp.dataset.code] = inp.value.trim(); });
    try {
        const j = await fetchJson('/api/baer_names.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ names }),
        });
        if (!j.ok) throw new Error(j.error||'API error');
        closeNicknames();
        loadReport();
    } catch(e) {
        alert('Save error: ' + e.message);
    }
}

async function parseAndImport() {
    if (!IS_ADMIN) return;
    await parseFiles();
    await importToDB();
}

async function parseFiles() {
    const resultBox = document.getElementById('result');
    resultBox.style.display = 'block';
    resultBox.textContent = 'Parsing...\n';

    window._parsedResults = [];
    window._parseLog = [];
    const allResults = window._parsedResults;

    for (const file of selectedFiles) {
        const meta = parseFileName(file.name);
        if (!meta) {
            const msg = `⚠ ${file.name}: could not parse the file name\n`;
            addParseLog(msg.trim());
            resultBox.textContent += msg; console.warn(msg); continue;
        }

        addParseLog(`=== ${file.name} ===`);
        addParseLog(`Meta: source=${meta.source}, period=${meta.dateFrom}_${meta.dateTo}, month=${meta.month}`);
        console.log(`\n=== ${file.name} ===`);
        console.log('Meta:', meta);

        const isCsv = file.name.toLowerCase().endsWith('.csv');
        let json;

        if (isCsv) {
            const text = await file.text();
            json = parseCSV(text);
            addParseLog(`CSV, rows: ${json.length}`);
            console.log(`CSV, rows: ${json.length}`);
        } else {
            const buf = await file.arrayBuffer();
            const wb  = XLSX.read(buf, { type: 'array' });
            const { sheet: ws, name: sheetName } = findDataSheet(wb);
            json = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            addParseLog(`Excel, sheets: ${wb.SheetNames.length}, selected: "${sheetName}", rows: ${json.length}`);
            console.log(`Excel, sheets: ${wb.SheetNames.length}, selected: "${sheetName}", rows: ${json.length}`);
        }

        // Look for the row with headers
        let headerRow = -1, colMap = {};
        for (let i = 0; i < Math.min(10, json.length); i++) {
            const row = json[i];
            const mapped = {}; let found = 0;
            row.forEach((cell, ci) => {
                const field = mapHeader(cell);
                if (field) { mapped[field] = ci; found++; }
            });
            if (found >= 2) { headerRow = i; colMap = mapped; break; }
        }

        if (headerRow === -1) {
            const msg = `⚠ ${file.name}: header row not found\n`;
            addParseLog(msg.trim());
            resultBox.textContent += msg;
            console.warn(msg, 'First 3 rows:', json.slice(0,3));
            continue;
        }

        addParseLog(`Headers in row ${headerRow}: ${Object.keys(colMap).join(', ')}`);
        console.log(`Headers in row ${headerRow}:`, colMap);

        // Parse data rows
        const rows = [];
        for (let i = headerRow + 1; i < json.length; i++) {
            const raw = json[i];
            if (raw.every(c => c === '' || c === null || c === undefined)) continue;
            // Skip total rows (ALL, TOTAL, etc.)
            const first = String(raw[0] || '').toUpperCase();
            if (first === 'ALL' || first === 'TOTAL') continue;

            const totalCommission = colMap.totalCommission !== undefined ? numCell(raw[colMap.totalCommission]) : 0;
            const totalDeposits = colMap.totalDeposits !== undefined ? numCell(raw[colMap.totalDeposits]) : 0;
            let sumM1MarketingSpend = colMap.sumM1MarketingSpend !== undefined ? numCell(raw[colMap.sumM1MarketingSpend]) : 0;
            let sumM1Deposits = colMap.sumM1Deposits !== undefined ? numCell(raw[colMap.sumM1Deposits]) : 0;

            if (colMap.totalDeposits !== undefined) {
                sumM1Deposits = totalDeposits;
            }
            if (colMap.totalCommission !== undefined) {
                sumM1MarketingSpend = totalCommission;
            } else if (colMap.totalDeposits !== undefined && colMap.kpi !== undefined) {
                sumM1MarketingSpend = totalDeposits * percentRatioCell(raw[colMap.kpi]);
            }

            const row = {
                trackingCode:     raw[colMap.trackingCode]      ?? null,
                brandName:        raw[colMap.brandName]         ?? null,
                country:          raw[colMap.country]           ?? null,
                firstDepositCount:numCell(raw[colMap.firstDepositCount]),
                sumM1Deposits,
                sumM1MarketingSpend,
                month:   meta.month,
                period:  `${meta.dateFrom}_${meta.dateTo}`,
                source:  meta.source,
            };
            if (!row.trackingCode && !row.brandName && !row.country) continue;
            rows.push(row);
        }

        console.log(`Parsed rows: ${rows.length}`);
        console.table(rows.slice(0, 20));
        if (rows.length > 20) console.log(`... and more ${rows.length - 20} rows`);
        addParseLog(`Parsed rows: ${rows.length}`);
        if (rows.length) {
            const preview = rows.slice(0, 20).map((r, idx) =>
                `${idx + 1}. tracking=${r.trackingCode || '—'}; brand=${r.brandName || '—'}; geo=${r.country || '—'}; deps=${r.firstDepositCount}; sum_M1depositS=${r.sumM1Deposits}; sum_M1marketingSpend=${r.sumM1MarketingSpend}`
            );
            addParseLog(`First ${preview.length} rows:\n${preview.join('\n')}`);
        }
        if (rows.length > 20) addParseLog(`... and more ${rows.length - 20} rows`);

        allResults.push({ file: file.name, meta, rows });
        resultBox.textContent += `✓ ${file.name}: ${rows.length} rows · ${meta.dateFrom} -> ${meta.dateTo} · month ${meta.month}\n`;
    }

    console.log('\n=== TOTAL ===');
    const total = allResults.reduce((s,r)=>s+r.rows.length, 0);
    console.log(`Files: ${allResults.length}, rows: ${total}`);
    addParseLog(`=== TOTAL ===`);
    addParseLog(`Files: ${allResults.length}, rows: ${total}`);

    resultBox.textContent += `\nDone. Details are in the console (F12 -> Console)`;
}

let _reportMonth  = '';
let _reportMonths = [];
let _reportBaseData = [];
let _reportData   = [];
let _reportSort   = { col: 'deps', dir: 'desc' };
let _reportFilters = { country: '', tracking_code: '', brand_name: '' };
let _reportGroup  = 'geo';

const REPORT_SORTS = ['label', 'deps', 'sum_m1_deposits', 'sum_m1_marketing_spend', 'kpi'];
const REPORT_FILTER_FIELDS = ['tracking_code', 'country', 'brand_name'];
const REPORT_GROUPS = ['geo', 'buyer', 'brand'];

function currentMonthKey() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function previousMonthKey() {
    const d = new Date();
    d.setMonth(d.getMonth() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function isMonthKey(v) {
    return /^\d{4}-\d{2}$/.test(String(v || ''));
}

function monthLabel(v) {
    if (!isMonthKey(v)) return v || '';
    const [y, m] = v.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en', {month: 'long', year: 'numeric'});
}

async function loadReportMonths() {
    try {
        const j = await fetchJson('/api/collations_report.php?months=1');
        if (!j.ok) throw new Error(j.error || 'API error');
        _reportMonths = j.data?.months || [];
        const current = j.current_month || currentMonthKey();
        if (!_reportMonth) _reportMonth = current;
        if (_reportMonths.length && !_reportMonths.includes(_reportMonth)) {
            _reportMonth = _reportMonths.includes(current) ? current : _reportMonths[0];
        }
    } catch (e) {
        if (!_reportMonth) _reportMonth = currentMonthKey();
    }
}

function readReportURL() {
    const p = new URLSearchParams(location.search.slice(1));
    const group = p.get('group');
    const month = p.get('month');
    const period = p.get('period');
    const sort = p.get('sort');
    const dir = p.get('dir');

    _reportGroup = REPORT_GROUPS.includes(group) ? group : 'geo';
    _reportMonth = isMonthKey(month)
        ? month
        : (period === 'previous' ? previousMonthKey() : currentMonthKey());
    _reportSort = {
        col: REPORT_SORTS.includes(sort) ? sort : 'deps',
        dir: dir === 'asc' ? 'asc' : 'desc',
    };
    _reportFilters = {
        country: p.get('country') || '',
        tracking_code: p.get('tracking_code') || '',
        brand_name: p.get('brand_name') || '',
    };
}

function writeReportURL(opts={}) {
    const p = new URLSearchParams();
    p.set('group', _reportGroup);
    p.set('month', _reportMonth || currentMonthKey());
    if (_reportSort.col !== 'deps') p.set('sort', _reportSort.col);
    if (_reportSort.dir !== 'desc') p.set('dir', _reportSort.dir);
    REPORT_FILTER_FIELDS.forEach(field => {
        if (_reportFilters[field]) p.set(field, _reportFilters[field]);
    });
    const url = location.pathname + '?' + p.toString();
    if (url !== location.pathname + location.search) {
        history[opts.replace ? 'replaceState' : 'pushState'](null, '', url);
    }
}

function syncReportControls() {
    const groupSelect = document.getElementById('reportGroupSelect');
    if (groupSelect) groupSelect.value = _reportGroup;
    const monthSelect = document.getElementById('reportMonthSelect');
    if (monthSelect) {
        const months = _reportMonths.length ? _reportMonths : [_reportMonth || currentMonthKey()];
        monthSelect.innerHTML = months.map(m => `<option value="${m}">${monthLabel(m)}</option>`).join('');
        monthSelect.value = _reportMonth || months[0] || currentMonthKey();
    }
    syncReportFilterOptions();
}

function setReportMonth(month) {
    _reportMonth = isMonthKey(month) ? month : currentMonthKey();
    syncReportControls();
    writeReportURL();
    loadReport();
}

function setReportGroup(group) {
    _reportGroup = REPORT_GROUPS.includes(group) ? group : 'geo';
    syncReportControls();
    writeReportURL();
    renderReport();
}

function setReportFilter(field, value) {
    if (!REPORT_FILTER_FIELDS.includes(field)) return;
    _reportFilters[field] = value || '';
    syncReportControls();
    writeReportURL();
    applyReportFilters();
}

function clearAllReportFilters() {
    _reportFilters = { country: '', tracking_code: '', brand_name: '' };
    syncReportControls();
    writeReportURL();
    applyReportFilters();
}

function sortReport(col) {
    if (_reportSort.col === col) _reportSort.dir = _reportSort.dir === 'desc' ? 'asc' : 'desc';
    else { _reportSort.col = col; _reportSort.dir = 'desc'; }
    writeReportURL({replace:true});
    renderReport();
}

function reportNum(row, col) {
    return +(row[col] || 0);
}

function formatMetric(value) {
    return (+value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function formatPct(value) {
    return `${(+value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })}%`;
}

function currentReportGroupMeta() {
    if (_reportGroup === 'buyer') return { field: 'buyer_label', label: 'Buyer' };
    if (_reportGroup === 'brand') return { field: 'brand_name', label: 'Brand' };
    return { field: 'country', label: 'Geo' };
}

function reportGroupFilterField(group) {
    if (group === 'buyer') return 'tracking_code';
    if (group === 'brand') return 'brand_name';
    return 'country';
}

function reportGroupShortLabel(group) {
    if (group === 'buyer') return 'U';
    if (group === 'brand') return 'B';
    return 'G';
}

function reportGroupLabel(group) {
    if (group === 'buyer') return 'Buyer';
    if (group === 'brand') return 'Brand';
    return 'Geo';
}

function reportLinkUrl(targetGroup, filterField, filterValue) {
    const p = new URLSearchParams();
    p.set('group', targetGroup);
    p.set('month', _reportMonth || currentMonthKey());
    REPORT_FILTER_FIELDS.forEach(field => {
        const value = field === filterField ? filterValue : _reportFilters[field];
        if (value) p.set(field, value);
    });
    return location.pathname + '?' + p.toString();
}

function reportRowLinks(row) {
    if (!row.filter_field || !row.filter_value || row.filter_value === '-') return '';
    const links = REPORT_GROUPS
        .filter(group => group !== _reportGroup)
        .map(group => {
            const label = reportGroupShortLabel(group);
            const title = `${reportGroupLabel(group)} report filtered by ${row.label || row.filter_value}`;
            return `<a class="report-row-link" href="${escapeHtml(reportLinkUrl(group, row.filter_field, row.filter_value))}" title="${escapeHtml(title)}">${label}</a>`;
        })
        .join('');
    return links ? `<span class="report-row-links">${links}</span>` : '';
}

function reportFilterValue(row, field) {
    if (field === 'tracking_code') return String(row?.tracking_code || row?.buyer_code || row?.buyer_label || '').trim();
    if (field === 'brand_name') return String(row?.brand_name || '').trim();
    if (field === 'country') return String(row?.country || '').trim();
    return String(row?.[field] || '').trim();
}

function reportRowMatches(row, filters) {
    return REPORT_FILTER_FIELDS.every(field => !filters[field] || reportFilterValue(row, field) === String(filters[field]));
}

async function fetchJson(url, options = {}) {
    const r = await fetch(url, options);
    const raw = await r.text();
    let data = null;
    if (raw) {
        try {
            data = JSON.parse(raw);
        } catch (e) {
            const preview = raw.replace(/\s+/g, ' ').trim().slice(0, 180);
            throw new Error(`HTTP ${r.status}: invalid JSON response${preview ? ` (${preview})` : ''}`);
        }
    }
    if (!r.ok) {
        const msg = data?.error || data?.message || `HTTP ${r.status}`;
        throw new Error(msg);
    }
    return data ?? {};
}

function uniqueSortedOptions(items, valueKey, labelKey = valueKey) {
    const map = new Map();
    items.forEach(item => {
        const value = reportFilterValue(item, valueKey);
        if (!value || value === '-' || value === '—') return;
        const label = String(item?.[labelKey] || value).trim() || value;
        if (!map.has(value)) map.set(value, label);
    });
    return [...map.entries()]
        .sort((a, b) => a[1].localeCompare(b[1]))
        .map(([value, label]) => ({ value, label }));
}

function fillReportSelect(selectId, options, value, placeholder) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const currentExists = !value || options.some(opt => opt.value === value);
    const selectedFallback = value && !currentExists
        ? `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`
        : '';
    select.innerHTML = `<option value="">${placeholder}</option>` + selectedFallback + options.map(opt =>
        `<option value="${String(opt.value).replace(/"/g, '&quot;')}">${opt.label}</option>`
    ).join('');
    select.value = value || '';
}

function syncReportFilterOptions() {
    const geoOptions = uniqueSortedOptions(
        _reportBaseData.filter(row => reportRowMatches(row, { ..._reportFilters, country: '' })),
        'country',
        'country'
    );
    const buyerOptions = uniqueSortedOptions(
        _reportBaseData.filter(row => reportRowMatches(row, { ..._reportFilters, tracking_code: '' })),
        'tracking_code',
        'buyer_label'
    );
    const brandOptions = uniqueSortedOptions(
        _reportBaseData.filter(row => reportRowMatches(row, { ..._reportFilters, brand_name: '' })),
        'brand_name',
        'brand_name'
    );
    fillReportSelect('reportGeoSelect', geoOptions, _reportFilters.country, 'All geos');
    fillReportSelect('reportBuyerSelect', buyerOptions, _reportFilters.tracking_code, 'All buyers');
    fillReportSelect('reportBrandSelect', brandOptions, _reportFilters.brand_name, 'All brands');
}

async function loadReport() {
    document.getElementById('reportTbl').innerHTML = '<div style="padding:40px;text-align:center;color:#888">Loading...</div>';
    try {
        const j = await fetchJson(`/api/collations_report.php?group=detail&month=${encodeURIComponent(_reportMonth || currentMonthKey())}`);
        if (!j.ok) throw new Error(j.error || 'API error');
        _reportBaseData = j.data || [];
        document.getElementById('reportMonth').textContent = `Month: ${monthLabel(j.month || j.meta?.month || _reportMonth)}`;
        applyReportFilters();
    } catch(e) {
        document.getElementById('reportTbl').innerHTML = `<div style="padding:20px;color:red">Error: ${e.message}</div>`;
    }
}

function applyReportFilters() {
    syncReportControls();
    _reportData = _reportBaseData.filter(row => reportRowMatches(row, _reportFilters));
    renderReport();
}

function buildReportGroups(rows) {
    const meta = currentReportGroupMeta();
    const grouped = new Map();
    rows.forEach(row => {
        const key = meta.field === 'buyer_label'
            ? String(row.tracking_code || row.buyer_code || row.buyer_label || '-')
            : String(row?.[meta.field] || '-');
        if (!grouped.has(key)) {
            const label = meta.field === 'buyer_label'
                ? (row.buyer_label || row.buyer_code || '-')
                : (row?.[meta.field] || '-');
            grouped.set(key, {
                label,
                sub_label: meta.field === 'buyer_label' && row.buyer_code && row.buyer_code !== label ? row.buyer_code : '',
                filter_field: reportGroupFilterField(_reportGroup),
                filter_value: key,
                deps: 0,
                sum_m1_deposits: 0,
                sum_m1_marketing_spend: 0,
            });
        }
        const item = grouped.get(key);
        item.deps += +row.deps || 0;
        item.sum_m1_deposits += reportNum(row, 'sum_m1_deposits');
        item.sum_m1_marketing_spend += reportNum(row, 'sum_m1_marketing_spend');
    });
    return [...grouped.values()].map(item => ({
        ...item,
        kpi: item.sum_m1_marketing_spend > 0 ? (item.sum_m1_deposits / item.sum_m1_marketing_spend) * 100 : 0,
    }));
}

function renderReport() {
    const tbl = document.getElementById('reportTbl');
    if (!_reportData.length) {
        tbl.innerHTML = '<div style="padding:40px;text-align:center;color:#888">No data for this period</div>';
        return;
    }

    const meta = currentReportGroupMeta();
    const groupedRows = buildReportGroups(_reportData);
    const sorted = [...groupedRows].sort((a, b) => {
        const numericCols = ['deps', 'sum_m1_deposits', 'sum_m1_marketing_spend', 'kpi'];
        const isNumeric = numericCols.includes(_reportSort.col);
        const va = isNumeric ? reportNum(a, _reportSort.col) : String(a.label || '');
        const vb = isNumeric ? reportNum(b, _reportSort.col) : String(b.label || '');
        return _reportSort.dir === 'desc'
            ? (typeof va === 'number' ? vb - va : vb.localeCompare(va))
            : (typeof va === 'number' ? va - vb : va.localeCompare(vb));
    });

    const totalDeps = groupedRows.reduce((s, r) => s + (+r.deps || 0), 0);
    const totalM1Deposits = groupedRows.reduce((s, r) => s + reportNum(r, 'sum_m1_deposits'), 0);
    const totalM1Spend = groupedRows.reduce((s, r) => s + reportNum(r, 'sum_m1_marketing_spend'), 0);
    const totalKpi = totalM1Spend > 0 ? (totalM1Deposits / totalM1Spend) * 100 : 0;
    const arrow = col => _reportSort.col === col ? (_reportSort.dir === 'desc' ? 'v' : '^') : '';

    let html = `<div style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;font-size:13px;color:var(--text2)">
        <span>Filtered report</span>
        <span style="margin-left:auto;font-size:11px;color:var(--text3)">${sorted.length} rows</span>
    </div>
    <table class="rtbl"><thead><tr>
        <th onclick="sortReport('label')">${meta.label} ${arrow('label')}</th>
        <th class="r" onclick="sortReport('deps')">Deps ${arrow('deps')}</th>
        <th class="r" onclick="sortReport('sum_m1_deposits')">sum_M1depositS ${arrow('sum_m1_deposits')}</th>
        <th class="r" onclick="sortReport('sum_m1_marketing_spend')">sum_M1marketingSpend ${arrow('sum_m1_marketing_spend')}</th>
        <th class="r" onclick="sortReport('kpi')">KPI ${arrow('kpi')}</th>
    </tr></thead><tbody>`;

    for (const row of sorted) {
        const subLabel = row.sub_label ? `<div style="font-size:11px;color:var(--text3);font-weight:400;margin-top:1px">${escapeHtml(row.sub_label)}</div>` : '';
        html += `<tr>
            <td style="font-weight:600"><div class="report-name-cell"><div class="report-name-main">${escapeHtml(row.label || '-')}${subLabel}</div>${reportRowLinks(row)}</div></td>
            <td class="r">${(+row.deps || 0).toLocaleString('en-US')}</td>
            <td class="r">${formatMetric(row.sum_m1_deposits)}</td>
            <td class="r">${formatMetric(row.sum_m1_marketing_spend)}</td>
            <td class="r">${formatPct(row.kpi)}</td>
        </tr>`;
    }

    html += `</tbody><tfoot><tr class="total-row">
        <td>Total: ${sorted.length} records</td>
        <td class="r">${totalDeps.toLocaleString('en-US')}</td>
        <td class="r">${formatMetric(totalM1Deposits)}</td>
        <td class="r">${formatMetric(totalM1Spend)}</td>
        <td class="r">${formatPct(totalKpi)}</td>
    </tr></tfoot></table>`;

    tbl.innerHTML = html;
}

// Load the report when the page opens




async function importToDB() {
    if (!IS_ADMIN) return;
    if (!window._parsedResults || !window._parsedResults.length) return;
    const resultBox = document.getElementById('result');
    resultBox.style.display = 'block';

    let totalInserted = 0, totalUpdated = 0, totalSkipped = 0, totalIgnoredPeriod = 0, allErrors = [];
    const fileResults = [];

    for (const {file, meta, rows} of window._parsedResults) {
        try {
            const j = await fetchJson('/api/collations_import.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ rows, period: meta.dateFrom+'_'+meta.dateTo, source: meta.source, month: meta.month }),
            });
            if (!j.ok) throw new Error(j.error || 'API error');
            const d = j.data;
            totalInserted += d.inserted;
            totalUpdated  += d.updated;
            totalSkipped  += d.skipped;
            totalIgnoredPeriod += Number(d.ignored_period || 0);
            if (d.errors?.length) allErrors.push(...d.errors.map(e => `${file}: ${e}`));
            fileResults.push({ file, ok: true, inserted: d.inserted, updated: d.updated, skipped: d.skipped, ignoredPeriod: Number(d.ignored_period || 0), errors: d.errors || [] });
        } catch(e) {
            fileResults.push({ file, ok: false, error: e.message });
            allErrors.push(`${file}: ${e.message}`);
        }
    }

    // Render the formatted result
    let html = '<div style="font-family:inherit;font-size:13px">';

    // Summary row
    const totalRows = totalInserted + totalUpdated + totalSkipped + totalIgnoredPeriod;
    const statusColor = allErrors.length ? '#fa3e3e' : '#31a24c';
    const statusIcon  = allErrors.length ? '⚠' : '✓';
    html += `<div style="display:flex;gap:24px;align-items:center;padding:12px 16px;background:#f7f8fa;border-radius:8px;margin-bottom:12px;border:1px solid #e4e6eb">
        <span style="font-size:16px;font-weight:800;color:${statusColor}">${statusIcon}</span>
        <div style="display:flex;gap:20px;flex-wrap:wrap">
            <span><b style="font-size:15px;color:#1877f2">${totalRows}</b> <span style="color:#65676b">rows processed</span></span>
            <span><b style="font-size:15px;color:#31a24c">${totalInserted}</b> <span style="color:#65676b">new</span></span>
            <span><b style="font-size:15px;color:#f0a500">${totalUpdated}</b> <span style="color:#65676b">updated</span></span>
            ${totalIgnoredPeriod ? `<span><b style="font-size:15px;color:#65676b">${totalIgnoredPeriod}</b> <span style="color:#65676b">smaller period</span></span>` : ''}
            ${totalSkipped ? `<span><b style="font-size:15px;color:#fa3e3e">${totalSkipped}</b> <span style="color:#65676b">skipped</span></span>` : ''}
        </div>
    </div>`;

    // Per-file details
    for (const r of fileResults) {
        if (r.ok) {
            html += `<div style="padding:6px 0;display:flex;align-items:baseline;gap:10px;border-bottom:1px solid #f0f0f0">
                <span style="color:#31a24c;font-weight:700">✓</span>
                <span style="flex:1;color:#1c1e21;font-weight:500">${r.file}</span>
                <span style="color:#31a24c">+${r.inserted} new</span>
                <span style="color:#f0a500">${r.updated} updated</span>
                ${r.ignoredPeriod ? `<span style="color:#65676b">${r.ignoredPeriod} smaller period</span>` : ''}
                ${r.skipped ? `<span style="color:#fa3e3e">${r.skipped} errors</span>` : ''}
            </div>`;
            if (r.errors.length) {
                html += r.errors.map(e =>
                    `<div style="padding:3px 6px 3px 24px;font-size:11px;color:#fa3e3e;font-family:monospace">${e}</div>`
                ).join('');
            }
        } else {
            html += `<div style="padding:6px 0;display:flex;align-items:baseline;gap:10px;border-bottom:1px solid #f0f0f0">
                <span style="color:#fa3e3e;font-weight:700">✗</span>
                <span style="flex:1;color:#1c1e21;font-weight:500">${r.file}</span>
                <span style="color:#fa3e3e">${r.error}</span>
            </div>`;
        }
    }

    if (window._parseLog?.length) {
        html += `<details open style="margin-top:14px;border:1px solid #e4e6eb;border-radius:8px;background:#fff">
            <summary style="cursor:pointer;padding:10px 12px;font-weight:700;color:#1c1e21">Parsing Details</summary>
            <pre style="margin:0;padding:12px;border-top:1px solid #e4e6eb;max-height:360px;overflow:auto;font-size:12px;line-height:1.45;white-space:pre-wrap;color:#1c1e21;background:#f7f8fa">${escapeHtml(window._parseLog.join('\n'))}</pre>
        </details>`;
    }

    html += '</div>';
    resultBox.innerHTML = html;
    resultBox.style.fontFamily = 'inherit';
    resultBox.style.whiteSpace = 'normal';

    loadReport();
}
</script>

<?php if ($isAdmin): ?>
<div style="margin-top:8px">
  <h2 style="margin-bottom:20px;font-size:18px">📂 File Upload</h2>
  <div class="upload-box" id="dropZone">
    <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" multiple>
    <label for="fileInput">
      <div style="font-size:32px;margin-bottom:12px">📂</div>
      <div><b>Click to choose</b> or drag files here</div>
      <div style="font-size:12px;color:var(--text3);margin-top:6px">Format: .xlsx, .xls, .csv</div>
    </label>
  </div>
  <div id="fileList" style="margin-top:12px"></div>
  <div id="result" class="result" style="display:none"></div>

</div>
<?php endif ?>

</body>
</html>
