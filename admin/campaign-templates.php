<?php
require_once __DIR__ . '/lib/admin_auth.php';
requireAdminAuth();

$msg = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'create') {
        DBHelper::insert(
            "INSERT INTO campaign_templates
                (shop_id, name, description, category, event_month, event_day,
                 suggested_duration_days, default_discount_pct, actions_json,
                 is_system, is_active, plan_required)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)",
            'sssiiidsi',
            [
                (string)$_POST['name'], (string)$_POST['description'], (string)$_POST['category'],
                (int)($_POST['event_month'] ?: 0), (int)($_POST['event_day'] ?: 0),
                (int)$_POST['suggested_duration_days'], (float)$_POST['default_discount_pct'],
                (string)$_POST['actions_json'], (int)$_POST['plan_required']
            ]
        );
        $msg = 'Created.';
    } elseif ($op === 'toggle') {
        DBHelper::execute("UPDATE campaign_templates SET is_active = 1 - is_active WHERE id = ?", 'i', [(int)$_POST['id']]);
        $msg = 'Toggled.';
    }
}

$rows = DBHelper::select(
    "SELECT id, name, category, event_month, event_day, suggested_duration_days, default_discount_pct,
            is_system, is_active, plan_required, use_count
     FROM campaign_templates WHERE shop_id IS NULL
     ORDER BY category, event_month, sort_order, id"
) ?: [];
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Campaign Templates — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>Campaign templates (system)</h1>
  <?php if ($msg): ?><div class="admin-card" style="background:#dcfce7;color:#166534;"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <div class="admin-card">
    <table class="admin-table">
      <thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Event</th><th>Duration</th><th>Default %</th><th>Plan</th><th>Used</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$r['category'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo $r['event_month'] ? sprintf('%02d-%02d', (int)$r['event_month'], (int)$r['event_day']) : '—'; ?></td>
          <td><?php echo (int)$r['suggested_duration_days']; ?>d</td>
          <td><?php echo $r['default_discount_pct'] !== null ? (float)$r['default_discount_pct'] . '%' : '—'; ?></td>
          <td><?php echo (int)$r['plan_required']; ?></td>
          <td><?php echo (int)$r['use_count']; ?></td>
          <td><?php echo (int)$r['is_active'] ? 'Yes' : 'No'; ?></td>
          <td>
            <form method="post" action="" style="display:inline;">
              <input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
              <button class="btn" type="submit">Toggle</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="admin-card">
    <h3>Add system template</h3>
    <form method="post" action="">
      <input type="hidden" name="op" value="create">
      <table class="admin-table">
        <tr><td>Name</td><td><input type="text" name="name" required></td></tr>
        <tr><td>Description</td><td><input type="text" name="description"></td></tr>
        <tr><td>Category</td><td>
          <select name="category">
            <option value="india_festival">india_festival</option>
            <option value="global_event">global_event</option>
            <option value="seasonal">seasonal</option>
            <option value="business">business</option>
          </select>
        </td></tr>
        <tr><td>Event month / day</td><td>
          <input type="number" name="event_month" min="1" max="12" placeholder="MM" style="width:60px;">
          <input type="number" name="event_day"   min="1" max="31" placeholder="DD" style="width:60px;">
        </td></tr>
        <tr><td>Duration (days)</td><td><input type="number" name="suggested_duration_days" value="3"></td></tr>
        <tr><td>Default discount %</td><td><input type="text" name="default_discount_pct" value="20"></td></tr>
        <tr><td>Plan required</td><td><input type="number" name="plan_required" value="1" min="1" max="4"></td></tr>
        <tr><td>Actions JSON</td><td><textarea name="actions_json" required>[{"action_type":"price_change_percent","params":{"direction":"decrease","value":20}}]</textarea></td></tr>
      </table>
      <button class="btn btn-primary" type="submit">Create</button>
    </form>
  </div>
</main>
</div></body></html>
