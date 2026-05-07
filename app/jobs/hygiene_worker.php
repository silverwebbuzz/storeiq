<?php
/**
 * Hygiene scan worker — handles hygiene_scan + hygiene_auto_fix.
 *
 * For each enabled rule, walks every product (and aggregates inventory/sku duplicates)
 * and upserts hygiene_flags. Computes a 0–100 health score.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/job_queue.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/entitlements.php';

if (!function_exists('runHygieneWorker')) {
    function runHygieneWorker(array $queueJob): void
    {
        $jobType = (string)$queueJob['job_type'];
        $shopId = (int)$queueJob['shop_id'];
        if ($jobType !== 'hygiene_scan' && $jobType !== 'hygiene_auto_fix') {
            throw new Exception("Unknown hygiene job: {$jobType}");
        }

        $store = DBHelper::selectOne("SELECT shop, access_token FROM stores WHERE id = ? LIMIT 1", 'i', [$shopId]);
        if (!$store) throw new Exception("store not found: {$shopId}");
        $shop = (string)$store['shop'];
        $token = (string)$store['access_token'];

        $maxRules = getLimit($shop, 'max_hygiene_rules');
        $planId = getCurrentPlanId($shop);

        $sql = "SELECT r.id, r.code, r.severity, r.entity_type
                FROM hygiene_rule_definitions r
                JOIN shop_hygiene_rules s ON s.rule_id = r.id AND s.shop_id = ?
                WHERE r.is_active = 1 AND s.is_enabled = 1 AND r.plan_required <= ?
                ORDER BY r.id ASC";
        if ($maxRules > 0) $sql .= " LIMIT " . (int)$maxRules;
        $rules = DBHelper::select($sql, 'ii', [$shopId, $planId]) ?: [];

        $payload = json_decode((string)($queueJob['payload_json'] ?? '{}'), true);
        $trigger = (string)($payload['trigger'] ?? 'scheduled');

        $runId = (int)DBHelper::insert(
            "INSERT INTO hygiene_scan_runs (shop_id, trigger_type, started_at) VALUES (?, ?, NOW())",
            'is', [$shopId, $trigger]
        );

        // If no rules enabled, finalize quickly.
        if (!$rules) {
            DBHelper::execute(
                "UPDATE hygiene_scan_runs SET completed_at = NOW(), products_scanned = 0, flags_found = 0, health_score = 100 WHERE id = ?",
                'i', [$runId]
            );
            completeJob((int)$queueJob['id']);
            return;
        }

        // Walk all products via REST cursor pagination.
        $pageInfo = null;
        $productsScanned = 0;
        $skuMap = [];
        $newCritical = 0; $newWarning = 0; $newInfo = 0;
        $resolvedCount = 0;
        $seenFlagKeys = []; // (rule_id|product_id) signatures detected this run.

        do {
            $qs = ['limit' => 50];
            if ($pageInfo) $qs['page_info'] = $pageInfo;
            $meta = shopifyRequestWithMeta($shop, $token, 'GET', '/products.json?' . http_build_query($qs));
            $data = is_array($meta['data'] ?? null) ? $meta['data'] : [];
            $products = is_array($data['products'] ?? null) ? $data['products'] : [];

            foreach ($products as $p) {
                $productsScanned++;
                $pid = (int)($p['id'] ?? 0);
                if ($pid <= 0) continue;

                foreach ($rules as $rule) {
                    $code = (string)$rule['code'];
                    $sev  = (string)$rule['severity'];
                    if ((string)$rule['entity_type'] !== 'product') continue;

                    if (siq_hygiene_product_matches($code, $p, $skuMap, $pid)) {
                        $key = $rule['id'] . '|' . $pid;
                        $seenFlagKeys[$key] = true;
                        $existing = DBHelper::selectOne(
                            "SELECT id FROM hygiene_flags WHERE shop_id = ? AND rule_id = ? AND shopify_entity_id = ? AND status = 'open' LIMIT 1",
                            'iii', [$shopId, (int)$rule['id'], $pid]
                        );
                        if (!$existing) {
                            DBHelper::insert(
                                "INSERT INTO hygiene_flags (shop_id, rule_id, entity_type, shopify_entity_id, entity_title, details_json, status)
                                 VALUES (?, ?, 'product', ?, ?, NULL, 'open')",
                                'iiis', [$shopId, (int)$rule['id'], $pid, (string)($p['title'] ?? '')]
                            );
                            if ($sev === 'critical') $newCritical++;
                            elseif ($sev === 'warning') $newWarning++;
                            else $newInfo++;
                        }
                    }
                }
            }

            $linkHeader = (string)($meta['headers']['link'] ?? '');
            $pageInfo = parseNextPageInfo($linkHeader);
            usleep(500000);
        } while ($pageInfo);

        // Resolve open flags that didn't fire this run (= product now passes the rule).
        $allOpen = DBHelper::select(
            "SELECT id, rule_id, shopify_entity_id FROM hygiene_flags WHERE shop_id = ? AND status = 'open'",
            'i', [$shopId]
        ) ?: [];
        foreach ($allOpen as $row) {
            $key = $row['rule_id'] . '|' . $row['shopify_entity_id'];
            if (!isset($seenFlagKeys[$key])) {
                DBHelper::execute(
                    "UPDATE hygiene_flags SET status = 'fixed', resolved_at = NOW() WHERE id = ?",
                    'i', [(int)$row['id']]
                );
                $resolvedCount++;
            }
        }

        $totalFlags = $newCritical + $newWarning + $newInfo;
        $score = (int)max(0, min(100, 100 - ($newCritical * 5 + $newWarning * 2 + (int)floor($newInfo * 0.5))));

        DBHelper::execute(
            "UPDATE hygiene_scan_runs
             SET completed_at = NOW(), products_scanned = ?, flags_found = ?, flags_resolved = ?, health_score = ?
             WHERE id = ?",
            'iiiii', [$productsScanned, $totalFlags, $resolvedCount, $score, $runId]
        );
        DBHelper::insert(
            "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
             VALUES (?, 'system', 'hygiene.scan_completed', 'hygiene_scan_runs', ?, ?)",
            'iis',
            [$shopId, $runId, "Hygiene scan completed: score={$score}, {$totalFlags} flags found, {$resolvedCount} resolved"]
        );

        completeJob((int)$queueJob['id']);
    }
}

if (!function_exists('siq_hygiene_product_matches')) {
    /**
     * Returns true if the given rule fires for this product.
     */
    function siq_hygiene_product_matches(string $code, array $p, array &$skuMap, int $pid): bool
    {
        switch ($code) {
            case 'no_description':
                return trim(strip_tags((string)($p['body_html'] ?? ''))) === '';
            case 'no_images':
                return empty($p['images']) || !is_array($p['images']);
            case 'no_alt_text':
                if (empty($p['images']) || !is_array($p['images'])) return false;
                foreach ($p['images'] as $img) {
                    if (trim((string)($img['alt'] ?? '')) === '') return true;
                }
                return false;
            case 'no_seo_title':
                // SEO title lives on metafields; skip unless present in payload.
                return empty($p['metafields_global_title_tag'] ?? null);
            case 'zero_price':
                if (!empty($p['variants'])) {
                    foreach ($p['variants'] as $v) {
                        if ((float)($v['price'] ?? 0) <= 0) return true;
                    }
                }
                return false;
            case 'compare_at_lower':
                if (!empty($p['variants'])) {
                    foreach ($p['variants'] as $v) {
                        $cmp = $v['compare_at_price'] ?? null;
                        if ($cmp !== null && (float)$cmp > 0 && (float)$cmp < (float)($v['price'] ?? 0)) return true;
                    }
                }
                return false;
            case 'compare_at_equals_price':
                if (!empty($p['variants'])) {
                    foreach ($p['variants'] as $v) {
                        $cmp = $v['compare_at_price'] ?? null;
                        if ($cmp !== null && (float)$cmp > 0 && (float)$cmp == (float)($v['price'] ?? 0)) return true;
                    }
                }
                return false;
            case 'out_of_stock_live':
                if (($p['status'] ?? '') !== 'active') return false;
                if (!empty($p['variants'])) {
                    foreach ($p['variants'] as $v) {
                        if ((string)($v['inventory_management'] ?? '') === 'shopify' && (int)($v['inventory_quantity'] ?? 0) <= 0) return true;
                    }
                }
                return false;
            case 'negative_inventory':
                if (!empty($p['variants'])) {
                    foreach ($p['variants'] as $v) {
                        if ((int)($v['inventory_quantity'] ?? 0) < 0) return true;
                    }
                }
                return false;
            case 'duplicate_sku':
                if (!empty($p['variants'])) {
                    foreach ($p['variants'] as $v) {
                        $sku = trim((string)($v['sku'] ?? ''));
                        if ($sku === '') continue;
                        if (isset($skuMap[$sku]) && $skuMap[$sku] !== $pid) return true;
                        $skuMap[$sku] = $pid;
                    }
                }
                return false;
            case 'dead_stock_30':
            case 'dead_stock_60':
                // Requires order data — out of scope for product-only scan. Skip.
                return false;
            default:
                return false;
        }
    }
}
