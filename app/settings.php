<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';

[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

$pageTitle = 'Settings';
$pageJs    = 'settings';
$planKey   = (string)($entitlements['plan_key'] ?? 'free');
$planLabel = (string)($entitlements['plan_label'] ?? 'Free');
$limits    = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$canPromo  = canAccess((string)$shop, 'cross_app_promo');

require __DIR__ . '/partials/header.php';
require __DIR__ . '/nav.php';
?>
<main class="siq-main">
  <h1 class="siq-page-title">Settings</h1>

  <div class="siq-card">
    <div class="siq-card__title">Your plan</div>
    <div class="siq-flex-between">
      <div>
        <?php echo siq_plan_badge($planKey); ?>
        <div class="siq-mt-16">
          <div>Bulk edit cap: <b><?php echo (int)($limits['max_products_per_task'] ?? 0) === 0 ? 'Unlimited' : (int)$limits['max_products_per_task']; ?></b> products per task</div>
          <div>Active campaigns: <b><?php echo (int)($limits['max_active_campaigns'] ?? 0) === 0 ? 'Unlimited' : (int)$limits['max_active_campaigns']; ?></b></div>
          <div>Hygiene rules: <b><?php echo (int)($limits['max_hygiene_rules'] ?? 0) === 0 ? 'Unlimited' : (int)$limits['max_hygiene_rules']; ?></b></div>
          <div>Undo window: <b><?php echo (int)($limits['undo_history_days'] ?? 0); ?></b> days</div>
        </div>
      </div>
      <div>
        <?php if ($planKey !== 'pro'): ?>
          <a class="btn btn-primary plan-change-cta" href="<?php echo siq_escape_html(siq_upgrade_url((string)$shop, (string)$host, 'pro')); ?>">Upgrade</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($canPromo): ?>
  <div class="siq-card" id="settings-promo-card" style="background:#fffbeb;border-color:#fde68a;">
    <div class="siq-card__title">SalesBoost AI perk</div>
    <div id="settings-promo-body" class="siq-muted">Loading…</div>
  </div>
  <?php endif; ?>

  <div class="siq-card">
    <div class="siq-card__title">Preferences</div>
    <label class="siq-flex">
      <input type="checkbox" id="pref-digest" checked>
      <span>Send weekly hygiene digest emails on Mondays</span>
    </label>
  </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
