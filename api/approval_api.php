<?php
/**
 * JSON API for the manager approve/reject decision.
 * Auth: session cookie, role manager, must be the checklist's assigned_manager_id.
 * Requires X-CSRF-Token header.
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
if (!$me || $me['role'] !== 'manager') {
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
    if ($action === 'approve') {
        handleDecision($pdo, $me, $input, 'approved');
    }
    if ($action === 'reject') {
        handleDecision($pdo, $me, $input, 'rejected');
    }
    respond(['success' => false, 'message' => 'unknown_action'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond([
        'success' => false,
        'message' => 'server_error',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], 500);
}

function handleDecision(PDO $pdo, array $me, array $input, string $decision): void
{
    $id = (int)($input['id'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));

    if ($id <= 0) {
        respond(['success' => false, 'message' => 'invalid_input'], 422);
    }

    if ($decision === 'rejected' && $note === '') {
        respond(['success' => false, 'message' => 'reject_reason_required'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM checklists WHERE id = ?');
    $stmt->execute([$id]);
    $checklist = $stmt->fetch();
    if (!$checklist) {
        respond(['success' => false, 'message' => 'not_found'], 404);
    }

    if ((int)$checklist['assigned_manager_id'] !== (int)$me['id']) {
        respond(['success' => false, 'message' => 'forbidden'], 403);
    }

    if ($checklist['status'] !== 'pending') {
        respond(['success' => false, 'message' => 'not_pending'], 409);
    }

    $pdo->beginTransaction();

    $pdo->prepare(
        'UPDATE checklists
         SET status = ?, decided_at = CURRENT_TIMESTAMP, decided_by = ?, revision_note = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    )->execute([$decision, $me['id'], $decision === 'rejected' ? $note : null, $id]);

    $pdo->prepare('INSERT INTO checklist_history (checklist_id, action, action_by, note, old_status, new_status) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $decision, $me['id'], $decision === 'rejected' ? $note : null, $checklist['status'], $decision]);

    if ($decision === 'approved') {
        $message = "Die-No {$checklist['die_no']} ได้รับการอนุมัติแล้ว";
    } else {
        $noteExcerpt = mb_substr($note, 0, 100);
        if (mb_strlen($note) > 100) {
            $noteExcerpt .= '...';
        }
        $message = "Die-No {$checklist['die_no']} ถูกตีกลับ: {$noteExcerpt}";
    }

    $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)')->execute([
        $checklist['created_by'],
        $message,
        APP_BASE_URL . '/engineer/checklist_view.php?id=' . $id,
    ]);

    $pdo->commit();

    respond(['success' => true, 'status' => $decision]);
}
