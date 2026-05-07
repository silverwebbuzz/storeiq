<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/ui.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$host = (string)($_GET['host'] ?? '');

$plans = DBHelper::select(
    "SELECT p.id, p.name, p.display_name, p.price_usd, l.*
     FROM plans p
     JOIN plan_limits l ON l.plan_id = p.id
     WHERE p.is_active = 1
     ORDER BY p.sort_order ASC"
) ?: [];

$currentPlanKey = getCurrentPlanKey($shop);
$out = [];
foreach ($plans as $p) {
    $key = (string)$p['name'];
    $out[] = [
        'id'           => (int)$p['id'],
        'key'          => $key,
        'name'         => (string)$p['display_name'],
        'price_usd'    => (float)$p['price_usd'],
        'is_current'   => $key === $currentPlanKey,
        'upgrade_url'  => siq_upgrade_url($shop, $host, $key),
        'limits'       => [
            'max_products_per_task'     => (int)$p['max_products_per_task'],
            'max_active_campaigns'      => (int)$p['max_active_campaigns'],
            'can_schedule'              => (bool)$p['can_schedule'],
            'can_auto_revert'           => (bool)$p['can_auto_revert'],
            'can_recurring_campaigns'   => (bool)$p['can_recurring_campaigns'],
            'can_save_custom_templates' => (bool)$p['can_save_custom_templates'],
            'can_multiphase_campaigns'  => (bool)$p['can_multiphase_campaigns'],
            'can_campaign_calendar'     => (bool)$p['can_campaign_calendar'],
            'can_campaign_analytics'    => (bool)$p['can_campaign_analytics'],
            'can_formula_pricing'       => (bool)$p['can_formula_pricing'],
            'can_cross_app_promo'       => (bool)$p['can_cross_app_promo'],
            'can_staff_activity_log'    => (bool)$p['can_staff_activity_log'],
            'max_hygiene_rules'         => (int)$p['max_hygiene_rules'],
            'undo_history_days'         => (int)$p['undo_history_days'],
        ],
    ];
}
echo json_encode(['current' => $currentPlanKey, 'plans' => $out]);
