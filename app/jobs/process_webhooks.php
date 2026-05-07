<?php
/**
 * Process stored Shopify webhook events and apply to per-store tables.
 *
 * Run via cron every minute:
 *   /usr/bin/php /path/to/app/jobs/process_webhooks.php >/dev/null 2>&1
 *
 * Optional browser testing:
 *   /app/jobs/process_webhooks?limit=25
 *   /app/jobs/process_webhooks?shop=storename.myshopify.com&limit=25
 */

require_once __DIR__ . '/../config.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if (!is_string($key) || $key === '' || CRON_KEY === '' || !hash_equals(CRON_KEY, $key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$mysqli = db();

$limit = $_GET['limit'] ?? 25;
$limit = is_numeric($limit) ? (int)$limit : 25;
if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;

$shop = $_GET['shop'] ?? null;
if (!is_string($shop) || $shop === '') {
    $shop = null;
}

// Requires these columns (add via ALTER TABLE if needed):
// - processed_at DATETIME NULL
// - attempts INT NOT NULL DEFAULT 0
// - last_error TEXT NULL

$sql = "SELECT id, shop, topic, webhook_id, payload_json
        FROM webhook_events
        WHERE processed_at IS NULL";
if ($shop !== null) {
    $sql .= " AND shop = ?";
}
$sql .= " ORDER BY received_at ASC
          LIMIT {$limit}";

if ($shop !== null) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $mysqli->error]);
        exit;
    }
    $stmt->bind_param('s', $shop);
} else {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $mysqli->error]);
        exit;
    }
}

$stmt->execute();
$res = $stmt->get_result();
$events = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$processed = 0;
$failed = 0;

foreach ($events as $ev) {
    $id = (int)($ev['id'] ?? 0);
    $evShop = (string)($ev['shop'] ?? '');
    $topic = (string)($ev['topic'] ?? '');
    $payloadJson = (string)($ev['payload_json'] ?? '');

    if ($id < 1 || $evShop === '' || $topic === '' || $payloadJson === '') {
        continue;
    }

    // increment attempts
    $mysqli->query("UPDATE webhook_events SET attempts = attempts + 1 WHERE id = {$id}");

    try {
        applyWebhookToStoreTables($evShop, $topic, $payloadJson);

        $u = $mysqli->prepare("UPDATE webhook_events SET processed_at = NOW(), last_error = NULL WHERE id = ?");
        if ($u) {
            $u->bind_param('i', $id);
            $u->execute();
            $u->close();
        }
        $processed++;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $u = $mysqli->prepare("UPDATE webhook_events SET last_error = ? WHERE id = ?");
        if ($u) {
            $u->bind_param('si', $msg, $id);
            $u->execute();
            $u->close();
        }
        $failed++;
    }
}

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'selected' => count($events),
    'processed' => $processed,
    'failed' => $failed,
], JSON_UNESCAPED_UNICODE);

