<?php
/**
 * Start Shopify billing (REST RecurringApplicationCharge).
 *
 * URL (example):
 *   /app/billing/subscribe?shop=storename.myshopify.com&plan=starter
 *
 * This will create a recurring charge and redirect the merchant to Shopify's confirmation_url.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/logger.php';

sendEmbeddedAppHeaders();

if (!function_exists('sbm_billing_subscribe_debug')) {
    function sbm_billing_subscribe_debug(string $event, array $ctx = []): void
    {
        sbm_log_write('billing', '[subscribe] ' . $event, $ctx);
    }
}

$params = $_GET;
$hmac = $_GET['hmac'] ?? null;
// Allow in-app upgrade links that do not include HMAC.
// If HMAC is present, it must validate.
if (is_string($hmac) && $hmac !== '' && !verifyHmac($params)) {
    sbm_billing_subscribe_debug('invalid_hmac', ['query' => $_GET]);
    http_response_code(400);
    echo 'Invalid HMAC';
    exit;
}

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$plan = $_GET['plan'] ?? 'free';
if (!is_string($plan) || $plan === '') {
    $plan = 'free';
}
$plan = normalizePlanKey($plan);

if ($shop === null) {
    sbm_billing_subscribe_debug('invalid_shop', ['query' => $_GET]);
    http_response_code(400);
    echo 'Invalid shop parameter.';
    exit;
}

$store = getShopByDomain($shop);
$token = is_array($store) ? ($store['access_token'] ?? null) : null;
if (!is_string($token) || $token === '') {
    sbm_billing_subscribe_debug('missing_store_token', ['shop' => $shop, 'plan' => $plan]);
    http_response_code(400);
    echo 'Missing access token for shop.';
    exit;
}

// Define your plan catalog here (amount in shop currency).
// NOTE: Keep these aligned with your in-app pricing UI.
// Keep aligned with marketing site (index.html) and in-app plan modal.
$plans = [
    'free'    => ['name' => 'Free',    'price' => 0.00,  'trial_days' => 0],
    'starter' => ['name' => 'Starter', 'price' => 9.99,  'trial_days' => 7],
    'growth'  => ['name' => 'Growth',  'price' => 29.99, 'trial_days' => 7],
    'pro'     => ['name' => 'Pro',     'price' => 49.99, 'trial_days' => 7],
];

if (!isset($plans[$plan])) {
    sbm_billing_subscribe_debug('unknown_plan', ['shop' => $shop, 'plan' => $plan]);
    http_response_code(400);
    echo 'Unknown plan.';
    exit;
}

if ($plan === 'free') {
    // If you want to downgrade to free, you typically cancel the active charge (optional).
    setSubscriptionPlan($shop, 'free', 'free', null, null);
    $storedHost = is_array($store) ? (string)($store['host'] ?? '') : '';
    $redirectUrl = sbm_embedded_app_admin_url((string)$shop, $storedHost);
    sbm_billing_subscribe_debug('downgrade_to_free', ['shop' => $shop]);
    header('Location: ' . $redirectUrl);
    exit;
}

// Prefer app base URL callback and include shop + host (embedded App Bridge needs host).
$returnBase = rtrim((string)(defined('BASE_URL') ? BASE_URL : SHOPIFY_APP_URL), '/');
$hostForReturn = trim((string)($_GET['host'] ?? ''));
if ($hostForReturn === '' && is_array($store)) {
    $hostForReturn = trim((string)($store['host'] ?? ''));
}
$returnUrl = $returnBase . '/billing/confirm?shop=' . urlencode($shop);
if ($hostForReturn !== '') {
    $returnUrl .= '&host=' . urlencode($hostForReturn);
}
sbm_billing_subscribe_debug('create_charge_start', [
    'shop' => $shop,
    'plan' => $plan,
    'return_url' => $returnUrl,
]);

$charge = createRecurringApplicationCharge($shop, $token, [
    'name' => $plans[$plan]['name'],
    'price' => $plans[$plan]['price'],
    'trial_days' => $plans[$plan]['trial_days'],
    'return_url' => $returnUrl,
    'test' => SHOPIFY_DEBUG,
]);

if (!is_array($charge) || !isset($charge['confirmation_url'])) {
    sbm_billing_subscribe_debug('create_charge_failed', [
        'shop' => $shop,
        'plan' => $plan,
        'charge' => $charge,
    ]);
    http_response_code(500);
    echo 'Failed to create charge.';
    exit;
}

// Save pending charge id (so we know what plan they selected).
setSubscriptionPlan($shop, $plan, 'pending', (string)($charge['id'] ?? ''), null);
sbm_billing_subscribe_debug('create_charge_success', [
    'shop' => $shop,
    'plan' => $plan,
    'charge_id' => (string)($charge['id'] ?? ''),
    'confirmation_url' => (string)($charge['confirmation_url'] ?? ''),
]);

header('Location: ' . $charge['confirmation_url']);
exit;

