<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/job_queue.php';
require_once __DIR__ . '/../../lib/logger.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'invalid_body']); exit; }

$name    = trim((string)($body['name'] ?? ''));
$filter  = is_array($body['filter']  ?? null) ? $body['filter']  : [];
$actions = is_array($body['actions'] ?? null) ? $body['actions'] : [];
$runAt   = isset($body['run_at']) && is_string($body['run_at']) && trim($body['run_at']) !== '' ? trim($body['run_at']) : null;

if ($name === '' || count($actions) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'name_and_actions_required']);
    exit;
}

if ($runAt !== null && !canAccess($shop, 'schedule')) {
    http_response_code(403);
    echo json_encode(['error' => 'scheduling_requires_upgrade']);
    exit;
}

// Plan limit on per-task product count is enforced inside the worker against the actual matched count.
$filterJson = json_encode($filter, JSON_UNESCAPED_UNICODE) ?: '{}';

$jobId = (int)DBHelper::insert(
    "INSERT INTO bulk_jobs (shop_id, name, status, filter_json, triggered_by)
     VALUES (?, ?, 'queued', ?, 'manual')",
    'iss',
    [$shopId, $name, $filterJson]
);

$sortOrder = 0;
foreach ($actions as $a) {
    $type = (string)($a['action_type'] ?? '');
    $params = is_array($a['params'] ?? null) ? $a['params'] : [];
    $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE) ?: '{}';
    if ($type === '') continue;
    DBHelper::insert(
        "INSERT INTO bulk_job_actions (job_id, sort_order, action_type, params_json)
         VALUES (?, ?, ?, ?)",
        'iiss',
        [$jobId, $sortOrder++, $type, $paramsJson]
    );
}

$queueId = pushJob($shopId, 'bulk_edit_run', $jobId, [], $runAt);

DBHelper::insert(
    "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
     VALUES (?, 'merchant', 'bulk_edit.created', 'bulk_job', ?, ?)",
    'iis',
    [$shopId, $jobId, "Bulk edit job created: {$name}"]
);

echo json_encode(['success' => true, 'job_id' => $jobId, 'queue_job_id' => $queueId]);
