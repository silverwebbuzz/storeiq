<?php

/**
 * Shared UI helpers for StoreIQ pages: plan labels, upgrade URLs, locked feature blocks.
 */

if (!function_exists('siq_escape_html')) {
    function siq_escape_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

// SalesBoost compatibility alias.
if (!function_exists('sbm_escape_html')) {
    function sbm_escape_html(string $value): string
    {
        return siq_escape_html($value);
    }
}

if (!function_exists('siq_plan_label')) {
    function siq_plan_label(string $planKey): string
    {
        $map = [
            'free'    => 'Free',
            'starter' => 'Starter',
            'growth'  => 'Growth',
            'pro'     => 'Pro',
        ];
        $k = strtolower(trim($planKey));
        return $map[$k] ?? 'Starter';
    }
}

if (!function_exists('sbm_plan_label')) {
    function sbm_plan_label(string $planKey): string
    {
        return siq_plan_label($planKey);
    }
}

if (!function_exists('siq_shop_admin_handle')) {
    function siq_shop_admin_handle(string $shop): string
    {
        $s = strtolower(trim($shop));
        if ($s === '') {
            return '';
        }
        $parts = explode('.', $s);
        return trim((string)($parts[0] ?? ''));
    }
}

if (!function_exists('siq_upgrade_url')) {
    function siq_upgrade_url(string $shop = '', string $host = '', string $toPlan = 'starter'): string
    {
        $toPlan = strtolower(trim($toPlan));
        if ($toPlan === 'premium') {
            $toPlan = 'pro';
        }
        if (!in_array($toPlan, ['free', 'starter', 'growth', 'pro'], true)) {
            $toPlan = 'starter';
        }
        $adminHandle = siq_shop_admin_handle($shop);
        $appHandle = defined('SHOPIFY_APP_HANDLE') ? trim((string)SHOPIFY_APP_HANDLE) : '';

        $forceSubscribe = defined('SIQ_FORCE_BILLING_SUBSCRIBE') && (bool)SIQ_FORCE_BILLING_SUBSCRIBE;

        // Managed app pricing: a single Shopify Admin URL handles every tier.
        if (!$forceSubscribe && $adminHandle !== '' && $appHandle !== '') {
            return 'https://admin.shopify.com/store/' . rawurlencode($adminHandle)
                . '/charges/' . rawurlencode($appHandle)
                . '/pricing_plans';
        }

        $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
        $url = $base . '/billing/subscribe?plan=' . urlencode($toPlan);
        if ($shop !== '') {
            $url .= '&shop=' . urlencode($shop);
        }
        if ($host !== '') {
            $url .= '&host=' . urlencode($host);
        }
        return $url;
    }
}

if (!function_exists('sbm_upgrade_url')) {
    function sbm_upgrade_url(string $shop = '', string $host = '', string $toPlan = 'starter'): string
    {
        return siq_upgrade_url($shop, $host, $toPlan);
    }
}

if (!function_exists('renderLockedFeatureBlock')) {
    /**
     * Render a reusable lock/upgrade UI block.
     */
    function renderLockedFeatureBlock(
        string $title,
        string $description,
        string $requiredPlanKey = 'starter',
        ?string $upgradeUrl = null,
        string $shop = '',
        string $host = ''
    ): void {
        $planLabel = siq_plan_label($requiredPlanKey);
        $ctaUrl = $upgradeUrl ?? siq_upgrade_url($shop, $host, $requiredPlanKey);
        ?>
        <div class="feature-lock-overlay-inner">
          <div class="feature-lock-overlay-title"><?php echo siq_escape_html($title); ?></div>
          <div class="feature-lock-overlay-copy"><?php echo siq_escape_html($description); ?></div>
          <a class="feature-lock-cta" href="<?php echo siq_escape_html($ctaUrl); ?>">
            Upgrade to <?php echo siq_escape_html($planLabel); ?>
          </a>
          <div class="feature-lock-desc feature-lock-desc--hint">Included in <?php echo siq_escape_html($planLabel); ?> plan</div>
        </div>
        <?php
    }
}

if (!function_exists('siq_status_badge')) {
    /**
     * Renders a status badge span. Status: queued | running | completed | failed | reverted | scheduled | cancelled.
     */
    function siq_status_badge(string $status): string
    {
        $s = strtolower(trim($status));
        $label = ucfirst($s);
        return '<span class="siq-badge siq-badge--' . siq_escape_html($s) . '">' . siq_escape_html($label) . '</span>';
    }
}

if (!function_exists('siq_plan_badge')) {
    function siq_plan_badge(string $planKey): string
    {
        $key = strtolower(trim($planKey));
        $label = siq_plan_label($key);
        return '<span class="siq-plan-badge siq-plan-badge--' . siq_escape_html($key) . '">' . siq_escape_html($label) . '</span>';
    }
}

if (!function_exists('siq_health_color')) {
    /**
     * Returns CSS color class for a 0-100 health score: red <50, amber 50-79, green 80-100.
     */
    function siq_health_color(int $score): string
    {
        if ($score >= 80) return 'green';
        if ($score >= 50) return 'amber';
        return 'red';
    }
}
