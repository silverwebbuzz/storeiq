<?php
require_once __DIR__ . '/lib/admin_auth.php';
requireAdminAuth();

$rows = DBHelper::select(
    "SELECT s.id, s.shop, s.store_name, s.status, s.created_at, s.uninstalled_at,
            p.name AS plan_key, p.display_name AS plan_name
     FROM stores s
     LEFT JOIN plans p ON p.id = s.plan_id
     ORDER BY s.id DESC LIMIT 500"
) ?: [];
$plans = DBHelper::select("SELECT id, display_name FROM plans ORDER BY sort_order") ?: [];
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Shops — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>Shops</h1>
  <div class="admin-card">
    <table class="admin-table">
      <thead><tr><th>Shop</th><th>Name</th><th>Plan</th><th>Status</th><th>Installed</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-shop-id="<?php echo (int)$r['id']; ?>">
          <td><?php echo htmlspecialchars((string)$r['shop'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)($r['store_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
            <select class="js-plan-select" data-shop-id="<?php echo (int)$r['id']; ?>">
              <?php foreach ($plans as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$p['id']) === (int)(DBHelper::selectOne("SELECT plan_id FROM stores WHERE id=?", 'i', [(int)$r['id']])['plan_id'] ?? 1) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string)$p['display_name'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><?php echo htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$r['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><button class="btn btn-primary js-save-plan" data-shop-id="<?php echo (int)$r['id']; ?>">Save plan</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</div>
<script>
document.querySelectorAll('.js-save-plan').forEach(function (b) {
  b.addEventListener('click', function () {
    var sid = parseInt(b.getAttribute('data-shop-id'), 10);
    var sel = document.querySelector('.js-plan-select[data-shop-id="' + sid + '"]');
    fetch('api/shops/update-plan.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ shop_id: sid, plan_id: parseInt(sel.value, 10) })
    }).then(function (r) { return r.json(); }).then(function (d) {
      b.textContent = d.success ? 'Saved' : 'Error';
      setTimeout(function () { b.textContent = 'Save plan'; }, 1500);
    });
  });
});
</script>
</body></html>
