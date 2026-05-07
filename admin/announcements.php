<?php
require_once __DIR__ . '/lib/admin_auth.php';
requireAdminAuth();

$msg = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op = (string)($_POST['op'] ?? 'create');
    if ($op === 'create') {
        DBHelper::insert(
            "INSERT INTO announcements (title, body, type, target_plan, show_from, show_until, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            'ssssss',
            [
                (string)$_POST['title'], (string)$_POST['body'], (string)$_POST['type'],
                (string)$_POST['target_plan'],
                (string)($_POST['show_from'] ?: date('Y-m-d H:i:s')),
                (string)($_POST['show_until'] ?: '2099-12-31 23:59:59')
            ]
        );
        $msg = 'Created.';
    } elseif ($op === 'toggle') {
        DBHelper::execute("UPDATE announcements SET is_active = 1 - is_active WHERE id = ?", 'i', [(int)$_POST['id']]);
        $msg = 'Toggled.';
    }
}

$rows = DBHelper::select("SELECT * FROM announcements ORDER BY id DESC LIMIT 200") ?: [];
?>
<!doctype html><html><head><meta charset="UTF-8"><title>Announcements — StoreIQ Admin</title>
<link rel="stylesheet" href="assets/admin.css"></head><body>
<div class="admin-shell">
<?php require __DIR__ . '/nav.php'; ?>
<main class="admin-main">
  <h1>In-app announcements</h1>
  <?php if ($msg): ?><div class="admin-card" style="background:#dcfce7;color:#166534;"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <div class="admin-card">
    <h3>Create</h3>
    <form method="post" action="">
      <input type="hidden" name="op" value="create">
      <table class="admin-table">
        <tr><td>Title</td><td><input type="text" name="title" required></td></tr>
        <tr><td>Body</td><td><textarea name="body" required></textarea></td></tr>
        <tr><td>Type</td><td>
          <select name="type"><option>info</option><option>tip</option><option>warning</option><option>celebration</option></select>
        </td></tr>
        <tr><td>Target plan (key or "all")</td><td><input type="text" name="target_plan" value="all"></td></tr>
        <tr><td>Show from</td><td><input type="text" name="show_from" placeholder="YYYY-MM-DD HH:MM:SS"></td></tr>
        <tr><td>Show until</td><td><input type="text" name="show_until" placeholder="YYYY-MM-DD HH:MM:SS"></td></tr>
      </table>
      <button class="btn btn-primary" type="submit">Create</button>
    </form>
  </div>

  <div class="admin-card">
    <table class="admin-table">
      <thead><tr><th>ID</th><th>Title</th><th>Type</th><th>Target plan</th><th>Shows</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$r['type'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$r['target_plan'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$r['show_from'], ENT_QUOTES, 'UTF-8') . ' → ' . htmlspecialchars((string)$r['show_until'], ENT_QUOTES, 'UTF-8'); ?></td>
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
</main>
</div></body></html>
