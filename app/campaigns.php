<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';

[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

$pageTitle = 'Campaigns';
$pageJs    = 'campaigns';
$canSchedule  = canAccess((string)$shop, 'schedule');
$canRecurring = canAccess((string)$shop, 'recurring_campaigns');
$canMultiphase = canAccess((string)$shop, 'multiphase_campaigns');
$canCalendar = canAccess((string)$shop, 'campaign_calendar');
$maxActive = getLimit((string)$shop, 'max_active_campaigns');

require __DIR__ . '/partials/header.php';
require __DIR__ . '/nav.php';
?>
<main class="siq-main">
  <div class="siq-flex-between">
    <div>
      <h1 class="siq-page-title">Campaigns</h1>
      <div class="siq-page-sub">Schedule price drops, sales and seasonal pushes — with auto-revert.</div>
    </div>
    <button class="btn btn-primary" id="new-campaign-btn" type="button" <?php echo $canSchedule ? '' : 'disabled'; ?>>+ New campaign</button>
  </div>

  <?php if (!$canSchedule): ?>
    <div class="siq-card" style="background:#fffbeb;border-color:#fde68a;">
      Scheduling is unlocked on <b>Starter and above</b>. <a class="plan-change-cta" href="settings?shop=<?php echo urlencode((string)$shop); ?>&host=<?php echo urlencode((string)$host); ?>">Upgrade your plan</a>.
    </div>
  <?php endif; ?>

  <div class="siq-tabs">
    <?php if ($canCalendar): ?><div class="siq-tab active" data-tab="calendar">Calendar</div><?php endif; ?>
    <div class="siq-tab <?php echo $canCalendar ? '' : 'active'; ?>" data-tab="list">List</div>
  </div>

  <?php if ($canCalendar): ?>
  <section data-tab-pane="calendar">
    <div class="siq-card">
      <div class="siq-flex-between">
        <div class="siq-card__title" id="cal-month-label">Loading…</div>
        <div>
          <button class="btn btn-ghost btn-sm" id="cal-prev" type="button">‹</button>
          <button class="btn btn-ghost btn-sm" id="cal-next" type="button">›</button>
        </div>
      </div>
      <div class="siq-calendar" id="cal-grid"></div>
    </div>
  </section>
  <?php endif; ?>

  <section data-tab-pane="list" <?php echo $canCalendar ? 'style="display:none;"' : ''; ?>>
    <div class="siq-card">
      <div class="siq-card__title">All campaigns</div>
      <div id="cmp-list" class="siq-empty">Loading…</div>
    </div>
  </section>
</main>

<!-- New campaign modal -->
<div class="sb-modal" id="cmp-modal" aria-hidden="true">
  <div class="sb-modal__panel" role="dialog">
    <div class="sb-modal__head">
      <div>
        <div class="sb-modal__title" id="cmp-modal-title">New campaign</div>
        <div class="sb-modal__meta" id="cmp-modal-step">Step 1 of 3 — pick a template</div>
      </div>
      <button class="sb-modal__close" type="button" id="cmp-modal-close">Close</button>
    </div>
    <div class="sb-modal__body" id="cmp-modal-body">Loading…</div>
  </div>
</div>

<script>
  window.SIQ_CAMPAIGN_FLAGS = <?php echo json_encode([
      'canRecurring'  => $canRecurring,
      'canMultiphase' => $canMultiphase,
      'maxActive'     => $maxActive,
  ]); ?>;
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
