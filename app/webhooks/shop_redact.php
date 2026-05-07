<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/webhook.php';

$event = validateWebhookRequest();
$shop = (string)$event['shop'];
$topic = (string)$event['topic'];
$webhookId = (string)($event['webhook_id'] ?? '');

respondWebhookAccepted();
webhookLog(['event' => 'incoming_webhook', 'topic' => $topic, 'shop' => $shop, 'webhook_id' => $webhookId]);

try {
    $mysqli = db();

    // Remove queue entries first.
    $stmt = $mysqli->prepare("DELETE FROM webhook_events WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    // Remove subscription rows (shop_subscriptions uses shop_id, so resolve first).
    $shopId = 0;
    $sel = $mysqli->prepare("SELECT id FROM stores WHERE shop = ? LIMIT 1");
    if ($sel) {
        $sel->bind_param('s', $shop);
        $sel->execute();
        $res = $sel->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $sel->close();
        $shopId = is_array($row) ? (int)($row['id'] ?? 0) : 0;
    }
    if ($shopId > 0) {
        $stmt = $mysqli->prepare("DELETE FROM shop_subscriptions WHERE shop_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $shopId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Remove store row.
    $stmt = $mysqli->prepare("DELETE FROM stores WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
    webhookLog(['event' => 'shop_redact_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}

