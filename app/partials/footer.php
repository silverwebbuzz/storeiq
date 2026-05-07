<?php
$baseUrlForAssets = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
?>
<?php if (!empty($pageJs) && is_string($pageJs)): ?>
  <script src="<?php echo htmlspecialchars($baseUrlForAssets, ENT_QUOTES, 'UTF-8'); ?>/assets/<?php echo htmlspecialchars($pageJs, ENT_QUOTES, 'UTF-8'); ?>.js"></script>
<?php endif; ?>
</body>
</html>
