<?php
// @version 1.4.361
require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Auth.php';

if (!defined('CAMPAIGN_BUILDER_DEFAULT_URL_PARAMS')) {
    define(
        'CAMPAIGN_BUILDER_DEFAULT_URL_PARAMS',
        'sub_id_1={{ad.id}}&sub_id_2={{campaign.id}}&sub_id_3=14886&sub_id_4={{campaign.name}}&sub_id_5={{adset.id}}&sub_id_6={{adset.name}}&sub_id_7={{ad.name}}&sub_id_8={{placement}}&pixel={pixel}'
    );
}

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

function fmtMoney(float $v, string $sym = '$'): string {
    return $sym . number_format($v, 2, '.', ' ');
}
function fmtNum(int $v): string {
    return number_format($v, 0, '.', ' ');
}
function fmtPct(float $v): string {
    return number_format($v, 2, '.', ' ') . '%';
}

$campaignBuilderBmIds = array_values(array_filter(array_map('strval', $auth->allowedBmIds($me))));
$campaignBuilderSummaryRows = campaignBuilderSummaryRows($db, $campaignBuilderBmIds, $me);

function campaignBuilderSummaryRows(PDO $db, array $bmIds, array $me): array
{
    if (!$bmIds) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($bmIds as $i => $bmId) {
        $key = ':bm_' . $i;
        $placeholders[] = $key;
        $params[$key] = (string)$bmId;
    }

    $tz = new DateTimeZone('Europe/Kyiv');
    $now = new DateTime('now', $tz);
    $dateFrom = (clone $now)->modify('-6 days midnight')->format('Y-m-d');
    $dateTo = $now->format('Y-m-d');
    $creativeDateFrom = (clone $now)->modify('-29 days midnight')->format('Y-m-d');
    $creativeDateTo = $now->format('Y-m-d');

    $sql = "
        WITH campaign_stats AS (
            SELECT
                a.campaign_id::text AS campaign_id,
                SUM(i.impressions) AS impressions,
                SUM(i.clicks) AS clicks,
                SUM(i.spend) AS spend,
                SUM(i.delta) AS delta,
                SUM(i.leads) AS leads,
                SUM(i.regs) AS regs,
                SUM(i.deps) AS deps,
                SUM(i.revenue) AS revenue
            FROM public.insights_daily i
            JOIN public.ads a ON a.id = i.ad_id
            JOIN public.ad_sets s ON s.id = a.ad_set_id
            JOIN public.campaigns c ON c.id = a.campaign_id
            LEFT JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
            WHERE (aa.bm_id IN (" . implode(',', $placeholders) . ") OR aa.bm_id IS NULL)
              AND i.date >= :date_from
              AND i.date <= :date_to
              AND COALESCE(a.status, '') != 'DELETED'
              AND COALESCE(s.status, '') != 'DELETED'
              AND a.campaign_id IS NOT NULL
            GROUP BY a.campaign_id
        ),
        base AS (
            SELECT
                campaign_geo(c.name) AS geo,
                c.id::text AS campaign_id,
                aa.status AS account_status,
                CASE
                    WHEN COALESCE(aa.status, 1) != 1 THEN 0
                    WHEN COALESCE(c.status, '') IN ('MANUAL_STOP', 'ARCHIVED', 'DELETED') THEN 0
                    WHEN COALESCE(c.effective_status, '') IN ('MANUAL_STOP', 'ARCHIVED', 'DELETED') THEN 0
                    WHEN COALESCE(c.effective_status, '') <> '' AND COALESCE(c.effective_status, '') <> 'ACTIVE' THEN 0
                    WHEN COALESCE(c.status, '') <> '' AND COALESCE(c.status, '') <> 'ACTIVE' THEN 0
                    WHEN COALESCE(c.effective_status, '') = 'ACTIVE' OR COALESCE(c.status, '') = 'ACTIVE' THEN 1
                    ELSE 0
                END AS is_active,
                COALESCE(cs.impressions, 0) AS impressions,
                COALESCE(cs.clicks, 0) AS clicks,
                COALESCE(cs.spend, 0) AS spend,
                COALESCE(cs.delta, 0) AS delta,
                COALESCE(cs.leads, 0) AS leads,
                COALESCE(cs.regs, 0) AS regs,
                COALESCE(cs.deps, 0) AS deps,
                COALESCE(cs.revenue, 0) AS revenue
            FROM public.campaigns c
            JOIN public.ad_accounts aa ON aa.id = c.ad_account_id
            JOIN public.business_managers bm ON bm.id = aa.bm_id
            LEFT JOIN campaign_stats cs ON cs.campaign_id = c.id::text
            WHERE aa.bm_id IN (" . implode(',', $placeholders) . ")
        ),
        active_creatives AS (
            SELECT
                campaign_geo(c.name) AS geo,
                a.name::text AS creative_name,
                a.id AS ad_id
            FROM public.ads a
            JOIN public.campaigns c ON c.id = a.campaign_id
            JOIN public.ad_sets s ON s.id = a.ad_set_id
            JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
            WHERE aa.bm_id IN (" . implode(',', $placeholders) . ")
              AND COALESCE(c.status, '') != 'DELETED'
              AND COALESCE(s.status, '') != 'DELETED'
              AND COALESCE(a.status, '') != 'DELETED'
              AND a.name IS NOT NULL
              AND a.name <> ''
        ),
        creative_totals AS (
            SELECT geo, COUNT(DISTINCT creative_name) AS creatives_count
            FROM active_creatives
            WHERE geo ~ '^[A-Z]{2}$'
            GROUP BY geo
        ),
        creative_stats_30d AS (
            SELECT
                ac.geo,
                ac.creative_name,
                COALESCE(SUM(i.spend), 0) AS spend,
                COALESCE(SUM(i.revenue), 0) AS revenue
            FROM active_creatives ac
            JOIN public.insights_daily i ON i.ad_id = ac.ad_id
            WHERE i.date >= :creative_date_from
              AND i.date <= :creative_date_to
              AND ac.geo ~ '^[A-Z]{2}$'
            GROUP BY ac.geo, ac.creative_name
        ),
        successful_creatives AS (
            SELECT
                geo,
                COUNT(*) FILTER (
                    WHERE spend > 0
                      AND ((revenue - spend) / spend * 100.0) > 30
                ) AS successful_creatives_count
            FROM creative_stats_30d
            GROUP BY geo
        ),
        geo_summary AS (
            SELECT
                geo,
                COUNT(DISTINCT CASE WHEN COALESCE(account_status, 1) = 1 THEN campaign_id END) AS campaigns_total,
                COUNT(DISTINCT CASE WHEN is_active = 1 THEN campaign_id END) AS campaigns_active,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(spend) AS spend,
                SUM(delta) AS delta,
                SUM(leads) AS leads,
                SUM(regs) AS regs,
                SUM(deps) AS deps,
                SUM(revenue) AS revenue
            FROM base
            WHERE geo ~ '^[A-Z]{2}$'
            GROUP BY geo
        )
        SELECT
            gs.*,
            COALESCE(ct.creatives_count, 0) AS creatives_count,
            COALESCE(sc.successful_creatives_count, 0) AS successful_creatives_count
        FROM geo_summary gs
        LEFT JOIN creative_totals ct ON ct.geo = gs.geo
        LEFT JOIN successful_creatives sc ON sc.geo = gs.geo
        ORDER BY (COALESCE(gs.revenue, 0) - COALESCE(gs.spend, 0)) DESC, gs.geo ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params + [
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
        ':creative_date_from' => $creativeDateFrom,
        ':creative_date_to' => $creativeDateTo,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $spend = (float)$row['spend'];
        $revenue = (float)$row['revenue'];
        $profit = $revenue - $spend;
        $clicks = (int)$row['clicks'];
        $leads = (int)$row['leads'];
        return [
            'geo' => (string)$row['geo'],
            'campaigns_total' => (int)$row['campaigns_total'],
            'campaigns_active' => (int)$row['campaigns_active'],
            'creatives_count' => (int)$row['creatives_count'],
            'successful_creatives_count' => (int)$row['successful_creatives_count'],
            'impressions' => (int)$row['impressions'],
            'clicks' => $clicks,
            'spend' => round($spend, 2),
            'delta' => round((float)$row['delta'], 2),
            'leads' => $leads,
            'regs' => (int)$row['regs'],
            'deps' => (int)$row['deps'],
            'revenue' => round($revenue, 2),
            'profit' => round($profit, 2),
            'roi' => $spend > 0 ? round($profit / $spend * 100, 2) : 0.0,
            'c2l' => $clicks > 0 ? round($leads / $clicks * 100, 2) : 0.0,
        ];
    }, $rows);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campaign Builder - FB Ads</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--surface:#fff;--surface2:#f7f8fa;
  --border:#dddfe2;--border2:#ccd0d5;--border-light:#e6e9ee;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;
  --blue:#1877f2;--blue2:#166fe5;--blue-bg:#e7f0fd;
  --green:#31a24c;--green-bg:#e6f4ea;
  --red:#fa3e3e;--red-bg:#fce8e8;
  --orange:#f59e0b;--orange-bg:#fff3cd;
  --shadow:0 1px 2px rgba(0,0,0,.08),0 1px 8px rgba(0,0,0,.05);
  --r:10px;--r2:6px;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh;display:flex;flex-direction:column}
