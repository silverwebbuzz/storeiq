<?php
require_once __DIR__ . '/lib/admin_auth.php';
$admin = requireAdminAuth();

$stats = [
    'installs'   => (int)(DBHelper::selectOne("SELECT COUNT(*) AS c FROM stores WHERE status='installed'")['c'] ?? 0),
    'uninstalls' => (int)(DBHelper::selectOne("SELECT COUNT(*) AS c FROM stores WHERE status='uninstalled'")['c'] ?? 0),
    'pending_jobs' => (int)(DBHelper::selectOne("SELECT COUNT(*) AS c FROM job_queue WHERE status='pending'")['c'] ?? 0),
    'failed_jobs'  => (int)(DBHelper::selectOne("SELECT COUNT(*) AS c FROM job_queue WHERE status='failed'")['c'] ?? 0),
];
$byPlan = DBHelper::select(
    "SELECT p.display_name, COUNT(s.id) AS shops
     FROM plans p LEFT JOIN stores s ON s.plan_id = p.id AND s.status='installed'
     GROUP BY p.id ORDER BY p.sort_order"
) ?: [];
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Dashboard — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>Dashboard</h1>
  <div class="admin-card">
    <h3>Installs</h3>
    <table class="admin-table">
      <tr><td>Active</td><td><?php echo $stats['installs']; ?></td></tr>
      <tr><td>Uninstalled</td><td><?php echo $stats['uninstalls']; ?></td></tr>
      <tr><td>Pending jobs</td><td><?php echo $stats['pending_jobs']; ?></td></tr>
      <tr><td>Failed jobs</td><td><?php echo $stats['failed_jobs']; ?></td></tr>
    </table>
  </div>
  <div class="admin-card">
    <h3>Shops by plan</h3>
    <table class="admin-table">
      <tr><th>Plan</th><th>Active shops</th></tr>
      <?php foreach ($byPlan as $r): ?>
        <tr><td><?php echo htmlspecialchars((string)$r['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo (int)$r['shops']; ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
</main>
</div></body></html>
