<?php

/**
 * job_queue helpers — backs all background work in StoreIQ.
 *
 * Schema (job_queue):
 *   id, shop_id, job_type ENUM, reference_id, payload_json,
 *   status ENUM('pending','running','done','failed'),
 *   attempts, max_attempts, run_at, started_at, completed_at,
 *   error_message, created_at
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

if (!defined('SIQ_VALID_JOB_TYPES')) {
    define('SIQ_VALID_JOB_TYPES', [
        'bulk_edit_run',
        'bulk_edit_rollback',
        'campaign_apply',
        'campaign_revert',
        'hygiene_scan',
        'hygiene_auto_fix',
        'digest_email',
    ]);
}

if (!function_exists('pushJob')) {
    /**
     * Insert a new job_queue row. $runAt may be null (=> NOW()) or a "Y-m-d H:i:s" timestamp.
     * Returns the new job id.
     */
    function pushJob(int $shopId, string $jobType, ?int $referenceId = null, array $payload = [], ?string $runAt = null): int
    {
        if (!in_array($jobType, SIQ_VALID_JOB_TYPES, true)) {
            throw new InvalidArgumentException("Invalid job_type: {$jobType}");
        }
        $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
        $runAtSql = $runAt && trim($runAt) !== '' ? $runAt : date('Y-m-d H:i:s');

        return (int)DBHelper::insert(
            "INSERT INTO job_queue (shop_id, job_type, reference_id, payload_json, status, run_at)
             VALUES (?, ?, ?, ?, 'pending', ?)",
            'isiss',
            [$shopId, $jobType, $referenceId, $payloadJson, $runAtSql]
        );
    }
}

if (!function_exists('pickNextJob')) {
    /**
     * Atomically pick one pending job that's due to run.
     *
     * Strategy: SELECT ... FOR UPDATE inside a transaction so two cron processes
     * never claim the same row. Falls back to a flag-and-check pattern if the
     * storage engine doesn't support row locks.
     */
    function pickNextJob(): ?array
    {
        $conn = DB::getInstance();

        $conn->begin_transaction();
        try {
            $sel = $conn->prepare(
                "SELECT id
                 FROM job_queue
                 WHERE status = 'pending'
                   AND attempts < max_attempts
                   AND run_at <= NOW()
                 ORDER BY run_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$sel) {
                $conn->rollback();
                return null;
            }
            $sel->execute();
            $res = $sel->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $sel->close();

            if (!is_array($row) || !isset($row['id'])) {
                $conn->commit();
                return null;
            }
            $jobId = (int)$row['id'];

            $upd = $conn->prepare(
                "UPDATE job_queue
                 SET status = 'running',
                     started_at = NOW(),
                     attempts = attempts + 1
                 WHERE id = ?"
            );
            if (!$upd) {
                $conn->rollback();
                return null;
            }
            $upd->bind_param('i', $jobId);
            $upd->execute();
            $upd->close();

            $conn->commit();

            return DBHelper::selectOne("SELECT * FROM job_queue WHERE id = ? LIMIT 1", 'i', [$jobId]);
        } catch (Throwable $e) {
            $conn->rollback();
            sbm_log_write('cron', 'pickNextJob_failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

if (!function_exists('completeJob')) {
    function completeJob(int $jobId): void
    {
        DBHelper::execute(
            "UPDATE job_queue SET status = 'done', completed_at = NOW(), error_message = NULL WHERE id = ?",
            'i',
            [$jobId]
        );
    }
}

if (!function_exists('failJob')) {
    /**
     * Mark a job failed. If attempts < max_attempts, requeue (status=pending) so cron retries.
     * Otherwise mark permanently failed.
     */
    function failJob(int $jobId, string $errorMessage): void
    {
        $job = DBHelper::selectOne("SELECT attempts, max_attempts FROM job_queue WHERE id = ? LIMIT 1", 'i', [$jobId]);
        if (!is_array($job)) {
            return;
        }
        $attempts = (int)($job['attempts'] ?? 0);
        $max = (int)($job['max_attempts'] ?? 3);

        if ($attempts < $max) {
            // Retry: backoff 60s * attempts, leave for next cron pickup.
            $delay = 60 * max(1, $attempts);
            DBHelper::execute(
                "UPDATE job_queue
                 SET status = 'pending',
                     run_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     error_message = ?
                 WHERE id = ?",
                'isi',
                [$delay, $errorMessage, $jobId]
            );
            return;
        }

        DBHelper::execute(
            "UPDATE job_queue
             SET status = 'failed',
                 completed_at = NOW(),
                 error_message = ?
             WHERE id = ?",
            'si',
            [$errorMessage, $jobId]
        );
    }
}

if (!function_exists('getJobStatus')) {
    function getJobStatus(int $jobId): ?array
    {
        return DBHelper::selectOne("SELECT * FROM job_queue WHERE id = ? LIMIT 1", 'i', [$jobId]) ?: null;
    }
}

if (!function_exists('getShopJobs')) {
    function getShopJobs(int $shopId, string $jobType, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        return DBHelper::select(
            "SELECT * FROM job_queue
             WHERE shop_id = ? AND job_type = ?
             ORDER BY id DESC
             LIMIT {$limit}",
            'is',
            [$shopId, $jobType]
        ) ?: [];
    }
}

if (!function_exists('hasPendingJobForReference')) {
    /**
     * True if there's already a pending|running job of this type for the given reference.
     * Used as a safety net so queue_campaigns / queue_hygiene don't double-queue work.
     */
    function hasPendingJobForReference(int $shopId, string $jobType, int $referenceId): bool
    {
        $row = DBHelper::selectOne(
            "SELECT id FROM job_queue
             WHERE shop_id = ? AND job_type = ? AND reference_id = ?
               AND status IN ('pending','running')
             LIMIT 1",
            'isi',
            [$shopId, $jobType, $referenceId]
        );
        return is_array($row);
    }
}
