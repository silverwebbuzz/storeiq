<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$jobId = (int)($_GET['job_id'] ?? 0);
if (!$shop || $jobId <= 0) { http_response_code(400); echo json_encode(['error' => 'bad_request']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$job = DBHelper::selectOne(
    "SELECT * FROM bulk_jobs WHERE id = ? AND shop_id = ? LIMIT 1",
    'ii', [$jobId, $shopId]
);
if (!$job) { http_response_code(404); echo json_encode(['error' => 'not_found']); exit; }

$actions = DBHelper::select(
    "SELECT id, sort_order, action_type, params_json FROM bulk_job_actions WHERE job_id = ? ORDER BY sort_order ASC",
    'i', [$jobId]
) ?: [];

$stats = DBHelper::selectOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
     FROM bulk_job_items WHERE job_id = ?",
    'i', [$jobId]
);

// Can undo if completed within undo_history_days.
$undoDays = getLimit($shop, 'undo_history_days');
$canUndo = false;
if ($job['status'] === 'completed' && !empty($job['completed_at']) && $undoDays > 0) {
    $age = (time() - strtotime($job['completed_at'])) / 86400;
    $canUndo = ($age <= $undoDays);
}

echo json_encode([
    'job' => $job,
    'actions' => $actions,
    'progress' => $stats ?: ['total' => 0, 'success_count' => 0, 'failed_count' => 0, 'pending_count' => 0],
    'can_undo' => $canUndo,
]);
