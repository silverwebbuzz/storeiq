<?php
require_once __DIR__ . '/lib/admin_auth.php';
requireAdminAuth();

$msg = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op = (string)($_POST['op'] ?? '');
    if ($op === 'toggle') {
        DBHelper::execute("UPDATE hygiene_rule_definitions SET is_active = 1 - is_active WHERE id = ?", 'i', [(int)$_POST['id']]);
        $msg = 'Toggled.';
    } elseif ($op === 'update') {
        DBHelper::execute(
            "UPDATE hygiene_rule_definitions
             SET name = ?, description = ?, severity = ?, plan_required = ?
             WHERE id = ?",
            'sssii',
            [(string)$_POST['name'], (string)$_POST['description'], (string)$_POST['severity'], (int)$_POST['plan_required'], (int)$_POST['id']]
        );
        $msg = 'Saved.';
    }
}

$rows = DBHelper::select("SELECT * FROM hygiene_rule_definitions ORDER BY category, sort_order, id") ?: [];
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Hygiene Rules — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>Hygiene rules</h1>
  <?php if ($msg): ?><div class="admin-card" style="background:#dcfce7;color:#166534;"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <div class="admin-card">
    <table class="admin-table">
      <thead><tr><th>Code</th><th>Category</th><th>Name</th><th>Severity</th><th>Plan</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <form method="post" action="">
            <input type="hidden" name="op" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <td><code><?php echo htmlspecialchars((string)$r['code'], ENT_QUOTES, 'UTF-8'); ?></code></td>
            <td><?php echo htmlspecialchars((string)$r['category'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><input type="text" name="name" value="<?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?>" size="30"></td>
            <td>
              <select name="severity">
                <?php foreach (['info','warning','critical'] as $s): ?>
                  <option value="<?php echo $s; ?>" <?php echo $r['severity'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
              </select>
            </td>
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
</main>
</div></body></html>
