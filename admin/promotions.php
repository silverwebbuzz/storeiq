<?php
require_once __DIR__ . '/lib/admin_auth.php';
requireAdminAuth();

$msg = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    DBHelper::execute(
        "UPDATE cross_app_promotions
         SET headline = ?, description = ?, discount_pct = ?, cta_label = ?
         WHERE id = ?",
        'ssdsi',
        [(string)$_POST['headline'], (string)$_POST['description'], (float)$_POST['discount_pct'], (string)$_POST['cta_label'], (int)$_POST['id']]
    );
    $msg = 'Saved.';
}

$rows = DBHelper::select(
    "SELECT cp.*,
       (SELECT COUNT(*) FROM shop_promo_codes WHERE promotion_id = cp.id) AS generated_count,
       (SELECT COUNT(*) FROM shop_promo_codes WHERE promotion_id = cp.id AND status = 'claimed') AS claimed_count
     FROM cross_app_promotions cp
     ORDER BY cp.id"
) ?: [];
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Promotions — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>Cross-app promotions</h1>
  <?php if ($msg): ?><div class="admin-card" style="background:#dcfce7;color:#166534;"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="admin-card">
      <h3><?php echo htmlspecialchars((string)$r['app_name'], ENT_QUOTES, 'UTF-8'); ?> — Plan trigger <?php echo (int)$r['plan_trigger']; ?></h3>
      <div class="muted" style="color:#6b7280;font-size:12px;">Codes generated: <?php echo (int)$r['generated_count']; ?> · Claimed: <?php echo (int)$r['claimed_count']; ?></div>
      <form method="post" action="">
        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
        <table class="admin-table">
          <tr><td>Headline</td><td><input type="text" name="headline" value="<?php echo htmlspecialchars((string)$r['headline'], ENT_QUOTES, 'UTF-8'); ?>" size="50"></td></tr>
          <tr><td>Description</td><td><textarea name="description"><?php echo htmlspecialchars((string)$r['description'], ENT_QUOTES, 'UTF-8'); ?></textarea></td></tr>
          <tr><td>Discount %</td><td><input type="number" step="0.01" name="discount_pct" value="<?php echo (float)$r['discount_pct']; ?>"></td></tr>
          <tr><td>CTA label</td><td><input type="text" name="cta_label" value="<?php echo htmlspecialchars((string)$r['cta_label'], ENT_QUOTES, 'UTF-8'); ?>"></td></tr>
        </table>
        <button class="btn btn-primary" type="submit">Save</button>
      </form>
    </div>
  <?php endforeach; ?>
</main>
</div></body></html>
