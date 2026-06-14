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
<title>Campaign Rules Review</title>
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
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
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
.title{font-size:16px;font-weight:700;margin-bottom:2px}
.sub{color:var(--text2);font-size:12px}
.wrap{padding:16px}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
select,button,label{font:inherit}
select{height:34px;border:1px solid var(--border);border-radius:6px;padding:0 10px;background:#fff}
button{height:34px;border:1px solid var(--blue);border-radius:6px;background:var(--blue);color:#fff;font-weight:700;padding:0 14px;cursor:pointer}
button:disabled{opacity:.55;cursor:default}
label{display:flex;align-items:center;gap:6px;color:var(--text2)}
.summary{margin:12px 0;color:var(--text2)}
.summary b{color:var(--text)}
.campaign-list{display:grid;gap:10px}
.campaign-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.campaign-card.xmlr{background:#f3f4f6;opacity:.82}
.campaign-card.xmlr .campaign-head,.campaign-card.xmlr .period-table th{background:#eef0f3}
.campaign-head{display:grid;grid-template-columns:minmax(260px,1fr) auto;gap:12px;align-items:start;padding:12px 14px;border-bottom:1px solid #edf0f4}
.campaign-name{font-weight:700}
.meta{color:var(--text2);font-size:11px;margin-top:3px}
.head-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;border-radius:4px;padding:2px 7px;font-weight:700;font-size:11px;min-height:22px}
.STOP{background:var(--red-bg);color:var(--red)}
.START{background:var(--orange-bg);color:var(--orange)}
.OK{background:var(--green-bg);color:var(--green)}
.HOLD_STOP,.NO_GEO,.NO_RULES,.MANUAL_STOP{background:var(--gray-bg);color:var(--gray)}
.status-badge{display:inline-flex;align-items:center;border-radius:4px;padding:2px 7px;font-weight:700;font-size:11px;min-height:22px;background:var(--gray-bg);color:var(--gray)}
.status-badge.ACTIVE{background:var(--green-bg);color:var(--green)}
.status-badge.PAUSED{background:var(--red-bg);color:var(--red)}
.action-change{color:var(--red);font-weight:700}
.action-none{color:var(--text2);font-weight:700}
.period-table{width:100%;border-collapse:collapse;table-layout:fixed}
.period-table th,.period-table td{border-bottom:1px solid #edf0f4;padding:9px 10px;text-align:left;vertical-align:top}
.period-table tr:last-child td{border-bottom:0}
.period-table th{font-size:11px;text-transform:uppercase;color:var(--text2);background:var(--soft)}
.period-col{width:68px;font-weight:700}
.stat-col{width:25%}
.limits-col{width:20%}
.signal-col{width:22%}
.metric-grid{display:grid;grid-template-columns:repeat(3,minmax(76px,1fr));gap:5px}
.metric,.limit{border:1px solid var(--border);border-radius:6px;padding:4px 6px;background:#fff;min-height:36px}
.metric b,.limit b{display:block;font-size:10px;color:var(--text2);font-weight:700;text-transform:uppercase}
.metric span,.limit span{font-variant-numeric:tabular-nums}
.limit.ok{border-color:#86efac;background:#f0fdf4;color:var(--green)}
.limit.warn{border-color:#fde68a;background:#fffbeb;color:var(--orange)}
.limit.bad{border-color:#fecaca;background:#fef2f2;color:var(--red)}
.limit.empty{background:#f8fafc;color:var(--text2)}
.signal{font-size:12px;line-height:1.45}
.signal-line{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px}
.signal-reason{color:var(--text2)}
.muted{color:var(--text2)}
.error{background:var(--red-bg);color:var(--red);border:1px solid #fecaca;border-radius:8px;padding:12px;margin-top:12px}
.okmsg{background:var(--green-bg);color:var(--green);border:1px solid #86efac;border-radius:8px;padding:12px;margin-top:12px}
.empty{background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text2);padding:24px}
a{color:var(--blue);text-decoration:none}
@media (max-width:1100px){
  .period-table{min-width:980px}
  .campaign-card{overflow:auto}
  .campaign-head{grid-template-columns:1fr}
  .head-actions{justify-content:flex-start}
}
</style>
</head>
<body>
<?php include __DIR__.'/_header.php'; ?>
<div class="wrap">
  <div style="margin-bottom:12px">
    <div class="title">Campaign Rules Review</div>
    <div class="sub">Dry-run: statuses are not changed, and signals are calculated separately from the database and Google Sheets</div>
  </div>
  <div class="panel">
    <select id="bmSelect"><option value="">Select BM</option></select>
    <label><input type="checkbox" id="onlyChanges"> only where status should be changed</label>
    <label><input type="checkbox" id="skipCpc" checked> skip CPC</label>
    <button id="runBtn" disabled>Run Check</button>
    <button id="createTasksBtn" disabled>&#1057;&#1086;&#1079;&#1076;&#1072;&#1090;&#1100; &#1079;&#1072;&#1076;&#1072;&#1095;&#1080;</button>
  </div>
  <div id="summary" class="summary"></div>
  <div id="error"></div>
  <div id="rows" class="campaign-list"><div class="empty">Select a BM to run the check.</div></div>
</div>
<script>
const $ = id => document.getElementById(id);
const money = n => '$' + Number(n || 0).toLocaleString('en', {maximumFractionDigits: 2});
const num = n => Number(n || 0).toLocaleString('en', {maximumFractionDigits: 2});
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

$('runBtn').addEventListener('click', runCheck);
$('createTasksBtn').addEventListener('click', createTasksFromVerdicts);
$('bmSelect').addEventListener('change', () => {
  $('runBtn').disabled = !$('bmSelect').value;
  $('createTasksBtn').disabled = !$('bmSelect').value;
  if ($('bmSelect').value) runCheck();
  else resetEmpty();
});
$('onlyChanges').addEventListener('change', () => { if ($('bmSelect').value) runCheck(); });
$('skipCpc').addEventListener('change', () => { if ($('bmSelect').value) runCheck(); });
loadBmList();

async function loadBmList() {
  $('error').innerHTML = '';
  $('summary').innerHTML = '';
  resetEmpty('Loading BM list...');
  try {
    const res = await fetch('/api/rules_check.php?list_bms=1');
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    fillBmSelect(json.data?.bms || []);
    resetEmpty();
  } catch (e) {
    $('error').innerHTML = `<div class="error">${esc(e.message)}</div>`;
    resetEmpty('Failed to load BM list.');
  }
}

async function runCheck() {
  if (!$('bmSelect').value) {
    resetEmpty();
    return;
  }
  $('runBtn').disabled = true;
  $('createTasksBtn').disabled = true;
  $('runBtn').textContent = 'Review...';
  $('error').innerHTML = '';
  $('rows').innerHTML = '<div class="empty">Calculating...</div>';
  try {
    const p = new URLSearchParams();
    p.set('bm_id', $('bmSelect').value);
    if ($('onlyChanges').checked) p.set('only_changes', '1');
    p.set('skip_cpc', $('skipCpc').checked ? '1' : '0');
    const res = await fetch('/api/rules_check.php?' + p.toString());
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    render(json.data);
  } catch (e) {
    $('error').innerHTML = `<div class="error">${esc(e.message)}</div>`;
    $('rows').innerHTML = '<div class="empty">Check failed.</div>';
  } finally {
    $('runBtn').disabled = !$('bmSelect').value;
    $('createTasksBtn').disabled = !$('bmSelect').value;
    $('runBtn').textContent = 'Run Check';
  }
}

async function createTasksFromVerdicts() {
  if (!$('bmSelect').value) return;
  if (!confirm('\u0421\u043e\u0437\u0434\u0430\u0442\u044c \u0437\u0430\u0434\u0430\u0447\u0438 \u043d\u0430 START/STOP \u043f\u043e \u0442\u0435\u043a\u0443\u0449\u0438\u043c \u0432\u0435\u0440\u0434\u0438\u043a\u0442\u0430\u043c?')) return;

  $('createTasksBtn').disabled = true;
  $('createTasksBtn').textContent = '\u0421\u043e\u0437\u0434\u0430\u044e...';
  $('error').innerHTML = '';
  try {
    const res = await fetch('/api/rules_check.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: 'create_tasks',
        bm_id: $('bmSelect').value,
        only_changes: $('onlyChanges').checked,
        skip_cpc: $('skipCpc').checked ? '1' : '0'
      })
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    render(json.data);
    const q = json.data?.queue || {};
    $('error').innerHTML = `<div class="okmsg">\u0417\u0430\u0434\u0430\u0447\u0438: \u0441\u043e\u0437\u0434\u0430\u043d\u043e ${esc(q.created || 0)}, \u0443\u0436\u0435 \u0431\u044b\u043b\u0438 ${esc(q.skipped_existing || 0)}, \u043e\u0442\u043c\u0435\u043d\u0435\u043d\u043e \u043f\u0440\u043e\u0442\u0438\u0432\u043e\u043f\u043e\u043b\u043e\u0436\u043d\u044b\u0445 ${esc(q.cancelled_conflicts || 0)}, \u043e\u0448\u0438\u0431\u043e\u043a ${esc((q.errors || []).length || 0)}</div>`;
  } catch (e) {
    $('error').innerHTML = `<div class="error">${esc(e.message)}</div>`;
  } finally {
    $('createTasksBtn').disabled = !$('bmSelect').value;
    $('createTasksBtn').textContent = '\u0421\u043e\u0437\u0434\u0430\u0442\u044c \u0437\u0430\u0434\u0430\u0447\u0438';
  }
}

function resetEmpty(text = 'Select a BM to run the check.') {
  $('summary').innerHTML = '';
  $('rows').innerHTML = `<div class="empty">${esc(text)}</div>`;
}

function fillBmSelect(bms) {
  const selected = $('bmSelect').value;
  $('bmSelect').innerHTML = '<option value="">Select BM</option>' + bms.map(b => `<option value="${esc(b.id)}">${esc(b.name)}</option>`).join('');
  $('bmSelect').value = selected;
  $('runBtn').disabled = !$('bmSelect').value;
  $('createTasksBtn').disabled = !$('bmSelect').value;
}

function render(data) {
  if (data.bms) fillBmSelect(data.bms);

  const s = data.stats || {};
  const sheets = data.sheets || {};
  const bmId = $('bmSelect').value;
  const cronEnabled = isTruthy((data.bm_cron_enabled || {})[bmId]);
  const cronText = cronEnabled
    ? '<b style="color:var(--green)">\u043a\u0440\u043e\u043d \u0432\u043a\u043b\u044e\u0447\u0435\u043d</b>'
    : '<b style="color:var(--red)">\u043a\u0440\u043e\u043d \u0432\u044b\u043a\u043b\u044e\u0447\u0435\u043d</b>';
  const sheetsText = sheets.configured
    ? (sheets.loaded ? `Sheets: <b>${sheets.count || 0}</b> GEO` : `Sheets: <b>error</b> ${esc(sheets.error || '')}`)
    : 'Sheets: <b>is not configured</b>';
  $('summary').innerHTML = `Period: <b>${esc(data.periods.today.from)}</b> and <b>${esc(data.periods.last30.from)} - ${esc(data.periods.last30.to)}</b> · ` +
    `Total: <b>${s.total || 0}</b> · STOP: <b>${s.stop || 0}</b> · START: <b>${s.start || 0}</b> · ` +
    `OK: <b>${s.ok || 0}</b> · HOLD: <b>${s.hold_stop || 0}</b> · NO_RULES: <b>${s.no_rules || 0}</b> · IGNORED: <b>${s.ignored_status || 0}</b> · ` +
    `Changes: <b>${s.changes || 0}</b> · ${sheetsText}`;

  $('summary').innerHTML += ` \u00b7 ${cronText}`;

  const rows = data.verdicts || [];
  if (!rows.length) {
    $('rows').innerHTML = '<div class="empty">No campaigns to display.</div>';
    return;
  }
  $('rows').innerHTML = rows.map(renderCampaign).join('');
}

function renderCampaign(v) {
  const action = v.should_change ? (v.desired_status === 'PAUSED' ? 'PAUSE' : 'START') : 'NO_CHANGE';
  const actionClass = v.should_change ? 'action-change' : 'action-none';
  const isXmlr = /^XMLR_/i.test(v.campaign_name || '');
  return `<section class="campaign-card${isXmlr ? ' xmlr' : ''}">
    <div class="campaign-head">
      <div>
        <div class="campaign-name">${esc(v.campaign_name)}</div>
        <div class="meta">${esc(v.campaign_id)} · GEO ${esc(v.geo || '-')} · FB ${esc(v.fb_status || '-')} · ${esc(v.account_name || v.account_id || '')}</div>
      </div>
      <div class="head-actions">
        <span class="badge ${esc(v.verdict)}">${esc(v.verdict)}</span>
        <span class="${actionClass}">${action}</span>
        ${renderAutoRuleTaskBadge(v)}
        <span class="status-badge ${esc(v.fb_status || '')}">${esc(v.fb_status || '-')}</span>
      </div>
    </div>
    <table class="period-table">
      <thead>
        <tr>
          <th class="period-col">Period</th>
          <th class="stat-col">Stats</th>
          <th class="limits-col">DB Limits × Mult</th>
          <th class="limits-col">Sheets Limits</th>
          <th class="signal-col">Start/Stop Signals</th>
        </tr>
      </thead>
      <tbody>
        ${renderPeriod('1D', v.data_1d, v.limits_db_1d, v.limits_sheets_1d, v.signal_db, v.signal_sheets)}
        ${renderPeriod('30D', v.data_30d, v.limits_db_30d, v.limits_sheets_30d, v.signal_db, v.signal_sheets)}
      </tbody>
    </table>
  </section>`;
}

function isTruthy(v) {
  return v === true || v === 1 || v === '1' || v === 't' || v === 'true' || v === 'TRUE';
}

function renderAutoRuleTaskBadge(v) {
  const task = v.auto_rule_task || null;
  if (!v.should_change && !task) return '';
  if (!task) {
    return `<span class="status-badge" title="\u0417\u0430\u0434\u0430\u0447\u0430 \u0435\u0449\u0435 \u043d\u0435 \u0441\u043e\u0437\u0434\u0430\u043d\u0430. \u041f\u0440\u043e\u0432\u0435\u0440\u044c: BM \u0432\u043a\u043b\u044e\u0447\u0435\u043d \u0432 \u043a\u0440\u043e\u043d, \u0438 \u043a\u0440\u043e\u043d \u0443\u0436\u0435 \u0437\u0430\u043f\u0443\u0441\u043a\u0430\u043b\u0441\u044f.">\u0437\u0430\u0434\u0430\u0447\u0438 \u043d\u0435\u0442</span>`;
  }
  const desired = task.payload?.desired_status || '-';
  const txt = `task #${task.id} ${task.status} ${desired}`;
  const title = `${txt}\n${task.error || ''}`;
  return `<span class="status-badge ${esc(task.status || '')}" title="${esc(title)}">${esc(txt)}</span>`;
}

function renderPeriod(period, stats, dbLimits, sheetsLimits, dbSignal, sheetsSignal) {
  return `<tr>
    <td class="period-col">${period}</td>
    <td>${fmtStats(stats)}</td>
    <td>${fmtLimits(dbLimits, period, stats)}</td>
    <td>${fmtLimits(sheetsLimits, period, stats)}</td>
    <td>${fmtPeriodSignals(period, dbSignal, sheetsSignal)}</td>
  </tr>`;
}

function fmtStats(d) {
  d = d || {};
  return `<div class="metric-grid">
    ${metric('Spend', money(d.spend))}
    ${metric('Clicks', num(d.clicks))}
    ${metric('CPC', money(d.cpc))}
    ${metric('Leads', num(d.leads))}
    ${metric('CPL', dashMoney(d.cpl))}
    ${metric('Regs', num(d.regs))}
    ${metric('CPR', dashMoney(d.cpr))}
    ${metric('Deps', num(d.deps))}
    ${metric('CPD', dashMoney(d.cpd))}
    ${metric('R2D', r2d(d))}
    ${metric('Revenue', money(d.revenue))}
  </div>`;
}

function metric(label, value) {
  return `<div class="metric"><b>${esc(label)}</b><span>${esc(value)}</span></div>`;
}

function fmtLimits(limits, period, stats) {
  limits = limits || {};
  const multValue = Number(limits['MULT' + period] || 1);
  const defs = [
    ['MAXCPC' + period, 'CPC', actualCost(stats, 'CPC')],
    ['MAXLEAD' + period, 'CPL', actualCost(stats, 'CPL')],
    ['MAXREG' + period, 'CPR', actualCost(stats, 'CPR')],
    ['MAXDEP' + period, 'CPD', actualCost(stats, 'CPD')],
  ];
  if (!defs.some(([key]) => limits[key])) {
    return '<div class="muted">no limits</div>';
  }
  const multCell = limits['MULT' + period]
    ? `<div class="limit empty"><b>Mult</b><span>${num(limits['MULT' + period])}</span></div>`
    : '';
  return `<div class="metric-grid">${defs.map(([key,label,actual]) => limitCell(label, limits[key], actual, multValue)).join('')}${multCell}</div>`;
}

function limitCell(label, limit, actual, mult = 1) {
  if (!limit) {
    return `<div class="limit empty"><b>${esc(label)}</b><span>-</span></div>`;
  }
  const cls = limitClass(actual, Number(limit));
  const base = mult && mult !== 1 ? Number(limit) / mult : null;
  const baseDetail = base ? `<br><span class="muted">base ${money(base)} × ${num(mult)}</span>` : '';
  const factDetail = actual === null ? '' : `<br><span class="muted">fact ${money(actual)}</span>`;
  return `<div class="limit ${cls}"><b>${esc(label)}</b><span>max ${money(limit)}</span>${baseDetail}${factDetail}</div>`;
}

function actualCost(d, metricName) {
  d = d || {};
  if (metricName === 'CPC') return Number(d.clicks || 0) > 0 ? Number(d.cpc || 0) : null;
  if (metricName === 'CPL') return Number(d.leads || 0) > 0 && d.cpl !== null && d.cpl !== undefined ? Number(d.cpl) : null;
  if (metricName === 'CPR') return Number(d.regs || 0) > 0 && d.cpr !== null && d.cpr !== undefined ? Number(d.cpr) : null;
  if (metricName === 'CPD') return Number(d.deps || 0) > 0 && d.cpd !== null && d.cpd !== undefined ? Number(d.cpd) : null;
  return null;
}

function limitClass(actual, limit) {
  if (!limit || actual === null || actual === undefined) return 'empty';
  const ratio = Number(actual) / Number(limit);
  if (ratio > 1) return 'bad';
  if (ratio >= 0.85) return 'warn';
  return 'ok';
}

function fmtPeriodSignals(period, dbSignal, sheetsSignal) {
  return `<div class="signal">
    ${fmtOneSignal('DB', periodSignal(dbSignal, period))}
    ${fmtOneSignal('Sheets', periodSignal(sheetsSignal, period))}
  </div>`;
}

function periodSignal(signal, period) {
  if (!signal) return null;
  const v = period === '1D' ? signal.violation_1d : signal.violation_30d;
  if (v) {
    return {
      verdict: 'STOP',
      desired_status: 'PAUSED',
      should_change: signal.should_change,
      reason: `${v.metric}: ${v.reason}`,
    };
  }
  if (period === '30D' && signal.verdict === 'START') {
    return signal;
  }
  if (period === '30D' && signal.start_block) {
    return {
      verdict: 'HOLD_STOP',
      desired_status: 'PAUSED',
      should_change: false,
      reason: signal.start_block,
    };
  }
  return {
    verdict: 'OK',
    desired_status: signal.desired_status,
    should_change: false,
    reason: 'no violations',
  };
}

function fmtOneSignal(label, sig) {
  if (!sig) {
    return `<div class="signal-line"><b>${esc(label)}</b><span class="badge NO_RULES">no limits</span></div>`;
  }
  const desired = sig.desired_status ? ` → ${esc(sig.desired_status)}` : '';
  return `<div class="signal-line"><b>${esc(label)}</b><span class="badge ${esc(sig.verdict)}">${esc(sig.verdict)}${desired}</span></div><div class="signal-reason">${esc(sig.reason || '')}</div>`;
}

function dashMoney(v) {
  return v === null || v === undefined ? '-' : money(v);
}
function r2d(d) {
  const regs = Number(d?.regs || 0);
  return regs > 0 ? (Number(d?.deps || 0) / regs * 100).toFixed(2) + '%' : '-';
}
</script>
</body>
</html>
