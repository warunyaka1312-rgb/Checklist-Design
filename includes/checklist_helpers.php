<?php
/**
 * Shared read helpers for the checklist workflow (engineer create/view pages).
 */

/**
 * Whether at least one external API connection (api_settings) is enabled.
 * Used to gate the "Fetch from API" button — no real API call is implemented
 * yet, this only reflects whether an admin has turned a connection on.
 */
function isImageFetchApiEnabled(PDO $pdo): bool
{
    return (bool)$pdo->query('SELECT COUNT(*) FROM api_settings WHERE is_enabled = 1')->fetchColumn();
}

/**
 * Active master checklist items, grouped by die_type as a flat list
 * (no sub-category grouping — the real checklist is a single ordered list).
 * @return array{solid: array<int,array{id:int,topic:string,note:?string}>, hollow: array<int,array{id:int,topic:string,note:?string}>}
 */
function fetchActiveChecklistItems(PDO $pdo): array
{
    $items = $pdo->query('SELECT id, die_type, topic, note FROM checklist_items WHERE is_active = 1 ORDER BY die_type ASC, sort_order ASC, id ASC')->fetchAll();

    $result = ['solid' => [], 'hollow' => []];
    foreach ($items as $it) {
        $result[$it['die_type']][] = [
            'id' => (int)$it['id'],
            'topic' => $it['topic'],
            'note' => $it['note'],
        ];
    }

    return $result;
}

/**
 * Full detail bundle for one checklist: the record, its selected item ids,
 * its saved results (master items keyed by item_id, plus custom items keyed
 * by "custom_<result_row_id>"), its custom items as a flat list, and its
 * full history (with actor names).
 */
function fetchChecklistWithDetails(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM checklists WHERE id = ?');
    $stmt->execute([$id]);
    $checklist = $stmt->fetch();
    if (!$checklist) {
        return null;
    }

    $selectedStmt = $pdo->prepare('SELECT item_id FROM checklist_selected_items WHERE checklist_id = ?');
    $selectedStmt->execute([$id]);
    $selectedItemIds = array_map('intval', array_column($selectedStmt->fetchAll(), 'item_id'));

    $resultsStmt = $pdo->prepare(
        'SELECT id, item_id, result, comment, prev_result, note_before, image_path, image_source, is_custom_item, custom_topic, custom_note
         FROM checklist_results WHERE checklist_id = ?'
    );
    $resultsStmt->execute([$id]);
    $results = [];
    $customItems = [];
    foreach ($resultsStmt->fetchAll() as $r) {
        if ((int)$r['is_custom_item'] === 1) {
            $key = 'custom_' . $r['id'];
            $entry = [
                'result' => $r['result'],
                'comment' => $r['comment'],
                'prev_result' => $r['prev_result'],
                'note_before' => $r['note_before'],
                'image_path' => $r['image_path'],
                'image_source' => $r['image_source'],
                'is_custom_item' => true,
                'custom_topic' => $r['custom_topic'],
                'custom_note' => $r['custom_note'],
            ];
            $results[$key] = $entry;
            $customItems[] = ['key' => $key] + $entry;
        } else {
            $results[(int)$r['item_id']] = [
                'result' => $r['result'],
                'comment' => $r['comment'],
                'prev_result' => $r['prev_result'],
                'note_before' => $r['note_before'],
                'image_path' => $r['image_path'],
                'image_source' => $r['image_source'],
            ];
        }
    }

    $historyStmt = $pdo->prepare(
        'SELECT h.id, h.action, h.note, h.old_status, h.new_status, h.created_at, u.full_name AS action_by_name
         FROM checklist_history h
         JOIN users u ON u.id = h.action_by
         WHERE h.checklist_id = ?
         ORDER BY h.created_at ASC, h.id ASC'
    );
    $historyStmt->execute([$id]);
    $history = $historyStmt->fetchAll();

    return [
        'checklist' => $checklist,
        'selected_item_ids' => $selectedItemIds,
        'results' => $results,
        'custom_items' => $customItems,
        'history' => $history,
    ];
}

/**
 * Checklists awaiting this manager's decision, oldest first.
 */
