<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/webhook.php';
require_once __DIR__ . '/../lib/logger.php';

$event = validateWebhookRequest();
$shop = (string)$event['shop'];
$topic = (string)$event['topic'];
$webhookId = (string)($event['webhook_id'] ?? '');

respondWebhookAccepted();
webhookLog(['event' => 'incoming_webhook', 'topic' => $topic, 'shop' => $shop, 'webhook_id' => $webhookId]);

try {
    $store = getShopByDomain($shop);
    $shopId = is_array($store) ? (int)($store['id'] ?? 0) : 0;

    // Mark store uninstalled (keep row so merchant can reinstall and recover state).
    DBHelper::execute(
        "UPDATE stores SET status = 'uninstalled', uninstalled_at = NOW() WHERE shop = ?",
        's',
        [$shop]
    );

    if ($shopId > 0) {
        // Cancel scheduled / in-flight campaigns.
        DBHelper::execute(
            "UPDATE campaigns SET status = 'cancelled', updated_at = NOW()
             WHERE shop_id = ? AND status IN ('scheduled','running')",
            'i',
            [$shopId]
        );

        // Fail any pending or running queue work for this shop.
        DBHelper::execute(
            "UPDATE job_queue SET status = 'failed', completed_at = NOW(), error_message = 'app_uninstalled'
             WHERE shop_id = ? AND status IN ('pending','running')",
            'i',
            [$shopId]
        );
    }

    // Mark subscription cancelled.
    if (function_exists('markSubscriptionUninstalled')) {
        markSubscriptionUninstalled($shop);
    }

    sbm_log_write('webhooks', 'app_uninstalled_processed', ['shop' => $shop, 'shop_id' => $shopId]);
} catch (Throwable $e) {
    webhookLog(['event' => 'app_uninstalled_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}
