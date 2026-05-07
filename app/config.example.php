<?php
/**
 * StoreIQ config — TEMPLATE.
 *
 * Copy this file to `app/config.php` and fill in real values.
 * `app/config.php` is gitignored so secrets never reach the repo.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---- Core URLs / Paths ----
define('BASE_PATH', '/absolute/path/to/storeiq/app');
define('BASE_URL',  'https://your-domain.example.com/storeiq/app');

// ---- Database ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'silverwebbuzz_in_storeiq');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// ---- Shopify App ----
define('SHOPIFY_APP_NAME',   'SWB StoreIQ');
define('SHOPIFY_APP_HANDLE', 'swb-storeiq');         // matches the handle in Shopify Partner Dashboard
define('SHOPIFY_APP_URL',    BASE_URL);

define('SHOPIFY_API_KEY',    'YOUR_SHOPIFY_API_KEY');
define('SHOPIFY_API_SECRET', 'YOUR_SHOPIFY_API_SECRET');
define('SHOPIFY_SCOPES',     'read_orders,read_products,read_customers,read_inventory,read_analytics');
define('SHOPIFY_API_VERSION','2026-01');

// Callback is rewritten to /auth/callback by .htaccess.
define('SHOPIFY_REDIRECT_URI', SHOPIFY_APP_URL . '/auth/callback');

// ---- Debug / Cookies ----
define('SHOPIFY_DEBUG', (getenv('SHOPIFY_DEBUG') ?: '') === '1');
define('COOKIE_DOMAIN', getenv('COOKIE_DOMAIN') ?: '.your-domain.example.com');
define('CRON_KEY',      getenv('CRON_KEY') ?: '');   // shared secret for /jobs/process_webhooks?key=...

// ---- Optional: third-party keys ----
// define('ANTHROPIC_API_KEY', 'sk-ant-...');         // only if you wire AI features

/**
 * Start a session compatible with Shopify embedded context.
 */
function startEmbeddedSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (!headers_sent()) {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $params['lifetime'] ?? 0,
            'path'     => $params['path'] ?? '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'None',
        ]);
    }
    session_start();
}

/**
 * Send headers required for embedded Shopify apps (iframe allowed).
 */
function sendEmbeddedAppHeaders(): void
{
    if (headers_sent()) return;
    header("Content-Security-Policy: frame-ancestors https://admin.shopify.com https://*.myshopify.com;");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/**
 * Append a debug line to a local log file.
 */
function debugLog(string $message, array $context = []): void
{
    $dir = __DIR__ . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE);
        if ($json !== false) $line .= ' ' . $json;
    }
    @file_put_contents($dir . '/shopify.log', $line . PHP_EOL, FILE_APPEND);
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/shopify.php';
