<?php
/**
 * JSON API for the engineer checklist workflow.
 * Actions: action=save (create/update, draft or submit) and action=delete
 * (remove a draft/rejected checklist the caller owns).
 * เมื่อสร้าง checklist ใหม่ที่มี Tech แต่ยังไม่มีรูปแบบ จะพยายามดึงรูปจาก Drawing API ให้อัตโนมัติ
 * (ถ้าดึงไม่ได้ก็สร้างต่อตามปกติ ไม่ทำให้การสร้างล้มเหลว — engineer กดดึงเองหรืออัปโหลดเองทีหลังได้)
 * Auth: session cookie, role engineer or admin. Requires X-CSRF-Token header.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/drawing_api.php';
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
if (!$me || !in_array($me['role'], ['engineer', 'admin'], true)) {
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
    if ($action === 'save') {
        handleSave($pdo, $me, $input);
    }
    if ($action === 'delete') {
        handleDelete($pdo, $me, $input);
    }
    respond(['success' => false, 'message' => 'unknown_action'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!empty($GLOBALS['autoFetchedImageFile'])) {
        @unlink(UPLOAD_PATH_IMAGES . '/' . basename($GLOBALS['autoFetchedImageFile']));
    }
    respond([
        'success' => false,
        'message' => 'server_error',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], 500);
}

/**
 * Resolve the checklist's model_id from what the form sent.
 *
 * The engineer may either pick an existing die_models row (model_id) or type a
 * Model that is not in master data yet (model_name). In the second case the
 * name is added to die_models here, so it becomes a normal master-data option
 * from then on — that is the only way an engineer can extend this list, since
 * api/master_data_api.php is admin-only.
 *
 * A name that already exists is reused rather than duplicated: die_models.name
 * is a UNIQUE key under utf8mb4_unicode_ci, so the lookup is case-insensitive
 * and matches what the INSERT would collide on. An existing but deactivated
 * row is reused as-is and deliberately NOT re-activated — hiding it was an
 * admin decision, and quietly undoing it here would override them.
 *
 * Responds 422 and exits when neither a valid id nor a usable name was given.
 */
