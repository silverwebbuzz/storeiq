<?php
require_once __DIR__ . '/lib/admin_auth.php';

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (adminLogin($email, $pass)) {
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid email or password.';
}

if (getCurrentAdmin()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>StoreIQ Admin — Sign in</title>
<link rel="stylesheet" href="assets/admin.css">
</head><body>
<div class="login-box">
  <h1>StoreIQ Admin</h1>
  <?php if ($error): ?><div class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <form method="post" action="">
    <label>Email <input type="email" name="email" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Sign in</button>
  </form>
</div>
</body></html>
