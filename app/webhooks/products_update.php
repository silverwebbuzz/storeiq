<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/webhook.php';
require_once __DIR__ . '/../lib/logger.php';

$event = validateWebhookRequest();
$shop      = (string)$event['shop'];
$topic     = (string)$event['topic'];
$webhookId = (string)($event['webhook_id'] ?? '');
$payload   = is_array($event['data'] ?? null) ? $event['data'] : [];

respondWebhookAccepted();
webhookLog(['event' => 'incoming_webhook', 'topic' => $topic, 'shop' => $shop, 'webhook_id' => $webhookId]);

try {
    $store = getShopByDomain($shop);
    if (!is_array($store)) {
        sbm_log_write('webhooks', 'products_update_unknown_shop', ['shop' => $shop]);
        exit;
    }
    $shopId = (int)($store['id'] ?? 0);
    $productId = (int)($payload['id'] ?? 0);
    if ($shopId <= 0 || $productId <= 0) {
        exit;
    }

    // Re-open any open hygiene flags for this product so the next scan re-checks them.
    // (We don't fix here — we just mark them stale via re_check_at if the column exists.)
    $cols = DBHelper::select("SHOW COLUMNS FROM hygiene_flags LIKE 're_check_at'");
    if (is_array($cols) && count($cols) > 0) {
        DBHelper::execute(
            "UPDATE hygiene_flags
             SET re_check_at = NOW()
             WHERE shop_id = ? AND shopify_entity_id = ? AND status = 'open'",
            'ii',
            [$shopId, $productId]
        );
    } else {
        // Fallback: just touch updated_at-like behavior by leaving status='open'.
        // No-op: a fresh hygiene scan will re-evaluate the product.
    }

    sbm_log_write('webhooks', 'products_update_processed', [
        'shop' => $shop,
        'product_id' => $productId,
    ]);
} catch (Throwable $e) {
    webhookLog(['event' => 'products_update_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}
