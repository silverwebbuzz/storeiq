<?php
/**
 * Bulk edit worker — handles bulk_edit_run + bulk_edit_rollback job types.
 * Invoked from process_queue.php with the job row from job_queue.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/entitlements.php';
require_once __DIR__ . '/../lib/snapshot.php';
require_once __DIR__ . '/../lib/job_queue.php';
require_once __DIR__ . '/../lib/logger.php';

if (!function_exists('runBulkEditWorker')) {
    function runBulkEditWorker(array $queueJob): void
    {
        $jobType = (string)$queueJob['job_type'];
        $jobId   = (int)$queueJob['reference_id'];
        $shopId  = (int)$queueJob['shop_id'];

        if ($jobType === 'bulk_edit_run') {
            siq_bulk_edit_run($jobId, $shopId);
        } elseif ($jobType === 'bulk_edit_rollback') {
            siq_bulk_edit_rollback($jobId, $shopId);
        } else {
            throw new Exception("Unknown bulk_edit job type: {$jobType}");
        }
        completeJob((int)$queueJob['id']);
    }
}

if (!function_exists('siq_bulk_edit_run')) {
    function siq_bulk_edit_run(int $jobId, int $shopId): void
    {
        $job = DBHelper::selectOne("SELECT * FROM bulk_jobs WHERE id = ? LIMIT 1", 'i', [$jobId]);
        if (!$job) throw new Exception("bulk_job not found: {$jobId}");

        $store = DBHelper::selectOne("SELECT shop, access_token FROM stores WHERE id = ? LIMIT 1", 'i', [$shopId]);
        if (!$store) throw new Exception("store not found: {$shopId}");
        $shop = (string)$store['shop'];
        $token = (string)$store['access_token'];

        $actions = DBHelper::select(
            "SELECT action_type, params_json FROM bulk_job_actions WHERE job_id = ? ORDER BY sort_order ASC",
            'i', [$jobId]
        ) ?: [];
        if (!$actions) throw new Exception("bulk_job has no actions: {$jobId}");

        DBHelper::execute("UPDATE bulk_jobs SET status = 'running', started_at = NOW() WHERE id = ?", 'i', [$jobId]);

        $filter = json_decode((string)($job['filter_json'] ?? '{}'), true);
        if (!is_array($filter)) $filter = [];
        $maxProducts = getLimit($shop, 'max_products_per_task');

        $pageInfo = null;
        $processed = 0;
        $failed = 0;

        do {
            $page = siq_fetch_products_page($shop, $token, $filter, $pageInfo);
            $products = $page['products'];
            $pageInfo = $page['next'];

            foreach ($products as $p) {
                if ($maxProducts > 0 && $processed >= $maxProducts) {
                    break 2;
                }

                $productId = (int)($p['id'] ?? 0);
                if ($productId <= 0) continue;

                $beforeState = siq_capture_before_state($p, $actions);
                saveBulkSnapshot($jobId, $productId, $beforeState);

                $update = siq_build_product_update($p, $actions);
                if (!$update) {
                    DBHelper::insert(
                        "INSERT INTO bulk_job_items (job_id, shopify_product_id, product_title, status, processed_at)
                         VALUES (?, ?, ?, 'skipped', NOW())",
                        'iis', [$jobId, $productId, (string)($p['title'] ?? '')]
                    );
                    $processed++;
                    continue;
                }

                try {
                    $resp = shopifyRequest($shop, $token, 'PUT', "/products/{$productId}.json", null, ['product' => $update]);
                    $ok = is_array($resp) && isset($resp['product']);
                    DBHelper::insert(
                        "INSERT INTO bulk_job_items (job_id, shopify_product_id, product_title, status, error_message, processed_at)
                         VALUES (?, ?, ?, ?, ?, NOW())",
                        'iisss',
                        [$jobId, $productId, (string)($p['title'] ?? ''), $ok ? 'success' : 'failed', $ok ? null : 'shopify_update_no_product_in_response']
                    );
                    if (!$ok) $failed++;
                } catch (Throwable $e) {
                    DBHelper::insert(
                        "INSERT INTO bulk_job_items (job_id, shopify_product_id, product_title, status, error_message, processed_at)
                         VALUES (?, ?, ?, 'failed', ?, NOW())",
                        'iiss', [$jobId, $productId, (string)($p['title'] ?? ''), $e->getMessage()]
                    );
                    $failed++;
                }

                $processed++;
                DBHelper::execute(
                    "UPDATE bulk_jobs SET processed = ?, failed_count = ? WHERE id = ?",
                    'iii', [$processed, $failed, $jobId]
                );
                usleep(500000); // 2 req/sec
            }
        } while ($pageInfo);

        DBHelper::execute(
            "UPDATE bulk_jobs
             SET status = 'completed', completed_at = NOW(),
                 total_products = ?, processed = ?, failed_count = ?
             WHERE id = ?",
            'iiii', [$processed, $processed, $failed, $jobId]
        );

        DBHelper::insert(
            "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
             VALUES (?, 'system', 'bulk_edit.completed', 'bulk_job', ?, ?)",
            'iis',
            [$shopId, $jobId, "Bulk edit completed: {$processed} products ({$failed} failed)"]
        );
    }
}

if (!function_exists('siq_bulk_edit_rollback')) {
    function siq_bulk_edit_rollback(int $jobId, int $shopId): void
    {
        $store = DBHelper::selectOne("SELECT shop, access_token FROM stores WHERE id = ? LIMIT 1", 'i', [$shopId]);
        if (!$store) throw new Exception("store not found: {$shopId}");
        $shop = (string)$store['shop'];
        $token = (string)$store['access_token'];

        $snaps = getBulkSnapshots($jobId);
        foreach ($snaps as $snap) {
            $productId = (int)$snap['shopify_product_id'];
            $before = json_decode((string)$snap['snapshot_json'], true);
            if (!is_array($before)) continue;

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
            } catch (Throwable $e) {
                sbm_log_write('cron', 'rollback_product_failed', ['job_id' => $jobId, 'product_id' => $productId, 'error' => $e->getMessage()]);
            }
            usleep(500000);
        }

        DBHelper::execute("UPDATE bulk_jobs SET status = 'rolled_back', completed_at = NOW() WHERE id = ?", 'i', [$jobId]);
        DBHelper::insert(
            "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
             VALUES (?, 'system', 'bulk_edit.rolled_back', 'bulk_job', ?, ?)",
            'iis',
            [$shopId, $jobId, "Bulk edit rolled back: " . count($snaps) . " products"]
        );
    }
}

/* ── helpers ─────────────────────────────────────────── */

