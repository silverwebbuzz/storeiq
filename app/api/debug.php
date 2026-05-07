<?php
/**
 * Debug endpoint — quick health-check for the app stack.
 * Visit: /app/api/debug.php?shop=YOUR-STORE.myshopify.com
 *
 * Reports: DB connection, shop record, plan + limits, key tables presence,
 * pending jobs, recent activity, latest hygiene scan.
 *
 * Disable in production by removing this file or guarding behind a CRON_KEY check.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/entitlements.php';

header('Content-Type: application/json; charset=UTF-8');

$out = ['ok' => true, 'errors' => []];

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$out['shop_param'] = $shop;

try {
    $mysqli = db();
    $out['db_connected'] = $mysqli && !$mysqli->connect_error;
    $out['db_name'] = DB_NAME;
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['errors'][] = 'db: ' . $e->getMessage();
}

// Tables presence.
$expected = [
    'stores', 'plans', 'plan_limits', 'shop_subscriptions',
    'bulk_jobs', 'bulk_job_actions', 'bulk_job_items', 'bulk_job_snapshots',
    'campaigns', 'campaign_templates', 'campaign_snapshots', 'campaign_logs',
    'hygiene_rule_definitions', 'shop_hygiene_rules', 'hygiene_flags', 'hygiene_scan_runs',
    'job_queue', 'activity_log', 'admin_users',
    'bulk_action_templates', 'cross_app_promotions', 'shop_promo_codes', 'announcements',
];
$out['tables'] = [];
foreach ($expected as $t) {
    $row = DBHelper::selectOne("SHOW TABLES LIKE '" . $t . "'");
    $out['tables'][$t] = (bool)$row;
    if (!$row) { $out['errors'][] = "missing_table: {$t}"; $out['ok'] = false; }
}

if ($shop) {
    $rec = getShopByDomain($shop);
    $out['shop_found'] = (bool)$rec;
    if ($rec) {
        $out['shop_id']      = (int)($rec['id'] ?? 0);
        $out['shop_status']  = $rec['status'] ?? null;
        $out['shop_plan_id'] = (int)($rec['plan_id'] ?? 0);
        $out['has_token']    = !empty($rec['access_token']);
        try {
            $out['plan_key']   = getCurrentPlanKey($shop);
            $out['plan_limits'] = getPlanLimits($shop);
        } catch (Throwable $e) {
            $out['errors'][] = 'entitlements: ' . $e->getMessage();
        }

        $sid = (int)($rec['id'] ?? 0);
        $out['active_jobs'] = (int)(DBHelper::selectOne(
            "SELECT COUNT(*) AS c FROM job_queue WHERE shop_id = ? AND status IN ('pending','running')",
            'i', [$sid]
        )['c'] ?? 0);
        $out['failed_jobs_24h'] = (int)(DBHelper::selectOne(
            "SELECT COUNT(*) AS c FROM job_queue WHERE shop_id = ? AND status = 'failed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            'i', [$sid]
        )['c'] ?? 0);
        $out['recent_activity_count'] = (int)(DBHelper::selectOne(
            "SELECT COUNT(*) AS c FROM activity_log WHERE shop_id = ?",
            'i', [$sid]
        )['c'] ?? 0);
        $out['latest_scan'] = DBHelper::selectOne(
            "SELECT id, health_score, started_at, completed_at, products_scanned, flags_found
             FROM hygiene_scan_runs WHERE shop_id = ? ORDER BY id DESC LIMIT 1",
            'i', [$sid]
        );

        // Sample 1 product from Shopify (validates access_token).
        if (!empty($rec['access_token'])) {
            try {
                $resp = shopifyRequest($shop, (string)$rec['access_token'], 'GET', '/products/count.json');
                $out['shopify_product_count'] = is_array($resp) ? ($resp['count'] ?? null) : null;
                $out['shopify_api_ok'] = is_array($resp);
            } catch (Throwable $e) {
                $out['errors'][] = 'shopify: ' . $e->getMessage();
                $out['shopify_api_ok'] = false;
            }
        }
    } else {
        $out['errors'][] = "shop_not_in_stores_table: {$shop}";
        $out['ok'] = false;
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
