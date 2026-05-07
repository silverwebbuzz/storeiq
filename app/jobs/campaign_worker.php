<?php
/**
 * Campaign worker — handles campaign_apply + campaign_revert.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/snapshot.php';
require_once __DIR__ . '/../lib/job_queue.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/bulk_edit_worker.php'; // reuses siq_fetch_products_page, siq_capture_before_state, siq_build_product_update

if (!function_exists('runCampaignWorker')) {
    function runCampaignWorker(array $queueJob): void
    {
        $jobType = (string)$queueJob['job_type'];
        $campaignId = (int)$queueJob['reference_id'];
        $shopId = (int)$queueJob['shop_id'];

        if ($jobType === 'campaign_apply') {
            siq_campaign_apply($campaignId, $shopId);
        } elseif ($jobType === 'campaign_revert') {
            siq_campaign_revert($campaignId, $shopId);
        } else {
            throw new Exception("Unknown campaign job type: {$jobType}");
        }
        completeJob((int)$queueJob['id']);
    }
}

if (!function_exists('siq_campaign_apply')) {
    function siq_campaign_apply(int $campaignId, int $shopId): void
    {
        $campaign = DBHelper::selectOne("SELECT * FROM campaigns WHERE id = ? LIMIT 1", 'i', [$campaignId]);
        if (!$campaign) throw new Exception("campaign not found: {$campaignId}");
        if ($campaign['status'] === 'cancelled') return;

        $store = DBHelper::selectOne("SELECT shop, access_token FROM stores WHERE id = ? LIMIT 1", 'i', [$shopId]);
        if (!$store) throw new Exception("store not found: {$shopId}");
        $shop = (string)$store['shop'];
        $token = (string)$store['access_token'];

        $payload = json_decode((string)$campaign['filter_json'], true);
        $filter = is_array($payload['filter'] ?? null) ? $payload['filter'] : [];
        $actions = is_array($payload['_actions'] ?? null) ? $payload['_actions'] : [];

        DBHelper::execute("UPDATE campaigns SET status = 'running' WHERE id = ?", 'i', [$campaignId]);
        DBHelper::insert(
            "INSERT INTO campaign_logs (campaign_id, event_type, message) VALUES (?, 'started', ?)",
            'is', [$campaignId, "apply started"]
        );

        // Re-shape actions into the bulk_job_actions format used by siq_build_product_update.
        $actionRows = [];
        foreach ($actions as $a) {
            $actionRows[] = [
                'action_type' => (string)($a['action_type'] ?? ''),
                'params_json' => json_encode($a['params'] ?? [], JSON_UNESCAPED_UNICODE),
            ];
        }

        $pageInfo = null;
        $processed = 0;
        $failed = 0;
        do {
            $page = siq_fetch_products_page($shop, $token, $filter, $pageInfo);
            $products = $page['products'];
            $pageInfo = $page['next'];

            foreach ($products as $p) {
                $productId = (int)($p['id'] ?? 0);
                if ($productId <= 0) continue;

                $beforeState = siq_capture_before_state($p, $actionRows);
                saveCampaignSnapshot($campaignId, $productId, $beforeState);

                $update = siq_build_product_update($p, $actionRows);
                if (!$update) {
                    $processed++;
                    continue;
                }
                try {
                    shopifyRequest($shop, $token, 'PUT', "/products/{$productId}.json", null, ['product' => $update]);
                    DBHelper::insert(
                        "INSERT INTO campaign_logs (campaign_id, event_type, shopify_product_id) VALUES (?, 'product_updated', ?)",
                        'ii', [$campaignId, $productId]
                    );
                } catch (Throwable $e) {
                    $failed++;
                    DBHelper::insert(
                        "INSERT INTO campaign_logs (campaign_id, event_type, message, shopify_product_id) VALUES (?, 'product_failed', ?, ?)",
                        'isi', [$campaignId, $e->getMessage(), $productId]
                    );
                }
                $processed++;
                usleep(500000);
            }
        } while ($pageInfo);

        DBHelper::execute(
            "UPDATE campaigns SET status = 'running', total_products = ? WHERE id = ?",
            'ii', [$processed, $campaignId]
        );
        DBHelper::insert(
            "INSERT INTO campaign_logs (campaign_id, event_type, message) VALUES (?, 'completed', ?)",
            'is', [$campaignId, "apply phase done: {$processed} processed, {$failed} failed"]
        );
        DBHelper::insert(
            "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
             VALUES (?, 'system', 'campaign.applied', 'campaign', ?, ?)",
            'iis',
            [$shopId, $campaignId, "Campaign applied: {$campaign['name']} ({$processed} products)"]
        );
    }
}

if (!function_exists('siq_campaign_revert')) {
    function siq_campaign_revert(int $campaignId, int $shopId): void
    {
        $campaign = DBHelper::selectOne("SELECT * FROM campaigns WHERE id = ? LIMIT 1", 'i', [$campaignId]);
        if (!$campaign) throw new Exception("campaign not found: {$campaignId}");

        $store = DBHelper::selectOne("SELECT shop, access_token FROM stores WHERE id = ? LIMIT 1", 'i', [$shopId]);
        if (!$store) throw new Exception("store not found: {$shopId}");
        $shop = (string)$store['shop'];
        $token = (string)$store['access_token'];

        DBHelper::insert(
            "INSERT INTO campaign_logs (campaign_id, event_type, message) VALUES (?, 'revert_started', ?)",
            'is', [$campaignId, "revert started"]
        );

        $snaps = getCampaignSnapshots($campaignId, true);
        $skipped = 0;
        $reverted = 0;
        foreach ($snaps as $snap) {
            $productId = (int)$snap['shopify_product_id'];
            try {
                $resp = shopifyRequest($shop, $token, 'GET', "/products/{$productId}.json");
                $current = is_array($resp['product'] ?? null) ? $resp['product'] : null;
            } catch (Throwable $e) {
                $current = null;
            }
            if ($current && checkManualEdit($snap, $current)) {
                $skipped++;
                DBHelper::insert(
                    "INSERT INTO campaign_logs (campaign_id, event_type, message, shopify_product_id)
                     VALUES (?, 'product_failed', 'manual_edit_detected_skipped', ?)",
                    'ii', [$campaignId, $productId]
                );
                markSnapshotReverted((int)$snap['id']); // don't keep retrying
                usleep(500000);
                continue;
            }

            $before = json_decode((string)$snap['before_json'], true);
            if (!is_array($before)) { markSnapshotReverted((int)$snap['id']); continue; }

            $update = ['id' => $productId];
            if (isset($before['tags']))         $update['tags']        = (string)$before['tags'];
            if (isset($before['vendor']))       $update['vendor']      = (string)$before['vendor'];
            if (isset($before['product_type'])) $update['product_type'] = (string)$before['product_type'];
            if (isset($before['status']))       $update['status']      = (string)$before['status'];
            if (isset($before['body_html']))    $update['body_html']   = (string)$before['body_html'];
            if (!empty($before['variants']) && is_array($before['variants'])) {
                $update['variants'] = [];
                foreach ($before['variants'] as $v) {
                    $update['variants'][] = array_filter([
                        'id'               => $v['id'] ?? null,
                        'price'            => $v['price'] ?? null,
                        'compare_at_price' => $v['compare_at'] ?? ($v['compare_at_price'] ?? null),
                    ], fn($x) => $x !== null);
                }
            }
            try {
                shopifyRequest($shop, $token, 'PUT', "/products/{$productId}.json", null, ['product' => $update]);
                markSnapshotReverted((int)$snap['id']);
                DBHelper::insert(
                    "INSERT INTO campaign_logs (campaign_id, event_type, shopify_product_id) VALUES (?, 'reverted', ?)",
                    'ii', [$campaignId, $productId]
                );
                $reverted++;
            } catch (Throwable $e) {
                DBHelper::insert(
                    "INSERT INTO campaign_logs (campaign_id, event_type, message, shopify_product_id) VALUES (?, 'product_failed', ?, ?)",
                    'isi', [$campaignId, $e->getMessage(), $productId]
                );
            }
            usleep(500000);
        }

        DBHelper::execute("UPDATE campaigns SET status = 'reverted' WHERE id = ?", 'i', [$campaignId]);
        DBHelper::insert(
            "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
             VALUES (?, 'system', 'campaign.reverted', 'campaign', ?, ?)",
            'iis',
            [$shopId, $campaignId, "Campaign reverted: {$campaign['name']} ({$reverted} reverted, {$skipped} skipped due to manual edits)"]
        );
    }
}
