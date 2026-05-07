<?php

/**
 * Snapshot helpers — store ONLY the fields a job will change, not the full product JSON.
 * Keeps storage small (~300 bytes per product vs ~10KB for full JSON).
 */

require_once __DIR__ . '/db.php';

if (!function_exists('saveBulkSnapshot')) {
    function saveBulkSnapshot(int $jobId, int $shopifyProductId, array $beforeState): bool
    {
        $json = json_encode($beforeState, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        DBHelper::insert(
            "INSERT INTO bulk_job_snapshots (job_id, shopify_product_id, snapshot_json)
             VALUES (?, ?, ?)",
            'iis',
            [$jobId, $shopifyProductId, $json]
        );
        return true;
    }
}

if (!function_exists('getBulkSnapshots')) {
    function getBulkSnapshots(int $jobId): array
    {
        return DBHelper::select(
            "SELECT * FROM bulk_job_snapshots WHERE job_id = ? ORDER BY id ASC",
            'i',
            [$jobId]
        ) ?: [];
    }
}

if (!function_exists('deleteBulkSnapshots')) {
    function deleteBulkSnapshots(int $jobId): void
    {
        DBHelper::execute("DELETE FROM bulk_job_snapshots WHERE job_id = ?", 'i', [$jobId]);
    }
}

if (!function_exists('saveCampaignSnapshot')) {
    function saveCampaignSnapshot(int $campaignId, int $shopifyProductId, array $beforeState): bool
    {
        $json = json_encode($beforeState, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        DBHelper::insert(
            "INSERT INTO campaign_snapshots (campaign_id, shopify_product_id, before_json)
             VALUES (?, ?, ?)",
            'iis',
            [$campaignId, $shopifyProductId, $json]
        );
        return true;
    }
}

if (!function_exists('updateCampaignSnapshotAfter')) {
    /**
     * Optionally store the post-apply state for verification (after_json column).
     */
    function updateCampaignSnapshotAfter(int $snapshotId, array $afterState): void
    {
        $json = json_encode($afterState, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        DBHelper::execute(
            "UPDATE campaign_snapshots SET after_json = ? WHERE id = ?",
            'si',
            [$json, $snapshotId]
        );
    }
}

if (!function_exists('getCampaignSnapshots')) {
    function getCampaignSnapshots(int $campaignId, bool $unrevertedOnly = true): array
    {
        $sql = "SELECT * FROM campaign_snapshots WHERE campaign_id = ?";
        if ($unrevertedOnly) {
            $sql .= " AND reverted = 0";
        }
        $sql .= " ORDER BY id ASC";
        return DBHelper::select($sql, 'i', [$campaignId]) ?: [];
    }
}

if (!function_exists('markSnapshotReverted')) {
    function markSnapshotReverted(int $snapshotId): void
    {
        DBHelper::execute(
            "UPDATE campaign_snapshots SET reverted = 1, reverted_at = NOW() WHERE id = ?",
            'i',
            [$snapshotId]
        );
    }
}

if (!function_exists('checkManualEdit')) {
    /**
     * True if the merchant manually changed a product after the campaign applied.
     *
     * Logic: we know the campaign's actions (e.g. price = 25% off original).
     * The snapshot's before_json contains the ORIGINAL prices.
     * If the current Shopify variant prices match neither:
     *   - the original (still pre-campaign), nor
     *   - the campaign's expected after-state,
     * then a manual edit happened — skip revert to respect the merchant's change.
     *
     * For variants we compare price + compare_at_price by variant id.
     *
     * @param array $snapshot         snapshot row from campaign_snapshots
     * @param array $currentProduct   Shopify product payload (with variants)
     */
    function checkManualEdit(array $snapshot, array $currentProduct): bool
    {
        $beforeJson = $snapshot['before_json'] ?? null;
        $afterJson  = $snapshot['after_json'] ?? null;
        $before = is_string($beforeJson) ? json_decode($beforeJson, true) : (is_array($beforeJson) ? $beforeJson : null);
        $after  = is_string($afterJson)  ? json_decode($afterJson, true)  : (is_array($afterJson)  ? $afterJson  : null);

        if (!is_array($before) || !isset($before['variants']) || !is_array($before['variants'])) {
            // Without variant-level history, assume no manual edit.
            return false;
        }

        $currentVariants = isset($currentProduct['variants']) && is_array($currentProduct['variants'])
            ? $currentProduct['variants'] : [];
        if (!$currentVariants) {
            return false;
        }

        $afterVariants = (is_array($after) && isset($after['variants']) && is_array($after['variants']))
            ? $after['variants'] : [];

        $byIdAfter = [];
        foreach ($afterVariants as $v) {
            if (isset($v['id'])) $byIdAfter[(string)$v['id']] = $v;
        }

        foreach ($before['variants'] as $bv) {
            $vid = (string)($bv['id'] ?? '');
            if ($vid === '') continue;
            $cur = null;
            foreach ($currentVariants as $cv) {
                if ((string)($cv['id'] ?? '') === $vid) { $cur = $cv; break; }
            }
            if (!is_array($cur)) continue;

            $curPrice = (string)($cur['price'] ?? '');
            $curCmp   = (string)($cur['compare_at_price'] ?? '');

            $beforePrice = (string)($bv['price'] ?? '');
            $beforeCmp   = (string)($bv['compare_at'] ?? ($bv['compare_at_price'] ?? ''));

            $expectedPrice = $beforePrice;
            $expectedCmp   = $beforeCmp;
            if (isset($byIdAfter[$vid])) {
                $av = $byIdAfter[$vid];
                $expectedPrice = (string)($av['price'] ?? $beforePrice);
                $expectedCmp   = (string)($av['compare_at'] ?? ($av['compare_at_price'] ?? $beforeCmp));
            }

            $matchesBefore = ($curPrice === $beforePrice) && ($curCmp === $beforeCmp);
            $matchesExpected = ($curPrice === $expectedPrice) && ($curCmp === $expectedCmp);

            if (!$matchesBefore && !$matchesExpected) {
                return true;
            }
        }

        return false;
    }
}
