<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';

[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

$pageTitle = 'Bulk Edit';
$pageJs    = 'bulk-edit';
$canSchedule = canAccess((string)$shop, 'schedule');
$maxProducts = getLimit((string)$shop, 'max_products_per_task');
$undoDays    = getLimit((string)$shop, 'undo_history_days');

require __DIR__ . '/partials/header.php';
require __DIR__ . '/nav.php';
?>
<main class="siq-main">
  <h1 class="siq-page-title">Bulk Edit</h1>
  <div class="siq-page-sub">
    Reprice, retag and restock matching products in one job.
    Plan limit: <b><?php echo $maxProducts === 0 ? 'Unlimited' : (int)$maxProducts; ?></b> products per task.
    Undo window: <b><?php echo (int)$undoDays; ?> days</b>.
  </div>

  <div class="siq-tabs">
    <div class="siq-tab active" data-tab="new">New bulk edit</div>
    <div class="siq-tab" data-tab="history">Job history</div>
  </div>

  <section data-tab-pane="new">
    <div class="siq-card">
      <div class="siq-card__title">1. Filter products</div>
      <div class="siq-grid-2">
        <div class="siq-form-row">
          <label class="siq-label">Apply to</label>
          <select class="siq-select" id="be-filter-scope">
            <option value="all">All products</option>
            <option value="collection">Specific collection</option>
            <option value="tag">By tag</option>
            <option value="vendor">By vendor</option>
            <option value="product_type">By product type</option>
          </select>
        </div>
        <div class="siq-form-row">
          <label class="siq-label">Filter value (collection id, tag, etc.)</label>
          <input class="siq-input" id="be-filter-value" placeholder="e.g. apparel">
        </div>
      </div>
      <button class="btn btn-ghost btn-sm" id="be-preview-btn" type="button">Preview matching products</button>
      <div id="be-preview-result" class="siq-mt-16 siq-muted"></div>
    </div>

    <div class="siq-card">
      <div class="siq-card__title">2. Actions</div>
      <div id="be-actions-list" class="siq-empty">No actions yet.</div>
      <button class="btn btn-ghost btn-sm" id="be-add-action" type="button">+ Add action</button>
    </div>

    <div class="siq-card">
      <div class="siq-card__title">3. Run</div>
      <div class="siq-form-row">
        <label class="siq-label">Job name</label>
        <input class="siq-input" id="be-job-name" placeholder="e.g. Diwali price reduction">
      </div>
      <?php if (!$canSchedule): ?>
        <div class="siq-muted">Scheduling is available on Starter and above. <a href="settings?shop=<?php echo urlencode((string)$shop); ?>&host=<?php echo urlencode((string)$host); ?>">Upgrade</a> to schedule jobs.</div>
      <?php endif; ?>
      <div class="siq-mt-16">
        <button class="btn btn-primary" id="be-run-now" type="button">Run now</button>
      </div>
    </div>
  </section>

  <section data-tab-pane="history" style="display:none;">
    <div class="siq-card">
      <div class="siq-card__title">Past bulk jobs</div>
      <div id="be-history" class="siq-empty">Loading…</div>
    </div>
  </section>
</main>

<!-- Action template picker (inline modal) -->
<div class="sb-modal" id="be-template-modal" aria-hidden="true">
  <div class="sb-modal__panel" role="dialog">
    <div class="sb-modal__head">
      <div class="sb-modal__title">Pick an action</div>
      <button class="sb-modal__close" type="button" id="be-template-close">Close</button>
    </div>
    <div class="sb-modal__body" id="be-template-list">Loading…</div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
