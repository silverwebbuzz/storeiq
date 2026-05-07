<?php
require_once __DIR__ . '/../../lib/admin_auth.php';
requireAdminAuth();
header('Content-Type: application/json; charset=UTF-8');

$body = json_decode((string)file_get_contents('php://input'), true);
$shopId = (int)($body['shop_id'] ?? 0);
$planId = (int)($body['plan_id'] ?? 0);
if ($shopId <= 0 || $planId <= 0) { http_response_code(400); echo json_encode(['error' => 'bad_request']); exit; }

DBHelper::execute("UPDATE stores SET plan_id = ? WHERE id = ?", 'ii', [$planId, $shopId]);
DBHelper::execute(
    "UPDATE shop_subscriptions SET plan_id = ? WHERE shop_id = ? AND id = (SELECT id FROM (SELECT MAX(id) AS id FROM shop_subscriptions WHERE shop_id = ?) x)",
    'iii', [$planId, $shopId, $shopId]
);
echo json_encode(['success' => true]);
