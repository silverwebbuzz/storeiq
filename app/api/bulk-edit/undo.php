<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/job_queue.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$body = json_decode((string)file_get_contents('php://input'), true);
$jobId = (int)($body['job_id'] ?? $_GET['job_id'] ?? 0);
if ($jobId <= 0) { http_response_code(400); echo json_encode(['error' => 'invalid_job_id']); exit; }

$job = DBHelper::selectOne(
    "SELECT * FROM bulk_jobs WHERE id = ? AND shop_id = ? LIMIT 1",
    'ii', [$jobId, $shopId]
);
if (!$job) { http_response_code(404); echo json_encode(['error' => 'not_found']); exit; }
if ($job['status'] !== 'completed') { http_response_code(400); echo json_encode(['error' => 'only_completed_jobs_undoable']); exit; }

$undoDays = getLimit($shop, 'undo_history_days');
if ($undoDays > 0 && !empty($job['completed_at'])) {
    $age = (time() - strtotime($job['completed_at'])) / 86400;
    if ($age > $undoDays) {
        http_response_code(400);
        echo json_encode(['error' => 'undo_window_expired']);
        exit;
    }
}

DBHelper::execute(
    "UPDATE bulk_jobs SET status = 'queued' WHERE id = ?",
    'i', [$jobId]
);
$queueId = pushJob($shopId, 'bulk_edit_rollback', $jobId);

DBHelper::insert(
    "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
     VALUES (?, 'merchant', 'bulk_edit.undo', 'bulk_job', ?, ?)",
    'iis',
    [$shopId, $jobId, "Bulk edit rollback queued: {$job['name']}"]
);

echo json_encode(['success' => true, 'queue_job_id' => $queueId]);
