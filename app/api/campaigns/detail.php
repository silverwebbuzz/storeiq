<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$id = (int)($_GET['campaign_id'] ?? 0);
if (!$shop || $id <= 0) { http_response_code(400); echo json_encode(['error' => 'bad_request']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$campaign = DBHelper::selectOne(
    "SELECT * FROM campaigns WHERE id = ? AND shop_id = ? LIMIT 1",
    'ii', [$id, $shopId]
);
if (!$campaign) { http_response_code(404); echo json_encode(['error' => 'not_found']); exit; }

$logs = DBHelper::select(
    "SELECT event_type, message, shopify_product_id, created_at FROM campaign_logs
     WHERE campaign_id = ? ORDER BY id DESC LIMIT 100",
    'i', [$id]
) ?: [];

echo json_encode(['campaign' => $campaign, 'logs' => $logs]);
