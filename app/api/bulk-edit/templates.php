<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }

$planId = getCurrentPlanId($shop);
$rows = DBHelper::select(
    "SELECT id, category, name, description, tag, actions_json, default_filter_json, plan_required, sort_order
     FROM bulk_action_templates
     WHERE is_active = 1 AND plan_required <= ?
     ORDER BY category ASC, sort_order ASC, id ASC",
    'i',
    [$planId]
) ?: [];

$grouped = [];
foreach ($rows as $r) {
    $cat = (string)($r['category'] ?? 'other');
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $grouped[$cat][] = $r;
}

echo json_encode(['plan_id' => $planId, 'groups' => $grouped]);
