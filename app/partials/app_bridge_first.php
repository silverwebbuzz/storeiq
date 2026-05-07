<?php
/**
 * Shopify App Bridge — must be the FIRST <script> in <head> (after charset/viewport only).
 * Do not load other JS before this include.
 */
if (!defined('SHOPIFY_API_KEY')) {
    return;
}
?>
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" data-api-key="<?php echo htmlspecialchars((string) SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8'); ?>"></script>
