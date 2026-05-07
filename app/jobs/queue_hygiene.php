<?php
/**
 * queue_hygiene — nightly cron (e.g. 02:00).
 * Pushes a hygiene_scan job for every active shop.
 *
 * On Mondays, also pushes a digest_email job for shops on Starter+.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/job_queue.php';
require_once __DIR__ . '/../lib/entitlements.php';
require_once __DIR__ . '/../lib/logger.php';

$shops = DBHelper::select(
    "SELECT id, shop FROM stores WHERE status = 'installed'"
) ?: [];

$queued = 0;
$digestQueued = 0;
$isMonday = (int)date('N') === 1;

foreach ($shops as $s) {
    $sid = (int)$s['id'];
    $shop = (string)$s['shop'];
    if (!hasPendingJobForReference($sid, 'hygiene_scan', $sid)) {
        pushJob($sid, 'hygiene_scan', $sid, ['trigger' => 'scheduled']);
        $queued++;
    }
    if ($isMonday) {
        $planKey = getCurrentPlanKey($shop);
        if (in_array($planKey, ['starter', 'growth', 'pro'], true)) {
            // Avoid double-queueing today.
            $today = date('Y-m-d');
            $already = DBHelper::selectOne(
                "SELECT id FROM job_queue
                 WHERE shop_id = ? AND job_type = 'digest_email' AND DATE(created_at) = ?
                 LIMIT 1",
                'is', [$sid, $today]
            );
            if (!$already) {
                pushJob($sid, 'digest_email', $sid);
                $digestQueued++;
            }
        }
    }
}

sbm_log_write('cron', "queue_hygiene: pushed scans for {$queued} shops, digest={$digestQueued}");
echo "queue_hygiene: scans={$queued} digest={$digestQueued}\n";
