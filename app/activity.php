<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';

[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

$pageTitle = 'Activity';
$canActivity = canAccess((string)$shop, 'staff_activity_log');

$shopId = (int)($shopRecord['id'] ?? 0);
$rows = $canActivity ? (DBHelper::select(
    "SELECT actor, action, entity_type, entity_id, summary, created_at
     FROM activity_log
     WHERE shop_id = ?
     ORDER BY id DESC LIMIT 50",
    'i', [$shopId]
) ?: []) : [];

require __DIR__ . '/partials/header.php';
require __DIR__ . '/nav.php';
?>
<main class="siq-main">
  <h1 class="siq-page-title">Activity log</h1>
  <?php if (!$canActivity): ?>
    <div class="siq-card feature-lock-overlay">
      <?php renderLockedFeatureBlock(
          'Staff activity log is a Pro feature',
          'Track who changed what — useful for teams and compliance.',
          'pro',
          null,
          (string)$shop,
          (string)$host
      ); ?>
    </div>
  <?php else: ?>
    <div class="siq-card">
      <table class="siq-table">
        <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Summary</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="siq-empty">No activity yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="siq-muted"><?php echo siq_escape_html((string)$r['created_at']); ?></td>
            <td><?php echo siq_escape_html((string)($r['actor'] ?? '')); ?></td>
            <td><?php echo siq_escape_html((string)$r['action']); ?></td>
            <td><?php echo siq_escape_html((string)($r['summary'] ?? '')); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
