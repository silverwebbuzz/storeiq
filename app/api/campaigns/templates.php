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

$rows = DBHelper::select(
    "SELECT id, name, description, category, event_month, event_day,
            suggested_duration_days, default_discount_pct, actions_json,
            is_system, plan_required, sort_order, use_count
     FROM campaign_templates
     WHERE is_active = 1
       AND (shop_id IS NULL OR shop_id = ?)
       AND plan_required <= ?
     ORDER BY
       CASE WHEN category = 'india_festival' THEN event_month ELSE NULL END ASC,
       sort_order ASC, id ASC",
    'ii',
    [$shopId, $planId]
) ?: [];

$grouped = [];
foreach ($rows as $r) {
    $cat = (string)($r['category'] ?? 'custom');
    if ($r['shop_id'] ?? null) $cat = 'my_templates';
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $grouped[$cat][] = $r;
}

echo json_encode(['plan_id' => $planId, 'groups' => $grouped]);
