<?php
/**
 * Weekly digest worker — handles digest_email job_queue entries.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/job_queue.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/logger.php';

if (!function_exists('runDigestEmailWorker')) {
    function runDigestEmailWorker(array $queueJob): void
    {
        $shopId = (int)$queueJob['shop_id'];
        $store = DBHelper::selectOne(
            "SELECT shop, store_name, email FROM stores WHERE id = ? LIMIT 1",
            'i', [$shopId]
        );
        if (!$store) throw new Exception("store not found: {$shopId}");
        $email = trim((string)($store['email'] ?? ''));
        if ($email === '') {
            sbm_log_write('cron', "digest_email skipped: no email on file for shop_id={$shopId}");
            completeJob((int)$queueJob['id']);
            return;
        }

        $latest = DBHelper::selectOne(
            "SELECT health_score FROM hygiene_scan_runs WHERE shop_id = ? ORDER BY id DESC LIMIT 1",
            'i', [$shopId]
        );

        $counts = DBHelper::select(
            "SELECT r.severity, COUNT(*) AS cnt
             FROM hygiene_flags f
             JOIN hygiene_rule_definitions r ON r.id = f.rule_id
             WHERE f.shop_id = ? AND f.status = 'open'
             GROUP BY r.severity",
            'i', [$shopId]
        ) ?: [];
        $bySev = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($counts as $c) {
            $sev = (string)$c['severity'];
            if (isset($bySev[$sev])) $bySev[$sev] = (int)$c['cnt'];
        }

        $top = DBHelper::select(
            "SELECT f.entity_title, r.name AS rule_name
             FROM hygiene_flags f
             JOIN hygiene_rule_definitions r ON r.id = f.rule_id
             WHERE f.shop_id = ? AND f.status = 'open' AND r.severity = 'critical'
             ORDER BY f.id DESC LIMIT 3",
            'i', [$shopId]
        ) ?: [];
        $topFlags = [];
        foreach ($top as $t) {
            $title = trim((string)($t['entity_title'] ?? '')) ?: '(untitled)';
            $topFlags[] = $t['rule_name'] . ' — ' . $title;
        }

        $digest = [
            'health_score'   => $latest['health_score'] ?? 100,
            'critical_count' => $bySev['critical'],
            'warning_count'  => $bySev['warning'],
            'info_count'     => $bySev['info'],
            'top_flags'      => $topFlags,
            'dashboard_url'  => rtrim((string)BASE_URL, '/') . '/dashboard?shop=' . urlencode((string)$store['shop']),
        ];

        $shopName = (string)($store['store_name'] ?? $store['shop']);
        sendDigestEmail($email, $shopName, $digest);

        DBHelper::insert(
            "INSERT INTO activity_log (shop_id, actor, action, entity_type, summary)
             VALUES (?, 'system', 'digest.email_sent', 'shop', ?)",
            'is',
            [$shopId, "Weekly digest sent to {$email}"]
        );

        completeJob((int)$queueJob['id']);
    }
}
