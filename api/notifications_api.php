<?php
/**
 * JSON API for the notification bell.
 * Auth: any logged-in user (session cookie). Reads (list/unread_count) are GET;
 * mutations (mark_read/mark_all_read) are POST and require X-CSRF-Token.
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

function formatNotification(array $n): array
{
    return [
        'id' => (int)$n['id'],
        'message' => $n['message'],
        'link' => $n['link'],
        'is_read' => (int)$n['is_read'],
        'time_label' => date('d M Y H:i', strtotime($n['created_at'])),
    ];
}

function unreadCountFor(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

$me = currentUser();
if (!$me) {
    respond(['success' => false, 'message' => 'forbidden'], 403);
}

$action = $_GET['action'] ?? '';
$pdo = getDbConnection();

try {
    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT id, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
        $stmt->execute([$me['id']]);
        $items = array_map('formatNotification', $stmt->fetchAll());

        respond(['success' => true, 'items' => $items, 'unread_count' => unreadCountFor($pdo, (int)$me['id'])]);
    }

    if ($action === 'unread_count') {
        respond(['success' => true, 'unread_count' => unreadCountFor($pdo, (int)$me['id'])]);
    }

    if ($action === 'mark_read' || $action === 'mark_all_read' || $action === 'delete_all') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['success' => false, 'message' => 'unknown_action'], 400);
        }
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verifyCsrfToken($csrf)) {
            respond(['success' => false, 'message' => 'invalid_csrf'], 403);
        }

        if ($action === 'mark_read') {
            $input = jsonInput();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                respond(['success' => false, 'message' => 'invalid_input'], 422);
            }
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $me['id']]);
        } elseif ($action === 'mark_all_read') {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$me['id']]);
        } else {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$me['id']]);
        }

        respond(['success' => true, 'unread_count' => unreadCountFor($pdo, (int)$me['id'])]);
    }

    respond(['success' => false, 'message' => 'unknown_action'], 400);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => 'server_error',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], 500);
}
