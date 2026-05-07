<?php
/**
 * Shopify webhook receiver.
 *
 * Route: /app/webhooks/handler (rewritten to this file)
 *
 * Verifies:
 * - X-Shopify-Hmac-Sha256 (webhook signature)
 *
 * Persists webhook into DB table `webhook_events` for reliable processing.
 */

require_once __DIR__ . '/../config.php';
ensureGlobalAppSchema();

// Shopify sends JSON body.
$body = file_get_contents('php://input');
$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? null;
$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? null;
$shop = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? null;
$webhookId = $_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID'] ?? null;

if (!is_string($body)) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

if (!is_string($hmacHeader) || $hmacHeader === '' || !verifyWebhookHmac($body, $hmacHeader)) {
    http_response_code(401);
    echo 'Invalid webhook signature';
    exit;
}

if (!is_string($shop) || $shop === '' || !is_string($topic) || $topic === '') {
    http_response_code(400);
    echo 'Missing webhook headers';
    exit;
}

$mysqli = db();
$payload = json_decode($body, true);
$payloadJson = $body; // keep raw JSON as received

$webhookIdStr = is_string($webhookId) && $webhookId !== '' ? $webhookId : '';
$topicStr = (string)$topic;
$shopStr = (string)$shop;

$stmt = $mysqli->prepare(
    "INSERT INTO webhook_events (shop, topic, webhook_id, payload_json)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), received_at = NOW()"
);
if ($stmt) {
    $stmt->bind_param('ssss', $shopStr, $topicStr, $webhookIdStr, $payloadJson);
    $stmt->execute();
    $stmt->close();
}

// Apply immediately so store tables stay fresh even if cron is delayed.
// Cron processor remains as backup/retry path.
try {
    applyWebhookToStoreTables($shopStr, $topicStr, $payloadJson);
} catch (Throwable $e) {
    // Keep webhook 200 to avoid Shopify retries storm; persist error for async retry visibility.
    $err = $e->getMessage();
    $u = $mysqli->prepare(
        "UPDATE webhook_events
         SET last_error = ?
         WHERE shop = ? AND topic = ?
         ORDER BY received_at DESC
         LIMIT 1"
    );
    if ($u) {
        $u->bind_param('sss', $err, $shopStr, $topicStr);
        $u->execute();
        $u->close();
    }
}

// Minimal immediate side-effect: app/uninstalled => mark store uninstalled
if ($topicStr === 'app/uninstalled') {
    $u = $mysqli->prepare("UPDATE stores SET status='uninstalled', updated_at=NOW() WHERE shop=?");
    if ($u) {
        $u->bind_param('s', $shopStr);
        $u->execute();
        $u->close();
    }

    // Also mark subscription cancelled/inactive.
    markSubscriptionUninstalled($shopStr);
}

http_response_code(200);
echo 'OK';

