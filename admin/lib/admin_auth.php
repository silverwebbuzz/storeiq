<?php
/**
 * Admin panel auth — plain PHP sessions, no Shopify.
 */

require_once __DIR__ . '/../../app/config.php'; // db helpers

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('SIQ_ADMIN');
    session_start();
}

if (!function_exists('adminLogin')) {
    function adminLogin(string $email, string $password): bool
    {
        $row = DBHelper::selectOne(
            "SELECT id, name, email, password_hash, role, is_active FROM admin_users WHERE email = ? LIMIT 1",
            's', [$email]
        );
        if (!is_array($row) || (int)$row['is_active'] !== 1) {
            return false;
        }
        if (!password_verify($password, (string)$row['password_hash'])) {
            return false;
        }
        $_SESSION['siq_admin'] = [
            'id'    => (int)$row['id'],
            'name'  => (string)$row['name'],
            'email' => (string)$row['email'],
            'role'  => (string)$row['role'],
        ];
        DBHelper::execute("UPDATE admin_users SET last_login_at = NOW() WHERE id = ?", 'i', [(int)$row['id']]);
        return true;
    }
}

if (!function_exists('adminLogout')) {
    function adminLogout(): void
    {
        unset($_SESSION['siq_admin']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

if (!function_exists('getCurrentAdmin')) {
    function getCurrentAdmin(): ?array
    {
        return is_array($_SESSION['siq_admin'] ?? null) ? $_SESSION['siq_admin'] : null;
    }
}

if (!function_exists('requireAdminAuth')) {
    function requireAdminAuth(): array
    {
        $admin = getCurrentAdmin();
        if (!$admin) {
            $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/api/') !== false;
            if ($isApi) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'unauthorized']);
            } else {
                header('Location: /admin/index.php');
            }
            exit;
        }
        return $admin;
    }
}