function resolveDieModelId(PDO $pdo, int $modelId, string $modelName): int
{
    if ($modelId > 0) {
        $byId = $pdo->prepare('SELECT id FROM die_models WHERE id = ?');
        $byId->execute([$modelId]);
        if ($byId->fetch()) {
            return $modelId;
        }
    }

    // Trimmed here as well as at the call site: a leading/trailing space
    // would otherwise slip past the unique key and create a near-duplicate
    // row (MySQL ignores trailing spaces in VARCHAR compares, not leading).
    $modelName = trim($modelName);

    // VARCHAR(150) in the schema — reject rather than let MySQL truncate.
    if ($modelName === '' || mb_strlen($modelName) > 150) {
        respond(['success' => false, 'message' => 'invalid_input'], 422);
    }

    $byName = $pdo->prepare('SELECT id FROM die_models WHERE name = ?');
    $byName->execute([$modelName]);
    $existing = $byName->fetchColumn();
    if ($existing !== false) {
        return (int)$existing;
    }

    try {
        $insert = $pdo->prepare('INSERT INTO die_models (name, is_active) VALUES (?, 1)');
        $insert->execute([$modelName]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Another request inserted the same name between the SELECT and the
        // INSERT — take the row that won instead of failing the save.
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
        $byName->execute([$modelName]);
        $raced = $byName->fetchColumn();
        if ($raced === false) {
            throw $e;
        }
        return (int)$raced;
    }
}

/**
 * Resolve the checklist's customer_id from what the form sent — the Customer
 * counterpart of resolveDieModelId() above.
 *
 * Three ways a customer can arrive from the form:
 *   - customer_id         an existing row was picked from the list
 *   - customer_card_code  a SAP customer was picked that has no local row yet
 *   - customer_name only  a name was typed that is in neither
 *
 * The card code is tried before the name because it is SAP's stable identity:
 * a customer renamed in SAP still matches its own row instead of spawning a
 * duplicate. A row created here is inserted WITH that code when we have one, so
 * the next syncCustomersFromApi() run updates this row rather than adding a
 * second. With no code we insert card_code = NULL, which is what lets that sync
 * adopt the row later by name (it looks for "name = ? AND card_code IS NULL").
 *
 * Responds 422 and exits when none of the three identifies a customer.
 */
function resolveCustomerId(PDO $pdo, int $customerId, string $customerName, string $cardCode = ''): int
{
    if ($customerId > 0) {
        $byId = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $byId->execute([$customerId]);
        if ($byId->fetch()) {
            return $customerId;
        }
    }

    $customerName = trim($customerName);
    $cardCode = trim($cardCode);

    if ($cardCode !== '') {
        $byCode = $pdo->prepare('SELECT id FROM customers WHERE card_code = ?');
        $byCode->execute([$cardCode]);
        $existing = $byCode->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }
    }

    // VARCHAR(150) in the schema — reject rather than let MySQL truncate.
    if ($customerName === '' || mb_strlen($customerName) > 150) {
        respond(['success' => false, 'message' => 'invalid_input'], 422);
    }
    // VARCHAR(30), and a bad code would poison the SAP link — drop it instead.
    if (mb_strlen($cardCode) > 30) {
        $cardCode = '';
    }

    $byName = $pdo->prepare('SELECT id, card_code FROM customers WHERE name = ?');
    $byName->execute([$customerName]);
    $row = $byName->fetch();
    if ($row) {
        // Known locally but not linked yet, and we now know its SAP code: link it.
        if ($cardCode !== '' && ($row['card_code'] === null || $row['card_code'] === '')) {
            $link = $pdo->prepare('UPDATE customers SET card_code = ? WHERE id = ?');
            try {
                $link->execute([$cardCode, $row['id']]);
            } catch (PDOException $e) {
                // Another row already owns that code — leave this one unlinked.
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        return (int)$row['id'];
    }

    try {
        $insert = $pdo->prepare('INSERT INTO customers (card_code, name, is_active) VALUES (?, ?, 1)');
        $insert->execute([$cardCode !== '' ? $cardCode : null, $customerName]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Another request inserted the same name or code in between — take the
        // row that won rather than failing the save.
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
        $byName->execute([$customerName]);
        $raced = $byName->fetch();
        if ($raced) {
            return (int)$raced['id'];
        }
        if ($cardCode !== '') {
            $byCode = $pdo->prepare('SELECT id FROM customers WHERE card_code = ?');
            $byCode->execute([$cardCode]);
            $racedCode = $byCode->fetchColumn();
            if ($racedCode !== false) {
                return (int)$racedCode;
            }
        }
        throw $e;
    }
}

/**
 * Find-or-create for the simple (id, name, is_active) master tables that the
 * SAP tech-drawing lookup can fill in: materials and tempers.
 *
 * The feed carries values our seed lists never had — materials 6106, 6060,
 * 6101, SF6063 and tempers O, T4, T64, H112 — so a fetched drawing would
 * otherwise be unsaveable. Creating the row here is what makes the master data
 * "extensible in future" without an admin having to pre-enter every value.
 *
 * $required = false lets the field stay empty (Temper is nullable), in which
 * case null is returned rather than a 422.
 */
function resolveMasterNameId(
    PDO $pdo,
    string $table,
    string $uniqueColumnMaxLen,
    int $id,
    string $name,
    bool $required = true
): ?int {
    // Whitelisted, never interpolated from request data.
    if (!in_array($table, ['materials', 'tempers'], true)) {
        throw new InvalidArgumentException('unsupported master table: ' . $table);
    }
    $maxLen = (int)$uniqueColumnMaxLen;

    if ($id > 0) {
        $byId = $pdo->prepare("SELECT id FROM {$table} WHERE id = ?");
        $byId->execute([$id]);
        if ($byId->fetch()) {
            return $id;
        }
    }

    $name = trim($name);
    if ($name === '' || mb_strlen($name) > $maxLen) {
        if ($required) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        return null;
    }

    $byName = $pdo->prepare("SELECT id FROM {$table} WHERE name = ?");
    $byName->execute([$name]);
    $existing = $byName->fetchColumn();
    if ($existing !== false) {
        return (int)$existing;
    }

    try {
        $insert = $pdo->prepare("INSERT INTO {$table} (name, is_active) VALUES (?, 1)");
        $insert->execute([$name]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Another request inserted the same name in between — take the winner.
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
        $byName->execute([$name]);
        $raced = $byName->fetchColumn();
        if ($raced === false) {
            throw $e;
        }
        return (int)$raced;
    }
}

/**
 * The approving manager must hold the manager role in Keycloak (see
 * assignableManagers), but a checklist that already points at someone stays
 * valid even after they lose it — otherwise taking the role away would make
 * every open checklist of theirs unsaveable.
 */
function assertManagerAssignable(PDO $pdo, int $managerId, ?int $currentManagerId): void
{
    if ($currentManagerId !== null && $managerId === $currentManagerId) {
        return;
    }

    if (!in_array($managerId, array_column(assignableManagers($pdo), 'id'), true)) {
        respond(['success' => false, 'message' => 'manager_not_assignable'], 422);
    }
}

/**
 * Delete a checklist the caller owns.
 *
 * Same gate as editing: the creator (or an admin) may remove it while it is
 * still a draft or has been rejected back to them. A checklist that is pending
 * sits in a manager's queue, and an approved one is the record of that
 * decision, so neither may be deleted — those respond 'locked'.
 *
 * checklist_results, checklist_selected_items and checklist_history all cascade
 * from checklists. notifications do not (they point at a URL, not a row), so
 * the ones linking to this checklist are removed explicitly to avoid leaving a
 * manager with a notification that opens a dead page.
 */
function handleDelete(PDO $pdo, array $me, array $input): void
{
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'message' => 'invalid_input'], 422);
    }

    $stmt = $pdo->prepare('SELECT id, created_by, status, design_pdf_path, design_image_path FROM checklists WHERE id = ?');
    $stmt->execute([$id]);
    $checklist = $stmt->fetch();
    if (!$checklist) {
        respond(['success' => false, 'message' => 'not_found'], 404);
    }

    $isOwner = (int)$checklist['created_by'] === (int)$me['id'];
    $isAdmin = $me['role'] === 'admin';
    if (!$isOwner && !$isAdmin) {
        respond(['success' => false, 'message' => 'forbidden'], 403);
    }
    if (!in_array($checklist['status'], ['draft', 'rejected'], true)) {
        respond(['success' => false, 'message' => 'locked'], 409);
    }

    // Gathered before the rows go, removed only once the delete has committed.
    $files = [];
    if (!empty($checklist['design_pdf_path'])) {
        $files[] = UPLOAD_PATH_PDF . '/' . basename((string)$checklist['design_pdf_path']);
    }
    if (!empty($checklist['design_image_path'])) {
        $files[] = UPLOAD_PATH_IMAGES . '/' . basename((string)$checklist['design_image_path']);
    }
    $imgStmt = $pdo->prepare('SELECT image_path FROM checklist_results WHERE checklist_id = ? AND image_path IS NOT NULL');
    $imgStmt->execute([$id]);
    foreach ($imgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if ((string)$path !== '') {
            $files[] = UPLOAD_PATH_IMAGES . '/' . basename((string)$path);
        }
    }

    $pdo->beginTransaction();
    try {
        // Matched exactly rather than with LIKE, since the link text contains
        // underscores that LIKE would treat as wildcards.
        $pdo->prepare('DELETE FROM notifications WHERE link IN (?, ?)')->execute([
            APP_BASE_URL . '/engineer/checklist_view.php?id=' . $id,
            APP_BASE_URL . '/manager/checklist_view.php?id=' . $id,
        ]);
        $pdo->prepare('DELETE FROM checklists WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Best-effort: the row is already gone, so a file that cannot be removed is
    // an orphan on disk, not a failed delete.
    foreach (array_unique($files) as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    respond(['success' => true]);
}

function handleSave(PDO $pdo, array $me, array $input): void
{
    $id = (int)($input['id'] ?? 0);
    $dieNo = trim((string)($input['die_no'] ?? ''));
    $tech = trim((string)($input['tech'] ?? '')) ?: null;
    $modelId = (int)($input['model_id'] ?? 0);
    $modelName = trim((string)($input['model_name'] ?? ''));
    $customerId = (int)($input['customer_id'] ?? 0);
    $customerName = trim((string)($input['customer_name'] ?? ''));
    $customerCardCode = trim((string)($input['customer_card_code'] ?? ''));
    $dieType = (string)($input['die_type'] ?? '');
    $materialId = (int)($input['material_id'] ?? 0);
    $materialName = trim((string)($input['material_name'] ?? ''));
    $temperId = (int)($input['temper_id'] ?? 0);
    $temperName = trim((string)($input['temper_name'] ?? ''));
    $managerId = (int)($input['assigned_manager_id'] ?? 0) ?: null;
    $status = (string)($input['status'] ?? 'draft');
    $selectedItemIds = array_values(array_unique(array_map('intval', $input['selected_item_ids'] ?? [])));
    $results = is_array($input['results'] ?? null) ? $input['results'] : [];
    $designPdfPath = $input['design_pdf_path'] ?? null;
    $designPdfPath = is_string($designPdfPath) && $designPdfPath !== '' ? basename($designPdfPath) : null;
    $designImagePath = $input['design_image_path'] ?? null;
    $designImagePath = is_string($designImagePath) && $designImagePath !== '' ? basename($designImagePath) : null;

    if (
        $dieNo === ''
        || !in_array($dieType, ['solid', 'hollow'], true)
        || !in_array($status, ['draft', 'pending'], true)
    ) {
        respond(['success' => false, 'message' => 'invalid_input'], 422);
    }

    // Model may arrive either as a picked master-data id or as a name the
    // engineer typed that is not in die_models yet — the latter is added to
    // master data here so the next checklist can just pick it from the list.
    $modelId = resolveDieModelId($pdo, $modelId, $modelName);

    // Same find-or-create as the Model above: the engineer may type a
    // customer that is not in master data yet.
    $customerId = resolveCustomerId($pdo, $customerId, $customerName, $customerCardCode);

    // Material and Temper may also arrive as names the SAP tech-drawing
    // lookup filled in, which the seed master lists do not always contain.
    $materialId = (int)resolveMasterNameId($pdo, 'materials', '50', $materialId, $materialName, true);
    $temperId = resolveMasterNameId($pdo, 'tempers', '20', $temperId, $temperName, false);

    if (!empty($selectedItemIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedItemIds), '?'));
        $itemCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM checklist_items WHERE id IN ($placeholders) AND die_type = ?"
        );
        $itemCheck->execute([...$selectedItemIds, $dieType]);
        if ((int)$itemCheck->fetchColumn() !== count($selectedItemIds)) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
    }

    // Custom (non-master) items: only entries with a non-blank topic are kept;
    // a row the engineer added but left empty is silently dropped.
    $customEntries = [];
    foreach ($results as $key => $r) {
        if (!is_array($r) || empty($r['is_custom_item'])) {
            continue;
        }
        $topic = trim((string)($r['custom_topic'] ?? ''));
        if ($topic === '') {
            continue;
        }
        $customEntries[$key] = [
            'topic' => $topic,
            'note' => trim((string)($r['custom_note'] ?? '')) ?: null,
            'result' => in_array($r['result'] ?? null, ['pass', 'fail'], true) ? $r['result'] : null,
            'comment' => trim((string)($r['comment'] ?? '')),
            'image_path' => !empty($r['image_path']) ? basename((string)$r['image_path']) : null,
        ];
    }

    $existing = null;
    if ($id > 0) {
        $existingStmt = $pdo->prepare('SELECT * FROM checklists WHERE id = ?');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            respond(['success' => false, 'message' => 'not_found'], 404);
        }
        $isOwner = (int)$existing['created_by'] === (int)$me['id'];
        $isAdmin = $me['role'] === 'admin';
        if (!$isOwner && !$isAdmin) {
            respond(['success' => false, 'message' => 'forbidden'], 403);
        }
        if (!in_array($existing['status'], ['draft', 'rejected'], true)) {
            respond(['success' => false, 'message' => 'locked'], 409);
        }
    }

    // Checked here rather than with the other fields because it needs to know
    // who this checklist is already assigned to (see assertManagerAssignable).
    if ($managerId !== null) {
        $currentManagerId = $existing && $existing['assigned_manager_id'] !== null
            ? (int)$existing['assigned_manager_id']
            : null;
        assertManagerAssignable($pdo, $managerId, $currentManagerId);
    }

    // Diff against whatever is currently saved so a Pass<->Fail flip gets
    // called out in the checklist's history. Either direction of the flip
    // is forced to say something new in the comment: Pass -> Fail must
    // explain why, and Fail -> Pass must confirm what was fixed (simply
    // leaving the old Fail comment in place doesn't count).
    //
    // The note itself is kept as two boxes on the checklist_results row:
    // note_before (the comment as it stood right before the last flip) and
    // the existing comment column, which now doubles as "note after" — e.g.
    // ก่อนเปลี่ยน Fail "ความเร็วไม่ได้" → หลังเปลี่ยน Pass "ความเร็วได้แล้ว".
    // A save that doesn't flip an item just carries its prev_result/
    // note_before forward unchanged.
    $oldResultByItemId = [];
    $oldTopicByItemId = [];
    $oldCommentByItemId = [];
    $oldPrevResultByItemId = [];
    $oldNoteBeforeByItemId = [];
    $oldResultByCustomTopic = [];
    $oldCommentByCustomTopic = [];
    $oldPrevResultByCustomTopic = [];
    $oldNoteBeforeByCustomTopic = [];
    if ($existing) {
        $oldStmt = $pdo->prepare(
            'SELECT cr.item_id, cr.result, cr.comment, cr.prev_result, cr.note_before, cr.is_custom_item, cr.custom_topic, ci.topic
             FROM checklist_results cr
             LEFT JOIN checklist_items ci ON ci.id = cr.item_id
             WHERE cr.checklist_id = ?'
        );
        $oldStmt->execute([$id]);
        foreach ($oldStmt->fetchAll() as $row) {
            if ((int)$row['is_custom_item'] === 1) {
                if ($row['custom_topic'] !== null) {
                    $oldResultByCustomTopic[$row['custom_topic']] = $row['result'];
                    $oldCommentByCustomTopic[$row['custom_topic']] = (string)($row['comment'] ?? '');
                    $oldPrevResultByCustomTopic[$row['custom_topic']] = $row['prev_result'];
                    $oldNoteBeforeByCustomTopic[$row['custom_topic']] = $row['note_before'];
                }
            } else {
                $oldResultByItemId[(int)$row['item_id']] = $row['result'];
                $oldTopicByItemId[(int)$row['item_id']] = $row['topic'];
                $oldCommentByItemId[(int)$row['item_id']] = (string)($row['comment'] ?? '');
                $oldPrevResultByItemId[(int)$row['item_id']] = $row['prev_result'];
                $oldNoteBeforeByItemId[(int)$row['item_id']] = $row['note_before'];
            }
        }
    }

    // Final prev_result/note_before values to persist per item this save —
    // set below, then written out alongside the INSERT further down.
    $prevResultByItemId = [];
    $noteBeforeByItemId = [];
    $prevResultByCustomTopic = [];
    $noteBeforeByCustomTopic = [];

    $resultChangeNotes = [];
    $hasUnexplainedPassToFail = false;
    $hasUnconfirmedFailToPass = false;
    foreach ($selectedItemIds as $itemId) {
        $r = $results[$itemId] ?? null;
        $newResult = $r['result'] ?? null;
        if (!in_array($newResult, ['pass', 'fail'], true)) {
            continue;
        }
        $oldResult = $oldResultByItemId[$itemId] ?? null;
        if ($oldResult !== null && $oldResult !== $newResult) {
            $topic = $oldTopicByItemId[$itemId] ?? ('#' . $itemId);
            $comment = trim((string)($r['comment'] ?? ''));
            $oldComment = trim($oldCommentByItemId[$itemId] ?? '');
            $oldLabel = $oldResult === 'fail' ? 'Fail' : 'Pass';
            $newLabel = $newResult === 'fail' ? 'Fail' : 'Pass';
            $line = $topic . ': ' . $oldLabel . ' → ' . $newLabel;
            if ($oldResult === 'pass' && $newResult === 'fail') {
                if ($comment === '') {
                    $hasUnexplainedPassToFail = true;
                } else {
                    $line .= ' | ก่อนเปลี่ยน (' . $oldLabel . ') หมายเหตุ: ' . ($oldComment !== '' ? $oldComment : '-')
                        . ' | หลังเปลี่ยน (' . $newLabel . ') หมายเหตุ: ' . $comment;
                }
            } elseif ($oldResult === 'fail' && $newResult === 'pass') {
                if ($comment === '' || $comment === $oldComment) {
                    $hasUnconfirmedFailToPass = true;
                } else {
                    $line .= ' | ก่อนเปลี่ยน (' . $oldLabel . ') หมายเหตุ: ' . ($oldComment !== '' ? $oldComment : '-')
                        . ' | หลังเปลี่ยน (' . $newLabel . ') หมายเหตุ: ' . $comment;
                }
            }
            $resultChangeNotes[] = $line;
            $prevResultByItemId[$itemId] = $oldResult;
            $noteBeforeByItemId[$itemId] = $oldComment !== '' ? $oldComment : null;
        } else {
            $prevResultByItemId[$itemId] = $oldPrevResultByItemId[$itemId] ?? null;
            $noteBeforeByItemId[$itemId] = $oldNoteBeforeByItemId[$itemId] ?? null;
        }
    }
    foreach ($customEntries as $key => $c) {
        $oldResult = $oldResultByCustomTopic[$c['topic']] ?? null;
        if ($c['result'] !== null && $oldResult !== null && $oldResult !== $c['result']) {
            $oldComment = trim($oldCommentByCustomTopic[$c['topic']] ?? '');
            $oldLabel = $oldResult === 'fail' ? 'Fail' : 'Pass';
            $newLabel = $c['result'] === 'fail' ? 'Fail' : 'Pass';
            $line = $c['topic'] . ': ' . $oldLabel . ' → ' . $newLabel;
            if ($oldResult === 'pass' && $c['result'] === 'fail') {
                if ($c['comment'] === '') {
                    $hasUnexplainedPassToFail = true;
                } else {
                    $line .= ' | ก่อนเปลี่ยน (' . $oldLabel . ') หมายเหตุ: ' . ($oldComment !== '' ? $oldComment : '-')
                        . ' | หลังเปลี่ยน (' . $newLabel . ') หมายเหตุ: ' . $c['comment'];
                }
            } elseif ($oldResult === 'fail' && $c['result'] === 'pass') {
                if ($c['comment'] === '' || $c['comment'] === $oldComment) {
                    $hasUnconfirmedFailToPass = true;
                } else {
                    $line .= ' | ก่อนเปลี่ยน (' . $oldLabel . ') หมายเหตุ: ' . ($oldComment !== '' ? $oldComment : '-')
                        . ' | หลังเปลี่ยน (' . $newLabel . ') หมายเหตุ: ' . $c['comment'];
                }
            }
            $resultChangeNotes[] = $line;
            $prevResultByCustomTopic[$key] = $oldResult;
            $noteBeforeByCustomTopic[$key] = $oldComment !== '' ? $oldComment : null;
        } else {
            $prevResultByCustomTopic[$key] = $oldPrevResultByCustomTopic[$c['topic']] ?? null;
            $noteBeforeByCustomTopic[$key] = $oldNoteBeforeByCustomTopic[$c['topic']] ?? null;
        }
    }
    if ($hasUnexplainedPassToFail) {
        respond(['success' => false, 'message' => 'pass_to_fail_reason_required'], 422);
    }
    if ($hasUnconfirmedFailToPass) {
        respond(['success' => false, 'message' => 'fail_to_pass_comment_required'], 422);
    }

    // "Fail" items always require a comment, on draft save and on submit alike.
    foreach ($selectedItemIds as $itemId) {
        $r = $results[$itemId] ?? null;
        if ($r && ($r['result'] ?? null) === 'fail' && trim((string)($r['comment'] ?? '')) === '') {
            respond(['success' => false, 'message' => 'fail_needs_comment'], 422);
        }
    }
    foreach ($customEntries as $c) {
        if ($c['result'] === 'fail' && $c['comment'] === '') {
            respond(['success' => false, 'message' => 'fail_needs_comment'], 422);
        }
    }

    if ($status === 'pending') {
        if ($managerId === null) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        if (empty($selectedItemIds) && empty($customEntries)) {
            respond(['success' => false, 'message' => 'invalid_input'], 422);
        }
        foreach ($selectedItemIds as $itemId) {
            $r = $results[$itemId] ?? null;
            if (!$r || !in_array($r['result'] ?? null, ['pass', 'fail'], true)) {
                respond(['success' => false, 'message' => 'incomplete_results'], 422);
            }
        }
        foreach ($customEntries as $c) {
            if ($c['result'] === null) {
                respond(['success' => false, 'message' => 'incomplete_results'], 422);
            }
        }
        if ($designPdfPath === null) {
            respond(['success' => false, 'message' => 'pdf_required'], 422);
        }
    }

    $isNew = $existing === null;
    $previousStatus = $existing['status'] ?? null;

    // ดึงรูปแบบอัตโนมัติตอนสร้างใหม่ — ทำก่อนเริ่ม transaction เพื่อไม่ให้การรอ API ไปถือ transaction ค้างไว้
    // $imageFetchStatus: null = ไม่ได้พยายามดึง, 'ok' = ดึงสำเร็จ, อื่น ๆ = คีย์ข้อความ (i18n) บอกสาเหตุที่ดึงไม่ได้
    $imageAutoFetched = false;
    $imageFetchStatus = null;
    if ($isNew && $tech !== null && $designImagePath === null) {
        if (!isDrawingApiConfigured()) {
            $imageFetchStatus = 'drawing_api_not_configured';
            logDrawingFetch("tech={$tech} SKIPPED IMAGE_FETCH_API_URL / IMAGE_FETCH_API_KEY are not set in includes/.env");
        } else {
            try {
                $fetched = fetchDrawingImage($tech);
                $designImagePath = $fetched['filename'];
                $GLOBALS['autoFetchedImageFile'] = $fetched['filename']; // ลบทิ้งถ้าการบันทึกล้มเหลว
                $imageAutoFetched = true;
                $imageFetchStatus = 'ok';
                logDrawingFetch("tech={$tech} OK {$fetched['filename']} <- {$fetched['source_url']}");
            } catch (Throwable $e) {
                // ไม่พบแบบ / API ล่ม / ไม่มีรูป — สร้าง checklist ต่อโดยไม่มีรูป แต่บันทึกสาเหตุไว้และแจ้งผู้ใช้
                $key = $e instanceof DrawingApiException ? $e->messageKey : 'drawing_api_failed';
                $imageFetchStatus = in_array($key, ['drawing_not_found', 'drawing_no_image', 'drawing_api_not_configured'], true)
                    ? $key
                    : 'drawing_api_failed';
                logDrawingFetch("tech={$tech} FAILED [{$key}] " . $e->getMessage());
            }
        }
    }

    $pdo->beginTransaction();

    if ($isNew) {
        $stmt = $pdo->prepare(
            'INSERT INTO checklists (die_no, tech, model_id, customer_id, die_type, material_id, temper_id, created_by, assigned_manager_id, status, design_pdf_path, design_image_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$dieNo, $tech, $modelId, $customerId, $dieType, $materialId, $temperId, $me['id'], $managerId, $status, $designPdfPath, $designImagePath]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare(
            'UPDATE checklists
             SET die_no = ?, tech = ?, model_id = ?, customer_id = ?, die_type = ?, material_id = ?, temper_id = ?, assigned_manager_id = ?, status = ?, design_pdf_path = ?, design_image_path = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$dieNo, $tech, $modelId, $customerId, $dieType, $materialId, $temperId, $managerId, $status, $designPdfPath, $designImagePath, $id]);
    }

    $pdo->prepare('DELETE FROM checklist_selected_items WHERE checklist_id = ?')->execute([$id]);
    $insertSelected = $pdo->prepare('INSERT INTO checklist_selected_items (checklist_id, item_id) VALUES (?, ?)');
    foreach ($selectedItemIds as $itemId) {
        $insertSelected->execute([$id, $itemId]);
    }

    $pdo->prepare('DELETE FROM checklist_results WHERE checklist_id = ?')->execute([$id]);
    $insertResult = $pdo->prepare(
        'INSERT INTO checklist_results (checklist_id, item_id, result, comment, prev_result, note_before, image_path, image_source, is_custom_item, custom_topic, custom_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($selectedItemIds as $itemId) {
        $r = $results[$itemId] ?? null;
        $resultValue = $r['result'] ?? null;
        if (!in_array($resultValue, ['pass', 'fail'], true)) {
            continue;
        }
        $comment = trim((string)($r['comment'] ?? ''));
        $imagePath = !empty($r['image_path']) ? basename((string)$r['image_path']) : null;
        $insertResult->execute([
            $id, $itemId, $resultValue, $comment !== '' ? $comment : null,
            $prevResultByItemId[$itemId] ?? null, $noteBeforeByItemId[$itemId] ?? null,
            $imagePath, 'manual', 0, null, null,
        ]);
    }
    // Custom items are always persisted once their topic is filled in, even
    // without a Pass/Fail yet — unlike master items, they have no other
    // table backing their identity, so dropping the row would lose the topic.
    foreach ($customEntries as $key => $c) {
        $insertResult->execute([
            $id, null, $c['result'], $c['comment'] !== '' ? $c['comment'] : null,
            $prevResultByCustomTopic[$key] ?? null, $noteBeforeByCustomTopic[$key] ?? null,
            $c['image_path'], 'manual', 1, $c['topic'], $c['note'],
        ]);
    }

    if ($status === 'pending') {
        if ($previousStatus === 'rejected') {
            $historyAction = 'edited';
            $historyNote = 'แก้ไขข้อมูลและส่งอนุมัติใหม่หลังถูกตีกลับ';
        } else {
            $historyAction = 'submitted';
            $historyNote = 'ส่งอนุมัติ';
        }
    } else {
        $historyAction = 'edited';
        $historyNote = $isNew ? 'สร้างฉบับร่าง' : 'บันทึกฉบับร่าง';
    }
    if ($imageAutoFetched) {
        $historyNote .= ' (ดึงรูปแบบจาก API อัตโนมัติ)';
    }
    if (!empty($resultChangeNotes)) {
        // One change per line (rendered as a bullet list on the history page)
        // instead of a semicolon-joined run-on sentence.
        $historyNote .= "\nเปลี่ยนผลตรวจ:\n" . implode("\n", array_map(static fn($c) => '• ' . $c, $resultChangeNotes));
    }
    $pdo->prepare('INSERT INTO checklist_history (checklist_id, action, action_by, note, old_status, new_status) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $historyAction, $me['id'], $historyNote, $previousStatus, $status]);

    if ($status === 'pending' && $managerId !== null) {
        $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)')->execute([
            $managerId,
            "Die-No {$dieNo} ส่งมารออนุมัติจากคุณ",
            APP_BASE_URL . '/manager/checklist_view.php?id=' . $id,
        ]);
    }

    $pdo->commit();

    respond(['success' => true, 'id' => $id, 'image_fetch' => $imageFetchStatus]);
}
