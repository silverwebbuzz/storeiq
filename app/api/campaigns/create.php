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

if (!canAccess($shop, 'schedule')) {
    http_response_code(403);
    echo json_encode(['error' => 'scheduling_requires_upgrade']);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'invalid_body']); exit; }

$name        = trim((string)($body['name'] ?? ''));
$startAt     = trim((string)($body['start_at'] ?? ''));
$endAt       = isset($body['end_at']) && $body['end_at'] !== '' ? (string)$body['end_at'] : null;
$autoRevert  = !empty($body['auto_revert']) ? 1 : 0;
$isRecurring = !empty($body['is_recurring']) ? 1 : 0;
$filter      = is_array($body['filter']  ?? null) ? $body['filter']  : [];
$actions     = is_array($body['actions'] ?? null) ? $body['actions'] : [];
$templateId  = isset($body['template_id']) ? (int)$body['template_id'] : null;

if ($name === '' || $startAt === '' || count($actions) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'name_start_actions_required']);
    exit;
}
if ($isRecurring && !canAccess($shop, 'recurring_campaigns')) {
    http_response_code(403);
    echo json_encode(['error' => 'recurring_requires_upgrade']);
    exit;
}

// Enforce active campaign cap.
$max = getLimit($shop, 'max_active_campaigns');
if ($max > 0) {
    $row = DBHelper::selectOne(
        "SELECT COUNT(*) AS cnt FROM campaigns
         WHERE shop_id = ? AND status IN ('scheduled','running')",
        'i', [$shopId]
    );
    $active = (int)($row['cnt'] ?? 0);
    if ($active >= $max) {
        http_response_code(403);
        echo json_encode(['error' => 'active_campaign_limit_reached', 'limit' => $max]);
        exit;
    }
}

$filterJson = json_encode($filter, JSON_UNESCAPED_UNICODE) ?: '{}';
// Persist actions inside campaign filter_json's _actions key for now (no separate campaign_actions table in schema).
$payload = ['filter' => $filter, '_actions' => $actions];
$filterJson = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';

$campaignId = (int)DBHelper::insert(
    "INSERT INTO campaigns (shop_id, template_id, name, status, filter_json, is_recurring, start_at, end_at, auto_revert)
     VALUES (?, ?, ?, 'scheduled', ?, ?, ?, ?, ?)",
    'iississi',
    [$shopId, $templateId, $name, $filterJson, $isRecurring, $startAt, $endAt, $autoRevert]
);

if ($templateId) {
    DBHelper::execute(
        "UPDATE campaign_templates SET use_count = use_count + 1 WHERE id = ?",
        'i', [$templateId]
    );
}

pushJob($shopId, 'campaign_apply', $campaignId, [], $startAt);
if ($autoRevert && $endAt) {
    pushJob($shopId, 'campaign_revert', $campaignId, [], $endAt);
}

DBHelper::insert(
    "INSERT INTO activity_log (shop_id, actor, action, entity_type, entity_id, summary)
     VALUES (?, 'merchant', 'campaign.scheduled', 'campaign', ?, ?)",
    'iis',
    [$shopId, $campaignId, "Campaign scheduled: {$name}"]
);

echo json_encode(['success' => true, 'campaign_id' => $campaignId]);
