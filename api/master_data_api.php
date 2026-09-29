<?php
/**
 * JSON API for admin master-data management: customers, die_models,
 * materials, checklist_items.
 *
 * Query params: entity=customers|die_models|materials|tempers|approvers|items, action=create|update|deactivate|activate|delete|move
 * เฉพาะ entity=customers มี action=sync เพิ่ม — ดึงรายชื่อลูกค้าจาก Customer API (SAP) เข้าตาราง customers
 * Auth: session cookie, role must be admin. All POSTs require X-CSRF-Token.
 *
 * "delete" always tries a hard DELETE first; if the row is referenced
 * elsewhere it responds {success:false, message:'in_use'} instead of
 * deleting, so the caller can offer "deactivate instead".
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_api.php';

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

function isDuplicateKeyError(PDOException $e): bool
{
    return (int)($e->errorInfo[1] ?? 0) === 1062;
}

$me = currentUser();
if (!$me || $me['role'] !== 'admin') {
    respond(['success' => false, 'message' => 'forbidden'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$entity = $_GET['entity'] ?? '';
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        respond(['success' => false, 'message' => 'invalid_csrf'], 403);
    }
}

if ($method !== 'POST') {
    respond(['success' => false, 'message' => 'unknown_action'], 400);
}

$pdo = getDbConnection();
$input = jsonInput();

try {
    switch ($entity) {
        case 'customers':
            if ($action === 'sync') {
                set_time_limit(120);
                try {
                    respond(['success' => true, 'result' => syncCustomersFromApi($pdo)]);
                } catch (RuntimeException $e) {
                    // ข้อความ error จาก API/การเชื่อมต่อ แสดงให้ admin เห็นเพื่อไล่ปัญหา (ไม่มีข้อมูลลับ)
                    respond(['success' => false, 'message' => 'sync_failed', 'detail' => $e->getMessage()], 502);
                }
            }
            handleSimpleNamedEntity($pdo, 'customers', 'checklists', 'customer_id', $action, $input);
            break;
        case 'die_models':
            handleSimpleNamedEntity($pdo, 'die_models', 'checklists', 'model_id', $action, $input);
            break;
        case 'materials':
            handleSimpleNamedEntity($pdo, 'materials', 'checklists', 'material_id', $action, $input);
            break;
        case 'tempers':
            handleSimpleNamedEntity($pdo, 'tempers', 'checklists', 'temper_id', $action, $input);
            break;
        case 'approvers':
            handleApprover($pdo, $action, $input);
            break;
        case 'items':
            handleItem($pdo, $action, $input);
            break;
        default:
            respond(['success' => false, 'message' => 'unknown_entity'], 400);
    }
    respond(['success' => false, 'message' => 'unknown_action'], 400);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => 'server_error',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], 500);
}

/**
 * Shared create/update/activate/deactivate/delete for a table shaped like
 * (id, name, is_active), whose "in use" check is a row count on a foreign table.
 */
