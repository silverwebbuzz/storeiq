<?php
/**
 * Sanitize and normalize `shop` parameter.
 *
 * @param string|null $shop
 * @return string|null
 */
function sanitizeShopDomain(?string $shop): ?string
{
    if ($shop === null) {
        return null;
    }

    $shop = trim(strtolower($shop));

    // Basic validation: must end with .myshopify.com and contain only allowed chars before
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop)) {
        return null;
    }

    return $shop;
}

/**
 * Build canonical admin app URL with shop + host params.
 */
function sbm_embedded_app_admin_url(string $shop, string $host = ''): string
{
    $shop = (string)(sanitizeShopDomain($shop) ?? '');
    if ($shop === '') {
        return '';
    }
    $resolvedHost = trim($host) !== '' ? trim($host) : '';
    $url = "https://{$shop}/admin/apps/" . SHOPIFY_APP_HANDLE . '?shop=' . urlencode($shop);
    if ($resolvedHost !== '') {
        $url .= '&host=' . urlencode($resolvedHost);
    }
    return $url;
}

/**
 * Build OAuth install/authorize URL for given shop.
 *
 * @param string $shop
 * @param string $state
 * @return string
 */
function buildInstallUrl(string $shop, string $state): string
{
    $params = [
        'client_id'    => SHOPIFY_API_KEY,
        'scope'        => SHOPIFY_SCOPES,
        'redirect_uri' => SHOPIFY_REDIRECT_URI,
        'state'        => $state,
    ];

    return "https://{$shop}/admin/oauth/authorize?" . http_build_query($params);
}

/**
 * Validate HMAC for incoming Shopify requests.
 *
 * @param array $params Typically $_GET
 * @return bool
 */
function verifyHmac(array $params): bool
{
    if (!isset($params['hmac'])) {
        return false;
    }

    $hmac = $params['hmac'];
    unset($params['hmac'], $params['signature']); // signature is legacy

    ksort($params);

    // Match Shopify canonicalization and sapi behavior (RFC3986 encoding).
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $computed = hash_hmac('sha256', $query, SHOPIFY_API_SECRET);

    return hash_equals($hmac, $computed);
}

/**
 * Exchange authorization code for a permanent access token.
 *
 * @param string $shop
 * @param string $code
 * @return string|null
 */
function exchangeCodeForAccessToken(string $shop, string $code): ?string
{
    $endpoint = "https://{$shop}/admin/oauth/access_token";
    $payload  = [
        'client_id'     => SHOPIFY_API_KEY,
        'client_secret' => SHOPIFY_API_SECRET,
        'code'          => $code,
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_HEADER, false);

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $err      = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || !$response || $httpCode < 200 || $httpCode >= 300) {
        $ctx = [
            'shop' => $shop,
            'curl_errno' => $errno,
            'curl_error' => $err,
            'http_code' => $httpCode,
            'response' => is_string($response) ? $response : null,
        ];
        $GLOBALS['sbm_oauth_last_error'] = $ctx;
        debugLog('[oauth] access_token exchange failed', $ctx);
        if (function_exists('sbm_log_write')) {
            sbm_log_write('auth', 'oauth_access_token_exchange_failed', $ctx);
        }
        return null;
    }

    $data = json_decode($response, true);

    return $data['access_token'] ?? null;
}

/**
 * Token Exchange — swaps an App Bridge session token (JWT issued by Shopify to the
 * embedded iframe) for an EXPIRING OFFLINE access token. This is the modern flow
 * required by Shopify for new apps.
 *
 * @param string $shop          e.g. mystore.myshopify.com
 * @param string $sessionToken  JWT obtained via shopify.idToken() in the browser
 * @return array|null  ['access_token' => ..., 'scope' => ..., 'expires_in' => seconds]
 */
function tokenExchange(string $shop, string $sessionToken): ?array
{
    $endpoint = "https://{$shop}/admin/oauth/access_token";
    $payload  = [
        'client_id'            => SHOPIFY_API_KEY,
        'client_secret'        => SHOPIFY_API_SECRET,
        'grant_type'           => 'urn:ietf:params:oauth:grant-type:token-exchange',
        'subject_token'        => $sessionToken,
        'subject_token_type'   => 'urn:ietf:params:oauth:token-type:id_token',
        'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $err      = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || !$response || $httpCode < 200 || $httpCode >= 300) {
        $ctx = [
            'shop' => $shop,
            'http_code' => $httpCode,
            'curl_errno' => $errno,
            'curl_error' => $err,
            'response' => is_string($response) ? $response : null,
        ];
        $GLOBALS['siq_token_exchange_last_error'] = $ctx;
        debugLog('[oauth] token_exchange_failed', $ctx);
        if (function_exists('sbm_log_write')) {
            sbm_log_write('auth', 'token_exchange_failed', $ctx);
        }
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token'])) {
        return null;
    }
    return [
        'access_token' => (string)$data['access_token'],
        'scope'        => (string)($data['scope'] ?? ''),
        'expires_in'   => (int)($data['expires_in'] ?? 0),  // seconds
    ];
}

/**
 * Persist a freshly-exchanged token onto an existing store row.
 * Updates expiry + scope + last_refresh.
 */
function saveExchangedToken(string $shop, array $exchanged): void
{
    $mysqli = db();
    $token  = (string)($exchanged['access_token'] ?? '');
    $scope  = (string)($exchanged['scope'] ?? '');
    $expIn  = (int)($exchanged['expires_in'] ?? 0);
    $expAt  = $expIn > 0 ? date('Y-m-d H:i:s', time() + $expIn) : null;

    $stmt = $mysqli->prepare(
        "UPDATE stores
         SET access_token = ?,
             access_token_scope = ?,
             access_token_expires_at = ?,
             last_token_refresh_at = NOW(),
             status = 'installed',
             uninstalled_at = NULL,
             updated_at = NOW()
         WHERE shop = ?"
    );
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param('ssss', $token, $scope, $expAt, $shop);
    $stmt->execute();
    $stmt->close();
}

/**
 * Fetch shop details via REST Admin API.
 *
 * @param string $shop
 * @param string $accessToken
 * @return array|null
 */
function fetchShopDetails(string $shop, string $accessToken): ?array
{
    $result = shopifyRequest($shop, $accessToken, 'GET', '/shop.json');
    return isset($result['shop']) && is_array($result['shop']) ? $result['shop'] : null;
}

/**
 * Create a safe per-store identifier based on shop domain.
 *
 * @param string $shop
 * @return string
 */
function makeShopName(string $shop): string
{
    return preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($shop));
}

/**
 * Build a safe per-store table name with suffix.
 *
 * @param string $shopName
 * @param string $suffix
 * @return string
 */
function perStoreTableName(string $shopName, string $suffix): string
{
    $shopName = (string)$shopName;
    $suffix = ltrim($suffix, '_');

    // MySQL table name length limit is 64 characters.
    $maxShopLen = 64 - 1 - strlen($suffix); // underscore + suffix
    if ($maxShopLen < 1) {
        $maxShopLen = 1;
    }

    if (strlen($shopName) > $maxShopLen) {
        $shopName = substr($shopName, 0, $maxShopLen);
    }

    return $shopName . '_' . $suffix;
}

/**
 * Global schema guard.
 *
 * Fixed/shared tables are managed manually in DB (phpMyAdmin),
 * so this function is intentionally a no-op.
 */
function ensureGlobalAppSchema(): void
{
    return;
}

/**
 * Ensure required per-store tables exist.
 *
 * @param string $shop
 * @return array Table names created/ensured
 */
