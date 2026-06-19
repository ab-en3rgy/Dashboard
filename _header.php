<?php
// _header.php - shared top bar for all dashboard pages
// @version 1.2.1
// Requires: $me user array and connected _bootstrap.php or session
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$impersonationAdmin = null;
$adminToken = $_COOKIE['fb_ads_admin_token'] ?? '';
$currentToken = $_COOKIE['fb_ads_token'] ?? '';
if ($adminToken && $adminToken !== $currentToken && isset($auth) && $auth instanceof Auth) {
    $adminUser = $auth->check($adminToken);
    if ($adminUser && $adminUser['role'] === 'admin') {
        $impersonationAdmin = $adminUser;
    }
}
?>
<style>
.topbar{
  width:100%;
  position:sticky;
  top:0;
  z-index:250;
  background:var(--surface);
  border-bottom:1px solid var(--border);
  box-shadow:0 1px 2px rgba(0,0,0,.04);
}
.tb-shell{
  width:min(100%,1800px);
  margin:0 auto;
  min-height:58px;
  padding:10px 16px;
  display:flex;
  align-items:center;
  gap:12px;
}
.tb-left,
.tb-right{
  display:flex;
  align-items:center;
  gap:10px;
  min-width:0;
}
.tb-left{flex:1 1 auto}
.tb-right{flex:0 0 auto;margin-left:auto;justify-content:flex-end;flex-wrap:wrap}
.tb-logo{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight:800;
  font-size:17px;
  color:var(--blue);
  letter-spacing:-.3px;
  text-decoration:none;
  white-space:nowrap;
}
.tb-logo-icon{
  width:32px;
  height:32px;
  background:var(--blue);
  border-radius:10px;
  display:flex;
  align-items:center;
  justify-content:center;
  flex-shrink:0;
}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sync{
  font-size:12px;
  color:var(--text3);
  white-space:nowrap;
}
.tb-user{
  display:flex;
  align-items:center;
  gap:6px;
  color:var(--text2);
  font-size:13px;
  white-space:nowrap;
}
.tb-avatar{
  width:28px;
  height:28px;
  border-radius:50%;
  background:var(--blue);
  display:flex;
  align-items:center;
  justify-content:center;
  color:#fff;
  font-size:11px;
  font-weight:800;
  flex-shrink:0;
}
.tb-icon-btn{
  width:38px;
  height:38px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:1px solid var(--border);
  border-radius:10px;
  background:var(--surface);
  color:var(--text2);
  cursor:pointer;
  flex-shrink:0;
  transition:all .15s;
}
.tb-theme-btn{
  position:relative;
}
.tb-icon-btn:hover{
  background:var(--bg);
  border-color:var(--blue);
  color:var(--blue);
}
.tb-icon-btn svg{width:18px;height:18px;display:block}
.tb-drawer-backdrop{
  position:fixed;
  inset:0;
  z-index:900;
  background:rgba(15,23,42,.42);
  opacity:0;
  pointer-events:none;
  transition:opacity .18s ease;
}
.tb-drawer-backdrop.open{
  opacity:1;
  pointer-events:auto;
}
.tb-drawer{
  position:fixed;
  top:10px;
  bottom:10px;
  left:0;
  z-index:910;
  width:min(300px,84vw);
  background:var(--surface);
  border-right:1px solid var(--border);
  border-radius:0 14px 14px 0;
  box-shadow:14px 0 40px rgba(0,0,0,.16);
  transform:translateX(-102%);
  transition:transform .2s ease;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  overflow-x:hidden;
  overscroll-behavior:contain;
  scrollbar-gutter:stable;
}
.tb-drawer.open{transform:translateX(0)}
.tb-drawer-head{
  min-height:48px;
  padding:7px 10px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  border-bottom:1px solid var(--border);
  background:linear-gradient(180deg,#fff 0%,#fbfcfe 100%);
  flex:0 0 auto;
}
.tb-drawer-title{
  display:flex;
  align-items:center;
  gap:10px;
  font-size:13px;
  font-weight:800;
  color:var(--text);
}
.tb-drawer-body{
  padding:8px;
  min-height:0;
  flex:1 1 auto;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  scrollbar-gutter:stable;
  display:flex;
  flex-direction:column;
  gap:8px;
}
.tb-group{
  border:1px solid var(--border-light);
  border-radius:10px;
  overflow:hidden;
  background:#fff;
}
.tb-group-h{
  padding:7px 9px;
  font-size:10px;
  font-weight:800;
  color:var(--text3);
  text-transform:uppercase;
  letter-spacing:.5px;
  background:#fbfcfd;
  border-bottom:1px solid var(--border-light);
}
.tb-links{
  display:flex;
  flex-direction:column;
  padding:4px;
  gap:2px;
}
.tb-link,
.tb-action{
  display:flex;
  align-items:center;
  gap:8px;
  width:100%;
  padding:7px 8px;
  border-radius:8px;
  border:1px solid transparent;
  background:transparent;
  color:var(--text2);
  text-decoration:none;
  font:inherit;
  font-size:11.5px;
  font-weight:700;
  cursor:pointer;
}
.tb-link:hover,
.tb-action:hover{
  background:var(--bg);
  border-color:var(--border-light);
  color:var(--text);
}
.tb-link.primary{
  background:var(--blue-bg);
  border-color:#c9dcfe;
  color:var(--blue);
}
.tb-link svg,
.tb-action svg{
  width:16px;
  height:16px;
  flex-shrink:0;
}
.tb-drawer-foot{
  padding:8px;
  border-top:1px solid var(--border);
  background:#fbfcfd;
  display:flex;
  flex-direction:column;
  gap:6px;
  flex:0 0 auto;
}
.tb-user-card{
  display:flex;
  align-items:center;
  gap:10px;
  padding:8px 9px;
  border:1px solid var(--border-light);
  border-radius:9px;
  background:#fff;
}
.tb-user-card .tb-avatar{width:32px;height:32px}
.tb-user-meta{min-width:0}
.tb-user-meta strong{display:block;font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tb-user-meta span{display:block;font-size:11px;color:var(--text3);margin-top:1px}
.tb-close{
  width:38px;
  height:38px;
  border-radius:10px;
}
.tb-edge-zone{
  position:fixed;
  left:0;
  top:0;
  width:14px;
  height:100vh;
  z-index:890;
  background:transparent;
}
.tb-edge-zone::after{
  content:'';
  position:absolute;
  inset:0 auto 0 0;
  width:3px;
  background:linear-gradient(180deg,rgba(24,119,242,.18),rgba(24,119,242,.06));
  opacity:.55;
}
.tb-edge-zone:hover::after{
  opacity:1;
  width:4px;
}
[data-theme="dark"] .tb-drawer-head{
  background:linear-gradient(180deg,#111827 0%,#0f172a 100%);
}
[data-theme="dark"] .tb-group{
  background:#111827;
}
[data-theme="dark"] .tb-group-h{
  background:#0f172a;
}
[data-theme="dark"] .tb-drawer-foot{
  background:#0f172a;
}
[data-theme="dark"] .tb-user-card{
  background:#111827;
}
[data-theme="dark"] .tb-link.primary{
  background:rgba(24,119,242,.16);
  border-color:rgba(96,165,250,.35);
}
[data-theme="dark"] .tb-edge-zone::after{
  background:linear-gradient(180deg,rgba(96,165,250,.32),rgba(96,165,250,.08));
}
@media (max-width: 900px){
  .tb-shell{padding:10px 12px}
  .tb-logo{font-size:16px}
}
@media (max-width: 680px){
  .tb-shell{gap:8px}
  .tb-logo{width:auto}
  .tb-logo{font-size:15px}
  .tb-sync{display:none}
  .tb-user span{display:none}
  .tb-drawer{
    width:100vw;
    top:0;
    bottom:0;
    border-radius:0;
  }
  .tb-edge-zone{display:none}
}
</style>

<div class="topbar">
  <div class="tb-shell">
    <div class="tb-left">
      <button class="tb-icon-btn" type="button" id="tbMenuBtn" aria-label="Open menu" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <line x1="4" y1="7" x2="20" y2="7"></line>
          <line x1="4" y1="12" x2="20" y2="12"></line>
          <line x1="4" y1="17" x2="20" y2="17"></line>
        </svg>
      </button>
      <a class="tb-logo" href="/" style="text-decoration:none">
        <div class="tb-logo-icon">
          <svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </div>
        Ads Dashboard
      </a>
      <?php if ($currentPage === 'index'): ?>
      <span class="tb-sync" id="syncLabel">Loading...</span>
      <?php endif ?>
    </div>

    <div class="tb-right">
      <div class="tb-user">
        <div class="tb-avatar"><?= mb_strtoupper(mb_substr($me['display_name'], 0, 1)) ?></div>
        <span><?= htmlspecialchars($me['display_name']) ?></span>
      </div>
      <button class="tb-icon-btn tb-theme-btn" type="button" id="tbThemeBtn" aria-label="Toggle dark theme" title="Toggle dark theme">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="tbThemeIcon">
          <path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"></path>
        </svg>
      </button>
      <?php if ($impersonationAdmin): ?>
      <form method="post" action="/impersonate.php" style="display:inline">
        <input type="hidden" name="action" value="stop">
        <button type="submit" class="tb-icon-btn" title="Return to admin account <?= htmlspecialchars($impersonationAdmin['display_name']) ?>" aria-label="Return to admin account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18l-6-6 6-6"></path>
            <path d="M3 12h12"></path>
            <path d="M21 5v14"></path>
          </svg>
        </button>
      </form>
      <?php endif ?>
      <a href="/logout.php" class="tb-icon-btn" title="Logout" aria-label="Logout">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10 17l5-5-5-5"></path>
          <path d="M15 12H3"></path>
          <path d="M21 3v18"></path>
        </svg>
      </a>
    </div>
  </div>
</div>

<div class="tb-drawer-backdrop" id="tbDrawerBackdrop" hidden></div>
<div class="tb-edge-zone" id="tbEdgeZone" aria-hidden="true"></div>
<aside class="tb-drawer" id="tbDrawer" aria-hidden="true">
  <div class="tb-drawer-head">
    <div class="tb-drawer-title">
      <div class="tb-logo-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
      </div>
      Menu
    </div>
    <button class="tb-icon-btn tb-close" type="button" id="tbDrawerClose" aria-label="Close menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <line x1="6" y1="6" x2="18" y2="18"></line>
        <line x1="18" y1="6" x2="6" y2="18"></line>
      </svg>
    </button>
  </div>

  <div class="tb-drawer-body">
    <div class="tb-group">
      <div class="tb-group-h">Main</div>
      <div class="tb-links">
        <a href="/" class="tb-link <?= $currentPage==='index' && (($_GET['view'] ?? '') !== 'tasks')?'primary':'' ?>">Dashboard</a>
        <a href="/?view=tasks" class="tb-link <?= $currentPage==='index' && (($_GET['view'] ?? '') === 'tasks')?'primary':'' ?>">Tasks</a>
        <a href="/campaign_builder.php" class="tb-link <?= $currentPage==='campaign_builder'?'primary':'' ?>">Campaign Builder</a>
        <a href="/campaign_builder2.php" class="tb-link <?= $currentPage==='campaign_builder2'?'primary':'' ?>">Campaign Builder 2</a>
        <a href="/global_log.php" class="tb-link <?= $currentPage==='global_log'?'primary':'' ?>">Global Log</a>
        <a href="/collations.php" class="tb-link <?= $currentPage==='collations'?'primary':'' ?>">Collations</a>
        <!-- Creo Texts temporarily hidden by request; keep the route/file for possible future use.
        <a href="/creo_texts.php" class="tb-link <?= $currentPage==='creo_texts'?'primary':'' ?>">Creo Texts</a>
        -->
        <a href="/domains.php" class="tb-link <?= $currentPage==='domains'?'primary':'' ?>">Domains & FP</a>
        <?php if ($me['role'] === 'admin'): ?>
        <a href="/fanpage_data.php" class="tb-link <?= $currentPage==='fanpage_data'?'primary':'' ?>">Fanpage Data</a>
        <a href="/api_sync_logs.php" class="tb-link <?= $currentPage==='api_sync_logs'?'primary':'' ?>">API Sync Logs</a>
        <?php endif ?>
      </div>
    </div>

    <?php if ($me['role'] === 'admin'): ?>
    <div class="tb-group">
      <div class="tb-group-h">Admin</div>
      <div class="tb-links">
        <a href="/admin/users.php" class="tb-link <?= $currentPage==='users'?'primary':'' ?>">Users</a>
        <a href="/admin/accounts.php" class="tb-link <?= in_array($currentPage, ['accounts','bm'], true)?'primary':'' ?>">Accounts</a>
        <a href="/rules_check.php" class="tb-link <?= $currentPage==='rules_check'?'primary':'' ?>">Rules Check</a>
        <a href="/creative_previews.php" class="tb-link <?= $currentPage==='creative_previews'?'primary':'' ?>">Creative Info</a>
      </div>
    </div>
    <?php endif ?>

    <?php if ($currentPage === 'index'): ?>
    <div class="tb-group">
      <div class="tb-group-h">Tools</div>
      <div class="tb-links">
        <button class="tb-action" type="button" id="btnBalances" onclick="openBalances()">Balances</button>
        <button class="tb-action" type="button" onclick="openBalanceOffers()">Offer Balancing</button>
        <button class="tb-action" type="button" onclick="openGeoMetrics()">Geo Metrics</button>
      </div>
    </div>
    <?php endif ?>
  </div>

  <div class="tb-drawer-foot">
    <div class="tb-user-card">
      <div class="tb-avatar"><?= mb_strtoupper(mb_substr($me['display_name'], 0, 1)) ?></div>
      <div class="tb-user-meta">
        <strong><?= htmlspecialchars($me['display_name']) ?></strong>
        <span><?= htmlspecialchars($me['role']) ?></span>
      </div>
    </div>
    <?php if ($impersonationAdmin): ?>
    <form method="post" action="/impersonate.php">
      <input type="hidden" name="action" value="stop">
      <button type="submit" class="tb-action" style="justify-content:center;background:var(--red-bg);color:var(--red);border-color:#f4c5c5">Return to admin</button>
    </form>
    <?php endif ?>
    <a href="/logout.php" class="tb-action" style="justify-content:center;background:var(--bg);border-color:var(--border-light)">Logout</a>
  </div>
</aside>

<script>
(function () {
  const storageKey = 'fb_ads_theme';
  const root = document.documentElement;
  const btn = document.getElementById('tbThemeBtn');
  const icon = document.getElementById('tbThemeIcon');
  if (!btn || !icon) return;

  function updateIcon(theme) {
    if (theme === 'dark') {
      icon.innerHTML = '<circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.93 4.93l1.41 1.41"></path><path d="M17.66 17.66l1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M4.93 19.07l1.41-1.41"></path><path d="M17.66 6.34l1.41-1.41"></path>';
      btn.setAttribute('aria-label', 'Switch to light theme');
      btn.setAttribute('title', 'Switch to light theme');
      return;
    }
    icon.innerHTML = '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"></path>';
    btn.setAttribute('aria-label', 'Switch to dark theme');
    btn.setAttribute('title', 'Switch to dark theme');
  }

  function applyTheme(theme, persist = true) {
    const nextTheme = theme === 'dark' ? 'dark' : 'light';
    root.dataset.theme = nextTheme;
    root.style.colorScheme = nextTheme;
    updateIcon(nextTheme);
    if (persist) localStorage.setItem(storageKey, nextTheme);
  }

  const storedTheme = localStorage.getItem(storageKey);
  const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  applyTheme(storedTheme === 'dark' || storedTheme === 'light' ? storedTheme : (prefersDark ? 'dark' : 'light'), false);

  btn.addEventListener('click', () => {
    applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
  });

  window.addEventListener('storage', e => {
    if (e.key === storageKey && (e.newValue === 'dark' || e.newValue === 'light')) {
      applyTheme(e.newValue, false);
    }
  });
})();

(function () {
  const btn = document.getElementById('tbMenuBtn');
  const drawer = document.getElementById('tbDrawer');
  const drawerBody = drawer ? drawer.querySelector('.tb-drawer-body') : null;
  const backdrop = document.getElementById('tbDrawerBackdrop');
  const closeBtn = document.getElementById('tbDrawerClose');
  const edgeZone = document.getElementById('tbEdgeZone');
  if (!btn || !drawer || !drawerBody || !backdrop || !closeBtn || !edgeZone) return;

  let pinned = false;
  let hoverTimer = null;

  function clearHoverTimer() {
    if (hoverTimer) {
      window.clearTimeout(hoverTimer);
      hoverTimer = null;
    }
  }

  function openDrawer(options = {}) {
    const { pinnedOpen = false } = options;
    pinned = pinnedOpen;
    drawer.classList.add('open');
    if (pinnedOpen) {
      backdrop.hidden = false;
      requestAnimationFrame(() => backdrop.classList.add('open'));
    } else {
      backdrop.classList.remove('open');
      backdrop.hidden = true;
    }
    drawer.setAttribute('aria-hidden', 'false');
    btn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = pinnedOpen ? 'hidden' : '';
    edgeZone.style.pointerEvents = 'auto';
  }

  function closeDrawer() {
    pinned = false;
    clearHoverTimer();
    drawer.classList.remove('open');
    backdrop.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    btn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    window.setTimeout(() => {
      if (!backdrop.classList.contains('open')) backdrop.hidden = true;
    }, 220);
  }

  function hoverOpen() {
    if (pinned || window.matchMedia('(hover: none)').matches) return;
    clearHoverTimer();
    openDrawer({ pinnedOpen: false });
  }

  function hoverClose() {
    if (pinned || window.matchMedia('(hover: none)').matches) return;
    clearHoverTimer();
    hoverTimer = window.setTimeout(() => {
      if (!pinned) closeDrawer();
    }, 120);
  }

  btn.addEventListener('click', () => {
    if (drawer.classList.contains('open') && pinned) {
      closeDrawer();
      return;
    }
    clearHoverTimer();
    openDrawer({ pinnedOpen: true });
  });
  closeBtn.addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDrawer();
  });

  edgeZone.addEventListener('pointerenter', hoverOpen);
  edgeZone.addEventListener('pointerleave', hoverClose);
  drawer.addEventListener('pointerenter', hoverOpen);
  drawer.addEventListener('pointerleave', hoverClose);
  btn.addEventListener('pointerenter', hoverOpen);

  drawer.querySelectorAll('a, button').forEach(el => {
    el.addEventListener('click', () => {
      if (el.closest('form') && el.type === 'submit') return;
      if (el.tagName === 'A' || el.tagName === 'BUTTON') closeDrawer();
    });
  });

  drawerBody.addEventListener('wheel', e => {
    if (!drawer.classList.contains('open')) return;
    const max = drawerBody.scrollHeight - drawerBody.clientHeight;
    if (max <= 0) return;
    e.stopPropagation();
  }, { passive: true });
})();
</script>
