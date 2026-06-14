<?php
declare(strict_types=1);
// @version 1.0.1

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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Ads Mobile</title>
<style>
:root{--bg:#f0f2f5;--surface:#fff;--border:#d8dde6;--text:#15171a;--text2:#68707c;--blue:#1877f2;--green:#16833a;--red:#c0262d}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.35 system-ui,-apple-system,Segoe UI,sans-serif}
.app{min-height:100vh;padding:12px 12px 22px}
.top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.brand{font-size:18px;font-weight:800;letter-spacing:-.2px}
.link{color:var(--blue);text-decoration:none;font-size:13px;font-weight:700}
.controls{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
select{width:100%;height:38px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text);font:inherit;padding:0 10px}
.meta{font-size:12px;color:var(--text2);margin:0 0 10px}
.grid{display:grid;grid-template-columns:1fr;gap:8px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;min-height:82px}
.label{font-size:12px;color:var(--text2);font-weight:700;margin-bottom:8px}
.value{font-size:24px;font-weight:800;letter-spacing:-.4px;font-variant-numeric:tabular-nums}
.value.money{font-size:22px}
.green{color:var(--green)}
.red{color:var(--red)}
.wide{grid-column:1 / -1}
.error{background:#fee2e2;color:var(--red);border:1px solid #fecaca;border-radius:8px;padding:12px;margin-top:10px}
.loading{color:var(--text2);font-size:13px;padding:12px;text-align:center}
@media (min-width:520px){.app{max-width:520px;margin:0 auto}}
</style>
</head>
<body>
<main class="app">
  <div class="top">
    <div class="brand">Ads Mobile</div>
    <a class="link" href="/index.php">Desktop</a>
  </div>

  <div class="controls">
    <select id="range">
      <option value="today">Today</option>
      <option value="yesterday">Yesterday</option>
      <option value="7d">7 Days</option>
      <option value="30d">30 Days</option>
      <option value="this_month">This Month</option>
    </select>
    <select id="bm">
      <option value="">All BM</option>
    </select>
  </div>

  <div id="meta" class="meta"></div>
  <div id="err"></div>
  <section class="grid" id="cards">
    <div class="loading wide">Loading...</div>
  </section>
</main>

<script>
const $ = id => document.getElementById(id);
const ranges = $('range');
const bm = $('bm');

ranges.addEventListener('change', loadTotals);
bm.addEventListener('change', loadTotals);
initMobile();

async function initMobile() {
  await syncKeitaro();
  loadBms();
  loadTotals();
}

async function syncKeitaro() {
  try {
    const res = await fetch('/api/sync_keitaro.php', {method:'POST', cache:'no-store'});
    if (!res.ok) throw new Error('Keitaro sync HTTP ' + res.status);
    return await res.json().catch(() => null);
  } catch (e) {
    console.warn(e);
    return null;
  }
}

async function loadBms() {
  try {
    const res = await fetch('/api/rules_check.php?list_bms=1');
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    const opts = (json.data?.bms || []).map(b => `<option value="${esc(b.id)}">${esc(b.name)}</option>`).join('');
    bm.innerHTML = '<option value="">All BM</option>' + opts;
  } catch (e) {
    showError(e.message);
  }
}

async function loadTotals() {
  $('err').innerHTML = '';
  $('cards').innerHTML = '<div class="loading wide">Loading...</div>';
  try {
    const p = new URLSearchParams({range: ranges.value});
    if (bm.value) p.set('bm_id', bm.value);
    const res = await fetch('/api/totals.php?' + p.toString());
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    render(json.data || {}, json.meta || {});
  } catch (e) {
    showError(e.message);
    $('cards').innerHTML = '';
  }
}

function render(d, meta) {
  const spend = Number(d.spend || 0);
  const revenue = Number(d.revenue || 0);
  const profit = revenue - spend;
  const roi = spend > 0 ? profit / spend * 100 : null;
  $('meta').textContent = meta.date_from && meta.date_to ? `${meta.date_from} - ${meta.date_to}` : '';
  $('cards').innerHTML = [
    card('ROI %', roi === null ? '-' : roi.toFixed(1) + '%', signed(profit)),
    card('Profit', money(profit), 'money ' + signed(profit)),
    card('Revenue', revenue > 0 ? money(revenue) : '-', 'money'),
    card('Spend', money(spend), 'money'),
    card('Deps', count(d.deps)),
    card('Regs', count(d.regs)),
    card('Leads', count(d.leads)),
  ].join('');
}

function card(label, value, cls = '') {
  return `<article class="card"><div class="label">${esc(label)}</div><div class="value ${esc(cls)}">${esc(value)}</div></article>`;
}

function count(v) {
  v = Number(v || 0);
  return v > 0 ? v.toLocaleString('ru') : '-';
}

function money(v) {
  const sign = v < 0 ? '-' : '';
  return sign + '$' + Math.abs(Number(v || 0)).toLocaleString('en', {maximumFractionDigits: 2, minimumFractionDigits: 2});
}

function signed(v) {
  return Number(v || 0) > 0 ? 'green' : Number(v || 0) < 0 ? 'red' : '';
}

function showError(message) {
  $('err').innerHTML = `<div class="error">${esc(message)}</div>`;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}
</script>
</body>
</html>
