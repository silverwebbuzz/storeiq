<?php

/**
 * Shared bootstrap for embedded app pages.
 *
 * Must be included after `config.php` so helper functions/constants exist.
 */
function sbm_bootstrap_embedded(array $options = []): array
{
    $shopInvalidMessage = (string)($options['shopInvalidMessage'] ?? 'Missing or invalid shop parameter.');
    $includeEntitlements = (bool)($options['includeEntitlements'] ?? false);

    // Standard embedded header so Shopify App Bridge works in all surfaces.
    sendEmbeddedAppHeaders();
    require_once __DIR__ . '/logger.php';
    require_once __DIR__ . '/entitlements.php';

    $shop = sanitizeShopDomain($_GET['shop'] ?? null);
    $host = $_GET['host'] ?? '';
    $chargeId = isset($_GET['charge_id']) && is_string($_GET['charge_id']) ? trim($_GET['charge_id']) : '';

    // If Shopify returns with only charge_id, try best-effort shop recovery.
    if ($shop === null && $chargeId !== '') {
        try {
            $stmt = db()->prepare("SELECT shop FROM shop_subscriptions WHERE shopify_charge_id = ? ORDER BY id DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $chargeId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
                $shop = sanitizeShopDomain(is_array($row) ? ($row['shop'] ?? null) : null);
            }
        } catch (Throwable $e) {
            sbm_log_write('billing', '[embedded_bootstrap] recover_shop_by_charge_failed', [
                'charge_id' => $chargeId,
                'error' => $e->getMessage(),
            ]);
        }
        if ($shop === null) {
            try {
                // Fallback for single-store environments.
                $resOne = db()->query("SELECT shop FROM stores WHERE status = 'installed' ORDER BY updated_at DESC LIMIT 2");
                if ($resOne) {
                    $rows = [];
                    while ($r = $resOne->fetch_assoc()) {
                        $rows[] = $r;
                    }
                    if (count($rows) === 1) {
                        $shop = sanitizeShopDomain((string)($rows[0]['shop'] ?? ''));
                        sbm_log_write('billing', '[embedded_bootstrap] recovered_shop_single_store', [
                            'charge_id' => $chargeId,
                            'shop' => $shop,
                        ]);
                    }
                }
            } catch (Throwable $e) {
                sbm_log_write('billing', '[embedded_bootstrap] recover_shop_single_store_failed', [
                    'charge_id' => $chargeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    if ($shop === null) {
        sbm_log_write('billing', '[embedded_bootstrap] missing_shop', [
            'query' => $_GET,
            'charge_id' => $chargeId,
        ]);
        http_response_code(400);
        echo $shopInvalidMessage;
        exit;
    }

    $shopRecord = getShopByDomain($shop);
    if (!$shopRecord) {
        // Redirect to install. Pass embedded=1 when host is present (we are inside Shopify Admin
        // iframe) so install.php uses App Bridge to break out of the iframe before OAuth.
        $installQs = 'shop=' . urlencode($shop);
        if ($host !== '' && is_string($host)) {
            $installQs .= '&host=' . urlencode($host) . '&embedded=1';
        }
        header('Location: ' . BASE_URL . '/auth/install?' . $installQs);
        exit;
    }

    // If Shopify loads our embedded page without `host`, App Bridge session tokens can fail
    // (e.g. appTokenGenerate 502). Recover a previously stored host and redirect once to
    // a canonical URL that includes it.
    if (($host === '' || !is_string($host)) && is_array($shopRecord)) {
        $storedHost = (string)($shopRecord['host'] ?? '');
        if ($storedHost !== '' && (!isset($_GET['host']) || (string)($_GET['host'] ?? '') === '')) {
            $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : (defined('SHOPIFY_APP_URL') ? SHOPIFY_APP_URL : '')), '/');

            // Strip BASE_URL's path prefix from REQUEST_URI to avoid double-path like /app/app/dashboard.
            $reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $basePath = rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/');
            if ($basePath !== '' && strpos($reqPath, $basePath) === 0) {
                $reqPath = substr($reqPath, strlen($basePath));
            }
            if ($reqPath === '' || $reqPath === false) {
                $reqPath = '/dashboard';
            }
            if ($reqPath[0] !== '/') {
                $reqPath = '/' . $reqPath;
            }

            $clean = $_GET;
            $clean['shop'] = $shop;
            $clean['host'] = $storedHost;
            $qs = http_build_query($clean);
            header('Location: ' . $base . $reqPath . ($qs !== '' ? ('?' . $qs) : ''));
            exit;
        }
        if ($host === '' && $storedHost !== '') {
            $host = $storedHost;
        }
    }

    // Managed Shopify pricing page can redirect directly back to app URL with charge_id.
    // Finalize billing here so subscription is updated even if /billing/confirm is skipped.
    if ($chargeId !== '' && function_exists('sbm_finalize_billing_charge')) {
        sbm_log_write('billing', '[embedded_bootstrap] finalize_attempt', [
            'shop' => $shop,
            'charge_id' => $chargeId,
            'host_present' => $host !== '',
        ]);
        try {
            $result = sbm_finalize_billing_charge($shop, $chargeId);
            sbm_log_write('billing', '[embedded_bootstrap] finalized_charge', [
                'shop' => $shop,
                'charge_id' => $chargeId,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            sbm_log_write('billing', '[embedded_bootstrap] finalize_failed', [
                'shop' => $shop,
                'charge_id' => $chargeId,
                'error' => $e->getMessage(),
            ]);
        }

        // Do NOT 302 after finalize: relative redirects often yield a blank iframe in Shopify Admin.
        // URL is cleaned client-side (nav.php history.replaceState) so refresh does not re-run finalize.
    }

    if (!$includeEntitlements) {
        return [$shop, $host, $shopRecord];
    }

    $entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : [
        'plan_key' => 'free',
        'plan_label' => 'Free',
        'features' => [],
        'limits' => [],
    ];

    return [$shop, $host, $shopRecord, $entitlements];
}

