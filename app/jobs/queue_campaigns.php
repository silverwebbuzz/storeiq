<?php
/**
 * queue_campaigns — safety net cron (every 30 min).
 * Catches campaigns whose apply/revert jobs were missed (e.g. server downtime).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/job_queue.php';
require_once __DIR__ . '/../lib/logger.php';

$applyQueued = 0;
$revertQueued = 0;

// Apply: status=scheduled and start_at has passed.
$dueApply = DBHelper::select(
    "SELECT id, shop_id FROM campaigns
     WHERE status = 'scheduled' AND start_at <= NOW()"
) ?: [];
foreach ($dueApply as $c) {
    $cid = (int)$c['id']; $sid = (int)$c['shop_id'];
    if (!hasPendingJobForReference($sid, 'campaign_apply', $cid)) {
        pushJob($sid, 'campaign_apply', $cid);
        $applyQueued++;
    }
}

// Revert: status running/completed, auto_revert=1, end_at has passed.
$dueRevert = DBHelper::select(
    "SELECT id, shop_id FROM campaigns
     WHERE status IN ('running','completed') AND auto_revert = 1
       AND end_at IS NOT NULL AND end_at <= NOW()"
) ?: [];
foreach ($dueRevert as $c) {
    $cid = (int)$c['id']; $sid = (int)$c['shop_id'];
    if (!hasPendingJobForReference($sid, 'campaign_revert', $cid)) {
        pushJob($sid, 'campaign_revert', $cid);
        $revertQueued++;
    }
}

sbm_log_write('cron', "queue_campaigns: {$applyQueued} apply jobs, {$revertQueued} revert jobs queued");
echo "queue_campaigns: apply={$applyQueued} revert={$revertQueued}\n";
