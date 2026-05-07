<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];
$planId = getCurrentPlanId($shop);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' || $method === 'PUT') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $ruleId = (int)($body['rule_id'] ?? 0);
    $enabled = !empty($body['enabled']) ? 1 : 0;
    if ($ruleId <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid_rule_id']); exit; }

    // Check rule exists + plan allows.
    $rule = DBHelper::selectOne(
        "SELECT id, plan_required FROM hygiene_rule_definitions WHERE id = ? AND is_active = 1 LIMIT 1",
        'i', [$ruleId]
    );
    if (!$rule) { http_response_code(404); echo json_encode(['error' => 'rule_not_found']); exit; }
    if ((int)$rule['plan_required'] > $planId) {
        http_response_code(403);
        echo json_encode(['error' => 'rule_requires_upgrade']);
        exit;
    }

    DBHelper::execute(
        "INSERT INTO shop_hygiene_rules (shop_id, rule_id, is_enabled)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)",
        'iii', [$shopId, $ruleId, $enabled]
    );
    echo json_encode(['success' => true]);
    exit;
}

// GET — list rules with the shop's enable state.
$rules = DBHelper::select(
    "SELECT r.id, r.code, r.category, r.name, r.description, r.severity, r.tag, r.plan_required,
            COALESCE(s.is_enabled, 0) AS is_enabled
     FROM hygiene_rule_definitions r
     LEFT JOIN shop_hygiene_rules s ON s.rule_id = r.id AND s.shop_id = ?
     WHERE r.is_active = 1
     ORDER BY r.category, r.sort_order, r.id",
    'i', [$shopId]
) ?: [];

$grouped = [];
foreach ($rules as $r) {
    $cat = (string)($r['category'] ?? 'other');
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $r['locked'] = ((int)$r['plan_required']) > $planId;
    $grouped[$cat][] = $r;
}

echo json_encode(['plan_id' => $planId, 'groups' => $grouped]);
