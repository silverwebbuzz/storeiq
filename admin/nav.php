<?php
$admin = requireAdminAuth();
$current = basename($_SERVER['SCRIPT_NAME']);
$items = [
    'dashboard.php'         => 'Dashboard',
    'shops.php'             => 'Shops',
    'plans.php'             => 'Plans',
    'bulk-templates.php'    => 'Bulk Templates',
    'campaign-templates.php'=> 'Campaign Templates',
    'hygiene-rules.php'     => 'Hygiene Rules',
    'promotions.php'        => 'Promotions',
    'announcements.php'     => 'Announcements',
];
?>
<aside class="admin-nav">
  <div class="admin-brand">StoreIQ Admin</div>
  <ul>
  <?php foreach ($items as $href => $label): ?>
    <li class="<?php echo $current === $href ? 'active' : ''; ?>"><a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a></li>
  <?php endforeach; ?>
  </ul>
  <div class="admin-user">
    <div><?php echo htmlspecialchars((string)$admin['name'], ENT_QUOTES, 'UTF-8'); ?></div>
    <a href="logout.php">Sign out</a>
  </div>
</aside>
