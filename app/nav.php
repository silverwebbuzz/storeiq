<?php
// Plan modal needs siq_upgrade_url(); ensure UI helpers are loaded even on pages that skip them.
require_once __DIR__ . '/lib/ui.php';

$rawShopForPlan = (isset($shop) && is_string($shop) && $shop !== '')
  ? $shop
  : ($_GET['shop'] ?? null);
$shopForPlan = sanitizeShopDomain($rawShopForPlan);
$hostForPlan = (isset($host) && is_string($host) && $host !== '')
  ? $host
  : (isset($_GET['host']) && is_string($_GET['host']) ? $_GET['host'] : '');
if ($hostForPlan === '' && $shopForPlan) {
  $rec = getShopByDomain((string)$shopForPlan);
  $hostForPlan = is_array($rec) ? (string)($rec['host'] ?? '') : '';
}
$planKey = $shopForPlan ? getCurrentPlanKey($shopForPlan) : 'free';
$planLabel = siq_plan_label($planKey);
$isProPlan = ($planKey === 'pro');

$planUrlFree    = siq_upgrade_url((string)$shopForPlan, $hostForPlan, 'free');
$planUrlStarter = siq_upgrade_url((string)$shopForPlan, $hostForPlan, 'starter');
$planUrlGrowth  = siq_upgrade_url((string)$shopForPlan, $hostForPlan, 'growth');
$planUrlPro     = siq_upgrade_url((string)$shopForPlan, $hostForPlan, 'pro');
?>

<nav class="top-nav" aria-label="Primary">
  <div class="top-nav__brand">
    <span class="top-nav__brand-name">StoreIQ</span>
  </div>
  <div class="top-nav__center" aria-label="Main navigation tabs">
    <ul class="top-nav__menu">
      <li><a id="nav-dashboard"  class="top-nav__link">Dashboard</a></li>
      <li><a id="nav-bulk-edit"  class="top-nav__link">Bulk Edit</a></li>
      <li><a id="nav-campaigns"  class="top-nav__link">Campaigns</a></li>
      <li><a id="nav-hygiene"    class="top-nav__link">Store Hygiene</a></li>
      <li><a id="nav-templates"  class="top-nav__link">Templates</a></li>
      <li><a id="nav-settings"   class="top-nav__link">Settings</a></li>
    </ul>
  </div>

  <div class="top-nav__right">
    <button id="nav-plan-trigger" class="top-nav__plan-badge top-nav__plan-badge--clickable top-nav__plan-badge--<?php echo siq_escape_html($planKey); ?>" type="button">
      Plan: <?php echo siq_escape_html($planLabel); ?>
    </button>
    <?php if ($planKey === 'free'): ?>
      <a class="top-nav__upgrade-cta plan-change-cta" href="<?php echo siq_escape_html($planUrlStarter); ?>">Upgrade</a>
    <?php endif; ?>
  </div>
</nav>

