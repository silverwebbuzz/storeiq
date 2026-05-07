<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$body = json_decode((string)file_get_contents('php://input'), true);
$flagId = (int)($body['flag_id'] ?? 0);
if ($flagId <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid_flag_id']); exit; }

DBHelper::execute(
    "UPDATE hygiene_flags SET status = 'dismissed', resolved_at = NOW() WHERE id = ? AND shop_id = ?",
    'ii', [$flagId, $shopId]
);
echo json_encode(['success' => true]);
