<?php
require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const ADMIN_SESSION_TTL = 3600;

function adminAllowedIps(): array {
    if (defined('ADMIN_ALLOWED_IPS') && is_array(ADMIN_ALLOWED_IPS) && !empty(ADMIN_ALLOWED_IPS)) {
        return ADMIN_ALLOWED_IPS;
    }

    return [];
}

function adminClientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function adminIsIpAllowed(): bool {
    $allowedIps = adminAllowedIps();
    if (empty($allowedIps)) {
        return true;
    }

    return in_array(adminClientIp(), $allowedIps, true);
}

function adminIsAuthenticated(): bool {
    if (!adminIsIpAllowed()) {
        return false;
    }

    return isset($_SESSION['authenticated'])
        && $_SESSION['authenticated'] === true
        && (time() - (int)($_SESSION['login_time'] ?? 0)) <= ADMIN_SESSION_TTL
        && ($_SESSION['user_ip'] ?? '') === adminClientIp();
}

function adminLogin(string $password): bool {
    $_SESSION['fail_count'] = (int)($_SESSION['fail_count'] ?? 0);
    if ($_SESSION['fail_count'] > 5) {
        sleep(2);
    }

    if (!adminIsIpAllowed()) {
        return false;
    }

    if (hash_equals((string)LOG_VIEW_PASSWORD, $password)) {
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = adminClientIp();
        $_SESSION['fail_count'] = 0;
        return true;
    }

    $_SESSION['fail_count']++;
    return false;
}

function adminLogout(): void {
    $_SESSION = [];
    session_destroy();
}

function requireAdminAuth(bool $asJson = true): void {
    if (adminIsAuthenticated()) {
        return;
    }

    if ($asJson) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    header('Location: index.php');
    exit;
}
