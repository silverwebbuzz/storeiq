<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$rows = DBHelper::select(
    "SELECT f.id, f.shopify_entity_id, f.entity_title, f.entity_type, f.detected_at, f.status,
            r.code, r.name AS rule_name, r.severity, r.category
     FROM hygiene_flags f
     JOIN hygiene_rule_definitions r ON r.id = f.rule_id
     WHERE f.shop_id = ? AND f.status = 'open'
     ORDER BY FIELD(r.severity,'critical','warning','info'), f.id DESC
     LIMIT 500",
    'i', [$shopId]
) ?: [];

$grouped = ['critical' => [], 'warning' => [], 'info' => []];
foreach ($rows as $r) {
    $sev = (string)($r['severity'] ?? 'info');
    if (!isset($grouped[$sev])) $grouped[$sev] = [];
    $grouped[$sev][] = $r;
}

$latest = DBHelper::selectOne(
    "SELECT health_score, completed_at FROM hygiene_scan_runs WHERE shop_id = ? ORDER BY id DESC LIMIT 1",
    'i', [$shopId]
);

echo json_encode([
    'health_score' => $latest['health_score'] ?? null,
    'last_scan_at' => $latest['completed_at'] ?? null,
    'flags' => $grouped,
    'total' => count($rows),
]);
