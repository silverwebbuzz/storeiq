<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/job_queue.php';

header('Content-Type: application/json; charset=UTF-8');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if (!$shop) { http_response_code(400); echo json_encode(['error' => 'invalid_shop']); exit; }
$shopRecord = getShopByDomain($shop);
if (!$shopRecord) { http_response_code(404); echo json_encode(['error' => 'not_installed']); exit; }
$shopId = (int)$shopRecord['id'];

$body = json_decode((string)file_get_contents('php://input'), true);
$ruleCode = trim((string)($body['rule_code'] ?? ''));
if ($ruleCode === '') { http_response_code(400); echo json_encode(['error' => 'invalid_rule_code']); exit; }

// Build a pre-filled bulk-edit job draft based on the rule code.
$presets = [
    'no_alt_text'    => ['name' => 'Fix missing alt text',    'actions' => [['action_type' => 'alt_text_set',    'params' => ['use_title' => true]]]],
    'no_seo_title'   => ['name' => 'Fix missing SEO titles',  'actions' => [['action_type' => 'seo_title_set',   'params' => ['use_title' => true]]]],
    'no_description' => ['name' => 'Add product descriptions','actions' => [['action_type' => 'description_append', 'params' => ['value' => '']]]],
];
if (!isset($presets[$ruleCode])) {
    echo json_encode(['success' => false, 'error' => 'no_preset', 'message' => 'Use the bulk edit page to design a fix.']);
    exit;
}

$preset = $presets[$ruleCode];
$actionsJson = json_encode($preset['actions'], JSON_UNESCAPED_UNICODE) ?: '[]';
$filterJson = json_encode(['_hygiene_rule' => $ruleCode], JSON_UNESCAPED_UNICODE) ?: '{}';

$jobId = (int)DBHelper::insert(
    "INSERT INTO bulk_jobs (shop_id, name, status, filter_json, triggered_by) VALUES (?, ?, 'queued', ?, 'automation')",
    'iss', [$shopId, $preset['name'], $filterJson]
);
$sortOrder = 0;
foreach ($preset['actions'] as $a) {
    DBHelper::insert(
        "INSERT INTO bulk_job_actions (job_id, sort_order, action_type, params_json) VALUES (?, ?, ?, ?)",
        'iiss',
        [$jobId, $sortOrder++, (string)$a['action_type'], json_encode($a['params'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}']
    );
}
$queueId = pushJob($shopId, 'bulk_edit_run', $jobId);
echo json_encode(['success' => true, 'job_id' => $jobId, 'queue_job_id' => $queueId]);
