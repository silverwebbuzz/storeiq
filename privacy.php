<?php $contactEmail = 'support@silverwebbuzz.com'; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Policy — SWB StoreIQ</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="hero" style="padding-bottom:32px;">
    <nav class="hero-nav">
      <a class="brand" href="index.html" style="color:#fff;text-decoration:none;">SWB StoreIQ</a>
      <a class="cta" href="index.html">Home</a>
    </nav>
    <div class="hero-inner" style="padding:32px;">
      <h1>Privacy Policy</h1>
      <p class="hero-sub">Last updated: <?php echo htmlspecialchars(date('F j, Y'), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
  </header>

  <main style="max-width: 820px; margin: 0 auto; padding: 40px 32px; line-height: 1.6;">
    <h2>What we collect</h2>
    <p>When you install SWB StoreIQ on your Shopify store, we receive limited information from Shopify needed to operate the app: shop domain, store name, contact email, and the OAuth access token. We also read product, inventory and (where granted) order metadata from your Shopify store solely to provide bulk-edit, campaign and hygiene-scan functionality.</p>

    <h2>How we use your data</h2>
    <ul>
      <li>To execute bulk product edits, scheduled campaigns and hygiene scans you initiate from the app.</li>
      <li>To compute health scores and surface flagged products inside the app.</li>
      <li>To send weekly digest and alert emails to your store contact (you can disable in Settings).</li>
      <li>To process subscription billing through Shopify (we do not see your payment card data).</li>
    </ul>

    <h2>What we do not do</h2>
    <p>We do not sell or share your data with third parties for marketing. We do not contact your customers. We do not store credit-card numbers.</p>

    <h2>Data retention</h2>
    <p>We retain shop and product metadata while the app is installed. On uninstall we mark your shop inactive and stop background work. On request via the GDPR <code>shop/redact</code> webhook (issued by Shopify 48 hours after uninstall), we delete shop-related data from our systems.</p>

    <h2>Your rights</h2>
    <p>You may request access to, or deletion of, the data we hold about your shop at any time. Send an email to
    <a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?></a>.</p>

    <h2>Contact</h2>
    <p>SilverWebBuzz · <a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?></a></p>
  </main>

  <footer class="site-footer">
    <div>SWB StoreIQ · <a href="index.html">Home</a></div>
  </footer>
</body>
</html>
