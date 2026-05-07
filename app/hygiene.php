<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';

[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

$pageTitle = 'Store Hygiene';
$pageJs    = 'hygiene';
$maxRules = getLimit((string)$shop, 'max_hygiene_rules');

require __DIR__ . '/partials/header.php';
require __DIR__ . '/nav.php';
?>
<main class="siq-main">
  <h1 class="siq-page-title">Store Hygiene</h1>
  <div class="siq-page-sub">Catch broken products, missing SEO, dead stock and pricing mistakes — automatically.</div>

  <div class="siq-tabs">
    <div class="siq-tab active" data-tab="health">Store Health</div>
    <div class="siq-tab" data-tab="rules">Rules</div>
  </div>

  <section data-tab-pane="health">
    <div class="siq-card">
      <div class="siq-flex-between">
        <div>
          <div class="siq-card__title">Health score</div>
          <div class="siq-muted" id="hyg-last-scan">No scan yet.</div>
        </div>
        <button class="btn btn-primary btn-sm" id="hyg-scan-btn" type="button">Run scan now</button>
      </div>
      <div id="hyg-health-circle" class="siq-mt-24"></div>
    </div>

    <div class="siq-card">
      <div class="siq-card__title">Open issues</div>
      <div id="hyg-flags" class="siq-empty">Loading…</div>
    </div>
  </section>

  <section data-tab-pane="rules" style="display:none;">
    <div class="siq-card">
      <div class="siq-card__title">Rules <span class="siq-muted">(plan limit: <?php echo (int)$maxRules; ?>)</span></div>
      <div id="hyg-rules" class="siq-empty">Loading…</div>
    </div>
  </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