.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}

.page{flex:1;padding:20px;display:flex;flex-direction:column;gap:16px}
.hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
.hero-copy h1{font-size:24px;font-weight:800;letter-spacing:-.4px}
.hero-copy p{margin-top:6px;color:var(--text2);max-width:820px;line-height:1.45}
.hero-meta{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;border:1px solid var(--border);background:var(--surface);font-size:12px;font-weight:700;color:var(--text2)}

.layout{display:grid;grid-template-columns:minmax(340px,30%) minmax(0,1fr);gap:16px;align-items:start}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}
.panel-h{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--surface)}
.panel-h h2{font-size:15px;font-weight:800}
.panel-sub{font-size:12px;color:var(--text3)}
.panel-body{padding:14px 16px}

.geo-table{width:100%;border-collapse:collapse}
.geo-table th{padding:9px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;border-bottom:1px solid var(--border);background:var(--surface2)}
.geo-table td{padding:10px;border-bottom:1px solid var(--border-light);font-size:13px}
.geo-table tr:last-child td{border-bottom:none}
.geo-table tbody tr{cursor:pointer;transition:background .12s}
.geo-table tbody tr:hover td{background:#f7fbff}
.geo-table tbody tr.active td{background:#edf4ff}
.num{font-variant-numeric:tabular-nums}
.roi-pos{color:var(--green);font-weight:700}
.roi-neg{color:var(--red);font-weight:700}

.empty{padding:24px 12px;text-align:center;color:var(--text3)}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.form-row{display:flex;flex-direction:column;gap:5px}
.form-row.full{grid-column:1/-1}
.form-row label{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.3px}
.label-inline{display:flex;align-items:center;gap:6px;justify-content:flex-start}
.label-link{appearance:none;border:0;background:transparent;padding:0;color:var(--blue);font:inherit;font-weight:800;cursor:pointer;line-height:1}
.label-link:hover{text-decoration:underline}
.form-row input,.form-row select,.form-row textarea{width:100%;padding:9px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font:inherit;color:var(--text);background:var(--surface);outline:none}
.form-row textarea{min-height:88px;resize:vertical}
.form-row input:focus,.form-row select:focus,.form-row textarea:focus{border-color:var(--blue)}
.hint{font-size:11px;color:var(--text3)}
.toggles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.toggle{display:flex;align-items:center;gap:9px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--r2);background:var(--surface2)}
.toggle input{width:16px;height:16px;accent-color:var(--blue)}
.toggle label{font-size:13px;font-weight:600;color:var(--text)}
.section-block{margin-top:16px;border-top:1px solid var(--border);padding-top:16px}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.section-head h3{font-size:14px;font-weight:800}
.actions-inline{display:flex;gap:8px;flex-wrap:wrap}
.mini-btn{padding:5px 9px;border:1px solid var(--border);border-radius:999px;background:var(--surface2);cursor:pointer;font-size:12px;font-weight:700;color:var(--text2)}
.mini-btn:hover{border-color:var(--blue);color:var(--blue)}
.tab-strip{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.tab-btn{padding:7px 11px;border:1px solid var(--border);border-radius:999px;background:var(--surface2);cursor:pointer;font-size:12px;font-weight:800;color:var(--text2)}
.tab-btn.active{background:var(--blue-bg);border-color:#bfd6fb;color:var(--blue)}
.creative-tools{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:10px}
.creative-search{min-width:220px;flex:1;max-width:360px;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font:inherit;font-size:13px;color:var(--text);background:var(--surface);outline:none}
.creative-search:focus{border-color:var(--blue)}

.check-list{display:flex;flex-direction:column;gap:8px;max-height:320px;overflow:auto;padding-right:2px}
.check-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface2)}
.check-item input{margin-top:2px;width:16px;height:16px;accent-color:var(--blue)}
.check-main{display:flex;flex-direction:column;gap:3px;min-width:0}
.check-title{font-size:13px;font-weight:700;color:var(--text)}
.check-meta{font-size:12px;color:var(--text2);display:flex;gap:10px;flex-wrap:wrap}
.account-item{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #dbe3ee;border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03);transition:border-color .12s, box-shadow .12s}
.account-item:hover{border-color:#bfd0ee;box-shadow:0 3px 10px rgba(24,119,242,.08)}
.account-item.checked{border-color:var(--blue);box-shadow:0 0 0 1px rgba(24,119,242,.18),0 4px 12px rgba(24,119,242,.12);background:#f7fbff}
.account-item input{margin-top:3px}
.account-item .check-title{font-size:14px}
.account-id{font-family:'SF Mono',Consolas,monospace;font-size:11px;color:var(--text3)}
.account-bm{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;background:#eef4ff;color:var(--blue);font-size:11px;font-weight:800;border:1px solid #d7e3fb}
.account-fbtool{font-size:11px;color:var(--text3)}
.account-sort-note{margin-top:8px;font-size:11px;color:var(--text3)}

.status-box{margin-top:14px;padding:10px 12px;border-radius:8px;font-size:13px;display:none}
.status-box.show{display:block}
.status-ok{background:var(--green-bg);color:var(--green)}
.status-err{background:var(--red-bg);color:var(--red)}
.status-warn{background:var(--orange-bg);color:#946200}
.submit-row{display:flex;justify-content:flex-end;gap:10px;align-items:center;margin-top:16px}
.submit-note{margin-right:auto;font-size:12px;color:var(--text3)}
.submit-btn{padding:10px 16px;border:none;border-radius:8px;background:var(--blue);color:#fff;font:inherit;font-weight:800;cursor:pointer}
.submit-btn:hover{background:var(--blue2)}
.submit-btn:disabled{opacity:.55;cursor:not-allowed}

@media(max-width:1100px){
  .layout{grid-template-columns:1fr}
}
@media(max-width:720px){
  .hero{flex-direction:column}
  .form-grid,.toggles{grid-template-columns:1fr}
}
</style>
</head>
<body>
<?php include __DIR__ . '/_header.php'; ?>

<div class="page">
  <div class="hero">
    <div class="hero-copy">
      <h1>Campaign Builder</h1>
      <p>Pick a geo from the last 7 days, review the top creatives for that geo, and queue one <code>create_campaign</code> task per selected cabinet that currently has no active campaign on the same geo.</p>
    </div>
    <div class="hero-meta">
      <span class="chip">Window: last 7 days</span>
      <span class="chip">Creatives: top by geo</span>
      <span class="chip">Cabinets: only without active geo campaigns</span>
    </div>
  </div>

  <div class="layout">
    <section class="panel">
      <div class="panel-h">
        <div>
          <h2>Geo Performance</h2>
          <div class="panel-sub">Click any geo to open the task form.</div>
        </div>
      </div>
      <div class="panel-body" style="padding:0">
        <table class="geo-table">
          <thead>
            <tr>
              <th>Geo</th>
              <th>Spend</th>
              <th>Profit</th>
              <th>ROI</th>
              <th>Deps</th>
              <th>Creo OK/Total</th>
              <th>Campaigns</th>
            </tr>
          </thead>
          <tbody id="geoBody">
            <?php if ($campaignBuilderSummaryRows): ?>
              <?php
                $selectedGeo = strtoupper(trim((string)($_GET['geo'] ?? '')));
                foreach ($campaignBuilderSummaryRows as $row):
                    $roiClass = (float)$row['roi'] >= 0 ? 'roi-pos' : 'roi-neg';
              ?>
                <tr class="<?= $selectedGeo === $row['geo'] ? 'active' : '' ?>" data-geo="<?= htmlspecialchars($row['geo'], ENT_QUOTES) ?>">
                  <td><strong><?= htmlspecialchars($row['geo']) ?></strong></td>
                  <td class="num"><?= htmlspecialchars(fmtMoney((float)$row['spend'])) ?></td>
                  <td class="num <?= (float)$row['profit'] >= 0 ? 'roi-pos' : 'roi-neg' ?>"><?= htmlspecialchars(fmtMoney((float)$row['profit'])) ?></td>
                  <td class="num <?= $roiClass ?>"><?= htmlspecialchars(fmtPct((float)$row['roi'])) ?></td>
                  <td class="num"><?= htmlspecialchars(fmtNum((int)$row['deps'])) ?></td>
                  <td class="num"><span class="<?= (int)$row['successful_creatives_count'] > 0 ? 'roi-pos' : '' ?>"><?= htmlspecialchars(fmtNum((int)$row['successful_creatives_count'])) ?></span>/<?= htmlspecialchars(fmtNum((int)$row['creatives_count'])) ?></td>
                  <td class="num"><span class="<?= (int)$row['campaigns_active'] > 0 ? 'roi-pos' : '' ?>"><?= htmlspecialchars(fmtNum((int)$row['campaigns_active'])) ?></span>/<?= htmlspecialchars(fmtNum((int)$row['campaigns_total'])) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="empty">No 7-day geo data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel-h">
        <div>
          <h2 id="formTitle">Select a geo</h2>
          <div class="panel-sub" id="formSub">The builder will load creatives and eligible cabinets for the selected geo.</div>
        </div>
      </div>
      <div class="panel-body">
        <div id="formEmpty" class="empty">Choose a geo on the left to start building tasks.</div>

        <form id="builderForm" style="display:none" onsubmit="submitBuilder(event)">
          <div class="form-grid">
            <div class="form-row full">
              <label for="configId">Configuration</label>
              <select id="configId" onchange="onConfigChange()">
                <option value="">Select a configuration</option>
              </select>
              <div class="hint" id="configHint">Configurations come from Domains & FP for the selected geo.</div>
              <div class="actions-inline" style="margin-top:8px">
                <button type="button" class="mini-btn" onclick="openDomainsFpForGeo()">Open Domains & FP</button>
                <button type="button" class="mini-btn" onclick="createConfigHint()">How to create a config</button>
              </div>
            </div>
          </div>

          <div class="section-block">
            <div class="section-head">
              <h3>Launch Settings</h3>
            </div>
            <div class="form-grid">
              <div class="form-row">
                <label for="bidStrategy">Bid Strategy</label>
                <select id="bidStrategy" onchange="syncBidFields()">
                  <option value="bidcap">Bid cap</option>
                  <option value="costcap">Cost cap</option>
                  <option value="auto">Auto</option>
                </select>
              </div>
              <div class="form-row">
                <label for="bidAmount">Bid ($)</label>
                <input id="bidAmount" type="number" min="0.01" step="0.01">
              </div>
              <div class="form-row">
                <label for="adsetsNum" class="label-inline"><span>Ad Sets</span><button type="button" class="label-link" onclick="fillFieldWithSelectedCreatives('adsetsNum')">+</button></label>
                <input id="adsetsNum" type="number" min="1" step="1" value="1">
              </div>
              <div class="form-row">
                <label for="adsNum" class="label-inline"><span>Ads per Ad Set</span><button type="button" class="label-link" onclick="fillFieldWithSelectedCreatives('adsNum')">+</button></label>
                <input id="adsNum" type="number" min="1" step="1">
              </div>
              <div class="form-row">
                <label for="bidSpreadPct">Bid Spread (%)</label>
                <input id="bidSpreadPct" type="number" min="0" max="100" step="1">
              </div>
              <div class="form-row">
                <label for="dailyBudget">Daily Budget ($)</label>
                <input id="dailyBudget" type="number" min="1" step="0.01">
              </div>
            </div>
            <div class="toggles" style="margin-top:12px">
              <div class="toggle"><input id="randomBidCap" type="checkbox"><label for="randomBidCap">Random bid cap</label></div>
              <div class="toggle"><input id="useLanguages" type="checkbox"><label for="useLanguages">Use languages</label></div>
              <div class="toggle"><input id="useTargetGeos" type="checkbox"><label for="useTargetGeos">Use target geos</label></div>
              <div class="toggle"><input id="noText" type="checkbox" onchange="syncTextFields()"><label for="noText">No text</label></div>
            </div>
            <div class="hint" id="targetGeosHint" style="margin-top:8px"></div>
          </div>

          <div class="section-block">
            <div class="section-head">
              <h3>Delivery IDs</h3>
            </div>
            <div class="form-grid">
              <div class="form-row">
                <label for="pageId">Page ID</label>
                <input id="pageId" type="text" placeholder="Facebook Page ID">
                <div class="hint">Loaded from the selected configuration. You can override it here.</div>
              </div>
              <div class="form-row">
                <label for="pixelMode">Pixel Mode</label>
                <select id="pixelMode" onchange="syncPixelFields()">
                  <option value="auto">Auto - any available pixel on account</option>
                  <option value="manual">Manual pixel</option>
                </select>
                <div class="hint" id="pixelModeHint">Auto mode keeps the placeholder and lets the worker resolve a pixel from the account.</div>
              </div>
              <div class="form-row">
                <label for="pixelId">Pixel ID</label>
                <input id="pixelId" type="text" placeholder="Facebook Pixel ID" oninput="syncResolvedUrlParams()">
                <div class="hint" id="pixelHint">Loaded from the selected configuration. You can override it here.</div>
              </div>
            </div>
          </div>

          <div class="section-block">
            <div class="section-head">
              <h3>Creative Selection</h3>
              <div class="actions-inline">
                <button type="button" class="mini-btn" onclick="setCreatives(true)">Select all</button>
                <button type="button" class="mini-btn" onclick="setCreatives(false)">Clear</button>
              </div>
            </div>
            <div class="tab-strip">
              <button type="button" id="creativeTabTop" class="tab-btn active" onclick="setCreativeTab('top')">Top creatives</button>
              <button type="button" id="creativeTabNew" class="tab-btn" onclick="setCreativeTab('new')">New creatives</button>
            </div>
            <div class="creative-tools">
              <input id="creativeSearch" class="creative-search" type="search" placeholder="Search creatives" oninput="setCreativeSearch(this.value)">
            </div>
            <div id="creativesWrap" class="check-list"></div>
          </div>

          <div class="section-block">
            <div class="section-head">
              <h3>Cabinets</h3>
              <div class="actions-inline">
                <button type="button" class="mini-btn" onclick="setAccounts(true)">Select all</button>
                <button type="button" class="mini-btn" onclick="setAccounts(false)">Clear</button>
              </div>
            </div>
            <div id="accountsWrap" class="check-list"></div>
            <div class="account-sort-note" id="accountsHint"></div>
          </div>

          <div class="section-block">
            <div class="section-head">
              <h3>Text Options</h3>
            </div>
            <div class="form-grid">
              <div class="form-row">
                <label for="approach">Approach</label>
                <input id="approach" type="text" placeholder="rtp98">
              </div>
              <div class="form-row">
                <label for="textGeo">Text Language</label>
                <input id="textGeo" type="text" maxlength="2" placeholder="Campaign GEO" oninput="this.value=this.value.toUpperCase()">
              </div>
            </div>
          </div>

          <div class="section-block">
            <div class="section-head">
              <h3>URL Params</h3>
            </div>
            <div class="form-grid">
              <div class="form-row full">
                <label for="urlParams">URL Params Template</label>
                <textarea id="urlParams" oninput="syncResolvedUrlParams()"></textarea>
                <div class="hint">Use <code>{pixel}</code> to inject the selected pixel ID.</div>
              </div>
              <div class="form-row full">
                <label for="resolvedUrlParams">Resolved URL Params</label>
                <textarea id="resolvedUrlParams" readonly></textarea>
                <div class="hint">This is the final value that will be sent to the task queue.</div>
              </div>
            </div>
          </div>

          <div id="formStatus" class="status-box"></div>

          <div class="submit-row">
            <div class="submit-note" id="submitNote">Select at least one creative and one cabinet.</div>
            <button id="submitBtn" class="submit-btn" type="submit">Create launch tasks</button>
          </div>
        </form>
      </div>
    </section>
  </div>
</div>

<script>
const DEFAULT_URL_PARAMS = <?= json_encode(CAMPAIGN_BUILDER_DEFAULT_URL_PARAMS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const state = {
  selectedGeo: '',
  summaryRows: <?= json_encode($campaignBuilderSummaryRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_BIGINT_AS_STRING) ?>,
  defaults: {},
  urlParamsTemplate: '',
  creatives: [],
  selectedCreatives: new Set(),
  creativeTab: 'top',
  creativeSearch: '',
  accounts: [],
  configs: [],
  currentConfig: null,
};

async function fetchJson(url, options, timeoutMs = 12000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, {...(options || {}), signal: controller.signal});
    const text = await res.text();
    let json = null;
    try { json = text ? JSON.parse(text) : null; } catch {}
    if (!res.ok || !json?.ok) {
      const msg = json?.error || `HTTP ${res.status}${text ? ' | ' + text.slice(0, 200) : ''}`;
      throw new Error(msg);
    }
    return json;
  } catch (e) {
    if (e?.name === 'AbortError') {
      throw new Error('Request timed out while loading data.');
    }
    throw e;
  } finally {
    clearTimeout(timer);
  }
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function fmtMoney(v) {
  const n = Number(v || 0);
  return '$' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function fmtNum(v) {
  return Number(v || 0).toLocaleString('en-US');
}

function fmtPct(v) {
  return Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%';
}

function statusBox(type, text) {
  const box = document.getElementById('formStatus');
  box.className = `status-box show status-${type}`;
  box.textContent = text;
}

function clearStatusBox() {
  const box = document.getElementById('formStatus');
  box.className = 'status-box';
  box.textContent = '';
}

function getUrlParamsTemplate() {
  const raw = String(document.getElementById('urlParams')?.value || '').trim();
  return raw || state.urlParamsTemplate || DEFAULT_URL_PARAMS;
}

function syncResolvedUrlParams() {
  const template = getUrlParamsTemplate();
  const mode = document.getElementById('pixelMode')?.value || 'auto';
  const pixelId = String(document.getElementById('pixelId')?.value || '').trim();
  const resolved = mode === 'manual' && pixelId
    ? template.replace(/\{pixel\}/g, pixelId)
    : template;
  const el = document.getElementById('resolvedUrlParams');
  if (el) el.value = resolved;
  syncSubmitState();
  return resolved;
}

function syncPixelFields() {
  const mode = document.getElementById('pixelMode')?.value || 'auto';
  const pixelEl = document.getElementById('pixelId');
  const pixelModeHint = document.getElementById('pixelModeHint');
  const pixelHint = document.getElementById('pixelHint');
  const isManual = mode === 'manual';
  if (pixelEl) {
    pixelEl.disabled = !isManual;
    pixelEl.style.opacity = isManual ? '1' : '0.55';
  }
  if (pixelModeHint) {
    pixelModeHint.textContent = isManual
      ? 'Manual mode writes the selected pixel ID into the resolved URL Params.'
      : 'Auto mode keeps {pixel} in the URL Params and lets the worker resolve any available pixel on the account.';
  }
  if (pixelHint) {
    pixelHint.textContent = isManual
      ? 'Type a pixel ID here. The resolved URL Params below will use this value.'
      : 'Leave this disabled if you want the worker to choose any available pixel on the account.';
  }
  syncResolvedUrlParams();
}

async function loadSummary() {
  const json = await fetchJson('/api/campaign_builder?action=summary');
  state.summaryRows = json.data.rows || [];
  renderSummary();
  const urlGeo = new URLSearchParams(location.search).get('geo');
  if (urlGeo && state.summaryRows.some(r => r.geo === urlGeo)) {
    await loadGeo(urlGeo);
  }
}

function renderSummary() {
  const body = document.getElementById('geoBody');
  if (!state.summaryRows.length) {
    body.innerHTML = '<tr><td colspan="7" class="empty">No 7-day geo data</td></tr>';
    return;
  }
  body.innerHTML = state.summaryRows.map(row => {
    const roiClass = Number(row.roi || 0) >= 0 ? 'roi-pos' : 'roi-neg';
    return `
      <tr class="${state.selectedGeo === row.geo ? 'active' : ''}" onclick="loadGeo('${esc(row.geo)}')">
        <td><strong>${esc(row.geo)}</strong></td>
        <td class="num">${fmtMoney(row.spend)}</td>
        <td class="num ${Number(row.profit || 0) >= 0 ? 'roi-pos' : 'roi-neg'}">${fmtMoney(row.profit)}</td>
        <td class="num ${roiClass}">${fmtPct(row.roi)}</td>
        <td class="num">${fmtNum(row.deps)}</td>
        <td class="num"><span class="${Number(row.successful_creatives_count || 0) > 0 ? 'roi-pos' : ''}">${fmtNum(row.successful_creatives_count)}</span>/${fmtNum(row.creatives_count)}</td>
        <td class="num"><span class="${Number(row.campaigns_active || 0) > 0 ? 'roi-pos' : ''}">${fmtNum(row.campaigns_active)}</span>/${fmtNum(row.campaigns_total)}</td>
      </tr>
    `;
  }).join('');
}

async function loadGeo(geo) {
  clearStatusBox();
  state.selectedGeo = geo;
  renderSummary();
  const title = document.getElementById('formTitle');
  const sub = document.getElementById('formSub');
  title.textContent = `Loading ${geo}...`;
  sub.textContent = 'Fetching creatives and eligible cabinets.';
  document.getElementById('formEmpty').style.display = 'block';
  document.getElementById('builderForm').style.display = 'none';
  try {
    const json = await fetchJson('/api/campaign_builder?action=geo&geo=' + encodeURIComponent(geo));
    state.defaults = json.data.defaults || {};
    state.creatives = json.data.creatives || [];
    state.selectedCreatives = new Set();
    state.creativeTab = 'top';
    state.creativeSearch = '';
    state.configs = json.data.configs || [];
    state.accounts = [];
    state.currentConfig = null;
    history.replaceState(null, '', '/campaign_builder.php?geo=' + encodeURIComponent(geo));
    fillForm();
  } catch (e) {
    title.textContent = `Failed to load ${geo}`;
    sub.textContent = 'The builder could not fetch creatives or eligible cabinets.';
    document.getElementById('formEmpty').style.display = 'block';
    document.getElementById('formEmpty').textContent = e.message || 'Load failed';
    document.getElementById('builderForm').style.display = 'none';
  }
}

function fillForm() {
  const geo = state.selectedGeo;
  const defaults = state.defaults || {};
  state.urlParamsTemplate = defaults.url_params || DEFAULT_URL_PARAMS;
  document.getElementById('formTitle').textContent = `Build launch tasks for ${geo}`;
  document.getElementById('formSub').textContent = `Last 30 days | ${state.creatives.length} creatives | ${state.accounts.length} eligible cabinets`;
  document.getElementById('formEmpty').style.display = 'none';
  document.getElementById('builderForm').style.display = 'block';

  document.getElementById('urlParams').value = state.urlParamsTemplate;
  document.getElementById('dailyBudget').value = defaults.daily_budget ?? 10;
  document.getElementById('bidAmount').value = defaults.bid_amount ?? 1;
  document.getElementById('bidStrategy').value = defaults.bid_strategy_mode || 'bidcap';
  document.getElementById('adsetsNum').value = defaults.adsets_num ?? 1;
  document.getElementById('adsNum').value = defaults.ads_num ?? 1;
  document.getElementById('approach').value = defaults.approach || 'rtp98';
  document.getElementById('textGeo').value = defaults.text_geo || '';
  document.getElementById('pageId').value = defaults.page_id || '';
  document.getElementById('pixelMode').value = defaults.pixel_id ? 'manual' : 'auto';
  document.getElementById('pixelId').value = defaults.pixel_id || '';
  document.getElementById('bidSpreadPct').value = defaults.bid_spread_pct ?? 20;
  document.getElementById('useLanguages').checked = !!defaults.use_languages;
  document.getElementById('useTargetGeos').checked = !!defaults.use_target_geos;
  document.getElementById('noText').checked = !!defaults.no_text;
  document.getElementById('randomBidCap').checked = !!defaults.random_bid_cap;
  document.getElementById('targetGeosHint').textContent = (defaults.target_geos || []).length
    ? 'Target geos from rule: ' + defaults.target_geos.join(', ')
    : 'No target geo override in geo rules.';

  fillConfigs();
  renderCreatives();
  renderAccounts();
  syncBidFields();
  syncTextFields();
  syncPixelFields();
  syncSubmitState();
}

function fillConfigs() {
  const sel = document.getElementById('configId');
  const hint = document.getElementById('configHint');
  const rows = state.configs || [];
  sel.innerHTML = '<option value="">Select a configuration</option>' + rows.map(cfg =>
    `<option value="${cfg.id}">${esc(cfg.title)}${cfg.bm_id ? ' (' + esc(cfg.bm_id) + ')' : ''} | ${fmtNum(cfg.accounts_count || 0)} free cabinet${Number(cfg.accounts_count || 0) === 1 ? '' : 's'}</option>`
  ).join('');
  sel.disabled = !rows.length;
  hint.textContent = rows.length
    ? `${rows.length} configuration${rows.length === 1 ? '' : 's'} available for ${state.selectedGeo}. Each option shows free cabinets for that BM on this geo.`
    : 'No active configurations found for this geo. Create one in Domains & FP, then return here.';
  if (!rows.length) {
    document.getElementById('formEmpty').style.display = 'block';
    document.getElementById('formEmpty').innerHTML = `
      <div style="display:flex;flex-direction:column;gap:10px;align-items:center;justify-content:center">
        <div>No configurations exist for ${esc(state.selectedGeo)} yet.</div>
        <div style="max-width:560px;color:var(--text3);line-height:1.5">
          Create a record in Domains & FP with the BM, GEO, landing domain, fan page name, Page ID, and Pixel ID.
          After that, the cabinets for that configuration will appear here automatically.
        </div>
        <div class="actions-inline" style="justify-content:center">
          <button type="button" class="mini-btn" onclick="openDomainsFpForGeo()">Open Domains & FP</button>
        </div>
      </div>`;
  } else {
    document.getElementById('formEmpty').style.display = 'none';
  }
}

async function onConfigChange() {
  clearStatusBox();
  const configId = Number(document.getElementById('configId').value || 0);
  state.currentConfig = null;
  state.accounts = [];
  if (!configId) {
    document.getElementById('configHint').textContent = 'Configurations come from Domains & FP for the selected geo.';
    document.getElementById('pixelMode').value = 'auto';
    document.getElementById('pageId').value = '';
    document.getElementById('pixelId').value = '';
    syncPixelFields();
    renderAccounts();
    syncSubmitState();
    return;
  }
  const json = await fetchJson('/api/campaign_builder?action=config&config_id=' + encodeURIComponent(configId));
  state.currentConfig = json.data.config || null;
  state.accounts = json.data.accounts || [];
  if (state.currentConfig) {
    document.getElementById('configHint').textContent =
      `Selected: ${state.currentConfig.domain} | ${state.currentConfig.fp_name} | ${state.currentConfig.bm_name || state.currentConfig.bm_id}` +
      `${state.currentConfig.bm_id ? ' | BM ' + state.currentConfig.bm_id : ''}` +
      `${state.currentConfig.page_id ? ' | Page ' + state.currentConfig.page_id : ''}` +
      `${state.currentConfig.pixel_id ? ' | Pixel ' + state.currentConfig.pixel_id : ''}` +
      ` | ${fmtNum(state.accounts.length)} free cabinet${state.accounts.length === 1 ? '' : 's'}`;
    document.getElementById('pageId').value = state.currentConfig.page_id || '';
    document.getElementById('pixelMode').value = state.currentConfig.pixel_id ? 'manual' : 'auto';
    document.getElementById('pixelId').value = state.currentConfig.pixel_id || '';
  }
  renderAccounts();
  syncPixelFields();
  syncSubmitState();
}

function renderCreatives() {
  syncCreativeTabButtons();
  const search = document.getElementById('creativeSearch');
  if (search && search.value !== state.creativeSearch) search.value = state.creativeSearch;
  const wrap = document.getElementById('creativesWrap');
  if (!state.creatives.length) {
    wrap.innerHTML = '<div class="empty">No creatives found for this geo.</div>';
    return;
  }
  const rows = getVisibleCreatives();
  if (!rows.length) {
    wrap.innerHTML = '<div class="empty">No creatives match the search.</div>';
    return;
  }
  wrap.innerHTML = rows.map(row => `
    <label class="check-item">
      <input type="checkbox" class="creative-cb" value="${esc(row.creative_name)}" ${state.selectedCreatives.has(String(row.creative_name || '')) ? 'checked' : ''} onchange="toggleCreativeSelection(this)">
      <div class="check-main">
        <div class="check-title">${row.rank ? `#${esc(row.rank)} ` : ''}${esc(row.creative_name)}</div>
        <div class="check-meta">
          <span>Ads ${fmtNum(row.ads_count)}</span>
          ${creativeCreatedLabel(row) ? `<span>Created ${esc(creativeCreatedLabel(row))}</span>` : ''}
          <span>Spend ${fmtMoney(row.spend)}</span>
          <span>Profit ${fmtMoney(row.profit)}</span>
          <span>ROI ${fmtPct(row.roi)}</span>
          <span>Deps ${fmtNum(row.deps)}</span>
          <span>C2L ${fmtPct(row.c2l)}</span>
        </div>
      </div>
    </label>
  `).join('');
}

function creativeCreatedSortValue(row) {
  const createdAt = String(row?.created_at || '').trim();
  if (createdAt) return createdAt;
  const launchDate = String(row?.launch_date || '').trim();
  if (launchDate) return `${launchDate}T00:00:00`;
  const lastSeen = String(row?.last_seen || '').trim();
  if (lastSeen) return `${lastSeen}T00:00:00`;
  return '';
}

function creativeCreatedLabel(row) {
  const createdAt = String(row?.created_at || '').trim();
  if (createdAt) return createdAt.slice(0, 10);
  const launchDate = String(row?.launch_date || '').trim();
  if (launchDate) return launchDate;
  return '';
}

function getVisibleCreatives() {
  const q = String(state.creativeSearch || '').trim().toLowerCase();
  const rows = [...(state.creatives || [])].filter(row => !q || String(row.creative_name || '').toLowerCase().includes(q));
  if (state.creativeTab === 'new') {
    return rows.sort((a, b) =>
      String(creativeCreatedSortValue(b)).localeCompare(String(creativeCreatedSortValue(a))) ||
      String(b.last_seen || '').localeCompare(String(a.last_seen || '')) ||
      String(a.creative_name || '').localeCompare(String(b.creative_name || ''))
    );
  }
  return rows.sort((a, b) => {
    const rankA = creativeRankSortValue(a);
    const rankB = creativeRankSortValue(b);
    return rankA - rankB
      || Number(b.rank_score || 0) - Number(a.rank_score || 0)
      || Number(b.profit || 0) - Number(a.profit || 0)
      || String(a.creative_name || '').localeCompare(String(b.creative_name || ''));
    });
}

function creativeRankSortValue(row) {
  const raw = row?.rank;
  if (raw === null || raw === undefined || raw === '') return Number.MAX_SAFE_INTEGER;
  const rank = Number(raw);
  return Number.isFinite(rank) && rank > 0 ? rank : Number.MAX_SAFE_INTEGER;
}

function setCreativeSearch(value) {
  state.creativeSearch = String(value || '');
  renderCreatives();
  syncSubmitState();
}

function syncCreativeTabButtons() {
  document.getElementById('creativeTabTop')?.classList.toggle('active', state.creativeTab === 'top');
  document.getElementById('creativeTabNew')?.classList.toggle('active', state.creativeTab === 'new');
}

function setCreativeTab(tab) {
  state.creativeTab = tab === 'new' ? 'new' : 'top';
  renderCreatives();
  syncSubmitState();
}

function toggleCreativeSelection(input) {
  const value = String(input?.value || '');
  if (!value) return;
  if (input.checked) state.selectedCreatives.add(value);
  else state.selectedCreatives.delete(value);
  syncSubmitState();
}

function renderAccounts() {
  const wrap = document.getElementById('accountsWrap');
  const hint = document.getElementById('accountsHint');
  if (!state.currentConfig) {
    wrap.innerHTML = '<div class="empty">Select a configuration to load cabinets for its BM. If none exist, create one in Domains & FP first.</div>';
    hint.textContent = '';
    return;
  }
  if (!state.accounts.length) {
    wrap.innerHTML = '<div class="empty">No cabinets are currently free for this config BM and geo.</div>';
    hint.textContent = '';
    return;
  }
  const rows = [...state.accounts].sort((a, b) =>
    Number(a.active_campaigns || 0) - Number(b.active_campaigns || 0) ||
    String(a.bm_name).localeCompare(String(b.bm_name)) ||
    String(a.account_name).localeCompare(String(b.account_name)) ||
    String(a.account_id).localeCompare(String(b.account_id))
  );
  wrap.innerHTML = rows.map(row => `
    <label class="check-item account-item">
      <input type="checkbox" class="account-cb" value="${esc(row.account_id)}" onchange="syncSubmitState()">
      <div class="check-main">
        <div class="check-title">${esc(row.account_name)}</div>
        <div class="account-id">${esc(row.account_id)}</div>
        <div class="check-meta">
          <span class="account-bm">${esc(row.bm_name)}</span>
          <span>Active campaigns ${fmtNum(row.active_campaigns || 0)}</span>
          ${row.fbtool_id ? `<span class="account-fbtool">FBTool ${esc(row.fbtool_id)}</span>` : ''}
        </div>
      </div>
    </label>
  `).join('');
  hint.textContent = `Showing ${rows.length} cabinet${rows.length === 1 ? '' : 's'} for ${state.currentConfig.bm_name}.`;
}

function setCreatives(checked) {
  document.querySelectorAll('.creative-cb').forEach(el => {
    el.checked = checked;
    const value = String(el.value || '');
    if (!value) return;
    if (checked) state.selectedCreatives.add(value);
    else state.selectedCreatives.delete(value);
  });
  syncSubmitState();
}

function setAccounts(checked) {
  document.querySelectorAll('.account-cb').forEach(el => { el.checked = checked; });
  syncSubmitState();
}

function fillFieldWithSelectedCreatives(fieldId) {
  const el = document.getElementById(fieldId);
  if (!el) return;
  el.value = Math.max(1, state.selectedCreatives.size || 0);
}

function syncBidFields() {
  const mode = document.getElementById('bidStrategy').value;
  const bid = document.getElementById('bidAmount');
  bid.disabled = mode === 'auto';
  bid.style.opacity = mode === 'auto' ? '0.55' : '1';
}

function syncTextFields() {
  const noText = document.getElementById('noText').checked;
  for (const id of ['approach', 'textGeo']) {
    const el = document.getElementById(id);
    el.disabled = noText;
    el.style.opacity = noText ? '0.55' : '1';
  }
}

function selectedValues(selector) {
  return Array.from(document.querySelectorAll(selector)).filter(el => el.checked).map(el => el.value);
}

function syncSubmitState() {
  const creativesCount = state.selectedCreatives.size;
  const accountsCount = selectedValues('.account-cb').length;
  const pixelMode = document.getElementById('pixelMode')?.value || 'auto';
  const pixelId = String(document.getElementById('pixelId')?.value || '').trim();
  document.querySelectorAll('.account-item').forEach(el => {
    const cb = el.querySelector('.account-cb');
    el.classList.toggle('checked', !!cb?.checked);
  });
  const btn = document.getElementById('submitBtn');
  btn.disabled = !state.selectedGeo || !state.currentConfig || creativesCount === 0 || accountsCount === 0 || (pixelMode === 'manual' && !pixelId);
  document.getElementById('submitNote').textContent = `${creativesCount} creatives selected | ${accountsCount} cabinets selected`;
}

async function submitBuilder(event) {
  event.preventDefault();
  clearStatusBox();
  const accountIds = selectedValues('.account-cb');
  const creativeNames = Array.from(state.selectedCreatives);
  const body = {
    action: 'create',
    geo: state.selectedGeo,
    config_id: Number(document.getElementById('configId').value || 0),
    account_ids: accountIds,
    creative_names: creativeNames,
    url_params: document.getElementById('resolvedUrlParams').value.trim() || syncResolvedUrlParams(),
    page_id: document.getElementById('pageId').value.trim(),
    pixel_id: document.getElementById('pixelMode').value === 'manual' ? document.getElementById('pixelId').value.trim() : '',
    pixel_mode: document.getElementById('pixelMode').value,
    daily_budget: document.getElementById('dailyBudget').value,
    bid_amount: document.getElementById('bidAmount').value,
    bid_strategy_mode: document.getElementById('bidStrategy').value,
    adsets_num: document.getElementById('adsetsNum').value,
    ads_num: document.getElementById('adsNum').value,
    approach: document.getElementById('approach').value.trim(),
    text_geo: document.getElementById('textGeo').value.trim().toUpperCase(),
    bid_spread_pct: document.getElementById('bidSpreadPct').value,
    use_languages: document.getElementById('useLanguages').checked,
    use_target_geos: document.getElementById('useTargetGeos').checked,
    no_text: document.getElementById('noText').checked,
    random_bid_cap: document.getElementById('randomBidCap').checked,
  };

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = 'Creating...';
  try {
    const json = await fetchJson('/api/campaign_builder', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body),
    });
    const created = json.data.created || [];
    const skipped = json.data.skipped_account_ids || [];
    const parts = [`Created ${created.length} task${created.length === 1 ? '' : 's'}`];
    if (created.length) parts.push('IDs: ' + created.map(r => '#' + r.id).join(', '));
    if (skipped.length) parts.push('Skipped: ' + skipped.join(', '));
    statusBox(skipped.length ? 'warn' : 'ok', parts.join(' | '));
  } catch (e) {
    statusBox('err', e.message || 'Task creation failed');
  } finally {
    btn.textContent = 'Create launch tasks';
    syncSubmitState();
  }
}

function openDomainsFpForGeo() {
  const geo = encodeURIComponent(state.selectedGeo || '');
  const url = geo ? `/domains.php?status=active&geo=${geo}` : '/domains.php';
  window.open(url, '_blank', 'noopener');
}

function createConfigHint() {
  alert('Open Domains & FP, click Add, and create a record for this GEO. Fill BM, landing domain, fan page name, Page ID, and Pixel ID there.');
}

function showCampaignBuilderLoadError(message) {
  const body = document.getElementById('geoBody');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="7" class="empty">${esc(message || 'Load failed')}</td></tr>`;
}

function startCampaignBuilder() {
  loadSummary().catch(err => {
    console.warn('Campaign Builder summary refresh failed:', err);
  });
}

function initCampaignBuilderGeoClicks() {
  const body = document.getElementById('geoBody');
  if (!body || body.dataset.geoClicksBound === '1') return;
  body.dataset.geoClicksBound = '1';
  body.addEventListener('click', (event) => {
    const row = event.target.closest('tr[data-geo]');
    if (!row || !body.contains(row)) return;
    const geo = String(row.dataset.geo || '').trim();
    if (!geo) return;
    loadGeo(geo);
  });
}

window.addEventListener('error', (event) => {
  console.error('Campaign Builder runtime error:', event?.message || event);
});

window.addEventListener('unhandledrejection', (event) => {
  console.error('Campaign Builder unhandled rejection:', event?.reason);
});

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', startCampaignBuilder, { once: true });
} else {
  startCampaignBuilder();
}

initCampaignBuilderGeoClicks();

/* loadSummary().catch(err => {
  document.getElementById('geoBody').innerHTML = `<tr><td colspan="7" class="empty">${esc(err.message || 'Load failed')}</td></tr>`;
}); */
</script>
</body>
</html>
