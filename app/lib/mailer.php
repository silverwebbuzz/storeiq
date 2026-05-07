<?php

/**
 * Simple PHP mail() wrapper for StoreIQ digest + alert emails.
 */

require_once __DIR__ . '/logger.php';

if (!function_exists('siq_mail_send')) {
    function siq_mail_send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            sbm_log_write('mailer', 'invalid_to', ['to' => $to]);
            return false;
        }

        $boundary = 'siq-' . bin2hex(random_bytes(8));
        $fromName = defined('APP_NAME') ? APP_NAME : 'StoreIQ';
        $fromEmail = 'no-reply@' . (parse_url((string)(defined('BASE_URL') ? BASE_URL : ''), PHP_URL_HOST) ?: 'silverwebbuzz.com');

        $headers = [
            "From: {$fromName} <{$fromEmail}>",
            "Reply-To: {$fromEmail}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            "X-Mailer: StoreIQ",
        ];

        if ($textBody === '') {
            $textBody = trim(strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $htmlBody)));
        }

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--{$boundary}--\r\n";

        $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
        sbm_log_write('mailer', $ok ? 'sent' : 'send_failed', [
            'to' => $to,
            'subject' => $subject,
        ]);
        return (bool)$ok;
    }
}

if (!function_exists('sendDigestEmail')) {
    function sendDigestEmail(string $shopEmail, string $shopName, array $digestData): bool
    {
        $score    = (int)($digestData['health_score'] ?? 0);
        $critical = (int)($digestData['critical_count'] ?? 0);
        $warning  = (int)($digestData['warning_count'] ?? 0);
        $info     = (int)($digestData['info_count'] ?? 0);
        $topFlags = $digestData['top_flags'] ?? [];
        $url      = (string)($digestData['dashboard_url'] ?? (defined('BASE_URL') ? BASE_URL . '/dashboard' : ''));

        $color = $score >= 80 ? '#16a34a' : ($score >= 50 ? '#f59e0b' : '#dc2626');
        $shopNameEsc = htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8');
        $urlEsc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        $flagsHtml = '';
        foreach ((array)$topFlags as $f) {
            $flagsHtml .= '<li>' . htmlspecialchars((string)$f, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        if ($flagsHtml === '') {
            $flagsHtml = '<li style="color:#16a34a;">No critical issues this week — nice work.</li>';
        }

        $subject = "Your StoreIQ weekly report — {$shopName}";
        $html = <<<HTML
<!doctype html><html><body style="font-family:system-ui,Helvetica,Arial,sans-serif;background:#f6f6f7;padding:24px;color:#111827;">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:28px;">
    <h2 style="margin:0 0 4px;">Weekly health report</h2>
    <div style="color:#6b7280;margin-bottom:24px;">{$shopNameEsc}</div>
    <div style="text-align:center;margin:24px 0;">
      <div style="display:inline-block;width:120px;height:120px;border-radius:50%;border:8px solid {$color};line-height:104px;font-size:36px;font-weight:700;color:{$color};">{$score}</div>
      <div style="margin-top:8px;color:#6b7280;">Health score</div>
    </div>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">
      <tr>
        <td style="text-align:center;background:#fee2e2;padding:12px;border-radius:6px;"><b>{$critical}</b><br><small>Critical</small></td>
        <td width="8"></td>
        <td style="text-align:center;background:#fef3c7;padding:12px;border-radius:6px;"><b>{$warning}</b><br><small>Warnings</small></td>
        <td width="8"></td>
        <td style="text-align:center;background:#dbeafe;padding:12px;border-radius:6px;"><b>{$info}</b><br><small>Info</small></td>
      </tr>
    </table>
    <h3 style="margin:24px 0 8px;">Top issues</h3>
    <ul>{$flagsHtml}</ul>
    <div style="text-align:center;margin-top:32px;">
      <a href="{$urlEsc}" style="background:#6366f1;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;display:inline-block;">View full report</a>
    </div>
    <div style="color:#9ca3af;font-size:12px;text-align:center;margin-top:24px;">SWB StoreIQ — Bulk Edit, Campaigns &amp; Automation</div>
  </div>
</body></html>
HTML;

        return siq_mail_send($shopEmail, $subject, $html);
    }
}

if (!function_exists('sendAlertEmail')) {
    function sendAlertEmail(string $shopEmail, string $shopName, string $alertType, array $data = []): bool
    {
        $titles = [
            'zero_price_detected' => 'Urgent: zero-price product detected',
            'campaign_failed'     => 'A StoreIQ campaign failed',
            'bulk_job_failed'     => 'A StoreIQ bulk edit failed',
        ];
        $title = $titles[$alertType] ?? ('StoreIQ alert: ' . $alertType);
        $subject = "{$title} — {$shopName}";

        $detail = '';
        foreach ($data as $k => $v) {
            $detail .= '<li><b>' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . ':</b> '
                     . htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v), ENT_QUOTES, 'UTF-8')
                     . '</li>';
        }

        $shopNameEsc = htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8');
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!doctype html><html><body style="font-family:system-ui,Helvetica,Arial,sans-serif;background:#f6f6f7;padding:24px;color:#111827;">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:28px;">
    <div style="background:#fee2e2;color:#991b1b;padding:8px 12px;border-radius:4px;font-weight:600;display:inline-block;">Alert</div>
    <h2 style="margin:12px 0 4px;">{$titleEsc}</h2>
    <div style="color:#6b7280;margin-bottom:16px;">Shop: {$shopNameEsc}</div>
    <ul>{$detail}</ul>
    <div style="color:#9ca3af;font-size:12px;text-align:center;margin-top:24px;">SWB StoreIQ</div>
  </div>
</body></html>
HTML;

        return siq_mail_send($shopEmail, $subject, $html);
    }
}