if (!function_exists('siq_fetch_products_page')) {
    function siq_fetch_products_page(string $shop, string $token, array $filter, ?string $pageInfo): array
    {
        $qs = ['limit' => 50];
        if ($pageInfo) {
            $qs['page_info'] = $pageInfo;
        } else {
            if (!empty($filter['collection_id']))   $qs['collection_id'] = $filter['collection_id'];
            if (!empty($filter['vendor']))          $qs['vendor'] = $filter['vendor'];
            if (!empty($filter['product_type']))    $qs['product_type'] = $filter['product_type'];
        }
        $path = '/products.json?' . http_build_query($qs);
        $meta = shopifyRequestWithMeta($shop, $token, 'GET', $path);
        $data = is_array($meta['data'] ?? null) ? $meta['data'] : [];
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];

        // Tag filter is client-side (REST doesn't filter by tag).
        if (!empty($filter['tags'])) {
            $needles = array_map('strtolower', (array)$filter['tags']);
            $products = array_values(array_filter($products, function ($p) use ($needles) {
                $tags = array_map('trim', explode(',', strtolower((string)($p['tags'] ?? ''))));
                foreach ($needles as $n) {
                    if (in_array($n, $tags, true)) return true;
                }
                return false;
            }));
        }

        $linkHeader = (string)($meta['headers']['link'] ?? '');
        $next = parseNextPageInfo($linkHeader);

        return ['products' => $products, 'next' => $next];
    }
}

if (!function_exists('siq_capture_before_state')) {
    function siq_capture_before_state(array $product, array $actions): array
    {
        $touchesPrice  = false;
        $touchesTags   = false;
        $touchesStatus = false;
        $touchesSeo    = false;
        $touchesVendor = false;
        $touchesType   = false;
        $touchesDesc   = false;
        foreach ($actions as $a) {
            $t = (string)($a['action_type'] ?? '');
            if (strpos($t, 'price') === 0 || strpos($t, 'compare_at') === 0) $touchesPrice = true;
            if (strpos($t, 'tag_') === 0) $touchesTags = true;
            if (strpos($t, 'status_') === 0) $touchesStatus = true;
            if (strpos($t, 'seo_') === 0 || $t === 'alt_text_set') $touchesSeo = true;
            if ($t === 'vendor_set') $touchesVendor = true;
            if ($t === 'product_type_set') $touchesType = true;
            if (strpos($t, 'description_') === 0) $touchesDesc = true;
        }

        $state = [];
        if ($touchesTags)   $state['tags'] = (string)($product['tags'] ?? '');
        if ($touchesStatus) $state['status'] = (string)($product['status'] ?? '');
        if ($touchesVendor) $state['vendor'] = (string)($product['vendor'] ?? '');
        if ($touchesType)   $state['product_type'] = (string)($product['product_type'] ?? '');
        if ($touchesDesc)   $state['body_html'] = (string)($product['body_html'] ?? '');
        if ($touchesPrice && !empty($product['variants']) && is_array($product['variants'])) {
            $state['variants'] = [];
            foreach ($product['variants'] as $v) {
                $state['variants'][] = [
                    'id'         => $v['id'] ?? null,
                    'price'      => $v['price'] ?? null,
                    'compare_at' => $v['compare_at_price'] ?? null,
                ];
            }
        }
        return $state;
    }
}

