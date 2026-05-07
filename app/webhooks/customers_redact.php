<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/webhook.php';

$event = validateWebhookRequest();
$shop = (string)$event['shop'];
$topic = (string)$event['topic'];
$payload = $event['data'];
$webhookId = (string)($event['webhook_id'] ?? '');

respondWebhookAccepted();
webhookLog(['event' => 'incoming_webhook', 'topic' => $topic, 'shop' => $shop, 'webhook_id' => $webhookId]);

try {
    $customerId = (int)($payload['customer']['id'] ?? 0);
    if ($customerId <= 0) {
        return;
    }

    $mysqli = db();
    $customerTable = perStoreTableName(makeShopName($shop), 'customer');
    if (preg_match('/^[a-z0-9_]{1,64}$/', $customerTable) !== 1) {
        return;
    }

    $stmt = $mysqli->prepare("DELETE FROM `{$customerTable}` WHERE customer_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
    webhookLog(['event' => 'customers_redact_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}

