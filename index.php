<?php
// index.php
// @version 1.4.440
require __DIR__.'/lib/DB.php';
require __DIR__.'/lib/Auth.php';
require __DIR__.'/lib/Timezone.php';

$db   = DB::getInstance();
$auth = new Auth($db);

// Auth check - redirect to login if not authenticated
$token = $_COOKIE['fb_ads_token'] ?? '';
if (!$token) { header('Location: /login.php'); exit; }
$me = $auth->check($token);
if (!$me)    {
    // Remove invalid cookie to avoid a redirect loop
    setcookie('fb_ads_token', '', ['expires' => time()-3600, 'path' => '/']);
    header('Location: /login.php');
    exit;
}

$cfg = require __DIR__.'/config/config.php';
$displayTz = appTimezoneName($me['display_tz'] ?? $cfg['display_tz'] ?? 'Europe/Kyiv');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FB Ads Dashboard</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--surface:#fff;
  --border:#dddfe2;--border2:#ccd0d5;--border-light:#e4e6eb;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;
  --blue:#1877f2;--blue2:#166fe5;--blue-bg:#e7f0fd;--blue-sel:#d0e5fe;
  --green:#31a24c;--green-bg:#e6f4ea;
  --red:#fa3e3e;--red-bg:#fce8e8;
  --orange:#e67e22;
  --toggle-on:#31a24c;--toggle-off:#bcc0c4;
  --shadow:0 1px 2px rgba(0,0,0,.08),0 1px 8px rgba(0,0,0,.05);
  --shadow-md:0 2px 16px rgba(0,0,0,.11);
  --r:8px;--r2:6px;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;height:100vh;display:flex;flex-direction:column;overflow:hidden}

/* TOP BAR */
.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2)}
.tb-updated{font-size:12px;color:var(--text3)}
.tb-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:600;color:var(--text2);transition:all .15s}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.tb-btn.primary:hover{background:var(--blue2)}
.tb-btn.alert-red{background:#d93025;border-color:#d93025;color:#fff}
.tb-btn.alert-red:hover{background:#b71c1c;border-color:#b71c1c}
#balancesModal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding-top:40px}
#balancesModal.open{display:flex}
#balancesModal .modal-box{background:var(--surface);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:min(1100px,96vw);max-height:85vh;display:flex;flex-direction:column}
#balancesModal .modal-hdr{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
#balancesModal .modal-hdr h3{margin:0;font-size:16px;font-weight:700}
#balancesModal .modal-body{overflow:auto;flex:1}
#balancesModal table{width:100%;border-collapse:collapse;min-width:700px}
#balancesModal th{position:sticky;top:0;background:var(--surface);padding:8px 12px;text-align:left;font-size:12px;font-weight:600;color:var(--text3);border-bottom:1px solid var(--border);white-space:nowrap}
#balancesModal th.r,#balancesModal td.r{text-align:right}
#balancesModal td{padding:8px 12px;font-size:13px;border-bottom:1px solid var(--border2);white-space:nowrap}
#balancesModal tr.alert-row td{background:#fff5f5}
#balancesModal tr:hover td{background:var(--bg)}
.bal-bar{height:5px;border-radius:3px;background:var(--bg);margin-top:3px;overflow:hidden}
.bal-bar-fill{height:100%;border-radius:3px;background:var(--green)}
.bal-bar-fill.warn{background:#f5a623}
.bal-bar-fill.danger{background:var(--red)}
.copy-flash td,.copy-flash{background:rgba(40,167,69,.25) !important;transition:background .1s}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}
.spinning{animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* LEVEL NAV */
.levelnav{min-height:44px;height:auto;border-bottom:1px solid var(--border);display:flex;align-items:center;background:var(--surface);flex-shrink:0;padding:8px 16px;gap:6px;flex-wrap:wrap;overflow-x:visible}
.lnav-item{display:flex;align-items:center;gap:6px;padding:0 14px;font-size:13px;font-weight:500;cursor:pointer;color:var(--text2);text-decoration:none;border-bottom:2.5px solid transparent;white-space:nowrap;transition:all .12s;user-select:none}
.lnav-item:hover{color:var(--text);background:var(--bg)}
.lnav-item.active{color:var(--blue);font-weight:700;border-bottom-color:var(--blue)}
.lnav-item svg{width:14px;height:14px;flex-shrink:0}
.lnav-arrow{color:var(--text3);font-size:14px;display:flex;align-items:center}
.lnav-badge{display:inline-flex;align-items:center;gap:4px;background:var(--blue);color:#fff;border-radius:10px;padding:2px 6px 2px 8px;font-size:11px;font-weight:700;margin-left:4px}
.lnav-badge-x{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:rgba(255,255,255,.25);font-size:10px;cursor:pointer;flex-shrink:0}
.lnav-badge-x:hover{background:rgba(255,255,255,.45)}
.lnav-right{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.lnav-daterange{display:flex;align-items:center;gap:5px;padding:4px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:13px;font-weight:600;color:var(--text2);cursor:pointer;background:var(--surface);transition:border-color .15s}
.lnav-daterange:hover{border-color:var(--blue);color:var(--blue)}
.tz-badge{font-size:13px;font-weight:500;color:var(--text2);padding:0 8px;white-space:nowrap}

/* FILTER TABS (status) */
.ftabs{min-height:44px;border-bottom:1px solid var(--border);display:flex;align-items:center;padding:6px 16px;gap:8px;flex-shrink:0;background:var(--surface);flex-wrap:wrap}
.ftab{display:flex;align-items:center;gap:5px;padding:4px 14px;border:1.5px solid var(--border);border-radius:20px;font-size:13px;font-weight:500;color:var(--text2);cursor:pointer;background:var(--surface);white-space:nowrap;transition:all .12s;user-select:none}
.ftab:hover{background:var(--bg);border-color:var(--blue2)}
.ftab.active{background:var(--blue-bg);border-color:var(--blue);color:var(--blue);font-weight:700}
.filter-selects{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
.filter-field{display:flex;align-items:center;gap:5px;min-width:0}
.filter-field label{font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;white-space:nowrap}
.filter-select{height:30px;max-width:210px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:12px;font-weight:650;font-family:inherit;padding:0 8px;outline:none}
.filter-select.small{max-width:92px}
.filter-select:focus{border-color:var(--blue);box-shadow:0 0 0 2px var(--blue-bg)}
.filter-select:disabled{color:var(--text3);background:var(--bg);cursor:not-allowed}
.filter-reset{height:30px;border:1px solid var(--border);background:var(--surface);border-radius:6px;padding:0 10px;font-size:12px;font-weight:800;color:var(--red);cursor:pointer}
.filter-reset:hover{border-color:var(--red);background:var(--red-bg)}
.width-reset-btn{height:30px;border:1px solid var(--border);background:var(--surface);border-radius:6px;padding:0 9px;font-size:11px;font-weight:700;color:var(--text3);cursor:pointer;opacity:.75}
.width-reset-btn:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-bg);opacity:1}
.width-reset-btn.icon{width:30px;padding:0;display:inline-flex;align-items:center;justify-content:center}
.width-reset-btn.icon svg{width:13px;height:13px}
.width-balance-toggle{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 8px 0 9px;border:1px solid var(--border);border-radius:6px;background:var(--surface);cursor:pointer;opacity:.78;user-select:none}
.width-balance-toggle:hover{border-color:var(--blue);background:var(--blue-bg);opacity:1}
.width-balance-toggle input{position:absolute;opacity:0;pointer-events:none}
.width-balance-track{width:28px;height:16px;border-radius:999px;background:#cfd4da;position:relative;transition:background .15s}
.width-balance-knob{position:absolute;top:2px;left:2px;width:12px;height:12px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.18);transition:transform .15s}
.width-balance-toggle input:checked + .width-balance-track{background:var(--blue)}
.width-balance-toggle input:checked + .width-balance-track .width-balance-knob{transform:translateX(12px)}
.width-balance-label{font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
.width-balance-toggle:hover .width-balance-label{color:var(--blue)}
.delivery-badges{display:flex;align-items:center;gap:5px;margin-left:4px}
.delivery-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:var(--blue-bg);border:1px solid var(--blue);color:var(--blue);cursor:pointer;white-space:nowrap}
.delivery-badge:hover{background:var(--blue);color:#fff}
.delivery-badge-x{margin-left:2px;opacity:.7;font-size:12px}
.fright{margin-left:auto;display:flex;align-items:center;gap:6px}

/* SEARCH BAR */
.factive{height:40px;border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:8px;background:var(--surface);flex-shrink:0}
.factive-tag{display:flex;align-items:center;gap:4px;padding:3px 10px;background:var(--blue-bg);border:1px solid var(--blue);border-radius:var(--r2);font-size:12px;font-weight:600;color:var(--blue)}
.factive-tag button{background:none;border:none;cursor:pointer;color:var(--blue);font-size:12px;padding:0;margin-left:2px}
.factive-search{flex:1;border:none;outline:none;font-size:13px;color:var(--text);background:transparent;font-family:inherit}
.factive-search::placeholder{color:var(--text3)}
.factive-clear{background:none;border:none;font-size:12px;font-weight:600;color:var(--blue);cursor:pointer;font-family:inherit}

/* SUMMARY CARDS */
.cards-bar{
  display:flex;
  justify-content:center;
  align-items:stretch;
  gap:10px;
  padding:12px 16px;
  background:var(--bg);
  border-bottom:1px solid var(--border-light);
  flex-shrink:0;
  flex-wrap:wrap;
}
.card{background:var(--surface);border:1px solid var(--border-light);border-radius:var(--r);padding:12px 16px;box-shadow:var(--shadow);min-width:130px;transition:box-shadow .15s,transform .12s}
.card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
.card-lbl{font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.card-val{font-size:22px;font-weight:800;line-height:1.1;color:var(--text);font-variant-numeric:tabular-nums}
.card-val.g{color:var(--green)}.card-val.r{color:var(--red)}.card-val.b{color:var(--blue)}

/* FILTER TAGS BAR */
.gf-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:var(--blue-bg);border:1px solid #badeff;border-radius:20px;font-size:12px;font-weight:600;color:var(--blue);white-space:nowrap}
.gf-tag button{background:none;border:none;cursor:pointer;color:var(--blue);font-size:13px;padding:0;margin-left:2px;line-height:1;opacity:.7}
.gf-tag button:hover{opacity:1}
.gf-reset{background:none;border:none;font-size:12px;font-weight:600;color:var(--text3);cursor:pointer;padding:3px 6px;border-radius:var(--r2);transition:color .12s}
.gf-reset:hover{color:var(--red)}


.main{padding:16px 20px 24px;flex:1;overflow:hidden;display:flex;flex-direction:column;min-height:0}
.main-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);display:flex;flex-direction:column;flex:1;overflow:hidden;min-height:0}


.abtn{display:flex;align-items:center;gap:5px;padding:4px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:12px;font-weight:600;color:var(--text2);cursor:pointer;background:var(--surface);font-family:inherit;white-space:nowrap;transition:all .12s}
.abtn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.ab-sep{width:1px;height:20px;background:var(--border2);margin:0 2px}
.ab-right{margin-left:auto;display:flex;align-items:center;gap:6px}

/* BULK BAR */
.selbar{display:none;align-items:center;gap:8px;padding:0 16px;height:40px;background:var(--blue-sel);border-bottom:1px solid #aac8f5;flex-shrink:0}
.selbar.on{display:flex}
.sel-info{font-size:13px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:5px}
.sel-x{background:var(--blue);color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.sbtn{display:flex;align-items:center;gap:4px;padding:4px 12px;border:1.5px solid var(--border);border-radius:var(--r2);font-size:12px;font-weight:600;color:var(--text2);cursor:pointer;background:var(--surface);font-family:inherit;white-space:nowrap;transition:all .12s}
.sbtn:hover{background:var(--bg)}
.sel-right{margin-left:auto}

/* TABLE */
.tblwrap{flex:1;overflow:auto}
#creoWrap,#creativeCalendarWrap,#campsCalendarWrap,#geoWrap,#topcreoWrap,#streamsWrap,#offersWrap{width:100%}
#creoTbl,#creativeCalendarTbl,#campsCalendarTbl,#geoTbl,#topcreoTbl,#streamsTbl,#offersTbl{width:100%;min-width:100%}
#creoTbl table,#creativeCalendarTbl table,#campsCalendarTbl table,#geoTbl table,#topcreoTbl table,#streamsTbl table,#offersTbl table{width:100%}
.offers-grid{display:grid;grid-template-columns:minmax(420px,1.15fr) minmax(420px,.85fr);gap:0;height:100%;min-height:0}
.offers-left{border-right:1px solid var(--border);overflow:auto;min-height:0}
.offers-tabs{position:sticky;top:0;z-index:2;display:flex;gap:6px;padding:10px 12px;border-bottom:1px solid var(--border);background:var(--surface);overflow-x:auto}
.offers-tab{border:1px solid var(--border);background:var(--surface);color:var(--text2);border-radius:var(--r2);padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.offers-tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
.streams-head{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid var(--border);background:#fbfcfd;flex-shrink:0}
.streams-toolbar{position:sticky;top:0;z-index:2;display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--border);background:var(--surface);flex-shrink:0}
.streams-toolbar label{font-size:12px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
.streams-select{min-width:240px;max-width:420px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);color:var(--text);padding:6px 10px;font:inherit;font-size:13px;font-weight:700}
.streams-select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 2px var(--blue-bg)}
.streams-title{font-size:14px;font-weight:800;color:var(--text)}
.streams-sub{font-size:12px;color:var(--text3)}
.rules-verdict{display:inline-flex;align-items:center;max-width:120px;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:850;text-transform:uppercase;background:var(--bg);border:1px solid var(--border);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:help}
.rules-verdict.stop,.rules-verdict.pause,.rules-verdict.pause_today,.rules-verdict.hold_stop,.rules-verdict.manual_stop{color:var(--red);background:var(--red-bg);border-color:#fecaca}
.rules-verdict.start,.rules-verdict.restart_candidate,.rules-verdict.protect,.rules-verdict.ok{color:var(--green);background:var(--green-bg);border-color:#bbf7d0}
.rules-verdict.watch,.rules-verdict.start_delayed,.rules-verdict.no_geo,.rules-verdict.no_rules,.rules-verdict.ignored_status,.rules-verdict.no_data{color:var(--text2);background:#f4f5f7;border-color:var(--border)}
.bid-cell{display:flex;align-items:center;justify-content:flex-end;gap:6px}
.bid-value{font-weight:800;color:var(--text)}
.bid-edit-btn{width:24px;height:24px;border:1px solid var(--border);background:var(--surface);color:var(--blue);border-radius:6px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0}
.bid-edit-btn:hover{background:var(--blue-bg);border-color:var(--blue)}
.bid-edit-btn svg{width:13px;height:13px}
.campaign-inline-actions{display:inline-flex;align-items:center;gap:6px;margin-left:8px}
.campaign-delete-btn{width:24px;height:24px;border:1px solid #f3c2c2;background:#fff6f6;color:var(--red);border-radius:6px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0}
.campaign-delete-btn:hover{background:var(--red-bg);border-color:#f1a6a6}
.campaign-delete-btn:disabled{opacity:.55;cursor:not-allowed}
.campaign-delete-btn svg{width:13px;height:13px}
#adsetBidModal{display:none;position:fixed;inset:0;z-index:1200;background:rgba(0,0,0,.46);align-items:center;justify-content:center;padding:24px}
#adsetBidModal.open{display:flex}
#adsetBidModal .modal-box{width:min(420px,96vw);background:var(--surface);border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden}
#adsetBidModal .modal-hdr{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
#adsetBidModal .modal-hdr h3{margin:0;font-size:15px;font-weight:800}
#adsetBidModal .modal-body{padding:14px 16px;display:flex;flex-direction:column;gap:12px}
.bid-current{font-size:12px;color:var(--text3)}
.bid-current strong{display:block;font-size:14px;color:var(--text);margin-top:4px}
.bid-input{width:100%;height:36px;border:1.5px solid var(--border);border-radius:8px;padding:0 12px;font-size:14px;font-family:inherit;color:var(--text);background:var(--surface)}
.bid-input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 2px var(--blue-bg)}
.bid-step-row{display:flex;gap:8px;flex-wrap:wrap}
.bid-step-btn{height:30px;padding:0 10px;border:1px solid var(--border);background:var(--bg);border-radius:8px;font-size:12px;font-weight:800;color:var(--text2);cursor:pointer}
.bid-step-btn:hover{border-color:var(--blue);background:var(--blue-bg);color:var(--blue)}
.bid-step-btn:disabled{opacity:.5;cursor:not-allowed}
.bid-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:2px}
.bid-save-btn{padding:6px 12px;border:1px solid var(--blue);background:var(--blue);color:#fff;border-radius:8px;font-size:13px;font-weight:800;cursor:pointer}
.bid-save-btn:hover{background:var(--blue2)}
.bid-save-btn:disabled{opacity:.55;cursor:wait}
.bid-cancel-btn{padding:6px 12px;border:1px solid var(--border);background:var(--surface);color:var(--text2);border-radius:8px;font-size:13px;font-weight:700;cursor:pointer}
.bid-cancel-btn:hover{border-color:var(--blue);color:var(--blue)}
.bid-help{font-size:12px;color:var(--text3);line-height:1.35}
.stream-state{display:inline-flex;align-items:center;border-radius:999px;padding:2px 7px;font-size:10.5px;font-weight:800;text-transform:uppercase;background:#eef0f3;color:var(--text2)}
.stream-state.active{background:var(--green-bg);color:var(--green)}
.stream-state.disabled,.stream-state.paused{background:var(--red-bg);color:var(--red)}
.stream-share-col{background:#eef6ff!important}
.stream-share-col .num{font-weight:800;color:#185a9d}
.stream-rec-col{background:#f7fbff!important}
.stream-rec-delta{display:block;font-size:10px;font-weight:800;margin-top:1px}
.stream-rec-delta.up{color:var(--green)}
.stream-rec-delta.down{color:var(--red)}
.stream-rec-mode{display:block;font-size:10px;color:var(--text3);font-weight:700;margin-top:1px;text-transform:uppercase}
.stream-rec-mode.up{color:var(--green)}
.stream-rec-mode.down{color:var(--red)}
.offers-right{display:flex;flex-direction:column;min-height:0;overflow:hidden;background:#fbfcfd}
.offers-panel{padding:14px 16px;border-bottom:1px solid var(--border);background:var(--surface);flex:0 0 auto}
.offers-title{font-size:15px;font-weight:800;margin-bottom:3px;color:var(--text)}
.offers-sub{font-size:12px;color:var(--text3)}
.offers-chart{height:300px;padding:12px 0 0}
.offers-splits{display:grid;grid-template-columns:1fr;grid-template-rows:minmax(140px,.42fr) minmax(180px,.58fr);gap:12px;padding:14px 16px;min-height:0;overflow:hidden;flex:1}
.offers-mini{background:var(--surface);border:1px solid var(--border-light);border-radius:var(--r);overflow:hidden;display:flex;flex-direction:column;min-height:0}
.offers-mini-h{padding:10px 12px;border-bottom:1px solid var(--border-light);font-size:12px;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:.4px}
.offers-mini-b{overflow:auto;min-height:0;flex:1}
.offer-row-active td{background:#edf5ff!important}
.offers-row{cursor:pointer}
.offers-row:hover td{background:#f6faff}
.offer-name{font-weight:700;color:var(--text);cursor:pointer}
.offer-name:hover{color:var(--blue)}
.offer-meta{font-size:11px;color:var(--text3);margin-top:2px}
.tasks-toolbar{padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0;background:var(--surface);flex-wrap:wrap}
.tasks-filter{border:1.5px solid var(--border);background:var(--surface);color:var(--text2);border-radius:var(--r2);padding:5px 12px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit}
.tasks-filter.active{background:var(--blue);border-color:var(--blue);color:#fff}
.task-badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:800;white-space:nowrap;background:#eef0f3;color:var(--text2)}
.task-badge.pending{background:#fff7e6;color:#9a4b00}
.task-badge.running{background:var(--blue-bg);color:var(--blue)}
.task-badge.done{background:var(--green-bg);color:var(--green)}
.task-badge.failed{background:var(--red-bg);color:var(--red)}
.task-badge.cancelled{background:#eef0f3;color:var(--text3)}
.task-type{font-size:12px;font-weight:800;color:var(--text)}
.task-sub{font-size:11px;color:var(--text3);margin-top:2px;line-height:1.25}
.task-target-main{font-size:13px;font-weight:800;color:var(--text);line-height:1.25;margin-bottom:4px}
.task-target-meta{font-size:10.5px;color:var(--text3);margin-top:2px;line-height:1.25}
.task-target-meta .num{font-size:inherit;color:inherit}
.task-json{max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px;color:var(--text2)}
.task-json.clickable{cursor:pointer}
.task-json.expanded{max-width:760px;white-space:pre-wrap;overflow:visible;text-overflow:clip;line-height:1.35;word-break:break-word}
.task-error{max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--red);font-size:11px;font-weight:700}
.task-action-group{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.task-action-btn{border:1.5px solid var(--border);background:var(--surface);color:var(--blue);border-radius:var(--r2);padding:4px 9px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap}
.task-action-btn:hover{border-color:var(--blue);background:var(--blue-bg)}
.task-action-btn:disabled{opacity:.55;cursor:wait}
.task-action-btn.danger{border-color:#f3c2c2;background:#fff6f6;color:var(--red)}
.task-action-btn.danger:hover{background:var(--red-bg);border-color:#f1a6a6}
.gc-table{border-collapse:collapse;font-size:13px;white-space:nowrap}
.gc-table th{background:#f7f8fa;padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1.5px solid var(--border);border-right:1px solid var(--border-light);position:sticky;top:0;z-index:10}
.gc-table th.geo-th{text-align:center;min-width:80px}
.gc-table th:first-child{position:sticky;left:0;z-index:20;background:#f7f8fa}
.gc-table td{padding:8px 12px;border-bottom:1px solid var(--border-light);border-right:1px solid var(--border-light);vertical-align:middle}
.gc-table td:first-child{position:sticky;left:0;background:var(--surface);z-index:5;font-weight:600;min-width:160px;max-width:220px;overflow:hidden;text-overflow:ellipsis}
.gc-table tr:hover td{background:#f0f4ff}
.gc-table tr:hover td:first-child{background:#e8edf8}
.gc-cell{display:flex;flex-direction:column;align-items:center;gap:2px}
.gc-rk{font-size:12px;font-weight:600;color:var(--text)}
.gc-count{font-size:11px;color:var(--text3)}
.gc-empty{color:#ccc;font-size:18px;text-align:center}
.gc-dot-green{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);margin-right:4px}
.gc-dot-red{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--red);margin-right:4px}
.gc-profit{font-size:11px;margin-top:1px}
.bm-cards-wrap{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:16px;align-items:start}
@media(max-width:1180px){.bm-cards-wrap{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.bm-cards-wrap{grid-template-columns:1fr}}
.bm-map-toolbar{padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;background:var(--surface);flex-shrink:0}
.bm-map-title{font-size:13px;font-weight:800;color:var(--text2);margin-right:4px}
.bm-map-period{border:1.5px solid var(--border);background:var(--surface);color:var(--text2);border-radius:var(--r2);padding:5px 12px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit}
.bm-map-period.active{background:var(--blue);border-color:var(--blue);color:#fff}
.bm-map-card{background:var(--surface);border:1px solid var(--border-light);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;min-width:0;height:100%}
.bm-map-card.loss,.bm-map-card.risk{border-color:#f0b7b7}
.bm-map-card.growing{border-color:#a9d8b5}
.bm-map-head{padding:12px 14px;border-bottom:1px solid var(--border-light);display:flex;align-items:flex-start;gap:10px}
.bm-map-name{font-size:14px;font-weight:800;color:var(--blue);cursor:pointer;line-height:1.3;word-break:break-word}
.bm-map-id{font-size:11px;color:var(--text3);margin-top:2px}
.bm-map-signal{margin-left:auto;border-radius:4px;padding:3px 8px;font-size:11px;font-weight:800;white-space:nowrap;background:var(--blue-bg);color:var(--blue)}
.bm-map-signal.loss,.bm-map-signal.risk{background:var(--red-bg);color:var(--red)}
.bm-map-signal.growing,.bm-map-signal.recovering{background:var(--green-bg);color:var(--green)}
.bm-map-signal.cooling{background:#fff4e5;color:#9a5b00}
.bm-map-body{padding:12px 14px;display:flex;flex-direction:column;gap:10px;flex:1}
.bm-map-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.bm-map-metric{border:1px solid var(--border-light);border-radius:var(--r2);padding:8px;background:#fbfcfd;min-width:0}
.bm-map-lbl{font-size:10px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
.bm-map-val{font-size:16px;font-weight:800;margin-top:2px;font-variant-numeric:tabular-nums;white-space:nowrap}
.bm-map-delta{font-size:11px;font-weight:800;margin-top:2px;color:var(--text3)}
.bm-map-delta.pos{color:var(--green)}.bm-map-delta.neg{color:var(--red)}
.bm-map-structure{display:flex;gap:10px;font-size:12px;color:var(--text2);font-weight:700}
.bm-map-chart{height:52px;width:100%;display:block;background:#fbfcfd;border:1px solid var(--border-light);border-radius:var(--r2)}
.bm-geo-list{display:flex;flex-direction:column;gap:5px;margin-top:auto}
.bm-geo-row{display:grid;grid-template-columns:34px 1fr 1fr 42px;gap:6px;align-items:center;font-size:12px}
.bm-geo-code{font-weight:800;color:var(--text)}
.bm-geo-cell{border-radius:4px;padding:4px 6px;background:#f7f8fa;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.bm-geo-cell.good{background:var(--green-bg);color:var(--green)}
.bm-geo-cell.bad{background:var(--red-bg);color:var(--red)}
.bm-geo-deps{text-align:right;color:var(--text3);font-weight:700}
.topcreo-geo-header{padding:10px 16px 6px;font-size:13px;font-weight:700;color:var(--text1);background:var(--bg);border-bottom:1px solid var(--border);letter-spacing:.5px}
.topcreo-geo-header span{display:inline-block;background:var(--blue);color:#fff;border-radius:4px;padding:1px 8px;font-size:12px;margin-right:6px}
.creative-calendar-toolbar{display:flex;align-items:center;gap:8px;padding:10px 16px;border-bottom:1px solid var(--border);background:var(--surface);flex:0 0 auto}
.creative-calendar-toolbar label{font-size:12px;font-weight:800;color:var(--text3);text-transform:uppercase}
.creative-calendar-select{height:34px;min-width:150px;border:1px solid var(--border);border-radius:6px;background:var(--surface);padding:0 10px;font-weight:700;color:var(--text)}
.creative-calendar-info{font-size:12px;color:var(--text3)}
.creative-calendar-date{background:var(--bg);border-top:1px solid var(--border);border-bottom:1px solid var(--border);font-weight:800;color:var(--text1)}
.creative-calendar-date span{display:inline-block;background:var(--blue);color:#fff;border-radius:4px;padding:1px 8px;margin-right:8px;font-size:12px}
table{width:100%;border-collapse:collapse;min-width:1300px}
.resizable-table{table-layout:fixed}
thead tr{background:var(--surface)}
th{padding:0;border-bottom:1.5px solid var(--border);white-space:nowrap;position:sticky;top:0;background:#f7f8fa;z-index:10}
.resizable-th{position:sticky;overflow:visible}
.resizable-th::after{content:'';position:absolute;top:0;right:0;width:12px;height:100%;background:transparent;border-right:1px solid transparent;pointer-events:none}
.resizable-th:hover::after,.resizable-th.is-resizing::after{border-right-color:var(--blue);background:linear-gradient(to right, transparent 0, rgba(24,119,242,.08) 100%)}
.resizable-table th,.resizable-table td{overflow:hidden}
.thi{display:flex;align-items:center;justify-content:flex-end;padding:8px 10px;gap:3px;cursor:pointer;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;user-select:none;white-space:nowrap;transition:color .1s}
.thi:hover{color:var(--blue)}
.thi.left{justify-content:flex-start}
.thi.center{justify-content:center;padding:8px 0}
.thi.multi{flex-direction:column;align-items:flex-end;gap:2px;line-height:1.15}
.thi.multi.left{align-items:flex-start}
.th-main{display:flex;align-items:center;gap:3px}
.th-sub{font-size:10px;font-weight:650;color:var(--text3);text-transform:none;letter-spacing:0;white-space:nowrap}
.th-resize-handle{position:absolute;top:0;right:0;width:20px;height:100%;cursor:col-resize;touch-action:none;user-select:none;pointer-events:auto;z-index:30}
.th-resize-handle::after{content:'';position:absolute;top:10%;bottom:10%;left:50%;width:2px;border-radius:2px;transform:translateX(-50%);background:transparent;transition:background .12s,box-shadow .12s}
.resizable-th:hover .th-resize-handle::after,.resizable-th.is-resizing .th-resize-handle::after{background:var(--blue)}
table.col-resizing,.col-resizing *{cursor:col-resize!important;user-select:none!important}
.sort-ico{display:inline-flex;flex-direction:column;margin-left:2px;opacity:.4}
.sort-ico svg{width:7px;height:7px}
.sort-ico.asc svg:first-child,.sort-ico.desc svg:last-child{opacity:1;color:var(--blue)}
th:hover .sort-ico{opacity:.8}
td{border-bottom:1px solid var(--border-light);vertical-align:middle;padding:0}
.tdi{padding:7px 10px;display:flex;align-items:center;justify-content:flex-end;min-height:40px;font-size:13px;min-width:0;overflow:hidden}
.tdi.left{justify-content:flex-start}
.tdi.center{justify-content:center;padding:7px 0}
tr:hover td{background:#f0f2f8}
tr.ad-status-disapproved td{background:#fff1f1}
tr.ad-status-disapproved:hover td{background:#ffe1e1}
tr.ad-status-with_issues td{background:#fff7e6}
tr.ad-status-with_issues:hover td{background:#ffedcc}
tr.ad-status-pending_review td,tr.ad-status-in_process td{background:#eef5ff}
tr.ad-status-pending_review:hover td,tr.ad-status-in_process:hover td{background:#dfeeff}
tr.ad-status-paused td,tr.ad-status-adset_paused td,tr.ad-status-campaign_paused td,tr.ad-status-archived td{background:#f7f8fa}
tr.ad-status-paused:hover td,tr.ad-status-adset_paused:hover td,tr.ad-status-campaign_paused:hover td,tr.ad-status-archived:hover td{background:#eef0f3}
tr.campaign-status-pending td{background:#fff8e6}
tr.campaign-status-pending:hover td{background:#fff2cc}
tr.sel td{background:#ddeeff}
tr.highlighted td{background:#e8f4fd}
tr.highlighted:hover td{background:#d5eaf8}
tr.banned .nc-name{color:var(--text3)!important;opacity:.6}
tr.sel:hover td{background:#cce0fa}
tr.total-row td{background:#eef3ff;font-weight:800;border-top:2px solid var(--blue);position:sticky;bottom:0;z-index:9;color:var(--blue);font-size:12px}
input[type=checkbox]{width:14px;height:14px;accent-color:var(--blue);cursor:pointer}

/* TOGGLE */
.tog{width:32px;height:18px;border-radius:9px;position:relative;border:none;cursor:pointer;transition:background .2s;flex-shrink:0}
.tog::after{content:'';width:14px;height:14px;background:#fff;border-radius:50%;position:absolute;top:2px;left:2px;transition:transform .18s;box-shadow:0 1px 3px rgba(0,0,0,.25)}
.tog.on{background:var(--toggle-on)}.tog.on::after{transform:translateX(14px)}
.tog.off{background:var(--toggle-off)}
.tog:disabled{opacity:.35;cursor:not-allowed}

/* NAME CELL */
.nc{display:flex;align-items:center;gap:8px;min-width:0;overflow:hidden}
.nc-ico{width:22px;height:22px;border-radius:var(--r2);background:var(--blue-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.nc-ico svg{width:12px;height:12px;color:var(--blue)}
.nc-texts{min-width:0;overflow:hidden}
.nc-name{font-size:13px;font-weight:600;color:var(--blue);cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;min-width:0}
.nc-name:hover{text-decoration:underline}
.nc-sub{font-size:11px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;min-width:0}
.creative-preview-name{position:relative;text-decoration-thickness:1px;text-underline-offset:2px}
.creative-preview-name.has-preview::after{content:'';display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--green);margin-left:6px;vertical-align:middle}
.creative-preview-tip{position:fixed;z-index:3000;display:none;width:180px;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 32px rgba(0,0,0,.22);pointer-events:none}
.creative-preview-tip.on{display:block}
.creative-preview-tip img{display:block;width:100%;aspect-ratio:9/16;object-fit:cover;border-radius:5px;background:#f7f8fa}
.creative-preview-tip-title{margin-top:6px;font-size:11px;line-height:1.25;color:var(--text2);font-weight:700;max-height:42px;overflow:hidden}

/* DELIVERY */
.dlv{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:500}
.dlv-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.dlv.ACTIVE .dlv-dot,.dlv.on .dlv-dot{background:var(--toggle-on)}
.dlv.ACTIVE,.dlv.on{color:var(--green)}
.dlv.PAUSED .dlv-dot,.dlv.off .dlv-dot{background:var(--toggle-off)}
.dlv.PAUSED,.dlv.off{color:var(--text3)}
.dlv.PENDING_TASK .dlv-dot{background:#f59e0b}.dlv.PENDING_TASK{color:#9a4b00}
.dlv.IN_PROCESS .dlv-dot{background:#e67e22}.dlv.IN_PROCESS{color:#7a4500}
.dlv.DISAPPROVED .dlv-dot{background:var(--red)}.dlv.DISAPPROVED{color:#b42318}
.dlv.WITH_ISSUES .dlv-dot{background:var(--orange)}.dlv.WITH_ISSUES{color:#9a4b00}
.dlv.PENDING_REVIEW .dlv-dot{background:var(--blue)}.dlv.PENDING_REVIEW{color:var(--blue)}
.dlv.CAMPAIGN_PAUSED .dlv-dot,.dlv.ADSET_PAUSED .dlv-dot,.dlv.ARCHIVED .dlv-dot{background:var(--toggle-off)}
.dlv.CAMPAIGN_PAUSED,.dlv.ADSET_PAUSED,.dlv.ARCHIVED{color:var(--text3)}
.dlv.account-muted{opacity:.45}
.dlv-sub{font-size:10.5px;color:var(--text3);margin-top:2px;line-height:1.2}

/* NUMBERS */
.num{font-size:13px;color:var(--text);font-variant-numeric:tabular-nums}
.num-dim{color:var(--text3)}
.num-small{font-size:11px;color:var(--text3);margin-top:1px}
.num-wrap{display:flex;flex-direction:column;align-items:flex-end}
.cost-diff{display:block;font-size:10px;line-height:1.05;font-weight:800;margin-top:1px;color:rgba(101,103,107,.72)}
td.cost-cell.good{background:rgba(49,162,76,var(--cost-bg-alpha,.18))}
td.cost-cell.bad{background:rgba(250,62,62,var(--cost-bg-alpha,.18))}
.spend-val{font-weight:700}

/* LIMIT BAR */
.limit-bar-wrap{display:flex;flex-direction:column;align-items:flex-end;gap:2px}
.limit-bar{width:64px;height:5px;background:var(--border);border-radius:3px;overflow:hidden}
.limit-bar-fill{height:100%;border-radius:3px;background:var(--blue)}
.limit-bar-fill.warn{background:var(--orange)}
.limit-bar-fill.danger{background:var(--red)}

/* LOADING / EMPTY */
.tbl-loading{display:flex;align-items:center;justify-content:center;padding:64px;gap:12px;color:var(--text3);font-size:14px}
.tbl-loading svg{width:22px;height:22px;animation:spin .8s linear infinite;color:var(--blue)}
.tbl-empty{text-align:center;padding:64px;color:var(--text3);font-size:14px}

/* DROPDOWN */
.dd-wrap{position:relative}
.dropdown{position:fixed;min-width:210px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow-md);z-index:1000;padding:4px 0;display:none}
.dropdown.open{display:block}
.dd-item{display:flex;align-items:center;gap:8px;padding:8px 14px;cursor:pointer;font-size:13px;color:var(--text2);transition:background .1s}
.dd-item:hover{background:var(--bg)}
.dd-item.active{background:var(--blue-bg);color:var(--blue);font-weight:700}
.dd-sep{height:1px;background:var(--border-light);margin:4px 0}
.dd-check{margin-left:auto;color:var(--blue)}

/* SCROLLBAR */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--text3)}

@media (max-width: 1280px){
  .topbar{height:auto;min-height:52px;padding:10px 12px;flex-wrap:wrap;align-items:flex-start}
  .tb-right{width:100%;margin-left:0;flex-wrap:wrap;gap:6px}
  .tb-sep{display:none}
  .tb-btn{padding:5px 10px;font-size:12px}
}

@media (max-width: 1700px){
  table{min-width:1180px}
  .thi{padding:7px 8px;font-size:10.5px}
  .thi.center{padding:7px 0}
  .tdi{padding:6px 8px;min-height:34px;font-size:12px}
  .num{font-size:12px}
  .num-small{font-size:10px}
  .nc-name{font-size:12px}
  .nc-sub{font-size:10.5px}
  .filter-selects{gap:6px}
  .filter-field label{font-size:9.5px}
  .filter-select{height:28px;font-size:11.5px}
  .filter-select.small{max-width:86px}
  .factive{padding:7px 10px}
}

@media (max-width: 1100px){
  body{height:auto;min-height:100vh;overflow:auto}
  .levelnav{padding:8px 12px}
  .lnav-item{white-space:normal}
  .lnav-right{width:100%;margin-left:0;flex-wrap:wrap;order:99;justify-content:flex-start}
  .lnav-daterange{width:100%;justify-content:center}
  .levelnav .tz-badge{order:1}
  .ftabs{padding:8px 12px}
  .filter-selects{width:100%;margin-left:0;flex-wrap:wrap}
  .delivery-badges{width:100%;margin-left:0;flex-wrap:wrap}
  .fright{width:100%;margin-left:0;display:flex;justify-content:flex-end}
  .factive{height:auto;min-height:40px;padding:8px 12px;flex-wrap:wrap}
  .factive-tag{flex:0 0 auto}
  .factive-search{width:100%;min-width:0;order:10}
  .factive-clear{margin-left:auto;order:11}
  .streams-toolbar{flex-wrap:wrap}
  .streams-select{width:100%;max-width:none;min-width:0}
  .main{padding:12px 12px 18px;overflow:visible}
  .main-wrap{overflow:visible}
  .offers-tabs{padding:8px 10px}
  .offers-splits{grid-template-rows:auto auto}
}

@media (max-width: 900px){
  .num-wrap,.limit-bar-wrap{align-items:flex-start}
  .tbl-loading,.tbl-empty{padding:40px 16px}
  .dropdown{min-width:180px;max-width:calc(100vw - 24px)}
}

@media (max-width: 680px){
  .tb-logo{width:100%}
  .tb-right{gap:5px}
  .tb-btn{font-size:11.5px;padding:5px 8px}
  .levelnav,.ftabs,.factive,.streams-toolbar{padding-left:8px;padding-right:8px}
  .lnav-item,.ftab{font-size:12px;padding-left:10px;padding-right:10px}
  .filter-select{max-width:100%;width:100%}
  .filter-select.small{max-width:100%;width:100%}
  .factive-search{font-size:12px}
  .streams-toolbar label{width:100%}
}
</style>
</head>
<body>

<!-- TOP BAR -->
<?php include __DIR__.'/_header.php'; ?>
<div id="creativePreviewTip" class="creative-preview-tip"></div>
<script>
// Click logo to reset all filters/state and sync
document.querySelector('.tb-logo').addEventListener('click', function(e) {
    e.preventDefault();
    resetState();
    syncKeitaroAndRefresh();
});
</script>

<div class="main">
<div class="main-wrap">
<!-- SUMMARY CARDS -->
<div class="cards-bar" id="cardsBar">
  <div class="card"><div class="card-lbl">Leads</div><div class="card-val" id="cardLeads">-</div></div>
  <div class="card"><div class="card-lbl">Regs</div><div class="card-val" id="cardRegs">-</div></div>
  <div class="card"><div class="card-lbl">Deps</div><div class="card-val" id="cardDeps">-</div></div>
  <div class="card"><div class="card-lbl">Spend</div><div class="card-val b" id="cardSpend">-</div></div>
  <div class="card"><div class="card-lbl">Revenue</div><div class="card-val" id="cardRevenue">-</div></div>
  <div class="card"><div class="card-lbl">Profit</div><div class="card-val" id="cardProfit">-</div></div>
  <div class="card"><div class="card-lbl">ROI %</div><div class="card-val" id="cardRoi">-</div></div>
</div>

<!-- LEVEL NAV -->
<div class="levelnav">
  <div class="lnav-item" id="lnav-geo" onclick="openGeoView()" style="border-right:1px solid var(--border2);padding-right:14px;margin-right:4px;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
    Geo
  </div>
  <div class="lnav-item" id="lnav-month" onclick="openMonthFromLevelNav()" style="border-right:1px solid var(--border2);padding-right:14px;margin-right:4px;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    Month
  </div>
  <div class="lnav-item" id="lnav-bm" onclick="setLevel('bm')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
    BM
    <span class="lnav-badge" id="bmSelBadge" style="display:none"><span id="bmSelCount"></span><span class="lnav-badge-x" onclick="event.stopPropagation();clearLevelSel('bm')">x</span></span>
  </div>
  <div class="lnav-item" id="lnav-bm_cards" onclick="setView('bm_cards')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
    BM Cards
  </div>
  <span class="lnav-arrow">></span>
  <div class="lnav-item" id="lnav-account" onclick="setLevel('account')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="18" rx="2"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="8" x2="12" y2="8"/></svg>
    Accounts
    <span class="lnav-badge" id="acctSelBadge" style="display:none"><span id="acctSelCount"></span><span class="lnav-badge-x" onclick="event.stopPropagation();clearLevelSel('account')">x</span></span>
  </div>
  <span class="lnav-arrow">></span>
  <div class="lnav-item active" id="lnav-campaign" onclick="setLevel('campaign')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
    Campaigns
    <span class="lnav-badge" id="campSelBadge" style="display:none"><span id="campSelCount"></span><span class="lnav-badge-x" onclick="event.stopPropagation();clearLevelSel('campaign')">x</span></span>
  </div>
  <span class="lnav-arrow">></span>
  <div class="lnav-item" id="lnav-adset" onclick="setLevel('adset')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
    <span id="adsetLabel">Ad Sets</span>
    <span class="lnav-badge" id="adsetSelBadge" style="display:none"><span id="adsetSelCount"></span><span class="lnav-badge-x" onclick="event.stopPropagation();clearLevelSel('adset')">x</span></span>
  </div>
  <span class="lnav-arrow">></span>
  <div class="lnav-item" id="lnav-ad" onclick="setLevel('ad')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="15" x2="13" y2="15"/></svg>
    <span id="adLabel">Ads</span>
    <span class="lnav-badge" id="adSelBadge" style="display:none"><span id="adSelCount"></span><span class="lnav-badge-x" onclick="event.stopPropagation();clearLevelSel('ad')">x</span></span>
  </div>
  <span class="lnav-arrow">></span>
  <div class="lnav-item" id="lnav-creo" onclick="openCreoView()" style="border-left:1px solid var(--border2);padding-left:14px;margin-left:4px;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
    Creo
  </div>
  <div class="lnav-item" id="lnav-topcreo" onclick="setView('topcreo')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
    Top Creo
  </div>
  <div class="lnav-item" id="lnav-creative_calendar" onclick="setView('creative_calendar')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
    Creo Calendar
  </div>
  <div class="lnav-item" id="lnav-camps_calendar" onclick="setView('camps_calendar')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M7 14h10M7 18h6"/></svg>
    Camps Calendar
  </div>
  <div class="lnav-item" id="lnav-streams" onclick="setView('streams')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5"/><path d="M20 19V5"/><path d="M8 7h8"/><path d="M8 12h8"/><path d="M8 17h8"/></svg>
    Streams
  </div>
  <div class="lnav-item" id="lnav-geotrends" onclick="setView('geotrends')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    GeoTrends
  </div>
  <div class="lnav-item" id="lnav-geodiff" onclick="setView('geodiff')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
    GeoDiff
  </div>
  <div class="lnav-item" id="lnav-trends" onclick="setView('trends')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
    Trends
  </div>
  <div class="lnav-item" id="lnav-geocabs" onclick="setView('geocabs')" style="border-left:1px solid var(--border2);margin-left:4px;padding-left:14px">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
    Geo&amp;Accs
  </div>
  <div class="lnav-item" id="lnav-rules_check" onclick="setView('rules_check')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Rules Check
  </div>
  <div class="lnav-right">
    <span class="tz-badge" id="tzBadge"></span>
    <div class="dd-wrap" id="ddRangeWrap">
      <div class="lnav-daterange" onclick="toggleDD('ddRange')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span id="rangeLabel">Today</span>
        <svg viewBox="0 0 20 20" fill="var(--text3)" width="10" height="10"><path d="M5 8l5 5 5-5z"/></svg>
      </div>
      <div class="dropdown" id="ddRange" style="right:0;left:auto;min-width:180px">
        <div class="dd-item" data-range="today" onclick="setRange('today')">Today</div>
        <div class="dd-item" data-range="yesterday" onclick="setRange('yesterday')">Yesterday</div>
        <div class="dd-item" data-range="yesterday_today" onclick="setRange('yesterday_today')">Yesterday+Today</div>
        <div class="dd-sep"></div>
        <div class="dd-item" data-range="3d" onclick="setRange('3d')">3 days</div>
        <div class="dd-item" data-range="7d" onclick="setRange('7d')">7 days</div>
        <div class="dd-item" data-range="14d" onclick="setRange('14d')">14 days</div>
        <div class="dd-item" data-range="this_month" onclick="setRange('this_month')">This month</div>
        <div class="dd-item" data-range="last_month" onclick="setRange('last_month')">Last month</div>
        <div class="dd-item" data-range="30d" onclick="setRange('30d')">30 days</div>
        <div class="dd-item" data-range="90d" onclick="setRange('90d')">3 months</div>
        <div class="dd-item" data-range="this_year" onclick="setRange('this_year')">This year</div>
        <div class="dd-item" data-range="all" onclick="setRange('all')">All time</div>
      </div>
    </div>
    <button class="tb-btn primary" id="btnRefresh" onclick="syncKeitaroAndRefresh()" style="display:flex;align-items:center;gap:5px;font-size:12px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
      Refresh
    </button>
    <button class="width-reset-btn icon" id="btnResetWidthsTop" onclick="resetTableColumnWidths()" title="Reset column widths" aria-label="Reset column widths" style="display:none">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h18"/><path d="M3 16h10"/><path d="M13 4l4 4-4 4"/><path d="M11 12l4 4-4 4"/></svg>
    </button>
    <label class="width-balance-toggle" id="btnBalanceWidthsWrap" title="Keep columns balanced while resizing" style="display:none">
      <input type="checkbox" id="btnBalanceWidthsTop" onchange="setTableColumnBalance(this.checked)">
      <span class="width-balance-track"><span class="width-balance-knob"></span></span>
      <span class="width-balance-label">Balance</span>
    </label>
  </div>
</div>

<!-- REPORT FILTERS -->
<div class="ftabs report-controls" id="reportControls">
  <div class="filter-selects" id="filterSelects">
    <div class="filter-field"><label for="fltGeo">Geo</label><select class="filter-select small" id="fltGeo" onchange="setReportFilter('geo',this.value)"></select></div>
    <div class="filter-field"><label for="fltBm">BM</label><select class="filter-select" id="fltBm" onchange="setReportFilter('bm_id',this.value,this.options[this.selectedIndex]?.text)"></select></div>
    <div class="filter-field"><label for="fltAccount">Account</label><select class="filter-select" id="fltAccount" onchange="setReportFilter('account_id',this.value,this.options[this.selectedIndex]?.text)"></select></div>
    <div class="filter-field"><label for="fltCampaign">Campaign</label><select class="filter-select" id="fltCampaign" onchange="setReportFilter('campaign_id',this.value,this.options[this.selectedIndex]?.text)"></select></div>
    <div class="filter-field"><label for="fltAdset">Ad Set</label><select class="filter-select" id="fltAdset" onchange="setReportFilter('adset_id',this.value,this.options[this.selectedIndex]?.text)"></select></div>
    <div class="filter-field"><label for="fltCreo">Creo</label><select class="filter-select" id="fltCreo" onchange="setReportFilter('ad_name',this.value,this.options[this.selectedIndex]?.text)"></select></div>
    <div class="filter-field" id="deliveryControl"><label for="fltDelivery">Status</label><select class="filter-select small" id="fltDelivery" onchange="setDeliveryFilter(this.value || null)"><option value="">All</option><option value="ACTIVE">Active</option><option value="PAUSED">Paused</option></select></div>
    <div class="filter-field"><label for="fltLaunchDate">Launch date</label><select class="filter-select" id="fltLaunchDate" onchange="setReportFilter('launch_date',this.value)"></select></div>
    <div class="filter-field"><label for="fltLaunchMode">Launch mode</label><select class="filter-select small" id="fltLaunchMode" onchange="setLaunchMode(this.value)"><option value="exact">Exact date</option><option value="after">All after</option><option value="before">All before</option></select></div>
    <div class="filter-field"><label for="fltV1Verdict">V1 verdict</label><select class="filter-select small" id="fltV1Verdict" onchange="setRulesVerdictFilter('v1',this.value)"></select></div>
    <div class="filter-field"><label for="fltV2Verdict">V2 verdict</label><select class="filter-select small" id="fltV2Verdict" onchange="setRulesVerdictFilter('v2',this.value)"></select></div>
  </div>
  <div class="delivery-badges" id="deliveryBadges"></div>
  <div class="fright">
    <button class="filter-reset" id="btnResetFilters" onclick="clearAllReportFilters()" style="display:none">Reset</button>
  </div>
</div>

<!-- SEARCH BAR -->
<div class="factive">
  <div class="factive-tag" id="filterTag" style="display:none">
    Filter: <strong id="filterTagText"></strong>
    <button onclick="clearFilters()">x</button>
  </div>
  <input class="factive-search" id="srch" placeholder="Search by name, ID..." oninput="applySearch()" onkeydown="handleSearchKey(event)">
  <button class="factive-clear" id="clearBtn" style="display:none" onclick="clearSearch()">Reset</button>
</div>

<!-- BULK BAR -->
<div class="selbar" id="selbar">
  <div class="sel-info">
    <button class="sel-x" onclick="clearSel()">x</button>
    Selected: <span id="selCount">0</span>
  </div>
  <button class="sbtn" onclick="bulkPause()">Pause</button>
  <button class="sbtn" onclick="bulkResume()">Start</button>
  <div class="sel-right"></div>
</div>

<!-- MONTH VIEW -->
<div id="monthWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div id="monthPeriodCtrl" style="padding:8px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:6px;flex-shrink:0">
    <label for="monthPeriodSel" style="font-size:12px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.02em">Month</label>
    <select id="monthPeriodSel" class="filter-select" style="min-width:220px;max-width:320px" onchange="setMonthPeriod(this.value)"></select>
    <div id="monthPeriodMeta" style="font-size:11px;color:var(--text3);margin-left:8px"></div>
  </div>
  <div id="monthTbl" style="overflow:auto;flex:1"></div>
</div>

<!-- BM CARDS VIEW -->
<div id="bmCardsWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div class="bm-map-toolbar">
    <span class="bm-map-title">Period</span>
    <button class="bm-map-period" id="bmCardsPeriodyesterday_today" onclick="setBmCardsPeriod('yesterday_today')">Yesterday+today</button>
    <button class="bm-map-period" id="bmCardsPeriod7d" onclick="setBmCardsPeriod('7d')">7d</button>
    <button class="bm-map-period" id="bmCardsPeriod14d" onclick="setBmCardsPeriod('14d')">14d</button>
    <button class="bm-map-period" id="bmCardsPeriod30d" onclick="setBmCardsPeriod('30d')">30d</button>
    <span class="dim" id="bmCardsRangeLabel"></span>
  </div>
  <div id="bmCardsGrid" style="overflow:auto;flex:1"></div>
</div>

<!-- CREO VIEW -->
<div id="creoWrap" style="display:none;flex:1;overflow:auto;background:var(--surface)">
  <div id="creoTbl"></div>
</div>

<!-- TOP CREO VIEW -->
<div id="topcreoWrap" style="display:none;flex:1;overflow:auto;background:var(--surface)">
  <div id="topcreoTbl"></div>
</div>

<!-- CREATIVE CALENDAR VIEW -->
<div id="creativeCalendarWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div id="creativeCalendarTbl" style="overflow:auto;flex:1"></div>
</div>

<!-- CAMPAIGNS CALENDAR VIEW -->
<div id="campsCalendarWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div id="campsCalendarTbl" style="overflow:auto;flex:1"></div>
</div>

<!-- GEO VIEW -->
<div id="geoWrap" style="display:none;flex:1;overflow:auto;background:var(--surface)">
  <div id="geoTbl"></div>
</div>

<div id="streamsWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div class="streams-toolbar">
    <label for="streamsSelect">Stream</label>
    <select class="streams-select" id="streamsSelect" onchange="setStream(this.value)"></select>
    <div class="streams-sub" id="streamsSyncInfo"></div>
  </div>
  <div class="streams-head" id="streamsHead" style="display:none"></div>
  <div id="streamsTbl" style="overflow:auto;flex:1"></div>
</div>

<div id="offersWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface)">
  <div class="offers-grid">
    <div class="offers-left">
      <div class="offers-tabs" id="offersGeoTabs"></div>
      <div id="offersTbl"></div>
    </div>
    <div class="offers-right">
      <div class="offers-panel">
        <div class="offers-title" id="offerDetailTitle">Choose an offer</div>
        <div class="offers-sub" id="offerDetailSub">Chart and breakdowns will appear after you select a row.</div>
        <div class="offers-chart"><canvas id="offersCanvas"></canvas></div>
      </div>
      <div class="offers-splits">
        <div class="offers-mini">
          <div class="offers-mini-h">GEO</div>
          <div class="offers-mini-b" id="offersGeoTbl"></div>
        </div>
        <div class="offers-mini">
          <div class="offers-mini-h">Creo</div>
          <div class="offers-mini-b" id="offersCreativeTbl"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="geotrendsWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div id="geotrendsCtrl" style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0"></div>
  <div id="geotrendsChart" style="flex:1;overflow:auto;padding:16px"></div>
</div>

<div id="geodiffWrap" style="display:none;flex:1;overflow:auto;background:var(--surface)">
  <div id="geodiffTbl"></div>
</div>

<div id="geocabsWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:var(--surface)">
    <span style="font-size:12px;font-weight:600;color:var(--text3)">BM</span>
    <select id="gcBmSelect" onchange="setGeocabsBm(this.value)" style="padding:4px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-family:inherit;color:var(--text);background:var(--surface);outline:none;height:30px;cursor:pointer">
      <option value="">All</option>
    </select>
  </div>
  <div id="geocabsTbl" style="padding:16px;overflow:auto;flex:1"></div>
</div>
<div id="tasksWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div class="tasks-toolbar">
    <span style="font-size:12px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.4px">&#1057;&#1090;&#1072;&#1090;&#1091;&#1089;</span>
    <button class="tasks-filter" id="taskStatusAll" onclick="setTasksStatus('')">&#1042;&#1089;&#1077;</button>
    <button class="tasks-filter" id="taskStatusPending" onclick="setTasksStatus('pending')">Pending</button>
    <button class="tasks-filter" id="taskStatusRunning" onclick="setTasksStatus('running')">Running</button>
    <button class="tasks-filter" id="taskStatusFailed" onclick="setTasksStatus('failed')">Failed</button>
    <button class="tasks-filter" id="taskStatusDone" onclick="setTasksStatus('done')">Done</button>
    <span class="dim" id="tasksSummary"></span>
  </div>
  <div id="tasksTbl" style="overflow:auto;flex:1"></div>
</div>
<div id="trendsWrap" style="display:none;flex:1;overflow:hidden;background:var(--surface);flex-direction:column">
  <div id="trendsChart" style="padding:16px;border-bottom:1px solid var(--border);flex-shrink:0;min-height:300px"><canvas id="trendsCanvas" style="max-height:320px"></canvas></div>
  <div id="trendsTabs" style="display:flex;gap:0;border-bottom:1px solid var(--border);flex-shrink:0;padding:0 16px">
    <button id="trendTab-campaign" class="ftab active" style="margin-top:8px" onclick="setTrendsTab('campaign')">Campaigns</button>
    <button id="trendTab-account"  class="ftab"        style="margin-top:8px" onclick="setTrendsTab('account')">Accounts</button>
  </div>
  <div id="trendsTbl" style="overflow:auto;flex:1"></div>
</div>

<!-- TABLE -->
<div class="tblwrap" id="tblwrap">
  <div class="tbl-loading">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
    Loading data...
  </div>
</div>

</div><!-- /main-wrap -->
</div><!-- /main -->

<script>
// ================================================================
// -- STATE ----------------------------------------------------
// ================================================================

const ME = <?= json_encode([
    'role'         => $me['role'],
    'display_name' => $me['display_name'],
    'tz'           => $displayTz,
]) ?>;

// -- CONSTANTS ------------------------------------------------

// Builds the date label for the current range in the client timezone
function rangeDateStr(range) {
    const tz = '<?= $displayTz ?>';
    const fmt = d => d.toLocaleDateString('en-GB', {day:'2-digit', month:'2-digit', year:'2-digit', timeZone: tz});
    const now = new Date();
    const toLocal = offset => new Date(now.getTime() + offset);
    // Yesterday
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    const fmtD = (y, m, d) => `${String(d).padStart(2,'0')}.${String(m+1).padStart(2,'0')}.${String(y).slice(-2)}`;
    const localNow = new Date(now.toLocaleString('en-US', {timeZone: tz}));
    const localYest = new Date(localNow); localYest.setDate(localNow.getDate() - 1);
    const f = d => fmtD(d.getFullYear(), d.getMonth(), d.getDate());
    const today = f(localNow);
    const yest  = f(localYest);
    const nDays = n => { const d = new Date(localNow); d.setDate(localNow.getDate() - n + 1); return f(d); };
    const firstOfMonth = () => { const d = new Date(localNow); d.setDate(1); return f(d); };
    const lastOfLastMonth = () => { const d = new Date(localNow); d.setDate(0); return f(d); };
    const firstOfLastMonth = () => { const d = new Date(localNow); d.setDate(0); d.setDate(1); return f(d); };
    const firstOfYear = () => { return `01.01.${String(localNow.getFullYear()).slice(-2)}`; };
    switch(range) {
        case 'today':          return today;
        case 'yesterday':      return yest;
        case 'yesterday_today':return `${yest} - ${today}`;
        case '3d':             return `${nDays(3)} - ${today}`;
        case '7d':             return `${nDays(7)} - ${today}`;
        case '14d':            return `${nDays(14)} - ${today}`;
        case 'this_week':      { const d=new Date(localNow); d.setDate(localNow.getDate()-((localNow.getDay()||7)-1)); return `${f(d)} - ${today}`; }
        case 'this_month':     return `${firstOfMonth()} - ${today}`;
        case 'last_month':     return `${firstOfLastMonth()} - ${lastOfLastMonth()}`;
        case '30d':            return `${nDays(30)} - ${today}`;
        case '90d':            return `${nDays(90)} - ${today}`;
        case 'this_year':      return `${firstOfYear()} - ${today}`;
        case 'all':            return 'all time';
        default:               return range;
    }
}

function updateTzBadge() {
    const el = document.getElementById('tzBadge');
    if (el) el.textContent = rangeDateStr(state.range);
}

const RANGE_LABELS = {today:'Today',yesterday:'Yesterday',yesterday_today:'Yesterday+Today','3d':'3 days','7d':'7 days','14d':'14 days',this_week:'This week',this_month:'This month',last_month:'Last month','30d':'30 days','90d':'3 months',this_year:'This year',all:'All time'};
const SORT_ICO = `<span class="sort-ico"><svg viewBox="0 0 8 5" fill="currentColor"><path d="M4 0l4 5H0z"/></svg><svg viewBox="0 0 8 5" fill="currentColor"><path d="M4 5L0 0h8z"/></svg></span>`;
const DAY_NAMES = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const TABLE_LEVELS = ['bm','account','campaign','rules_check','adset','ad'];
const TABLE_VIEW_CONTROLS = new Set(['bm','account','campaign','rules_check','adset','ad','month','creo','topcreo','creative_calendar','camps_calendar','streams','offers','geo','geodiff','geocabs','tasks']);
const FILTER_ORDER = ['geo','bm_id','account_id','launch_date','campaign_id','adset_id','ad_name','v1_verdict','v2_verdict'];
const ACCOUNT_FILTER_ACTIVE = '__all_active__';
const ACCOUNT_FILTER_ALL = '__all_accounts__';
const VIEW_LEVEL   = {bm:0, bm_cards:-1, account:1, campaign:2, rules_check:2, adset:3, ad:4, creo:5, topcreo:5, creative_calendar:5, camps_calendar:5, streams:-1, geo:-1, month:-1, geotrends:-1, geodiff:-1, trends:-1, geocabs:-1, tasks:-1};
const REPORT_FILTERS_BY_VIEW = {
    bm: ['geo', 'delivery'],
    account: ['geo', 'bm_id', 'delivery'],
    campaign: ['geo', 'bm_id', 'account_id', 'delivery', 'launch_date', 'ad_name'],
    adset: ['geo', 'bm_id', 'account_id', 'campaign_id', 'delivery'],
    ad: ['geo', 'bm_id', 'account_id', 'campaign_id', 'adset_id', 'ad_name', 'delivery'],
    creo: ['geo', 'bm_id', 'account_id', 'campaign_id', 'delivery'],
    topcreo: [],
    bm_cards: [],
    streams: [],
    geotrends: [],
    geodiff: [],
    trends: [],
    geo: [],
    month: ['geo'],
    creative_calendar: [],
    camps_calendar: ['geo'],
    geocabs: [],
    tasks: [],
    rules_check: ['geo', 'bm_id', 'account_id', 'delivery', 'launch_date', 'ad_name', 'v1_verdict', 'v2_verdict'],
};

// -- STATE ----------------------------------------------------
const state = {
    view:  'campaign', // 'geo'|'month'|'creo'|'campaign'|'adset'|'ad'|'bm'|'bm_cards'|'account'|'tasks'
    range: 'today',
    filters: {
        geo: null, bm_id: null, bm_name: null,
        account_id: null, account_name: null,
        launch_date: null,
        launch_mode: null,
        campaign_id: null, campaign_name: null,
        adset_id: null, adset_name: null,
        ad_name: null,
        v1_verdict: null,
        v2_verdict: null,
    },
    tabs: {
        geo:      { search: '', sortCol: 'stats.spend', sortDir: 'desc' },
        month:    { search: '', sortCol: 'day', sortDir: 'desc', period: '' },
        creo:     { search: '', sortCol: 'rank', sortDir: 'asc' },
        topcreo:  { search: '', sortCol: 'stats.profit', sortDir: 'desc' },
        creative_calendar: { search: '' },
        camps_calendar: { search: '' },
        streams:  { search: '', sortCol: 'rank_score', sortDir: 'desc', stream_id: '' },
        geotrends:{ search: '', geo: null, metrics: 'spend,revenue,profit' },
        geodiff:  { search: '', sortCol: 'today.spend', sortDir: 'desc' },
        trends:   { tab: 'campaign', sel: '' },
        geocabs:  { bm_id: '' },
        tasks:    { search: '', status: '', sortCol: 'created_at', sortDir: 'desc' },
        bm_cards: { period: '14d' },
        bm:       { search: '', delivery: null, sortCol: 'spend',       sortDir: 'desc' },
        account:  { search: '', delivery: null, sortCol: 'spend',       sortDir: 'desc' },
        campaign: { search: '', delivery: null, sortCol: 'stats.spend', sortDir: 'desc' },
        rules_check: { search: '', delivery: null, sortCol: 'stats.spend', sortDir: 'desc' },
        adset:    { search: '', delivery: null, sortCol: 'stats.spend', sortDir: 'desc' },
        ad:       { search: '', delivery: null, sortCol: 'stats.spend', sortDir: 'desc' },
    },
    selections: { bm: new Set(), account: new Set(), campaign: new Set(), rules_check: new Set(), adset: new Set(), ad: new Set() },
};
const TAB_DEFAULTS = JSON.parse(JSON.stringify(state.tabs));

// Data caches
let rows = [], creoRows = [], creativeCalendarRows = [], creativeCalendarMeta = {}, campsCalendarRows = [], campsCalendarMeta = {}, geoRows = [], geoDiffRows = [], monthRows = [], monthPeriods = [], monthPeriodsKey = '', accounts = [], bmCardRows = [], streamRows = [], taskRows = [], taskMeta = {}, _topCreoFlat = [];
let adsetBidEditor = null;
let creativePreviewMap = null;
let reportFilterOptions = {geos:[], bms:[], accounts:[], launch_dates:[], campaigns:[], adsets:[], creatives:[]};
let _filterOptionsKey = '';
let _filterOptionsSeq = 0;
const pendingCampaignTasks = new Map();
const campaignTaskTimers = new Map();
const pendingAdsetTasks = new Map();
const adsetTaskTimers = new Map();
const pendingAdTasks = new Map();
const adTaskTimers = new Map();
const DEFAULT_CREATIVE_PREVIEW = '/uploads/creative_previews/default.png';

// -- HELPERS --------------------------------------------------
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const escAttr = s => esc(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;');
const f$  = v => !v ? '-' : '$' + (+v).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
const fN  = v => !v ? '-' : (+v).toLocaleString('en');
const fP  = v => !v ? '-' : (+v).toFixed(2)+'%';
const fF  = v => !v ? '-' : (+v).toFixed(2);
const fPct = v => v === null || v === undefined ? '-' : (+v).toFixed(2) + '%';
const fR2D = (regs, deps) => Number(regs||0) > 0 ? (Number(deps||0) / Number(regs||0) * 100).toFixed(2) + '%' : '-';
const c2lValue = (clicks, leads) => Number(clicks || 0) > 0 ? Number(leads || 0) / Number(clicks || 0) * 100 : null;
const r2dValue = s => Number(s?.regs || 0) > 0 ? Number(s?.deps || 0) / Number(s?.regs || 0) * 100 : 0;
const metricValue = (s, key) => key === 'r2d' ? r2dValue(s) : (key === 'c2l' ? c2lValue(s?.clicks, s?.leads) ?? 0 : Number(s?.[key] ?? 0));
const COST_METRICS = ['cpc','cpl','cpr','cpd'];
function baselineRangeForCurrent() {
    return ['90d','this_year','all'].includes(state.range) ? '90d' : '30d';
}
function finalizeCostStats(s) {
    const out = {...(s || {})};
    out.spend = Number(out.spend || 0);
    out.clicks = Number(out.clicks || 0);
    out.leads = Number(out.leads || 0);
    out.regs = Number(out.regs || 0);
    out.deps = Number(out.deps || 0);
    out.cpc = out.clicks > 0 ? out.spend / out.clicks : 0;
    out.cpl = out.leads > 0 ? out.spend / out.leads : 0;
    out.cpr = out.regs > 0 ? out.spend / out.regs : 0;
    out.cpd = out.deps > 0 ? out.spend / out.deps : 0;
    return out;
}
function sumCostStats(items, getter = x => x?.stats) {
    const total = {spend:0, clicks:0, leads:0, regs:0, deps:0};
    items.forEach(item => {
        const s = getter(item) || {};
        total.spend += Number(s.spend || 0);
        total.clicks += Number(s.clicks || 0);
        total.leads += Number(s.leads || 0);
        total.regs += Number(s.regs || 0);
        total.deps += Number(s.deps || 0);
    });
    return finalizeCostStats(total);
}
function costDiffHtml(value, baseline, metric) {
    const cur = Number(value || 0), base = Number(baseline?.[metric] || 0);
    if (cur <= 0 || base <= 0) return '';
    const diff = (cur - base) / base * 100;
    if (!Number.isFinite(diff)) return '';
    const sign = diff > 0 ? '+' : '';
    const baseRange = baselineRangeForCurrent();
    return `<span class="cost-diff" title="vs ${baseRange} avg ${f$(base)}">${sign}${diff.toFixed(0)}%</span>`;
}
function costMetricCell(s, metric, value = null, baseline = null) {
    const actual = value === null || value === undefined ? Number(s?.[metric] || 0) : Number(value || 0);
    const base = Number(baseline?.[metric] || 0);
    const diffPct = actual > 0 && base > 0 ? Math.abs((actual - base) / base * 100) : 0;
    const alpha = diffPct > 0 ? Math.min(0.10, Math.max(0.02, 0.02 + diffPct / 100 * 0.08)) : 0;
    const cls = actual > 0 && base > 0 ? (actual < base ? 'good' : (actual > base ? 'bad' : '')) : '';
    const style = alpha > 0 ? ` style="--cost-bg-alpha:${alpha.toFixed(3)}"` : '';
    return `<td class="cost-cell ${cls}"${style}><div class="tdi"><div class="num-wrap"><span class="num">${actual > 0 ? f$(actual) : '-'}</span>${costDiffHtml(actual, baseline, metric)}</div></div></td>`;
}
const isTableView = () => TABLE_LEVELS.includes(state.view);
const curTab = () => state.tabs[state.view] || state.tabs.campaign;

async function ensureCreativePreviewMap() {
    if (creativePreviewMap !== null) return creativePreviewMap;
    try {
        const json = await fetch('/creative_previews.php?action=map').then(r => r.json());
        creativePreviewMap = json.ok ? (json.data || {}) : {};
    } catch (e) {
        creativePreviewMap = {};
    }
    return creativePreviewMap;
}

function creativePreviewAttrs(name) {
    const url = creativePreviewMap?.[name || ''] || DEFAULT_CREATIVE_PREVIEW;
    return `data-preview-url="${escAttr(url)}" data-preview-name="${escAttr(name)}" onmouseenter="showCreativePreview(event,this)" onmousemove="moveCreativePreview(event)" onmouseleave="hideCreativePreview()"`;
}

function creativePreviewClass(name) {
    return creativePreviewMap?.[name || ''] ? ' creative-preview-name has-preview' : ' creative-preview-name';
}

function showCreativePreview(e, el) {
    const tip = document.getElementById('creativePreviewTip');
    const url = el?.dataset?.previewUrl || '';
    if (!tip || !url) return;
    tip.innerHTML = `<img src="${escAttr(url)}" alt="" onerror="this.onerror=null;this.src='${DEFAULT_CREATIVE_PREVIEW}'"><div class="creative-preview-tip-title">${esc(el.dataset.previewName || '')}</div>`;
    tip.classList.add('on');
    moveCreativePreview(e);
}

function moveCreativePreview(e) {
    const tip = document.getElementById('creativePreviewTip');
    if (!tip || !tip.classList.contains('on')) return;
    const pad = 14;
    const w = tip.offsetWidth || 180;
    const h = tip.offsetHeight || 330;
    let left = e.clientX + 18;
    let top = e.clientY + 18;
    if (left + w + pad > window.innerWidth) left = e.clientX - w - 18;
    if (top + h + pad > window.innerHeight) top = window.innerHeight - h - pad;
    tip.style.left = Math.max(pad, left) + 'px';
    tip.style.top = Math.max(pad, top) + 'px';
}

function hideCreativePreview() {
    const tip = document.getElementById('creativePreviewTip');
    if (tip) tip.classList.remove('on');
}

function sortVal(v) {
    if (v === null || v === undefined || v === '-' || v === '' || v === 0) return -1;
    if (typeof v === 'number') return v;
    const n = parseFloat(String(v).replace(/[$,%\s]/g,'').replace(/,/g,''));
    return isNaN(n) ? String(v) : n;
}
function cmp(a, b) {
    const ts = curTab();
    const va = sortVal(a), vb = sortVal(b);
    if (typeof va==='string'&&typeof vb==='string')
        return ts.sortDir==='asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    return ts.sortDir==='asc' ? va-vb : vb-va;
}
function activeScore(row) {
    if (!row || row._isOrphan) return 0;
    if (row.account_status !== undefined && row.account_status !== null && Number(row.account_status) !== 1) return 0;
    if (Number(row.status) === 1) return 1;
    if (isReallyActive(row)) return 1;
    if ((row.ads_active || row.camps_active || row.active || 0) > 0) return 1;
    return 0;
}
function normalizedStatus(v) {
    return String(v || '').trim().toUpperCase();
}
function parentDeliveryStatus(row, parent) {
    if (parent === 'campaign' && (row?.campaign_status !== undefined || row?.campaign_effective_status !== undefined)) {
        return realDeliveryStatus({
            status: row.campaign_status,
            effective_status: row.campaign_effective_status,
            account_status: row.account_status
        });
    }
    if (parent === 'adset' && (row?.adset_status !== undefined || row?.adset_effective_status !== undefined)) {
        return realDeliveryStatus({
            status: row.adset_status,
            effective_status: row.adset_effective_status,
            account_status: row.account_status
        });
    }
    return '';
}
function realDeliveryStatus(row) {
    let status = normalizedStatus(row?.status);
    let eff = normalizedStatus(row?.effective_status);
    const campaignParentStatus = parentDeliveryStatus(row, 'campaign');
    const adsetParentStatus = parentDeliveryStatus(row, 'adset');

    if (campaignParentStatus === 'ACTIVE') {
        if (status === 'CAMPAIGN_PAUSED') status = '';
        if (eff === 'CAMPAIGN_PAUSED') eff = '';
    }
    if (adsetParentStatus === 'ACTIVE') {
        if (status === 'ADSET_PAUSED') status = '';
        if (eff === 'ADSET_PAUSED') eff = '';
    }

    if (status === 'MANUAL_STOP' || eff === 'MANUAL_STOP') return 'MANUAL_STOP';
    if (['ARCHIVED','DELETED'].includes(status)) return status;
    if (eff && eff !== 'ACTIVE') return eff;
    if (status && status !== 'ACTIVE') return status;
    if (status === 'ACTIVE' && eff === 'ACTIVE') return 'ACTIVE';
    if (status === 'ACTIVE' && !eff) return 'ACTIVE';
    return eff || status || '';
}
function isReallyActive(row) {
    if (!row || row._isOrphan) return false;
    if (row.account_status !== undefined && row.account_status !== null && Number(row.account_status) !== 1) return false;
    return realDeliveryStatus(row) === 'ACTIVE';
}
function matchesDelivery(status, effectiveStatus, accountStatus, desired) {
    if (!desired) return true;
    const probe = {status, effective_status: effectiveStatus, account_status: accountStatus};
    return desired === 'ACTIVE'
        ? isReallyActive(probe)
        : realDeliveryStatus(probe) === desired;
}
function matchesParentDeliveryFilters(row, view = state.view) {
    if (!row || row._isOrphan) return true;
    if ((view === 'adset' || view === 'ad') && state.tabs.campaign?.delivery) {
        if (!matchesDelivery(row.campaign_status, row.campaign_effective_status, row.account_status, state.tabs.campaign.delivery)) {
            return false;
        }
    }
    if (view === 'ad' && state.tabs.adset?.delivery) {
        if (!matchesDelivery(row.adset_status, row.adset_effective_status, row.account_status, state.tabs.adset.delivery)) {
            return false;
        }
    }
    return true;
}
function activeTie(a, b) {
    return activeScore(b) - activeScore(a);
}
function orphanTie(a, b) {
    const ao = !!(a && a._isOrphan);
    const bo = !!(b && b._isOrphan);
    if (ao === bo) return 0;
    return ao ? 1 : -1;
}
function compareWithActive(a, b, primary) {
    return orphanTie(a, b) || primary || activeTie(a, b);
}

// -- STATE ----------------------------------------------------
function resetRouteState() {
    state.view = 'campaign';
    state.range = 'today';
    Object.keys(state.filters).forEach(k => state.filters[k] = null);
    Object.keys(TAB_DEFAULTS).forEach(k => { state.tabs[k] = {...TAB_DEFAULTS[k]}; });
    Object.keys(state.selections).forEach(k => { state.selections[k] = new Set(); });
}

function routeParamsFromLocation() {
    const search = location.search ? location.search.slice(1) : '';
    const hash = location.hash ? location.hash.slice(1) : '';
    return search || hash;
}

function routeUrl(params) {
    const q = params.toString();
    return location.pathname + (q ? '?' + q : '');
}

function pushURL(opts={}) {
    const p = new URLSearchParams();
    p.set('view', state.view);
    p.set('range', state.range);

    // Global filters
    Object.entries(state.filters).forEach(([k,v]) => {
        if (!v) return;
        if (k === 'account_id' && v === ACCOUNT_FILTER_ALL) return;
        if (k === 'account_name' && state.filters.account_id === ACCOUNT_FILTER_ALL) return;
        p.set('f_'+k, v);
    });

    // Tab state (all tabs, for bookmark restore)
    Object.entries(state.tabs).forEach(([tab, ts]) => {
        const def = TAB_DEFAULTS[tab] || {};
        if (ts.search)   p.set(tab+'_q', ts.search);
        if (ts.delivery) p.set(tab+'_dlv', ts.delivery);
        if (ts.sortCol && ts.sortCol !== def.sortCol) p.set(tab+'_sort', ts.sortCol);
        if (ts.sortDir && ts.sortDir !== def.sortDir) p.set(tab+'_dir',  ts.sortDir);
        ['period','tab','geo','metrics','bm_id','sel','stream_id','offer_id','offer_geo','status'].forEach(k => {
            if (ts[k] && ts[k] !== def[k]) p.set(tab+'_'+k, ts[k]);
        });
    });

    // Selections
    Object.entries(state.selections).forEach(([k,s]) => {
        const values = [...s].map(v => String(v || '').trim()).filter(v => v && v !== 'undefined' && v !== 'null');
        if (values.length) p.set('sel_'+k, values.join(','));
    });

    const url = routeUrl(p);
    const cur = location.pathname + location.search;
    if (url === cur) return;
    history[opts.replace ? 'replaceState' : 'pushState'](null, '', url);
}

function readURL() {
    const raw = routeParamsFromLocation();
    if (!raw) return false;
    const p = new URLSearchParams(raw);
    resetRouteState();

    state.view  = p.get('view')  || 'campaign';
    state.range = p.get('range') || 'today';
    if (!state.tabs[state.view] && !TABLE_LEVELS.includes(state.view)) state.view = 'geo';

    // Filters
    FILTER_ORDER.forEach(k => { state.filters[k] = p.get('f_'+k) || null; });
    ['launch_mode','bm_name','account_name','campaign_name','adset_name'].forEach(k => {
        state.filters[k] = p.get('f_'+k) || null;
    });
    ensureDefaultAccountFilter(state.view);

    // Tab states
    Object.keys(state.tabs).forEach(tab => {
        const ts = state.tabs[tab];
        ts.search   = p.get(tab+'_q')   || '';
        if ('delivery' in ts) ts.delivery = p.get(tab+'_dlv') || null;
        if ('sortCol' in ts) ts.sortCol = p.get(tab+'_sort') || ts.sortCol;
        if ('sortDir' in ts) ts.sortDir = p.get(tab+'_dir')  || ts.sortDir;
        ['period','tab','geo','metrics','bm_id','sel','stream_id','offer_id','offer_geo','status'].forEach(k => {
            if (k in ts) ts[k] = p.get(tab+'_'+k) || ts[k];
        });
    });
    if (p.has('stream_id') && !p.has('streams_stream_id')) {
        state.tabs.streams.stream_id = p.get('stream_id') || '';
    } else if (!p.has('streams_stream_id')) {
        state.tabs.streams.stream_id = '';
    }

    // Selections
    Object.keys(state.selections).forEach(k => {
        const str = p.get('sel_'+k);
        state.selections[k] = str
            ? new Set(str.split(',').map(v => String(v || '').trim()).filter(v => v && v !== 'undefined' && v !== 'null'))
            : new Set();
    });

    return true;
}

// -- FILTERS --------------------------------------------------
function setFilter(key, val, label=null) {
    const idx = FILTER_ORDER.indexOf(key);
    if (idx !== -1) {
        // Reset everything to the right
        for (let i = idx+1; i < FILTER_ORDER.length; i++) {
            const k = FILTER_ORDER[i];
            state.filters[k] = null;
            const nameKey = k.replace('_id','_name');
            if (nameKey in state.filters) state.filters[nameKey] = null;
        }
    }
    state.filters[key] = val;
    if (label && key.endsWith('_id')) state.filters[key.replace('_id','_name')] = label;
    else if (key === 'geo') {} // no label field
    renderFilterTags();
}

function setReportFilter(key, val, label=null) {
    val = val || null;
    if (key === 'geo') {
        state.filters.geo = val;
        _geoSel = val ? new Set(String(val).split(',').map(g=>g.trim()).filter(Boolean)) : new Set();
        renderFilterTags();
    } else {
        setFilter(key, val, val ? label : null);
        ensureDefaultAccountFilter();
        if (key === 'account_id') {
            if (val === ACCOUNT_FILTER_ACTIVE) state.filters.account_name = 'All Active';
            else if (val === ACCOUNT_FILTER_ALL) state.filters.account_name = 'All accounts';
            else if (!val && viewUsesAccountFilter()) {
                state.filters.account_id = ACCOUNT_FILTER_ALL;
                state.filters.account_name = 'All accounts';
            }
        }
        if (key === 'launch_date' && !val) state.filters.launch_mode = null;
    }
    pushURL();
    reload();
    loadCards();
}

function setLaunchMode(value) {
    state.filters.launch_mode = value && value !== 'exact' ? value : null;
    pushURL();
    reload();
    loadCards();
}

function clearAllReportFilters() {
    clearFilter('all');
    for (const view of Object.keys(state.tabs)) {
        if ('delivery' in state.tabs[view]) state.tabs[view].delivery = null;
    }
    renderFilterTags();
    renderDeliveryBadges();
    pushURL();
    reload();
    loadCards();
}

function clearFilter(key='all') {
    if (key === 'all') {
        Object.keys(state.filters).forEach(k => state.filters[k] = null);
        ensureDefaultAccountFilter();
    } else {
        setFilter(key, null);
        ensureDefaultAccountFilter();
    }
    renderFilterTags();
    pushURL();
}

function renderFilterTags() {
    const allowed = currentReportFilters();
    const controls = document.getElementById('reportControls');
    if (controls) controls.style.display = allowed.size ? 'flex' : 'none';
    const btn = document.getElementById('btnResetFilters');
    const hasGlobal = hasMeaningfulGlobalFilters();
    const hasDelivery = Object.values(state.tabs).some(ts => ts && ts.delivery);
    if (btn) btn.style.display = hasGlobal || hasDelivery ? '' : 'none';
    renderReportFilterSelects();
    if ([...allowed].some(k => k !== 'delivery')) loadReportFilterOptions();
}

function currentReportFilters() {
    return new Set(REPORT_FILTERS_BY_VIEW[state.view] || []);
}

function currentReportFiltersFor(view) {
    return new Set(REPORT_FILTERS_BY_VIEW[view] || []);
}

function setFilterFieldVisible(id, visible) {
    const el = document.getElementById(id);
    const wrap = el?.closest('.filter-field');
    if (wrap) wrap.style.display = visible ? '' : 'none';
}

function viewUsesAccountFilter(view = state.view) {
    return currentReportFiltersFor(view).has('account_id');
}

function isPseudoAccountFilterValue(value) {
    return value === ACCOUNT_FILTER_ACTIVE || value === ACCOUNT_FILTER_ALL;
}

function exactAccountFilterValue() {
    const value = state.filters.account_id || '';
    return value && !isPseudoAccountFilterValue(value) ? value : '';
}

function currentAccountScope() {
    if (!viewUsesAccountFilter()) return '';
    const value = state.filters.account_id || '';
    if (value === ACCOUNT_FILTER_ACTIVE) return 'active';
    return '';
}

function ensureDefaultAccountFilter(view = state.view) {
    if (!viewUsesAccountFilter(view)) return;
    if (view === 'rules_check' && (!state.filters.account_id || state.filters.account_id === ACCOUNT_FILTER_ALL)) {
        state.filters.account_id = ACCOUNT_FILTER_ACTIVE;
        state.filters.account_name = 'All Active';
        return;
    }
    if (!state.filters.account_id) {
        state.filters.account_id = ACCOUNT_FILTER_ALL;
        state.filters.account_name = 'All accounts';
    }
}

function hasMeaningfulGlobalFilters() {
    return Object.entries(state.filters).some(([key, value]) => {
        if (!value) return false;
        if (key === 'account_id') return value !== ACCOUNT_FILTER_ALL;
        if (key === 'account_name') return state.filters.account_id !== ACCOUNT_FILTER_ALL;
        if (key.endsWith('_name')) {
            const idKey = key.replace('_name', '_id');
            if (idKey in state.filters) return false;
        }
        return true;
    });
}

function reportOptionHtml(items, selected, placeholder, selectedLabel='') {
    const exists = selected && items.some(x => String(x.id) === String(selected));
    const prefix = `<option value="">${esc(placeholder)}</option>`;
    const injected = selected && !exists ? `<option value="${escAttr(selected)}" selected>${esc(selectedLabel || selected)}</option>` : '';
    return prefix + injected + items.map(x => {
        const id = String(x.id ?? '');
        const name = String(x.name ?? id);
        return `<option value="${escAttr(id)}"${id===String(selected||'')?' selected':''}>${esc(name)}</option>`;
    }).join('');
}

function accountOptionHtml(items, selected, selectedLabel='') {
    const base = [
        {id: ACCOUNT_FILTER_ALL, name: 'All accounts'},
        {id: ACCOUNT_FILTER_ACTIVE, name: 'All Active'},
        ...items,
    ];
    const exists = selected && base.some(x => String(x.id) === String(selected));
    const injected = selected && !exists ? `<option value="${escAttr(selected)}" selected>${esc(selectedLabel || selected)}</option>` : '';
    return injected + base.map(x => {
        const id = String(x.id ?? '');
        const name = String(x.name ?? id);
        return `<option value="${escAttr(id)}"${id===String(selected||'')?' selected':''}>${esc(name)}</option>`;
    }).join('');
}

const RULES_V1_VERDICTS = ['STOP','START','OK','HOLD_STOP','NO_GEO','NO_RULES','IGNORED_STATUS','MANUAL_STOP','NO_DATA'];
const RULES_V2_VERDICTS = ['PAUSE_TODAY','STOP','START','START_DELAYED','HOLD_STOP','PROTECT','WATCH','DISABLED','OK','NO_GEO','NO_RULES','IGNORED_STATUS','MANUAL_STOP','NO_DATA'];

function verdictOptionHtml(items, selected, placeholder) {
    return `<option value="">${esc(placeholder)}</option>` + items.map(v =>
        `<option value="${escAttr(v)}"${String(selected || '') === v ? ' selected' : ''}>${esc(v)}</option>`
    ).join('');
}

function renderReportFilterSelects() {
    const allowed = currentReportFilters();
    const map = [
        ['fltGeo', 'geos', 'geo', 'All'],
        ['fltBm', 'bms', 'bm_id', 'All BM', 'bm_name'],
        ['fltAccount', 'accounts', 'account_id', 'All accounts', 'account_name'],
        ['fltLaunchDate', 'launch_dates', 'launch_date', 'All launch dates'],
        ['fltCampaign', 'campaigns', 'campaign_id', 'All campaigns', 'campaign_name'],
        ['fltAdset', 'adsets', 'adset_id', 'All ad sets', 'adset_name'],
        ['fltCreo', 'creatives', 'ad_name', 'All creatives'],
    ];
    for (const [id, optKey, filterKey, placeholder, labelKey] of map) {
        const el = document.getElementById(id);
        if (!el) continue;
        setFilterFieldVisible(id, allowed.has(filterKey));
        const selected = state.filters[filterKey] || '';
        const label = labelKey ? (state.filters[labelKey] || '') : selected;
        el.innerHTML = filterKey === 'account_id'
            ? accountOptionHtml(reportFilterOptions[optKey] || [], selected, label)
            : reportOptionHtml(reportFilterOptions[optKey] || [], selected, placeholder, label);
        el.value = selected;
    }
    setFilterFieldVisible('fltDelivery', allowed.has('delivery'));
    const delivery = document.getElementById('fltDelivery');
    if (delivery) delivery.value = curTab().delivery || '';
    setFilterFieldVisible('fltLaunchMode', allowed.has('launch_date'));
    const launchMode = document.getElementById('fltLaunchMode');
    if (launchMode) {
        launchMode.value = state.filters.launch_mode || 'exact';
        launchMode.disabled = !state.filters.launch_date;
    }
    setFilterFieldVisible('fltV1Verdict', allowed.has('v1_verdict'));
    const v1 = document.getElementById('fltV1Verdict');
    if (v1) {
        v1.innerHTML = verdictOptionHtml(RULES_V1_VERDICTS, state.filters.v1_verdict, 'All');
        v1.value = state.filters.v1_verdict || '';
    }
    setFilterFieldVisible('fltV2Verdict', allowed.has('v2_verdict'));
    const v2 = document.getElementById('fltV2Verdict');
    if (v2) {
        v2.innerHTML = verdictOptionHtml(RULES_V2_VERDICTS, state.filters.v2_verdict, 'All');
        v2.value = state.filters.v2_verdict || '';
    }
}

function setRulesVerdictFilter(kind, value) {
    const key = kind === 'v1' ? 'v1_verdict' : 'v2_verdict';
    state.filters[key] = value || null;
    renderFilterTags();
    pushURL();
    renderCurrentTable();
}

function filterOptionsParams() {
    const allowed = currentReportFilters();
    const p = new URLSearchParams();
    ['geo','bm_id','campaign_id','adset_id','ad_name'].forEach(k => {
        if (allowed.has(k) && state.filters[k]) p.set(k, state.filters[k]);
    });
    if (allowed.has('account_id')) {
        const accountId = exactAccountFilterValue();
        const scope = currentAccountScope();
        if (accountId) p.set('account_id', accountId);
        else if (scope) p.set('account_scope', scope);
    }
    return p;
}

async function loadReportFilterOptions() {
    const allowed = currentReportFilters();
    if (![...allowed].some(k => k !== 'delivery')) return;
    const params = filterOptionsParams();
    const key = state.view + '?' + params.toString();
    if (key === _filterOptionsKey && (reportFilterOptions.geos || []).length) return;
    _filterOptionsKey = key;
    const seq = ++_filterOptionsSeq;
    try {
        const res = await fetch('/api/filter_options.php?' + params.toString(), {cache:'no-store'});
        const json = await res.json();
        if (seq !== _filterOptionsSeq || !json.ok) return;
        reportFilterOptions = json.data || reportFilterOptions;
        renderReportFilterSelects();
    } catch (e) {}
}

function buildAPIParams(extra={}) {
    const p = new URLSearchParams({range: state.range, ...extra});
    const allowed = currentReportFilters();
    const level = extra.level || state.view;
    const selectedCampaignIds = [...(state.selections.campaign || new Set())]
        .map(v => String(v || '').trim())
        .filter(v => v && v !== 'undefined' && v !== 'null');
    const selectedAdsetIds = [...(state.selections.adset || new Set())]
        .map(v => String(v || '').trim())
        .filter(v => v && v !== 'undefined' && v !== 'null');
    if (allowed.has('geo') && state.filters.geo)                 p.set('geo',         state.filters.geo);
    if (allowed.has('account_id')) {
        const accountId = exactAccountFilterValue();
        const scope = currentAccountScope();
        if (accountId) p.set('account_id', accountId);
        else if (scope) p.set('account_scope', scope);
    }
    if (allowed.has('launch_date') && state.filters.launch_date) p.set('launch_date', state.filters.launch_date);
    if (allowed.has('launch_date') && state.filters.launch_mode) p.set('launch_mode', state.filters.launch_mode);
    if (allowed.has('campaign_id')) {
        const campaignFilter = ['adset', 'ad'].includes(level) && selectedCampaignIds.length
            ? selectedCampaignIds.join(',')
            : state.filters.campaign_id;
        if (campaignFilter) p.set('campaign_id', campaignFilter);
    }
    if (allowed.has('adset_id')) {
        const adsetFilter = level === 'ad' && selectedAdsetIds.length
            ? selectedAdsetIds.join(',')
            : state.filters.adset_id;
        if (adsetFilter) p.set('adset_id', adsetFilter);
    }
    if (allowed.has('bm_id') && state.filters.bm_id)             p.set('bm_id',       state.filters.bm_id);
    if (allowed.has('ad_name') && state.filters.ad_name)         p.set('ad_name',     state.filters.ad_name);

    // Pass delivery filters from parent levels
    if (allowed.has('delivery') && ['campaign','adset','ad'].includes(level) && state.tabs.account?.delivery)
        p.set('account_status', state.tabs.account.delivery);
    if (allowed.has('delivery') && ['adset','ad'].includes(level) && state.tabs.campaign?.delivery)
        p.set('campaign_status', state.tabs.campaign.delivery);
    if (allowed.has('delivery') && level === 'ad' && state.tabs.adset?.delivery)
        p.set('adset_status', state.tabs.adset.delivery);
    // Current tab also passes its delivery filter to the server
    const curDelivery = state.tabs[state.view]?.delivery || state.tabs[level]?.delivery;
    if (allowed.has('delivery') && curDelivery) p.set('effective_status', curDelivery);

    return p;
}

function applyGeoFilter(rows) {
    if (!state.filters.geo) return rows;
    const geos = state.filters.geo.toUpperCase().split(',').map(g=>g.trim()).filter(Boolean);
    if (!geos.length) return rows;
    return rows.filter(r => {
        const name = (r.campaign_name || r.name || '').toUpperCase();
        return geos.some(g => name.includes('_'+g+'_') || name.includes('_'+g+' ') || name.endsWith('_'+g));
    });
}

function buildRulesCheckParams() {
    const p = new URLSearchParams();
    if (currentReportFilters().has('bm_id') && state.filters.bm_id) p.set('bm_id', state.filters.bm_id);
    return p;
}

function attachRulesCheckVerdicts(campaignRows, rulesJson) {
    const payload = rulesJson?.data || rulesJson || {};
    const verdicts = Array.isArray(payload.verdicts) ? payload.verdicts : [];
    const byCampaign = new Map();
    verdicts.forEach(v => {
        const id = String(v.campaign_id || v.id || '').trim();
        if (id) byCampaign.set(id, v);
    });
    return campaignRows.map(row => {
        const verdictRaw = byCampaign.get(String(row.id)) || null;
        const verdict = verdictRaw ? {
            ...verdictRaw,
            today: verdictRaw.today || verdictRaw.data_1d || {},
            last7: verdictRaw.last7 || verdictRaw.data_7d || {},
            last30: verdictRaw.last30 || verdictRaw.data_30d || {},
        } : null;
        return {
            ...row,
            rules_check: verdict,
            v1_verdict: verdict?.verdict || 'NO_DATA',
            v2_verdict: verdict?.candidate_verdict || 'NO_DATA',
        };
    });
}

function buildCreativeRankParams(extra={}) {
    const params = buildAPIParams({level:'ad', ...extra});
    params.delete('range');
    return params;
}

async function fetchCreativeRankMap(extra={}) {
    const res = await fetch('/api/creative_rank.php?' + buildCreativeRankParams(extra).toString());
    const text = await res.text();
    let json = null;
    try {
        json = text ? JSON.parse(text) : null;
    } catch (parseErr) {
        throw new Error((text || '').trim().slice(0, 220) || `HTTP ${res.status} | Empty API response`);
    }
    if (!res.ok) {
        throw new Error(`HTTP ${res.status}${text ? ' | ' + text.trim().slice(0, 220) : ''}`);
    }
    if (!json || typeof json !== 'object') {
        throw new Error(`HTTP ${res.status} | Empty API response body`);
    }
    if (!json.ok) throw new Error(json.error || 'creative rank API error');
    return json.data?.rank_map || {};
}

async function readApiJson(res, label = 'API') {
    const text = await res.text();
    let json = null;
    try {
        json = text ? JSON.parse(text) : null;
    } catch (parseErr) {
        const snippet = (text || '').trim().slice(0, 220);
        throw new Error(snippet || `HTTP ${res.status} | Empty ${label} response`);
    }
    if (!res.ok) {
        const snippet = (text || '').trim().slice(0, 220);
        throw new Error(`HTTP ${res.status}${snippet ? ' | ' + snippet : ''}`);
    }
    if (!json || typeof json !== 'object') {
        throw new Error(`HTTP ${res.status} | Empty ${label} response body`);
    }
    return json;
}

// -- VIEW NAVIGATION ------------------------------------------
function setView(v, skipLoad=false) {
    // Save current tab search
    const srch = document.getElementById('srch');
    if (srch) curTab().search = srch.value;

    state.view = v;
    ensureDefaultAccountFilter(v);
    renderNavActive();
    renderSearchBar();
    renderFilterTags();
    renderDeliveryBadges();
    syncWidthResetButton();
    updateBulk();

    // Show/hide wraps
    const isTable = TABLE_LEVELS.includes(v);
    document.getElementById('tblwrap').style.display      = isTable ? '' : 'none';
    document.getElementById('monthWrap').style.display    = v==='month'    ? 'flex'  : 'none';
    document.getElementById('bmCardsWrap').style.display  = v==='bm_cards' ? 'flex'  : 'none';
    document.getElementById('creoWrap').style.display     = v==='creo'     ? 'block' : 'none';
    document.getElementById('topcreoWrap').style.display  = v==='topcreo'  ? 'block' : 'none';
    document.getElementById('creativeCalendarWrap').style.display = v==='creative_calendar' ? 'flex' : 'none';
    document.getElementById('campsCalendarWrap').style.display = v==='camps_calendar' ? 'flex' : 'none';
    document.getElementById('streamsWrap').style.display  = v==='streams'  ? 'flex'  : 'none';
    document.getElementById('offersWrap').style.display   = v==='offers'   ? 'block' : 'none';
    document.getElementById('geoWrap').style.display        = v==='geo'        ? 'block' : 'none';
    document.getElementById('geotrendsWrap').style.display  = v==='geotrends'  ? 'flex'  : 'none';
    document.getElementById('geodiffWrap').style.display    = v==='geodiff'    ? 'block' : 'none';
    document.getElementById('trendsWrap').style.display      = v==='trends'      ? 'flex'  : 'none';
    document.getElementById('geocabsWrap').style.display     = v==='geocabs'     ? 'flex'  : 'none';
    document.getElementById('tasksWrap').style.display       = v==='tasks'       ? 'flex'  : 'none';

    // Restore tab state
    const ts = curTab();
    if (srch) srch.value = ts.search || '';
    const clearBtn = document.getElementById('clearBtn');
    if (clearBtn) clearBtn.style.display = ts.search ? '' : 'none';
    const delivery = document.getElementById('fltDelivery');
    if (delivery) delivery.value = ts.delivery || '';

    pushURL();
    if (!skipLoad) reload();
}

function renderNavActive() {
    document.querySelectorAll('.lnav-item').forEach(el => {
        const id = el.id.replace('lnav-','');
        el.classList.toggle('active', id === state.view);
    });
    const delivery = document.getElementById('fltDelivery');
    if (delivery?.options?.[2]) delivery.options[2].textContent = (state.view === 'account' || state.view === 'bm') ? 'Banned' : 'Paused';
}

function renderSearchBarLegacy() {
    // Update adset/ad labels
    const adsetLbl = document.getElementById('adsetLabel');
    const adLbl    = document.getElementById('adLabel');
    const srch = document.getElementById('srch');
    if (adsetLbl) adsetLbl.textContent = 'Ad Sets';
    if (adLbl)    adLbl.textContent    = 'Ads';
    if (srch) srch.placeholder = state.view === 'tasks'
        ? 'Search by task #, ID, payload, error...'
        : 'Search by name, ID...';
}

// -- DRILL DOWN -----------------------------------------------
function drillDown(id) {
    const nextMap = {bm:'account', account:'campaign', campaign:'adset', rules_check:'adset', adset:'ad', ad:'creo'};
    const next = nextMap[state.view];
    if (!next) return;
    id = String(id);
    const row = rows.find(r => String(r.id) === id);
    const name = row?.name || id;

    if (state.view === 'bm')       setFilter('bm_id',       id, name);
    else if (state.view === 'account')  setFilter('account_id',  id, name);
    else if (state.view === 'campaign' || state.view === 'rules_check') setFilter('campaign_id', id, name);
    else if (state.view === 'adset')    setFilter('adset_id',    id, name);
    else if (state.view === 'ad') {
        setFilter('ad_name', name);
        setView('creo');
        return;
    }
    setView(next);
}

function drillDownGeo(geo) {
    setFilter('geo', geo);
    setView('campaign');
}

function drillDownBm(bmId, bmName) {
    setFilter('bm_id', bmId, bmName);
    setView('account');
}

function drillDownAccount(accId, accName) {
    setFilter('account_id', accId, accName);
    setView('campaign');
}

// -- RELOAD ---------------------------------------------------
function reload() {
    const v = state.view;
    if (v === 'month') loadMonthPeriods();
    else if (v === 'creo') loadCreoData();
    else if (v === 'topcreo') loadTopCreoData();
    else if (v === 'creative_calendar') loadCreativeCalendarData();
    else if (v === 'camps_calendar') loadCampsCalendarData();
    else if (v === 'streams') loadStreamsData();
    else if (v === 'offers') loadOffersData();
    else if (v === 'geo') loadGeoData();
    else if (v === 'geotrends') loadGeoTrendsData();
    else if (v === 'geodiff') loadGeoDiffData();
    else if (v === 'geocabs')    loadGeocabsData();
    else if (v === 'bm_cards') loadBmCardsData();
    else if (v === 'tasks') loadTasksData();
    else if (v === 'trends') loadTrendsData();
    else loadData();
}

// -- NAV ACTIONS ----------------------------------------------
function setLevel(v) { setView(v); }
function openMonthFromLevelNav() { setView('month'); }

function resetState() {
    resetRouteState();
    state.view = 'geo';
    renderNavActive();
    renderSearchBar();
    renderFilterTags();
    renderDeliveryBadges();
    syncRangeUI();
    const srch = document.getElementById('srch'); if (srch) srch.value = '';
    setView('geo');
}
function openCreoView() { setView('creo'); }
function drillDownCreoName(name) {
    if (!name) return;
    setFilter('ad_name', name, name);
    setView('campaign');
}
function openGeoView()  { setView('geo'); }

function setRange(v) {
    state.range = v;
    syncRangeUI();
    closeDropdowns();
    pushURL();
    reload();
    loadCards();
}

function syncRangeUI() {
    const label = document.getElementById('rangeLabel');
    if (label) label.textContent = RANGE_LABELS[state.range] || state.range;
    document.querySelectorAll('#ddRange .dd-item').forEach(el => {
        el.classList.toggle('active', el.dataset.range === state.range);
    });
    updateTzBadge();
}

const DELIVERY_LABELS = {ACTIVE:'Active', PAUSED:'Paused', IN_PROCESS:'Learning'};
const DELIVERY_LABELS_ACCOUNT = {ACTIVE:'Active', PAUSED:'Banned', IN_PROCESS:'Learning'};
const VIEW_SHORT = {bm:'BM', account:'Acc', campaign:'Camp', adset:'Ad Set', ad:'Ad'};
const ACCOUNT_STATUS_LABELS = {1:'Account active',2:'Account off',3:'Account debt',7:'Account review',9:'Account grace'};


function renderDeliveryBadges() {
    const bar = document.getElementById('deliveryBadges');
    if (!bar) return;

    const curLevel = VIEW_LEVEL[state.view] ?? -1;

    const deliveryControl = document.getElementById('deliveryControl');
    const showFtabs = currentReportFilters().has('delivery');
    if (deliveryControl) deliveryControl.style.display = showFtabs ? '' : 'none';
    const delivery = document.getElementById('fltDelivery');
    if (delivery) delivery.value = curTab().delivery || '';

    if (curLevel < 0) { bar.innerHTML = ''; return; }

    const badges = [];
    for (const [view, ts] of Object.entries(state.tabs)) {
        if (!ts.delivery) continue;
        const viewLevel = VIEW_LEVEL[view] ?? -1;
        if (viewLevel < 0) continue;
        // Show badge only when current tab is below the filter tab
        // and not on the filter tab itself (already visible via buttons)
        if (curLevel <= viewLevel) continue;
        const short = VIEW_SHORT[view];
        if (!short) continue;
        const labelMap = (view === 'account' || view === 'bm') ? DELIVERY_LABELS_ACCOUNT : DELIVERY_LABELS;
        const label = labelMap[ts.delivery] || ts.delivery;
        badges.push(`<span class="delivery-badge" onclick="setView('${view}',true)">${short}: ${label}<span class="delivery-badge-x" onclick="event.stopPropagation();clearDelivery('${view}')">x</span></span>`);
    }
    bar.innerHTML = badges.join('');
}

function syncWidthResetButton() {
    const visible = TABLE_VIEW_CONTROLS.has(state.view);
    const btn = document.getElementById('btnResetWidthsTop');
    if (btn) btn.style.display = visible ? '' : 'none';
    const wrap = document.getElementById('btnBalanceWidthsWrap');
    if (wrap) wrap.style.display = visible ? '' : 'none';
    const chk = document.getElementById('btnBalanceWidthsTop');
    if (chk) chk.checked = loadTableColBalanceState();
}

function clearDelivery(view) {
    if (state.tabs[view]) state.tabs[view].delivery = null;
    renderDeliveryBadges();
    pushURL();
    if (state.view === view) {
        const delivery = document.getElementById('fltDelivery');
        if (delivery) delivery.value = '';
        renderCurrentTable();
    }
}

function setDeliveryFilter(val) {
    curTab().delivery = val;
    const delivery = document.getElementById('fltDelivery');
    if (delivery) delivery.value = val || '';
    renderDeliveryBadges();
    renderFilterTags();
    pushURL();
    // Accounts/BM data already loaded; just re-render
    if (state.view === 'account' || state.view === 'bm') {
        if (window._lastAccts) renderAccountsTable(window._lastAccts);
    } else {
        renderCurrentTable();
    }
}

function applySearch() {
    const q = document.getElementById('srch').value;
    curTab().search = q;
    document.getElementById('clearBtn').style.display = q ? '' : 'none';
    pushURL({replace:true});
    renderCurrentTable();
}

function handleSearchKey(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    applySearch();
}

function clearSearch() {
    document.getElementById('srch').value = '';
    curTab().search = '';
    document.getElementById('clearBtn').style.display = 'none';
    pushURL({replace:true});
    renderCurrentTable();
}

function clearFilters() {
    clearFilter('all');
    setDeliveryFilter(null);
    clearSearch();
    reload();
}

function renderCurrentTable() {
    const v = state.view;
    if (v === 'month') renderMonthTable();
    else if (v === 'bm_cards') renderBmCards();
    else if (v === 'creo') renderCreoTable();
    else if (v === 'creative_calendar') renderCreativeCalendarTable();
    else if (v === 'camps_calendar') renderCampsCalendarTable();
    else if (v === 'streams') renderStreamsTable();
    else if (v === 'offers') renderOffersTable();
    else if (v === 'geo') renderGeoTable();
    else if (v === 'geodiff') renderGeoDiffTable();
    else if (v === 'tasks') renderTasksTable();
    else if ((v === 'bm' || v === 'account') && window._lastAccts) renderAccountsTable(window._lastAccts);
    else renderTable();
}

// -- CARDS ----------------------------------------------------
function updateCards(T) {
    if (!T) return;
    const profit = (T.revenue||0) - (T.spend||0);
    const roi    = T.spend > 0 ? profit/T.spend*100 : 0;
    const fmt    = v => v ? '$'+parseFloat(v).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) : '-';
    document.getElementById('cardSpend').textContent   = fmt(T.spend);
    document.getElementById('cardRevenue').textContent = fmt(T.revenue);
    const profitEl = document.getElementById('cardProfit');
    profitEl.textContent = fmt(profit);
    profitEl.className   = 'card-val ' + (profit>0?'g':profit<0?'r':'');
    const roiEl = document.getElementById('cardRoi');
    roiEl.textContent = T.spend > 0 ? roi.toFixed(1)+'%' : '-';
    roiEl.className   = 'card-val ' + (roi>0?'g':roi<0?'r':'');
    document.getElementById('cardLeads').textContent = T.leads ? fN(T.leads) : '-';
    document.getElementById('cardRegs').textContent  = T.regs  ? fN(T.regs)  : '-';
    document.getElementById('cardDeps').textContent  = T.deps  ? fN(T.deps)  : '-';
}
async function loadCards() {
    try {
        const params = new URLSearchParams({range: state.range});
        if (state.filters.bm_id) params.set('bm_id', state.filters.bm_id);
        const res  = await fetch('/api/totals.php?'+params);
        const json = await res.json();
        if (json.ok) updateCards(json.data);
    } catch(e) {}
}

function setBmCardsPeriod(period) {
    state.tabs.bm_cards.period = period;
    pushURL();
    loadBmCardsData();
}

async function loadBmCardsData() {
    const grid = document.getElementById('bmCardsGrid');
    if (!grid) return;
    grid.innerHTML = SPIN;
    try {
        const period = state.tabs.bm_cards.period || '14d';
        document.querySelectorAll('.bm-map-period').forEach(btn => btn.classList.toggle('active', btn.id === 'bmCardsPeriod' + period));
        const res = await fetch('/api/bm_cards.php?period=' + encodeURIComponent(period));
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            throw new Error(text.trim().slice(0, 220) || 'Empty API response');
        }
        if (!json.ok) throw new Error(json.error || 'API error');
        const data = json.data || {};
        bmCardRows = data.rows || [];
        const label = document.getElementById('bmCardsRangeLabel');
        if (label) label.textContent = data.date_from && data.date_to
            ? `${data.date_from} - ${data.date_to} vs ${data.prev_date_from} - ${data.prev_date_to}`
            : '';
        renderBmCards();
    } catch (e) {
        grid.innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function bmDeltaHtml(v, invert=false) {
    if (v === null || v === undefined) return '<span class="bm-map-delta">new</span>';
    const cls = v > 0 ? (invert ? 'neg' : 'pos') : v < 0 ? (invert ? 'pos' : 'neg') : '';
    const sign = v > 0 ? '+' : '';
    return `<span class="bm-map-delta ${cls}">${sign}${Number(v).toFixed(1)}%</span>`;
}

function bmSignalClass(signal) {
    return String(signal || '').toLowerCase().replace(/\s+/g, '-');
}

function bmMoney(v) {
    const n = Number(v || 0);
    return n ? '$' + n.toLocaleString('en', {maximumFractionDigits:0}) : '-';
}

function bmInt(v) {
    return Number(v || 0).toLocaleString('en');
}

function bmSparkline(days) {
    if (!days || !days.length) return '';
    const w = 320, h = 52, pad = 5;
    const vals = days.flatMap(d => [Number(d.spend || 0), Number(d.profit || 0)]);
    let min = Math.min(...vals, 0), max = Math.max(...vals, 0);
    if (Math.abs(max - min) < 0.0001) { max += 1; min -= 1; }
    const x = i => pad + (days.length === 1 ? 0 : i * (w - pad * 2) / (days.length - 1));
    const y = v => h - pad - ((v - min) / (max - min)) * (h - pad * 2);
    const line = key => days.map((d,i) => `${x(i).toFixed(1)},${y(Number(d[key] || 0)).toFixed(1)}`).join(' ');
    const zeroY = y(0).toFixed(1);
    return `<svg class="bm-map-chart" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
        <line x1="${pad}" y1="${zeroY}" x2="${w-pad}" y2="${zeroY}" stroke="#dddfe2" stroke-width="1"/>
        <polyline points="${line('spend')}" fill="none" stroke="#1877f2" stroke-width="2.2"/>
        <polyline points="${line('profit')}" fill="none" stroke="#31a24c" stroke-width="2.2"/>
    </svg>`;
}

function renderBmCards() {
    const grid = document.getElementById('bmCardsGrid');
    if (!grid) return;
    document.querySelectorAll('.bm-map-period').forEach(btn => btn.classList.toggle('active', btn.id === 'bmCardsPeriod' + (state.tabs.bm_cards.period || '14d')));
    if (!bmCardRows.length) {
        grid.innerHTML = '<div class="tbl-empty">No BM data</div>';
        return;
    }
    let html = '<div class="bm-cards-wrap">';
    bmCardRows.forEach(row => {
        const cur = row.current || {};
        const delta = row.delta || {};
        const sigCls = bmSignalClass(row.signal);
        const profitColor = Number(cur.profit || 0) > 0 ? 'color:var(--green)' : Number(cur.profit || 0) < 0 ? 'color:var(--red)' : '';
        html += `<div class="bm-map-card ${sigCls}">
            <div class="bm-map-head">
                <div>
                    <div class="bm-map-name" data-bm-id="${escAttr(row.bm_id)}" data-bm-name="${escAttr(row.bm_name)}" onclick="drillDownBm(this.dataset.bmId,this.dataset.bmName)">${esc(row.bm_name)}</div>
                    <div class="bm-map-id">${esc(row.bm_id)}</div>
                </div>
                <div class="bm-map-signal ${sigCls}">${esc(row.signal)}</div>
            </div>
            <div class="bm-map-body">
                <div class="bm-map-structure">
                    <span>Accounts: ${bmInt(row.accounts_active)} / ${bmInt(row.accounts_total)}</span>
                    <span>Campaigns: ${bmInt(row.campaigns_active)} / ${bmInt(row.campaigns_total)}</span>
                </div>
                <div class="bm-map-metrics">
                    <div class="bm-map-metric"><div class="bm-map-lbl">Spend</div><div class="bm-map-val">${bmMoney(cur.spend)}</div>${bmDeltaHtml(delta.spend_pct, true)}</div>
                    <div class="bm-map-metric"><div class="bm-map-lbl">Profit</div><div class="bm-map-val" style="${profitColor}">${bmMoney(cur.profit)}</div>${bmDeltaHtml(delta.profit_pct)}</div>
                    <div class="bm-map-metric"><div class="bm-map-lbl">ROI</div><div class="bm-map-val">${cur.roi === null || cur.roi === undefined ? '-' : Number(cur.roi).toFixed(1) + '%'}</div><div class="bm-map-delta">${bmInt(cur.deps)} deps</div></div>
                </div>
                ${bmSparkline(row.days)}
                <div class="bm-geo-list">
                    ${(row.geos || []).slice(0,6).map(g => {
                        const pc = Number(g.profit || 0) > 0 ? 'good' : Number(g.profit || 0) < 0 ? 'bad' : '';
                        return `<div class="bm-geo-row">
                            <div class="bm-geo-code">${esc(g.geo)}</div>
                            <div class="bm-geo-cell">${bmMoney(g.spend)}</div>
                            <div class="bm-geo-cell ${pc}">${bmMoney(g.profit)}</div>
                            <div class="bm-geo-deps">${g.profit_delta_pct === null || g.profit_delta_pct === undefined ? 'new' : (g.profit_delta_pct > 0 ? '+' : '') + Number(g.profit_delta_pct).toFixed(0) + '%'}</div>
                        </div>`;
                    }).join('') || '<div class="dim">No geo data</div>'}
                </div>
            </div>
        </div>`;
    });
    html += '</div>';
    grid.innerHTML = html;
}

function setTasksStatus(status) {
    state.tabs.tasks.status = status || '';
    pushURL();
    loadTasksData();
}

async function loadTasksData() {
    const box = document.getElementById('tasksTbl');
    if (!box) return;
    box.innerHTML = SPIN;
    try {
        const ts = state.tabs.tasks;
        const params = new URLSearchParams({limit: 300});
        if (ts.status) params.set('status', ts.status);
        if (ts.search) params.set('q', ts.search);
        const res = await fetch('/api/tasks.php?' + params);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        taskRows = json.data?.tasks || [];
        taskMeta = json.data || {};
        renderTasksTable();
    } catch (e) {
        box.innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function taskStatusLabelLegacy(status) {
    return {
        pending: 'Pending',
        running: 'Running',
        done: 'Done',
        failed: 'Failed',
        cancelled: 'Cancelled'
    }[status] || status || '-';
}

function taskTypeLabelLegacy(type) {
    return {
        set_campaign_status: 'Campaign on/off',
        set_adset_status: 'Ad Set on/off',
        set_ad_status: 'Ad on/off',
        delete_campaign: 'Campaign delete',
        update_campaign_budget: 'Campaign budget',
        update_adset_budget: 'Ad set budget',
        update_adset_bid: 'Ad set bid',
        create_campaign: 'Campaign creation'
    }[type] || type || '-';
}

function taskTimeLegacy(v) {
    if (!v) return '-';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return v;
    return d.toLocaleString('ru', {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'});
}

function taskCompactJsonLegacy(value) {
    if (!value) return '-';
    const text = JSON.stringify(value);
    return text && text !== '{}' ? text : '-';
}

function taskTh(label, col, align='left') {
    const ts = state.tabs.tasks;
    const active = ts.sortCol === col;
    const dir = active ? ts.sortDir : '';
    return `<th><div class="thi ${align === 'left' ? 'left' : ''}" onclick="tasksSortBy('${col}')">
        ${align === 'left' ? label : ''}<span class="sort-ico ${dir}">${SORT_ICO}</span>${align !== 'left' ? label : ''}
    </div></th>`;
}

function tasksSortBy(col) {
    const ts = state.tabs.tasks;
    ts.sortDir = ts.sortCol === col ? (ts.sortDir === 'desc' ? 'asc' : 'desc') : 'desc';
    ts.sortCol = col;
    pushURL({replace:true});
    renderTasksTable();
}

function renderTasksTableLegacy() {
    const box = document.getElementById('tasksTbl');
    if (!box) return;
    const ts = state.tabs.tasks;
    const counts = taskMeta.counts || {};
    const total = Object.values(counts).reduce((s, v) => s + Number(v || 0), 0);
    const summary = document.getElementById('tasksSummary');
    if (summary) summary.textContent = `Total: ${total || taskRows.length}  |  shown: ${taskRows.length}`;

    const activeId = {
        '': 'taskStatusAll',
        pending: 'taskStatusPending',
        running: 'taskStatusRunning',
        failed: 'taskStatusFailed',
        done: 'taskStatusDone'
    };
    document.querySelectorAll('.tasks-filter').forEach(btn => btn.classList.toggle('active', btn.id === activeId[ts.status || '']));

    let rowsView = [...taskRows];
    const q = (ts.search || '').trim().toLowerCase();
    if (q) rowsView = rowsView.filter(row => [
        row.id, row.task_type, row.status, row.bm_id, row.bm_name, row.account_id,
        row.account_name, row.campaign_id, row.campaign_name, row.adset_id,
        row.adset_name, row.ad_id, row.ad_name, row.error, taskCompactJson(row.payload)
    ].some(v => String(v || '').toLowerCase().includes(q)));

    rowsView.sort((a, b) => {
        const col = ts.sortCol || 'created_at';
        const va = col === 'payload' ? taskCompactJson(a.payload) : (a[col] ?? '');
        const vb = col === 'payload' ? taskCompactJson(b.payload) : (b[col] ?? '');
        const cmpVal = (typeof va === 'number' || typeof vb === 'number')
            ? Number(va || 0) - Number(vb || 0)
            : String(va || '').localeCompare(String(vb || ''));
        return ts.sortDir === 'asc' ? cmpVal : -cmpVal;
    });

    if (!rowsView.length) {
        box.innerHTML = '<div class="tbl-empty">No tasks</div>';
        return;
    }

    let html = `<table><thead><tr>
        ${taskTh('ID','id')}
        ${taskTh('Status','status')}
        ${taskTh('Type','task_type')}
        ${taskTh('Target','bm_id')}
        ${taskTh('Payload','payload')}
        ${taskTh('Attempts','attempts','right')}
        ${taskTh('Created','created_at')}
        ${taskTh('Updated','updated_at')}
        <th><div class="thi left">Error</div></th>
    </tr></thead><tbody>`;

    rowsView.forEach(row => {
        const payload = taskCompactJson(row.payload);
        html += `<tr>
            <td><div class="tdi left"><span class="num">#${row.id}</span></div></td>
            <td><div class="tdi left"><span class="task-badge ${escAttr(row.status)}">${esc(taskStatusLabel(row.status))}</span></div></td>
            <td><div class="tdi left"><div><div class="task-type">${esc(taskTypeLabel(row.task_type))}</div><div class="task-sub">${esc(row.task_type)}</div></div></div></td>
            <td><div class="tdi left"><div>
                <div class="task-sub"><b>BM:</b> ${esc(row.bm_name || row.bm_id || '-')}</div>
                <div class="task-sub"><b>Account:</b> ${esc(row.account_name || row.account_id || '-')}</div>
                ${row.campaign_id ? `<div class="task-sub"><b>Campaigns:</b> ${esc(row.campaign_name || row.campaign_id)}</div>` : ''}
                ${row.adset_id ? `<div class="task-sub"><b>Ad set:</b> ${esc(row.adset_name || row.adset_id)}</div>` : ''}
            </div></div></td>
            <td><div class="tdi left"><div class="task-json" title="${escAttr(payload)}">${esc(payload)}</div></div></td>
            <td><div class="tdi"><span class="num">${fN(row.attempts || 0)} / ${fN(row.max_attempts || 0)}</span></div></td>
            <td><div class="tdi left"><span class="num">${esc(taskTime(row.created_at))}</span></div></td>
            <td><div class="tdi left"><span class="num">${esc(taskTime(row.updated_at))}</span></div></td>
            <td><div class="tdi left"><div class="task-error" title="${escAttr(row.error || '')}">${esc(row.error || '-')}</div></div></td>
        </tr>`;
    });

    html += '</tbody></table>';
    box.innerHTML = html;
}

// Encoding-safe task view overrides. Keep these strings ASCII in source so the
// legacy mixed-encoding file cannot corrupt the new menu/report labels.
function renderSearchBar() {
    const adsetLbl = document.getElementById('adsetLabel');
    const adLbl    = document.getElementById('adLabel');
    const srch = document.getElementById('srch');
    if (adsetLbl) adsetLbl.textContent = 'Ad Sets';
    if (adLbl)    adLbl.textContent    = 'Ads';
    if (srch) srch.placeholder = state.view === 'tasks'
        ? 'Search by task #, ID, payload, error...'
        : 'Search by name, ID...';
}

function taskStatusLabel(status) {
    return {
        pending: 'Pending',
        running: 'Running',
        done: 'Done',
        failed: 'Failed',
        cancelled: 'Cancelled'
    }[status] || status || '-';
}

function taskTypeLabel(type) {
    return {
        set_campaign_status: 'Campaign on/off',
        set_adset_status: 'Ad Set on/off',
        set_ad_status: 'Ad on/off',
        delete_campaign: 'Campaign delete',
        update_campaign_budget: 'Campaign budget',
        update_adset_budget: 'Ad Set budget',
        update_adset_bid: 'Ad Set bid',
        create_campaign: 'Campaign creation'
    }[type] || type || '-';
}

function taskTime(v) {
    if (!v) return '-';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return v;
    return d.toLocaleString('en-GB', {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'});
}

function taskCompactJson(value) {
    if (!value) return '-';
    const text = JSON.stringify(value);
    return text && text !== '{}' ? text : '-';
}

function taskPrettyJson(value) {
    if (!value) return '-';
    try {
        return JSON.stringify(value, null, 2) || '-';
    } catch (e) {
        return String(value || '-');
    }
}

function taskTargetHtml(row) {
    const campaignName = row.campaign_name || row.payload?.campaign_name || row.campaign_id || '-';
    const accountName = row.account_name || row.account_id || '-';
    const bmName = row.bm_name || row.bm_id || '-';
    const taskType = row.task_type || row.payload?.task_type || '';
    const verdict = row.payload?.verdict || row.verdict || '';
    const signalLevel = row.payload?.signal_level || '';
    const reasonShort = row.payload?.reason_short || row.reason_short || '';
    const reasonDetail = row.payload?.reason_detail || row.reason_detail || '';
    const whyNow = row.payload?.why_now || row.why_now || '';
    const parts = [
        `<div class="task-target-main">Campaign: ${esc(campaignName)}</div>`,
    ];
    if (taskType === 'delete_campaign') parts.push(`<div class="task-target-meta"><b>Action:</b> Delete campaign</div>`);
    if (taskType === 'set_adset_status') parts.push(`<div class="task-target-meta"><b>Action:</b> Change ad set status</div>`);
    if (taskType === 'set_ad_status') parts.push(`<div class="task-target-meta"><b>Action:</b> Change ad status</div>`);
    if (verdict) parts.push(`<div class="task-target-meta"><b>Verdict:</b> ${esc(verdict)}</div>`);
    if (signalLevel) parts.push(`<div class="task-target-meta"><b>Signal:</b> ${esc(signalLevel)}</div>`);
    if (reasonShort) parts.push(`<div class="task-target-meta"><b>Reason:</b> ${esc(reasonShort)}</div>`);
    if (reasonDetail) parts.push(`<div class="task-target-meta"><b>Detail:</b> ${esc(reasonDetail)}</div>`);
    if (whyNow) parts.push(`<div class="task-target-meta"><b>Why now:</b> ${esc(whyNow)}</div>`);
    if (taskType === 'update_adset_bid') {
        const currBid = row.payload?.current_bid ?? row.payload?.currentBid ?? null;
        const newBid = row.payload?.bid_amount ?? row.payload?.bidAmount ?? null;
        const deltaPct = row.payload?.bid_delta_pct ?? row.payload?.bidDeltaPct ?? null;
        if (deltaPct !== null && deltaPct !== undefined && deltaPct !== '') {
            const sign = Number(deltaPct) > 0 ? '+' : '';
            parts.push(`<div class="task-target-meta"><b>Bid:</b> ${esc(sign + Math.round(Number(deltaPct) * 100) + '%')} from current bid</div>`);
        } else {
            parts.push(`<div class="task-target-meta"><b>Bid:</b> ${esc(currBid !== null && currBid !== undefined ? f$(Number(currBid)) : '-')} -> ${esc(newBid !== null && newBid !== undefined ? f$(Number(newBid)) : '-')}</div>`);
        }
    }
    if (row.adset_id) parts.push(`<div class="task-target-meta"><b>Ad Set:</b> ${esc(row.adset_name || row.adset_id)}</div>`);
    if (row.ad_id) parts.push(`<div class="task-target-meta"><b>Ad:</b> ${esc(row.ad_name || row.payload?.ad_name || row.ad_id)}</div>`);
    parts.push(`<div class="task-target-meta"><b>Account:</b> ${esc(accountName)}</div>`);
    parts.push(`<div class="task-target-meta"><b>Campaign ID:</b> <span class="num">${esc(row.campaign_id || '-')}</span></div>`);
    if (row.adset_id) parts.push(`<div class="task-target-meta"><b>Ad Set ID:</b> <span class="num">${esc(row.adset_id)}</span></div>`);
    if (row.ad_id) parts.push(`<div class="task-target-meta"><b>Ad ID:</b> <span class="num">${esc(row.ad_id)}</span></div>`);
    parts.push(`<div class="task-target-meta"><b>Account ID:</b> <span class="num">${esc(row.account_id || '-')}</span></div>`);
    parts.push(`<div class="task-target-meta"><b>BM:</b> ${esc(bmName)}</div>`);
    return parts.join('');
}

function taskTargetText(row) {
    const campaignName = row.campaign_name || row.payload?.campaign_name || row.campaign_id || '-';
    const accountName = row.account_name || row.account_id || '-';
    const bmName = row.bm_name || row.bm_id || '-';
    const taskType = row.task_type || row.payload?.task_type || '';
    const verdict = row.payload?.verdict || row.verdict || '';
    const signalLevel = row.payload?.signal_level || '';
    const reasonShort = row.payload?.reason_short || row.reason_short || '';
    const reasonDetail = row.payload?.reason_detail || row.reason_detail || '';
    const whyNow = row.payload?.why_now || row.why_now || '';
    const lines = [`Campaign: ${campaignName}`];
    if (taskType === 'delete_campaign') lines.push('Action: Delete campaign');
    if (taskType === 'set_adset_status') lines.push('Action: Change ad set status');
    if (taskType === 'set_ad_status') lines.push('Action: Change ad status');
    if (verdict) lines.push(`Verdict: ${verdict}`);
    if (signalLevel) lines.push(`Signal: ${signalLevel}`);
    if (reasonShort) lines.push(`Reason: ${reasonShort}`);
    if (reasonDetail) lines.push(`Detail: ${reasonDetail}`);
    if (whyNow) lines.push(`Why now: ${whyNow}`);
    if (taskType === 'update_adset_bid') {
        const currBid = row.payload?.current_bid ?? row.payload?.currentBid ?? null;
        const newBid = row.payload?.bid_amount ?? row.payload?.bidAmount ?? null;
        const deltaPct = row.payload?.bid_delta_pct ?? row.payload?.bidDeltaPct ?? null;
        if (deltaPct !== null && deltaPct !== undefined && deltaPct !== '') {
            const sign = Number(deltaPct) > 0 ? '+' : '';
            lines.push(`Bid: ${sign + Math.round(Number(deltaPct) * 100)}% from current bid`);
        } else {
            lines.push(`Bid: ${currBid !== null && currBid !== undefined ? currBid : '-'} -> ${newBid !== null && newBid !== undefined ? newBid : '-'}`);
        }
    }
    if (row.adset_id) lines.push(`Ad Set: ${row.adset_name || row.adset_id}`);
    if (row.ad_id) lines.push(`Ad: ${row.ad_name || row.payload?.ad_name || row.ad_id}`);
    lines.push(`Account: ${accountName}`);
    lines.push(`Campaign ID: ${row.campaign_id || '-'}`);
    if (row.adset_id) lines.push(`Ad Set ID: ${row.adset_id}`);
    if (row.ad_id) lines.push(`Ad ID: ${row.ad_id}`);
    lines.push(`Account ID: ${row.account_id || '-'}`);
    lines.push(`BM: ${bmName}`);
    return lines.join('\n');
}

function toggleTaskPayload(el) {
    if (!el) return;
    const expanded = el.classList.toggle('expanded');
    el.textContent = expanded ? (el.dataset.full || '-') : (el.dataset.compact || '-');
}

function taskAgeMs(row) {
    const raw = row?.created_at || row?.updated_at || row?.started_at || '';
    const ts = Date.parse(raw);
    return Number.isFinite(ts) ? Math.max(0, Date.now() - ts) : 0;
}

function taskRetryable(row) {
    return row?.status === 'failed' || (row?.status === 'pending' && taskAgeMs(row) >= 90000);
}

async function retryTaskRequest(taskId) {
    const res = await fetch('/api/tasks.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'retry_task', task_id: taskId})
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    return json.data?.task || null;
}

async function retryPendingEntityTask(entityId, entityType = 'campaign', btn = null) {
    const pendingMap = pendingTaskMap(entityType);
    const key = String(entityId);
    const pending = pendingMap.get(key);
    if (!pending?.taskId) return;
    if (btn) btn.disabled = true;
    try {
        const task = await retryTaskRequest(pending.taskId);
        if (!task) throw new Error('Retry task was not created');
        pendingMap.set(key, {
            ...pending,
            taskId: task.id,
            status: task.status || 'pending',
            error: '',
            startedAt: Date.now()
        });
        renderTable();
        pollEntityTask(task.id, key, pending.desiredStatus, pending.taskType, entityType);
    } catch (e) {
        if (btn) btn.disabled = false;
        alert('Retry error: ' + e.message);
    }
}

function taskActionHtml(row) {
    const taskId = Number(row.id) || 0;
    const actions = [];
    if (taskRetryable(row)) {
        actions.push(`<button class="task-action-btn" onclick="retryDashboardTask(${taskId}, this)">Retry</button>`);
    }
    const disabled = row.status === 'running' ? 'disabled title="Running tasks cannot be deleted"' : 'title="Delete task"';
    actions.push(`<button class="task-action-btn danger" ${disabled} onclick="deleteTask(${taskId}, this)">Delete</button>`);
    return `<div class="task-action-group">${actions.join('')}</div>`;
}

async function retryDashboardTask(taskId, btn) {
    if (!taskId) return;
    if (btn) btn.disabled = true;
    try {
        await retryTaskRequest(taskId);
        await loadTasksData();
    } catch (e) {
        if (btn) btn.disabled = false;
        alert('Error: ' + e.message);
    }
}

async function deleteTask(taskId, btn) {
    if (!taskId) return;
    if (!confirm(`Delete task #${taskId}?`)) return;
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('/api/tasks.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete_task', task_id: taskId})
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        await loadTasksData();
    } catch (e) {
        if (btn) btn.disabled = false;
        alert('Error: ' + e.message);
    }
}

function renderTasksTable() {
    const box = document.getElementById('tasksTbl');
    if (!box) return;
    const ts = state.tabs.tasks;
    const counts = taskMeta.counts || {};
    const total = Object.values(counts).reduce((s, v) => s + Number(v || 0), 0);
    const summary = document.getElementById('tasksSummary');
    if (summary) summary.textContent = `Total: ${total || taskRows.length} / shown: ${taskRows.length}`;

    const activeId = {
        '': 'taskStatusAll',
        pending: 'taskStatusPending',
        running: 'taskStatusRunning',
        failed: 'taskStatusFailed',
        done: 'taskStatusDone'
    };
    document.querySelectorAll('.tasks-filter').forEach(btn => btn.classList.toggle('active', btn.id === activeId[ts.status || '']));

    let rowsView = [...taskRows];
    const q = (ts.search || '').trim().toLowerCase();
    if (q) rowsView = rowsView.filter(row => [
        row.id, row.task_type, row.status, row.bm_id, row.bm_name, row.account_id,
        row.account_name, row.campaign_id, row.campaign_name, row.adset_id,
        row.adset_name, row.ad_id, row.ad_name, row.locked_by, row.error, taskCompactJson(row.payload)
    ].some(v => String(v || '').toLowerCase().includes(q)));

    rowsView.sort((a, b) => {
        const col = ts.sortCol || 'created_at';
        const va = col === 'payload' ? taskCompactJson(a.payload) : (a[col] ?? '');
        const vb = col === 'payload' ? taskCompactJson(b.payload) : (b[col] ?? '');
        const cmpVal = (typeof va === 'number' || typeof vb === 'number')
            ? Number(va || 0) - Number(vb || 0)
            : String(va || '').localeCompare(String(vb || ''));
        return ts.sortDir === 'asc' ? cmpVal : -cmpVal;
    });

    if (!rowsView.length) {
        box.innerHTML = '<div class="tbl-empty">No tasks</div>';
        return;
    }

    let html = `<table><thead><tr>
        ${taskTh('ID','id')}
        ${taskTh('Status','status')}
        ${taskTh('Type','task_type')}
        ${taskTh('Target','bm_id')}
        ${taskTh('Payload','payload')}
        ${taskTh('Attempts','attempts','right')}
        ${taskTh('Created','created_at')}
        ${taskTh('Updated','updated_at')}
        ${taskTh('Locked by','locked_by')}
        ${taskTh('Locked at','locked_at')}
        <th><div class="thi left">Error</div></th>
        <th><div class="thi left">Action</div></th>
    </tr></thead><tbody>`;

    rowsView.forEach(row => {
        const payload = taskCompactJson(row.payload);
        const payloadFull = taskPrettyJson(row.payload);
        html += `<tr>
            <td><div class="tdi left"><span class="num">#${row.id}</span></div></td>
            <td><div class="tdi left"><span class="task-badge ${escAttr(row.status)}">${esc(taskStatusLabel(row.status))}</span></div></td>
            <td><div class="tdi left"><div><div class="task-type">${esc(taskTypeLabel(row.task_type))}</div><div class="task-sub">${esc(row.task_type)}</div></div></div></td>
            <td><div class="tdi left"><div>${taskTargetHtml(row)}</div></div></td>
            <td><div class="tdi left"><div class="task-json clickable" title="${escAttr(payloadFull)}" data-compact="${escAttr(payload)}" data-full="${escAttr(payloadFull)}" onclick="toggleTaskPayload(this)">${esc(payload)}</div></div></td>
            <td><div class="tdi"><span class="num">${fN(row.attempts || 0)} / ${fN(row.max_attempts || 0)}</span></div></td>
            <td><div class="tdi left"><span class="num">${esc(taskTime(row.created_at))}</span></div></td>
            <td><div class="tdi left"><span class="num">${esc(taskTime(row.updated_at))}</span></div></td>
            <td><div class="tdi left"><span class="num">${esc(row.locked_by || '-')}</span></div></td>
            <td><div class="tdi left"><span class="num">${esc(taskTime(row.locked_at))}</span></div></td>
            <td><div class="tdi left"><div class="task-error" title="${escAttr(row.error || '')}">${esc(row.error || '-')}</div></div></td>
            <td><div class="tdi left">${taskActionHtml(row)}</div></td>
        </tr>`;
    });

    html += '</tbody></table>';
    box.innerHTML = html;
}

// -- SYNC LABEL -----------------------------------------------
let _lastSyncAccs = [], _lastSyncJson = {};

function updateSyncLabel(accs, json) {
    _lastSyncAccs = accs || [];
    _lastSyncJson = json || {};
    _renderSyncLabel();
}

function _renderSyncLabel() {
    const el = document.getElementById('syncLabel');
    if (!el) return;
    const accs = _lastSyncAccs, json = _lastSyncJson;
    const parts = [];
    const fbRaw = json?.fb_synced_at || accs.reduce((m,a) => a.synced_at > m ? a.synced_at : m, '');
    if (fbRaw) {
        const dt = new Date(fbRaw), diff = Math.round((Date.now()-dt.getTime())/60000);
        parts.push(`FB: ${dt.toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'})} (${diff<60?diff+'m':Math.round(diff/60)+'h'} ago)`);
    }
    if (json?.kt_synced_at) {
        const dt = new Date(json.kt_synced_at), diff = Math.round((Date.now()-dt.getTime())/60000);
        parts.push(`KT: ${dt.toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'})} (${diff<60?diff+'m':Math.round(diff/60)+'h'} ago)`);
    }
    el.textContent = parts.join('  |  ');
}

// Refresh the "Xm ago" label every minute
setInterval(_renderSyncLabel, 60000);

async function loadAccounts() {
    try {
        const params = new URLSearchParams({range: state.range});
        if (currentReportFilters().has('geo') && state.filters.geo) params.set('geo', state.filters.geo);
        const res  = await fetch('/api/accounts.php?'+params);
        const text = await res.text();
        let json = null;
        try {
            json = text ? JSON.parse(text) : null;
        } catch (parseErr) {
            throw new Error((text || '').trim().slice(0, 220) || `HTTP ${res.status} | Empty API response`);
        }
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}${text ? ' | ' + text.trim().slice(0, 220) : ''}`);
        }
        if (!json || typeof json !== 'object') {
            throw new Error(`HTTP ${res.status} | Empty API response body`);
        }
        accounts   = json.data || [];
        updateSyncLabel(accounts, json);
    } catch(e) { console.error(e); }
}

// -- LOAD TABLE DATA ------------------------------------------
const SPIN = '<div class="tbl-loading"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin .8s linear infinite"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Loading...</div>';

async function loadData() {
    document.getElementById('tblwrap').innerHTML = SPIN;
    const v = state.view;

    if (v === 'bm' || v === 'account') {
        try {
            const params = new URLSearchParams({range: state.range});
            // On the BM tab, do not filter the list; bm_id is only for row highlighting
            // On the Accounts tab, filter by bm_id
            if (v === 'account' && state.filters.bm_id) params.set('bm_id', state.filters.bm_id);
            if (currentReportFilters().has('geo') && state.filters.geo) params.set('geo', state.filters.geo);
            const [res, totalsRes] = await Promise.all([
                fetch('/api/accounts.php?'+params),
                fetch('/api/totals.php?'+(() => { const p = new URLSearchParams({range:state.range}); if (currentReportFilters().has('bm_id') && state.filters.bm_id) p.set('bm_id', state.filters.bm_id); return p; })()),
            ]);
            const [json, totalsJson] = await Promise.all([
                readApiJson(res, 'accounts'),
                readApiJson(totalsRes, 'totals'),
            ]);
            if (!json.ok) throw new Error(json.error||'API error');
            let accts = json.data || [];
            if (currentReportFilters().has('account_id') && exactAccountFilterValue()) accts = accts.filter(a => a.id===exactAccountFilterValue());

            // Compute orphan rows
            const globalTotals = totalsJson.data || {};
            const acctTotals = accts.reduce((acc,a) => {
                const p = a.period||{};
                for (const k of ['spend','impressions','clicks','leads','regs','deps','revenue'])
                    acc[k] = (acc[k]||0) + (p[k]||0);
                return acc;
            }, {});
            const orphan = {};
            for (const k of ['spend','impressions','clicks','leads','regs','deps','revenue'])
                orphan[k] = Math.max(0, Math.round(((globalTotals[k]||0) - (acctTotals[k]||0)) * 10000) / 10000);
            const allowedAcctFilters = currentReportFilters();
            const hasOrphan = !(allowedAcctFilters.has('account_id') && state.filters.account_id) && !(allowedAcctFilters.has('geo') && state.filters.geo) && (orphan.revenue > 0 || orphan.spend > 0 || orphan.leads > 0);
            if (hasOrphan) {
                accts = [...accts, {
                    id: '__orphan__', name: '(no structure)', status: -1,
                    bm_id: null, bm_name: '-', period: orphan, _isOrphan: true,
                }];
            }

            window._lastAccts = accts;
            updateSyncLabel(json.data||[], json);
            renderAccountsTable(accts);
        } catch(e) {
            document.getElementById('tblwrap').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
        }
        return;
    }

    if (v === 'rules_check') {
        try {
            const campaignParams = buildAPIParams({level: 'campaign'});
            const [res, totalsRes, rulesRes] = await Promise.all([
                fetch('/api/campaigns.php?' + campaignParams),
                fetch('/api/totals.php?' + (() => { const p = new URLSearchParams({range:state.range}); if (currentReportFilters().has('bm_id') && state.filters.bm_id) p.set('bm_id', state.filters.bm_id); return p; })()),
                fetch('/api/rules_check.php?' + buildRulesCheckParams()),
            ]);
            const [json, totalsJson, rulesJson] = await Promise.all([
                readApiJson(res, 'campaigns'),
                readApiJson(totalsRes, 'totals'),
                readApiJson(rulesRes, 'rules_check'),
            ]);
            if (!json.ok) throw new Error(json.error || 'Campaigns API error');
            if (!rulesJson.ok) throw new Error(rulesJson.error || 'Rules Check API error');
            rows = attachRulesCheckVerdicts(applyGeoFilter(json.data || []), rulesJson);

            const T = json.totals || {};
            const totalSpendEl = document.getElementById('totalSpend');
            if (totalSpendEl) totalSpendEl.textContent =
                T.spend ? '$'+parseFloat(T.spend).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) : '';
            const totalRowsEl = document.getElementById('totalRows');
            if (totalRowsEl) totalRowsEl.textContent = rows.length ? rows.length + ' campaigns' : '';

            renderTable();
            updateBulk();
        } catch(e) {
            document.getElementById('tblwrap').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
        }
        return;
    }

    try {
        const params = buildAPIParams({level: v});
        if (v === 'campaign') params.set('cost_baseline', '1');
        const [res, totalsRes] = await Promise.all([
            fetch('/api/campaigns.php?'+params),
            fetch('/api/totals.php?'+(() => { const p = new URLSearchParams({range:state.range}); if (currentReportFilters().has('bm_id') && state.filters.bm_id) p.set('bm_id', state.filters.bm_id); return p; })()),
        ]);
        const [json, totalsJson] = await Promise.all([
            readApiJson(res, 'campaigns'),
            readApiJson(totalsRes, 'totals'),
        ]);
        if (!json.ok) throw new Error(json.error||'API error');
        rows = applyGeoFilter(json.data || []);
        if (v === 'campaign') rows.forEach(row => { row.cost_baseline = row.stats?.cost_baseline || null; });

        // Add orphan row only when there are no deep filters (do not add it on adset/ad level)
        const globalTotals = totalsJson.data || {};
        const rowTotals = rows.reduce((acc,r) => {
            const s=r.stats||{};
            for (const k of ['spend','delta','impressions','clicks','leads','regs','deps','revenue'])
                acc[k] = (acc[k]||0) + (s[k]||0);
            return acc;
        }, {});
        const orphan = {};
        for (const k of ['spend','delta','impressions','clicks','leads','regs','deps','revenue'])
            orphan[k] = Math.max(0, Math.round(((globalTotals[k]||0) - (rowTotals[k]||0)) * 10000) / 10000);
        const allowedFilters = currentReportFilters();
        const noDeepFilter = !['account_id','campaign_id','adset_id','geo','ad_name','launch_date'].some(k => allowedFilters.has(k) && state.filters[k]);
        const hasOrphan = noDeepFilter && (orphan.revenue > 0 || orphan.spend > 0 || orphan.leads > 0);
        if (hasOrphan) {
            rows.push({
                id: '__orphan__', name: '(no structure)',
                status: null, effective_status: null,
                campaign_name: '', adset_name: '',
                stats: orphan, _isOrphan: true,
            });
        }

        const T = json.totals || {};
        const totalSpendEl = document.getElementById('totalSpend');
        if (totalSpendEl) totalSpendEl.textContent =
            T.spend ? '$'+parseFloat(T.spend).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) : '';
        const totalRowsEl = document.getElementById('totalRows');
        if (totalRowsEl) totalRowsEl.textContent =
            rows.length ? rows.length+' '+(v==='campaign'?'campaigns':v==='adset'?'ad sets':'ads') : '';

        renderTable();
        updateBulk();
    } catch(e) {
        document.getElementById('tblwrap').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

// -- TH HELPER ------------------------------------------------
function th(label, col, align='right') {
    const ts = curTab();
    const active = ts.sortCol === col;
    const dir = active ? ts.sortDir : '';
    return `<th class="resizable-th" data-col-key="${escAttr(col)}"><div class="thi ${align==='left'?'left':''}" onclick="sortBy('${col}')">
        ${align==='left' ? label : ''}<span class="sort-ico ${dir}">${SORT_ICO}</span>${align!=='left' ? label : ''}
    </div></th>`;
}

const TABLE_COL_WIDTHS_KEY = 'fb_ads_dashboard_table_col_widths_v1';
const TABLE_COL_BALANCE_KEY = 'fb_ads_dashboard_table_col_balance_v1';
let _tableColWidthsState = null;
let _tableColResizeLock = false;
let _tableColBalanceState = null;

function loadTableColWidthsState() {
    if (_tableColWidthsState) return _tableColWidthsState;
    try {
        _tableColWidthsState = JSON.parse(localStorage.getItem(TABLE_COL_WIDTHS_KEY) || '{}') || {};
    } catch (e) {
        _tableColWidthsState = {};
    }
    return _tableColWidthsState;
}

function saveTableColWidthsState() {
    try {
        localStorage.setItem(TABLE_COL_WIDTHS_KEY, JSON.stringify(loadTableColWidthsState()));
    } catch (e) {
        // Ignore storage failures in private mode or restricted browsers.
    }
}

function loadTableColBalanceState() {
    if (_tableColBalanceState !== null) return _tableColBalanceState;
    try {
        const raw = localStorage.getItem(TABLE_COL_BALANCE_KEY);
        _tableColBalanceState = raw === null ? true : raw === '1';
    } catch (e) {
        _tableColBalanceState = true;
    }
    return _tableColBalanceState;
}

function saveTableColBalanceState() {
    try {
        localStorage.setItem(TABLE_COL_BALANCE_KEY, loadTableColBalanceState() ? '1' : '0');
    } catch (e) {
        // Ignore storage failures in private mode or restricted browsers.
    }
}

function setTableColumnBalance(enabled) {
    _tableColBalanceState = !!enabled;
    saveTableColBalanceState();
    syncWidthResetButton();
    renderCurrentTable();
}

function resetTableColumnWidths() {
    try {
        localStorage.removeItem(TABLE_COL_WIDTHS_KEY);
    } catch (e) {
        // Ignore storage failures in private mode or restricted browsers.
    }
    _tableColWidthsState = {};
    const tables = document.querySelectorAll('table[data-resize-key]');
    tables.forEach(table => {
        delete table.dataset.resizeInit;
        const colgroup = table.querySelector('colgroup');
        if (colgroup) colgroup.remove();
    });
    renderCurrentTable();
}

function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
}

function tableColumnKey(th, index) {
    const raw = String(th?.dataset?.colKey || th?.getAttribute('data-col-key') || th?.textContent || '').trim();
    const base = raw
        .replace(/\s+/g, ' ')
        .replace(/[^a-zA-Z0-9._-]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .toLowerCase();
    return base || `col_${index}`;
}

function getTableKey(table) {
    return String(table?.dataset?.resizeKey || table?.id || '').trim();
}

function readTableWidths(key) {
    const state = loadTableColWidthsState();
    return state[key] || null;
}

function writeTableWidths(key, order, widths) {
    const state = loadTableColWidthsState();
    state[key] = { order: [...order], widths: {...widths} };
    saveTableColWidthsState();
}

function ensureTableColgroup(table, key) {
    const headRow = table?.tHead?.rows?.[0];
    if (!headRow) return null;

    const headerCells = [...headRow.cells];
    if (!headerCells.length) return null;

    const colKeys = headerCells.map((th, index) => tableColumnKey(th, index));
    const stored = readTableWidths(key);
    const signatureMatches = stored
        && Array.isArray(stored.order)
        && stored.order.length === colKeys.length
        && stored.order.every((value, index) => value === colKeys[index]);

    const currentWidths = {};
    if (!signatureMatches) {
        headerCells.forEach((th, index) => {
            currentWidths[colKeys[index]] = Math.max(60, Math.round(th.getBoundingClientRect().width || th.offsetWidth || 0) || 60);
        });
    }

    const widths = signatureMatches ? (stored.widths || {}) : currentWidths;
    let colgroup = table.querySelector('colgroup');
    if (!colgroup) {
        colgroup = document.createElement('colgroup');
        table.insertBefore(colgroup, table.firstChild);
    }

    let widthsChanged = false;
    colgroup.innerHTML = '';
    headerCells.forEach((th, index) => {
        const col = document.createElement('col');
        const fixedWidth = Number(th?.dataset?.fixedWidth || 0);
        const width = fixedWidth > 0 ? fixedWidth : (Number(widths[colKeys[index]]) || 0);
        if (width > 0) col.style.width = `${width}px`;
        if (fixedWidth > 0 && Number(widths[colKeys[index]]) !== fixedWidth) {
            widths[colKeys[index]] = fixedWidth;
            widthsChanged = true;
        }
        colgroup.appendChild(col);
        th.classList.add('resizable-th');
        th.dataset.colKey = colKeys[index];
        th.dataset.colIndex = String(index);
    });

    if (!signatureMatches || widthsChanged) {
        writeTableWidths(key, colKeys, widths);
    }

    return { colgroup, colKeys };
}

function syncTableColWidths(table, key, colKeys, colgroup) {
    const widths = {};
    [...colgroup.children].forEach((col, index) => {
        const keyName = colKeys[index];
        const width = Math.max(60, Math.round(parseFloat(col.style.width || col.getBoundingClientRect().width || col.offsetWidth || 0)) || 60);
        widths[keyName] = width;
    });
    writeTableWidths(key, colKeys, widths);
}

function applyBalancedRightResize(startWidths, colIndex, delta, minWidth = 60) {
    const widths = [...startWidths];
    const currentIndex = colIndex;
    const rightIndexes = [];
    for (let i = colIndex + 1; i < widths.length; i++) rightIndexes.push(i);
    if (!rightIndexes.length) {
        widths[currentIndex] = Math.max(minWidth, widths[currentIndex] + delta);
        return widths;
    }

    const currentShrinkLimit = minWidth - widths[currentIndex];
    if (delta < currentShrinkLimit) delta = currentShrinkLimit;

    const currentAvailableIncrease = Math.max(0, rightIndexes.reduce((sum, idx) => sum + Math.max(0, widths[idx] - minWidth), 0));
    if (delta > currentAvailableIncrease) delta = currentAvailableIncrease;

    widths[currentIndex] = clamp(widths[currentIndex] + delta, minWidth, Number.MAX_SAFE_INTEGER);

    let remaining = delta;
    if (remaining > 0) {
        let active = rightIndexes.slice();
        while (remaining > 0.01 && active.length) {
            const share = remaining / active.length;
            const nextActive = [];
            let consumed = 0;
            for (const idx of active) {
                const available = Math.max(0, widths[idx] - minWidth);
                const cut = Math.min(share, available);
                widths[idx] -= cut;
                consumed += cut;
                if (widths[idx] > minWidth + 0.01) nextActive.push(idx);
            }
            remaining = Math.max(0, remaining - consumed);
            active = nextActive;
        }
    } else if (remaining < 0) {
        const grow = -remaining;
        const share = grow / rightIndexes.length;
        for (const idx of rightIndexes) widths[idx] += share;
    }
    return widths;
}

function applySimpleRightResize(startWidths, colIndex, delta, minWidth = 60) {
    const widths = [...startWidths];
    const currentIndex = colIndex;
    const nextIndex = colIndex + 1;
    widths[currentIndex] = Math.max(minWidth, widths[currentIndex] + delta);
    if (nextIndex < widths.length) {
        widths[nextIndex] = Math.max(minWidth, widths[nextIndex] - delta);
    }
    return widths;
}

function startTableColumnResize(event) {
    if (event.button !== 0) return;
    const handle = event.currentTarget;
    const th = handle.closest('th');
    const table = th?.closest('table');
    const key = getTableKey(table);
    const colIndex = Number(th?.dataset?.colIndex || -1);
    const colgroup = table?.querySelector('colgroup');
    if (!table || !key || !colgroup || colIndex < 0) return;

    const cols = [...colgroup.children];
    const currentCol = cols[colIndex];
    const nextCol = cols[colIndex + 1] || null;
    if (!currentCol) return;

    const minWidth = 60;
    const startX = event.clientX;
    const startWidths = cols.map(col => Math.max(minWidth, Math.round(parseFloat(col.style.width || col.getBoundingClientRect().width || col.offsetWidth || 0)) || minWidth));
    const totalWidth = startWidths.reduce((sum, val) => sum + val, 0);
    const balanceEnabled = loadTableColBalanceState();

    event.preventDefault();
    event.stopPropagation();
    _tableColResizeLock = true;
    table.classList.add('col-resizing');
    th.classList.add('is-resizing');

    const move = ev => {
        const delta = ev.clientX - startX;
        let widths;
        if (balanceEnabled) {
            widths = applyBalancedRightResize(startWidths, colIndex, delta, minWidth);
        } else {
            widths = applySimpleRightResize(startWidths, colIndex, delta, minWidth);
        }
        const widthSum = widths.reduce((sum, val) => sum + val, 0);
        if (widthSum > 0 && totalWidth > 0 && Math.abs(widthSum - totalWidth) > 0.5 && balanceEnabled) {
            const diff = totalWidth - widthSum;
            widths[widths.length - 1] = Math.max(minWidth, widths[widths.length - 1] + diff);
        }
        cols.forEach((col, idx) => { col.style.width = `${Math.round(widths[idx])}px`; });
    };

    const finish = () => {
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', finish);
        table.classList.remove('col-resizing');
        th.classList.remove('is-resizing');
        const allHeadCells = [...table.querySelectorAll('thead th')];
        const keys = allHeadCells.map((cell, index) => tableColumnKey(cell, index));
        syncTableColWidths(table, key, keys, colgroup);
        window.setTimeout(() => { _tableColResizeLock = false; }, 150);
    };

    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', finish);
}

function initColumnResizing(root) {
    if (!root) return;
    const tables = root.matches?.('table[data-resize-key]') ? [root] : [...root.querySelectorAll('table[data-resize-key]')];
    for (const table of tables) {
        if (!table || table.dataset.resizeInit === '1') continue;
        const key = getTableKey(table);
        if (!key) continue;
        const setup = ensureTableColgroup(table, key);
        if (!setup) continue;
        table.dataset.resizeInit = '1';
        table.querySelectorAll('thead th').forEach(th => {
            if (Number(th?.dataset?.fixedWidth || 0) > 0) return;
            if (th.querySelector('.th-resize-handle')) return;
            const handle = document.createElement('span');
            handle.className = 'th-resize-handle';
            handle.setAttribute('aria-hidden', 'true');
            handle.title = 'Drag to resize';
            handle.addEventListener('mousedown', startTableColumnResize);
            handle.addEventListener('pointerdown', ev => {
                if (ev.pointerType && ev.pointerType !== 'mouse') startTableColumnResize(ev);
            });
            th.appendChild(handle);
        });
    }
}

function sortBy(col) {
    if (_tableColResizeLock) return;
    const ts = curTab();
    ts.sortDir = ts.sortCol===col ? (ts.sortDir==='desc'?'asc':'desc') : 'desc';
    ts.sortCol = col;
    pushURL({replace:true});
    if (state.view==='bm'||state.view==='account') {
        if (window._lastAccts) renderAccountsTable(window._lastAccts);
    } else if (state.view==='tasks') {
        renderTasksTable();
    } else renderTable();
}

// -- ACCOUNTS TABLE -------------------------------------------
function statCols(p) {
    const spend=p.spend||0, delta=p.delta||0, impr=p.impressions||0, clicks=p.clicks||0;
    const leads=p.leads||0, regs=p.regs||0, deps=p.deps||0, rev=p.revenue||0;
    const profit=rev-spend, roi=spend>0?profit/spend*100:0;
    return ''
        +`<td><div class="tdi"><span class="num">${fN(impr)}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${fN(clicks)}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${clicks>0?f$(spend/clicks):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${leads>0?fN(leads):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${leads>0?f$(spend/leads):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${regs>0?fN(regs):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${regs>0?f$(spend/regs):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${deps>0?fN(deps):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${deps>0?f$(spend/deps):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${fR2D(regs,deps)}</span></div></td>`
        +`<td><div class="tdi"><span class="num spend-val">${f$(spend)}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${f$(delta)}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${rev>0?f$(rev):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num" style="color:${profit>0?'var(--green)':profit<0?'var(--red)':''}">${rev>0||spend>0?f$(profit):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num" style="color:${profit>0?'var(--green)':profit<0?'var(--red)':''}">${spend>0?roi.toFixed(1)+'%':'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${impr>0?fP(clicks/impr*100):'-'}</span></div></td>`
        +`<td><div class="tdi"><span class="num">${impr>0?f$(spend/impr*1000):'-'}</span></div></td>`;
}

function groupByBm(accts) {
    const map = new Map();
    accts.forEach(a => {
        const key = a.bm_id || '0';
        if (!map.has(key)) map.set(key, {bm_id:a.bm_id, bm_name:a.bm_name, cnt:0, campaigns_active_count:0, campaigns_count:0, active:0, banned:0, spend:0, delta:0, impressions:0, clicks:0, leads:0, regs:0, deps:0, revenue:0});
        const bm = map.get(key);
        bm.cnt++;
        bm.campaigns_active_count += Number(a.campaigns_active_count || 0);
        bm.campaigns_count += Number(a.campaigns_count || 0);
        if (a.status===1) bm.active++; else bm.banned++;
        const p = a.period || {};
        ['spend','delta','impressions','clicks','leads','regs','deps','revenue'].forEach(k => bm[k]+=(p[k]||0));
    });
    return Array.from(map.values());
}

function renderAccountsTable(accts) {
    const ts = curTab();
    const isBm = state.view === 'bm';
    let data = isBm ? groupByBm(accts) : [...accts];

    // Search filter
    const q = (ts.search||'').trim().toLowerCase();
    if (q) data = data.filter(x => {
        const name = String(isBm ? x.bm_name : x.name || '').toLowerCase();
        const id = String(isBm ? x.bm_id : x.id || '').toLowerCase();
        return name.includes(q) || id.includes(q);
    });

    // Delivery filter for accounts
    if (!isBm && ts.delivery) {
        data = ts.delivery === 'ACTIVE'
            ? data.filter(a => a.status === 1)
            : data.filter(a => a.status !== 1);
    }

    data.sort((a,b) => {
        const statFields = new Set(['spend','delta','impressions','clicks','leads','regs','deps','revenue','profit','roi','ctr','cpm','cpl','cpr','cpd','r2d','c2l']);
        const countFields = new Set(['campaigns_active_count','campaigns_count']);
        const va = countFields.has(ts.sortCol) ? Number(a[ts.sortCol] || 0) : (statFields.has(ts.sortCol) ? metricValue((isBm?a:a.period||{}), ts.sortCol) : (a[ts.sortCol]??0));
        const vb = countFields.has(ts.sortCol) ? Number(b[ts.sortCol] || 0) : (statFields.has(ts.sortCol) ? metricValue((isBm?b:b.period||{}), ts.sortCol) : (b[ts.sortCol]??0));
        const primary = typeof va==='string' ? (ts.sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va)) : (ts.sortDir==='asc'?va-vb:vb-va);
        return compareWithActive(a, b, primary);
    });

    const thead = `<thead><tr>
        <th data-fixed-width="36" style="width:36px;min-width:36px;max-width:36px"><div class="thi center"><input type="checkbox" id="cbAll" onchange="toggleAll(this)"></div></th>
        <th data-fixed-width="44" style="width:44px;min-width:44px;max-width:44px"><div class="thi center"></div></th>
        ${th('Name','name','left')}
        <th><div class="thi left">${isBm ? 'Accounts' : 'Status'}</div></th>
        ${isBm ? th('Campaigns','campaigns_active_count') : `${th('Active campaigns','campaigns_active_count')}${th('Total campaigns','campaigns_count')}`}
        ${th('Impressions','impressions')}${th('Clicks','clicks')}${th('CPC','cpc')}
        ${th('Leads','leads')}${th('CPL','cpl')}
        ${th('Regs','regs')}${th('CPR','cpr')}
        ${th('Deps','deps')}${th('CPD','cpd')}${th('R2D','r2d')}
        ${th('Spend','spend')}${th('Delta','delta')}${th('Revenue','revenue')}
        ${th('Profit','profit')}${th('ROI','roi')}
        ${th('CTR','ctr')}${th('CPM','cpm')}
    </tr></thead>`;

    const T = {spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0};
    let html = `<table class="resizable-table" data-resize-key="${isBm ? 'bm' : 'account'}">${thead}<tbody>`;
    const selKey = isBm ? 'bm' : 'account';

    data.forEach(item => {
        const p = isBm ? item : (item.period || {}); // stats are in item.period for accounts
        const isSelected = (state.selections[selKey]||new Set()).has(String(isBm?item.bm_id:item.id));
        ['spend','delta','impressions','clicks','leads','regs','deps','revenue'].forEach(k => T[k]+=p[k]||0);

        if (isBm) {
            const isHighlighted = state.filters.bm_id && String(item.bm_id) === String(state.filters.bm_id);
            html += `<tr class="${isSelected?'sel':''}">
                <td><div class="tdi center"><input type="checkbox" data-id="${item.bm_id}" ${isSelected||isHighlighted?'checked':''} onchange="toggleRow('${item.bm_id}',this)"></div></td>
                <td></td>
                <td><div class="tdi left"><div class="nc"><div class="nc-texts">
                    <div class="nc-name" onclick="drillDownBm('${item.bm_id}','${esc(item.bm_name)}')">${esc(item.bm_name)}</div>
                    <div class="nc-sub">${esc(item.bm_id)}  |  ${item.cnt} acc.</div>
                </div></div></div></td>
                <td><div class="tdi left" style="font-size:11px">
                    ${item.active>0?`<span style="color:var(--green)">${item.active} active</span>`:''}
                    ${item.banned>0?`<span style="color:var(--text3)">${item.banned} off</span>`:''}
                </div></td>
                <td><div class="tdi"><span class="num">${fN(item.campaigns_active_count)} / ${fN(item.campaigns_count)}</span></div></td>
                ${statCols(p)}</tr>`;
        } else if (item._isOrphan) {
            html += `<tr style="opacity:.7">
                <td></td><td></td>
                <td><div class="tdi left"><div class="nc"><div class="nc-texts">
                    <div class="nc-name" style="color:var(--text3);cursor:default">(no structure)</div>
                    <div class="nc-sub">orphan rows without account</div>
                </div></div></div></td>
                <td></td><td></td><td></td>
                ${statCols(p)}</tr>`;
        } else {
            const isBanned = item.status!==1;
            const statusLabel = {1:'Active',2:'Off',3:'Debt',7:'Review',9:'Grace'}[item.status]||String(item.status);
            const dlvCls = item.status===1?'ACTIVE':'PAUSED';
            html += `<tr class="${isSelected?'sel':''}${isBanned?' banned':''}">
                <td><div class="tdi center"><input type="checkbox" data-id="${item.id}" ${isSelected?'checked':''} onchange="toggleRow('${item.id}',this)"></div></td>
                <td></td>
                <td><div class="tdi left"><div class="nc"><div class="nc-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="18" rx="2"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="8" x2="12" y2="8"/></svg>
                    </div><div class="nc-texts">
                    <div class="nc-name" onclick="drillDownAccount('${item.id}','${esc(item.name)}')" style="${isBanned?'color:var(--text3)':''}">${esc(item.name)}</div>
                    <div class="nc-sub">${esc(item.id)}  |  ${esc(item.bm_name||'')}</div>
                </div></div></div></td>
                <td><div class="tdi left"><div class="dlv ${dlvCls}"><div class="dlv-dot"></div>${esc(statusLabel)}</div></div></td>
                <td><div class="tdi"><span class="num">${fN(item.campaigns_active_count)}</span></div></td>
                <td><div class="tdi"><span class="num">${fN(item.campaigns_count)}</span></div></td>
                ${statCols(p)}</tr>`;
        }
    });

    html += `</tbody><tfoot><tr class="total-row"><td></td><td></td>
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${data.length} ${isBm?'BM':'accounts'}</div></td><td></td>
        ${isBm ? `<td><div class="tdi"><span class="num">${fN(data.reduce((s,x)=>s+Number(x.campaigns_active_count||0),0))} / ${fN(data.reduce((s,x)=>s+Number(x.campaigns_count||0),0))}</span></div></td>` : `<td><div class="tdi"><span class="num">${fN(data.reduce((s,x)=>s+Number(x.campaigns_active_count||0),0))}</span></div></td><td><div class="tdi"><span class="num">${fN(data.reduce((s,x)=>s+Number(x.campaigns_count||0),0))}</span></div></td>`}
        ${statCols(T)}</tr></tfoot></table>`;
    const tblwrapEl = document.getElementById('tblwrap');
    tblwrapEl.innerHTML = html;
    initColumnResizing(tblwrapEl);
    window._lastAccts = accts;
    updateBulk();
}
// -- CAMPAIGNS / ADSETS / ADS TABLE ---------------------------
function getFilteredRows() {
    const ts = curTab();
    let r = [...rows];
    const q = (ts.search||'').toLowerCase();
    if (q) r = r.filter(x => (x.name||'').toLowerCase().includes(q)||String(x.id).includes(q));
    if (ts.delivery) r = r.filter(x => ts.delivery === 'ACTIVE' ? isReallyActive(x) : realDeliveryStatus(x) === ts.delivery);
    r = r.filter(x => matchesParentDeliveryFilters(x));
    r.sort((a,b) => {
        const col = ts.sortCol;
        const va = col.startsWith('stats.') ? (a.stats?.[col.slice(6)]??0) : (a[col]??'');
        const vb = col.startsWith('stats.') ? (b.stats?.[col.slice(6)]??0) : (b[col]??'');
        return compareWithActive(a, b, cmp(va, vb));
    });
    return r;
}

function normalizeRulesVerdict(value) {
    return String(value || 'NO_DATA').trim().toUpperCase() || 'NO_DATA';
}

function rulesVerdictClass(value) {
    return normalizeRulesVerdict(value).toLowerCase().replace(/[^a-z0-9]+/g, '_');
}

function rulesStatLine(label, stat) {
    if (!stat || typeof stat !== 'object') return `${label}: no data`;
    return `${label}: spend ${f$(stat.spend || 0)}, deps ${fN(stat.deps || 0)}, revenue ${f$(stat.revenue || 0)}, profit ${f$(stat.profit || ((stat.revenue || 0) - (stat.spend || 0)))}, ROI ${fPct(stat.roi ?? 0)}, C2L ${fPct(stat.c2l ?? null)}, R2D ${fPct(stat.r2d ?? null)}`;
}

function rulesBaselineLine(baseline) {
    if (!baseline || typeof baseline !== 'object') return 'Baseline diff: no data';
    const bits = Object.entries(baseline).map(([k, v]) => {
        if (v && typeof v === 'object') {
            const actual = Number(v.actual);
            const base = Number(v.baseline);
            const diff = Number(v.diff_pct);
            const ratio = Number(v.ratio);
            const miss = v.miss ? ' miss' : '';
            return `${k}: ${Number.isFinite(actual) ? actual.toFixed(2) : 'n/a'} vs ${Number.isFinite(base) ? base.toFixed(2) : 'n/a'} (${Number.isFinite(diff) ? diff.toFixed(1) + '%' : 'n/a'}, x${Number.isFinite(ratio) ? ratio.toFixed(2) : 'n/a'}${miss})`;
        }
        return `${k}: ${typeof v === 'number' ? v.toFixed(2) : v}`;
    });
    return 'Baseline diff: ' + (bits.length ? bits.join(', ') : 'no data');
}

function rulesScoreBreakdownLine(breakdown) {
    if (!breakdown || typeof breakdown !== 'object') return 'V2 score model: no data';
    if (breakdown.p_profit !== undefined || breakdown.prior !== undefined || Array.isArray(breakdown.notes)) {
        const pProfit = breakdown.p_profit !== undefined ? Number(breakdown.p_profit).toFixed(0) + '%' : 'n/a';
        const score = breakdown.score !== undefined ? Number(breakdown.score).toFixed(0) : 'n/a';
        const prior = breakdown.prior || 'n/a';
        const payouts = breakdown.payout_multiple !== undefined ? Number(breakdown.payout_multiple).toFixed(2) : 'n/a';
        return `V2 model: ${breakdown.model || 'probability'} | p_profit ${pProfit} | score ${score} | prior ${prior} | all-time spend ${payouts} payouts`;
    }
    const weights = breakdown.weights || {};
    const scores = breakdown.scores || {};
    const pct = key => weights[key] !== undefined ? Math.round(Number(weights[key]) * 100) + '%' : '?';
    const val = key => scores[key] !== undefined ? Number(scores[key]).toFixed(0) : '?';
    const total = breakdown.score !== undefined ? Number(breakdown.score).toFixed(0) : '?';
    return `V2 score model: ${breakdown.model || 'weighted'} | today ${val('today')} x ${pct('today')}, 7d ${val('last7')} x ${pct('last7')}, 30d ${val('last30')} x ${pct('last30')} => ${total}`;
}

function rulesScoreNotesLine(breakdown) {
    const notes = breakdown?.notes || {};
    if (Array.isArray(notes)) {
        return `V2 factors: ${notes.length ? notes.join('; ') : 'n/a'}`;
    }
    const fmt = key => Array.isArray(notes[key]) && notes[key].length ? notes[key].join('; ') : 'n/a';
    return `V2 score notes: today: ${fmt('today')} | 7d: ${fmt('last7')} | 30d: ${fmt('last30')}`;
}

function rulesRestartHysteresisLine(h) {
    if (!h || typeof h !== 'object') return null;
    const state = h.blocked ? 'blocked' : 'clear';
    const parts = [`Restart hysteresis: ${state}`];
    if (h.hours !== undefined) parts.push(`${Number(h.hours).toFixed(1)}h`);
    if (h.paused_at) parts.push(`paused ${h.paused_at}`);
    if (h.restart_after) parts.push(`restart after ${h.restart_after}`);
    if (h.pause_task_id) parts.push(`pause task #${h.pause_task_id}`);
    if (h.original?.candidate_verdict) parts.push(`original ${h.original.candidate_verdict}`);
    return parts.join(' | ');
}

function rulesVerdictTooltip(row, kind) {
    const rc = row.rules_check || {};
    const today = rc.today || rc.data_1d || {};
    const last7 = rc.last7 || rc.data_7d || {};
    const last30 = rc.last30 || rc.data_30d || {};
    const lines = [
        `Campaign: ${row.name || row.id || ''}`,
        `Campaign ID: ${row.id || ''}`,
        `Metrics source: ${rc.metrics_source || rc.used_algo || 'n/a'}`,
    ];
    if (kind === 'v1') {
        lines.push(
            `V1 verdict: ${normalizeRulesVerdict(rc.verdict)}`,
            `Desired status: ${rc.desired_status || 'n/a'}`,
            `Should change: ${rc.should_change ? 'yes' : 'no'}`,
            `Signal level: ${rc.signal_level || 'n/a'}`,
            `Reason: ${rc.reason_short || rc.reason || 'n/a'}`,
            `Detail: ${rc.reason_detail || 'n/a'}`,
            `Why now: ${rc.why_now || 'n/a'}`
        );
    } else {
        const hysteresisLine = rulesRestartHysteresisLine(rc.auto_rules_restart_hysteresis);
        lines.push(
            `V2 verdict: ${normalizeRulesVerdict(rc.candidate_verdict)}`,
            `V2 action: ${rc.candidate_action || 'n/a'}`,
            `Candidate level: ${rc.candidate_level || 'n/a'}`,
            `Desired status: ${rc.candidate_desired_status || 'n/a'}`,
            `Would change status: ${rc.candidate_should_change ? 'yes' : 'no'}`,
            `Restart policy: ${rc.restart_policy || 'n/a'}`,
            ...(hysteresisLine ? [hysteresisLine] : []),
            `V2 baseline: ${rc.v2_baseline_source || 'n/a'}`,
            `p_profit: ${rc.potential_score ?? 'n/a'}%`,
            rulesScoreBreakdownLine(rc.candidate_score_breakdown),
            rulesScoreNotesLine(rc.candidate_score_breakdown),
            `Reason: ${rc.candidate_reason || 'n/a'}`,
            rulesBaselineLine(rc.baseline_diff)
        );
    }
    lines.push(
        rulesStatLine('Today', today),
        rulesStatLine('Last 7d', last7),
        rulesStatLine('Last 30d', last30),
        `Limits: daily ${f$(rc.daily_limit || 0)}, target CPD ${f$(rc.target_cpd || 0)}, hold stop ${f$(rc.hold_stop_after || 0)}`,
        `Task: ${rc.auto_rule_task?.id ? ('#' + rc.auto_rule_task.id + ' ' + (rc.auto_rule_task.status || '')) : 'none'}`
    );
    return lines.join('\n');
}

function rulesVerdictCell(row, kind) {
    const rc = row.rules_check || {};
    const value = kind === 'v1'
        ? normalizeRulesVerdict(rc.verdict || row.v1_verdict)
        : normalizeRulesVerdict(rc.candidate_verdict || row.v2_verdict);
    const tooltip = rulesVerdictTooltip(row, kind);
    return `<td><div class="tdi left"><span class="rules-verdict ${rulesVerdictClass(value)}" title="${escAttr(tooltip)}" data-copy="${escAttr(tooltip)}" onclick="copyToClipboard(this.dataset.copy,this)">${esc(value)}</span></div></td>`;
}

function savedAutoRulePayloadTitle(row) {
    const payload = row.auto_rule_payload || null;
    if (!payload) return 'No saved auto-rule payload';
    try {
        return JSON.stringify(payload, null, 2);
    } catch (e) {
        return String(payload);
    }
}

function savedAutoRuleVerdictCell(row) {
    const value = normalizeRulesVerdict(row.auto_rule_verdict || row.auto_rule_payload?.v2?.verdict);
    const title = savedAutoRulePayloadTitle(row);
    return `<td><div class="tdi left"><span class="rules-verdict ${rulesVerdictClass(value)}" title="${escAttr(title)}">${esc(value)}</span></div></td>`;
}

function applyRulesVerdictFilters(data) {
    if (state.filters.v1_verdict) {
        data = data.filter(row => normalizeRulesVerdict(row.v1_verdict || row.rules_check?.verdict) === state.filters.v1_verdict);
    }
    if (state.filters.v2_verdict) {
        data = data.filter(row => normalizeRulesVerdict(row.v2_verdict || row.rules_check?.candidate_verdict) === state.filters.v2_verdict);
    }
    return data;
}

function renderTable() {
    const ts = curTab();
    const v=state.view, isRulesCheck=v==='rules_check', isCamp=v==='campaign'||isRulesCheck, isAdset=v==='adset', isAd=v==='ad';
    let filtered = [...rows];

    const q = (ts.search||'').toLowerCase();
    if (q) filtered = filtered.filter(x =>
        (x.name||'').toLowerCase().includes(q) ||
        String(x.id).includes(q) ||
        (x.campaign_name||'').toLowerCase().includes(q) ||
        (x.adset_name||'').toLowerCase().includes(q) ||
        String(x.campaign_id||'').includes(q) ||
        String(x.adset_id||'').includes(q)
    );
    if (ts.delivery) filtered = filtered.filter(x => ts.delivery === 'ACTIVE' ? isReallyActive(x) : realDeliveryStatus(x) === ts.delivery);
    filtered = filtered.filter(x => matchesParentDeliveryFilters(x, v));
    if (isRulesCheck) filtered = applyRulesVerdictFilters(filtered);

    filtered.sort((a,b) => {
        let va = ts.sortCol.startsWith('stats.') ? metricValue(a.stats, ts.sortCol.slice(6)) : (a[ts.sortCol]??0);
        let vb = ts.sortCol.startsWith('stats.') ? metricValue(b.stats, ts.sortCol.slice(6)) : (b[ts.sortCol]??0);
        const primary = (typeof va==='string'||typeof vb==='string')
            ? (ts.sortDir==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va)))
            : (ts.sortDir==='asc'?va-vb:vb-va);
        return compareWithActive(a, b, primary);
    });

    if (!filtered.length) { document.getElementById('tblwrap').innerHTML='<div class="tbl-empty">No data</div>'; updateBulk(); return; }

    const T = filtered.reduce((acc,x) => {
        const s=x.stats||{};
        return {impressions:acc.impressions+(s.impressions||0),clicks:acc.clicks+(s.clicks||0),leads:acc.leads+(s.leads||0),regs:acc.regs+(s.regs||0),deps:acc.deps+(s.deps||0),spend:acc.spend+(s.spend||0),delta:acc.delta+(s.delta||0),revenue:acc.revenue+(s.revenue||0)};
    },{impressions:0,clicks:0,leads:0,regs:0,deps:0,spend:0,delta:0,revenue:0});
    T.profit=T.revenue-T.spend; T.roi=T.spend>0?T.profit/T.spend*100:0;
    T.ctr=T.impressions>0?T.clicks/T.impressions*100:0; T.cpm=T.impressions>0?T.spend/T.impressions*1000:0;
    T.cpc=T.clicks>0?T.spend/T.clicks:0; T.cpl=T.leads>0?T.spend/T.leads:0; T.cpr=T.regs>0?T.spend/T.regs:0; T.cpd=T.deps>0?T.spend/T.deps:0;
    const BT = v === 'campaign' ? sumCostStats([...new Map(filtered.map(row => [extractGeo(row.name || ''), row.cost_baseline])).values()], x => x) : null;

    const sel=state.selections[v]||new Set();

    let html=`<table class="resizable-table" data-resize-key="${v}"><thead><tr>
        <th class="resizable-th" style="width:36px" data-col-key="select"><div class="thi center"><input type="checkbox" id="cbAll" onchange="toggleAll(this)"></div></th>
        <th class="resizable-th" style="width:44px" data-col-key="toggle"><div class="thi center">On</div></th>
        ${th(isCamp?'Campaign':isAdset?'Ad Set':'Ad','name','left')}
        <th class="resizable-th" data-col-key="status"><div class="thi left">Status</div></th>
        ${v==='campaign'?th('Verdict','auto_rule_verdict','left'):''}
        ${isRulesCheck?`${th('V1 verdict','v1_verdict','left')}${th('V2 verdict','v2_verdict','left')}`:''}
        ${isAdset?th('Bid','bid_amount','right'):''}
        ${!isCamp?th('Campaign','campaign_name','left'):''}
        ${isAd?th('Ad Set','adset_name','left'):''}
        ${th('Impressions','stats.impressions')}${th('Clicks','stats.clicks')}${th('CPC','stats.cpc')}
        ${th('Leads','stats.leads')}${th('CPL','stats.cpl')}
        ${th('Regs','stats.regs')}${th('CPR','stats.cpr')}
        ${th('Deps','stats.deps')}${th('CPD','stats.cpd')}${th('R2D','stats.r2d')}
        ${th('Spend','stats.spend')}${th('Delta','stats.delta')}${th('Revenue','stats.revenue')}
        ${th('Profit','stats.profit')}${th('ROI','stats.roi')}
        ${th('CTR','stats.ctr')}${th('CPM','stats.cpm')}
    </tr></thead><tbody>`;

    filtered.forEach(row => {
        const s=row.stats||{}, dlv=row.effective_status||row.status||'';
        const costBaseline = v === 'campaign' ? row.cost_baseline : null;
        const realStatus = realDeliveryStatus(row);
        const ownStatus = normalizedStatus(row.status);
        const ownDisplayStatus = isAd && ownStatus ? ownStatus : realStatus;
        const pendingTask = isCamp
            ? pendingCampaignTasks.get(String(row.id))
            : (isAdset ? pendingAdsetTasks.get(String(row.id)) : (isAd ? pendingAdTasks.get(String(row.id)) : null));
        const displayStatus = pendingTask ? 'PENDING_TASK' : ownDisplayStatus;
        const pendingActionLabel = pendingTask?.taskType === 'delete_campaign'
            ? 'Delete'
            : (pendingTask?.taskType === 'update_adset_bid'
                ? (pendingTask.targetLabel || 'Bid update')
                : (pendingTask?.desiredStatus === 'ACTIVE' ? 'ACTIVE' : 'PAUSED'));
        const isOrphan = row._isOrphan;
        const dlvLabel={ACTIVE:'Active',PAUSED:'Paused',MANUAL_STOP:'Manual stop',PENDING_TASK:'Updating',DELETED:'Deleted',ARCHIVED:'Archived',IN_PROCESS:'Learning',WITH_ISSUES:'Issue',DISAPPROVED:'Disapproved'}[displayStatus]||dlv;
        const realStatusLabel = {
            PENDING_REVIEW: 'PENDING_REVIEW',
            CAMPAIGN_PAUSED: 'CAMPAIGN_PAUSED',
            ADSET_PAUSED: 'ADSET_PAUSED'
        }[displayStatus] || dlvLabel;
        const accountStatus = row.account_status === undefined || row.account_status === null ? 1 : Number(row.account_status);
        const accountInactive = accountStatus !== 1;
        const accountStatusLabel = ACCOUNT_STATUS_LABELS[accountStatus] || ('Account status ' + accountStatus);
        const manualStop = isCamp && (row.manual_status === 'manual_stop' || realStatus === 'MANUAL_STOP');
        const pendingAgeMs = pendingTask?.startedAt ? Math.max(0, Date.now() - pendingTask.startedAt) : 0;
        const pendingRetryable = !!pendingTask && !!pendingTask.taskId && pendingAgeMs >= 90000;
        const isOn=pendingTask ? pendingTask.desiredStatus === 'ACTIVE' : (isAd ? ownStatus === 'ACTIVE' : isReallyActive(row) && !manualStop), selected=sel.has(String(row.id));
        const toggleReadonly = !(isCamp || isAdset || isAd);
        const toggleDisabled = accountInactive || toggleReadonly || !!pendingTask;
        const toggleTitle = pendingTask ? `Task #${pendingTask.taskId || '...'} in progress` : (accountInactive ? accountStatusLabel : (toggleReadonly ? 'Read only: control via campaigns' : (manualStop ? 'Manual stop' : '')));
        const statusClass = isAd && !isOrphan && ownDisplayStatus ? ' ad-status-' + ownDisplayStatus.toLowerCase().replace(/[^a-z0-9]+/g, '_') : '';
        const pendingClass = pendingTask ? ' campaign-status-pending' : '';
        const canDeleteCampaign = isCamp && !isOrphan && !accountInactive && !pendingTask && realStatus !== 'DELETED' && realStatus !== 'ARCHIVED';
        const canEditBid = isAdset && !isOrphan && !accountInactive && !pendingTask;
        const bidCellHtml = isAdset
            ? `<td><div class="tdi"><div><div class="bid-cell"><span class="num bid-value">${row.bid_amount !== null && row.bid_amount !== undefined ? f$(row.bid_amount) : '-'}</span><button class="bid-edit-btn" title="${escAttr(canEditBid ? 'Edit bid' : (pendingTask ? ('Task #' + (pendingTask.taskId || '...') + ' in progress') : accountStatusLabel))}" ${canEditBid ? `onclick="openAdsetBidPopup('${escAttr(String(row.id))}')"` : 'disabled'}><svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 20h9\"/><path d=\"M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z\"/></svg></button></div>${pendingTask?.taskType === 'update_adset_bid' ? `<div class="dlv-sub" style="text-align:right;color:#9a4b00;font-weight:700">${esc(pendingTask.targetLabel || 'Waiting for bid update')}</div>` : ''}</div></div></td>`
            : '';
        const deleteButton = isCamp
            ? `<span class="campaign-inline-actions"><button class="campaign-delete-btn" title="${escAttr(canDeleteCampaign ? 'Delete campaign' : (pendingTask ? 'Task in progress' : 'Campaign cannot be deleted right now'))}" ${canDeleteCampaign ? `onclick="event.stopPropagation(); deleteCampaign('${escAttr(String(row.id))}')"` : 'disabled'}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button></span>`
            : '';
        html+=`<tr class="${(selected?'sel':'')}${statusClass}${pendingClass}"${isOrphan?' style="opacity:.7"':''}>
        <td><div class="tdi center">${isOrphan?'':'<input type="checkbox" data-id="'+row.id+'" '+(selected?'checked':'')+' onchange="toggleRow(\''+row.id+'\',this)">'}</div></td>
        <td><div class="tdi center">${isOrphan?'':`<button class="tog ${isOn?'on':'off'}" ${toggleDisabled?'disabled':''} ${toggleTitle?'title="'+esc(toggleTitle)+'"':''} ${(!toggleDisabled && (isCamp || isAdset || isAd))?'onclick="toggleStatus(\''+esc(String(row.id))+'\')"':''}></button>`}</div></td>
        <td><div class="tdi left"><div class="nc">
                <div class="nc-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${isCamp?'<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>':isAdset?'<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>':'<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="12" x2="15" y2="12"/>'}</svg></div>
                <div class="nc-texts">
                    <div class="nc-name" ${isOrphan?'style="color:var(--text3);cursor:default"':('onclick="drillDown(\''+row.id+'\')"')}>${esc(row.name)}${deleteButton}</div>
                    <div class="nc-sub">${isOrphan?'':esc(row.id)+'  |  '+(row.account_id||'')+(row.bm_name?' '+row.bm_name:'')}</div>
                </div>
            </div></div></td>
            <td><div class="tdi left">${isOrphan?'':`<div><div class="dlv ${displayStatus} ${accountInactive?'account-muted':''}" title="${esc(pendingTask ? ('Task #' + (pendingTask.taskId || '...')) : (ownDisplayStatus || realStatusLabel))}"><div class="dlv-dot"></div>${esc(realStatusLabel)}</div>${pendingTask?`<div class="dlv-sub" style="color:#9a4b00;font-weight:700">Waiting for ${esc(pendingActionLabel)}</div>${pendingRetryable?`<button class="task-action-btn" style="margin-top:6px" onclick="event.stopPropagation();retryPendingEntityTask('${escAttr(String(row.id))}','${isCamp ? 'campaign' : (isAdset ? 'adset' : 'ad')}',this)">Retry</button>`:''}`:''}${isAd&&realStatus&&realStatus!==ownDisplayStatus?`<div class="dlv-sub">${esc(realStatus)}</div>`:''}${accountInactive?`<div class="dlv-sub">${esc(accountStatusLabel)}</div>`:''}</div>`}</div></td>
            ${v==='campaign'?savedAutoRuleVerdictCell(row):''}
            ${isRulesCheck?`${rulesVerdictCell(row,'v1')}${rulesVerdictCell(row,'v2')}`:''}
            ${bidCellHtml}
            ${!isCamp?`<td><div class="tdi left"><span class="num" style="font-size:11px">${esc(row.campaign_name||'')}</span></div></td>`:''}
            ${isAd?`<td><div class="tdi left"><span class="num" style="font-size:11px">${esc(row.adset_name||'')}</span></div></td>`:''}
            <td><div class="tdi"><span class="num">${fN(s.impressions)}</span></div></td>
            <td><div class="tdi"><span class="num">${fN(s.clicks)}</span></div></td>
            ${costMetricCell(s, 'cpc', s.cpc, costBaseline)}
            <td><div class="tdi"><span class="num">${s.leads>0?fN(s.leads):'-'}</span></div></td>
            ${costMetricCell(s, 'cpl', s.leads > 0 ? s.spend / s.leads : 0, costBaseline)}
            <td><div class="tdi"><span class="num">${s.regs>0?fN(s.regs):'-'}</span></div></td>
            ${costMetricCell(s, 'cpr', s.regs > 0 ? s.spend / s.regs : 0, costBaseline)}
            <td><div class="tdi"><span class="num">${s.deps>0?fN(s.deps):'-'}</span></div></td>
            ${costMetricCell(s, 'cpd', s.deps > 0 ? s.spend / s.deps : 0, costBaseline)}
            <td><div class="tdi"><span class="num">${fR2D(s.regs,s.deps)}</span></div></td>
            <td><div class="tdi"><span class="num spend-val">${f$(s.spend)}</span></div></td>
            <td><div class="tdi"><span class="num">${f$(s.delta||0)}</span></div></td>
            <td><div class="tdi"><span class="num">${s.revenue>0?f$(s.revenue):'-'}</span></div></td>
            <td><div class="tdi"><span class="num" style="color:${(s.revenue-s.spend)>0?'var(--green)':(s.revenue-s.spend)<0?'var(--red)':''}">${s.revenue>0||s.spend>0?f$(s.revenue-s.spend):'-'}</span></div></td>
            <td><div class="tdi"><span class="num" style="color:${(s.revenue-s.spend)>0?'var(--green)':(s.revenue-s.spend)<0?'var(--red)':''}">${s.spend>0?((s.revenue-s.spend)/s.spend*100).toFixed(1)+'%':'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${fP(s.ctr)}</span></div></td>
            <td><div class="tdi"><span class="num">${f$(s.cpm)}</span></div></td>
        </tr>`;
    });

    const pC=T.profit>0?'var(--green)':T.profit<0?'var(--red)':'inherit';
    html+=`</tbody><tfoot><tr class="total-row"><td></td><td></td>
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${filtered.length} rows</div></td><td></td>
        ${v==='campaign'?'<td></td>':''}
        ${isRulesCheck?'<td></td><td></td>':''}
        ${isAdset?'<td></td>':''}
        ${!isCamp?'<td></td>':''}${isAd?'<td></td>':''}
        <td><div class="tdi"><span class="num">${fN(T.impressions)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(T.clicks)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpc>0?f$(T.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?fN(T.leads):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?f$(T.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?fN(T.regs):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?f$(T.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?fN(T.deps):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?f$(T.cpd):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(T.regs,T.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${f$(T.spend)}</span></div></td>
        <td><div class="tdi"><span class="num">${f$(T.delta||0)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.revenue>0?f$(T.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="color:${pC}">${T.revenue>0||T.spend>0?f$(T.profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="color:${pC}">${T.spend>0?T.roi.toFixed(1)+'%':'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fP(T.ctr)}</span></div></td>
        <td><div class="tdi"><span class="num">${f$(T.cpm)}</span></div></td>
    </tr></tfoot></table>`;
    const tblwrapEl = document.getElementById('tblwrap');
    tblwrapEl.innerHTML = html;
    initColumnResizing(tblwrapEl);
    updateBulk();
}
// -- SELECTION ------------------------------------------------
function toggleRow(id, cb) {
    id = String(id);
    const sel = state.selections[state.view]||(state.selections[state.view]=new Set());
    if (cb.checked) sel.add(id); else sel.delete(id);
    cb.closest('tr').classList.toggle('sel', cb.checked);
    updateBulk();
    const all = document.querySelectorAll('tbody input[type=checkbox]');
    const hdr = document.getElementById('cbAll');
    if (hdr) { hdr.checked=[...all].every(c=>c.checked); hdr.indeterminate=sel.size>0&&!hdr.checked; }
    pushURL({replace:true});
}
function toggleAll(cb) {
    const sel = state.selections[state.view]||(state.selections[state.view]=new Set());
    document.querySelectorAll('tbody input[type=checkbox]').forEach(c => {
        c.checked=cb.checked; c.closest('tr').classList.toggle('sel',cb.checked);
        if (cb.checked) sel.add(String(c.dataset.id)); else sel.delete(String(c.dataset.id));
    });
    updateBulk();
    pushURL({replace:true});
}
function clearSel() {
    const sel = state.selections[state.view];
    if (sel) sel.clear();
    document.querySelectorAll('tbody input[type=checkbox]').forEach(c => { c.checked=false; c.closest('tr').classList.remove('sel'); });
    const h=document.getElementById('cbAll'); if (h) { h.checked=false; h.indeterminate=false; }
    updateBulk();
    pushURL();
}
function updateBulk() {
    const isTable = TABLE_LEVELS.includes(state.view);
    const sel = isTable ? state.selections[state.view] : null;
    const count = sel?.size||0;
    document.getElementById('selCount').textContent = count;
    document.getElementById('selbar').classList.toggle('on', count>0);
    updateSelBadge('bm','bmSelBadge','bmSelCount');
    updateSelBadge('account','acctSelBadge','acctSelCount');
    updateSelBadge('campaign','campSelBadge','campSelCount');
    updateSelBadge('adset','adsetSelBadge','adsetSelCount');
    updateSelBadge('ad','adSelBadge','adSelCount');
}
function updateSelBadge(lv, badgeId, countId) {
    const n = state.selections[lv]?.size||0;
    const badge = document.getElementById(badgeId);
    if (!badge) return;
    badge.style.display = n>0?'':'none';
    const el = document.getElementById(countId);
    if (el) el.textContent = n+' selected';
}
function clearLevelSel(lv) {
    if (state.selections[lv]) state.selections[lv].clear();
    updateBulk();
    pushURL();
    reload();
}
async function toggleStatus(id) {
    const btn = document.querySelector(`button.tog[onclick*="'${id}'"]`);
    if (!btn) return;
    if (btn.disabled) return;
    if (state.view !== 'campaign' && state.view !== 'rules_check' && state.view !== 'adset' && state.view !== 'ad') return;
    const row = rows.find(r => String(r.id) === String(id));
    if (!row) return;
    const wasOn = btn.classList.contains('on');
    const desiredStatus = wasOn ? 'PAUSED' : 'ACTIVE';
    const isCampaignView = state.view === 'campaign' || state.view === 'rules_check';
    const isAdsetView = state.view === 'adset';
    const isAdView = state.view === 'ad';
    const entityType = isCampaignView ? 'campaign' : (isAdsetView ? 'adset' : 'ad');
    const taskType = isCampaignView ? 'set_campaign_status' : (isAdsetView ? 'set_adset_status' : 'set_ad_status');
    const pendingMap = pendingTaskMap(entityType);
    pendingMap.set(String(id), {
        taskId: null,
        taskType,
        desiredStatus,
        previousStatus: row.status,
        previousEffectiveStatus: row.effective_status,
        previousManualStatus: row.manual_status || '',
        startedAt: Date.now()
    });
    renderTable();
    try {
        const res = await fetch('/api/tasks.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                task_type: taskType,
                campaign_id: isCampaignView ? String(id) : undefined,
                adset_id: isAdsetView ? String(id) : undefined,
                ad_id: isAdView ? String(id) : undefined,
                desired_status: desiredStatus
            })
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Task API error');
        const task = json.data?.task || {};
        const pending = pendingMap.get(String(id));
        if (pending) {
            pending.taskId = task.id;
            pending.status = task.status || 'pending';
            pendingMap.set(String(id), pending);
        }
        renderTable();
        pollEntityTask(task.id, String(id), desiredStatus, taskType, entityType);
    } catch (e) {
        pendingMap.delete(String(id));
        renderTable();
        alert('Task error: ' + e.message);
    }
}

async function deleteCampaign(id) {
    const row = rows.find(r => String(r.id) === String(id));
    if (!row) return;
    if (!confirm(`Delete campaign "${row.name || row.id}"?`)) return;
    pendingCampaignTasks.set(String(id), {
        taskId: null,
        taskType: 'delete_campaign',
        desiredStatus: 'DELETED',
        previousStatus: row.status,
        previousEffectiveStatus: row.effective_status,
        previousManualStatus: row.manual_status || '',
        startedAt: Date.now()
    });
    renderTable();
    try {
        const res = await fetch('/api/tasks.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                task_type: 'delete_campaign',
                campaign_id: String(id)
            })
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Task API error');
        const task = json.data?.task || {};
        const pending = pendingCampaignTasks.get(String(id));
        if (pending) {
            pending.taskId = task.id;
            pending.status = task.status || 'pending';
            pendingCampaignTasks.set(String(id), pending);
        }
        renderTable();
        pollEntityTask(task.id, String(id), 'DELETED', 'delete_campaign', 'campaign');
    } catch (e) {
        pendingCampaignTasks.delete(String(id));
        renderTable();
        alert('Task error: ' + e.message);
    }
}

function pendingTaskMap(entityType) {
    if (entityType === 'campaign') return pendingCampaignTasks;
    if (entityType === 'ad') return pendingAdTasks;
    return pendingAdsetTasks;
}
function pendingTaskTimerMap(entityType) {
    if (entityType === 'campaign') return campaignTaskTimers;
    if (entityType === 'ad') return adTaskTimers;
    return adsetTaskTimers;
}

function pollEntityTask(taskId, entityId, desiredStatus, taskType = 'set_campaign_status', entityType = 'campaign', attempt = 0) {
    if (!taskId) return;
    const key = String(entityId);
    const pendingMap = pendingTaskMap(entityType);
    const timerMap = pendingTaskTimerMap(entityType);
    clearTimeout(timerMap.get(key));
    timerMap.set(key, setTimeout(async () => {
        try {
            const params = new URLSearchParams({task_id: String(taskId), _: String(Date.now())});
            const res = await fetch('/api/tasks.php?' + params, {cache: 'no-store'});
            const text = await res.text();
            let json = null;
            try {
                json = text ? JSON.parse(text) : null;
            } catch (parseErr) {
                const snippet = (text || '').trim().slice(0, 220);
                const details = `HTTP ${res.status}${snippet ? ' | ' + snippet : ''}`;
                throw new Error(details);
            }
            if (!res.ok) {
                const snippet = (text || '').trim().slice(0, 220);
                throw new Error(`HTTP ${res.status}${snippet ? ' | ' + snippet : ''}`);
            }
            if (!json || typeof json !== 'object') {
                throw new Error(`HTTP ${res.status} | Empty response body`);
            }
            if (!json.ok) throw new Error(json.error || 'Task API error');
            const task = json.data?.task || json.data?.tasks?.[0] || null;
            if (!task) throw new Error('Task not found');
            const pending = pendingMap.get(key);
            if (pending && pending.taskId && String(pending.taskId) !== String(taskId)) {
                return;
            }
            if (pending) {
                pending.status = task.status;
                pending.error = task.error || '';
                pendingMap.set(key, pending);
            }
            if (task.status === 'done') {
                timerMap.delete(key);
                pendingMap.delete(key);
                applyEntityTaskDone(key, desiredStatus, taskType, entityType, task);
                renderTable();
                if (state.view === 'tasks') loadTasksData();
                return;
            }
            if (task.status === 'failed' || task.status === 'cancelled') {
                timerMap.delete(key);
                pendingMap.delete(key);
                renderTable();
                alert('Task execution error #' + taskId + ': ' + (task.error || task.status));
                if (state.view === 'tasks') loadTasksData();
                return;
            }
            if (attempt < 90) {
                pollEntityTask(taskId, key, desiredStatus, taskType, entityType, attempt + 1);
            } else {
                const pending = pendingMap.get(key);
                if (pending) {
                    pending.error = 'timeout';
                    pendingMap.set(key, pending);
                }
                renderTable();
            }
        } catch (e) {
            if (attempt < 5) {
                pollEntityTask(taskId, key, desiredStatus, taskType, entityType, attempt + 1);
            } else {
                timerMap.delete(key);
                pendingMap.delete(key);
                renderTable();
                alert('Could not check task #' + taskId + ': ' + e.message);
            }
        }
    }, attempt === 0 ? 1200 : 2000));
}

function applyEntityTaskDone(entityId, desiredStatus, taskType = 'set_campaign_status', entityType = 'campaign', task = null) {
    const row = rows.find(r => String(r.id) === String(entityId));
    if (!row) return;
    if (entityType === 'adset' && taskType === 'update_adset_bid') {
        const finalBid = bidTaskResolvedValue(task || {});
        if (finalBid !== null) row.bid_amount = finalBid;
        return;
    }
    if (entityType === 'campaign' && (taskType === 'delete_campaign' || desiredStatus === 'DELETED')) {
        row.status = 'DELETED';
        row.effective_status = 'DELETED';
        row.manual_status = '';
        return;
    }
    if (entityType === 'campaign' && desiredStatus === 'PAUSED') {
        row.status = 'MANUAL_STOP';
        row.effective_status = 'MANUAL_STOP';
        row.manual_status = 'manual_stop';
    } else {
        row.status = desiredStatus;
        row.effective_status = desiredStatus;
        if (entityType === 'campaign') row.manual_status = '';
    }
}
function bulkPause()  { alert('Bulk pause: '+[...( state.selections[state.view]||[])].join(', ')+'\\n(not implemented)'); }
function bulkResume() { alert('Bulk resume: '+[...(state.selections[state.view]||[])].join(', ')+'\\n(not implemented)'); }

// -- MONTH VIEW -----------------------------------------------
function bidNum(v) {
    const n = Number(String(v ?? '').replace(',', '.'));
    return Number.isFinite(n) ? n : null;
}
function bidFmt(v) {
    const n = bidNum(v);
    return n === null ? '-' : f$(n);
}
function bidTaskTargetLabel(task = {}) {
    const payload = task.payload || {};
    if (payload.bid_amount !== undefined && payload.bid_amount !== null && payload.bid_amount !== '') {
        return `Bid ${bidFmt(payload.bid_amount)}`;
    }
    const pctRaw = payload.bid_delta_pct;
    if (pctRaw !== undefined && pctRaw !== null && pctRaw !== '') {
        const pct = Number(pctRaw);
        if (Number.isFinite(pct) && pct !== 0) {
            const sign = pct > 0 ? '+' : '';
            return `Bid ${sign}${Math.round(pct * 100)}%`;
        }
    }
    return 'Bid update';
}
function bidTaskResolvedValue(task = {}) {
    const result = task.result || {};
    const payload = task.payload || {};
    for (const candidate of [result.final_bid, result.bid_amount, result.new_bid, payload.bid_amount]) {
        const bid = bidNum(candidate);
        if (bid !== null && bid > 0) return bid;
    }
    return null;
}
function openAdsetBidPopup(id) {
    const row = rows.find(r => String(r.id) === String(id));
    if (!row) return;
    adsetBidEditor = {
        id: String(row.id),
        name: row.name || '',
        campaignName: row.campaign_name || '',
        adsetName: row.adset_name || '',
        accountId: row.account_id || '',
        campaignId: row.campaign_id || '',
        bmId: row.bm_id || '',
        currentBid: bidNum(row.bid_amount),
        mode: row.bid_amount !== null && row.bid_amount !== undefined ? 'absolute' : 'delta',
        bidDeltaPct: null,
    };
    const modal = document.getElementById('adsetBidModal');
    const title = document.getElementById('adsetBidTitle');
    const current = document.getElementById('adsetBidCurrent');
    const input = document.getElementById('adsetBidInput');
    const hint = document.getElementById('adsetBidHint');
    if (title) title.textContent = row.name || row.adset_name || 'Adset bid';
    if (current) current.textContent = bidFmt(row.bid_amount);
    if (input) input.value = row.bid_amount !== null && row.bid_amount !== undefined ? Number(row.bid_amount).toFixed(2) : '';
    if (hint) hint.textContent = row.bid_strategy_mode ? `Strategy: ${row.bid_strategy_mode}` : 'Task will be created and executed from the queue.';
    if (modal) modal.classList.add('open');
    if (input) setTimeout(() => input.focus(), 0);
}
function closeAdsetBidPopup() {
    adsetBidEditor = null;
    const modal = document.getElementById('adsetBidModal');
    if (modal) modal.classList.remove('open');
}
function shiftAdsetBid(pct) {
    const input = document.getElementById('adsetBidInput');
    if (!input) return;
    if (adsetBidEditor) {
        adsetBidEditor.mode = 'delta';
        adsetBidEditor.bidDeltaPct = pct;
    }
    const sign = pct > 0 ? '+' : '';
    input.value = `${sign}${Math.round(pct * 100)}%`;
}
function setAdsetBidAbsoluteMode() {
    if (!adsetBidEditor) return;
    adsetBidEditor.mode = 'absolute';
    adsetBidEditor.bidDeltaPct = null;
}
async function saveAdsetBid() {
    if (!adsetBidEditor) return;
    const input = document.getElementById('adsetBidInput');
    const saveBtn = document.getElementById('adsetBidSave');
    const rawValue = (input?.value || '').trim();
    const isDeltaMode = adsetBidEditor.mode === 'delta' || /%$/.test(rawValue);
    const value = isDeltaMode ? null : bidNum(rawValue);
    if (!isDeltaMode && (value === null || value <= 0)) {
        alert('Enter a valid bid.');
        return;
    }
    if (saveBtn) saveBtn.disabled = true;
    try {
        const adsetId = String(adsetBidEditor.id);
        const payload = {
            current_bid: adsetBidEditor.currentBid,
            bid_mode: isDeltaMode ? 'delta' : 'absolute',
            source: 'dashboard_bid_popup',
        };
        if (isDeltaMode) {
            const pct = adsetBidEditor.bidDeltaPct !== null && adsetBidEditor.bidDeltaPct !== undefined
                ? adsetBidEditor.bidDeltaPct
                : (bidNum(rawValue.replace('%','')) || 0) / 100;
            if (!pct) throw new Error('Choose a bid change using a percent button.');
            payload.bid_delta_pct = Number(pct.toFixed(2));
            payload.bid_delta_dir = pct > 0 ? 'UP' : 'DOWN';
        } else {
            payload.bid_amount = Number(value.toFixed(2));
            payload.bid_amount_cents = Math.round(value * 100);
        }
        const res = await fetch('/api/tasks.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                task_type: 'update_adset_bid',
                bm_id: adsetBidEditor.bmId,
                account_id: adsetBidEditor.accountId,
                campaign_id: adsetBidEditor.campaignId,
                adset_id: adsetBidEditor.id,
                priority: 200,
                payload
            })
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Task API error');
        const task = json.data?.task || {};
        pendingAdsetTasks.set(String(adsetBidEditor.id), {
            taskId: task.id || null,
            status: task.status || 'pending',
            taskType: 'update_adset_bid',
            targetLabel: bidTaskTargetLabel(task),
            error: '',
        });
        closeAdsetBidPopup();
        renderTable();
        pollEntityTask(task.id, adsetId, null, 'update_adset_bid', 'adset');
        if (state.view === 'tasks') loadTasksData();
    } catch (e) {
        pendingAdsetTasks.delete(String(adsetBidEditor?.id || ''));
        renderTable();
        alert('Task error: ' + e.message);
    } finally {
        if (saveBtn) saveBtn.disabled = false;
    }
}
function monthFilterParams() {
    const params = new URLSearchParams();
    if (currentReportFilters().has('geo') && state.filters.geo) params.set('geo', state.filters.geo);
    if (currentReportFilters().has('bm_id') && state.filters.bm_id) params.set('bm_id', state.filters.bm_id);
    if (currentReportFilters().has('account_id') && exactAccountFilterValue()) params.set('account_id', exactAccountFilterValue());
    return params;
}
async function loadMonthData() {
    document.getElementById('monthTbl').innerHTML = SPIN;
    try {
        const period = state.tabs.month.period || '';
        const params = monthFilterParams();
        if (period) params.set('period', period);
        const res = await fetch('/api/monthly.php?' + params);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error||'API error');
        monthRows = json.data||[];
        const metaEl = document.getElementById('monthPeriodMeta');
        if (metaEl) {
            const from = json.meta?.date_from || '';
            const to = json.meta?.date_to || '';
            metaEl.textContent = from && to ? `${from} - ${to}` : '';
        }
        renderMonthTable();
    } catch(e) { document.getElementById('monthTbl').innerHTML=`<div class="tbl-empty">Error: ${esc(e.message)}</div>`; }
}
async function loadMonthPeriods() {
    const sel = document.getElementById('monthPeriodSel');
    if (!sel) return loadMonthData();
    const listParams = monthFilterParams();
    listParams.set('list', '1');
    const key = listParams.toString();
    if (monthPeriodsKey !== key) {
        monthPeriods = [];
        monthPeriodsKey = key;
    }
    if (!monthPeriods.length) {
        try {
            const res = await fetch('/api/monthly.php?' + listParams);
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'API error');
            monthPeriods = json.data || [];
        } catch (e) {
            sel.innerHTML = `<option value="">Error loading months</option>`;
            document.getElementById('monthTbl').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
            return;
        }
    }
    const keys = new Set(monthPeriods.map(x => String(x.month_key || '')));
    let selected = state.tabs.month.period || '';
    if (!selected || !keys.has(selected)) selected = monthPeriods[0]?.month_key || '';
    state.tabs.month.period = selected;
    sel.innerHTML = monthPeriods.map(m => {
        const key = String(m.month_key || '');
        const label = m.month_label || key;
        const stat = m.spend > 0 || m.revenue > 0 ? ` - ${f$(m.spend)} / ${f$(m.revenue)}` : '';
        return `<option value="${escAttr(key)}"${key===selected?' selected':''}>${esc(label + stat)}</option>`;
    }).join('');
    sel.value = selected;
    const metaEl = document.getElementById('monthPeriodMeta');
    const active = monthPeriods.find(m => String(m.month_key || '') === String(selected));
    if (metaEl) metaEl.textContent = active ? `${active.date_from} - ${active.date_to}` : '';
    return loadMonthData();
}
function mth(label, col, align='right') {
    const ts = state.tabs.month;
    const active=ts.sortCol===col, dir=active?ts.sortDir:'';
    return `<th><div class="thi ${align==='left'?'left':''}" onclick="sortMonthBy('${col}')">
        ${align==='left'?label:''}<span class="sort-ico ${dir}">${SORT_ICO}</span>${align!=='left'?label:''}
    </div></th>`;
}
function setMonthPeriod(period) {
    state.tabs.month.period = period;
    pushURL();
    loadMonthData();
}

function sortMonthBy(col) {
    const ts=state.tabs.month;
    ts.sortDir=ts.sortCol===col?(ts.sortDir==='desc'?'asc':'desc'):(col==='day'?'desc':'desc');
    ts.sortCol=col; pushURL({replace:true}); renderMonthTable();
}
function renderMonthTable() {
    if (!monthRows.length) { document.getElementById('monthTbl').innerHTML='<div class="tbl-empty">No data</div>'; return; }
    const ts = state.tabs.month;
    const sorted = [...monthRows].sort((a,b) => {
        const va=ts.sortCol === 'r2d' ? r2dValue(a) : (a[ts.sortCol]??0), vb=ts.sortCol === 'r2d' ? r2dValue(b) : (b[ts.sortCol]??0);
        return ts.sortDir==='asc'?(va>vb?1:va<vb?-1:0):(vb>va?1:vb<va?-1:0);
    });
    const T = monthRows.reduce((acc,x)=>({impressions:acc.impressions+(x.impressions||0),clicks:acc.clicks+(x.clicks||0),leads:acc.leads+(x.leads||0),regs:acc.regs+(x.regs||0),deps:acc.deps+(x.deps||0),spend:acc.spend+(x.spend||0),revenue:acc.revenue+(x.revenue||0)}),{impressions:0,clicks:0,leads:0,regs:0,deps:0,spend:0,revenue:0});
    T.profit=T.revenue-T.spend; T.roi=T.spend>0?T.profit/T.spend*100:0;
    T.ctr=T.impressions>0?T.clicks/T.impressions*100:0; T.cpm=T.impressions>0?T.spend/T.impressions*1000:0;
    T.cpc=T.clicks>0?T.spend/T.clicks:0;
    T.cpl=T.leads>0?T.spend/T.leads:0; T.cpr=T.regs>0?T.spend/T.regs:0; T.cpd=T.deps>0?T.spend/T.deps:0;
    const pC=T.profit>0?'color:var(--green)':T.profit<0?'color:var(--red)':'';

    let html=`<table><thead><tr>${mth('Date','day','left')}${mth('Impressions','impressions')}${mth('Clicks','clicks')}${mth('CPC','cpc')}${mth('Leads','leads')}${mth('CPL','cpl')}${mth('Regs','regs')}${mth('CPR','cpr')}${mth('Deps','deps')}${mth('CPD','cpd')}${mth('R2D','r2d')}${mth('Spend','spend')}${mth('Revenue','revenue')}${mth('Profit','profit')}${mth('ROI','roi')}${mth('CTR','ctr')}${mth('CPM','cpm')}</tr></thead><tbody>`;
    for (const row of sorted) {
        const d=new Date(row.day+'T00:00:00'), dn=d.getDay(), isWE=dn===0||dn===6;
        const dayLabel=row.day+` <span style="color:var(--text3);font-size:10px">${DAY_NAMES[dn]}</span>`;
        const pr=row.revenue-row.spend, prC=pr>0?'color:var(--green)':pr<0?'color:var(--red)':'';
        html+=`<tr${isWE?' style="background:var(--bg)"':''}>
            <td><div class="tdi left" style="font-weight:500">${dayLabel}</div></td>
            <td><div class="tdi"><span class="num">${fN(row.impressions)}</span></div></td>
            <td><div class="tdi"><span class="num">${fN(row.clicks)}</span></div></td>
            <td><div class="tdi"><span class="num">${row.cpc>0?f$(row.cpc):row.clicks>0?f$(row.spend/row.clicks):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${row.leads>0?fN(row.leads):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${row.leads>0?f$(row.cpl):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${row.regs>0?fN(row.regs):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${row.regs>0?f$(row.cpr):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${row.deps>0?fN(row.deps):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${row.deps>0?f$(row.cpd):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${fR2D(row.regs,row.deps)}</span></div></td>
            <td><div class="tdi"><span class="num spend-val">${f$(row.spend)}</span></div></td>
            <td><div class="tdi"><span class="num">${row.revenue>0?f$(row.revenue):'-'}</span></div></td>
            <td><div class="tdi"><span class="num" style="${prC}">${row.revenue>0||row.spend>0?f$(pr):'-'}</span></div></td>
            <td><div class="tdi"><span class="num" style="${prC}">${row.spend>0?row.roi.toFixed(1)+'%':'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${fP(row.ctr)}</span></div></td>
            <td><div class="tdi"><span class="num">${f$(row.cpm)}</span></div></td>
        </tr>`;
    }
    html+=`</tbody><tfoot><tr class="total-row">
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${monthRows.length} days</div></td>
        <td><div class="tdi"><span class="num">${fN(T.impressions)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(T.clicks)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpc>0?f$(T.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?fN(T.leads):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?f$(T.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?fN(T.regs):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?f$(T.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?fN(T.deps):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?f$(T.cpd):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(T.regs,T.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${f$(T.spend)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.revenue>0?f$(T.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pC}">${T.revenue>0||T.spend>0?f$(T.profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pC}">${T.spend>0?T.roi.toFixed(1)+'%':'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fP(T.ctr)}</span></div></td>
        <td><div class="tdi"><span class="num">${f$(T.cpm)}</span></div></td>
    </tr></tfoot></table>`;
    document.getElementById('monthTbl').innerHTML = html;
}

// -- CREO VIEW ------------------------------------------------
async function loadCreoData() {
    document.getElementById('creoTbl').innerHTML = SPIN;
    try {
        const params = buildAPIParams({level:'ad'});
        const [adsRes, totalsRes, rankMap] = await Promise.all([
            fetch('/api/campaigns.php?'+params),
            fetch('/api/totals.php?'+(() => { const p = new URLSearchParams({range:state.range}); if (currentReportFilters().has('bm_id') && state.filters.bm_id) p.set('bm_id', state.filters.bm_id); return p; })()),
            fetchCreativeRankMap(),
        ]);
        const adsText = await adsRes.text();
        const totalsText = await totalsRes.text();
        let adsJson = null;
        let totalsJson = null;
        try {
            adsJson = adsText ? JSON.parse(adsText) : null;
        } catch (parseErr) {
            throw new Error((adsText || '').trim().slice(0, 220) || `HTTP ${adsRes.status} | Empty API response`);
        }
        try {
            totalsJson = totalsText ? JSON.parse(totalsText) : null;
        } catch (parseErr) {
            throw new Error((totalsText || '').trim().slice(0, 220) || `HTTP ${totalsRes.status} | Empty API response`);
        }
        if (!adsRes.ok) {
            throw new Error(`HTTP ${adsRes.status}${adsText ? ' | ' + adsText.trim().slice(0, 220) : ''}`);
        }
        if (!totalsRes.ok) {
            throw new Error(`HTTP ${totalsRes.status}${totalsText ? ' | ' + totalsText.trim().slice(0, 220) : ''}`);
        }
        if (!adsJson || typeof adsJson !== 'object') {
            throw new Error(`HTTP ${adsRes.status} | Empty API response body`);
        }
        if (!totalsJson || typeof totalsJson !== 'object') {
            throw new Error(`HTTP ${totalsRes.status} | Empty API response body`);
        }
        if (!adsJson.ok) throw new Error(adsJson.error||'API error');
        const monthTotals=totalsJson.data||{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0};
        const allAds = applyGeoFilter(adsJson.data || []);
        const adsTotals=allAds.reduce((acc,r)=>{const s=r.stats||{};for(const k of['spend','delta','impressions','clicks','leads','regs','deps','revenue'])acc[k]+=s[k]||0;return acc;},{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0});
        const orphan={};for(const k of['spend','delta','impressions','clicks','leads','regs','deps','revenue'])orphan[k]=Math.max(0,Math.round((monthTotals[k]-adsTotals[k])*10000)/10000);
        const hasOrphan=!state.filters.geo&&(orphan.revenue>0||orphan.spend>0||orphan.leads>0);
        const byName=groupCreoAdsForRank(adsJson.data || []);
        if (hasOrphan) byName['XX||(no structure)']={geo:'XX',name:'(no structure)',ads_active:0,ads_total:0,stats:{...orphan},_isOrphan:true};
        creoRows=Object.entries(byName).map(([key,g])=>{
            finalizeCreoStats(g.stats);
            const rec = rankMap[key] || null;
            g.rank = rec ? rec.rank : null;
            g.rank_score = rec ? rec.score : null;
            g.rank_share = rec ? rec.share : null;
            return g;
        });
        await ensureCreativePreviewMap();
        renderCreoTable();
    } catch(e) { document.getElementById('creoTbl').innerHTML=`<div class="tbl-empty">Error: ${esc(e.message)}</div>`; }
}
function emptyCreoStats() {
    return {spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0};
}
function addCreoStats(dst, src={}) {
    for (const k of ['spend','delta','impressions','clicks','leads','regs','deps','revenue']) dst[k] += Number(src[k] || 0);
}
function finalizeCreoStats(s) {
    s.profit=s.revenue-s.spend;
    s.roi=s.spend>0?s.profit/s.spend*100:0;
    s.ctr=s.impressions>0?s.clicks/s.impressions*100:0;
    s.cpm=s.impressions>0?s.spend/s.impressions*1000:0;
    s.cpc=s.clicks>0?s.spend/s.clicks:0;
    s.cpl=s.leads>0?s.spend/s.leads:0;
    s.cpr=s.regs>0?s.spend/s.regs:0;
    s.cpd=s.deps>0?s.spend/s.deps:0;
    return s;
}
function groupCreoAdsForRank(ads, opts={}) {
    const byName = {};
    const sourceAds = opts.ignoreGeo ? (ads || []) : applyGeoFilter(ads || []);
    for (const ad of sourceAds) {
        if (normalizedStatus(ad?.status) === 'DELETED') continue;
        if (normalizedStatus(ad?.adset_status) === 'DELETED') continue;
        if (normalizedStatus(ad?.campaign_status) === 'DELETED') continue;
        const name = ad.name || '(no name)';
        if (currentReportFilters().has('ad_name') && state.filters.ad_name && name !== state.filters.ad_name) continue;
        const geo = extractGeo(ad.campaign_name || ad.name || '');
        const key = `${geo}||${name}`;
        if (!byName[key]) byName[key] = {geo, name, ads_active:0, ads_total:0, stats:emptyCreoStats()};
        const g = byName[key];
        g.ads_total++;
        if (isReallyActive(ad)) g.ads_active++;
        addCreoStats(g.stats, ad.stats || {});
    }
    return byName;
}
function hasCreoRankStats(s) {
    return ['spend','clicks','leads','regs','deps','revenue'].some(k => Number(s?.[k] || 0) > 0);
}
function creoRoi(s) {
    const spend = Number(s?.spend || 0), revenue = Number(s?.revenue || 0);
    return spend > 0 ? (revenue - spend) / spend * 100 : (revenue > 0 ? 100 : null);
}
function creoCpr(s) {
    const regs = Number(s?.regs || 0);
    return regs > 0 ? Number(s?.spend || 0) / regs : null;
}
function creoRegRate(s) {
    const clicks = Number(s?.clicks || 0);
    return clicks > 0 ? Number(s?.regs || 0) / clicks : null;
}
function clampNum(v, min, max) {
    return Math.max(min, Math.min(max, v));
}
function ratioFactor(base, value, inverse=true) {
    if (!base || !value || base <= 0 || value <= 0) return 1;
    return clampNum(inverse ? base / value : value / base, 0.25, 2);
}
function calcCreoGeoRanks(stats3, stats30) {
    const keys = [...new Set([...Object.keys(stats3), ...Object.keys(stats30)])];
    const byGeo = {};
    for (const key of keys) {
        const base = stats3[key] || stats30[key];
        const s3 = stats3[key]?.stats || emptyCreoStats();
        const s30 = stats30[key]?.stats || emptyCreoStats();
        if (!base || (!hasCreoRankStats(s3) && !hasCreoRankStats(s30))) continue;
        const geo = base.geo || 'XX';
        if (!byGeo[geo]) byGeo[geo] = [];
        byGeo[geo].push({key, geo, name:base.name, s3, s30});
    }
    const result = {};
    for (const [geo, items] of Object.entries(byGeo)) {
        const total3 = emptyCreoStats(), total30 = emptyCreoStats();
        items.forEach(x => { addCreoStats(total3, x.s3); addCreoStats(total30, x.s30); });
        finalizeCreoStats(total3); finalizeCreoStats(total30);
        const geoRoi3 = creoRoi(total3) ?? 0;
        const geoRoi30 = creoRoi(total30) ?? geoRoi3;
        const geoAvgRoi = geoRoi3 * 0.8 + geoRoi30 * 0.2;
        const geoCpr3 = creoCpr(total3), geoCpr30 = creoCpr(total30);
        const geoReg3 = creoRegRate(total3), geoReg30 = creoRegRate(total30);
        const rows = {};
        for (const item of items) {
            const s3 = finalizeCreoStats({...item.s3});
            const s30 = finalizeCreoStats({...item.s30});
            const deps3 = Number(s3.deps || 0), deps30 = Number(s30.deps || 0);
            const conf3 = Math.min(1, deps3 / 3), conf30 = Math.min(1, deps30 / 10);
            const roi3 = creoRoi(s3), roi30 = creoRoi(s30);
            const confWeight = 0.8 * conf3 + 0.2 * conf30;
            const roiPart = (roi3 ?? 0) * 0.8 * conf3 + (roi30 ?? 0) * 0.2 * conf30;
            const roiBlend = roiPart + geoAvgRoi * Math.max(0, 1 - confWeight);
            const profitBlend = Math.max(0, Number(s3.profit || 0) * 0.7 + Number(s30.profit || 0) * 0.3);
            const cprFactor = 0.7 * ratioFactor(geoCpr3, creoCpr(s3), true) + 0.3 * ratioFactor(geoCpr30, creoCpr(s30), true);
            const regFactor = 0.7 * ratioFactor(geoReg3, creoRegRate(s3), false) + 0.3 * ratioFactor(geoReg30, creoRegRate(s30), false);
            const regsBlend = 0.7 * Number(s3.regs || 0) + 0.3 * Number(s30.regs || 0);
            const profitSignal = Number(s3.profit || 0) * 0.8 + Number(s30.profit || 0) * 0.2;
            const totalDeps = deps3 + deps30;
            const hasPositiveEconomy = totalDeps > 0 && (profitSignal > 0 || roiBlend > 0);
            const rankBucket = hasPositiveEconomy ? 0 : (totalDeps > 0 ? 1 : (regsBlend > 0 ? 2 : 3));
            const spendPenalty = Math.max(0, Number(s3.spend || 0) * 0.8 + Number(s30.spend || 0) * 0.2);
            const roiScore = Math.max(0, (roiBlend + 30) / 130);
            const roiWeight = 0.55 + 0.75 * roiScore;
            rows[item.key] = {
                key:item.key,
                rank_bucket: rankBucket,
                score: Math.max(0, roiBlend + 30) * (1 + Math.log(1 + profitBlend) / 10) * cprFactor,
                test_score: (0.30 * cprFactor + 0.20 * regFactor + 0.15 * Math.log(1 + Math.max(0, regsBlend)) + 0.10 * (1 / (1 + spendPenalty)) + 0.25 * roiScore) * roiWeight,
            };
        }
        Object.values(rows)
            .sort((a,b)=>Number(a.rank_bucket || 0)-Number(b.rank_bucket || 0) || Number(b.score || b.test_score || 0)-Number(a.score || a.test_score || 0))
            .forEach((row, idx)=>{ result[row.key] = {rank: idx + 1, share: null, score: Number(row.score || row.test_score || 0)}; });
    }
    return result;
}
function hasSingleGeoFilter() {
    return (state.filters.geo || '').split(',').map(g=>g.trim()).filter(Boolean).length === 1;
}
function creoTh(label, col, align='right') {
    const ts=state.tabs.creo, active=ts.sortCol===col, dir=active?ts.sortDir:'';
    return `<th><div class="thi ${align==='left'?'left':''}" onclick="sortCreoBy('${col}')">
        ${align==='left'?label:''}<span class="sort-ico ${dir}">${SORT_ICO}</span>${align!=='left'?label:''}</div></th>`;
}
function sortCreoBy(col) {
    const ts=state.tabs.creo;
    ts.sortDir=ts.sortCol===col?(ts.sortDir==='desc'?'asc':'desc'):'desc'; ts.sortCol=col;
    document.getElementById('creoTbl').style.opacity='0.4';
    requestAnimationFrame(()=>requestAnimationFrame(()=>{renderCreoTable();document.getElementById('creoTbl').style.opacity='';}));
}
async function pauseCreativeAds(name) {
    const creativeName = String(name || '').trim();
    if (!creativeName || creativeName === '(no structure)') return;
    const row = creoRows.find(x => String(x.name || '') === creativeName);
    const activeAds = Number(row?.ads_active || 0);
    if (activeAds <= 0) {
        alert('No active ads found for this creative in the current view.');
        return;
    }
    if (!confirm(`Pause ${activeAds} active ad${activeAds === 1 ? '' : 's'} for ${creativeName}?`)) return;
    try {
        const body = {
            action: 'pause_creative_ads',
            creative_name: creativeName,
            geo: state.filters.geo || '',
            bm_id: state.filters.bm_id || '',
            account_id: exactAccountFilterValue() || '',
            campaign_id: state.filters.campaign_id || '',
            adset_id: state.filters.adset_id || ''
        };
        const res = await fetch('/api/tasks.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(body)
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Task API error');
        const data = json.data || {};
        alert(`Queued ${data.count || 0} ad pause task${Number(data.count || 0) === 1 ? '' : 's'}${data.skipped_existing ? `, skipped ${data.skipped_existing} existing` : ''}.`);
        loadTasks();
    } catch (e) {
        alert('Task error: ' + e.message);
    }
}
function renderCreoTable() {
    const ts=state.tabs.creo;
    let r=[...creoRows];
    const q=(ts.search||'').toLowerCase();
    if (q) r=r.filter(x=>x.name.toLowerCase().includes(q));
    r.sort((a,b)=>{const col=ts.sortCol;const va=col.startsWith('stats.')?metricValue(a.stats,col.slice(6)):col==='ads_active'?a.ads_active:col==='ads_total'?a.ads_total:col==='rank'?(a.rank??999999):(a[col]??'');const vb=col.startsWith('stats.')?metricValue(b.stats,col.slice(6)):col==='ads_active'?b.ads_active:col==='ads_total'?b.ads_total:col==='rank'?(b.rank??999999):(b[col]??'');const primary=typeof va==='string'?(ts.sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va)):(ts.sortDir==='asc'?va-vb:vb-va);return compareWithActive(a,b,primary);});
    if (!r.length) { document.getElementById('creoTbl').innerHTML='<div class="tbl-empty">No data</div>'; return; }
    const T=r.reduce((a,x)=>({spend:a.spend+(x.stats.spend||0),delta:a.delta+(x.stats.delta||0),impressions:a.impressions+(x.stats.impressions||0),clicks:a.clicks+(x.stats.clicks||0),leads:a.leads+(x.stats.leads||0),regs:a.regs+(x.stats.regs||0),deps:a.deps+(x.stats.deps||0),revenue:a.revenue+(x.stats.revenue||0),ads_active:a.ads_active+x.ads_active,ads_total:a.ads_total+x.ads_total}),{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0,ads_active:0,ads_total:0});
    T.profit=T.revenue-T.spend;T.roi=T.spend>0?T.profit/T.spend*100:0;T.ctr=T.impressions>0?T.clicks/T.impressions*100:0;T.cpm=T.impressions>0?T.spend/T.impressions*1000:0;T.cpc=T.clicks>0?T.spend/T.clicks:0;T.cpl=T.leads>0?T.spend/T.leads:0;T.cpr=T.regs>0?T.spend/T.regs:0;T.cpd=T.deps>0?T.spend/T.deps:0;
    let html=`<table><thead><tr><th style="width:44px"><div class="thi center">On</div></th>${creoTh('Creo name','name','left')}${creoTh('Rank','rank')}${creoTh('Active ads','ads_active')}${creoTh('Total ads','ads_total')}${creoTh('Impressions','stats.impressions')}${creoTh('Clicks','stats.clicks')}${creoTh('CPC','stats.cpc')}${creoTh('Leads','stats.leads')}${creoTh('CPL','stats.cpl')}${creoTh('Regs','stats.regs')}${creoTh('CPR','stats.cpr')}${creoTh('Deps','stats.deps')}${creoTh('CPD','stats.cpd')}${creoTh('R2D','stats.r2d')}${creoTh('Spend','stats.spend')}${creoTh('Delta','stats.delta')}${creoTh('Revenue','stats.revenue')}${creoTh('Profit','stats.profit')}${creoTh('ROI','stats.roi')}${creoTh('CTR','stats.ctr')}${creoTh('CPM','stats.cpm')}</tr></thead><tbody>`;
    r.forEach(row=>{const s=row.stats,pC=s.profit>0?'color:var(--green)':s.profit<0?'color:var(--red)':'';
        const rankLabel = row.rank ? `${hasSingleGeoFilter() ? '' : esc(row.geo)+' '}#${row.rank}` : '-';
        const canPause = !row._isOrphan && Number(row.ads_active || 0) > 0;
        html+=`<tr><td><div class="tdi center"><button class="tog ${canPause?'on':'off'}" ${canPause ? `onclick="event.stopPropagation();pauseCreativeAds('${escAttr(row.name)}')"` : 'disabled'} title="${escAttr(canPause ? 'Pause active ads for this creative' : 'No active ads to pause')}"></button></div></td><td><div class="tdi left"><div class="nc"><div class="nc-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div><div class="nc-texts"><div class="nc-name${creativePreviewClass(row.name)}" ${creativePreviewAttrs(row.name)} onclick="drillDownCreoName('${esc(row.name)}')" style="cursor:pointer">${esc(row.name)}</div></div></div></div></td>
        <td><div class="tdi"><span class="num">${rankLabel}</span></div></td>
        <td><div class="tdi"><span class="num" style="color:${row.ads_active>0?'var(--green)':'var(--text3)'}">${row.ads_active}</span></div></td>
        <td><div class="tdi"><span class="num">${row.ads_total}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(s.impressions)}</span></div></td><td><div class="tdi"><span class="num">${fN(s.clicks)}</span></div></td>
        <td><div class="tdi"><span class="num">${s.cpc>0?f$(s.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${s.leads>0?fN(s.leads):'-'}</span></div></td><td><div class="tdi"><span class="num">${s.leads>0?f$(s.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${s.regs>0?fN(s.regs):'-'}</span></div></td><td><div class="tdi"><span class="num">${s.regs>0?f$(s.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${s.deps>0?fN(s.deps):'-'}</span></div></td><td><div class="tdi"><span class="num">${s.deps>0?f$(s.cpd):'-'}</span></div></td><td><div class="tdi"><span class="num">${fR2D(s.regs,s.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${f$(s.spend)}</span></div></td>
        <td><div class="tdi"><span class="num">${f$(s.delta||0)}</span></div></td>
        <td><div class="tdi"><span class="num">${s.revenue>0?f$(s.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pC}">${s.revenue>0||s.spend>0?f$(s.profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pC}">${s.spend>0?s.roi.toFixed(1)+'%':'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fP(s.ctr)}</span></div></td><td><div class="tdi"><span class="num">${f$(s.cpm)}</span></div></td></tr>`;
    });
    const pC=T.profit>0?'color:var(--green)':T.profit<0?'color:var(--red)':'';
    html+=`</tbody><tfoot><tr class="total-row"><td></td><td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${r.length} creatives</div></td><td><div class="tdi"><span class="num">-</span></div></td><td><div class="tdi"><span class="num" style="color:var(--green)">${T.ads_active}</span></div></td><td><div class="tdi"><span class="num">${T.ads_total}</span></div></td><td><div class="tdi"><span class="num">${fN(T.impressions)}</span></div></td><td><div class="tdi"><span class="num">${fN(T.clicks)}</span></div></td><td><div class="tdi"><span class="num">${T.cpc>0?f$(T.cpc):'-'}</span></div></td><td><div class="tdi"><span class="num">${T.leads>0?fN(T.leads):'-'}</span></div></td><td><div class="tdi"><span class="num">${T.leads>0?f$(T.cpl):'-'}</span></div></td><td><div class="tdi"><span class="num">${T.regs>0?fN(T.regs):'-'}</span></div></td><td><div class="tdi"><span class="num">${T.regs>0?f$(T.cpr):'-'}</span></div></td><td><div class="tdi"><span class="num">${T.deps>0?fN(T.deps):'-'}</span></div></td><td><div class="tdi"><span class="num">${T.deps>0?f$(T.cpd):'-'}</span></div></td><td><div class="tdi"><span class="num">${fR2D(T.regs,T.deps)}</span></div></td><td><div class="tdi"><span class="num spend-val">${f$(T.spend)}</span></div></td><td><div class="tdi"><span class="num">${f$(T.delta||0)}</span></div></td><td><div class="tdi"><span class="num">${T.revenue>0?f$(T.revenue):'-'}</span></div></td><td><div class="tdi"><span class="num" style="${pC}">${T.revenue>0||T.spend>0?f$(T.profit):'-'}</span></div></td><td><div class="tdi"><span class="num" style="${pC}">${T.spend>0?T.roi.toFixed(1)+'%':'-'}</span></div></td><td><div class="tdi"><span class="num">${fP(T.ctr)}</span></div></td><td><div class="tdi"><span class="num">${f$(T.cpm)}</span></div></td></tr></tfoot></table>`;
    document.getElementById('creoTbl').innerHTML = html;
}

// -- CREO VIEW ------------------------------------------------
async function loadTopCreoData() {
    document.getElementById('topcreoTbl').innerHTML = SPIN;
    try {
        const params = new URLSearchParams({level:'ad', range:'30d'});
        if (currentReportFilters().has('bm_id') && state.filters.bm_id)           params.set('bm_id',      state.filters.bm_id);
        if (currentReportFilters().has('account_id') && exactAccountFilterValue()) params.set('account_id', exactAccountFilterValue());
        const [res, rankMap] = await Promise.all([
            fetch('/api/campaigns.php?'+params),
            fetchCreativeRankMap(),
        ]);
        const json = await readApiJson(res, 'campaigns');
        if (!json.ok) throw new Error(json.error||'API error');

        // Group by geo + creative name
        const byGeoName = {};
        for (const ad of (json.data||[])) {
            if (normalizedStatus(ad?.status) === 'DELETED') continue;
            if (normalizedStatus(ad?.adset_status) === 'DELETED') continue;
            if (normalizedStatus(ad?.campaign_status) === 'DELETED') continue;
            const geo  = extractGeo(ad.campaign_name || ad.name || '');
            const name = ad.name || '(no name)';
            const key  = geo + '||' + name;
            if (!byGeoName[key]) byGeoName[key] = {geo, name, ads_active:0, ads_total:0, stats:{spend:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0}};
            const g = byGeoName[key]; g.ads_total++;
            if (isReallyActive(ad)) g.ads_active++;
            const s = ad.stats||{};
            for (const k of ['spend','impressions','clicks','leads','regs','deps','revenue']) g.stats[k]+=(s[k]||0);
        }

        // Add derived metrics
        const rows = Object.values(byGeoName).map(g => {
            const s = g.stats;
            s.profit = s.revenue - s.spend;
            s.roi    = s.spend>0 ? s.profit/s.spend*100 : 0;
            s.ctr    = s.impressions>0 ? s.clicks/s.impressions*100 : 0;
            s.cpm    = s.impressions>0 ? s.spend/s.impressions*1000 : 0;
            s.cpc    = s.clicks>0 ? s.spend/s.clicks : 0;
            s.cpl    = s.leads>0  ? s.spend/s.leads  : 0;
            s.cpr    = s.regs>0   ? s.spend/s.regs   : 0;
            s.cpd    = s.deps>0   ? s.spend/s.deps   : 0;
            const key = `${g.geo || 'XX'}||${g.name || ''}`;
            const rec = rankMap[key] || null;
            g.rank = rec ? rec.rank : null;
            g.rank_score = rec ? Number(rec.score || 0) : 0;
            return g;
        });

        // Top ranked creatives for each geo using the canonical creative rank.
        const byGeo = {};
        for (const r of rows) {
            if (!byGeo[r.geo]) byGeo[r.geo] = [];
            byGeo[r.geo].push(r);
        }
        for (const geo of Object.keys(byGeo)) {
            byGeo[geo].sort((a,b) => {
                const rankA = Number(a.rank ?? 999999);
                const rankB = Number(b.rank ?? 999999);
                const primary = rankA - rankB
                    || Number(b.rank_score || 0) - Number(a.rank_score || 0)
                    || Number(b.stats?.profit || 0) - Number(a.stats?.profit || 0);
                return compareWithActive(a, b, primary);
            });
            byGeo[geo] = byGeo[geo].slice(0, 10);
        }

        // Sort GEOs by their strongest ranked creative first.
        const geosSorted = Object.keys(byGeo).sort((a,b) => {
            const topA = byGeo[a][0] || null;
            const topB = byGeo[b][0] || null;
            const rankA = Number(topA?.rank ?? 999999);
            const rankB = Number(topB?.rank ?? 999999);
            const primary = rankA - rankB
                || Number(topB?.rank_score || 0) - Number(topA?.rank_score || 0);
            if (primary) return primary;
            const scoreA = byGeo[a].reduce((s,r)=>s+Number(r.rank_score || 0),0);
            const scoreB = byGeo[b].reduce((s,r)=>s+Number(r.rank_score || 0),0);
            if (scoreA !== scoreB) return scoreB - scoreA;
            return byGeo[b].reduce((s,r)=>s+Number(r.stats?.profit || 0),0) - byGeo[a].reduce((s,r)=>s+Number(r.stats?.profit || 0),0);
        });

        await ensureCreativePreviewMap();
        renderTopCreoTable(byGeo, geosSorted);
    } catch(e) {
        document.getElementById('topcreoTbl').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function renderTopCreoTable(byGeo, geosSorted) {
    if (!geosSorted.length) {
        document.getElementById('topcreoTbl').innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }

    const hdr = `<thead><tr>
        <th><div class="thi left">Creo name</div></th>
        <th><div class="thi">Rank</div></th>
        ${creoTh('Active','ads_active')}${creoTh('Total','ads_total')}
        ${creoTh('Impressions','stats.impressions')}${creoTh('Clicks','stats.clicks')}${creoTh('CPC','stats.cpc')}
        ${creoTh('Leads','stats.leads')}${creoTh('CPL','stats.cpl')}
        ${creoTh('Regs','stats.regs')}${creoTh('CPR','stats.cpr')}
        ${creoTh('Deps','stats.deps')}${creoTh('CPD','stats.cpd')}${creoTh('R2D','stats.r2d')}
        ${creoTh('Spend','stats.spend')}${creoTh('Revenue','stats.revenue')}
        ${creoTh('Profit','stats.profit')}${creoTh('ROI','stats.roi')}
        ${creoTh('CTR','stats.ctr')}${creoTh('CPM','stats.cpm')}
    </tr></thead>`;

    _topCreoFlat = [];
    let html = '';
    for (const geo of geosSorted) {
        const rows = byGeo[geo];
        const geoProfit = rows.reduce((s,r)=>s+r.stats.profit,0);
        const pC = geoProfit>0?'color:var(--green)':geoProfit<0?'color:var(--red)':'';

        html += `<div class="topcreo-geo-header"><span>${esc(geo)}</span>Top ${rows.length} by Rank  |  Profit: <b style="${pC}">${f$(geoProfit)}</b></div>`;
        html += `<table>${hdr}<tbody>`;

        for (const row of rows) {
            const s = row.stats;
            const pC2 = s.profit>0?'color:var(--green)':s.profit<0?'color:var(--red)':'';
            const _tci = _topCreoFlat.length; _topCreoFlat.push(row);
            html += `<tr>
                <td><div class="tdi left"><div class="nc"><div class="nc-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div><div class="nc-texts"><div class="nc-name${creativePreviewClass(row.name)}" ${creativePreviewAttrs(row.name)} onclick="drillDownCreoName('${esc(row.name)}')" style="cursor:pointer">${esc(row.name)}</div></div></div></div></td>
                <td><div class="tdi"><span class="num">${row.rank ? '#' + fN(row.rank) : '-'}</span></div></td>
                <td><div class="tdi"><span class="num" style="color:${row.ads_active>0?'var(--green)':'var(--text3)'}">${row.ads_active}</span></div></td>
                <td><div class="tdi"><span class="num">${row.ads_total}</span></div></td>
                <td><div class="tdi"><span class="num">${fN(s.impressions)}</span></div></td>
                <td><div class="tdi"><span class="num">${fN(s.clicks)}</span></div></td>
                <td><div class="tdi"><span class="num">${s.cpc>0?f$(s.cpc):'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${s.leads>0?fN(s.leads):'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${s.leads>0?f$(s.cpl):'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${s.regs>0?fN(s.regs):'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${s.regs>0?f$(s.cpr):'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${s.deps>0?fN(s.deps):'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${s.deps>0?f$(s.cpd):'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${fR2D(s.regs,s.deps)}</span></div></td>
                <td><div class="tdi"><span class="num spend-val">${f$(s.spend)}</span></div></td>
                <td><div class="tdi"><span class="num">${s.revenue>0?f$(s.revenue):'-'}</span></div></td>
                <td><div class="tdi"><span class="num" style="${pC2}">${s.revenue>0||s.spend>0?f$(s.profit):'-'}</span></div></td>
                <td><div class="tdi"><span class="num" style="${pC2}">${s.spend>0?s.roi.toFixed(1)+'%':'-'}</span></div></td>
                <td><div class="tdi"><span class="num">${fP(s.ctr)}</span></div></td>
                <td><div class="tdi"><span class="num">${f$(s.cpm)}</span></div></td>
            </tr>`;
        }
        html += `</tbody></table>`;
    }

    document.getElementById('topcreoTbl').innerHTML = html;
}
async function loadCreativeCalendarData() {
    const tbl = document.getElementById('creativeCalendarTbl');
    if (tbl) tbl.innerHTML = SPIN;
    try {
        const params = buildAPIParams();
        params.delete('range');
        const [res, rankMap] = await Promise.all([
            fetch('/api/creative_calendar.php?' + params.toString()),
            fetchCreativeRankMap(),
        ]);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        creativeCalendarMeta = json.data || {};
        creativeCalendarRows = (creativeCalendarMeta.rows || []).map(row => {
            const key = `${row.geo || 'XX'}||${row.creative_name || ''}`;
            return {...row, rank: rankMap[key]?.rank || null};
        });
        await ensureCreativePreviewMap();
        renderCreativeCalendarTable();
    } catch (e) {
        if (tbl) tbl.innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}
function creativeCalendarMetricCols(r) {
    const profit = Number(r.profit || 0);
    const pc = profit > 0 ? 'color:var(--green)' : profit < 0 ? 'color:var(--red)' : '';
    return `
        <td><div class="tdi"><span class="num">${fN(r.ads_count || 0)}</span></div></td>
        <td><div class="tdi"><span class="num">${r.rank ? '#' + fN(r.rank) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.impressions>0?fN(r.impressions):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.clicks>0?fN(r.clicks):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpc>0?f$(r.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.leads>0?fN(r.leads):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpl>0?f$(r.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.regs>0?fN(r.regs):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpr>0?f$(r.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.deps>0?fN(r.deps):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpd>0?f$(r.cpd):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(r.regs,r.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${r.spend>0?f$(r.spend):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.revenue>0?f$(r.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pc}">${r.revenue>0||r.spend>0?f$(profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pc}">${r.spend>0?Number(r.roi || 0).toFixed(1)+'%':'-'}</span></div></td>`;
}
function renderCreativeCalendarTable() {
    const tbl = document.getElementById('creativeCalendarTbl');
    if (!tbl) return;
    let rows = [...creativeCalendarRows];
    if (!rows.length) {
        tbl.innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }
    const q = String(state.tabs.creative_calendar?.search || '').trim().toLowerCase();
    if (q) {
        rows = rows.filter(row => [
            row.geo, row.creative_name, row.first_seen, row.last_seen
        ].some(v => String(v || '').toLowerCase().includes(q)));
    }
    if (!rows.length) {
        tbl.innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }
    const T = rows.reduce((a,r)=>({
        ads_count:a.ads_count+Number(r.ads_count||0),
        impressions:a.impressions+Number(r.impressions||0),
        clicks:a.clicks+Number(r.clicks||0),
        leads:a.leads+Number(r.leads||0),
        regs:a.regs+Number(r.regs||0),
        deps:a.deps+Number(r.deps||0),
        spend:a.spend+Number(r.spend||0),
        revenue:a.revenue+Number(r.revenue||0),
    }), {ads_count:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,spend:0,revenue:0});
    T.profit = T.revenue - T.spend;
    T.cpc = T.clicks > 0 ? T.spend / T.clicks : 0;
    T.cpl = T.leads > 0 ? T.spend / T.leads : 0;
    T.cpr = T.regs > 0 ? T.spend / T.regs : 0;
    T.cpd = T.deps > 0 ? T.spend / T.deps : 0;
    T.roi = T.spend > 0 ? T.profit / T.spend * 100 : 0;
    const totalPc = T.profit > 0 ? 'color:var(--green)' : T.profit < 0 ? 'color:var(--red)' : '';
    let html = `<table><thead><tr>
        <th><div class="thi left">Date / GEO / creatives</div></th>
        <th><div class="thi">Ads</div></th>
        <th><div class="thi">Rank by GEO</div></th>
        <th><div class="thi">Impressions</div></th><th><div class="thi">Clicks</div></th><th><div class="thi">CPC</div></th>
        <th><div class="thi">Leads</div></th><th><div class="thi">CPL</div></th>
        <th><div class="thi">Regs</div></th><th><div class="thi">CPR</div></th>
        <th><div class="thi">Deps</div></th><th><div class="thi">CPD</div></th><th><div class="thi">R2D</div></th>
        <th><div class="thi">Spend</div></th><th><div class="thi">Revenue</div></th>
        <th><div class="thi">Profit</div></th><th><div class="thi">ROI</div></th>
    </tr></thead><tbody>`;
    let curDate = '';
    for (const row of rows) {
        if (row.first_seen !== curDate) {
            curDate = row.first_seen;
            const dayCount = rows.filter(x => x.first_seen === curDate).length;
            html += `<tr class="creative-calendar-date"><td colspan="17"><div class="tdi left"><span>${esc(curDate)}</span>${fN(dayCount)} creatives</div></td></tr>`;
        }
        html += `<tr>
            <td><div class="tdi left"><div class="nc"><div class="nc-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div><div class="nc-texts"><div class="nc-name${creativePreviewClass(row.creative_name)}" ${creativePreviewAttrs(row.creative_name)} data-creative-name="${escAttr(row.creative_name || '')}" onclick="drillDownCreoName(this.dataset.creativeName)" style="cursor:pointer"><span class="dim">${esc(row.geo || '')}</span> ${esc(row.creative_name)}</div></div></div></div></td>
            ${creativeCalendarMetricCols(row)}
        </tr>`;
    }
    html += `</tbody><tfoot><tr class="total-row">
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${fN(rows.length)} creatives</div></td>
        <td><div class="tdi"><span class="num">${fN(T.ads_count)}</span></div></td>
        <td><div class="tdi"><span class="num">-</span></div></td>
        <td><div class="tdi"><span class="num">${T.impressions>0?fN(T.impressions):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.clicks>0?fN(T.clicks):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpc>0?f$(T.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?fN(T.leads):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpl>0?f$(T.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?fN(T.regs):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpr>0?f$(T.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?fN(T.deps):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpd>0?f$(T.cpd):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(T.regs,T.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${T.spend>0?f$(T.spend):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.revenue>0?f$(T.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${totalPc}">${T.revenue>0||T.spend>0?f$(T.profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${totalPc}">${T.spend>0?T.roi.toFixed(1)+'%':'-'}</span></div></td>
    </tr></tfoot></table>`;
    tbl.innerHTML = html;
}
async function loadCampsCalendarData() {
    const tbl = document.getElementById('campsCalendarTbl');
    if (tbl) tbl.innerHTML = SPIN;
    try {
        const params = buildAPIParams();
        params.delete('range');
        const res = await fetch('/api/camps_calendar.php?' + params.toString());
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        campsCalendarMeta = json.data || {};
        campsCalendarRows = campsCalendarMeta.rows || [];
        renderCampsCalendarTable();
    } catch (e) {
        if (tbl) tbl.innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}
function campsCalendarMetricCols(r) {
    const profit = Number(r.profit || 0);
    const pc = profit > 0 ? 'color:var(--green)' : profit < 0 ? 'color:var(--red)' : '';
    return `
        <td><div class="tdi"><span class="num">${fN(r.campaigns_count || 0)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(r.campaigns_active_count || 0)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(r.geos_count || 0)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(r.ads_count || 0)}</span></div></td>
        <td><div class="tdi"><span class="num">${r.impressions>0?fN(r.impressions):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.clicks>0?fN(r.clicks):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpc>0?f$(r.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.leads>0?fN(r.leads):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpl>0?f$(r.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.regs>0?fN(r.regs):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpr>0?f$(r.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.deps>0?fN(r.deps):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpd>0?f$(r.cpd):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(r.regs,r.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${r.spend>0?f$(r.spend):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.delta>0?f$(r.delta):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.revenue>0?f$(r.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pc}">${r.revenue>0||r.spend>0?f$(profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pc}">${r.spend>0?Number(r.roi || 0).toFixed(1)+'%':'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fP(r.ctr)}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpm>0?f$(r.cpm):'-'}</span></div></td>`;
}
function renderCampsCalendarTable() {
    const tbl = document.getElementById('campsCalendarTbl');
    if (!tbl) return;
    let rows = [...campsCalendarRows];
    if (!rows.length) {
        tbl.innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }
    const q = String(state.tabs.camps_calendar?.search || '').trim().toLowerCase();
    if (q) {
        rows = rows.filter(row => [
            row.launch_date, row.geos, row.campaigns_count, row.ads_count
        ].some(v => String(v || '').toLowerCase().includes(q)));
    }
    if (!rows.length) {
        tbl.innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }
    const T = rows.reduce((a,r)=>({
        campaigns_count:a.campaigns_count+Number(r.campaigns_count||0),
        campaigns_active_count:a.campaigns_active_count+Number(r.campaigns_active_count||0),
        ads_count:a.ads_count+Number(r.ads_count||0),
        impressions:a.impressions+Number(r.impressions||0),
        clicks:a.clicks+Number(r.clicks||0),
        leads:a.leads+Number(r.leads||0),
        regs:a.regs+Number(r.regs||0),
        deps:a.deps+Number(r.deps||0),
        spend:a.spend+Number(r.spend||0),
        delta:a.delta+Number(r.delta||0),
        revenue:a.revenue+Number(r.revenue||0),
    }), {campaigns_count:0,campaigns_active_count:0,ads_count:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,spend:0,delta:0,revenue:0});
    T.profit = T.revenue - T.spend;
    T.cpc = T.clicks > 0 ? T.spend / T.clicks : 0;
    T.cpl = T.leads > 0 ? T.spend / T.leads : 0;
    T.cpr = T.regs > 0 ? T.spend / T.regs : 0;
    T.cpd = T.deps > 0 ? T.spend / T.deps : 0;
    T.roi = T.spend > 0 ? T.profit / T.spend * 100 : 0;
    T.ctr = T.impressions > 0 ? T.clicks / T.impressions * 100 : 0;
    T.cpm = T.impressions > 0 ? T.spend / T.impressions * 1000 : 0;
    const totalPc = T.profit > 0 ? 'color:var(--green)' : T.profit < 0 ? 'color:var(--red)' : '';
    let html = `<table><thead><tr>
        <th><div class="thi left">Date</div></th>
        <th><div class="thi">Campaigns</div></th>
        <th><div class="thi">Active</div></th>
        <th><div class="thi">GEOs</div></th>
        <th><div class="thi">Ads</div></th>
        <th><div class="thi">Impressions</div></th><th><div class="thi">Clicks</div></th><th><div class="thi">CPC</div></th>
        <th><div class="thi">Leads</div></th><th><div class="thi">CPL</div></th>
        <th><div class="thi">Regs</div></th><th><div class="thi">CPR</div></th>
        <th><div class="thi">Deps</div></th><th><div class="thi">CPD</div></th><th><div class="thi">R2D</div></th>
        <th><div class="thi">Spend</div></th><th><div class="thi">Delta</div></th><th><div class="thi">Revenue</div></th>
        <th><div class="thi">Profit</div></th><th><div class="thi">ROI</div></th><th><div class="thi">CTR</div></th><th><div class="thi">CPM</div></th>
    </tr></thead><tbody>`;
    for (const row of rows) {
        html += `<tr>
            <td><div class="tdi left"><div class="nc"><div class="nc-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="nc-texts"><div class="nc-name" data-launch-date="${escAttr(row.launch_date || '')}" onclick="setReportFilter('launch_date',this.dataset.launchDate);setView('campaign')" style="cursor:pointer">${esc(row.launch_date || '-')}</div><div class="nc-sub">${esc(row.geos || 'All GEOs')}</div></div></div></div></td>
            ${campsCalendarMetricCols(row)}
        </tr>`;
    }
    html += `</tbody><tfoot><tr class="total-row">
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${fN(rows.length)} dates</div></td>
        <td><div class="tdi"><span class="num">${fN(T.campaigns_count)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(T.campaigns_active_count)}</span></div></td>
        <td><div class="tdi"><span class="num">-</span></div></td>
        <td><div class="tdi"><span class="num">${fN(T.ads_count)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.impressions>0?fN(T.impressions):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.clicks>0?fN(T.clicks):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpc>0?f$(T.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?fN(T.leads):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpl>0?f$(T.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?fN(T.regs):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpr>0?f$(T.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?fN(T.deps):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpd>0?f$(T.cpd):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(T.regs,T.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${T.spend>0?f$(T.spend):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.delta>0?f$(T.delta):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.revenue>0?f$(T.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${totalPc}">${T.revenue>0||T.spend>0?f$(T.profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${totalPc}">${T.spend>0?T.roi.toFixed(1)+'%':'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fP(T.ctr)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpm>0?f$(T.cpm):'-'}</span></div></td>
    </tr></tfoot></table>`;
    tbl.innerHTML = html;
}
function extractGeo(name) {
    const parts = String(name || '').split('_').map(v => String(v || '').trim().toUpperCase()).filter(Boolean);
    if (!parts.length) return 'XX';
    const reserved = new Set(['BC', 'CBO', 'ABO', 'SLOT', 'CRASH']);
    for (let i = 0; i < parts.length; i++) {
        const part = parts[i];
        if (/^[A-Z]{2}$/.test(part) && !reserved.has(part) && i >= 3) {
            return part;
        }
    }
    for (const part of parts) {
        if (/^[A-Z]{2}$/.test(part) && !reserved.has(part)) {
            return part;
        }
    }
    return 'XX';
}

function geoLabel(name) {
    const geo = extractGeo(name);
    return geo === 'XX' ? '' : geo;
}

// -- STREAMS VIEW ---------------------------------------------
async function loadStreamsData() {
    const tbl = document.getElementById('streamsTbl');
    const head = document.getElementById('streamsHead');
    if (tbl) tbl.innerHTML = SPIN;
    if (head) head.style.display = 'none';
    try {
        const params = appendOfferFilters(new URLSearchParams({range: state.range}));
        const wantedGeo = String(state.filters.geo || '').split(',')[0].trim().toUpperCase();
        params.delete('geo');
        const requestAllStreams = !String(state.tabs.streams.stream_id || '');
        params.set('stream_id', requestAllStreams ? 'all' : state.tabs.streams.stream_id);
        const res = await fetch('/api/streams.php?' + params.toString());
        const raw = await res.text();
        let json;
        try {
            json = JSON.parse(raw);
        } catch (parseErr) {
            throw new Error((raw || '').trim().slice(0, 300) || parseErr.message);
        }
        if (!json.ok) throw new Error(json.error || 'API error');
        if (json.data?.installed === false) {
            tbl.innerHTML = '<div class="tbl-empty">Offer insights table is not installed</div>';
            return;
        }
        streamRows = json.data?.streams || [];
        if (requestAllStreams && wantedGeo && streamRows.length) {
            const geoStream = streamRows.find(s => String(s.geo || '').toUpperCase() === wantedGeo);
            if (geoStream?.stream_id) {
                state.tabs.streams.stream_id = String(geoStream.stream_id);
                state.tabs.streams.sortCol = 'rank_score';
                state.tabs.streams.sortDir = 'desc';
                pushURL({replace:true});
                return loadStreamsData();
            }
        }
        if (!requestAllStreams && json.data?.selected_stream_id && String(state.tabs.streams.stream_id || '') !== String(json.data.selected_stream_id)) {
            state.tabs.streams.stream_id = String(json.data.selected_stream_id);
            pushURL({replace:true});
        }
        let selected = streamRows.find(s => String(s.stream_id) === String(state.tabs.streams.stream_id));
        if (state.tabs.streams.stream_id && !selected && streamRows.length) {
            state.tabs.streams.stream_id = String(streamRows[0].stream_id);
            pushURL({replace:true});
        }
        renderStreamsTabs();
        renderStreamsTable();
    } catch (e) {
        if (tbl) tbl.innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function selectedStream() {
    if (!state.tabs.streams.stream_id) return null;
    return streamRows.find(s => String(s.stream_id) === String(state.tabs.streams.stream_id)) || null;
}

function isAllStreamsSelected() {
    return !String(state.tabs.streams.stream_id || '');
}

function setStream(streamId) {
    const wasAllStreams = isAllStreamsSelected();
    state.tabs.streams.stream_id = streamId && streamId !== 'all' ? String(streamId) : '';
    if (state.tabs.streams.stream_id && wasAllStreams) {
        state.tabs.streams.sortCol = 'rank_score';
        state.tabs.streams.sortDir = 'desc';
    } else if (!state.tabs.streams.stream_id) {
        state.tabs.streams.sortCol = 'epc';
        state.tabs.streams.sortDir = 'desc';
    }
    state.filters.geo = null;
    _geoSel = new Set();
    pushURL();
    loadStreamsData();
}

function renderStreamsTabs() {
    const selectEl = document.getElementById('streamsSelect');
    const syncInfo = document.getElementById('streamsSyncInfo');
    if (!selectEl) return;
    if (!streamRows.length) {
        selectEl.innerHTML = '';
        selectEl.disabled = true;
        if (syncInfo) syncInfo.textContent = '';
        return;
    }
    const current = String(state.tabs.streams.stream_id || '');
    selectEl.disabled = false;
    selectEl.innerHTML = `<option value="all" ${current ? '' : 'selected'}>All streams</option>` + streamRows.map(s => {
        const active = String(s.stream_id) === current;
        const label = s.stream_name || `${s.geo || 'XX'} stream`;
        return `<option value="${escAttr(s.stream_id)}" ${active ? 'selected' : ''}>${esc(label)} (${esc(s.stream_id)})</option>`;
    }).join('');
    selectEl.value = current || 'all';
    if (syncInfo) {
        const synced = streamRows.reduce((a,s) => String(s.synced_at || '') > a ? String(s.synced_at || '') : a, '');
        syncInfo.textContent = synced ? `Sync: ${synced}` : '';
    }
}

function streamMetricSource(row) {
    return row?.totals && row?.offer_id === undefined ? row.totals : row;
}

function streamNum(row, key) {
    const src = streamMetricSource(row);
    return Number(src?.[key] ?? row?.[key] ?? 0);
}

function streamValue(row, key) {
    const revenue = streamNum(row, 'revenue');
    const clicks = streamNum(row, 'clicks');
    const leads = streamNum(row, 'leads');
    const reportClicks = leads || clicks;
    const regs = streamNum(row, 'regs');
    const deps = streamNum(row, 'deps');
    switch (key) {
        case 'stream_name': return String(row?.stream_name || '');
        case 'geo': return String(row?.geo || '');
        case 'campaign_name': return String(row?.campaign_name || '');
        case 'offer_count': return Number(row?.offer_count ?? (row?.offers || []).length ?? 0);
        case 'report_clicks': return reportClicks;
        case 'clicks': return clicks;
        case 'leads': return leads;
        case 'regs': return regs;
        case 'deps': return deps;
        case 'r2d': return regs > 0 ? deps / regs * 100 : 0;
        case 'revenue': return revenue;
        case 'epc': return reportClicks > 0 ? revenue / reportClicks : 0;
        case 'safe_epc': return Number(row?.safe_epc || 0);
        case 'confidence': return Number(row?.confidence || 0);
        case 'rank_score': return Number(row?.rank_score || 0);
        case 'recommended_weight': return Number(row?.recommended_weight || 0);
        default: return streamNum(row, key);
    }
}

function calculateOfferEpcRanks(rows, totals, stream = {}) {
    return rows.map(row => {
        const rec = row.recommendation || {};
        const rankExcluded = row.rank_excluded !== undefined ? Boolean(row.rank_excluded) : !isRankableStreamOffer(row);
        return {
            ...row,
            safe_epc: Number(row.safe_epc ?? rec.safe_epc ?? 0),
            confidence: Number(row.confidence ?? rec.confidence ?? 0),
            rank_score: rankExcluded ? null : Number(row.rank_score ?? rec.score ?? 0),
            recommended_weight: rankExcluded ? null : Number(row.recommended_weight ?? rec.raw_target ?? 0),
            epc_rank: rankExcluded ? null : (row.epc_rank ?? rec.rank ?? null),
            rank_mode: row.rank_mode ?? rec.mode ?? '',
            rank_note: row.rank_note ?? rec.reason ?? '',
            test_weight: Number(row.test_weight ?? rec.test_weight ?? 0),
            weight_cap: Number(row.weight_cap ?? rec.weight_cap ?? 0),
            ranking_clicks: Number(row.ranking_clicks ?? rec.ranking_clicks ?? 0),
            ranking_periods: row.ranking_periods ?? rec.periods ?? [],
            rank_excluded: rankExcluded,
        };
    });
}

function isRankableStreamOffer(row) {
    return streamNum(row, 'share') > 0;
}

function buildStreamOfferTotal(rows) {
    return rows.reduce((acc, row) => {
        addClientStreamStats(acc, row);
        return acc;
    }, {clicks:0, leads:0, regs:0, deps:0, conversions:0, revenue:0});
}

function streamExactMetricCols(r) {
    const clicks = streamValue(r, 'report_clicks');
    const regs = streamValue(r, 'regs');
    const deps = streamValue(r, 'deps');
    const revenue = streamValue(r, 'revenue');
    const epc = streamValue(r, 'epc');
    const safeEpc = streamValue(r, 'safe_epc');
    const confidence = streamValue(r, 'confidence');
    const score = streamValue(r, 'rank_score');
    const mode = String(r.rank_mode || '');
    const modeCls = mode === 'scale' ? 'up' : (mode === 'cut' || mode === 'trim') ? 'down' : '';
    const modeText = mode || '-';
    const scoreText = !r.rank_excluded && score > 0 ? score.toFixed(3) : '-';
    return `
        <td><div class="tdi"><span class="num">${clicks > 0 ? fN(clicks) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${regs > 0 ? fN(regs) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${deps > 0 ? fN(deps) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${revenue > 0 ? f$(revenue) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${epc > 0 ? f$(epc) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${safeEpc > 0 ? f$(safeEpc) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${confidence > 0 ? (confidence * 100).toFixed(0) + '%' : '-'}</span></div></td>
        <td><div class="tdi"><span class="stream-rec-mode ${modeCls}" title="${escAttr(r.rank_note || '')}">${esc(modeText)}</span><span class="num" style="margin-left:6px">${scoreText}</span></div></td>`;
}

function streamRecommendedWeightCell(r) {
    const recommendedWeight = streamValue(r, 'recommended_weight');
    return `<td><div class="tdi"><span class="num">${!r.rank_excluded && recommendedWeight > 0 ? recommendedWeight.toFixed(1) + '%' : '-'}</span></div></td>`;
}

function streamTh(label, col, align='right', cls='') {
    const ts = state.tabs.streams, active = ts.sortCol === col, dir = active ? ts.sortDir : '';
    return `<th class="${cls}"><div class="thi ${align==='left'?'left':''}" onclick="sortStreamsBy('${col}')">${align==='left'?label:''}<span class="sort-ico ${dir}">${SORT_ICO}</span>${align!=='left'?label:''}</div></th>`;
}

function sortStreamsBy(col) {
    const ts = state.tabs.streams;
    ts.sortDir = ts.sortCol === col ? (ts.sortDir === 'desc' ? 'asc' : 'desc') : 'desc';
    ts.sortCol = col;
    pushURL({replace:true});
    renderStreamsTable();
}

function streamRankWeightCell(rank, row, totalClicks) {
    const clicks = streamValue(row, 'report_clicks');
    const weight = totalClicks > 0 ? clicks / totalClicks * 100 : 0;
    return `<td><div class="tdi"><div>
        <div class="num">#${fN(rank)}</div>
        <div class="streams-sub">${weight > 0 ? weight.toFixed(1) + '%' : '-'}</div>
    </div></div></td>`;
}

function renderAllStreamsTable() {
    const tbl = document.getElementById('streamsTbl');
    const head = document.getElementById('streamsHead');
    const total = streamRows.reduce((acc, row) => {
        addClientStreamStats(acc, row.totals || {});
        return acc;
    }, {clicks:0, leads:0, regs:0, deps:0, conversions:0, revenue:0});

    if (head) {
        head.style.display = 'flex';
        head.innerHTML = `
            <div>
                <div class="streams-title">All streams</div>
                <div class="streams-sub">${fN(streamRows.length)} streams in the current report range</div>
            </div>
            <div class="streams-sub" style="margin-left:auto">Clicks ${fN(streamValue(total, 'report_clicks'))}  |  Revenue ${f$(total.revenue)}  |  EPC ${streamValue(total, 'epc') > 0 ? f$(streamValue(total, 'epc')) : '-'}</div>`;
    }

    let rows = [...streamRows];
    const q = (state.tabs.streams.search || '').toLowerCase();
    if (q) rows = rows.filter(r =>
        (r.stream_name || '').toLowerCase().includes(q) ||
        (r.campaign_name || '').toLowerCase().includes(q) ||
        String(r.stream_id || '').includes(q) ||
        String(r.geo || '').toLowerCase().includes(q)
    );

    const ts = state.tabs.streams;
    const allowedCols = ['stream_name', 'report_clicks', 'regs', 'deps', 'revenue', 'epc'];
    const sortCol = allowedCols.includes(ts.sortCol) ? ts.sortCol : 'epc';
    rows.sort((a,b) => {
        const stringCols = ['stream_name', 'geo', 'campaign_name'];
        const col = sortCol;
        const va = stringCols.includes(col) ? streamValue(a, col) : streamValue(a, col);
        const vb = stringCols.includes(col) ? streamValue(b, col) : streamValue(b, col);
        return typeof va === 'string'
            ? (ts.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va))
            : (ts.sortDir === 'asc' ? va - vb : vb - va);
    });

    if (!rows.length) {
        tbl.innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }

    let html = `<table><thead><tr>
        ${streamTh('Stream','stream_name','left')}
        ${streamTh('Clicks','report_clicks')}
        ${streamTh('Regs','regs')}
        ${streamTh('Deps','deps')}
        ${streamTh('Revenue','revenue')}
        ${streamTh('EPC','epc')}
    </tr></thead><tbody>`;

    const totalClicks = rows.reduce((a,r)=>a+streamValue(r,'report_clicks'),0);
    rows.forEach((r) => {
        const epc = streamValue(r, 'epc');
        html += `<tr data-stream-id="${escAttr(r.stream_id)}" onclick="setStream(this.dataset.streamId)" style="cursor:pointer">
            <td><div class="tdi left"><div>
                <div class="offer-name">${esc(r.stream_name || ((r.geo || 'XX') + ' stream'))}</div>
                <div class="streams-sub">GEO ${esc(r.geo || '-')} | Campaign: ${esc(r.campaign_name || '-')} | Stream ID ${esc(r.stream_id || '')}</div>
            </div></div></td>
            <td><div class="tdi"><span class="num">${streamValue(r, 'report_clicks') > 0 ? fN(streamValue(r, 'report_clicks')) : '-'}</span></div></td>
            <td><div class="tdi"><span class="num">${streamValue(r, 'regs') > 0 ? fN(streamValue(r, 'regs')) : '-'}</span></div></td>
            <td><div class="tdi"><span class="num">${streamValue(r, 'deps') > 0 ? fN(streamValue(r, 'deps')) : '-'}</span></div></td>
            <td><div class="tdi"><span class="num">${streamValue(r, 'revenue') > 0 ? f$(streamValue(r, 'revenue')) : '-'}</span></div></td>
            <td><div class="tdi"><span class="num">${epc > 0 ? f$(epc) : '-'}</span></div></td>
        </tr>`;
    });

    const totalEpc = streamValue(total, 'epc');
    html += `</tbody><tfoot><tr class="total-row">
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${rows.length} streams</div></td>
        <td><div class="tdi"><span class="num">${totalClicks > 0 ? fN(totalClicks) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${streamValue(total, 'regs') > 0 ? fN(streamValue(total, 'regs')) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${streamValue(total, 'deps') > 0 ? fN(streamValue(total, 'deps')) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${streamValue(total, 'revenue') > 0 ? f$(streamValue(total, 'revenue')) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${totalEpc > 0 ? f$(totalEpc) : '-'}</span></div></td>
    </tr></tfoot></table>`;
    tbl.innerHTML = html;
}

function addClientStreamStats(total, row) {
    ['clicks','leads','regs','deps','conversions'].forEach(key => total[key] += Number(row?.[key] || 0));
    total.revenue += Number(row?.revenue || 0);
}

function renderStreamsTable() {
    renderStreamsTabs();
    const tbl = document.getElementById('streamsTbl');
    const head = document.getElementById('streamsHead');
    if (!streamRows.length) {
        if (head) head.style.display = 'none';
        if (tbl) tbl.innerHTML = '<div class="tbl-empty">No streams found</div>';
        return;
    }
    if (isAllStreamsSelected()) {
        renderAllStreamsTable();
        return;
    }
    const stream = selectedStream();
    if (!stream) return;
    const total = stream.totals || {};
    const rankedRows = calculateOfferEpcRanks([...(stream.offers || [])], total, stream);

    if (head) {
        const rankedCount = rankedRows.filter(row => row.epc_rank).length;
        const totalCount = rankedRows.length;
        head.style.display = 'flex';
        head.innerHTML = `
            <div>
                <div class="streams-title">${esc(stream.stream_name || ((stream.geo || 'XX') + ' stream'))}</div>
                <div class="streams-sub">Campaign: ${esc(stream.campaign_name || '-')}  |  Stream ID ${esc(stream.stream_id || '')}</div>
            </div>
            <div class="streams-sub" style="margin-left:auto">Ranked offers: ${fN(rankedCount)} / ${fN(totalCount)}  |  Clicks ${fN(streamValue(total, 'report_clicks'))}  |  Revenue ${f$(streamValue(total, 'revenue'))}  |  EPC ${streamValue(total, 'epc') > 0 ? f$(streamValue(total, 'epc')) : '-'}</div>`;
    }

    let rows = [...rankedRows];
    const q = (state.tabs.streams.search || '').toLowerCase();
    if (q) rows = rows.filter(r =>
        (r.offer_name || '').toLowerCase().includes(q) ||
        String(r.offer_id || '').includes(q) ||
        String(r.affiliate_network || '').toLowerCase().includes(q)
    );

    const ts = state.tabs.streams;
    const allowedCols = ['offer_name', 'share', 'recommended_weight', 'report_clicks', 'regs', 'deps', 'revenue', 'epc', 'safe_epc', 'confidence', 'rank_score'];
    if (!allowedCols.includes(ts.sortCol)) ts.sortCol = 'rank_score';
    rows.sort((a,b) => {
        if (a.rank_excluded !== b.rank_excluded) return a.rank_excluded ? 1 : -1;
        const va = ts.sortCol === 'offer_name' ? (a.offer_name || '') : streamValue(a, ts.sortCol);
        const vb = ts.sortCol === 'offer_name' ? (b.offer_name || '') : streamValue(b, ts.sortCol);
        return typeof va === 'string'
            ? (ts.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va))
            : (ts.sortDir === 'asc' ? va - vb : vb - va);
    });

    if (!rows.length) {
        tbl.innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }

    let html = `<table><thead><tr>
        ${streamTh('Rank','rank_score')}
        ${streamTh('Offer','offer_name','left')}
        ${streamTh('Weight','share','right','stream-share-col')}
        ${streamTh('Recommended Weight','recommended_weight')}
        ${streamTh('Clicks','report_clicks')}
        ${streamTh('Regs','regs')}
        ${streamTh('Deps','deps')}
        ${streamTh('Revenue','revenue')}
        ${streamTh('EPC','epc')}
        ${streamTh('Safe EPC','safe_epc')}
        ${streamTh('Confidence','confidence')}
        ${streamTh('Mode / Score','rank_score')}
    </tr></thead><tbody>`;

    rows.forEach(r => {
        const offerTitle = `${r.offer_name || 'Offer ' + r.offer_id}  |  ID ${r.offer_id || ''}`;
        html += `<tr>
            <td><div class="tdi"><span class="num">${r.epc_rank ? '#' + fN(r.epc_rank) : '-'}</span></div></td>
            <td><div class="tdi left"><div>
                <div class="offer-name">${esc(offerTitle)}</div>
            </div></div></td>
            <td class="stream-share-col"><div class="tdi"><span class="num">${r.rank_excluded ? '-' : fN(r.share)}</span></div></td>
            ${streamRecommendedWeightCell(r)}
            ${streamExactMetricCols(r)}
        </tr>`;
    });

    html += `</tbody><tfoot><tr class="total-row">
        <td><div class="tdi"><span class="num">-</span></div></td>
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${rows.length} offers</div></td>
        <td class="stream-share-col"><div class="tdi"><span class="num">${fN(rows.reduce((a,r)=>a+(isRankableStreamOffer(r)?streamNum(r,'share'):0),0))}</span></div></td>
        <td><div class="tdi"><span class="num">${rows.some(r=>!r.rank_excluded && streamValue(r,'recommended_weight')>0) ? rows.reduce((a,r)=>a+(!r.rank_excluded?streamValue(r,'recommended_weight'):0),0).toFixed(1) + '%' : '-'}</span></div></td>
        ${streamExactMetricCols(total)}
    </tr></tfoot></table>`;
    tbl.innerHTML = html;
}

// -- OFFERS VIEW ----------------------------------------------
let offerRows = [];
let _offersChart = null;

async function loadOffersData() {
    document.getElementById('offersTbl').innerHTML = SPIN;
    document.getElementById('offersGeoTbl').innerHTML = '<div class="tbl-empty">Choose an offer</div>';
    document.getElementById('offersCreativeTbl').innerHTML = '<div class="tbl-empty">Choose an offer</div>';
    try {
        const params = appendOfferFilters(new URLSearchParams({range: state.range, group: 'offer'}));
        const res = await fetch('/api/offers.php?' + params.toString());
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        if (json.data?.installed === false) {
            document.getElementById('offersTbl').innerHTML = '<div class="tbl-empty">Offer table is not installed yet</div>';
            return;
        }
        offerRows = json.data?.rows || [];
        renderOfferGeoTabs();
        renderOffersTable();
        if (!offerRows.length) {
            resetOfferDetail('No offer data for the selected period');
            return;
        }
        const visibleRows = filterOffersByGeo(offerRows);
        if (!visibleRows.length) {
            resetOfferDetail('No offers for the selected GEO');
            return;
        }
        const saved = state.tabs.offers.offer_id;
        const selected = visibleRows.find(r => String(r.offer_id) === String(saved)) || visibleRows[0];
        selectOffer(selected.offer_id, true);
    } catch (e) {
        document.getElementById('offersTbl').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
        resetOfferDetail('Offer loading error');
    }
}

function resetOfferDetail(text='Choose an offer') {
    document.getElementById('offerDetailTitle').textContent = text;
    document.getElementById('offerDetailSub').textContent = '';
    if (_offersChart) { _offersChart.destroy(); _offersChart = null; }
    document.getElementById('offersGeoTbl').innerHTML = '';
    document.getElementById('offersCreativeTbl').innerHTML = '';
}

function appendOfferFilters(params) {
    ['geo','bm_id','account_id','campaign_id','adset_id'].forEach(k => {
        if (currentReportFilters().has(k) && state.filters[k]) params.set(k, state.filters[k]);
    });
    return params;
}

function offerGeoPrefix(row) {
    const prefix = String(row?.offer_name || '').trim().slice(0, 2).toUpperCase();
    return /^[A-Z]{2}$/.test(prefix) ? prefix : 'XX';
}

function filterOffersByGeo(rows) {
    const geo = state.tabs.offers.offer_geo || '';
    return geo ? rows.filter(r => offerGeoPrefix(r) === geo) : rows;
}

function renderOfferGeoTabs() {
    const tabsEl = document.getElementById('offersGeoTabs');
    if (!tabsEl) return;
    const current = state.tabs.offers.offer_geo || '';
    const geos = [...new Set(offerRows.map(offerGeoPrefix))].sort();
    const makeBtn = (geo, label) => `<button class="offers-tab ${current === geo ? 'active' : ''}" onclick="setOffersGeo('${escAttr(geo)}')">${esc(label)}</button>`;
    tabsEl.innerHTML = makeBtn('', 'All') + geos.map(g => makeBtn(g, g)).join('');
}

function setOffersGeo(geo) {
    const ts = state.tabs.offers;
    if (ts.offer_geo === geo) return;
    ts.offer_geo = geo;
    ts.sortCol = 'profit';
    ts.sortDir = 'desc';
    ts.offer_id = '';
    pushURL();
    renderOfferGeoTabs();
    renderOffersTable();
    const visibleRows = filterOffersByGeo(offerRows);
    if (visibleRows.length) selectOffer(visibleRows[0].offer_id, true);
    else resetOfferDetail('No offers for the selected GEO');
}

function offerNum(row, key) { return key === 'r2d' ? r2dValue(row) : Number(row?.[key] || 0); }
function offerMetricCols(r) {
    const profit = offerNum(r,'profit');
    const pc = profit > 0 ? 'color:var(--green)' : profit < 0 ? 'color:var(--red)' : '';
    return `
        <td><div class="tdi"><span class="num">${fN(r.clicks)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(r.regs)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(r.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${f$(r.spend)}</span></div></td>
        <td><div class="tdi"><span class="num">${f$(r.revenue)}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pc}">${f$(r.profit)}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pc}">${r.roi === null || r.roi === undefined ? '-' : Number(r.roi).toFixed(1) + '%'}</span></div></td>
        <td><div class="tdi"><span class="num">${r.cpd ? f$(r.cpd) : '-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(r.regs,r.deps)}</span></div></td>`;
}

function offerTh(label, col, align='right') {
    const ts = state.tabs.offers, active = ts.sortCol === col, dir = active ? ts.sortDir : '';
    return `<th><div class="thi ${align==='left'?'left':''}" onclick="sortOffersBy('${col}')">${align==='left'?label:''}<span class="sort-ico ${dir}">${SORT_ICO}</span>${align!=='left'?label:''}</div></th>`;
}

function sortOffersBy(col) {
    const ts = state.tabs.offers;
    ts.sortDir = ts.sortCol === col ? (ts.sortDir === 'desc' ? 'asc' : 'desc') : 'desc';
    ts.sortCol = col;
    pushURL({replace:true});
    renderOffersTable();
}

function renderOffersTable(totals=null) {
    const ts = state.tabs.offers;
    renderOfferGeoTabs();
    let rows = filterOffersByGeo(offerRows);
    const q = (ts.search || '').toLowerCase();
    if (q) rows = rows.filter(r =>
        (r.offer_name || '').toLowerCase().includes(q) ||
        String(r.offer_id || '').includes(q) ||
        (r.affiliate_network || '').toLowerCase().includes(q)
    );
    rows.sort((a,b) => {
        const va = ts.sortCol === 'offer_name' ? (a.offer_name || '') : offerNum(a, ts.sortCol);
        const vb = ts.sortCol === 'offer_name' ? (b.offer_name || '') : offerNum(b, ts.sortCol);
        return typeof va === 'string'
            ? (ts.sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va))
            : (ts.sortDir === 'asc' ? va - vb : vb - va);
    });
    if (!rows.length) {
        document.getElementById('offersTbl').innerHTML = '<div class="tbl-empty">No data</div>';
        return;
    }
    const total = totals || rows.reduce((a,r) => {
        ['clicks','regs','deps','spend','revenue','profit'].forEach(k => a[k] += offerNum(r,k));
        return a;
    }, {clicks:0,regs:0,deps:0,spend:0,revenue:0,profit:0});
    total.roi = total.spend > 0 ? total.profit / total.spend * 100 : null;
    total.cpd = total.deps > 0 ? total.spend / total.deps : null;

    let html = `<table><thead><tr>
        ${offerTh('Offer','offer_name','left')}
        ${offerTh('Clicks','clicks')}${offerTh('Regs','regs')}${offerTh('Deps','deps')}
        ${offerTh('Spend','spend')}${offerTh('Revenue','revenue')}${offerTh('Profit','profit')}${offerTh('ROI','roi')}${offerTh('CPD','cpd')}${offerTh('R2D','r2d')}
    </tr></thead><tbody>`;
    rows.forEach(r => {
        const active = String(r.offer_id) === String(state.tabs.offers.offer_id);
        html += `<tr class="offers-row ${active?'offer-row-active':''}" data-offer-id="${escAttr(r.offer_id || '')}" onclick="selectOffer(this.dataset.offerId)">
            <td><div class="tdi left"><div>
                <div class="offer-name">${esc(r.offer_name || 'Offer ' + r.offer_id)}</div>
                <div class="offer-meta">${esc(r.affiliate_network || '-')}  |  ID ${esc(r.offer_id || '')}</div>
            </div></div></td>
            ${offerMetricCols(r)}
        </tr>`;
    });
    html += `</tbody><tfoot><tr class="total-row">
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${rows.length} offers</div></td>
        ${offerMetricCols(total)}
    </tr></tfoot></table>`;
    document.getElementById('offersTbl').innerHTML = html;
}

async function selectOffer(offerId, replace=false) {
    const row = offerRows.find(r => String(r.offer_id) === String(offerId));
    if (!row) return;
    state.tabs.offers.offer_id = String(offerId);
    pushURL({replace});
    renderOffersTable();
    document.getElementById('offerDetailTitle').textContent = row.offer_name || ('Offer ' + offerId);
    document.getElementById('offerDetailSub').textContent = `${row.affiliate_network || '-'}  |  ID ${offerId}`;
    document.getElementById('offersGeoTbl').innerHTML = SPIN;
    document.getElementById('offersCreativeTbl').innerHTML = SPIN;
    try {
        const base = appendOfferFilters(new URLSearchParams({range: state.range, offer_id: String(offerId)}));
        const urls = ['day','geo','creative'].map(group => {
            const p = new URLSearchParams(base); p.set('group', group); return '/api/offers.php?' + p.toString();
        });
        const [dayJson, geoJson, creativeJson] = await Promise.all(urls.map(u => fetch(u).then(r => r.json())));
        if (!dayJson.ok) throw new Error(dayJson.error || 'day API error');
        if (!geoJson.ok) throw new Error(geoJson.error || 'geo API error');
        if (!creativeJson.ok) throw new Error(creativeJson.error || 'creative API error');
        await ensureCreativePreviewMap();
        renderOfferChart(dayJson.data?.rows || []);
        renderOffersMiniTable('offersGeoTbl', geoJson.data?.rows || [], 'geo');
        renderOffersMiniTable('offersCreativeTbl', creativeJson.data?.rows || [], 'creative');
    } catch (e) {
        document.getElementById('offersGeoTbl').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
        document.getElementById('offersCreativeTbl').innerHTML = '';
    }
}

function renderOffersMiniTable(id, rows, kind) {
    if (!rows.length) { document.getElementById(id).innerHTML = '<div class="tbl-empty">No data</div>'; return; }
    rows = [...rows].sort((a,b) => offerNum(b,'profit') - offerNum(a,'profit')).slice(0, 20);
    const name = r => kind === 'geo'
        ? (r.geo || '-')
        : (r.fb_ad_name || r.fb_adset_name || r.fb_campaign_name || '-');
    let html = `<table style="min-width:620px"><thead><tr>
        <th><div class="thi left">${kind === 'geo' ? 'GEO' : 'Creo'}</div></th>
        <th><div class="thi">Deps</div></th><th><div class="thi">Spend</div></th><th><div class="thi">Revenue</div></th><th><div class="thi">Profit</div></th><th><div class="thi">ROI</div></th>
    </tr></thead><tbody>`;
    rows.forEach(r => {
        const pc = offerNum(r,'profit') > 0 ? 'color:var(--green)' : offerNum(r,'profit') < 0 ? 'color:var(--red)' : '';
        const rowName = name(r);
        const nameAttrs = kind === 'creative' ? creativePreviewAttrs(rowName) : '';
        const nameCls = kind === 'creative' ? creativePreviewClass(rowName) : '';
        html += `<tr><td><div class="tdi left"><span class="num${nameCls}" ${nameAttrs}>${esc(rowName)}</span></div></td>
            <td><div class="tdi"><span class="num">${fN(r.deps)}</span></div></td>
            <td><div class="tdi"><span class="num spend-val">${f$(r.spend)}</span></div></td>
            <td><div class="tdi"><span class="num">${f$(r.revenue)}</span></div></td>
            <td><div class="tdi"><span class="num" style="${pc}">${f$(r.profit)}</span></div></td>
            <td><div class="tdi"><span class="num" style="${pc}">${r.roi === null || r.roi === undefined ? '-' : Number(r.roi).toFixed(1) + '%'}</span></div></td></tr>`;
    });
    html += '</tbody></table>';
    document.getElementById(id).innerHTML = html;
}

function ensureChartJs(cb) {
    if (window.Chart) { cb(); return; }
    const existing = document.querySelector('script[data-chartjs]');
    if (existing) { existing.addEventListener('load', cb, {once:true}); return; }
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
    s.dataset.chartjs = '1';
    s.onload = cb;
    document.head.appendChild(s);
}

function renderOfferChart(rows) {
    const canvas = document.getElementById('offersCanvas');
    if (_offersChart) { _offersChart.destroy(); _offersChart = null; }
    if (!rows.length || !canvas) return;
    const labels = rows.map(r => String(r.date || '').slice(5));
    const datasets = [
        {label:'Spend', key:'spend', color:'#1877f2'},
        {label:'Revenue', key:'revenue', color:'#31a24c'},
        {label:'Profit', key:'profit', color:'#e67e22'},
        {label:'Deps', key:'deps', color:'#9c27b0', axis:'y1'},
    ].map(m => ({
        label: m.label,
        data: rows.map(r => Number(r[m.key] || 0)),
        borderColor: m.color,
        backgroundColor: m.color + '18',
        borderWidth: 2,
        pointRadius: 2,
        tension: 0.3,
        yAxisID: m.axis || 'y',
    }));
    ensureChartJs(() => {
        _offersChart = new Chart(canvas, {
            type: 'line',
            data: {labels, datasets},
            options: {
                responsive:true,
                maintainAspectRatio:false,
                interaction:{mode:'index', intersect:false},
                plugins:{legend:{display:true, labels:{boxWidth:12, font:{size:11}}}},
                scales:{
                    y:{ticks:{font:{size:10}, callback:v=>'$'+Number(v).toLocaleString('en')}, grid:{color:'rgba(0,0,0,.06)'}},
                    y1:{position:'right', ticks:{font:{size:10}}, grid:{drawOnChartArea:false}},
                    x:{ticks:{font:{size:10}}, grid:{color:'rgba(0,0,0,.04)'}},
                },
            },
        });
    });
}

// -- TRENDS VIEW ----------------------------------------------
let _trendsChart  = null;
let _trendsData   = { campaign: null, account: null };
let _trendsTotals = { campaign: {}, account: {} };
let _trendsDaily  = { campaign: {}, account: {} };
let _trendsSel    = new Set();
const TREND_COLORS = ['#1877f2','#e91e63','#ff9800','#4caf50','#9c27b0','#00bcd4','#ff5722','#607d8b','#795548','#f44336'];

function setTrendsTab(tab) {
    state.tabs.trends.tab = tab;
    document.getElementById('trendTab-campaign').classList.toggle('active', tab==='campaign');
    document.getElementById('trendTab-account').classList.toggle('active',  tab==='account');
    _trendsSel = new Set();
    state.tabs.trends.sel = '';
    pushURL();
    renderTrendsTbl();
}

async function loadTrendsData() {
    document.getElementById('trendsTbl').innerHTML = SPIN;
    if (_trendsChart) { _trendsChart.destroy(); _trendsChart = null; }
    try {
        // 1. Load lists
        const accRes = await fetch('/api/accounts.php?range=30d');
        const accJson = await accRes.json();
        _trendsData.account = (accJson.data||[]).filter(a=>a.status===1).map(a=>({id:String(a.id), name:a.name, spend30d:a.period?.spend||0, status:a.status||0})).sort((a,b)=>compareWithActive(a,b,b.spend30d-a.spend30d));

        // Campaigns only from active accounts
        const activeAccIds = _trendsData.account.map(a=>a.id).join(',');
        const campRes = await fetch('/api/campaigns.php?level=campaign&range=30d' + (activeAccIds ? '&account_ids='+activeAccIds : ''));
        const campJson = await campRes.json();
        _trendsData.campaign = (campJson.data||[]).map(c=>({id:String(c.id), name:c.name, spend30d:c.stats?.spend||0, status:realDeliveryStatus(c)})).sort((a,b)=>compareWithActive(a,b,b.spend30d-a.spend30d));

        // 2. Load sparklines for all rows at once (both types in parallel)
        const accIds  = _trendsData.account.slice(0,100).map(a=>a.id).join(',');
        const campIds = _trendsData.campaign.slice(0,100).map(c=>c.id).join(',');

        const [spAcc, spCamp] = await Promise.all([
            accIds  ? fetch(`/api/sparklines.php?type=account&ids=${accIds}`)  : null,
            campIds ? fetch(`/api/sparklines.php?type=campaign&ids=${campIds}`) : null,
        ]);

        if (spAcc) {
            const j = await spAcc.json();
            _trendsTotals.account = j.data?.totals || {};
            _trendsDaily.account  = j.data?.daily  || {};
        }
        if (spCamp) {
            const j = await spCamp.json();
            _trendsTotals.campaign = j.data?.totals || {};
            _trendsDaily.campaign  = j.data?.daily  || {};
        }

        // 3. Restore selected lines from URL or take the top 5 from the current tab.
        const tab = state.tabs.trends.tab || 'campaign';
        const availableIds = new Set((_trendsData[tab] || []).map(r => r.id));
        const savedSel = (state.tabs.trends.sel || '').split(',').filter(id => availableIds.has(id));
        _trendsSel = new Set(savedSel.length ? savedSel : (_trendsData[tab] || []).slice(0,5).map(r=>r.id));
        state.tabs.trends.sel = [..._trendsSel].join(',');
        document.getElementById('trendTab-campaign').classList.toggle('active', tab==='campaign');
        document.getElementById('trendTab-account').classList.toggle('active',  tab==='account');
        pushURL({replace:true});

        renderTrendsTbl();
    } catch(e) {
        document.getElementById('trendsTbl').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function trendsStatusBadge(status, tab) {
    if (tab === 'account') {
        const map = {1:['Active','var(--green)'], 2:['Off','var(--text3)'], 3:['Debt','var(--red)'], 7:['Review','#f5a623'], 9:['Grace','var(--blue)']};
        const [label, color] = map[status] || ['?','var(--text3)'];
        return `<span style="font-size:11px;font-weight:600;color:${color}">${label}</span>`;
    } else {
        const map = {'ACTIVE':['Active','var(--green)'], 'PAUSED':['Pause','var(--text3)'], 'ARCHIVED':['Archived','var(--text3)'], 'DELETED':['Deleted','var(--red)']};
        const s = String(status).toUpperCase();
        const [label, color] = map[s] || [s||'?','var(--text3)'];
        return `<span style="font-size:11px;font-weight:600;color:${color}">${label}</span>`;
    }
}

function renderTrendsTbl() {
    const tab  = state.tabs.trends.tab;
    const rows = _trendsData[tab] || [];
    if (!rows.length) { document.getElementById('trendsTbl').innerHTML = '<div class="tbl-empty">No data</div>'; return; }

    const totals = _trendsTotals[tab] || {};
    let colorIdx = 0;
    const selColors = {};
    for (const id of _trendsSel) selColors[id] = TREND_COLORS[colorIdx++ % TREND_COLORS.length];

    let html = `<table style="width:100%;border-collapse:collapse">
        <thead><tr style="border-bottom:2px solid var(--border)">
            <th style="width:50px;padding:8px 12px"></th>
            <th style="text-align:left;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3)">Name</th>
            <th style="text-align:center;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3)">Status</th>
            <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3)">Today</th>
            <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3)">Yesterday</th>
            <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3)">7 days</th>
            <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3)">30 days</th>
        </tr></thead><tbody>`;

    rows.forEach(row => {
        const id    = row.id;
        const isSel = _trendsSel.has(id);
        const color = selColors[id] || '';
        const t     = totals[id] || {};
        html += `<tr style="border-bottom:1px solid var(--border2);cursor:pointer${isSel?';background:var(--sel-bg)':''}" onclick="toggleTrendsRow('${esc(id)}')">
            <td style="padding:8px 12px;text-align:center">
                <input type="checkbox" ${isSel?'checked':''} onclick="event.stopPropagation();toggleTrendsRow('${esc(id)}')">
                ${isSel?`<div style="width:20px;height:3px;background:${color};border-radius:2px;margin:3px auto 0"></div>`:''}
            </td>
            <td style="padding:8px 12px;font-size:13px;font-weight:${isSel?700:400}">${esc(row.name)}</td>
            <td style="text-align:center;padding:8px 12px">${trendsStatusBadge(row.status, tab)}</td>
            <td style="text-align:right;padding:8px 12px;font-family:monospace;font-size:13px">${t.today>0?f$(t.today):'-'}</td>
            <td style="text-align:right;padding:8px 12px;font-family:monospace;font-size:13px">${t.yesterday>0?f$(t.yesterday):'-'}</td>
            <td style="text-align:right;padding:8px 12px;font-family:monospace;font-size:13px">${t['7d']>0?f$(t['7d']):'-'}</td>
            <td style="text-align:right;padding:8px 12px;font-family:monospace;font-size:13px">${f$(row.spend30d)}</td>
        </tr>`;
    });

    html += '</tbody></table>';
    document.getElementById('trendsTbl').innerHTML = html;
    updateTrendsChart(tab);
}

function toggleTrendsRow(id) {
    if (_trendsSel.has(id)) _trendsSel.delete(id); else _trendsSel.add(id);
    state.tabs.trends.sel = [..._trendsSel].join(',');
    pushURL({replace:true});
    renderTrendsTbl();
}

function updateTrendsChart(tab) {
    const canvas = document.getElementById('trendsCanvas');
    if (_trendsChart) { _trendsChart.destroy(); _trendsChart = null; }
    const daily = _trendsDaily[tab] || {};
    if (_trendsSel.size === 0 || !Object.keys(daily).length) return;

    const allDates = new Set();
    for (const id of _trendsSel) (_trendsDaily[tab][id]||[]).forEach(x => allDates.add(x.date));
    const labels = [...allDates].sort().map(d => d.slice(5));
    const rows = _trendsData[tab] || [];
    let colorIdx = 0;
    const datasets = [];
    for (const id of _trendsSel) {
        const row   = rows.find(r => r.id === id);
        const color = TREND_COLORS[colorIdx++ % TREND_COLORS.length];
        const dateMap = {};
        (_trendsDaily[tab][id]||[]).forEach(x => dateMap[x.date.slice(5)] = x.spend);
        datasets.push({
            label: row?.name || id,
            data:  labels.map(l => dateMap[l] || 0),
            borderColor: color, backgroundColor: color+'18',
            borderWidth: 2, pointRadius: 2, tension: 0.3, fill: false,
        });
    }
    const draw = () => {
        _trendsChart = new Chart(canvas, {
            type: 'line', data: {labels, datasets},
            options: {
                responsive: true, interaction: {mode:'index', intersect:false},
                plugins: {legend:{display:true, labels:{boxWidth:14, font:{size:11}}}},
                scales: {
                    x: {ticks:{font:{size:10}}, grid:{color:'rgba(0,0,0,.06)'}},
                    y: {ticks:{font:{size:10}, callback:v=>'$'+v.toLocaleString('en')}, grid:{color:'rgba(0,0,0,.06)'}},
                },
            },
        });
    };
    if (!window.Chart) {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
        s.onload = draw; document.head.appendChild(s);
    } else draw();
}

// -- TRENDS VIEW ----------------------------------------------
let _geoTrendsData = null;
let _geoTrendsCharts = [];
const GEOTRENDS_METRICS = {
    spend:  { label: 'Spend',   color: '#1877f2', active: true  },
    revenue:{ label: 'Revenue', color: '#28a745', active: true  },
    profit: { label: 'Profit',  color: '#6f42c1', active: true  },
    roi:    { label: 'ROI %',   color: '#fd7e14', active: false },
};

function syncGeoTrendsMetricsFromRoute() {
    const defaults = TAB_DEFAULTS.geotrends.metrics.split(',');
    const active = (state.tabs.geotrends.metrics || '').split(',').filter(k => GEOTRENDS_METRICS[k]);
    const selected = new Set(active.length ? active : defaults);
    Object.keys(GEOTRENDS_METRICS).forEach(k => GEOTRENDS_METRICS[k].active = selected.has(k));
    state.tabs.geotrends.metrics = Object.keys(GEOTRENDS_METRICS).filter(k => GEOTRENDS_METRICS[k].active).join(',');
}

function saveGeoTrendsMetricsToRoute() {
    state.tabs.geotrends.metrics = Object.keys(GEOTRENDS_METRICS).filter(k => GEOTRENDS_METRICS[k].active).join(',');
}

async function loadGeoTrendsData() {
    document.getElementById('geotrendsChart').innerHTML = SPIN;
    document.getElementById('geotrendsCtrl').innerHTML = '';
    try {
        const params = new URLSearchParams({level:'campaign', range:'30d'});
        if (currentReportFilters().has('bm_id') && state.filters.bm_id)           params.set('bm_id',      state.filters.bm_id);
        if (currentReportFilters().has('account_id') && exactAccountFilterValue()) params.set('account_id', exactAccountFilterValue());
        const res  = await fetch('/api/campaigns.php?'+params);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error||'API error');

        const geos = [...new Set((json.data||[]).map(c => extractGeo(c.name)))].sort();
        if (!geos.length) { document.getElementById('geotrendsChart').innerHTML = '<div class="tbl-empty">No data</div>'; return; }

        // Load data for all geos
        const geoData = {};
        await Promise.all(geos.map(async geo => {
            const p = new URLSearchParams({geo, period:'last30'});
            if (currentReportFilters().has('bm_id') && state.filters.bm_id)           p.set('bm_id',      state.filters.bm_id);
            if (currentReportFilters().has('account_id') && exactAccountFilterValue()) p.set('account_id', exactAccountFilterValue());
            const r = await fetch('/api/monthly.php?'+p);
            const j = await r.json();
            geoData[geo] = j.data || [];
        }));

        // Determine top geos by profit
        const geoProfit = {};
        for (const [geo, days] of Object.entries(geoData)) {
            geoProfit[geo] = days.reduce((s,d) => s + (d.profit||0), 0);
        }
        const sortedGeos = geos.sort((a,b) => geoProfit[b] - geoProfit[a]);
        const topGeo = sortedGeos[0];

        _geoTrendsData = {geos: sortedGeos, geoData, geoProfit};
        _geoTrendsData.currentGeo = sortedGeos.includes(state.tabs.geotrends.geo) ? state.tabs.geotrends.geo : topGeo;
        state.tabs.geotrends.geo = _geoTrendsData.currentGeo;
        syncGeoTrendsMetricsFromRoute();
        pushURL({replace:true});

        renderGeoTrendsCtrl();
        drawSingleGeoChart(_geoTrendsData.currentGeo);
    } catch(e) {
        document.getElementById('geotrendsChart').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function renderGeoTrendsCtrl() {
    if (!_geoTrendsData) return;
    const {geos, geoProfit, currentGeo} = _geoTrendsData;
    const ctrl = document.getElementById('geotrendsCtrl');

    // Geo selector
    let selHtml = '<select onchange="selectGeoTrend(this.value)" style="padding:4px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-weight:600;background:var(--surface);color:var(--text1);cursor:pointer;margin-right:16px">';
    for (const geo of geos) {
        const profit = geoProfit[geo] || 0;
        const pStr = (profit >= 0 ? '+' : '') + '$' + Math.round(profit).toLocaleString('en');
        selHtml += `<option value="${esc(geo)}" ${geo===currentGeo?'selected':''}>${esc(geo)} (${pStr})</option>`;
    }
    selHtml += '</select>';

    // Metric toggles
    let btnsHtml = '<span style="font-size:12px;font-weight:600;color:var(--text3);margin-right:8px">Metrics:</span>';
    for (const [key, m] of Object.entries(GEOTRENDS_METRICS)) {
        btnsHtml += `<button onclick="toggleGeoTrendsMetric('${key}')" style="padding:3px 12px;border:2px solid ${m.color};border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;background:${m.active?m.color:'transparent'};color:${m.active?'#fff':m.color};transition:all .15s">${m.label}</button>`;
    }

    ctrl.innerHTML = selHtml + btnsHtml;
}

function selectGeoTrend(geo) {
    if (!_geoTrendsData) return;
    _geoTrendsData.currentGeo = geo;
    state.tabs.geotrends.geo = geo;
    pushURL();
    drawSingleGeoChart(geo);
}

function toggleGeoTrendsMetric(key) {
    GEOTRENDS_METRICS[key].active = !GEOTRENDS_METRICS[key].active;
    saveGeoTrendsMetricsToRoute();
    pushURL({replace:true});
    renderGeoTrendsCtrl();
    if (_geoTrendsData) drawSingleGeoChart(_geoTrendsData.currentGeo);
}

function renderGeoTrendsCharts() {
    if (!_geoTrendsData) return;
    renderGeoTrendsCtrl();
    drawSingleGeoChart(_geoTrendsData.currentGeo);
}

function drawSingleGeoChart(geo) {
    const container = document.getElementById('geotrendsChart');
    container.innerHTML = `<canvas id="gtc_main" style="max-height:420px"></canvas>`;
    const activeMetrics = Object.entries(GEOTRENDS_METRICS).filter(([,m])=>m.active);

    if (!window.Chart) {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
        s.onload = () => _drawChart(geo, activeMetrics);
        document.head.appendChild(s);
    } else {
        _drawChart(geo, activeMetrics);
    }
}

function _drawChart(geo, activeMetrics) {
    _geoTrendsCharts.forEach(c => c.destroy());
    _geoTrendsCharts = [];
    const canvas = document.getElementById('gtc_main');
    if (!canvas || !_geoTrendsData) return;
    const days = _geoTrendsData.geoData[geo] || [];
    const labels = days.map(d => d.day.slice(5));
    const hasRoi = activeMetrics.some(([k]) => k === 'roi');
    const datasets = activeMetrics.map(([key, m]) => ({
        label: m.label,
        data: days.map(d => key==='roi' ? (d.spend>0?+((d.revenue-d.spend)/d.spend*100).toFixed(2):0) : +(d[key]||0).toFixed(2)),
        borderColor: m.color,
        backgroundColor: m.color + '18',
        borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 5, tension: 0.3, fill: true,
        yAxisID: key==='roi' ? 'y1' : 'y',
    }));
    const chart = new Chart(canvas, {
        type: 'line',
        data: {labels, datasets},
        options: {
            responsive: true,
            interaction: {mode:'index', intersect:false},
            plugins: {
                legend:{display:true, labels:{boxWidth:14, font:{size:12}}},
                title:{display:true, text:`${geo}  - last 30 days`, font:{size:14,weight:'bold'}, color:'#1a1a1a'},
            },
            scales: {
                x: {ticks:{font:{size:11}}, grid:{color:'rgba(0,0,0,.06)'}},
                y: {ticks:{font:{size:11}, callback:v=>'$'+v.toLocaleString('en')}, grid:{color:'rgba(0,0,0,.06)'}},
                ...(hasRoi?{y1:{position:'right',ticks:{font:{size:11},callback:v=>v+'%'},grid:{display:false}}}:{}),
            },
        },
    });
    _geoTrendsCharts.push(chart);
}

async function loadGeoData() {
    // Restore selection from filter
    _geoSel = state.filters.geo ? new Set(state.filters.geo.split(',').map(g=>g.trim()).filter(Boolean)) : new Set();
    document.getElementById('geoTbl').innerHTML = SPIN;
    try {
        const params = buildAPIParams({level:'campaign'});
        params.set('include_deleted', '1');
        params.set('cost_baseline', '1');
        const countParams = buildAPIParams();
        countParams.delete('range');
        if (viewUsesAccountFilter('geo')) {
            const accountId = exactAccountFilterValue();
            const scope = currentAccountScope();
            if (accountId) countParams.set('account_id', accountId);
            else if (scope) countParams.set('account_scope', scope);
        }
        const [campRes,totalsRes,creativeCountsRes]=await Promise.all([fetch('/api/campaigns.php?'+params),fetch('/api/totals.php?'+(() => { const p = new URLSearchParams({range:state.range}); if (currentReportFilters().has('bm_id') && state.filters.bm_id) p.set('bm_id', state.filters.bm_id); return p; })()),fetch('/api/creative_geo_counts.php?'+countParams)]);
        const campText = await campRes.text();
        const totalsText = await totalsRes.text();
        const creativeCountsText = await creativeCountsRes.text();
        let campJson = null, totalsJson = null, creativeCountsJson = null;
        try {
            campJson = campText ? JSON.parse(campText) : null;
        } catch (parseErr) {
            throw new Error((campText || '').trim().slice(0, 220) || `HTTP ${campRes.status} | Empty API response`);
        }
        try {
            totalsJson = totalsText ? JSON.parse(totalsText) : null;
        } catch (parseErr) {
            throw new Error((totalsText || '').trim().slice(0, 220) || `HTTP ${totalsRes.status} | Empty API response`);
        }
        try {
            creativeCountsJson = creativeCountsText ? JSON.parse(creativeCountsText) : null;
        } catch (parseErr) {
            throw new Error((creativeCountsText || '').trim().slice(0, 220) || `HTTP ${creativeCountsRes.status} | Empty API response`);
        }
        if (!campRes.ok) {
            throw new Error(`HTTP ${campRes.status}${campText ? ' | ' + campText.trim().slice(0, 220) : ''}`);
        }
        if (!totalsRes.ok) {
            throw new Error(`HTTP ${totalsRes.status}${totalsText ? ' | ' + totalsText.trim().slice(0, 220) : ''}`);
        }
        if (!creativeCountsRes.ok) {
            throw new Error(`HTTP ${creativeCountsRes.status}${creativeCountsText ? ' | ' + creativeCountsText.trim().slice(0, 220) : ''}`);
        }
        if (!campJson || typeof campJson !== 'object') {
            throw new Error(`HTTP ${campRes.status} | Empty API response body`);
        }
        if (!totalsJson || typeof totalsJson !== 'object') {
            throw new Error(`HTTP ${totalsRes.status} | Empty API response body`);
        }
        if (!creativeCountsJson || typeof creativeCountsJson !== 'object') {
            throw new Error(`HTTP ${creativeCountsRes.status} | Empty API response body`);
        }
        if (!campJson.ok) throw new Error(campJson.error||'API error');
        if (!creativeCountsJson.ok) throw new Error(creativeCountsJson.error||'creative counts API error');
        const creativeCounts = {};
        for (const row of (creativeCountsJson.data || [])) {
            creativeCounts[row.geo] = {
                total: Number(row.creatives_count || 0),
                successful: Number(row.successful_creatives_count || 0),
            };
        }
        const monthTotals=totalsJson.data||{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0};
        const campRows = campJson.data || [];
        const campTotals=campRows.reduce((acc,r)=>{const s=r.stats||{};for(const k of['spend','delta','impressions','clicks','leads','regs','deps','revenue'])acc[k]+=s[k]||0;return acc;},{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0});
        const orphan={};for(const k of['spend','delta','impressions','clicks','leads','regs','deps','revenue'])orphan[k]=Math.max(0,Math.round((monthTotals[k]-campTotals[k])*10000)/10000);
        const hasOrphan=orphan.revenue>0||orphan.spend>0||orphan.leads>0;
        const byGeo={};
        for (const camp of campRows) {
            const geo=extractGeo(camp.name);
            const creoCount = creativeCounts[geo] || {total:0,successful:0};
            if (!byGeo[geo]) byGeo[geo]={geo,creatives_count:creoCount.total,successful_creatives_count:creoCount.successful,camps_active:0,camps_total:0,cost_baseline:null,stats:{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0}};
            const g=byGeo[geo]; if (Number(camp.account_status ?? 1) === 1 && normalizedStatus(camp.status) !== 'DELETED') g.camps_total++;
            if (isReallyActive(camp)) g.camps_active++;
            if (!g.cost_baseline && camp.stats?.cost_baseline) g.cost_baseline = camp.stats.cost_baseline;
            const s=camp.stats||{}; for(const k of['spend','delta','impressions','clicks','leads','regs','deps','revenue'])g.stats[k]+=s[k]||0;
        }
        for (const [geo, cnt] of Object.entries(creativeCounts)) {
            if (!byGeo[geo]) byGeo[geo]={geo,creatives_count:cnt.total,successful_creatives_count:cnt.successful,camps_active:0,camps_total:0,cost_baseline:null,stats:{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0}};
        }
        if (hasOrphan) byGeo['(unattributed)']={geo:'(unattributed)',creatives_count:0,successful_creatives_count:0,camps_active:0,camps_total:0,stats:{...orphan},_isOrphan:true};
        geoRows=Object.values(byGeo).map(g=>{const s=g.stats;s.profit=s.revenue-s.spend;s.usd_per_campaign=g.camps_total>0?s.profit/g.camps_total:0;s.roi=s.spend>0?s.profit/s.spend*100:0;s.ctr=s.impressions>0?s.clicks/s.impressions*100:0;s.cpm=s.impressions>0?s.spend/s.impressions*1000:0;s.cpc=s.clicks>0?s.spend/s.clicks:0;s.cpl=s.leads>0?s.spend/s.leads:0;s.cpr=s.regs>0?s.spend/s.regs:0;s.cpd=s.deps>0?s.spend/s.deps:0;return g;});
        renderGeoTable();
    } catch(e) { document.getElementById('geoTbl').innerHTML=`<div class="tbl-empty">Error: ${esc(e.message)}</div>`; }
}
function geoTh(label,col,align='right'){const ts=state.tabs.geo,active=ts.sortCol===col,dir=active?ts.sortDir:'';return `<th class="resizable-th" data-col-key="${escAttr(col)}"><div class="thi ${align==='left'?'left':''}" onclick="sortGeoBy('${col}')">${align==='left'?label:''}<span class="sort-ico ${dir}">${SORT_ICO}</span>${align!=='left'?label:''}</div></th>`;}
function sortGeoBy(col){const ts=state.tabs.geo;ts.sortDir=ts.sortCol===col?(ts.sortDir==='desc'?'asc':'desc'):'desc';ts.sortCol=col;pushURL({replace:true});document.getElementById('geoTbl').style.opacity='0.4';requestAnimationFrame(()=>requestAnimationFrame(()=>{renderGeoTable();document.getElementById('geoTbl').style.opacity='';}));}
function onGeoSearch(v){state.tabs.geo.search=v;const cb=document.getElementById('geoClearBtn');if(cb)cb.style.display=v?'':'none';pushURL({replace:true});renderGeoTable();}
function renderGeoActiveTags(){ /* panel removed */ }
function clearGeoFilter(k){
    if(k==='geo'){state.filters.geo='';state.filters.bm_id='';state.filters.bm_name='';}
    else if(k==='bm_id'){state.filters.bm_id='';state.filters.bm_name='';}
    pushURL(); loadGeoData();
}
// Geo selection (checkbox)
let _geoSel = new Set();
function toggleGeoRow(geo, checked) {
    if (checked) _geoSel.add(geo); else _geoSel.delete(geo);
    state.filters.geo = [..._geoSel].sort().join(',');
    pushURL();
    renderGeoTable();
}
function drillDownGeo(geo) {
    setFilter('geo', geo, geo);
    setView('campaign');
}
function renderGeoTable() {
    const ts=state.tabs.geo;
    let r=[...geoRows];
    const q=(ts.search||'').toLowerCase();
    if (q) r=r.filter(x=>x.geo.toLowerCase().includes(q));
    r.sort((a,b)=>{const col=ts.sortCol;const va=col.startsWith('stats.')?metricValue(a.stats,col.slice(6)):col==='camps_active'?a.camps_active:col==='camps_total'?a.camps_total:col==='creatives_count'?a.creatives_count:col==='successful_creatives_count'?a.successful_creatives_count:(a[col]??'');const vb=col.startsWith('stats.')?metricValue(b.stats,col.slice(6)):col==='camps_active'?b.camps_active:col==='camps_total'?b.camps_total:col==='creatives_count'?b.creatives_count:col==='successful_creatives_count'?b.successful_creatives_count:(b[col]??'');const primary=typeof va==='string'?(ts.sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va)):(ts.sortDir==='asc'?va-vb:vb-va);return compareWithActive(a,b,primary);});
    if (!r.length) { document.getElementById('geoTbl').innerHTML='<div class="tbl-empty">No data</div>'; return; }
    const T=r.reduce((a,x)=>({spend:a.spend+(x.stats.spend||0),delta:a.delta+(x.stats.delta||0),impressions:a.impressions+(x.stats.impressions||0),clicks:a.clicks+(x.stats.clicks||0),leads:a.leads+(x.stats.leads||0),regs:a.regs+(x.stats.regs||0),deps:a.deps+(x.stats.deps||0),revenue:a.revenue+(x.stats.revenue||0),creatives_count:a.creatives_count+(x.creatives_count||0),successful_creatives_count:a.successful_creatives_count+(x.successful_creatives_count||0),camps_active:a.camps_active+x.camps_active,camps_total:a.camps_total+x.camps_total}),{spend:0,delta:0,impressions:0,clicks:0,leads:0,regs:0,deps:0,revenue:0,creatives_count:0,successful_creatives_count:0,camps_active:0,camps_total:0});
    T.profit=T.revenue-T.spend;T.usd_per_campaign=T.camps_total>0?T.profit/T.camps_total:0;T.roi=T.spend>0?T.profit/T.spend*100:0;T.ctr=T.impressions>0?T.clicks/T.impressions*100:0;T.cpm=T.impressions>0?T.spend/T.impressions*1000:0;T.cpc=T.clicks>0?T.spend/T.clicks:0;T.cpl=T.leads>0?T.spend/T.leads:0;T.cpr=T.regs>0?T.spend/T.regs:0;T.cpd=T.deps>0?T.spend/T.deps:0;
    const BT=sumCostStats(r, x=>x.cost_baseline);
    renderGeoActiveTags();
    let html=`<table class="resizable-table" data-resize-key="geo"><thead><tr>
        <th class="resizable-th" style="width:36px" data-col-key="select"><div class="thi center"><input type="checkbox" title="Select all" onchange="r.forEach(x=>toggleGeoRow(x.geo,this.checked))"></div></th>
        ${geoTh('Geo','geo','left')}${geoTh('Creo OK/Total','successful_creatives_count')}${geoTh('Active/Total','camps_active')}${geoTh('USD/Campaign','stats.usd_per_campaign')}
        ${geoTh('Impressions','stats.impressions')}${geoTh('Clicks','stats.clicks')}${geoTh('CPC','stats.cpc')}
        ${geoTh('Leads','stats.leads')}${geoTh('CPL','stats.cpl')}
        ${geoTh('Regs','stats.regs')}${geoTh('CPR','stats.cpr')}
        ${geoTh('Deps','stats.deps')}${geoTh('CPD','stats.cpd')}${geoTh('R2D','stats.r2d')}
        ${geoTh('Spend','stats.spend')}${geoTh('Delta','stats.delta')}${geoTh('Revenue','stats.revenue')}
        ${geoTh('Profit','stats.profit')}${geoTh('ROI','stats.roi')}
        ${geoTh('CTR','stats.ctr')}${geoTh('CPM','stats.cpm')}
    </tr></thead><tbody>`;

    r.forEach(row=>{
        const s=row.stats, pC=s.profit>0?'color:var(--green)':s.profit<0?'color:var(--red)':'';
        const isSel=_geoSel.has(row.geo);
        const isGeoFilter=state.filters.geo && state.filters.geo.split(',').map(g=>g.trim()).includes(row.geo);
        const isOrphan=!!row._isOrphan;
        html+=`<tr class="${isSel?'sel':''}">
            <td><div class="tdi center"><input type="checkbox" ${isSel||isGeoFilter?'checked':''} onchange="toggleGeoRow('${esc(row.geo)}',this.checked)"></div></td>
            <td><div class="tdi left"><div class="nc"><div class="nc-texts">
                <div class="nc-name" onclick="drillDownGeo('${esc(row.geo)}')">${esc(row.geo)}</div>
                ${isOrphan?'<div class="nc-sub">unattributed Keitaro rows</div>':''}
            </div></div></div></td>
            <td><div class="tdi"><span class="num" style="color:${row.successful_creatives_count>0?'var(--green)':'var(--text3)'}">${fN(row.successful_creatives_count||0)}</span><span class="num">/${row.creatives_count>0?fN(row.creatives_count):'0'}</span></div></td>
            <td><div class="tdi"><span class="num" style="color:${row.camps_active>0?'var(--green)':'var(--text3)'}">${row.camps_active}</span><span class="num">/${row.camps_total}</span></div></td>
            <td><div class="tdi"><span class="num">${row.camps_total>0?(s.profit!==0||s.spend>0||s.revenue>0?f$(s.usd_per_campaign):f$(0)):'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${fN(s.impressions)}</span></div></td>
            <td><div class="tdi"><span class="num">${fN(s.clicks)}</span></div></td>
            ${costMetricCell(s, 'cpc', s.cpc, row.cost_baseline)}
            <td><div class="tdi"><span class="num">${s.leads>0?fN(s.leads):'-'}</span></div></td>
            ${costMetricCell(s, 'cpl', s.cpl, row.cost_baseline)}
            <td><div class="tdi"><span class="num">${s.regs>0?fN(s.regs):'-'}</span></div></td>
            ${costMetricCell(s, 'cpr', s.cpr, row.cost_baseline)}
            <td><div class="tdi"><span class="num">${s.deps>0?fN(s.deps):'-'}</span></div></td>
            ${costMetricCell(s, 'cpd', s.cpd, row.cost_baseline)}
            <td><div class="tdi"><span class="num">${fR2D(s.regs,s.deps)}</span></div></td>
            <td><div class="tdi"><span class="num spend-val">${f$(s.spend)}</span></div></td>
            <td><div class="tdi"><span class="num">${f$(s.delta||0)}</span></div></td>
            <td><div class="tdi"><span class="num">${s.revenue>0?f$(s.revenue):'-'}</span></div></td>
            <td><div class="tdi"><span class="num" style="${pC}">${s.revenue>0||s.spend>0?f$(s.profit):'-'}</span></div></td>
            <td><div class="tdi"><span class="num" style="${pC}">${s.spend>0?s.roi.toFixed(1)+'%':'-'}</span></div></td>
            <td><div class="tdi"><span class="num">${fP(s.ctr)}</span></div></td>
            <td><div class="tdi"><span class="num">${f$(s.cpm)}</span></div></td>
        </tr>`;
    });

    const pC=T.profit>0?'color:var(--green)':T.profit<0?'color:var(--red)':'';
    html+=`</tbody><tfoot><tr class="total-row">
        <td></td>
        <td><div class="tdi left" style="font-size:11.5px;color:var(--text2)">${r.length} geo</div></td>
        <td><div class="tdi"><span class="num" style="color:${T.successful_creatives_count>0?'var(--green)':'var(--text3)'}">${fN(T.successful_creatives_count)}</span><span class="num">/${T.creatives_count>0?fN(T.creatives_count):'0'}</span></div></td>
        <td><div class="tdi"><span class="num" style="color:var(--green)">${T.camps_active}</span><span class="num">/${T.camps_total}</span></div></td>
        <td><div class="tdi"><span class="num">${T.camps_total>0?f$(T.usd_per_campaign):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(T.impressions)}</span></div></td>
        <td><div class="tdi"><span class="num">${fN(T.clicks)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.cpc>0?f$(T.cpc):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?fN(T.leads):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.leads>0?f$(T.cpl):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?fN(T.regs):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.regs>0?f$(T.cpr):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?fN(T.deps):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${T.deps>0?f$(T.cpd):'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fR2D(T.regs,T.deps)}</span></div></td>
        <td><div class="tdi"><span class="num spend-val">${f$(T.spend)}</span></div></td>
        <td><div class="tdi"><span class="num">${f$(T.delta||0)}</span></div></td>
        <td><div class="tdi"><span class="num">${T.revenue>0?f$(T.revenue):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pC}">${T.revenue>0||T.spend>0?f$(T.profit):'-'}</span></div></td>
        <td><div class="tdi"><span class="num" style="${pC}">${T.spend>0?T.roi.toFixed(1)+'%':'-'}</span></div></td>
        <td><div class="tdi"><span class="num">${fP(T.ctr)}</span></div></td>
        <td><div class="tdi"><span class="num">${f$(T.cpm)}</span></div></td>
    </tr></tfoot></table>`;
    const geoTblEl = document.getElementById('geoTbl');
    geoTblEl.innerHTML = html;
    initColumnResizing(geoTblEl);
}

// -- GEO DIFF VIEW --------------------------------------------
async function loadGeoDiffData() {
    const el = document.getElementById('geodiffTbl');
    el.innerHTML = SPIN;
    try {
        const p = new URLSearchParams();
        if (currentReportFilters().has('bm_id') && state.filters.bm_id) p.set('bm_id', state.filters.bm_id);
        const res = await fetch('/api/geo_diff.php?' + p.toString());
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');
        geoDiffRows = json.data || [];
        window._geoDiffMeta = json;
        renderGeoDiffTable();
    } catch (e) {
        el.innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function geoDiffShortDate(s) {
    const parts = String(s || '').split('-');
    return parts.length === 3 ? `${parts[2]}.${parts[1]}` : String(s || '');
}
function geoDiffPeriodLabel(isSpend=false) {
    const meta = window._geoDiffMeta || {};
    const today = geoDiffShortDate(meta.date_today);
    const yesterday = geoDiffShortDate(meta.date_yesterday);
    const from7 = geoDiffShortDate(meta.date_7d_from);
    const to7 = geoDiffShortDate(meta.date_7d_to);
    const from30 = geoDiffShortDate(meta.date_30d_from);
    const to30 = geoDiffShortDate(meta.date_30d_to);
    const base7 = from7 && to7 ? `${from7}-${to7}` : '7d';
    const base30 = from30 && to30 ? `${from30}-${to30}` : '30d';
    return `${today || 'today'} / ${yesterday || 'yesterday'} / ${isSpend ? base7 + ' avg/day' : base7} / ${isSpend ? base30 + ' avg/day' : base30}`;
}
function geoDiffTh(label, col, align='right', sub='') {
    const ts = state.tabs.geodiff;
    const active = ts.sortCol === col;
    const dir = active ? ts.sortDir : '';
    const cls = `thi ${align==='left'?'left':''} ${sub?'multi':''}`.trim();
    const main = align === 'left'
        ? `${esc(label)}<span class="sort-ico ${dir}">${SORT_ICO}</span>`
        : `<span class="sort-ico ${dir}">${SORT_ICO}</span>${esc(label)}`;
    return `<th><div class="${cls}" onclick="sortGeoDiffBy('${col}')"><div class="th-main">${main}</div>${sub ? `<div class="th-sub">${esc(sub)}</div>` : ''}</div></th>`;
}
function sortGeoDiffBy(col) {
    const ts = state.tabs.geodiff;
    ts.sortDir = ts.sortCol === col ? (ts.sortDir === 'desc' ? 'asc' : 'desc') : 'desc';
    ts.sortCol = col;
    renderGeoDiffTable();
}
function geoDiffVal(row, path) {
    return path.split('.').reduce((v, k) => v && v[k] !== undefined ? v[k] : null, row);
}
function renderGeoDiffTable() {
    const el = document.getElementById('geodiffTbl');
    const ts = state.tabs.geodiff;
    let r = [...geoDiffRows];
    const q = (ts.search || '').toLowerCase();
    if (q) r = r.filter(x => x.geo.toLowerCase().includes(q));
    r.sort((a, b) => cmp(geoDiffVal(a, ts.sortCol), geoDiffVal(b, ts.sortCol)));
    if (!r.length) { el.innerHTML = '<div class="tbl-empty">No data</div>'; return; }

    let html = `<table><thead><tr>
        ${geoDiffTh('Geo','geo','left')}
        ${geoDiffTh('CPC','today.cpc','right',geoDiffPeriodLabel())}
        ${geoDiffTh('CPL','today.cpl','right',geoDiffPeriodLabel())}
        ${geoDiffTh('CPR','today.cpr','right',geoDiffPeriodLabel())}
        ${geoDiffTh('CPD','today.cpd','right',geoDiffPeriodLabel())}
        ${geoDiffTh('R2D','today.r2d','right',geoDiffPeriodLabel())}
        ${geoDiffTh('Spend','today.spend','right',geoDiffPeriodLabel(true))}
    </tr></thead><tbody>`;
    for (const row of r) {
        html += `<tr>
            <td><div class="tdi left"><div class="nc"><div class="nc-texts"><div class="nc-name" onclick="drillDownGeo('${esc(row.geo)}')">${esc(row.geo)}</div></div></div></div></td>
            ${geoDiffCell(row, 'cpc', true)}
            ${geoDiffCell(row, 'cpl', true)}
            ${geoDiffCell(row, 'cpr', true)}
            ${geoDiffCell(row, 'cpd', true)}
            ${geoDiffCell(row, 'r2d', false, true)}
            ${geoDiffCell(row, 'spend', true)}
        </tr>`;
    }
    html += '</tbody></table>';
    el.innerHTML = html;
}
function geoDiffCell(row, key, isMoney, isPercent=false) {
    const today = row.today?.[key] ?? null;
    const yesterday = row.yesterday?.[key] ?? null;
    const higherIsGood = key === 'spend' || key === 'r2d';
    const last7 = key === 'spend'
        ? (row.last7?.spend_daily ?? null)
        : (row.last7?.[key] ?? null);
    const last30 = key === 'spend'
        ? (row.last30?.spend_daily ?? null)
        : (row.last30?.[key] ?? null);
    const vals = [
        {value: today, style: geoDiffColor(today, yesterday, higherIsGood)},
        {value: yesterday, style: ''},
        {value: last7, style: geoDiffColor(today, last7, higherIsGood)},
        {value: last30, style: geoDiffColor(today, last30, higherIsGood)},
    ];
    return `<td><div class="tdi"><div class="num-wrap">
        <span class="num">${vals.map(v => `<span style="${v.style}">${geoDiffFmt(v.value, isMoney, isPercent)}</span>`).join(' / ')}</span>
    </div></div></td>`;
}
function geoDiffFmt(v, isMoney, isPercent=false) {
    if (v === null || v === undefined) return '-';
    if (isPercent) return Number(v).toFixed(2) + '%';
    return isMoney ? ('$' + Number(v).toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2})) : fN(v);
}
function geoDiffColor(value, base, higherIsGood=false) {
    if (value === null || value === undefined || base === null || base === undefined) return '';
    const v = Number(value), b = Number(base);
    if (!Number.isFinite(v) || !Number.isFinite(b) || b === 0 || v === b) return '';
    const pct = Math.abs((v - b) / Math.abs(b)) * 100;
    const level = pct >= 50 ? 4 : pct >= 25 ? 3 : pct >= 10 ? 2 : 1;
    const alpha = [0, .55, .70, .85, 1][level];
    const weight = [0, 650, 700, 750, 800][level];
    const isBetter = higherIsGood ? v > b : v < b;
    const color = isBetter ? `rgba(22, 163, 74, ${alpha})` : `rgba(220, 38, 38, ${alpha})`;
    return `color:${color};font-weight:${weight}`;
}

// -- KEITARO SYNC ---------------------------------------------
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

async function syncKeitaroAndRefresh() {
    const btn=document.getElementById('btnRefresh');
    if (btn) { btn.disabled=true; btn.style.opacity='0.5'; }
    try {
        await syncKeitaro();
    } finally {
        if (btn) { btn.disabled=false; btn.style.opacity=''; }
    }
    reload();
    loadCards();
    loadAccounts(); // refresh syncLabel
}

function copyToClipboard(text, el) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try { document.execCommand('copy'); } catch(e) { console.warn('copy failed', e); }
    document.body.removeChild(ta);
    el.classList.add('copy-flash');
    setTimeout(() => el.classList.remove('copy-flash'), 700);
}

// -- GEO METRICS MODAL ----------------------------------------
function openGeoMetrics() {
    document.getElementById('geoMetricsModal').style.display = 'flex';
    loadGeoMetrics();
}
function closeGeoMetrics() {
    document.getElementById('geoMetricsModal').style.display = 'none';
}
function geoMetricC2L(m) {
    if (m?.c2l !== null && m?.c2l !== undefined && Number.isFinite(Number(m.c2l))) {
        return Number(m.c2l).toFixed(2) + '%';
    }
    const clicks = Number(m?.clicks || 0);
    const leads = Number(m?.leads || 0);
    return clicks > 0 ? (leads / clicks * 100).toFixed(2) + '%' : '-';
}
async function loadGeoMetrics() {
    const el = document.getElementById('geoMetricsContent');
    el.innerHTML = SPIN;
    const btn = document.getElementById('btnRefreshGeoMetrics');
    btn.disabled = true;
    try {
        const res  = await fetch('/api/geo_metrics.php');
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API error');

        const geos  = json.data.geos  || {};
        const rules = json.data.rules || [];

        if (!Object.keys(geos).length) {
            el.innerHTML = '<div class="tbl-empty">No data</div>';
            return;
        }

        // Metrics table
        let html = `<table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead><tr style="border-bottom:2px solid var(--border)">
                <th style="text-align:left;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">Geo</th>
                <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">CPC</th>
                <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">CPL</th>
                <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">C2L</th>
                <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">CPR</th>
                <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">CPD</th>
                <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">R2D</th>
                <th style="text-align:right;padding:8px 12px;font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap">Payout 7D/30D</th>
            </tr></thead><tbody>`;

        for (const [geo, m] of Object.entries(geos)) {
            html += `<tr style="border-bottom:1px solid var(--border2)" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
                <td style="padding:8px 12px;font-weight:700;font-size:14px">${esc(geo)}</td>
                <td style="text-align:right;padding:8px 12px;font-family:monospace">${m.cpc > 0 ? f$(m.cpc) : '-'}</td>
                <td style="text-align:right;padding:8px 12px;font-family:monospace">${m.cpl > 0 ? f$(m.cpl) : '-'}</td>
                <td style="text-align:right;padding:8px 12px;font-family:monospace">${geoMetricC2L(m)}</td>
                <td style="text-align:right;padding:8px 12px;font-family:monospace">${m.cpr > 0 ? f$(m.cpr) : '-'}</td>
                <td style="text-align:right;padding:8px 12px;font-family:monospace">${m.cpd > 0 ? f$(m.cpd) : '-'}</td>
                <td style="text-align:right;padding:8px 12px;font-family:monospace">${m.r2d !== null && m.r2d !== undefined ? Number(m.r2d).toFixed(2) + '%' : '-'}</td>
                <td style="text-align:right;padding:8px 12px;font-weight:600;color:var(--green);font-family:monospace">${m.payout > 0 ? f$(m.payout) : '-'}</td>
            </tr>`;
        }
        html += `</tbody></table>`;

        // Bid rules
        if (rules.length) {
            html += `<div style="margin-top:20px">
                <div style="font-weight:700;font-size:13px;margin-bottom:8px;color:var(--text2)">Application rules (Spend / Payout)</div>
                <table style="border-collapse:collapse;font-size:13px">
                <thead><tr style="border-bottom:2px solid var(--border)">
                    <th style="text-align:left;padding:6px 16px 6px 0;font-size:12px;font-weight:600;color:var(--text3)">Range</th>
                    <th style="text-align:right;padding:6px 0;font-size:12px;font-weight:600;color:var(--text3)">Multiplier</th>
                </tr></thead><tbody>`;
            for (const r of rules) {
                const range = r.spend_max === null
                    ? `> ${r.spend_min} payouts`
                    : `${r.spend_min} - ${r.spend_max} payouts`;
                const mult = r.multiplier >= 1
                    ? `<span style="color:var(--green);font-weight:700">x${r.multiplier}</span>`
                    : `<span style="color:var(--red);font-weight:700">x${r.multiplier}</span>`;
                html += `<tr style="border-bottom:1px solid var(--border2)">
                    <td style="padding:6px 24px 6px 0">${range}</td>
                    <td style="text-align:right;padding:6px 0">${mult}</td>
                </tr>`;
            }
            html += `</tbody></table></div>`;
        }

        el.innerHTML = html;
    } catch(e) {
        el.innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
    btn.disabled = false;
}

// -- BALANCE OFFERS MODAL -------------------------------------
function resetBalanceOffersButton() {
    const btn = document.getElementById('btnRunBalance');
    if (!btn) return;
    btn.disabled = false;
    btn.textContent = 'Start';
    btn.onclick = runBalanceOffers;
}

function openBalanceOffers() {
    document.getElementById('balanceOffersModal').style.display = 'flex';
    document.getElementById('balanceOffersOutput').textContent = 'Click "Start" to calculate and apply weights.';
    resetBalanceOffersButton();
}

function closeBalanceOffers() {
    document.getElementById('balanceOffersModal').style.display = 'none';
    resetBalanceOffersButton();
}

async function runBalanceOffers() {
    const btn = document.getElementById('btnRunBalance');
    const out = document.getElementById('balanceOffersOutput');
    btn.disabled = true;
    btn.textContent = 'Running...';
    out.textContent = 'Request sent, waiting for response...\n';
    try {
        const res  = await fetch('/api/run_balance.php', {method:'POST'});
        const json = await res.json();
        if (!json.ok) throw new Error(json.error||'API error');
        out.textContent = json.data.output || '(no output)';
        btn.textContent = json.data.exit_code === 0 ? 'Done' : 'Completed with error';
        btn.disabled = false;
        btn.onclick = closeBalanceOffers;
    } catch(e) {
        out.textContent = 'Error: ' + e.message;
        btn.textContent = 'Error';
        btn.disabled = false;
        btn.onclick = closeBalanceOffers;
    }
}

// -- BALANCES MODAL -------------------------------------------
async function openBalances() {
    document.getElementById('balancesModal').classList.add('open');
    document.getElementById('balancesTbl').innerHTML = SPIN;
    try {
        const res  = await fetch('/api/accounts.php?range=today');
        const json = await res.json();
        if (!json.ok) throw new Error(json.error||'API error');

        // Filter: only active (status=1) accounts with non-zero spend_cap
        const accts = (json.data||[]).filter(a => a.status === 1 && a.spend_cap && a.spend_cap > 0);

        // Alert rules: cap<=500 -> remaining<200, cap>500 -> remaining<500
        const needsAlert = a => {
            const cap  = a.spend_cap || 0;
            const diff = cap - (a.amount_spent || 0);
            return cap <= 500 ? diff < 200 : diff < 500;
        };

        const hasAlerts = accts.some(needsAlert);

        // Refresh button state
        const btn = document.getElementById('btnBalances');
        btn.className = 'tb-btn' + (hasAlerts ? ' alert-red' : '');

        // Sort by remaining amount (lower first)
        accts.sort((a,b) => {
            const da = (a.spend_cap||0) - (a.amount_spent||0);
            const db = (b.spend_cap||0) - (b.amount_spent||0);
            return da - db;
        });

        if (!accts.length) {
            document.getElementById('balancesTbl').innerHTML = '<div class="tbl-empty">No data</div>';
            return;
        }

        const alertAccts = accts.filter(needsAlert);

        let html = '';

        // Header with alert
        const container = document.getElementById('balancesTbl');
        let balHtml = '';

        if (alertAccts.length) {
            const alertList = alertAccts.map(a => `${a.name} ${a.id.replace('act_','')} +1000`).join('\n');
            const alertText = `Need top up\n${alertList}`;
            balHtml += `<div id="balAlertHdr" style="padding:10px 16px;background:#fff0f0;border-bottom:2px solid var(--red);display:flex;align-items:center;gap:10px;cursor:pointer" title="Click to copy the list">
                <span style="color:var(--red);font-weight:700">Need top up ${alertAccts.length} account${alertAccts.length===1?'':'s'}</span>
                <span style="font-size:11px;color:var(--text3)"> |  click to copy list</span>
            </div>`;
            window._balAlertText = alertText;
        }

        balHtml += `<table>
            <thead><tr>
                <th>Account</th><th>BM</th>
                <th class="r">Spend cap</th>
                <th class="r">Spent</th>
                <th class="r">Remaining</th>
                <th class="r">%</th>
            </tr></thead><tbody>`;

        for (const a of accts) {
            const cap   = a.spend_cap    || 0;
            const spent = a.amount_spent || 0;
            const diff  = cap - spent;
            const pct   = cap > 0 ? (spent/cap*100) : 0;
            const isAlert = needsAlert(a);
            const fillCls = pct > 90 ? 'danger' : pct > 70 ? 'warn' : '';
            const diffColor = isAlert ? 'color:var(--red);font-weight:700' : diff < cap*0.3 ? 'color:#f5a623' : 'color:var(--green)';
            const copyText = `${a.name} ${a.id.replace('act_','')} +1000\n`;
            balHtml += `<tr class="${isAlert?'alert-row':''}" style="cursor:pointer" data-copy="${esc(copyText)}" title="Copy">
                <td>
                    <div style="font-weight:600">${esc(a.name)}</div>
                    <div style="font-size:11px;color:var(--text3)">${esc(a.id)}</div>
                </td>
                <td style="font-size:12px;color:var(--text2)">${esc(a.bm_name||'-')}</td>
                <td class="r">${f$(cap)}</td>
                <td class="r">${f$(spent)}</td>
                <td class="r">
                    <span style="${diffColor}">${f$(diff)}</span>
                    <div class="bal-bar"><div class="bal-bar-fill ${fillCls}" style="width:${pct.toFixed(1)}%"></div></div>
                </td>
                <td class="r" style="font-size:12px;color:var(--text3)">${pct.toFixed(1)}%</td>
            </tr>`;
        }

        balHtml += `</tbody></table>`;
        container.innerHTML = balHtml;

        // Attach events after render
        container.querySelectorAll('tr[data-copy]').forEach(tr => {
            tr.addEventListener('click', () => copyToClipboard(tr.dataset.copy, tr));
        });
        const alertHdr = container.querySelector('#balAlertHdr');
        if (alertHdr) alertHdr.addEventListener('click', () => copyToClipboard(window._balAlertText, alertHdr));
    } catch(e) {
        document.getElementById('balancesTbl').innerHTML = `<div class="tbl-empty">Error: ${esc(e.message)}</div>`;
    }
}

function closeBalances() {
    document.getElementById('balancesModal').classList.remove('open');
}

// Check balances on load to highlight the button
async function checkBalancesAlert() {
    try {
        const res  = await fetch('/api/accounts.php?range=today');
        const json = await res.json();
        if (!json.ok) return;
        const accts = (json.data||[]).filter(a => a.status===1 && a.spend_cap && a.spend_cap>0);
        const hasAlerts = accts.some(a => {
            const diff = (a.spend_cap||0) - (a.amount_spent||0);
            return (a.spend_cap||0) <= 500 ? diff < 200 : diff < 500;
        });
        const btn = document.getElementById('btnBalances');
        if (btn) btn.className = 'tb-btn' + (hasAlerts ? ' alert-red' : '');
    } catch(e) {}
}
function toggleDD(id) {
    const dd=document.getElementById(id); if (!dd) return;
    if (id === 'ddRange') syncRangeUI();
    document.querySelectorAll('.dropdown').forEach(d => { if (d.id!==id) d.classList.remove('open'); });
    if (dd.classList.contains('open')) { dd.classList.remove('open'); return; }
    const trigger=dd.previousElementSibling||dd.parentElement;
    const rect=trigger.getBoundingClientRect();
    dd.style.top=(rect.bottom+4)+'px';
    const ddW=parseInt(dd.style.minWidth)||210;
    if (rect.right-ddW<0) { dd.style.left=rect.left+'px'; dd.style.right='auto'; }
    else { dd.style.left='auto'; dd.style.right=(window.innerWidth-rect.right)+'px'; }
    dd.classList.add('open');
}
function closeDropdowns() { document.querySelectorAll('.dropdown').forEach(d=>d.classList.remove('open')); }
document.addEventListener('click', e => { if (!e.target.closest('.dd-wrap')) closeDropdowns(); });

function syncRouteUI() {
    renderNavActive();
    renderFilterTags();
    renderDeliveryBadges();
    syncRangeUI();
    syncWidthResetButton();

    const ts = curTab();
    const srch = document.getElementById('srch');
    if (srch) srch.value = ts.search||'';
    const clearBtn = document.getElementById('clearBtn');
    if (clearBtn) clearBtn.style.display = ts.search?'':'none';
    const delivery = document.getElementById('fltDelivery');
    if (delivery) delivery.value = ts.delivery || '';

    document.getElementById('tblwrap').style.display      = TABLE_LEVELS.includes(state.view)?'':'none';
    document.getElementById('monthWrap').style.display    = state.view==='month'   ?'flex':'none';
    document.getElementById('bmCardsWrap').style.display  = state.view==='bm_cards'?'flex':'none';
    document.getElementById('creoWrap').style.display     = state.view==='creo'    ?'block':'none';
    document.getElementById('topcreoWrap').style.display  = state.view==='topcreo' ?'block':'none';
    document.getElementById('creativeCalendarWrap').style.display = state.view==='creative_calendar'?'flex':'none';
    document.getElementById('campsCalendarWrap').style.display = state.view==='camps_calendar'?'flex':'none';
    document.getElementById('streamsWrap').style.display  = state.view==='streams' ?'flex':'none';
    document.getElementById('offersWrap').style.display   = state.view==='offers'  ?'block':'none';
    document.getElementById('geoWrap').style.display        = state.view==='geo'        ?'block':'none';
    document.getElementById('geotrendsWrap').style.display  = state.view==='geotrends'  ?'flex':'none';
    document.getElementById('geodiffWrap').style.display    = state.view==='geodiff'    ?'block':'none';
    document.getElementById('trendsWrap').style.display      = state.view==='trends'      ?'flex':'none';
    document.getElementById('geocabsWrap').style.display     = state.view==='geocabs'     ?'flex':'none';
    document.getElementById('tasksWrap').style.display       = state.view==='tasks'       ?'flex':'none';

    const trendCamp = document.getElementById('trendTab-campaign');
    const trendAcc = document.getElementById('trendTab-account');
    if (trendCamp) trendCamp.classList.toggle('active', state.tabs.trends.tab === 'campaign');
    if (trendAcc) trendAcc.classList.toggle('active', state.tabs.trends.tab === 'account');
    const bmSel = document.getElementById('gcBmSelect');
    if (bmSel) bmSel.value = state.tabs.geocabs.bm_id || '';
}

// -- INIT -----------------------------------------------------
(async function init() {
    const loaded = readURL();
    if (!loaded) state.view = 'geo'; // default is Geo

    syncRouteUI();
    pushURL({replace:true});

    await syncKeitaro();
    loadAccounts();
    loadCards();
    checkBalancesAlert();
    reload();

    window.addEventListener('popstate', () => {
        readURL();
        syncRouteUI();
        loadCards();
        reload();
    });
    window.addEventListener('hashchange', () => {
        if (location.search) return;
        readURL();
        syncRouteUI();
        pushURL({replace:true});
        loadCards();
        reload();
    });
})();


// -- GEO & ACCOUNTS --------------------------------------------------
async function loadGeocabsData() {
    const el = document.getElementById('geocabsTbl');
    el.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)">Loading...</div>';

    let res, json;
    try {
        const bmVal = state.tabs.geocabs.bm_id || '';
        const gcUrl = '/api/geocabs.php' + (bmVal ? '?bm_id=' + encodeURIComponent(bmVal) : '');
        res  = await fetch(gcUrl);
        json = await res.json();
    } catch(e) {
        el.innerHTML = `<div style="padding:40px;color:var(--red)">Error: ${e.message}</div>`;
        return;
    }
    if (!json.ok || !json.data) {
        el.innerHTML = `<div style="padding:40px;color:var(--red)">${json.error||'No data'}</div>`;
        return;
    }

    const { accounts, cells, bms } = json.data;
    const geoProfit30d = json.data.geo_profit_30d || {};
    const geos = [...(json.data.geos || [])].sort((a, b) => {
        const diff = (Number(geoProfit30d[b]) || 0) - (Number(geoProfit30d[a]) || 0);
        return diff || String(a).localeCompare(String(b));
    });

    // Fill BM selector on first load
    const bmSel2 = document.getElementById('gcBmSelect');
    if (bmSel2 && bms && bmSel2.options.length <= 1) {
        const curVal = state.tabs.geocabs.bm_id || '';
        bmSel2.innerHTML = '<option value="">All</option>' +
            bms.map(b => `<option value="${b.id}" ${b.id==curVal?'selected':''}>${b.name}</option>`).join('');
    }
    if (bmSel2) bmSel2.value = state.tabs.geocabs.bm_id || '';

    if (!accounts.length) {
        el.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)">No active accounts with data</div>';
        return;
    }

    const fP = n => {
        const sign = n >= 0 ? '+' : '';
        return sign + '$' + Math.abs(Math.round(n)).toLocaleString('en');
    };

    // Table header
    let html = '<div style="overflow:auto"><table class="gc-table"><thead><tr>';
    html += '<th>Account</th>';
    for (const geo of geos) {
        html += `<th class="geo-th" title="Profit 30d: ${fP(Number(geoProfit30d[geo]) || 0)}">${geo}</th>`;
    }
    html += '</tr></thead><tbody>';

    // Rows: accounts
    for (const acc of accounts) {
        const accIdEsc = acc.id.replace(/'/g, "\'");
        const accNameEsc = acc.name.replace(/'/g, "\'").replace(/"/g, '&quot;');
        html += `<tr>`;
        html += `<td title="${acc.id}">
            <div class="nc-texts" style="cursor:pointer" onclick="setRange('30d');drillDownAccount('${accIdEsc}','${accNameEsc}')">
                <div class="nc-name" style="font-size:13px">${acc.name}</div>
                <div class="nc-sub">${acc.id}${acc.bm_name ? " " + acc.bm_name : ""}</div>
            </div>
        </td>`;

        for (const geo of geos) {
            const key  = acc.id + ':' + geo;
            const cell = cells[key];
            if (!cell) {
                html += `<td class="gc-empty">-</td>`;
            } else {
                const dot = cell.active > 0
                    ? '<span class="gc-dot-green"></span>'
                    : '<span class="gc-dot-red"></span>';
                html += `<td>
                    <div class="gc-cell">
                        <div class="gc-rk">${dot}${cell.active}/${cell.total}</div>
                    </div>
                </td>`;
            }
        }
        html += '</tr>';
    }

    html += '</tbody></table></div>';
    html += `<div style="padding:10px 4px;font-size:11px;color:var(--text3)">
        Period: ${json.data.date_from} - ${json.data.date_to} | Active accounts only | Rows by 30d profit v | Geos by 30d profit v
    </div>`;

    el.innerHTML = html;
}

function setGeocabsBm(value) {
    state.tabs.geocabs.bm_id = value || '';
    pushURL();
    loadGeocabsData();
}

</script>
<!-- GEO METRICS MODAL -->
<div id="geoMetricsModal" onclick="if(event.target===this)closeGeoMetrics()" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding-top:40px">
  <div style="background:var(--surface);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:80vw;max-height:85vh;display:flex;flex-direction:column">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <h3 style="margin:0;font-size:16px;font-weight:700">Geo metrics (30D -> ROI 30%, payout 70% 7D / 30% 30D)</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <button id="btnRefreshGeoMetrics" class="tb-btn primary" onclick="loadGeoMetrics()">Refresh</button>
        <button class="tb-btn" onclick="closeGeoMetrics()">Close</button>
      </div>
    </div>
    <div style="overflow:auto;flex:1;padding:0">
      <div id="geoMetricsContent" style="padding:16px;min-height:200px">
        <div class="tbl-loading">Loading...</div>
      </div>
    </div>
  </div>
</div>

<!-- BALANCE OFFERS MODAL -->
<div id="balanceOffersModal" onclick="if(event.target===this)closeBalanceOffers()" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:flex-start;justify-content:center;padding-top:40px">
  <div style="background:var(--surface);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.25);width:80vw;max-height:85vh;display:flex;flex-direction:column">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <h3 style="margin:0;font-size:16px;font-weight:700">Offer balancing</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <button id="btnRunBalance" class="tb-btn primary" onclick="runBalanceOffers()">Start</button>
        <button class="tb-btn" onclick="closeBalanceOffers()">Close</button>
      </div>
    </div>
    <div style="overflow:auto;flex:1;padding:0">
      <pre id="balanceOffersOutput" style="margin:0;padding:16px;font-family:'SF Mono',Monaco,monospace;font-size:14px;line-height:1.6;color:var(--text1);white-space:pre-wrap;word-break:break-word;min-height:200px">Click "Start" to calculate and apply weights.</pre>
    </div>
  </div>
</div>

<!-- BALANCES MODAL -->
<div id="adsetBidModal" onclick="if(event.target===this)closeAdsetBidPopup()">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3 id="adsetBidTitle">Adset bid</h3>
      <button class="tb-btn" onclick="closeAdsetBidPopup()">Close</button>
    </div>
    <div class="modal-body">
      <div class="bid-current">Current bid<strong id="adsetBidCurrent">-</strong></div>
      <input id="adsetBidInput" class="bid-input" type="text" inputmode="decimal" placeholder="Enter new bid" oninput="setAdsetBidAbsoluteMode()">
      <div class="bid-step-row">
        <button class="bid-step-btn" onclick="shiftAdsetBid(-0.2)">-20%</button>
        <button class="bid-step-btn" onclick="shiftAdsetBid(-0.1)">-10%</button>
        <button class="bid-step-btn" onclick="shiftAdsetBid(0.1)">+10%</button>
        <button class="bid-step-btn" onclick="shiftAdsetBid(0.2)">+20%</button>
      </div>
      <div class="bid-help" id="adsetBidHint">Percent buttons create a task relative to the current bid. Manual input creates a task for an exact bid.</div>
      <div class="bid-actions">
        <button class="bid-cancel-btn" onclick="closeAdsetBidPopup()">Cancel</button>
        <button class="bid-save-btn" id="adsetBidSave" onclick="saveAdsetBid()">Save</button>
      </div>
    </div>
  </div>
</div>
<div id="balancesModal" onclick="if(event.target===this)closeBalances()">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3>Account balances</h3>
      <button class="tb-btn" onclick="closeBalances()">Close</button>
    </div>
    <div class="modal-body">
      <div id="balancesTbl" style="padding:12px"><div class="tbl-loading">Loading...</div></div>
    </div>
  </div>
</div>

</body>
</html>
