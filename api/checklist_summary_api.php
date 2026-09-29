<?php
/**
 * Read-only summary for the "view summary" button shown on approved
 * checklists in the dashboard lists: the design drawing image (if any)
 * plus a compact No / Topic / Result / Notes table.
 * Auth: session cookie. Access mirrors api/export_pdf.php — admin/manager
 * see any checklist, engineer sees only checklists they created.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$me = currentUser();
if (!$me) {
    respond(['success' => false, 'message' => 'forbidden'], 403);
}

$pdo = getDbConnection();
$id = (int)($_GET['id'] ?? 0);

try {
    $data = $id > 0 ? fetchChecklistWithDetails($pdo, $id) : null;
    if (!$data) {
        respond(['success' => false, 'message' => 'not_found'], 404);
    }

    $checklist = $data['checklist'];
    $isOwner = (int)$checklist['created_by'] === (int)$me['id'];
    $canView = $me['role'] === 'admin' || $me['role'] === 'manager' || ($me['role'] === 'engineer' && $isOwner);
    if (!$canView) {
        respond(['success' => false, 'message' => 'forbidden'], 403);
    }

    $activeItems = fetchActiveChecklistItems($pdo);
    $itemsById = [];
    foreach ($activeItems[$checklist['die_type']] ?? [] as $it) {
        $itemsById[$it['id']] = $it;
    }

    $rows = [];
    foreach ($data['selected_item_ids'] as $itemId) {
        if (!isset($itemsById[$itemId])) {
            continue;
        }
        $r = $data['results'][$itemId] ?? ['result' => null, 'comment' => null, 'prev_result' => null, 'note_before' => null];
        $rows[] = [
            'topic' => $itemsById[$itemId]['topic'],
            'result' => $r['result'],
            'comment' => $r['comment'],
            'prev_result' => $r['prev_result'] ?? null,
            'note_before' => $r['note_before'] ?? null,
        ];
    }
    foreach ($data['custom_items'] as $ci) {
        $rows[] = [
            'topic' => $ci['custom_topic'],
            'result' => $ci['result'],
            'comment' => $ci['comment'],
            'prev_result' => $ci['prev_result'] ?? null,
            'note_before' => $ci['note_before'] ?? null,
        ];
    }

    // design_image_path only exists after migration_006 has been run; read
    // it defensively so an un-migrated database doesn't break this endpoint.
    $designImagePath = $checklist['design_image_path'] ?? null;

    respond([
        'success' => true,
        'die_no' => $checklist['die_no'],
        'status' => $checklist['status'],
        'design_image_url' => $designImagePath ? UPLOAD_URL_IMAGES . '/' . $designImagePath : null,
        'items' => array_map(static function (array $row, int $idx): array {
            return [
                'no' => $idx + 1,
                'topic' => $row['topic'],
                'result' => $row['result'],
                'note' => $row['comment'],
                'prev_result' => $row['prev_result'],
                'note_before' => $row['note_before'],
            ];
        }, $rows, array_keys($rows)),
    ]);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => 'server_error',
        'detail' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null,
    ], 500);
}
