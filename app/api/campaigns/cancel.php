<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$body = json_decode((string)file_get_contents('php://input'), true);
$campaignId = (int)($body['campaign_id'] ?? $_GET['campaign_id'] ?? 0);
if ($campaignId <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid_id']); exit; }

DBHelper::execute(
    "UPDATE campaigns SET status = 'cancelled' WHERE id = ? AND shop_id = ? AND status IN ('scheduled','running')",
    'ii', [$campaignId, $shopId]
);
DBHelper::execute(
    "UPDATE job_queue SET status = 'failed', completed_at = NOW(), error_message = 'campaign_cancelled'
     WHERE shop_id = ? AND reference_id = ? AND job_type IN ('campaign_apply','campaign_revert') AND status = 'pending'",
    'ii', [$shopId, $campaignId]
);

echo json_encode(['success' => true]);
