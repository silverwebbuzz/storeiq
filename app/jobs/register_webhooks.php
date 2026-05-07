<?php
/**
 * Background webhook registration (fast, non-blocking from OAuth callback).
 *
 * Route:
 *   /app/jobs/register_webhooks?shop=...&ts=...&sig=...
 *
 * Auth:
 *   sig = hex(HMAC_SHA256(shop|ts, SHOPIFY_API_SECRET))
 *   ts must be within 10 minutes.
 *
 * Notes:
 * - Compliance webhooks (customers/data_request, customers/redact, shop/redact)
 *   must be configured in the Partner Dashboard UI. API registration does not satisfy the checker.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/shopify.php';

sendEmbeddedAppHeaders();

$isCli = (PHP_SAPI === 'cli');

$shop = $_GET['shop'] ?? '';
$ts = $_GET['ts'] ?? '';
$sig = $_GET['sig'] ?? '';

if (!$isCli) {
    if (!is_string($shop) || $shop === '' || !is_string($ts) || $ts === '' || !is_string($sig) || $sig === '') {
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }

    if (!ctype_digit($ts)) {
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }

    $shop = sanitizeShopDomain($shop);
    if (!is_string($shop) || $shop === '') {
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }

    $tsInt = (int)$ts;
    if (abs(time() - $tsInt) > 600) {
        http_response_code(403);
        echo 'Expired';
        exit;
    }

    $expected = hash_hmac('sha256', $shop . '|' . $ts, SHOPIFY_API_SECRET);
    if (!hash_equals($expected, (string)$sig)) {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
} else {
    $shop = is_string($shop) ? sanitizeShopDomain($shop) : null;
}

if (!is_string($shop) || $shop === '') {
    http_response_code(400);
    echo 'Missing shop';
    exit;
}

try {
    $store = getShopByDomain($shop);
    $token = is_array($store) ? ($store['access_token'] ?? null) : null;
    if (!is_string($token) || $token === '') {
        sbm_log_write('webhooks', '[register_webhooks] missing_access_token', ['shop' => $shop]);
        http_response_code(400);
        echo 'Missing token';
        exit;
    }

    sbm_log_write('webhooks', '[register_webhooks] start', ['shop' => $shop]);
    registerWebhooks($shop, $token);
    sbm_log_write('webhooks', '[register_webhooks] done', ['shop' => $shop]);

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'shop' => $shop], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    sbm_log_write('webhooks', '[register_webhooks] error', ['shop' => $shop, 'error' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}

