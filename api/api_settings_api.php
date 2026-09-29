<?php
/**
 * JSON API for admin management of api_settings (external API connections).
 * This only stores configuration; no outbound HTTP call is implemented here —
 * reserved for a future phase per product decision.
 * Auth: session cookie, role must be admin. All POSTs require X-CSRF-Token.
 */

require_once __DIR__ . '/../includes/auth.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'unknown_action'], 400);
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrf)) {
    respond(['success' => false, 'message' => 'invalid_csrf'], 403);
}

$action = $_GET['action'] ?? '';
$pdo = getDbConnection();
$input = jsonInput();

try {
    if ($action === 'create') {
        $name = trim((string)($input['name'] ?? ''));
        $endpointUrl = trim((string)($input['endpoint_url'] ?? ''));
        $apiKey = trim((string)($input['api_key'] ?? ''));
        $isEnabled = !empty($input['is_enabled']) ? 1 : 0;

        if ($name === '' || $endpointUrl === '') {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $stmt = $pdo->prepare('INSERT INTO api_settings (name, endpoint_url, api_key, is_enabled) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $endpointUrl, $apiKey !== '' ? $apiKey : null, $isEnabled]);
        respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $endpointUrl = trim((string)($input['endpoint_url'] ?? ''));
        $apiKey = trim((string)($input['api_key'] ?? ''));
        $isEnabled = !empty($input['is_enabled']) ? 1 : 0;

        if ($id <= 0 || $name === '' || $endpointUrl === '') {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $stmt = $pdo->prepare('UPDATE api_settings SET name = ?, endpoint_url = ?, api_key = ?, is_enabled = ? WHERE id = ?');
        $stmt->execute([$name, $endpointUrl, $apiKey !== '' ? $apiKey : null, $isEnabled, $id]);
        respond(['success' => true]);
    }

    if ($action === 'activate' || $action === 'deactivate') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        $value = $action === 'activate' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE api_settings SET is_enabled = ? WHERE id = ?');
        $stmt->execute([$value, $id]);
        respond(['success' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        $pdo->prepare('DELETE FROM api_settings WHERE id = ?')->execute([$id]);
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