function ensurePerStoreTables(string $shop): array
{
    ensureGlobalAppSchema();
    $mysqli = db();
    $shopName = makeShopName($shop);

    $orderTable = perStoreTableName($shopName, 'order');
    $customerTable = perStoreTableName($shopName, 'customer');
    $productsInventoryTable = perStoreTableName($shopName, 'products_inventory');
    $analyticsTable = perStoreTableName($shopName, 'analytics');
    $actionItemsTable = perStoreTableName($shopName, 'action_items');
    $cohortsTable = perStoreTableName($shopName, 'cohorts');
    $funnelTable = perStoreTableName($shopName, 'funnel');
    $attributionTable = perStoreTableName($shopName, 'attribution');
    $forecastsTable = perStoreTableName($shopName, 'forecasts');

    // Validate identifiers (defense-in-depth). Only allow alnum + underscore.
    foreach ([
        $orderTable,
        $customerTable,
        $productsInventoryTable,
        $analyticsTable,
        $actionItemsTable,
        $cohortsTable,
        $funnelTable,
        $attributionTable,
        $forecastsTable,
    ] as $t) {
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $t)) {
            throw new Exception('Unsafe table name generated.');
        }
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS `{$orderTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            `payload_json` LONGTEXT NOT NULL,
            `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$customerTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            `payload_json` LONGTEXT NOT NULL,
            `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_customer_id` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$productsInventoryTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED NULL,
            `inventory_item_id` BIGINT UNSIGNED NULL,
            `sku` VARCHAR(128) NULL,
            `title` VARCHAR(255) NULL,
            `inventory_quantity` INT NULL,
            `payload_json` LONGTEXT NOT NULL,
            `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_variant_id` (`variant_id`),
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$analyticsTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `metric_key` VARCHAR(64) NOT NULL,
            `metric_value` VARCHAR(255) NULL,
            `payload_json` LONGTEXT NULL,
            `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_metric_key` (`metric_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$actionItemsTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `action_key` VARCHAR(128) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `severity` VARCHAR(32) NOT NULL DEFAULT 'medium',
            `impact_score` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `confidence_score` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL DEFAULT 'new',
            `owner_section` VARCHAR(64) NULL,
            `cta_label` VARCHAR(64) NULL,
            `cta_url` VARCHAR(512) NULL,
            `source_json` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_action_key` (`action_key`),
            KEY `idx_action_status` (`status`),
            KEY `idx_action_impact` (`impact_score`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$cohortsTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cohort_key` VARCHAR(32) NOT NULL,
            `period_index` INT NOT NULL DEFAULT 0,
            `base_customers` INT NOT NULL DEFAULT 0,
            `retained_customers` INT NOT NULL DEFAULT 0,
            `retention_rate` DECIMAL(6,2) NOT NULL DEFAULT 0,
            `orders_count` INT NOT NULL DEFAULT 0,
            `revenue_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_cohort_period` (`cohort_key`, `period_index`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$funnelTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `window_key` VARCHAR(32) NOT NULL,
            `step_name` VARCHAR(64) NOT NULL,
            `step_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `step_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `conversion_rate` DECIMAL(6,2) NOT NULL DEFAULT 0,
            `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_funnel_window_step` (`window_key`, `step_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$attributionTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `window_key` VARCHAR(32) NOT NULL,
            `source_name` VARCHAR(128) NOT NULL,
            `orders_count` INT NOT NULL DEFAULT 0,
            `revenue_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `aov` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attribution_window_source` (`window_key`, `source_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `{$forecastsTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `forecast_key` VARCHAR(128) NOT NULL,
            `entity_type` VARCHAR(32) NOT NULL,
            `entity_id` VARCHAR(64) NOT NULL,
            `window_days` INT NOT NULL DEFAULT 30,
            `metric_name` VARCHAR(64) NOT NULL,
            `metric_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `payload_json` LONGTEXT NULL,
            `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_forecast` (`forecast_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    ];

    foreach ($queries as $sql) {
        if (!$mysqli->query($sql)) {
            throw new Exception('Failed creating per-store tables: ' . $mysqli->error);
        }
    }

    return [
        'shop_name' => $shopName,
        'order' => $orderTable,
        'customer' => $customerTable,
        'products_inventory' => $productsInventoryTable,
        'analytics' => $analyticsTable,
        'action_items' => $actionItemsTable,
        'cohorts' => $cohortsTable,
        'funnel' => $funnelTable,
        'attribution' => $attributionTable,
        'forecasts' => $forecastsTable,
    ];
}

/**
 * Upsert store record into `stores` table.
 *
 * Matches your `stores.sql` schema.
 *
 * Backward compatible with both call styles:
 * - upsertStore($shop, $accessToken, $shopDetails)
 * - upsertStore($shop, $accessToken, $host, $shopDetails)
 */
function upsertStore(
    string $shop,
    string $accessToken,
    string|array|null $hostOrShopDetails = null,
    ?array $shopDetails = null
): void {
    ensureGlobalAppSchema();
    $mysqli = db();

    // Back-compat: third argument used to be $shopDetails (array)
    $host = null;
    if (is_array($hostOrShopDetails) && $shopDetails === null) {
        $shopDetails = $hostOrShopDetails;
    } elseif (is_string($hostOrShopDetails)) {
        $host = $hostOrShopDetails;
    }

    $details = $shopDetails ?? [];

    $domain = $details['domain'] ?? $shop;
    $shopifyId = isset($details['id']) ? (string)$details['id'] : null;
    $storeName = $details['name'] ?? null;
    $shopOwner = $details['shop_owner'] ?? null;
    $email = $details['email'] ?? null;
    $phone = $details['phone'] ?? null;
    $planDisplayName = $details['plan_display_name'] ?? null;
    $planName = $details['plan_name'] ?? null;
    $country = $details['country'] ?? null;
    $currency = $details['currency'] ?? null;
    $timezone = $details['timezone'] ?? null;
    $ianaTimezone = $details['iana_timezone'] ?? null;
    $countryCode = $details['country_code'] ?? null;
    $countryName = $details['country_name'] ?? null;
    $address1 = $details['address1'] ?? null;
    $address2 = $details['address2'] ?? null;
    $city = $details['city'] ?? null;
    $zip = $details['zip'] ?? null;
    $province = $details['province'] ?? null;
    $provinceCode = $details['province_code'] ?? null;
    $primaryLocale = $details['primary_locale'] ?? null;
    $moneyFormat = $details['money_format'] ?? null;
    $moneyWithCurrencyFormat = $details['money_with_currency_format'] ?? null;
    $moneyInEmailsFormat = $details['money_in_emails_format'] ?? null;
    $moneyWithCurrencyInEmailsFormat = $details['money_with_currency_in_emails_format'] ?? null;
    $taxId = $details['tax_id'] ?? null;
    $gstin = $details['gstin'] ?? null;
    $taxSettings = isset($details['taxes_included']) ? json_encode(['taxes_included' => $details['taxes_included']], JSON_UNESCAPED_UNICODE) : null;
    $restapiJson = $details ? json_encode(['shop' => $details], JSON_UNESCAPED_UNICODE) : null;
    $createdAt = isset($details['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$details['created_at'])) : null;
    $updatedAt = isset($details['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$details['updated_at'])) : null;

    $stmt = $mysqli->prepare("
        INSERT INTO stores (
            shop, domain, access_token, host, shopify_id, store_name, shop_owner, logo_url, email, phone,
            plan_display_name, plan_name, country, currency, timezone, iana_timezone,
            country_code, country_name, address1, address2, city, zip, province,
            province_code, primary_locale, money_format, money_with_currency_format,
            money_in_emails_format, money_with_currency_in_emails_format,
            tax_id, gstin, tax_settings, restapi_json, created_at, updated_at, app_install_date, status
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, NOW(), 'installed'
        )
        ON DUPLICATE KEY UPDATE
            domain = VALUES(domain),
            access_token = VALUES(access_token),
            host = VALUES(host),
            shopify_id = VALUES(shopify_id),
            store_name = VALUES(store_name),
            shop_owner = VALUES(shop_owner),
            email = VALUES(email),
            phone = VALUES(phone),
            plan_display_name = VALUES(plan_display_name),
            plan_name = VALUES(plan_name),
            country = VALUES(country),
            currency = VALUES(currency),
            timezone = VALUES(timezone),
            iana_timezone = VALUES(iana_timezone),
            country_code = VALUES(country_code),
            country_name = VALUES(country_name),
            address1 = VALUES(address1),
            address2 = VALUES(address2),
            city = VALUES(city),
            zip = VALUES(zip),
            province = VALUES(province),
            province_code = VALUES(province_code),
            primary_locale = VALUES(primary_locale),
            money_format = VALUES(money_format),
            money_with_currency_format = VALUES(money_with_currency_format),
            money_in_emails_format = VALUES(money_in_emails_format),
            money_with_currency_in_emails_format = VALUES(money_with_currency_in_emails_format),
            tax_id = VALUES(tax_id),
            gstin = VALUES(gstin),
            tax_settings = VALUES(tax_settings),
            restapi_json = VALUES(restapi_json),
            status = 'installed',
            updated_at = NOW(),
            app_install_date = NOW()
    ");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }

    // Build parameter list once; if you add/remove columns later,
    // just update this array and the SQL above. The type string is
    // generated automatically based on the number of values.
    $params = [
        &$shop,
        &$domain,
        &$accessToken,
        &$host,
        &$shopifyId,
        &$storeName,
        &$shopOwner,
        &$email,
        &$phone,
        &$planDisplayName,
        &$planName,
        &$country,
        &$currency,
        &$timezone,
        &$ianaTimezone,
        &$countryCode,
        &$countryName,
        &$address1,
        &$address2,
        &$city,
        &$zip,
        &$province,
        &$provinceCode,
        &$primaryLocale,
        &$moneyFormat,
        &$moneyWithCurrencyFormat,
        &$moneyInEmailsFormat,
        &$moneyWithCurrencyInEmailsFormat,
        &$taxId,
        &$gstin,
        &$taxSettings,
        &$restapiJson,
        &$createdAt,
        &$updatedAt,
    ];

    $types = str_repeat('s', count($params));

    // First argument is the type string, followed by all param refs.
    array_unshift($params, $types);

    if (!$stmt->bind_param(...$params)) {
        throw new Exception('bind_param failed in upsertStore: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed in upsertStore: ' . $stmt->error);
    }
    $stmt->close();
}

/**
 * Upsert a metric into analytics table.
 *
 * @param mysqli $mysqli
 * @param string $table
 * @param string $key
 * @param string|null $value
 * @param array|null $payload
 * @return void
 */
function upsertAnalyticsMetric(mysqli $mysqli, string $table, string $key, ?string $value, ?array $payload = null): void
{
    $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $mysqli->prepare("INSERT INTO `{$table}` (metric_key, metric_value, payload_json) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), payload_json = VALUES(payload_json), fetched_at = NOW()");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param('sss', $key, $value, $payloadJson);
    $stmt->execute();
    $stmt->close();
}

/**
 * Fetch and store basic datasets: orders, customers, products/inventory and analytics counts.
 *
 * Not used from the OAuth callback (install stays fast; use Dashboard Sync or cron + runOneSyncStep).
 *
 * @param string $shop
 * @param string $accessToken
 * @param array $tables output of ensurePerStoreTables()
 * @return void
 */
function fetchAndStoreInitialData(string $shop, string $accessToken, array $tables): void
{
    $mysqli = db();

    // ---- Analytics (counts) ----
    $ordersCount = shopifyRequest($shop, $accessToken, 'GET', '/orders/count.json');
    $productsCount = shopifyRequest($shop, $accessToken, 'GET', '/products/count.json');
    $customersCount = shopifyRequest($shop, $accessToken, 'GET', '/customers/count.json');

    if (isset($ordersCount['count'])) {
        upsertAnalyticsMetric($mysqli, $tables['analytics'], 'orders_count', (string)$ordersCount['count'], $ordersCount);
    }
    if (isset($productsCount['count'])) {
        upsertAnalyticsMetric($mysqli, $tables['analytics'], 'products_count', (string)$productsCount['count'], $productsCount);
    }
    if (isset($customersCount['count'])) {
        upsertAnalyticsMetric($mysqli, $tables['analytics'], 'customers_count', (string)$customersCount['count'], $customersCount);
    }

    // ---- Orders (sample recent) ----
    $orders = shopifyRequest($shop, $accessToken, 'GET', '/orders.json', [
        'status' => 'any',
        'limit' => 50,
        'order' => 'created_at desc',
    ]);
    if (isset($orders['orders']) && is_array($orders['orders'])) {
        $stmt = $mysqli->prepare("INSERT INTO `{$tables['order']}` (order_id, created_at, updated_at, payload_json)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), updated_at = VALUES(updated_at), payload_json = VALUES(payload_json), fetched_at = NOW()");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        foreach ($orders['orders'] as $o) {
            if (!isset($o['id'])) {
                continue;
            }
            $orderId = (int)$o['id'];
            $createdAt = isset($o['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$o['created_at'])) : null;
            $updatedAt = isset($o['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$o['updated_at'])) : null;
            $payload = json_encode($o, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param('isss', $orderId, $createdAt, $updatedAt, $payload);
            $stmt->execute();
        }
        $stmt->close();
    }

    // ---- Customers (sample recent) ----
    $customers = shopifyRequest($shop, $accessToken, 'GET', '/customers.json', [
        'limit' => 50,
        'order' => 'created_at desc',
    ]);
    if (isset($customers['customers']) && is_array($customers['customers'])) {
        $stmt = $mysqli->prepare("INSERT INTO `{$tables['customer']}` (customer_id, created_at, updated_at, payload_json)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), updated_at = VALUES(updated_at), payload_json = VALUES(payload_json), fetched_at = NOW()");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        foreach ($customers['customers'] as $c) {
            if (!isset($c['id'])) {
                continue;
            }
            $customerId = (int)$c['id'];
            $createdAt = isset($c['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$c['created_at'])) : null;
            $updatedAt = isset($c['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$c['updated_at'])) : null;
            $payload = json_encode($c, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param('isss', $customerId, $createdAt, $updatedAt, $payload);
            $stmt->execute();
        }
        $stmt->close();
    }

    // ---- Products + Inventory snapshot (variants carry inventory fields) ----
    $products = shopifyRequest($shop, $accessToken, 'GET', '/products.json', [
        'limit' => 50,
    ]);
    if (isset($products['products']) && is_array($products['products'])) {
        $stmt = $mysqli->prepare("INSERT INTO `{$tables['products_inventory']}` (
                product_id, variant_id, inventory_item_id, sku, title, inventory_quantity, payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                inventory_item_id = VALUES(inventory_item_id),
                sku = VALUES(sku),
                title = VALUES(title),
                inventory_quantity = VALUES(inventory_quantity),
                payload_json = VALUES(payload_json),
                fetched_at = NOW()");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }

        foreach ($products['products'] as $p) {
            if (!isset($p['id'])) {
                continue;
            }
            $productId = (int)$p['id'];
            $title = isset($p['title']) ? (string)$p['title'] : null;
            $variants = isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [];

            foreach ($variants as $v) {
                $variantId = isset($v['id']) ? (int)$v['id'] : null;
                if ($variantId === null) {
                    continue;
                }
                $inventoryItemId = isset($v['inventory_item_id']) ? (int)$v['inventory_item_id'] : null;
                $sku = $v['sku'] ?? null;
                $inventoryQty = isset($v['inventory_quantity']) ? (int)$v['inventory_quantity'] : null;

                $payload = json_encode(
                    ['product' => $p, 'variant' => $v],
                    JSON_UNESCAPED_UNICODE
                );

                $stmt->bind_param(
                    'iiissis',
                    $productId,
                    $variantId,
                    $inventoryItemId,
                    $sku,
                    $title,
                    $inventoryQty,
                    $payload
                );
                $stmt->execute();
            }
        }
        $stmt->close();
    }
}

/**
 * Create/refresh sync tasks for a shop.
 */
function enqueueFullSync(string $shop): void
{
    ensureGlobalAppSchema();
    $mysqli = db();

    foreach (['orders', 'customers', 'products'] as $resource) {
        $stmt = $mysqli->prepare(
            "INSERT INTO store_sync_state (shop, resource, next_page_info, status, last_error)
             VALUES (?, ?, NULL, 'pending', NULL)
             ON DUPLICATE KEY UPDATE status = IF(status='done','done','pending'), last_error = NULL"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $shop, $resource);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/**
 * Remove stored dashboard JSON cache so the next /api/dashboard request recomputes from DB.
 * Call after sync (or any bulk write) so KPIs/charts are not stuck on pre-sync zeros.
 */
function invalidateDashboardCache(string $shop): void
{
    static $cleared = [];
    if (isset($cleared[$shop])) {
        return;
    }
    $cleared[$shop] = true;

    try {
        $mysqli = db();
        $shopName = makeShopName($shop);
        $analyticsTable = perStoreTableName($shopName, 'analytics');
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $analyticsTable)) {
            return;
        }
        $safe = $mysqli->real_escape_string($analyticsTable);
        $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
        if (!$exists || $exists->num_rows < 1) {
            return;
        }
        $mysqli->query("DELETE FROM `{$safe}` WHERE metric_key IN ('dashboard_cache', 'dashboard_cache_sig')");
    } catch (Throwable $e) {
        // non-blocking
    }
}

/**
 * Cheap fingerprint of local row counts — must match dashboard_cache_sig when serving cached JSON.
 *
 * @return string|null "orders:customers:inventory_rows"
 */
function dashboardCacheLiveFingerprint(string $shop): ?string
{
    try {
        $mysqli = db();
        $shopName = makeShopName($shop);
        $ordersTable = perStoreTableName($shopName, 'order');
        $customersTable = perStoreTableName($shopName, 'customer');
        $inventoryTable = perStoreTableName($shopName, 'products_inventory');
        foreach ([$ordersTable, $customersTable, $inventoryTable] as $t) {
            if (!preg_match('/^[a-z0-9_]{1,64}$/', $t)) {
                return null;
            }
        }
        $o = 0;
        $c = 0;
        $p = 0;
        $resO = $mysqli->query("SELECT COUNT(*) AS c FROM `{$ordersTable}`");
        if (!$resO) {
            return null;
        }
        $o = (int)($resO->fetch_assoc()['c'] ?? 0);
        $resC = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}`");
        if (!$resC) {
            return null;
        }
        $c = (int)($resC->fetch_assoc()['c'] ?? 0);
        $resP = $mysqli->query("SELECT COUNT(*) AS c FROM `{$inventoryTable}`");
        if (!$resP) {
            return null;
        }
        $p = (int)($resP->fetch_assoc()['c'] ?? 0);

        return "{$o}:{$c}:{$p}";
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Shopify request that returns decoded JSON + headers + status.
 *
 * @return array{data:?array,http_code:int,headers:array<string,string|string[]>}
 */
function shopifyRequestWithMeta(
    string $shop,
    string $accessToken,
    string $method,
    string $path,
    ?array $query = null,
    ?array $data = null
): array {
    $apiVersion = SHOPIFY_API_VERSION;
    $url = "https://{$shop}/admin/api/{$apiVersion}" . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $respHeaders = [];
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'X-Shopify-Access-Token: ' . $accessToken,
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$respHeaders) {
        $len = strlen($headerLine);
        $headerLine = trim($headerLine);
        if ($headerLine === '' || strpos($headerLine, ':') === false) {
            return $len;
        }
        [$name, $value] = explode(':', $headerLine, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        if (isset($respHeaders[$name])) {
            if (!is_array($respHeaders[$name])) {
                $respHeaders[$name] = [$respHeaders[$name]];
            }
            $respHeaders[$name][] = $value;
        } else {
            $respHeaders[$name] = $value;
        }
        return $len;
    });

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || !$response) {
        debugLog('[shopify] request failed', [
            'shop' => $shop,
            'path' => $path,
            'curl_errno' => $errno,
            'curl_error' => $err,
            'http_code' => $httpCode,
        ]);
        return ['data' => null, 'http_code' => $httpCode, 'headers' => $respHeaders];
    }

    $decoded = json_decode($response, true);
    return ['data' => is_array($decoded) ? $decoded : null, 'http_code' => $httpCode, 'headers' => $respHeaders];
}

function parseNextPageInfo(?string $linkHeader): ?string
{
    if (!$linkHeader) {
        return null;
    }
    // Link: <https://...page_info=xyz...>; rel="next", <...>; rel="previous"
    foreach (explode(',', $linkHeader) as $part) {
        if (stripos($part, 'rel="next"') === false) {
            continue;
        }
        if (preg_match('/<([^>]+)>/', $part, $m)) {
            $url = $m[1];
            $qs = parse_url($url, PHP_URL_QUERY);
            if (is_string($qs)) {
                parse_str($qs, $q);
                if (isset($q['page_info']) && is_string($q['page_info']) && $q['page_info'] !== '') {
                    return $q['page_info'];
                }
            }
        }
    }
    return null;
}

function verifyWebhookHmac(string $rawBody, string $hmacHeader): bool
{
    $computed = base64_encode(hash_hmac('sha256', $rawBody, SHOPIFY_API_SECRET, true));
    return hash_equals($computed, trim($hmacHeader));
}

/**
 * Register the webhooks StoreIQ uses to stay in sync.
 */
function registerSalesboostWebhooks(string $shop, string $accessToken): void
{
    registerWebhooks($shop, $accessToken);
}

/**
 * Register required Shopify webhooks via Admin API 2026-01.
 */
function registerWebhooks(string $shop, string $token): void
{
    // Use BASE_URL for webhook addresses to match app routing (/app/...) consistently.
    $base = rtrim(defined('BASE_URL') ? BASE_URL : SHOPIFY_APP_URL, '/');
    $apiVersion = SHOPIFY_API_VERSION;
    $endpoint = "https://{$shop}/admin/api/{$apiVersion}/webhooks.json";

    $webhooks = [
        // Product mutations — drive hygiene re-checks.
        ['topic' => 'products/update', 'address' => $base . '/webhooks/products_update'],
        ['topic' => 'products/create', 'address' => $base . '/webhooks/handler'],
        ['topic' => 'products/delete', 'address' => $base . '/webhooks/handler'],

        // Inventory — used by hygiene rules (zero stock, etc.).
        ['topic' => 'inventory_levels/update', 'address' => $base . '/webhooks/handler'],

        // Billing state — keeps shop_subscriptions in sync if Shopify cancels a charge.
        ['topic' => 'app_subscriptions/update', 'address' => $base . '/webhooks/handler'],

        // App lifecycle.
        ['topic' => 'app/uninstalled', 'address' => $base . '/webhooks/app_uninstalled'],
    ];

    // NOTE:
    // Mandatory compliance webhooks are configured at app level (Partner Dashboard or app config),
    // not reliably through Admin REST webhook creation.
    debugLog('[webhooks] compliance topics must be configured in Partner Dashboard/app config', [
        'shop' => $shop,
        'topics' => [
            'customers/data_request' => $base . '/webhooks/customers_data_request',
            'customers/redact' => $base . '/webhooks/customers_redact',
            'shop/redact' => $base . '/webhooks/shop_redact',
        ],
    ]);

    foreach ($webhooks as $hook) {
        $payload = json_encode([
            'webhook' => [
                'topic' => $hook['topic'],
                'address' => $hook['address'],
                'format' => 'json',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $token,
        ]);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 201 = created, 422 = already exists (safe idempotency).
        if ($errno !== 0 || ($httpCode !== 201 && $httpCode !== 422)) {
            debugLog('[webhooks] register failed', [
                'shop' => $shop,
                'topic' => $hook['topic'],
                'address' => $hook['address'],
                'http_code' => $httpCode,
                'curl_errno' => $errno,
                'curl_error' => $error,
                'response' => is_string($resp) ? $resp : null,
            ]);
        }
    }
}

/**
 * Apply one webhook event payload to per-store tables.
 *
 * Idempotency:
 * - orders/customers: upsert by {id}
 * - products: upsert variants by {variant_id}; delete by product_id on products/delete
 * - inventory_levels/update: update rows by inventory_item_id
 */
function applyWebhookToStoreTables(string $shop, string $topic, string $rawJson): void
{
    $mysqli = db();
    $payload = json_decode($rawJson, true);
    if (!is_array($payload)) {
        return;
    }

    if ($topic === 'app_subscriptions/update') {
        $sub = isset($payload['app_subscription']) && is_array($payload['app_subscription'])
            ? $payload['app_subscription']
            : $payload;

        $gqlId = (string)($sub['admin_graphql_api_id'] ?? '');
        $statusRaw = strtolower(trim((string)($sub['status'] ?? '')));
        $name = (string)($sub['name'] ?? '');
        $currentPeriodEndRaw = (string)($sub['current_period_end'] ?? ($sub['currentPeriodEnd'] ?? ''));
        $chargeId = sbm_extract_gql_numeric_id($gqlId);
        $resolvedPlan = sbm_resolve_plan_from_text($name);
        $currentPeriodEndsAt = $currentPeriodEndRaw !== '' ? date('Y-m-d H:i:s', strtotime($currentPeriodEndRaw)) : null;

        // Keep DB aligned with Shopify subscription lifecycle.
        if (in_array($statusRaw, ['active', 'accepted'], true)) {
            setSubscriptionPlan($shop, $resolvedPlan, 'active', $chargeId, $currentPeriodEndsAt);
            return;
        }
        if (in_array($statusRaw, ['pending', 'pending_active'], true)) {
            setSubscriptionPlan($shop, $resolvedPlan, 'pending', $chargeId, $currentPeriodEndsAt);
            return;
        }
        if (in_array($statusRaw, ['cancelled', 'expired', 'frozen', 'declined'], true)) {
            setSubscriptionPlan($shop, 'free', 'free', null, null);
            return;
        }

        // Unknown status: do not destructively downgrade.
        $fallbackPlan = getCurrentPlanKey($shop);
        setSubscriptionPlan($shop, $fallbackPlan, $statusRaw !== '' ? $statusRaw : 'active', $chargeId, $currentPeriodEndsAt);
        return;
    }

    $tables = ensurePerStoreTables($shop);

    if (str_starts_with($topic, 'orders/')) {
        if (!isset($payload['id'])) {
            return;
        }
        $orderId = (int)$payload['id'];
        $createdAt = isset($payload['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$payload['created_at'])) : null;
        $updatedAt = isset($payload['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$payload['updated_at'])) : null;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $stmt = $mysqli->prepare("INSERT INTO `{$tables['order']}` (order_id, created_at, updated_at, payload_json)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), updated_at = VALUES(updated_at), payload_json = VALUES(payload_json), fetched_at = NOW()");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('isss', $orderId, $createdAt, $updatedAt, $payloadJson);
        $stmt->execute();
        $stmt->close();
        return;
    }

    if (str_starts_with($topic, 'customers/')) {
        if ($topic === 'customers/delete') {
            if (!isset($payload['id'])) {
                return;
            }
            $customerId = (int)$payload['id'];
            $stmt = $mysqli->prepare("DELETE FROM `{$tables['customer']}` WHERE customer_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $customerId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        if (!isset($payload['id'])) {
            return;
        }
        $customerId = (int)$payload['id'];
        $createdAt = isset($payload['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$payload['created_at'])) : null;
        $updatedAt = isset($payload['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$payload['updated_at'])) : null;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $stmt = $mysqli->prepare("INSERT INTO `{$tables['customer']}` (customer_id, created_at, updated_at, payload_json)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), updated_at = VALUES(updated_at), payload_json = VALUES(payload_json), fetched_at = NOW()");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('isss', $customerId, $createdAt, $updatedAt, $payloadJson);
        $stmt->execute();
        $stmt->close();
        return;
    }

    if (str_starts_with($topic, 'products/')) {
        if ($topic === 'products/delete') {
            if (!isset($payload['id'])) {
                return;
            }
            $productId = (int)$payload['id'];
            $stmt = $mysqli->prepare("DELETE FROM `{$tables['products_inventory']}` WHERE product_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $productId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        if (!isset($payload['id'])) {
            return;
        }

        $productId = (int)$payload['id'];
        $title = isset($payload['title']) ? (string)$payload['title'] : null;
        $variants = isset($payload['variants']) && is_array($payload['variants']) ? $payload['variants'] : [];

        $stmt = $mysqli->prepare("INSERT INTO `{$tables['products_inventory']}` (
                product_id, variant_id, inventory_item_id, sku, title, inventory_quantity, payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                inventory_item_id = VALUES(inventory_item_id),
                sku = VALUES(sku),
                title = VALUES(title),
                inventory_quantity = VALUES(inventory_quantity),
                payload_json = VALUES(payload_json),
                fetched_at = NOW()");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }

        foreach ($variants as $v) {
            $variantId = isset($v['id']) ? (int)$v['id'] : null;
            if ($variantId === null) {
                continue;
            }
            $inventoryItemId = isset($v['inventory_item_id']) ? (int)$v['inventory_item_id'] : null;
            $sku = $v['sku'] ?? null;
            $inventoryQty = isset($v['inventory_quantity']) ? (int)$v['inventory_quantity'] : null;
            $payloadJson = json_encode(['product' => $payload, 'variant' => $v], JSON_UNESCAPED_UNICODE);

            $stmt->bind_param('iiissis', $productId, $variantId, $inventoryItemId, $sku, $title, $inventoryQty, $payloadJson);
            $stmt->execute();
        }
        $stmt->close();
        return;
    }

    if ($topic === 'inventory_levels/update') {
        // Payload includes: inventory_item_id, available, location_id, updated_at
        if (!isset($payload['inventory_item_id'])) {
            return;
        }
        $inventoryItemId = (int)$payload['inventory_item_id'];
        $available = isset($payload['available']) ? (int)$payload['available'] : null;
        if ($available === null) {
            return;
        }

        $stmt = $mysqli->prepare("UPDATE `{$tables['products_inventory']}` SET inventory_quantity = ?, fetched_at = NOW() WHERE inventory_item_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $available, $inventoryItemId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    if ($topic === 'app/uninstalled') {
        // handled in webhook handler (stores.status = uninstalled)
        return;
    }
}

function sbm_extract_gql_numeric_id(string $gqlId): ?string
{
    if ($gqlId === '') {
        return null;
    }
    if (preg_match('~/(\d+)$~', $gqlId, $m) !== 1) {
        return null;
    }
    return (string)$m[1];
}

function sbm_resolve_plan_from_text(string $source): string
{
    $s = strtolower(trim($source));
    if ($s === '') {
        return 'free';
    }
    if (strpos($s, 'pro') !== false) {
        return 'pro';
    }
    if (strpos($s, 'growth') !== false) {
        return 'growth';
    }
    if (strpos($s, 'starter') !== false) {
        return 'starter';
    }
    if (strpos($s, 'free') !== false) {
        return 'free';
    }
    return 'free';
}

/**
 * Resolve a plan key (free/starter/growth/pro) to its plan id from the plans table.
 */
function getPlanIdByKey(string $planKey): int
{
    $planKey = normalizePlanKey($planKey);
    static $cache = [];
    if (isset($cache[$planKey])) {
        return $cache[$planKey];
    }
    $mysqli = db();
    $stmt = $mysqli->prepare("SELECT id FROM plans WHERE name = ? LIMIT 1");
    if (!$stmt) {
        return 1;
    }
    $stmt->bind_param('s', $planKey);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $id = is_array($row) ? (int)($row['id'] ?? 1) : 1;
    $cache[$planKey] = $id;
    return $id;
}

/**
 * Resolve a plan id to its key (free/starter/growth/pro).
 */
function getPlanKeyById(int $planId): string
{
    static $cache = [];
    if (isset($cache[$planId])) {
        return $cache[$planId];
    }
    $mysqli = db();
    $stmt = $mysqli->prepare("SELECT name FROM plans WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return 'free';
    }
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $key = normalizePlanKey(is_array($row) ? (string)($row['name'] ?? 'free') : 'free');
    $cache[$planId] = $key;
    return $key;
}

/**
 * Ensure a store has a subscription row (default: free / plan_id=1).
 */
function ensureFreeSubscription(string $shop): void
{
    ensureGlobalAppSchema();
    $store = getShopByDomain($shop);
    if (!is_array($store)) {
        return;
    }
    $shopId = (int)($store['id'] ?? 0);
    if ($shopId <= 0) {
        return;
    }

    $mysqli = db();
    $existing = $mysqli->prepare("SELECT id FROM shop_subscriptions WHERE shop_id = ? LIMIT 1");
    if (!$existing) {
        return;
    }
    $existing->bind_param('i', $shopId);
    $existing->execute();
    $res = $existing->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $existing->close();
    if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
        return; // already has a subscription row
    }

    $freePlanId = getPlanIdByKey('free');
    $status = 'active';
    $stmt = $mysqli->prepare(
        "INSERT INTO shop_subscriptions (shop_id, plan_id, status, activated_at)
         VALUES (?, ?, ?, NOW())"
    );
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param('iis', $shopId, $freePlanId, $status);
    $stmt->execute();
    $stmt->close();

    // Also update stores.plan_id to free (1) for consistency.
    $upd = $mysqli->prepare("UPDATE stores SET plan_id = ? WHERE id = ?");
    if ($upd) {
        $upd->bind_param('ii', $freePlanId, $shopId);
        $upd->execute();
        $upd->close();
    }
}

/**
 * Mark subscription cancelled on uninstall.
 */
function markSubscriptionUninstalled(string $shop): void
{
    $store = getShopByDomain($shop);
    if (!is_array($store)) {
        return;
    }
    $shopId = (int)($store['id'] ?? 0);
    if ($shopId <= 0) {
        return;
    }
    $mysqli = db();
    $stmt = $mysqli->prepare(
        "UPDATE shop_subscriptions
         SET status = 'cancelled', cancelled_at = NOW()
         WHERE shop_id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('i', $shopId);
        $stmt->execute();
        $stmt->close();
    }
}

function getSubscriptionByShop(string $shop): ?array
{
    $store = getShopByDomain($shop);
    if (!is_array($store)) {
        return null;
    }
    $shopId = (int)($store['id'] ?? 0);
    if ($shopId <= 0) {
        return null;
    }
    $mysqli = db();
    $stmt = $mysqli->prepare(
        "SELECT s.*, p.name AS plan_key
         FROM shop_subscriptions s
         JOIN plans p ON p.id = s.plan_id
         WHERE s.shop_id = ?
         ORDER BY s.id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $shopId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();
    if (is_array($row)) {
        $row['plan_key'] = normalizePlanKey((string)($row['plan_key'] ?? 'free'));
        $row['shop'] = $shop;
    }
    return $row;
}

/**
 * Canonical plan key for this app.
 *
 * Allowed values only:
 * - free
 * - starter
 * - growth
 * - pro
 */
function normalizePlanKey(string $planKey): string
{
    $key = strtolower(trim($planKey));
    if ($key === 'premium') {
        $key = 'pro';
    }
    return in_array($key, ['free', 'starter', 'growth', 'pro'], true) ? $key : 'free';
}

/**
 * Read current plan key for a shop (safe fallback to free).
 */
function getCurrentPlanKey(string $shop): string
{
    $sub = getSubscriptionByShop($shop);
    if (!is_array($sub)) {
        return 'free';
    }
    return normalizePlanKey((string)($sub['plan_key'] ?? 'free'));
}

/**
 * Subscription status considered paid/active for feature unlock logic.
 * Schema enum: pending, active, cancelled, expired, frozen.
 */
function isSubscriptionActive(array $sub): bool
{
    $status = strtolower(trim((string)($sub['status'] ?? '')));
    return $status === 'active';
}

/**
 * Upsert current subscription state (single row per shop_id).
 *
 * Caller passes a logical $status from the legacy API: 'pending' | 'active' | 'cancelled' | 'free'.
 * 'free' is mapped to 'active' (free plan rows are considered active in StoreIQ).
 * Also keeps stores.plan_id in sync.
 */
function setSubscriptionPlan(
    string $shop,
    string $planKey,
    string $status,
    ?string $shopifyChargeId,
    ?string $currentPeriodEndsAt
): void {
    $planKey = normalizePlanKey($planKey);
    $store = getShopByDomain($shop);
    if (!is_array($store)) {
        return;
    }
    $shopId = (int)($store['id'] ?? 0);
    if ($shopId <= 0) {
        return;
    }

    $planId = getPlanIdByKey($planKey);
    $statusNormalized = strtolower(trim($status));
    if ($statusNormalized === 'free' || $statusNormalized === '') {
        $statusNormalized = 'active';
    }
    if (!in_array($statusNormalized, ['pending', 'active', 'cancelled', 'expired', 'frozen'], true)) {
        $statusNormalized = 'active';
    }

    $billingOn = null;
    if ($currentPeriodEndsAt !== null && $currentPeriodEndsAt !== '') {
        $ts = strtotime($currentPeriodEndsAt);
        if ($ts !== false) {
            $billingOn = date('Y-m-d', $ts);
        }
    }

    $mysqli = db();

    // One subscription row per shop. Update most-recent if it exists, else insert.
    $existing = $mysqli->prepare("SELECT id FROM shop_subscriptions WHERE shop_id = ? ORDER BY id DESC LIMIT 1");
    $existingId = 0;
    if ($existing) {
        $existing->bind_param('i', $shopId);
        $existing->execute();
        $res = $existing->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $existing->close();
        $existingId = is_array($row) ? (int)($row['id'] ?? 0) : 0;
    }

    if ($existingId > 0) {
        $stmt = $mysqli->prepare(
            "UPDATE shop_subscriptions
             SET plan_id = ?,
                 status = ?,
                 shopify_charge_id = ?,
                 billing_on = ?,
                 activated_at = IF(? = 'active' AND activated_at IS NULL, NOW(), activated_at),
                 cancelled_at = IF(? = 'cancelled', NOW(), NULL)
             WHERE id = ?"
        );
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param(
            'isssssi',
            $planId,
            $statusNormalized,
            $shopifyChargeId,
            $billingOn,
            $statusNormalized,
            $statusNormalized,
            $existingId
        );
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $mysqli->prepare(
            "INSERT INTO shop_subscriptions
                (shop_id, plan_id, status, shopify_charge_id, billing_on, activated_at, cancelled_at)
             VALUES
                (?, ?, ?, ?, ?,
                 IF(? = 'active', NOW(), NULL),
                 IF(? = 'cancelled', NOW(), NULL))"
        );
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param(
            'iisssss',
            $shopId,
            $planId,
            $statusNormalized,
            $shopifyChargeId,
            $billingOn,
            $statusNormalized,
            $statusNormalized
        );
        $stmt->execute();
        $stmt->close();
    }

    // Keep stores.plan_id in sync so JOINs / quick reads stay accurate.
    $upd = $mysqli->prepare("UPDATE stores SET plan_id = ? WHERE id = ?");
    if ($upd) {
        $upd->bind_param('ii', $planId, $shopId);
        $upd->execute();
        $upd->close();
    }
}

/**
 * REST RecurringApplicationCharge helpers.
 * Docs: https://shopify.dev/docs/api/admin-rest/latest/resources/recurringapplicationcharge
 */
function createRecurringApplicationCharge(string $shop, string $token, array $charge): ?array
{
    $meta = shopifyRequestWithMeta($shop, $token, 'POST', '/recurring_application_charges.json', null, [
        'recurring_application_charge' => $charge,
    ]);
    $data = $meta['data'];
    if (!is_array($data)) {
        debugLog('[billing] recurring_charge_create_no_json', [
            'shop' => $shop,
            'http_code' => $meta['http_code'] ?? 0,
        ]);
        return null;
    }
    $rac = $data['recurring_application_charge'] ?? null;
    if (is_array($rac)) {
        return $rac;
    }
    debugLog('[billing] recurring_charge_create_failed', [
        'shop' => $shop,
        'http_code' => $meta['http_code'] ?? 0,
        'errors' => $data['errors'] ?? $data,
    ]);
    return null;
}

function getRecurringApplicationCharge(string $shop, string $token, string $chargeId): ?array
{
    $resp = shopifyRequest($shop, $token, 'GET', "/recurring_application_charges/{$chargeId}.json");
    $rac = $resp['recurring_application_charge'] ?? null;
    return is_array($rac) ? $rac : null;
}

function activateRecurringApplicationCharge(string $shop, string $token, string $chargeId): ?array
{
    $resp = shopifyRequest($shop, $token, 'POST', "/recurring_application_charges/{$chargeId}/activate.json");
    $rac = $resp['recurring_application_charge'] ?? null;
    return is_array($rac) ? $rac : null;
}

/**
 * Finalize billing for managed pricing page callbacks that return directly
 * to the embedded app URL with ?charge_id=... (without hitting billing/confirm).
 *
 * @return array{ok:bool,status:string,plan_key:string,charge_id:string}
 */
function sbm_finalize_billing_charge(string $shop, string $chargeId): array
{
    $shop = sanitizeShopDomain($shop);
    if ($shop === null || $chargeId === '') {
        throw new Exception('Invalid billing finalize parameters.');
    }

    $store = getShopByDomain($shop);
    $token = is_array($store) ? ($store['access_token'] ?? null) : null;
    if (!is_string($token) || $token === '') {
        throw new Exception('Missing access token for shop during billing finalize.');
    }

    $charge = getRecurringApplicationCharge($shop, $token, $chargeId);
    if (!is_array($charge)) {
        throw new Exception('Unable to fetch recurring charge for billing finalize.');
    }

    $status = (string)($charge['status'] ?? '');
    $chargeName = strtolower(trim((string)($charge['name'] ?? '')));
    $sub = getSubscriptionByShop($shop);
    $fallbackPlanKey = is_array($sub) ? normalizePlanKey((string)($sub['plan_key'] ?? 'free')) : 'free';

    $planKeyFromCharge = 'free';
    if (strpos($chargeName, 'pro') !== false) {
        $planKeyFromCharge = 'pro';
    } elseif (strpos($chargeName, 'growth') !== false) {
        $planKeyFromCharge = 'growth';
    } elseif (strpos($chargeName, 'starter') !== false) {
        $planKeyFromCharge = 'starter';
    }
    $resolvedPlanKey = ($planKeyFromCharge !== 'free') ? $planKeyFromCharge : $fallbackPlanKey;

    if ($status === 'accepted') {
        $activated = activateRecurringApplicationCharge($shop, $token, $chargeId);
        if (!is_array($activated)) {
            throw new Exception('Unable to activate recurring charge during billing finalize.');
        }
        $billingOn = isset($activated['billing_on']) ? (string)$activated['billing_on'] : null;
        $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;
        setSubscriptionPlan($shop, $resolvedPlanKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
        return ['ok' => true, 'status' => 'active', 'plan_key' => $resolvedPlanKey, 'charge_id' => (string)$chargeId];
    }

    if ($status === 'active') {
        $billingOn = isset($charge['billing_on']) ? (string)$charge['billing_on'] : null;
        $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;
        setSubscriptionPlan($shop, $resolvedPlanKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
        return ['ok' => true, 'status' => 'active', 'plan_key' => $resolvedPlanKey, 'charge_id' => (string)$chargeId];
    }

    setSubscriptionPlan($shop, 'free', 'free', null, null);
    return ['ok' => true, 'status' => $status !== '' ? $status : 'free', 'plan_key' => 'free', 'charge_id' => (string)$chargeId];
}

function maybeThrottleFromHeaders(array $headers): void
{
    $limit = $headers['x-shopify-shop-api-call-limit'] ?? null;
    if (!is_string($limit) || strpos($limit, '/') === false) {
        return;
    }
    [$used, $max] = array_map('intval', explode('/', $limit, 2));
    if ($max > 0 && $used >= ($max - 3)) {
        usleep(900000); // ~0.9s
    }
}

/**
 * Process exactly one page (limit=250) for one resource.
 *
 * @return array{done:bool,fetched:int,next_page_info:?string}
 */
function syncOnePage(string $shop, string $accessToken, array $tables, string $resource, ?string $pageInfo): array
{
    $mysqli = db();

    $path = '';
    $query = ['limit' => 250];
    $itemsKey = '';
    if ($pageInfo !== null) {
        $query = ['limit' => 250, 'page_info' => $pageInfo];
    } else {
        // First page: stable ordering
        if ($resource === 'orders') {
            $query += ['status' => 'any', 'order' => 'id asc'];
        } elseif ($resource === 'customers') {
            $query += ['order' => 'id asc'];
        }
    }

    if ($resource === 'orders') {
        $path = '/orders.json';
        $itemsKey = 'orders';
    } elseif ($resource === 'customers') {
        $path = '/customers.json';
        $itemsKey = 'customers';
    } elseif ($resource === 'products') {
        $path = '/products.json';
        $itemsKey = 'products';
    } else {
        throw new Exception('Unknown resource: ' . $resource);
    }

    $attempts = 0;
    while (true) {
        $attempts++;
        $resp = shopifyRequestWithMeta($shop, $accessToken, 'GET', $path, $query);
        $http = $resp['http_code'];
        $headers = $resp['headers'];

        if ($http === 429 && $attempts <= 3) {
            $retryAfter = $headers['retry-after'] ?? null;
            $sleep = is_string($retryAfter) ? max(1, (int)$retryAfter) : 2;
            sleep($sleep);
            continue;
        }
        if ($http < 200 || $http >= 300 || !is_array($resp['data'])) {
            $msg = "Shopify API error {$http} for {$resource}";
            debugLog('[sync] api error', ['shop' => $shop, 'resource' => $resource, 'http_code' => $http]);
            throw new Exception($msg);
        }

        maybeThrottleFromHeaders($headers);

        $data = $resp['data'];
        $items = (isset($data[$itemsKey]) && is_array($data[$itemsKey])) ? $data[$itemsKey] : [];

        // Upsert into per-store tables
        $fetched = 0;
        if ($resource === 'orders') {
            $stmt = $mysqli->prepare("INSERT INTO `{$tables['order']}` (order_id, created_at, updated_at, payload_json)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), updated_at = VALUES(updated_at), payload_json = VALUES(payload_json), fetched_at = NOW()");
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $mysqli->error);
            }
            foreach ($items as $o) {
                if (!isset($o['id'])) {
                    continue;
                }
                $orderId = (int)$o['id'];
                $createdAt = isset($o['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$o['created_at'])) : null;
                $updatedAt = isset($o['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$o['updated_at'])) : null;
                $payload = json_encode($o, JSON_UNESCAPED_UNICODE);
                $stmt->bind_param('isss', $orderId, $createdAt, $updatedAt, $payload);
                $stmt->execute();
                $fetched++;
            }
            $stmt->close();
        } elseif ($resource === 'customers') {
            $stmt = $mysqli->prepare("INSERT INTO `{$tables['customer']}` (customer_id, created_at, updated_at, payload_json)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE created_at = VALUES(created_at), updated_at = VALUES(updated_at), payload_json = VALUES(payload_json), fetched_at = NOW()");
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $mysqli->error);
            }
            foreach ($items as $c) {
                if (!isset($c['id'])) {
                    continue;
                }
                $customerId = (int)$c['id'];
                $createdAt = isset($c['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$c['created_at'])) : null;
                $updatedAt = isset($c['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$c['updated_at'])) : null;
                $payload = json_encode($c, JSON_UNESCAPED_UNICODE);
                $stmt->bind_param('isss', $customerId, $createdAt, $updatedAt, $payload);
                $stmt->execute();
                $fetched++;
            }
            $stmt->close();
        } else { // products
            $stmt = $mysqli->prepare("INSERT INTO `{$tables['products_inventory']}` (
                    product_id, variant_id, inventory_item_id, sku, title, inventory_quantity, payload_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    inventory_item_id = VALUES(inventory_item_id),
                    sku = VALUES(sku),
                    title = VALUES(title),
                    inventory_quantity = VALUES(inventory_quantity),
                    payload_json = VALUES(payload_json),
                    fetched_at = NOW()");
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $mysqli->error);
            }
            foreach ($items as $p) {
                if (!isset($p['id'])) {
                    continue;
                }
                $productId = (int)$p['id'];
                $title = isset($p['title']) ? (string)$p['title'] : null;
                $variants = isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [];
                foreach ($variants as $v) {
                    $variantId = isset($v['id']) ? (int)$v['id'] : null;
                    if ($variantId === null) {
                        continue;
                    }
                    $inventoryItemId = isset($v['inventory_item_id']) ? (int)$v['inventory_item_id'] : null;
                    $sku = $v['sku'] ?? null;
                    $inventoryQty = isset($v['inventory_quantity']) ? (int)$v['inventory_quantity'] : null;
                    $payload = json_encode(['product' => $p, 'variant' => $v], JSON_UNESCAPED_UNICODE);
                    $stmt->bind_param('iiissis', $productId, $variantId, $inventoryItemId, $sku, $title, $inventoryQty, $payload);
                    $stmt->execute();
                    $fetched++;
                }
            }
            $stmt->close();
        }

        $link = $headers['link'] ?? null;
        $next = parseNextPageInfo(is_string($link) ? $link : null);
        return ['done' => $next === null, 'fetched' => $fetched, 'next_page_info' => $next];
    }
}

/**
 * Run one "step" of full sync: picks one pending/in_progress task and syncs one page.
 *
 * @return array{shop:string,resource:string,done:bool,fetched:int}
 */
function runOneSyncStep(?string $shopFilter = null): array
{
    $mysqli = db();

    if ($shopFilter !== null) {
        $stmt = $mysqli->prepare("SELECT shop, resource, next_page_info, status FROM store_sync_state
            WHERE shop = ? AND status IN ('pending','in_progress')
            ORDER BY updated_at ASC LIMIT 1");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('s', $shopFilter);
    } else {
        $stmt = $mysqli->prepare("SELECT shop, resource, next_page_info, status FROM store_sync_state
            WHERE status IN ('pending','in_progress')
            ORDER BY updated_at ASC LIMIT 1");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();

    if (!$row) {
        return ['shop' => '', 'resource' => '', 'done' => true, 'fetched' => 0];
    }

    $shop = (string)$row['shop'];
    $resource = (string)$row['resource'];
    $pageInfo = isset($row['next_page_info']) ? (string)$row['next_page_info'] : null;
    if ($pageInfo === '') {
        $pageInfo = null;
    }

    $shopRow = getShopByDomain($shop);
    $token = is_array($shopRow) ? ($shopRow['access_token'] ?? null) : null;
    if (!is_string($token) || $token === '') {
        $upd = $mysqli->prepare("UPDATE store_sync_state SET status='error', last_error=? WHERE shop=? AND resource=?");
        if ($upd) {
            $err = 'Missing access token in stores table.';
            $upd->bind_param('sss', $err, $shop, $resource);
            $upd->execute();
            $upd->close();
        }
        return ['shop' => $shop, 'resource' => $resource, 'done' => true, 'fetched' => 0];
    }

    // Ensure per-store tables exist
    $tables = ensurePerStoreTables($shop);

    // Mark in-progress
    $mysqli->query("UPDATE store_sync_state SET status='in_progress', last_error=NULL WHERE shop='" . $mysqli->real_escape_string($shop) . "' AND resource='" . $mysqli->real_escape_string($resource) . "'");

    try {
        $out = syncOnePage($shop, $token, $tables, $resource, $pageInfo);
        $next = $out['next_page_info'];
        if ($out['done']) {
            $u = $mysqli->prepare("UPDATE store_sync_state SET status='done', next_page_info=NULL, last_error=NULL WHERE shop=? AND resource=?");
            if ($u) {
                $u->bind_param('ss', $shop, $resource);
                $u->execute();
                $u->close();
            }
        } else {
            $u = $mysqli->prepare("UPDATE store_sync_state SET status='in_progress', next_page_info=? WHERE shop=? AND resource=?");
            if ($u) {
                $u->bind_param('sss', $next, $shop, $resource);
                $u->execute();
                $u->close();
            }
        }
        // Drop cached dashboard so the next load runs full KPI/chart queries (avoids stale all-zero cache).
        invalidateDashboardCache($shop);

        return ['shop' => $shop, 'resource' => $resource, 'done' => $out['done'], 'fetched' => $out['fetched']];
    } catch (Throwable $e) {
        $u = $mysqli->prepare("UPDATE store_sync_state SET status='error', last_error=? WHERE shop=? AND resource=?");
        if ($u) {
            $msg = $e->getMessage();
            $u->bind_param('sss', $msg, $shop, $resource);
            $u->execute();
            $u->close();
        }
        throw $e;
    }
}

/**
 * Read analytics metrics from the per-store analytics table.
 *
 * @param string $shop
 * @return array<string, string> metric_key => metric_value
 */
function getStoredAnalyticsMetrics(string $shop): array
{
    $mysqli = db();
    $shopName = makeShopName($shop);
    $analyticsTable = perStoreTableName($shopName, 'analytics');

    // If the table doesn't exist yet, just return empty.
    $safe = $mysqli->real_escape_string($analyticsTable);
    $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if (!$exists || $exists->num_rows < 1) {
        return [];
    }

    $result = $mysqli->query("SELECT metric_key, metric_value FROM `{$analyticsTable}`");
    if (!$result) {
        return [];
    }

    $out = [];
    while ($row = $result->fetch_assoc()) {
        if (!isset($row['metric_key'])) {
            continue;
        }
        $out[(string)$row['metric_key']] = (string)($row['metric_value'] ?? '');
    }
    return $out;
}

/**
 * Persist or update shop access token.
 *
 * @param string $shopDomain
 * @param string $accessToken
 * @return void
 */
function saveShopAccessToken(string $shopDomain, string $accessToken): void
{
    // Legacy shim. New installs should use upsertStore().
    $shopDetails = fetchShopDetails($shopDomain, $accessToken);
    upsertStore($shopDomain, $accessToken, null, $shopDetails);
}

/**
 * Fetch shop record by domain.
 *
 * @param string $shopDomain
 * @return array|null
 */
function getShopByDomain(string $shopDomain): ?array
{
    $mysqli = db();

    $stmt = $mysqli->prepare("SELECT * FROM stores WHERE shop = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $shopDomain);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

/**
 * Generic Shopify REST Admin API request helper.
 *
 * @param string $shop
 * @param string $accessToken
 * @param string $method GET|POST|PUT|DELETE
 * @param string $path e.g. '/orders.json'
 * @param array|null $query
 * @param array|null $data
 * @return array|null
 */
function shopifyRequest(
    string $shop,
    string $accessToken,
    string $method,
    string $path,
    ?array $query = null,
    ?array $data = null
): ?array {
    $apiVersion = SHOPIFY_API_VERSION;

    $url = "https://{$shop}/admin/api/{$apiVersion}" . $path;

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'X-Shopify-Access-Token: ' . $accessToken,
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    if ($errno || !$response) {
        return null;
    }

    return json_decode($response, true);
}

/**
 * Convenience wrapper: fetch total orders count.
 *
 * @param string $shop
 * @param string $token
 * @return int|null
 */
function getOrders(string $shop, string $token): ?int
{
    $result = shopifyRequest($shop, $token, 'GET', '/orders/count.json');
    return isset($result['count']) ? (int)$result['count'] : null;
}

/**
 * Convenience wrapper: fetch total products count.
 *
 * @param string $shop
 * @param string $token
 * @return int|null
 */
function getProducts(string $shop, string $token): ?int
{
    $result = shopifyRequest($shop, $token, 'GET', '/products/count.json');
    return isset($result['count']) ? (int)$result['count'] : null;
}

/**
 * Convenience wrapper: fetch total customers count.
 *
 * @param string $shop
 * @param string $token
 * @return int|null
 */
function getCustomers(string $shop, string $token): ?int
{
    $result = shopifyRequest($shop, $token, 'GET', '/customers/count.json');
    return isset($result['count']) ? (int)$result['count'] : null;
}

