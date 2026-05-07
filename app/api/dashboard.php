<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/entitlements.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) {
    echo json_encode(['error' => 'invalid_shop']);
    exit;
}
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) {
    echo json_encode(['error' => 'not_installed']);
    exit;
}
$shopId = (int)$shopRecord['id'];

// Latest hygiene scan + open flag counts.
$latestScan = DBHelper::selectOne(
    "SELECT id, health_score, completed_at, started_at, flags_found
     FROM hygiene_scan_runs WHERE shop_id = ? ORDER BY id DESC LIMIT 1",
    'i', [$shopId]
);

$flagCounts = DBHelper::select(
    "SELECT r.severity, COUNT(*) AS cnt
     FROM hygiene_flags f
     JOIN hygiene_rule_definitions r ON r.id = f.rule_id
     WHERE f.shop_id = ? AND f.status = 'open'
     GROUP BY r.severity",
    'i', [$shopId]
) ?: [];
$openFlags = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
foreach ($flagCounts as $row) {
    $sev = (string)($row['severity'] ?? 'info');
    $cnt = (int)($row['cnt'] ?? 0);
    if (isset($openFlags[$sev])) $openFlags[$sev] = $cnt;
    $openFlags['total'] += $cnt;
}

// Upcoming campaigns (next 3 scheduled).
$upcoming = DBHelper::select(
    "SELECT id, name, start_at, end_at, total_products, status
     FROM campaigns
     WHERE shop_id = ? AND status = 'scheduled'
     ORDER BY start_at ASC
     LIMIT 3",
    'i', [$shopId]
) ?: [];

// Recent activity (last 5).
$recent = DBHelper::select(
    "SELECT action, summary, created_at
     FROM activity_log
     WHERE shop_id = ?
     ORDER BY id DESC
     LIMIT 5",
    'i', [$shopId]
) ?: [];

// Active queue jobs.
$activeJobs = (int)(DBHelper::selectOne(
    "SELECT COUNT(*) AS cnt FROM job_queue
     WHERE shop_id = ? AND status IN ('pending','running')",
    'i', [$shopId]
)['cnt'] ?? 0);

// Promo eligibility.
$hasPromo = false;
$promoOffer = null;
if (canAccess($shop, 'cross_app_promo')) {
    $row = DBHelper::selectOne(
        "SELECT spc.id, spc.code, spc.status, p.headline, p.description, p.cta_label
         FROM shop_promo_codes spc
         JOIN cross_app_promotions p ON p.id = spc.promotion_id
         WHERE spc.shop_id = ? AND spc.status IN ('available','viewed')
         ORDER BY spc.id DESC LIMIT 1",
        'i', [$shopId]
    );
    if (is_array($row)) {
        $hasPromo = true;
        $promoOffer = $row;
    } else {
        // No code yet — but eligible. Surface a generic banner.
        $promo = DBHelper::selectOne(
            "SELECT headline, description, cta_label FROM cross_app_promotions
             WHERE plan_trigger <= (SELECT plan_id FROM stores WHERE id = ? LIMIT 1)
             ORDER BY plan_trigger DESC LIMIT 1",
            'i', [$shopId]
        );
        if (is_array($promo)) {
            $hasPromo = true;
            $promoOffer = $promo;
        }
    }
}

echo json_encode([
    'shop'             => $shop,
    'plan_key'         => getCurrentPlanKey($shop),
    'health_score'     => isset($latestScan['health_score']) ? (int)$latestScan['health_score'] : null,
    'last_scan_at'     => $latestScan['completed_at'] ?? null,
    'open_flags'       => $openFlags,
    'upcoming_campaigns' => $upcoming,
    'recent_activity'  => $recent,
    'active_jobs'      => $activeJobs,
    'has_promo'        => $hasPromo,
    'promo'            => $promoOffer,
], JSON_UNESCAPED_UNICODE);