function fetchPendingForManager(PDO $pdo, int $managerId): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.die_no, c.die_type, c.status, c.created_at,
                dm.name AS model_name, cu.name AS customer_name,
                creator.full_name AS creator_name
         FROM checklists c
         JOIN die_models dm ON dm.id = c.model_id
         JOIN customers cu ON cu.id = c.customer_id
         JOIN users creator ON creator.id = c.created_by
         WHERE c.assigned_manager_id = ? AND c.status = 'pending'
         ORDER BY c.created_at ASC"
    );
    $stmt->execute([$managerId]);
    return $stmt->fetchAll();
}

/**
 * Checklists this manager has already approved/rejected, newest decision first.
 */
function fetchDecidedByManager(PDO $pdo, int $managerId): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.die_no, c.die_type, c.status, c.created_at, c.decided_at,
                dm.name AS model_name, cu.name AS customer_name,
                creator.full_name AS creator_name
         FROM checklists c
         JOIN die_models dm ON dm.id = c.model_id
         JOIN customers cu ON cu.id = c.customer_id
         JOIN users creator ON creator.id = c.created_by
         WHERE c.decided_by = ?
         ORDER BY c.decided_at DESC"
    );
    $stmt->execute([$managerId]);
    return $stmt->fetchAll();
}

function statusBadgeHtml(string $status): string
{
    $map = [
        'draft' => ['bg-status-mutedBg text-status-mutedText', t('status_draft')],
        'pending' => ['bg-status-warnBg text-status-warnText', t('status_pending')],
        'approved' => ['bg-status-successBg text-status-successText', t('status_approved')],
        'rejected' => ['bg-status-dangerBg text-status-dangerText', t('status_rejected')],
    ];
    [$classes, $label] = $map[$status] ?? ['bg-status-mutedBg text-status-mutedText', $status];
    return '<span class="status-pill ' . $classes . '">' . htmlspecialchars($label) . '</span>';
}

/**
 * Plain-text (non-badge) label for a status value, for use in the
 * before -> after status-change log. Falls back to the raw value if
 * a translation key isn't defined.
 */
function statusLabelText(?string $status): string
{
    if ($status === null || $status === '') {
        return '-';
    }
    $map = [
        'draft' => t('status_draft'),
        'pending' => t('status_pending'),
        'approved' => t('status_approved'),
        'rejected' => t('status_rejected'),
    ];
    return $map[$status] ?? $status;
}

function historyActionLabel(string $action): string
{
    $map = [
        'submitted' => t('history_action_submitted'),
        'approved' => t('history_action_approved'),
        'rejected' => t('history_action_rejected'),
        'edited' => t('history_action_edited'),
    ];
    return $map[$action] ?? $action;
}

/**
 * Render a checklist_history note as HTML: the first line inline (after a
 * middot, same as before), and — when the save also flipped one or more
 * item results — every following line as its own bullet in a real list
 * instead of one long semicolon-joined sentence. Already HTML-escaped.
 */
function historyNoteHtml(?string $note): string
{
    if ($note === null || trim($note) === '') {
        return '';
    }
    $lines = preg_split('/\r\n|\r|\n/', $note);
    $main = array_shift($lines);
    $html = ' &middot; ' . htmlspecialchars($main);
    if (!empty($lines)) {
        $header = array_shift($lines);
        $html .= '<div class="mt-1"><span class="font-medium text-steel-600">' . htmlspecialchars($header) . '</span>'
            . '<ul class="list-disc list-inside mt-0.5 space-y-0.5">';
        foreach ($lines as $line) {
            $line = ltrim($line, "\xE2\x80\xA2 \t"); // strip a leading "• " bullet char, if present
            if ($line === '') {
                continue;
            }
            $html .= '<li>' . htmlspecialchars($line) . '</li>';
        }
        $html .= '</ul></div>';
    }
    return $html;
}

/**
 * PDF file icon: a document with a folded corner, drawn in red because that is
 * the colour everyone associates with a PDF. Used on the "open the attached
 * file" buttons in the checklist views, which appear in four places across the
 * engineer and manager pages.
 */
function pdfIconSvg(string $classes = 'w-4 h-4 text-red-600 shrink-0'): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" class="' . htmlspecialchars($classes) . '"'
        . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">'
        . '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" />'
        . '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" />'
        . '<path stroke-linecap="round" stroke-linejoin="round" d="M8.5 13.5h1.2a1.1 1.1 0 0 1 0 2.2H8.5V18m6.8-4.5h-1.6V18m0-2.4h1.4m-4-2.1V18" />'
        . '</svg>';
}
