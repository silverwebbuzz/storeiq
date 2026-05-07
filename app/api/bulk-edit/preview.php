<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$token = (string)($shopRecord['access_token'] ?? '');

$scope = (string)($_GET['scope'] ?? 'all');
$value = (string)($_GET['value'] ?? '');
$qs = ['limit' => 5, 'fields' => 'id,title,vendor,product_type,tags'];
switch ($scope) {
    case 'collection':   if ($value !== '') $qs['collection_id'] = $value; break;
    case 'vendor':       if ($value !== '') $qs['vendor'] = $value; break;
    case 'product_type': if ($value !== '') $qs['product_type'] = $value; break;
    case 'tag':
        // Shopify REST products endpoint doesn't filter by tag directly; include all and filter client-side via title match.
        // For preview we just return a sample; the worker will do exact tag filtering.
        break;
}

$path = '/products.json?' . http_build_query($qs);
$resp = shopifyRequest($shop, $token, 'GET', $path);
$products = is_array($resp['products'] ?? null) ? $resp['products'] : [];

if ($scope === 'tag' && $value !== '') {
    $needle = strtolower($value);
    $products = array_values(array_filter($products, function ($p) use ($needle) {
        $tags = strtolower((string)($p['tags'] ?? ''));
        return strpos($tags, $needle) !== false;
    }));
}

// Approximate count from a separate count endpoint (cheaper than fetching all).
$countResp = shopifyRequest($shop, $token, 'GET', '/products/count.json');
$total = is_array($countResp) ? (int)($countResp['count'] ?? 0) : 0;

$planLimit = getLimit((string)$shop, 'max_products_per_task');
echo json_encode([
    'count'         => $total,
    'sample'        => array_slice($products, 0, 5),
    'plan_limit'    => $planLimit,
    'exceeds_limit' => $planLimit > 0 && $total > $planLimit,
]);
