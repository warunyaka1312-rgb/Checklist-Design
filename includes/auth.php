<?php
/**
 * Session-based authentication: login, logout, role guards, CSRF helpers.
 */

require_once __DIR__ . '/db.php';

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * @return array{success: bool, role?: string, message?: string}
 */
function attemptLogin(string $username, string $password): array
{
    startSecureSession();
    $pdo = getDbConnection();

    $stmt = $pdo->prepare('SELECT id, username, password_hash, full_name, role, is_active FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'error_invalid_credentials'];
    }

    if ((int)$user['is_active'] !== 1) {
        return ['success' => false, 'message' => 'error_inactive_account'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];

    return ['success' => true, 'role' => $user['role']];
}

function logoutUser(): void
{
    startSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function currentUser(): ?array
{
    startSecureSession();
    if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name'])) {
        // Partial/stale session (e.g. left over from before a session-shape
        // change, or an interrupted login) — treat as logged out and clear
        // the auth keys so the broken state doesn't keep reappearing on every
        // request. Pre-login state (lang choice, login error flash, Keycloak
        // state/nonce) is left alone.
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name'], $_SESSION['auth_source'], $_SESSION['kc_id_token']);
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'full_name' => $_SESSION['full_name'],
    ];
}

function requireLogin(): void
{
    if (currentUser() === null) {
        header('Location: ' . APP_BASE_URL . '/index.php');
        exit;
    }
}

/**
 * @param string|string[] $roles
 */
function requireRole($roles): void
{
    requireLogin();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array(currentUser()['role'], $roles, true)) {
        http_response_code(403);
        die('Forbidden: insufficient permissions.');
    }
}

function dashboardUrlForRole(string $role): string
{
    return match ($role) {
        'admin' => APP_BASE_URL . '/admin/dashboard.php',
        'engineer' => APP_BASE_URL . '/engineer/dashboard.php',
        'manager' => APP_BASE_URL . '/manager/dashboard.php',
        default => APP_BASE_URL . '/index.php',
    };
}

function csrfToken(): string
{
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    startSecureSession();
    return isset($_SESSION['csrf_token']) && is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * JSON-encode a value for embedding inside a double-quoted HTML attribute
 * (e.g. x-data="someFunc(<?= jsonForAttr($config) ?>)"). Plain json_encode()
 * is not enough: JSON's own double quotes would terminate the attribute
 * early, corrupting the tag and silently breaking every Alpine directive
 * inside it. htmlspecialchars() entity-encodes them so the browser restores
 * the real JSON text before Alpine evaluates the expression.
 */
function jsonForAttr(mixed $value): string
{
    return htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}
