<?php

require_once __DIR__ . '/logger.php';

/**
 * Session token authentication helpers for embedded app API routes.
 */

if (!function_exists('base64UrlDecode')) {
    function base64UrlDecode(string $value): string|false
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? false : $decoded;
    }
}

if (!function_exists('verifySessionToken')) {
    function verifySessionToken(string $token): array|false
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$encodedHeader, $encodedPayload, $encodedSig] = $parts;
        $headerJson = base64UrlDecode($encodedHeader);
        $payloadJson = base64UrlDecode($encodedPayload);
        $sigBin = base64UrlDecode($encodedSig);

        if (!is_string($headerJson) || !is_string($payloadJson) || !is_string($sigBin)) {
            return false;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            return false;
        }

        if (($header['alg'] ?? '') !== 'HS256') {
            return false;
        }

        $signedData = $encodedHeader . '.' . $encodedPayload;
        $expectedSig = hash_hmac('sha256', $signedData, SHOPIFY_API_SECRET, true);
        if (!hash_equals($expectedSig, $sigBin)) {
            return false;
        }

        $now = time();
        $nbf = (int)($payload['nbf'] ?? 0);
        $exp = (int)($payload['exp'] ?? 0);
        if ($nbf > 0 && $nbf > ($now + 10)) {
            return false;
        }
        if ($exp <= 0 || $exp < ($now - 10)) {
            return false;
        }

        $audClaim = $payload['aud'] ?? '';
        $audOk = false;
        if (is_string($audClaim)) {
            $audOk = ($audClaim === SHOPIFY_API_KEY);
        } elseif (is_array($audClaim)) {
            $audOk = in_array(SHOPIFY_API_KEY, $audClaim, true);
        }
        if (!$audOk) {
            return false;
        }

        return $payload;
    }
}

if (!function_exists('getBearerTokenFromHeaders')) {
    function getBearerTokenFromHeaders(): ?string
    {
        $headerVal = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strtolower((string)$k) === 'authorization') {
                        $headerVal = (string)$v;
                        break;
                    }
                }
            }
        }
        if ($headerVal === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headerVal = (string)$_SERVER['HTTP_AUTHORIZATION'];
        }
        if ($headerVal === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headerVal = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($headerVal), $m)) {
            return null;
        }
        return trim((string)$m[1]);
    }
}

if (!function_exists('requireSessionTokenAuth')) {
    function requireSessionTokenAuth(?string $expectedShop = null): array
    {
        $token = getBearerTokenFromHeaders();
        if (!is_string($token) || $token === '') {
            sbm_log_write('auth', 'missing_authorization_header');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $payload = verifySessionToken($token);
        if (!is_array($payload)) {
            sbm_log_write('auth', 'invalid_session_token');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid token'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($expectedShop !== null && $expectedShop !== '') {
            $dest = (string)($payload['dest'] ?? '');
            $shopDomain = parse_url($dest, PHP_URL_HOST);
            if (is_string($shopDomain) && $shopDomain !== '' && strtolower($shopDomain) !== strtolower($expectedShop)) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Token shop mismatch'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        return $payload;
    }
}

if (!function_exists('sessionTokenShopDomain')) {
    function sessionTokenShopDomain(array $payload): ?string
    {
        $dest = (string)($payload['dest'] ?? '');
        if ($dest === '') {
            return null;
        }
        $host = parse_url($dest, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        return sanitizeShopDomain($host);
    }
}

if (!function_exists('resolveApiShopFromToken')) {
    function resolveApiShopFromToken(?string $requestedShop = null): string
    {
        $payload = requireSessionTokenAuth(null);
        $tokenShop = sessionTokenShopDomain($payload);
        if (!is_string($tokenShop) || $tokenShop === '') {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid token shop'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $cleanRequested = sanitizeShopDomain($requestedShop);
        if ($cleanRequested !== null && strtolower($cleanRequested) !== strtolower($tokenShop)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Token shop mismatch'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return $tokenShop;
    }
}