function handleSimpleNamedEntity(PDO $pdo, string $table, string $refTable, string $refColumn, string $action, array $input): void
{
    if ($action === 'create') {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO {$table} (name, is_active) VALUES (?, 1)");
            $stmt->execute([$name]);
        } catch (PDOException $e) {
            if (isDuplicateKeyError($e)) {
                respond(['success' => false, 'message' => 'name_taken'], 409);
            }
            throw $e;
        }
        respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        try {
            $stmt = $pdo->prepare("UPDATE {$table} SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
        } catch (PDOException $e) {
            if (isDuplicateKeyError($e)) {
                respond(['success' => false, 'message' => 'name_taken'], 409);
            }
            throw $e;
        }
        respond(['success' => true]);
    }

    if ($action === 'activate' || $action === 'deactivate') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        $value = $action === 'activate' ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE {$table} SET is_active = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        respond(['success' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        $check = $pdo->prepare("SELECT COUNT(*) FROM {$refTable} WHERE {$refColumn} = ?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            respond(['success' => false, 'message' => 'in_use'], 409);
        }
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        respond(['success' => true]);
    }
}

/**
 * Approver list: which users may be picked as the approving manager on a
 * checklist. Unlike the other master entities this holds no name of its own —
 * a row is a pointer at a users row, so the person keeps one identity for
 * login, notifications and the approval screens.
 *
 * create takes user_id (an active user whose role is manager) rather than a
 * name. delete is refused while checklists still point at that user, exactly
 * like the other entities' "in_use" guard, because removing them would leave
 * those checklists showing an approver who is no longer on the list.
 */
function handleApprover(PDO $pdo, string $action, array $input): void
{
    if ($action === 'create') {
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $userCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'manager' AND is_active = 1");
        $userCheck->execute([$userId]);
        if (!$userCheck->fetch()) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        // Re-adding someone who was deactivated just switches them back on.
        $stmt = $pdo->prepare(
            'INSERT INTO approvers (user_id, is_active) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE is_active = 1'
        );
        $stmt->execute([$userId]);
        respond(['success' => true]);
    }

    if ($action === 'activate' || $action === 'deactivate') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        $stmt = $pdo->prepare('UPDATE approvers SET is_active = ? WHERE id = ?');
        $stmt->execute([$action === 'activate' ? 1 : 0, $id]);
        respond(['success' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $row = $pdo->prepare('SELECT user_id FROM approvers WHERE id = ?');
        $row->execute([$id]);
        $userId = $row->fetchColumn();
        if ($userId === false) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $used = $pdo->prepare('SELECT COUNT(*) FROM checklists WHERE assigned_manager_id = ?');
        $used->execute([$userId]);
        if ((int)$used->fetchColumn() > 0) {
            respond(['success' => false, 'message' => 'in_use'], 409);
        }

        $pdo->prepare('DELETE FROM approvers WHERE id = ?')->execute([$id]);
        respond(['success' => true]);
    }
}

function handleItem(PDO $pdo, string $action, array $input): void
{
    if ($action === 'create') {
        $dieType = (string)($input['die_type'] ?? '');
        $topic = trim((string)($input['topic'] ?? ''));
        $note = trim((string)($input['note'] ?? ''));

        if (!in_array($dieType, ['solid', 'hollow'], true) || $topic === '') {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM checklist_items WHERE die_type = ?');
        $maxOrder->execute([$dieType]);
        $nextOrder = (int)$maxOrder->fetchColumn() + 1;

        $stmt = $pdo->prepare('INSERT INTO checklist_items (die_type, topic, note, is_active, sort_order) VALUES (?, ?, ?, 1, ?)');
        $stmt->execute([$dieType, $topic, $note !== '' ? $note : null, $nextOrder]);
        respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $topic = trim((string)($input['topic'] ?? ''));
        $note = trim((string)($input['note'] ?? ''));

        if ($id <= 0 || $topic === '') {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $stmt = $pdo->prepare('UPDATE checklist_items SET topic = ?, note = ? WHERE id = ?');
        $stmt->execute([$topic, $note !== '' ? $note : null, $id]);
        respond(['success' => true]);
    }

    if ($action === 'activate' || $action === 'deactivate') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        $value = $action === 'activate' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE checklist_items SET is_active = ? WHERE id = ?');
        $stmt->execute([$value, $id]);
        respond(['success' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        $check = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM checklist_selected_items WHERE item_id = ?) +
                (SELECT COUNT(*) FROM checklist_results WHERE item_id = ?) AS usage_count'
        );
        $check->execute([$id, $id]);
        if ((int)$check->fetchColumn() > 0) {
            respond(['success' => false, 'message' => 'in_use'], 409);
        }
        $stmt = $pdo->prepare('DELETE FROM checklist_items WHERE id = ?');
        $stmt->execute([$id]);
        respond(['success' => true]);
    }

    if ($action === 'move') {
        $id = (int)($input['id'] ?? 0);
        $direction = (string)($input['direction'] ?? '');
        if ($id <= 0 || !in_array($direction, ['up', 'down'], true)) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $itemStmt = $pdo->prepare('SELECT id, die_type FROM checklist_items WHERE id = ?');
        $itemStmt->execute([$id]);
        $item = $itemStmt->fetch();
        if (!$item) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }

        $listStmt = $pdo->prepare('SELECT id, sort_order FROM checklist_items WHERE die_type = ? ORDER BY sort_order ASC, id ASC');
        $listStmt->execute([$item['die_type']]);
        $siblings = $listStmt->fetchAll();

        $index = null;
        foreach ($siblings as $i => $row) {
            if ((int)$row['id'] === $id) {
                $index = $i;
                break;
            }
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === null || $swapIndex < 0 || $swapIndex >= count($siblings)) {
            respond(['success' => true]);
        }

        $current = $siblings[$index];
        $swap = $siblings[$swapIndex];

        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE checklist_items SET sort_order = ? WHERE id = ?');
        $update->execute([$swap['sort_order'], $current['id']]);
        $update->execute([$current['sort_order'], $swap['id']]);
        $pdo->commit();

        respond(['success' => true]);
    }
}
