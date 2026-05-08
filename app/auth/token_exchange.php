<?php
/**
 * Token Exchange endpoint.
 *
 * Browser flow (in nav.php / boot.js):
 *   1. App Bridge gives us a session token via shopify.idToken()
 *   2. JS POSTs that token here with `Authorization: Bearer <token>`
 *   3. We verify the JWT (HS256, signed with SHOPIFY_API_SECRET, dest = shop)
 *   4. We exchange it for an offline access token via Shopify
 *   5. We upsert/refresh the stores row, fetch shop details, populate fields
 *   6. Return { ok: true, shop, expires_at } so the page can proceed
 *
 * Run on EVERY page load (cheap if token is still valid — exchange is < 200ms
 * and Shopify allows it). To save round-trips, JS only calls this if the local
 * sessionStorage flag says we haven't refreshed in the last hour.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';   // verifySessionToken, getBearerTokenFromHeaders
require_once __DIR__ . '/../lib/logger.php';

header('Content-Type: application/json; charset=UTF-8');
sendEmbeddedAppHeaders();

// 1. Pull the JWT from the Authorization header.
$jwt = getBearerTokenFromHeaders();
if (!is_string($jwt) || $jwt === '') {
    http_response_code(401);
    echo json_encode(['error' => 'missing_authorization_header']);
    exit;
}

// 2. Verify it's a real Shopify session token signed with our secret.
$payload = verifySessionToken($jwt);
if (!is_array($payload)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_session_token']);
    exit;
}

// 3. Extract the shop from the dest claim.
$dest = (string)($payload['dest'] ?? '');
$shopHost = parse_url($dest, PHP_URL_HOST);
$shop = is_string($shopHost) ? sanitizeShopDomain($shopHost) : null;
if (!$shop) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_shop_in_token']);
    exit;
}

// 4. Exchange the JWT for an offline access token.
$exchanged = tokenExchange($shop, $jwt);
if (!is_array($exchanged) || empty($exchanged['access_token'])) {
    $err = $GLOBALS['siq_token_exchange_last_error'] ?? null;
    sbm_log_write('auth', 'token_exchange_endpoint_failed', ['shop' => $shop, 'err' => $err]);
    http_response_code(500);
    echo json_encode([
        'error' => 'token_exchange_failed',
        'shopify_response' => $err['response'] ?? null,
        'http_code' => $err['http_code'] ?? null,
    ]);
    exit;
}

// 5. Make sure a stores row exists. If first install, create it.
$existing = getShopByDomain($shop);
$accessToken = (string)$exchanged['access_token'];

if (!$existing) {
    // Fresh install — create row with whatever shop details we can pull right now.
    $details = [];
    try {
        $maybe = fetchShopDetails($shop, $accessToken);
        if (is_array($maybe)) $details = $maybe;
    } catch (Throwable $e) {
        // Tolerate failure; stores row will still be created with token.
    }
    upsertStore($shop, $accessToken, '', $details);
    ensureFreeSubscription($shop);
    sbm_log_write('auth', 'token_exchange_first_install', ['shop' => $shop]);
}

// 6. Persist the new token + expiry onto the stores row (whether new or refresh).
saveExchangedToken($shop, $exchanged);

// 7. If shop details are still NULL (first install or previous /shop.json failure),
//    pull them now and update.
$rec = getShopByDomain($shop);
if ($rec && empty($rec['store_name'])) {
    try {
        $details = fetchShopDetails($shop, $accessToken);
        if (is_array($details) && $details) {
            upsertStore($shop, $accessToken, (string)($rec['host'] ?? ''), $details);
        }
    } catch (Throwable $e) {
        sbm_log_write('auth', 'token_exchange_shop_details_refresh_failed', ['shop' => $shop, 'err' => $e->getMessage()]);
    }
}

// 8. Done.
echo json_encode([
    'ok'         => true,
    'shop'       => $shop,
    'expires_at' => date('c', time() + (int)($exchanged['expires_in'] ?? 0)),
    'scope'      => $exchanged['scope'] ?? null,
]);
