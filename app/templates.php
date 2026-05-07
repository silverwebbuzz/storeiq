<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';

[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

$pageTitle = 'Templates';
$pageJs    = 'templates';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/nav.php';
?>
<main class="siq-main">
  <h1 class="siq-page-title">Templates</h1>
  <div class="siq-page-sub">Browse pre-built bulk edit and campaign templates.</div>

  <div class="siq-tabs">
    <div class="siq-tab active" data-tab="bulk">Bulk edit templates</div>
    <div class="siq-tab" data-tab="campaign">Campaign templates</div>
  </div>

  <section data-tab-pane="bulk">
    <div class="siq-card">
      <div class="siq-card__title">Bulk edit templates</div>
      <div id="tpl-bulk" class="siq-empty">Loading…</div>
    </div>
  </section>

  <section data-tab-pane="campaign" style="display:none;">
    <div class="siq-card">
      <div class="siq-card__title">Campaign templates</div>
      <div id="tpl-campaign" class="siq-empty">Loading…</div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
