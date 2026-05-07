<?php
require_once __DIR__ . '/lib/admin_auth.php';
requireAdminAuth();

$msg = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'create') {
        DBHelper::insert(
            "INSERT INTO bulk_action_templates (category, name, description, tag, actions_json, plan_required, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            'sssssi',
            [
                (string)$_POST['category'],
                (string)$_POST['name'],
                (string)$_POST['description'],
                (string)$_POST['tag'],
                (string)$_POST['actions_json'],
                (int)$_POST['plan_required'],
            ]
        );
        $msg = 'Template created.';
    } elseif ($op === 'toggle') {
        DBHelper::execute("UPDATE bulk_action_templates SET is_active = 1 - is_active WHERE id = ?", 'i', [(int)$_POST['id']]);
        $msg = 'Toggled.';
    } elseif ($op === 'update') {
        DBHelper::execute(
            "UPDATE bulk_action_templates
             SET name = ?, description = ?, tag = ?, plan_required = ?, category = ?
             WHERE id = ?",
            'sssisi',
            [
                (string)$_POST['name'], (string)$_POST['description'], (string)$_POST['tag'],
                (int)$_POST['plan_required'], (string)$_POST['category'], (int)$_POST['id']
            ]
        );
        $msg = 'Saved.';
    }
}

$rows = DBHelper::select(
    "SELECT id, category, name, description, tag, plan_required, is_active, sort_order
     FROM bulk_action_templates ORDER BY category, sort_order, id"
) ?: [];
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Bulk Templates — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>Bulk action templates</h1>
  <?php if ($msg): ?><div class="admin-card" style="background:#dcfce7;color:#166534;"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <div class="admin-card">
    <table class="admin-table">
      <thead><tr><th>ID</th><th>Category</th><th>Name</th><th>Tag</th><th>Plan</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <form method="post" action="">
            <input type="hidden" name="op" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <td><?php echo (int)$r['id']; ?></td>
            <td><input type="text" name="category" value="<?php echo htmlspecialchars((string)$r['category'], ENT_QUOTES, 'UTF-8'); ?>" size="10"></td>
            <td><input type="text" name="name" value="<?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?>" size="30"></td>
            <td><input type="text" name="tag" value="<?php echo htmlspecialchars((string)$r['tag'], ENT_QUOTES, 'UTF-8'); ?>" size="6"></td>
            <td><input type="number" name="plan_required" value="<?php echo (int)$r['plan_required']; ?>" min="1" max="4" style="width:60px;"></td>
            <td><?php echo (int)$r['is_active'] ? 'Yes' : 'No'; ?></td>
            <td>
              <input type="text" name="description" value="<?php echo htmlspecialchars((string)$r['description'], ENT_QUOTES, 'UTF-8'); ?>" size="20">
              <button class="btn" type="submit">Save</button>
            </td>
          </form>
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
    <h3>Add new template</h3>
    <form method="post" action="">
      <input type="hidden" name="op" value="create">
      <table class="admin-table">
        <tr><td>Category</td><td><input type="text" name="category" required></td></tr>
        <tr><td>Name</td><td><input type="text" name="name" required></td></tr>
        <tr><td>Description</td><td><input type="text" name="description"></td></tr>
        <tr><td>Tag</td><td>
          <select name="tag"><option>quick</option><option>smart</option><option>power</option><option>auto</option></select>
        </td></tr>
        <tr><td>Plan required (1=free, 4=pro)</td><td><input type="number" name="plan_required" value="1" min="1" max="4"></td></tr>
        <tr><td>Actions JSON</td><td><textarea name="actions_json" required>[{"action_type":"price_change_percent","params":{"direction":"decrease","value":10}}]</textarea></td></tr>
      </table>
      <button class="btn btn-primary" type="submit">Create</button>
    </form>
  </div>
</main>
</div></body></html>
