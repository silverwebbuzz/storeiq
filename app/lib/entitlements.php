<?php

/**
 * StoreIQ plan entitlements — DB-driven.
 *
 * Unlike SalesBoost AI which hardcodes the plan matrix in PHP, StoreIQ reads
 * limits from the `plan_limits` table so admin can change them without a deploy.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/shopify.php'; // getCurrentPlanKey, getShopByDomain, getPlanIdByKey

if (!function_exists('getCurrentPlanId')) {
    /**
     * Returns the current plan_id for a shop (defaults to free / 1).
     */
    function getCurrentPlanId(string $shop): int
    {
        $store = getShopByDomain($shop);
        if (!is_array($store)) {
            return 1;
        }
        $sub = getSubscriptionByShop($shop);
        if (is_array($sub) && isset($sub['plan_id'])) {
            return (int)$sub['plan_id'];
        }
        return (int)($store['plan_id'] ?? 1);
    }
}

if (!function_exists('getPlanLimits')) {
    /**
     * Returns the plan_limits row for the shop's current plan.
     * Result is cached per-shop for the request lifetime.
     */
    function getPlanLimits(string $shop): array
    {
        static $cache = [];
        if (isset($cache[$shop])) {
            return $cache[$shop];
        }

        $planId = getCurrentPlanId($shop);
        $mysqli = db();
        $stmt = $mysqli->prepare("SELECT * FROM plan_limits WHERE plan_id = ? LIMIT 1");
        $row = null;
        if ($stmt) {
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!is_array($row)) {
            // Safe default: most-restrictive (free).
            $row = [
                'plan_id'                     => 1,
                'max_products_per_task'       => 50,
                'max_active_campaigns'        => 1,
                'can_schedule'                => 0,
                'can_auto_revert'             => 0,
                'can_recurring_campaigns'     => 0,
                'can_save_custom_templates'   => 0,
                'can_multiphase_campaigns'    => 0,
                'can_campaign_analytics'      => 0,
                'can_campaign_calendar'       => 0,
                'can_order_tagging'           => 0,
                'can_customer_tagging'        => 0,
                'can_formula_pricing'         => 0,
                'can_cross_app_promo'         => 0,
                'can_staff_activity_log'      => 0,
                'max_hygiene_rules'           => 3,
                'max_system_templates'        => 5,
                'undo_history_days'           => 7,
            ];
        }

        $cache[$shop] = $row;
        return $row;
    }
}

if (!function_exists('getPlanEntitlements')) {
    /**
     * Returns ['plan_key' => ..., 'plan_label' => ..., 'plan_id' => ..., 'limits' => [...]].
     */
    function getPlanEntitlements(string $shop): array
    {
        $planKey = getCurrentPlanKey($shop);
        $planId = getCurrentPlanId($shop);
        $limits = getPlanLimits($shop);
        $labels = [
            'free'    => 'Free',
            'starter' => 'Starter',
            'growth'  => 'Growth',
            'pro'     => 'Pro',
        ];
        return [
            'plan_key'   => $planKey,
            'plan_label' => $labels[$planKey] ?? ucfirst($planKey),
            'plan_id'    => $planId,
            'limits'     => $limits,
        ];
    }
}

if (!function_exists('canAccess')) {
    /**
     * Checks a can_* feature flag from plan_limits.
     * $feature is the suffix without the "can_" prefix:
     *   canAccess($shop, 'schedule') reads can_schedule.
     */
    function canAccess(string $shop, string $feature): bool
    {
        $limits = getPlanLimits($shop);
        $col = (strpos($feature, 'can_') === 0) ? $feature : ('can_' . $feature);
        return !empty($limits[$col]);
    }
}

if (!function_exists('canAccessFeature')) {
    // Compatibility shim with the SalesBoost name.
    function canAccessFeature(string $shop, string $feature): bool
    {
        return canAccess($shop, $feature);
    }
}

if (!function_exists('getLimit')) {
    /**
     * Returns a numeric limit value (e.g. max_products_per_task).
     */
    function getLimit(string $shop, string $limitKey): int
    {
        $limits = getPlanLimits($shop);
        return (int)($limits[$limitKey] ?? 0);
    }
}

if (!function_exists('getPlanLimit')) {
    function getPlanLimit(string $shop, string $limitKey): int
    {
        return getLimit($shop, $limitKey);
    }
}

if (!function_exists('isUnlimited')) {
    /**
     * 0 in plan_limits means "no cap".
     */
    function isUnlimited(string $shop, string $limitKey): bool
    {
        return getLimit($shop, $limitKey) === 0;
    }
}

if (!function_exists('getFeatureRequiredPlan')) {
    /**
     * Find the lowest plan that has a given feature flag enabled.
     * Returns plan key (free/starter/growth/pro) or null if no plan has it.
     */
    function getFeatureRequiredPlan(string $feature): ?string
    {
        $col = (strpos($feature, 'can_') === 0) ? $feature : ('can_' . $feature);
        if (!preg_match('/^can_[a-z_]+$/', $col)) {
            return null;
        }
        $mysqli = db();
        $sql = "SELECT p.name
                FROM plan_limits l
                JOIN plans p ON p.id = l.plan_id
                WHERE l.`{$col}` = 1
                ORDER BY p.sort_order ASC
                LIMIT 1";
        $res = $mysqli->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
        return is_array($row) ? (string)($row['name'] ?? null) : null;
    }
}