if (!function_exists('siq_build_product_update')) {
    function siq_build_product_update(array $product, array $actions): ?array
    {
        $update = ['id' => (int)($product['id'] ?? 0)];
        $tags = (string)($product['tags'] ?? '');
        $tagList = array_filter(array_map('trim', explode(',', $tags)));

        $variants = [];
        if (!empty($product['variants']) && is_array($product['variants'])) {
            foreach ($product['variants'] as $v) {
                $variants[(string)$v['id']] = [
                    'id'               => $v['id'],
                    'price'            => (string)($v['price'] ?? ''),
                    'compare_at_price' => $v['compare_at_price'] ?? null,
                ];
            }
        }

        $touched = false;

        foreach ($actions as $a) {
            $type = (string)($a['action_type'] ?? '');
            $params = json_decode((string)($a['params_json'] ?? '{}'), true) ?: [];

            switch ($type) {
                case 'price_change_percent':
                    $direction = (string)($params['direction'] ?? 'decrease');
                    $value = (float)($params['value'] ?? 0);
                    $factor = $direction === 'increase' ? (1 + $value/100) : (1 - $value/100);
                    foreach ($variants as $vid => $v) {
                        $variants[$vid]['price'] = number_format(((float)$v['price']) * $factor, 2, '.', '');
                    }
                    $touched = true; break;

                case 'price_change_fixed':
                    $direction = (string)($params['direction'] ?? 'decrease');
                    $value = (float)($params['value'] ?? 0);
                    foreach ($variants as $vid => $v) {
                        $newP = $direction === 'increase' ? ((float)$v['price'] + $value) : ((float)$v['price'] - $value);
                        $variants[$vid]['price'] = number_format(max(0, $newP), 2, '.', '');
                    }
                    $touched = true; break;

                case 'compare_at_set':
                    $value = $params['value'] ?? null;
                    foreach ($variants as $vid => $v) {
                        $variants[$vid]['compare_at_price'] = $value !== null ? (string)$value : (string)$v['price'];
                    }
                    $touched = true; break;

                case 'compare_at_remove':
                    foreach ($variants as $vid => $_) {
                        $variants[$vid]['compare_at_price'] = null;
                    }
                    $touched = true; break;

                case 'tag_add':
                    $tag = trim((string)($params['tag'] ?? ''));
                    if ($tag !== '' && !in_array($tag, $tagList, true)) { $tagList[] = $tag; $touched = true; }
                    break;
                case 'tag_remove':
                    $tag = trim((string)($params['tag'] ?? ''));
                    $tagList = array_values(array_filter($tagList, fn($t) => strcasecmp($t, $tag) !== 0));
                    $touched = true; break;
                case 'tag_replace':
                    $from = trim((string)($params['from'] ?? ''));
                    $to   = trim((string)($params['to'] ?? ''));
                    foreach ($tagList as $i => $t) {
                        if (strcasecmp($t, $from) === 0) { $tagList[$i] = $to; $touched = true; }
                    }
                    break;

                case 'status_publish':
                    $update['status'] = 'active'; $touched = true; break;
                case 'status_unpublish':
                    $update['status'] = 'draft'; $touched = true; break;
                case 'status_archive':
                    $update['status'] = 'archived'; $touched = true; break;

                case 'vendor_set':
                    $update['vendor'] = (string)($params['value'] ?? ''); $touched = true; break;
                case 'product_type_set':
                    $update['product_type'] = (string)($params['value'] ?? ''); $touched = true; break;

                case 'description_append':
                    $update['body_html'] = ((string)($product['body_html'] ?? '')) . (string)($params['value'] ?? '');
                    $touched = true; break;
                case 'description_find_replace':
                    $update['body_html'] = str_replace(
                        (string)($params['find'] ?? ''),
                        (string)($params['replace'] ?? ''),
                        (string)($product['body_html'] ?? '')
                    );
                    $touched = true; break;

                // SEO and alt-text live on metafields/images — out of scope for the minimal worker.
                default:
                    break;
            }
        }

        if (!$touched) return null;

        if ($variants) {
            $update['variants'] = array_values($variants);
        }
        $update['tags'] = implode(', ', $tagList);
        return $update;
    }
}
