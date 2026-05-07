<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

if (!canAccess($shop, 'cross_app_promo')) {
    http_response_code(403);
    echo json_encode(['error' => 'promo_requires_upgrade']);
    exit;
}

// Existing code?
$existing = DBHelper::selectOne(
    "SELECT spc.*, p.headline, p.description, p.cta_label, p.code_prefix, p.discount_pct, p.free_days
     FROM shop_promo_codes spc
     JOIN cross_app_promotions p ON p.id = spc.promotion_id
     WHERE spc.shop_id = ?
     ORDER BY spc.id DESC LIMIT 1",
    'i', [$shopId]
);

if (!is_array($existing)) {
    // Pick a promotion the merchant qualifies for (highest plan_trigger that's <= their plan).
    $planId = getCurrentPlanId($shop);
    $promo = DBHelper::selectOne(
        "SELECT * FROM cross_app_promotions
         WHERE plan_trigger <= ?
         ORDER BY plan_trigger DESC, id ASC
         LIMIT 1",
        'i', [$planId]
    );
    if (!is_array($promo)) {
        echo json_encode(['error' => 'no_promotion_available']);
        exit;
    }
    $prefix = trim((string)($promo['code_prefix'] ?? 'STOREIQ'));
    $code = $prefix . '-' . strtoupper(substr(uniqid('', true), -6));
    $expires = !empty($promo['valid_days']) ? date('Y-m-d H:i:s', strtotime('+' . (int)$promo['valid_days'] . ' days')) : null;
    $promoId = (int)$promo['id'];

    DBHelper::insert(
        "INSERT INTO shop_promo_codes (shop_id, promotion_id, code, status, viewed_at, expires_at)
         VALUES (?, ?, ?, 'viewed', NOW(), ?)",
        'iiss', [$shopId, $promoId, $code, $expires]
    );
    $existing = DBHelper::selectOne(
        "SELECT spc.*, p.headline, p.description, p.cta_label
         FROM shop_promo_codes spc
         JOIN cross_app_promotions p ON p.id = spc.promotion_id
         WHERE spc.shop_id = ? ORDER BY spc.id DESC LIMIT 1",
        'i', [$shopId]
    );
} elseif ((string)$existing['status'] === 'available') {
    DBHelper::execute(
        "UPDATE shop_promo_codes SET status = 'viewed', viewed_at = NOW() WHERE id = ?",
        'i', [(int)$existing['id']]
    );
}

echo json_encode([
    'code'        => (string)$existing['code'],
    'status'      => (string)$existing['status'],
    'expires_at'  => $existing['expires_at'] ?? null,
    'headline'    => $existing['headline'] ?? null,
    'description' => $existing['description'] ?? null,
    'cta_label'   => $existing['cta_label'] ?? null,
]);