<div class="sb-modal" id="planCompareModal" aria-hidden="true">
  <div class="sb-modal__panel sb-plan-modal" role="dialog" aria-modal="true" aria-labelledby="planCompareTitle">
    <div class="sb-modal__head">
      <div>
        <div class="sb-modal__title" id="planCompareTitle">Choose your StoreIQ plan</div>
        <div class="sb-modal__meta">Compare plans and change anytime.</div>
      </div>
      <button class="sb-modal__close" type="button" id="planCompareClose">Close</button>
    </div>
    <div class="sb-modal__body">
      <div class="sb-plan-grid">
        <div class="sb-plan-card <?php echo $planKey === 'free' ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Free</div>
          <div class="sb-plan-copy">Try bulk edit and weekly hygiene scans on small catalogs.</div>
          <ul class="sb-plan-features">
            <li>Up to 50 products per bulk edit</li>
            <li>1 active campaign (manual only)</li>
            <li>3 hygiene rules</li>
            <li>7-day undo history</li>
          </ul>
          <div class="sb-plan-action">
            <?php if ($planKey === 'free'): ?>
              <span class="btn btn-ghost btn-sm is-current">Current plan</span>
            <?php else: ?>
              <a class="btn btn-primary btn-sm plan-change-cta" href="<?php echo siq_escape_html($planUrlFree); ?>">Change to Free</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="sb-plan-card <?php echo $planKey === 'starter' ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Starter — $9.99/mo</div>
          <div class="sb-plan-copy">Scheduling, more products and core hygiene.</div>
          <ul class="sb-plan-features">
            <li>Up to 1,000 products per bulk edit</li>
            <li>Schedule + auto-revert campaigns</li>
            <li>10 hygiene rules</li>
            <li>Weekly digest email</li>
          </ul>
          <div class="sb-plan-action">
            <a class="btn btn-primary btn-sm plan-change-cta <?php echo $planKey === 'starter' ? 'feature-disabled' : ''; ?>" href="<?php echo siq_escape_html($planUrlStarter); ?>">
              <?php echo $planKey === 'starter' ? 'Current plan' : 'Change to Starter'; ?>
            </a>
          </div>
        </div>
        <div class="sb-plan-card <?php echo $planKey === 'growth' ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Growth — $29.99/mo</div>
          <div class="sb-plan-copy">Recurring campaigns, custom templates, calendar.</div>
          <ul class="sb-plan-features">
            <li>Unlimited products per bulk edit</li>
            <li>Recurring + multiphase campaigns</li>
            <li>Save custom templates</li>
            <li>Cross-app SalesBoost AI promo</li>
          </ul>
          <div class="sb-plan-action">
            <a class="btn btn-primary btn-sm plan-change-cta <?php echo $planKey === 'growth' ? 'feature-disabled' : ''; ?>" href="<?php echo siq_escape_html($planUrlGrowth); ?>">
              <?php echo $planKey === 'growth' ? 'Current plan' : 'Change to Growth'; ?>
            </a>
          </div>
        </div>
        <div class="sb-plan-card <?php echo $isProPlan ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Pro — $49.99/mo</div>
          <div class="sb-plan-copy">All hygiene rules, formula pricing, staff audit log.</div>
          <ul class="sb-plan-features">
            <li>All hygiene rules + auto-fix</li>
            <li>Formula pricing engine</li>
            <li>Order &amp; customer tagging</li>
            <li>Staff activity log</li>
          </ul>
          <div class="sb-plan-action">
            <a class="btn btn-primary btn-sm plan-change-cta <?php echo $isProPlan ? 'feature-disabled' : ''; ?>" href="<?php echo siq_escape_html($planUrlPro); ?>">
              <?php echo $isProPlan ? 'Current plan' : 'Change to Pro'; ?>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  // Strip charge_id/hmac/signature once finalize has run on the server, so refresh doesn't re-fire.
  (function () {
    try {
      var u = new URL(window.location.href);
      if (u.searchParams.has('charge_id')) {
        u.searchParams.delete('charge_id');
        u.searchParams.delete('hmac');
        u.searchParams.delete('signature');
        history.replaceState({}, '', u.pathname + u.search + u.hash);
      }
    } catch (e0) {}
  })();
