<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/webhook.php';

$event = validateWebhookRequest();
$shop = (string)$event['shop'];
$topic = (string)$event['topic'];
$payload = $event['data'];
$webhookId = (string)($event['webhook_id'] ?? '');

respondWebhookAccepted();

webhookLog([
    'event' => 'incoming_webhook',
    'topic' => $topic,
    'shop' => $shop,
    'webhook_id' => $webhookId,
    'customer_id' => (string)($payload['customer']['id'] ?? ''),
    'orders_requested' => isset($payload['orders_requested']) ? (int)$payload['orders_requested'] : 0,
]);

