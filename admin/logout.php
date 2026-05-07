<?php
require_once __DIR__ . '/lib/admin_auth.php';
adminLogout();
header('Location: index.php');
exit;