</script>
<script>
  (function () {
    if (!window.__siqAppBridgeInit) {
      window.__siqAppBridgeInit = true;

      var params0 = new URLSearchParams(window.location.search);
      var siqDebug = params0.get('debug') === '1';
      var siqConsole = (siqDebug && window.console) ? window.console : null;
      var host0 = params0.get('host') || <?php echo json_encode((string)$hostForPlan); ?>;

      var AppBridge = window['app-bridge'];
      var app = null;
      if (AppBridge && typeof AppBridge.createApp === 'function' && host0) {
        try {
          app = AppBridge.createApp({
            apiKey: <?php echo json_encode(SHOPIFY_API_KEY); ?>,
            host: host0,
            forceRedirect: false
          });
        } catch (eCreate) {
          if (siqConsole) siqConsole.error('App Bridge createApp failed', eCreate);
        }
      }
      window.__siqApp = app;

      window.getToken = async function getToken() {
        for (var attempt = 0; attempt < 8; attempt++) {
          try {
            var g = window.shopify;
            if (g && typeof g.idToken === 'function') {
              var t = await g.idToken();
              if (t) return t;
            }
          } catch (e1) {
            if (attempt === 7 && siqConsole) siqConsole.error('shopify.idToken failed', e1);
          }
          await new Promise(function (r) { setTimeout(r, 80); });
        }
        if (!app) return '';
        try {
          if (AppBridge && AppBridge.utilities && typeof AppBridge.utilities.getSessionToken === 'function') {
            return await AppBridge.utilities.getSessionToken(app);
          }
          if (AppBridge && typeof AppBridge.getSessionToken === 'function') {
            return await AppBridge.getSessionToken(app);
          }
        } catch (e2) {
          if (siqConsole) siqConsole.error('getSessionToken failed', e2);
        }
        return '';
      };

      window.authFetch = async function authFetch(url, options) {
        var opts = options || {};
        var token = null;
        for (var i = 0; i < 3 && !token; i++) {
          try {
            token = await window.getToken();
          } catch (e) {
            if (i === 2 && siqConsole) siqConsole.error("Token fetch failed", e);
          }
          if (!token && i < 2) {
            await new Promise(function (resolve) { setTimeout(resolve, 150); });
          }
        }
        if (!token && siqConsole) siqConsole.warn("No session token available");
        var headers = Object.assign({}, opts.headers || {});
        if (token) headers.Authorization = 'Bearer ' + token;
        opts.headers = headers;
        return fetch(url, opts);
      };

      // Embedded-safe top-level redirect for plan upgrades and external flows.
      window.siqOpenRemote = function siqOpenRemote(url) {
        try {
          var target = String(url || '');
          if (!target) return;
          if (window.top && window.top !== window) {
            window.top.location.href = target;
            return;
          }
          window.location.href = target;
        } catch (e) {
          window.location.href = String(url || '');
        }
      };
      // Backwards-compatible alias for any copy-pasted SalesBoost code.
      window.sbmOpenRemote = window.siqOpenRemote;
    }

    var params = new URLSearchParams(window.location.search);
    var shop = params.get("shop") || <?php echo json_encode((string)($shopForPlan ?? '')); ?>;
    var host = params.get("host") || <?php echo json_encode((string)$hostForPlan); ?>;

    var query = "?shop=" + encodeURIComponent(shop || "") + "&host=" + encodeURIComponent(host || "");

    var links = {
      'nav-dashboard':  'dashboard',
      'nav-bulk-edit':  'bulk-edit',
      'nav-campaigns':  'campaigns',
      'nav-hygiene':    'hygiene',
      'nav-templates':  'templates',
      'nav-settings':   'settings'
    };
    Object.keys(links).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.href = links[id] + query;
    });

    var planTrigger    = document.getElementById("nav-plan-trigger");
    var planModal      = document.getElementById("planCompareModal");
    var planModalClose = document.getElementById("planCompareClose");
    if (planTrigger && planModal) {
      var openPlanModal  = function () { planModal.classList.add('is-open'); planModal.setAttribute('aria-hidden', 'false'); };
      var closePlanModal = function () { planModal.classList.remove('is-open'); planModal.setAttribute('aria-hidden', 'true'); };
      planTrigger.addEventListener('click', openPlanModal);
      if (planModalClose) planModalClose.addEventListener('click', closePlanModal);
      planModal.addEventListener('click', function (ev) { if (ev.target === planModal) closePlanModal(); });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && planModal.classList.contains('is-open')) closePlanModal();
      });
    }

    // Ensure all upgrade/change-plan links use embedded-safe redirect.
    document.addEventListener('click', function (ev) {
      var target = ev.target;
      if (!target || typeof target.closest !== 'function') return;
      var anchor = target.closest('a[href]');
      if (!anchor) return;
      var href = String(anchor.getAttribute('href') || '');
      if (!href) return;
      var isPlanLink =
        anchor.classList.contains('plan-change-cta') ||
        anchor.classList.contains('feature-lock-cta') ||
        href.indexOf('/billing/subscribe') !== -1 ||
        href.indexOf('billing/subscribe') !== -1 ||
        href.indexOf('/pricing_plans') !== -1 ||
        href.indexOf('pricing_plans') !== -1;
      if (!isPlanLink) return;
      ev.preventDefault();
      window.siqOpenRemote(href);
    }, false);

    // Active state — match by route segment.
    var path = window.location.pathname.toLowerCase();
    var activeMap = [
      ['dashboard',  'nav-dashboard'],
      ['bulk-edit',  'nav-bulk-edit'],
      ['campaigns',  'nav-campaigns'],
      ['hygiene',    'nav-hygiene'],
      ['templates',  'nav-templates'],
      ['settings',   'nav-settings'],
      ['activity',   'nav-dashboard']
    ];
    activeMap.forEach(function (pair) {
      if (path.indexOf(pair[0]) !== -1) {
        var el = document.getElementById(pair[1]);
        if (el) el.classList.add('active');
      }
    });
  })();
</script>
