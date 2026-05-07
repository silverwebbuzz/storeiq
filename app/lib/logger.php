<?php
/**
 * Centralized application logger.
 * Writes channel-based log files into one folder: app/storage/logs
 */

if (!function_exists('sbm_log_dir')) {
    function sbm_log_dir(): string
    {
        $dir = __DIR__ . '/../storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('sbm_log_write')) {
    function sbm_log_write(string $channel, string $message, array $context = []): void
    {
        $safeChannel = preg_replace('/[^a-z0-9_\-]/i', '', strtolower(trim($channel)));
        if (!is_string($safeChannel) || $safeChannel === '') {
            $safeChannel = 'app';
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '') {
                $line .= ' | ' . $json;
            }
        }
        $line .= PHP_EOL;

        $file = rtrim(sbm_log_dir(), '/') . '/' . $safeChannel . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        // Unified master log file for quick server-side inspection.
        $master = rtrim(sbm_log_dir(), '/') . '/shopify.log';
        @file_put_contents($master, '[' . $safeChannel . '] ' . $line, FILE_APPEND | LOCK_EX);
    }
}

