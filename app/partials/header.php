<?php
// Shared <head> opener for all StoreIQ pages.
// Pages are expected to set $pageTitle (string) and optionally $pageJs (filename without extension).
$pageTitleResolved = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== ''
    ? ($pageTitle . ' — StoreIQ')
    : 'StoreIQ';
$baseUrlForAssets = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
?><!doctype html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/app_bridge_first.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($pageTitleResolved, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrlForAssets, ENT_QUOTES, 'UTF-8'); ?>/assets/styles.css">
  <script>
    window.SIQ_BASE_URL = <?php echo json_encode($baseUrlForAssets); ?>;
    window.SIQ_API_BASE = window.SIQ_BASE_URL + '/api';
  </script>
  <script src="<?php echo htmlspecialchars($baseUrlForAssets, ENT_QUOTES, 'UTF-8'); ?>/assets/boot.js"></script>
</head>
<body class="siq-body">
