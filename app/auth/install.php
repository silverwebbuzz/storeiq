<?php
/**
 * Shopify OAuth installation entry point.
 *
 * Expected entry:
 *   /app/auth/install.php?shop=storename.myshopify.com
 *
 * Aligns with newcode/install.php:
 * - Top-level: 302 to Shopify OAuth (fast path).
 * - embedded=1: small HTML + App Bridge (data-api-key) + redirects.toRemote / top fallback
 *   so OAuth runs outside the Admin iframe.
 */

require_once __DIR__ . '/../config.php';

sendEmbeddedAppHeaders();
startEmbeddedSession();

$debug = SHOPIFY_DEBUG || (($_GET['debug'] ?? '') === '1');

$rawShop = $_GET['shop'] ?? null;
$shop    = sanitizeShopDomain($rawShop);

if ($shop === null) {
    http_response_code(400);
    echo 'Invalid shop parameter.';
    exit;
}

$hostParam = isset($_GET['host']) && is_string($_GET['host']) ? trim($_GET['host']) : '';
$embedded  = (int)($_GET['embedded'] ?? 0);

$state = bin2hex(random_bytes(16));
$_SESSION['nonce'] = $state;
$_SESSION['shop'] = $shop;

debugLog('[install] state created', [
    'shop' => $shop,
    'debug' => $debug,
    'embedded' => $embedded,
]);

$installUrl = function_exists('buildInstallUrl')
    ? buildInstallUrl($shop, $state)
    : ("https://{$shop}/admin/oauth/authorize?" . http_build_query([
        'client_id' => SHOPIFY_API_KEY,
        'scope' => SHOPIFY_SCOPES,
        'redirect_uri' => SHOPIFY_REDIRECT_URI,
        'state' => $state,
    ]));

if ($debug) {
    $installUrl .= (strpos($installUrl, '?') !== false ? '&' : '?') . 'debug=1';
}

// newcode: already installed and opened inside Admin iframe → app UI (avoid OAuth loop)
$existing = getShopByDomain($shop);
$hasToken = is_array($existing) && is_string($existing['access_token'] ?? null) && ($existing['access_token'] ?? '') !== '';
if ($hasToken && $embedded === 1 && $hostParam !== '') {
    $dash = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/dashboard?shop=' . urlencode($shop) . '&host=' . urlencode($hostParam);
    header('Location: ' . $dash);
    exit;
}

// newcode: embedded install must break out of iframe before OAuth
if ($embedded === 1) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(200);
    $apiKeyEsc  = htmlspecialchars((string)SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8');
    $authUrlEsc = htmlspecialchars($installUrl, ENT_QUOTES, 'UTF-8');
    $authJson   = json_encode($installUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- App Bridge MUST be first — data-api-key enables shopify.redirects.toRemote() -->
  <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" data-api-key="<?php echo $apiKeyEsc; ?>"></script>
  <title>Installing StoreIQ…</title>
  <style>
    body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f6f6f7;}
    .box{text-align:center;padding:32px 24px;max-width:400px;}
    .spinner{width:32px;height:32px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 16px;}
    @keyframes spin{to{transform:rotate(360deg)}}
    p{color:#374151;font-size:15px;margin:0 0 20px;}
    .auth-btn{display:inline-block;padding:10px 20px;background:#6366f1;color:#fff;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;}
    .auth-btn:hover{background:#4f46e5;}
  </style>
</head>
<body>
  <div class="box">
    <div class="spinner" id="spinner"></div>
    <p id="msg">Connecting to Shopify…</p>
    <!--
      target="_top" is the most reliable cross-origin iframe escape hatch.
      Browsers allow it when triggered by a real user click (user gesture).
      It also works as a plain link even if JS is disabled.
    -->
    <a id="authLink" class="auth-btn" href="<?php echo $authUrlEsc; ?>" target="_top" style="display:none;">
      Authorize StoreIQ
    </a>
  </div>
  <script>
  (function () {
    var authUrl = <?php echo $authJson; ?>;
    var link    = document.getElementById('authLink');
    var msg     = document.getElementById('msg');
    var spinner = document.getElementById('spinner');

    function showFallback() {
      if (spinner) spinner.style.display = 'none';
      if (msg)  msg.textContent = 'Click below to authorize StoreIQ in your Shopify store.';
      if (link) link.style.display = 'inline-block';
    }

    function tryRedirect() {
      // 1. New App Bridge CDN — shopify.redirects.toRemote() posts a message to the
      //    Shopify Admin frame, which then performs a safe top-level navigation.
      try {
        var s = window.shopify;
        if (s && s.redirects && typeof s.redirects.toRemote === 'function') {
          s.redirects.toRemote(authUrl);
          return; // Admin will handle the navigation; nothing else to do here.
        }
      } catch (e1) {}

      // 2. window.open with _top target — allowed in cross-origin iframes when
      //    called from a user gesture (DOMContentLoaded counts in some browsers
      //    but not all). We try it anyway before falling back to the button.
      try {
        var w = window.open(authUrl, '_top');
        if (w !== null) return;
      } catch (e2) {}

      // 3. Nothing worked automatically — show the manual "Authorize" button.
      //    Clicking it IS a user gesture, so target="_top" on the <a> tag works.
      showFallback();
    }

    // Give App Bridge a moment to finish initializing, then attempt the redirect.
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { setTimeout(tryRedirect, 150); });
    } else {
      setTimeout(tryRedirect, 150);
    }

    // Safety net: if still on this page after 3 seconds, show the button.
    setTimeout(showFallback, 3000);
  })();
  </script>
</body>
</html>
    <?php
    exit;
}

debugLog('[install] redirect', [
    'shop' => $shop,
    'has_state' => strpos($installUrl, 'state=') !== false,
    'installUrl' => $installUrl,
]);

header('Location: ' . $installUrl);
exit;
