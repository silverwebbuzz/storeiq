<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/job_queue.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

if (hasPendingJobForReference($shopId, 'hygiene_scan', $shopId)) {
    echo json_encode(['success' => true, 'queued' => false, 'reason' => 'already_pending']);
    exit;
}
$queueId = pushJob($shopId, 'hygiene_scan', $shopId, ['trigger' => 'manual']);
echo json_encode(['success' => true, 'queued' => true, 'queue_job_id' => $queueId]);
