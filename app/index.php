<?php
require_once __DIR__ . '/config.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = rtrim((string)BASE_URL, '/') . '/dashboard' . ($qs !== '' ? ('?' . $qs) : '');
header('Location: ' . $target);
exit;
