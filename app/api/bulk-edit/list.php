<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$rows = DBHelper::select(
    "SELECT id, name, status, total_products, processed, failed_count, created_at, completed_at
     FROM bulk_jobs WHERE shop_id = ?
     ORDER BY id DESC LIMIT 20",
    'i', [$shopId]
) ?: [];

echo json_encode(['jobs' => $rows]);
