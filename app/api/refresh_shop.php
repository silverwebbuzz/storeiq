<?php
/**
 * Pulls /shop.json from Shopify and re-runs upsertStore() to populate
 * store_name, email, country, currency, etc. for an existing install.
 *
 * Use when the install callback succeeded but shop details are NULL in the
 * stores table (e.g. fetchShopDetails failed silently first time around).
 *
 * Usage: /app/api/refresh_shop.php?shop=YOUR-STORE.myshopify.com
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }

$rec = getShopByDomain($shop);
if (!$rec) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$token = (string)($rec['access_token'] ?? '');
if ($token === '') { http_response_code(400); echo json_encode(['error' => 'no_access_token']); exit; }

$out = ['shop' => $shop, 'shop_id' => (int)$rec['id']];

// Hit /shop.json directly so we can surface the raw HTTP code if it fails.
$meta = shopifyRequestWithMeta($shop, $token, 'GET', '/shop.json');
$out['http_code']  = $meta['http_code'] ?? null;
$out['shopify_response'] = $meta['data'] ?? null;

$details = is_array($meta['data']['shop'] ?? null) ? $meta['data']['shop'] : null;
if (!$details) {
    $out['ok'] = false;
    $out['error'] = 'shop_json_failed';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Persist via existing upsertStore().
upsertStore($shop, $token, (string)($rec['host'] ?? ''), $details);

// Re-read to confirm.
$updated = getShopByDomain($shop);
$out['ok'] = true;
$out['updated_fields'] = [
    'shopify_id'  => $updated['shopify_id']  ?? null,
    'store_name'  => $updated['store_name']  ?? null,
    'shop_owner'  => $updated['shop_owner']  ?? null,
    'email'       => $updated['email']       ?? null,
    'phone'       => $updated['phone']       ?? null,
    'country'     => $updated['country']     ?? null,
    'currency'    => $updated['currency']    ?? null,
    'timezone'    => $updated['timezone']    ?? null,
    'plan_name'   => $updated['plan_name']   ?? null,
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
