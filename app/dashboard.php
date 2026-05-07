<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';

[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

$pageTitle = 'Dashboard';
$pageJs    = 'dashboard';
$shopName  = (string)($shopRecord['store_name'] ?? $shopRecord['shop'] ?? $shop);
$planKey   = (string)($entitlements['plan_key'] ?? 'free');
$planLabel = (string)($entitlements['plan_label'] ?? 'Free');
$canPromo  = canAccess((string)$shop, 'cross_app_promo');

require __DIR__ . '/partials/header.php';
require __DIR__ . '/nav.php';
?>
<main class="siq-main">
  <div class="siq-flex-between">
    <div>
      <h1 class="siq-page-title">Welcome back, <?php echo siq_escape_html($shopName); ?></h1>
      <div class="siq-page-sub">You're on the <?php echo siq_plan_badge($planKey); ?> plan.</div>
    </div>
  </div>

  <div class="siq-grid-2">
    <div class="siq-card">
      <div class="siq-card__title">Store health</div>
      <div id="dash-health" class="siq-empty">Loading…</div>
      <div class="siq-mt-16">
        <a class="btn btn-ghost btn-sm" id="dash-view-issues" href="#">View issues</a>
        <button class="btn btn-primary btn-sm" id="dash-scan-now" type="button">Scan store health</button>
      </div>
    </div>

    <div class="siq-card">
      <div class="siq-card__title">Upcoming campaigns</div>
      <div id="dash-upcoming" class="siq-empty">Loading…</div>
      <div class="siq-mt-16"><a class="btn btn-ghost btn-sm" id="dash-view-campaigns" href="#">View all</a></div>
    </div>
  </div>

  <div class="siq-grid-3">
    <div class="siq-card">
      <div class="siq-card__title">Run a bulk edit</div>
      <div class="siq-muted">Reprice, retag, or restock matching products in one go.</div>
      <div class="siq-mt-16"><a class="btn btn-primary btn-sm" id="quick-bulk-edit" href="#">Open bulk edit</a></div>
    </div>
    <div class="siq-card">
      <div class="siq-card__title">Schedule a campaign</div>
      <div class="siq-muted">Plan festive sales with auto-revert.</div>
      <div class="siq-mt-16"><a class="btn btn-primary btn-sm" id="quick-campaign" href="#">New campaign</a></div>
    </div>
    <div class="siq-card">
      <div class="siq-card__title">Active jobs</div>
      <div id="dash-active-jobs" class="siq-empty">Loading…</div>
    </div>
  </div>

  <div class="siq-card">
    <div class="siq-card__title">Recent activity</div>
    <div id="dash-activity" class="siq-empty">Loading…</div>
  </div>

  <?php if ($canPromo): ?>
  <div class="siq-card" id="dash-promo-card" style="display:none;border-color:#fde68a;background:#fffbeb;">
    <div class="siq-card__title">A perk for you</div>
    <div id="dash-promo-body" class="siq-muted">Loading…</div>
  </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
