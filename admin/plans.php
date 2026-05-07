<?php
require_once __DIR__ . '/lib/admin_auth.php';
requireAdminAuth();

$rows = DBHelper::select(
    "SELECT p.id, p.name, p.display_name, l.*
     FROM plans p
     JOIN plan_limits l ON l.plan_id = p.id
     ORDER BY p.sort_order ASC"
) ?: [];

$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    if ($planId > 0) {
        $cols = [
            'max_products_per_task' => 'i',
            'max_active_campaigns' => 'i',
            'can_schedule' => 'i',
            'can_auto_revert' => 'i',
            'can_recurring_campaigns' => 'i',
            'can_save_custom_templates' => 'i',
            'can_multiphase_campaigns' => 'i',
            'can_campaign_analytics' => 'i',
            'can_campaign_calendar' => 'i',
            'can_order_tagging' => 'i',
            'can_customer_tagging' => 'i',
            'can_formula_pricing' => 'i',
            'can_cross_app_promo' => 'i',
            'can_staff_activity_log' => 'i',
            'max_hygiene_rules' => 'i',
            'max_system_templates' => 'i',
            'undo_history_days' => 'i',
        ];
        $sets = [];
        $types = '';
        $vals = [];
        foreach ($cols as $c => $t) {
            $sets[] = "`{$c}` = ?";
            $types .= $t;
            $vals[] = (int)($_POST[$c] ?? 0);
        }
        $types .= 'i';
        $vals[] = $planId;
        DBHelper::execute("UPDATE plan_limits SET " . implode(',', $sets) . " WHERE plan_id = ?", $types, $vals);
        $saved = true;
        $rows = DBHelper::select(
            "SELECT p.id, p.name, p.display_name, l.*
             FROM plans p JOIN plan_limits l ON l.plan_id = p.id
             ORDER BY p.sort_order ASC"
        ) ?: [];
    }
}
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Plans — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>Plans &amp; limits</h1>
  <?php if ($saved): ?><div class="admin-card" style="background:#dcfce7;color:#166534;">Saved.</div><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="admin-card">
      <h3><?php echo htmlspecialchars((string)$r['display_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?>)</h3>
      <form method="post" action="">
        <input type="hidden" name="plan_id" value="<?php echo (int)$r['id']; ?>">
        <table class="admin-table">
          <?php
          $cols = ['max_products_per_task','max_active_campaigns','can_schedule','can_auto_revert','can_recurring_campaigns',
                   'can_save_custom_templates','can_multiphase_campaigns','can_campaign_analytics','can_campaign_calendar',
                   'can_order_tagging','can_customer_tagging','can_formula_pricing','can_cross_app_promo',
                   'can_staff_activity_log','max_hygiene_rules','max_system_templates','undo_history_days'];
          foreach ($cols as $c): ?>
            <tr>
              <td><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></td>
              <td><input type="number" name="<?php echo $c; ?>" value="<?php echo (int)($r[$c] ?? 0); ?>" min="0"></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </div>
  <?php endforeach; ?>
</main>
</div></body></html>
