<?php
/**
 * Main job-queue worker. Run by cron every 1 minute:
 *   * * * * * php /path/to/app/jobs/process_queue.php >> /path/to/app/logs/cron.log 2>&1
 *
 * Picks ONE pending job, dispatches to the right worker, exits.
 * One-job-per-run keeps overlapping crons safe.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/job_queue.php';
require_once __DIR__ . '/../lib/logger.php';

$job = pickNextJob();
if (!$job) {
    exit(0);
}

$jobId   = (int)$job['id'];
$jobType = (string)$job['job_type'];
sbm_log_write('cron', "process_queue: picked job #{$jobId} type={$jobType}");

try {
    switch ($jobType) {
        case 'bulk_edit_run':
        case 'bulk_edit_rollback':
            require_once __DIR__ . '/bulk_edit_worker.php';
            runBulkEditWorker($job);
            break;

        case 'campaign_apply':
        case 'campaign_revert':
            require_once __DIR__ . '/campaign_worker.php';
            runCampaignWorker($job);
            break;

        case 'hygiene_scan':
        case 'hygiene_auto_fix':
            require_once __DIR__ . '/hygiene_worker.php';
            runHygieneWorker($job);
            break;

        case 'digest_email':
            require_once __DIR__ . '/digest_email.php';
            runDigestEmailWorker($job);
            break;

        default:
            failJob($jobId, "Unknown job type: {$jobType}");
            sbm_log_write('cron', "process_queue: unknown job type {$jobType} (job #{$jobId})");
    }
} catch (Throwable $e) {
    failJob($jobId, $e->getMessage());
    sbm_log_write('cron', "process_queue: job #{$jobId} threw: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
    ]);
}
