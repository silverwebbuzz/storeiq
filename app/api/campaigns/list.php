<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$status = (string)($_GET['status'] ?? '');
$sql = "SELECT id, name, status, start_at, end_at, total_products, auto_revert, is_recurring, created_at
        FROM campaigns WHERE shop_id = ?";
$types = 'i';
$params = [$shopId];
if ($status !== '') {
    $sql .= " AND status = ?";
    $types .= 's';
    $params[] = $status;
}
$sql .= " ORDER BY start_at DESC LIMIT 100";

$rows = DBHelper::select($sql, $types, $params) ?: [];
echo json_encode(['campaigns' => $rows]);
