<?php
/**
 * JSON API for admin user management (list/create/update/toggle_active/reset_password).
 * Auth: session cookie, role must be admin. State-changing requests require the
 * X-CSRF-Token header to match the session's csrf token.
 */

require_once __DIR__ . '/../includes/keycloak.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonInput(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$me = currentUser();
if (!$me || $me['role'] !== 'admin') {
    respond(['success' => false, 'message' => 'forbidden'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        respond(['success' => false, 'message' => 'invalid_csrf'], 403);
    }
}

$pdo = getDbConnection();

try {
    if ($method === 'GET' && $action === 'list') {
        $stmt = $pdo->query('SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY id ASC');
        respond(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    $input = $method === 'POST' ? jsonInput() : [];

    // Keycloak on: username/name/role are overwritten from Keycloak on every
    // login, so editing them here would silently not stick.
    if ($method === 'POST' && in_array($action, ['create', 'update'], true) && keycloakEnabled()) {
        respond(['success' => false, 'message' => 'managed_by_keycloak'], 403);
    }
    if ($method === 'POST' && $action === 'reset_password' && !localLoginAllowed()) {
        respond(['success' => false, 'message' => 'managed_by_keycloak'], 403);
    }

    if ($method === 'POST' && $action === 'create') {
        $username = trim((string)($input['username'] ?? ''));
        $fullName = trim((string)($input['full_name'] ?? ''));
        $role     = (string)($input['role'] ?? '');
        $password = (string)($input['password'] ?? '');

        if ($username === '' || $fullName === '' || !in_array($role, ['admin', 'engineer', 'manager'], true)) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        if (strlen($password) < 6) {
            respond(['success' => false, 'message' => 'password_too_short'], 422);
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            respond(['success' => false, 'message' => 'username_taken'], 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([$username, $hash, $fullName, $role]);

        respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($method === 'POST' && $action === 'update') {
        $id       = (int)($input['id'] ?? 0);
        $username = trim((string)($input['username'] ?? ''));
        $fullName = trim((string)($input['full_name'] ?? ''));
        $role     = (string)($input['role'] ?? '');

        if ($id <= 0 || $username === '' || $fullName === '' || !in_array($role, ['admin', 'engineer', 'manager'], true)) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $check->execute([$username, $id]);
        if ($check->fetch()) {
            respond(['success' => false, 'message' => 'username_taken'], 409);
        }

        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, role = ? WHERE id = ?');
        $stmt->execute([$fullName, $username, $role, $id]);

        respond(['success' => true]);
    }

    if ($method === 'POST' && $action === 'toggle_active') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        if ($id === (int)$me['id']) {
            respond(['success' => false, 'message' => 'cannot_deactivate_self'], 422);
        }

        $stmt = $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?');
        $stmt->execute([$id]);

        respond(['success' => true]);
    }

    if ($method === 'POST' && $action === 'reset_password') {
        $id       = (int)($input['id'] ?? 0);
        $password = (string)($input['password'] ?? '');

        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        if (strlen($password) < 6) {
            respond(['success' => false, 'message' => 'password_too_short'], 422);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);

        respond(['success' => true]);
    }

    respond(['success' => false, 'message' => 'unknown_action'], 400);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => 'server_error',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], 500);
}
