<?php
/**
 * Shopify billing confirmation callback (REST RecurringApplicationCharge).
 *
 * Shopify redirects here after the merchant approves/declines the charge.
 * Expected:
 *   /app/billing/confirm?shop=...&charge_id=...&hmac=...
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/logger.php';

sendEmbeddedAppHeaders();

if (!function_exists('sbm_billing_debug')) {
    function sbm_billing_debug(string $event, array $ctx = []): void
    {
        sbm_log_write('billing', '[confirm] ' . $event, $ctx);
    }
}

$params = $_GET;
$chargeId = $_GET['charge_id'] ?? null;
$shop = sanitizeShopDomain($_GET['shop'] ?? null);

$hasHmac = isset($_GET['hmac']) && is_string($_GET['hmac']) && $_GET['hmac'] !== '';
$hmacValid = $hasHmac ? verifyHmac($params) : false;

if (!is_string($chargeId) || $chargeId === '') {
    sbm_billing_debug('missing_charge_id', ['query' => $_GET]);
    http_response_code(400);
    echo 'Missing charge_id.';
    exit;
}

// Fallback path: some Shopify admin flows may not include `shop` here.
// Resolve shop from the pending/active subscription row by charge id.
if ($shop === null) {
    $stmtShop = db()->prepare(
        "SELECT shop FROM shop_subscriptions WHERE shopify_charge_id = ? ORDER BY id DESC LIMIT 1"
    );
    if ($stmtShop) {
        $stmtShop->bind_param('s', $chargeId);
        $stmtShop->execute();
        $resShop = $stmtShop->get_result();
        $rowShop = $resShop ? $resShop->fetch_assoc() : null;
        $stmtShop->close();
        $shop = sanitizeShopDomain(is_array($rowShop) ? ($rowShop['shop'] ?? null) : null);
    }
}

if ($shop === null) {
    sbm_billing_debug('missing_shop_after_fallback', [
        'charge_id' => (string)$chargeId,
        'query' => $_GET,
    ]);
    http_response_code(400);
    echo 'Missing shop parameter.';
    exit;
}

$store = getShopByDomain($shop);
$token = is_array($store) ? ($store['access_token'] ?? null) : null;
if (!is_string($token) || $token === '') {
    sbm_billing_debug('missing_store_token', [
        'shop' => $shop,
        'charge_id' => (string)$chargeId,
    ]);
    http_response_code(400);
    echo 'Missing access token for shop.';
    exit;
}

$charge = getRecurringApplicationCharge($shop, $token, $chargeId);
if (!is_array($charge)) {
    sbm_billing_debug('charge_fetch_failed', [
        'shop' => $shop,
        'charge_id' => (string)$chargeId,
    ]);
    http_response_code(500);
    echo 'Unable to fetch charge.';
    exit;
}

// If HMAC is missing/invalid, still allow only when charge belongs to this callback.
$chargeIdFromApi = (string)($charge['id'] ?? '');
if (!$hmacValid && $chargeIdFromApi !== (string)$chargeId) {
    sbm_billing_debug('invalid_confirmation_payload', [
        'shop' => $shop,
        'charge_id_query' => (string)$chargeId,
        'charge_id_api' => (string)$chargeIdFromApi,
        'has_hmac' => $hasHmac,
        'hmac_valid' => $hmacValid,
    ]);
    http_response_code(400);
    echo 'Invalid confirmation payload.';
    exit;
}

$status = (string)($charge['status'] ?? '');
$sub = getSubscriptionByShop($shop);
$fallbackPlanKey = is_array($sub) ? normalizePlanKey((string)($sub['plan_key'] ?? 'free')) : 'free';
$chargeName = strtolower(trim((string)($charge['name'] ?? '')));
$planKeyFromCharge = 'free';
if (strpos($chargeName, 'pro') !== false) {
    $planKeyFromCharge = 'pro';
} elseif (strpos($chargeName, 'growth') !== false) {
    $planKeyFromCharge = 'growth';
} elseif (strpos($chargeName, 'starter') !== false) {
    $planKeyFromCharge = 'starter';
}
$resolvedPlanKey = ($planKeyFromCharge !== 'free') ? $planKeyFromCharge : $fallbackPlanKey;
sbm_billing_debug('resolved_context', [
    'shop' => $shop,
    'charge_id' => (string)$chargeId,
    'status' => $status,
    'charge_name' => (string)($charge['name'] ?? ''),
    'plan_from_charge' => $planKeyFromCharge,
    'fallback_plan' => $fallbackPlanKey,
    'resolved_plan' => $resolvedPlanKey,
    'has_hmac' => $hasHmac,
    'hmac_valid' => $hmacValid,
]);

if ($status === 'accepted') {
    $activated = activateRecurringApplicationCharge($shop, $token, $chargeId);
    if (!is_array($activated)) {
        sbm_billing_debug('activate_charge_failed', [
            'shop' => $shop,
            'charge_id' => (string)$chargeId,
        ]);
        http_response_code(500);
        echo 'Unable to activate charge.';
        exit;
    }
    $billingOn = isset($activated['billing_on']) ? (string)$activated['billing_on'] : null;
    $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;

    try {
        setSubscriptionPlan($shop, $resolvedPlanKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
    } catch (Throwable $e) {
        sbm_billing_debug('db_update_failed_accepted', [
            'shop' => $shop,
            'charge_id' => (string)$chargeId,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
} elseif ($status === 'active') {
    // Already active, just reflect it in DB.
    $billingOn = isset($charge['billing_on']) ? (string)$charge['billing_on'] : null;
    $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;
    try {
        setSubscriptionPlan($shop, $resolvedPlanKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
    } catch (Throwable $e) {
        sbm_billing_debug('db_update_failed_active', [
            'shop' => $shop,
            'charge_id' => (string)$chargeId,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
} else {
    // declined / cancelled / expired
    try {
        setSubscriptionPlan($shop, 'free', 'free', null, null);
    } catch (Throwable $e) {
        sbm_billing_debug('db_update_failed_non_active', [
            'shop' => $shop,
            'charge_id' => (string)$chargeId,
            'status' => $status,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}

$subAfter = null;
try {
    $subAfter = getSubscriptionByShop($shop);
} catch (Throwable $e) {
    sbm_billing_debug('db_read_after_write_failed', [
        'shop' => $shop,
        'error' => $e->getMessage(),
    ]);
}
sbm_billing_debug('db_after_write', [
    'shop' => $shop,
    'charge_id' => (string)$chargeId,
    'subscription' => $subAfter,
]);

// Redirect back into the embedded app with shop/host for stable App Bridge init.
$storeHost = is_array($store) ? (string)($store['host'] ?? '') : '';
$redirectUrl = sbm_embedded_app_admin_url((string)$shop, $storeHost);
sbm_billing_debug('redirect_back_to_app', [
    'shop' => $shop,
    'redirect_url' => $redirectUrl,
]);
header('Location: ' . $redirectUrl);
exit;

