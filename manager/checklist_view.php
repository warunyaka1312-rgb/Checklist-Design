<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';

requireRole('manager');

$me = currentUser();
$pdo = getDbConnection();

$id = (int)($_GET['id'] ?? 0);
$data = $id > 0 ? fetchChecklistWithDetails($pdo, $id) : null;
if (!$data) {
    http_response_code(404);
    die('Checklist not found.');
}

$checklist = $data['checklist'];
$isAssignedManager = (int)$checklist['assigned_manager_id'] === (int)$me['id'];
$canDecide = $isAssignedManager && $checklist['status'] === 'pending';

$modelNameStmt = $pdo->prepare('SELECT name FROM die_models WHERE id = ?');
$modelNameStmt->execute([$checklist['model_id']]);
$modelName = $modelNameStmt->fetchColumn() ?: '-';

// Material and Temper describe the alloy the die is cut for, so they belong
// next to the other basic info the approver reads before deciding.
$materialNameStmt = $pdo->prepare('SELECT name FROM materials WHERE id = ?');
$materialNameStmt->execute([$checklist['material_id']]);
$materialName = $materialNameStmt->fetchColumn() ?: '-';

$temperName = '-';
if (!empty($checklist['temper_id'])) {
    $temperNameStmt = $pdo->prepare('SELECT name FROM tempers WHERE id = ?');
    $temperNameStmt->execute([$checklist['temper_id']]);
    $temperName = $temperNameStmt->fetchColumn() ?: '-';
}

$customerNameStmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
$customerNameStmt->execute([$checklist['customer_id']]);
$customerName = $customerNameStmt->fetchColumn() ?: '-';

$creatorNameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
$creatorNameStmt->execute([$checklist['created_by']]);
$creatorName = $creatorNameStmt->fetchColumn() ?: '-';

$items = fetchActiveChecklistItems($pdo);
$itemsById = [];
foreach ($items[$checklist['die_type']] ?? [] as $it) {
    $itemsById[$it['id']] = $it;
}

$selectedItems = [];
foreach ($data['selected_item_ids'] as $itemId) {
    if (!isset($itemsById[$itemId])) {
        continue;
    }
    $r = $data['results'][$itemId] ?? ['result' => null, 'comment' => null, 'image_path' => null, 'prev_result' => null, 'note_before' => null];
    $selectedItems[] = [
        'topic' => $itemsById[$itemId]['topic'],
        'note' => $itemsById[$itemId]['note'],
        'is_custom' => false,
        'result' => $r['result'],
        'comment' => $r['comment'],
        // Result + comment from before the last Pass<->Fail flip, so the
        // approver sees why an item failed, not only why it now passes.
        'prev_result' => $r['prev_result'] ?? null,
        'note_before' => $r['note_before'] ?? null,
        'image_url' => $r['image_path'] ? UPLOAD_URL_IMAGES . '/' . $r['image_path'] : null,
    ];
}
foreach ($data['custom_items'] as $ci) {
    $selectedItems[] = [
        'topic' => $ci['custom_topic'],
        'note' => $ci['custom_note'],
        'is_custom' => true,
        'result' => $ci['result'],
        'comment' => $ci['comment'],
        'prev_result' => $ci['prev_result'] ?? null,
        'note_before' => $ci['note_before'] ?? null,
        'image_url' => $ci['image_path'] ? UPLOAD_URL_IMAGES . '/' . $ci['image_path'] : null,
    ];
}

$pdfUrl = $checklist['design_pdf_path'] ? UPLOAD_URL_PDF . '/' . $checklist['design_pdf_path'] : null;
$designImageUrl = !empty($checklist['design_image_path']) ? UPLOAD_URL_IMAGES . '/' . $checklist['design_image_path'] : null;

$reviewConfig = [
    'checklistId' => (int)$checklist['id'],
    'csrfToken' => csrfToken(),
    'redirectUrl' => 'pending_approval.php',
    'i18n' => [
        'server_error' => t('server_error'),
        'invalid_csrf' => t('invalid_csrf'),
        'forbidden' => t('forbidden'),
        'not_pending' => t('not_pending_status'),
        'not_found' => t('not_found'),
        'invalid_input' => t('invalid_input'),
        'reject_reason_required' => t('reject_reason_required'),
        'confirm_approve' => t('confirm_approve'),
    ],
];

$pageTitle = t('view_checklist_title') . ' - ' . $checklist['die_no'];
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div x-data="managerReview(<?= jsonForAttr($reviewConfig) ?>)">

  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
      <h1 class="text-xl font-semibold text-navy-900 font-tabular"><?= htmlspecialchars($checklist['die_no']) ?></h1>
      <?= statusBadgeHtml($checklist['status']) ?>
    </div>
    <a href="<?= htmlspecialchars(APP_BASE_URL) ?>/api/export_pdf.php?id=<?= (int)$checklist['id'] ?>" target="_blank" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50">
      <?= htmlspecialchars(t('export_pdf')) ?>
    </a>
  </div>

  <?php if (!$isAssignedManager): ?>
    <div class="bg-steel-100 border border-steel-300 text-steel-600 text-sm rounded px-4 py-3 mb-4"><?= htmlspecialchars(t('view_only_notice_manager')) ?></div>
  <?php endif; ?>

  <!-- Basic info: laid out horizontally to keep this card short -->
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 mb-4">
    <div class="text-sm font-semibold text-navy-900 mb-4"><?= htmlspecialchars(t('step1_title')) ?></div>
    <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-6 gap-y-3 text-sm">
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('die_no_label')) ?></dt><dd class="font-tabular font-medium text-navy-900"><?= htmlspecialchars($checklist['die_no']) ?></dd></div>
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_model')) ?></dt><dd class="font-tabular"><?= htmlspecialchars($modelName) ?></dd></div>
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_customer')) ?></dt><dd><?= htmlspecialchars($customerName) ?></dd></div>
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('die_type_solid_hollow')) ?></dt><dd><?= htmlspecialchars(t($checklist['die_type'] . '_die')) ?></dd></div>
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_material')) ?></dt><dd class="font-tabular"><?= htmlspecialchars($materialName) ?></dd></div>
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_temper')) ?></dt><dd class="font-tabular"><?= htmlspecialchars($temperName) ?></dd></div>
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('creator')) ?></dt><dd><?= htmlspecialchars($creatorName) ?></dd></div>
      <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('created_at')) ?></dt><dd class="font-tabular text-steel-500"><?= htmlspecialchars(date('d M Y H:i', strtotime($checklist['created_at']))) ?></dd></div>
    </dl>
  </div>

  <!-- Design drawing picture (from API, not yet connected) + PDF attachment -->
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 mb-4">
    <div class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('design_picture_title')) ?></div>
    <div class="grid grid-cols-1 md:grid-cols-[minmax(0,320px)_1fr] gap-6">
      <?php if ($designImageUrl): ?>
        <!-- Same click-to-enlarge viewer as engineer/checklist_view.php. The
             approver reads the drawing to decide, and 192px of letterboxed
             thumbnail is not enough to judge one by. -->
        <div x-data="{ zoomOpen: false }">
          <div class="h-48 rounded overflow-hidden border border-steel-200 bg-steel-50">
            <img
              src="<?= htmlspecialchars($designImageUrl) ?>"
              @click="zoomOpen = true"
              class="w-full h-full object-contain cursor-zoom-in"
              title="<?= htmlspecialchars(t('zoom_image')) ?>"
              alt="<?= htmlspecialchars(t('design_picture_title')) ?>"
            >
          </div>

          <div
            x-show="zoomOpen"
            x-cloak
            @keydown.escape.window="zoomOpen = false"
            @click="zoomOpen = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-navy-950/85 p-4 cursor-zoom-out no-print"
            role="dialog"
            aria-modal="true"
          >
            <img src="<?= htmlspecialchars($designImageUrl) ?>" @click.stop class="max-w-full max-h-full object-contain bg-white rounded shadow-softLg cursor-default">
            <a
              href="<?= htmlspecialchars($designImageUrl) ?>"
              target="_blank"
              rel="noopener"
              @click.stop
              class="absolute bottom-4 left-1/2 -translate-x-1/2 text-xs text-white/80 hover:text-white underline"
            ><?= htmlspecialchars(t('open_full_size')) ?></a>
            <button
              type="button"
              @click="zoomOpen = false"
              class="absolute top-4 right-4 w-9 h-9 flex items-center justify-center rounded-full bg-white/90 hover:bg-white text-navy-900 text-xl leading-none"
              aria-label="<?= htmlspecialchars(t('close_image')) ?>"
            >&times;</button>
          </div>
        </div>
      <?php else: ?>
        <div class="flex flex-col items-center justify-center gap-2 h-48 border-2 border-dashed border-steel-300 rounded bg-steel-50 text-steel-400 text-center px-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-9 h-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
          <span class="text-xs"><?= htmlspecialchars(t('design_picture_api_not_connected')) ?></span>
        </div>
      <?php endif; ?>
      <div class="flex flex-col justify-center">
        <div class="text-xs font-medium text-steel-500 mb-2"><?= htmlspecialchars(t('attached_pdf_title')) ?></div>
        <?php if ($pdfUrl): ?>
          <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" rel="noopener" class="self-start inline-flex items-center gap-2 px-3 py-2 border border-steel-300 rounded text-sm text-steel-700 hover:bg-steel-50 hover:border-steel-400">
            <?= pdfIconSvg() ?>
            <span><?= htmlspecialchars(t('open_attached_file')) ?></span>
          </a>
        <?php else: ?>
          <span class="text-sm text-steel-400"><?= htmlspecialchars(t('no_pdf_attached')) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Inspection results: compact table (No / Topic / Result / Notes) -->
  <div class="space-y-3 mb-4">
    <div class="text-sm font-semibold text-navy-900"><?= htmlspecialchars(t('inspection_results_title')) ?></div>
    <?php if (!empty($selectedItems)): ?>
      <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
        <div class="hidden sm:grid grid-cols-[3rem_1fr_7rem_1fr] gap-3 px-3 py-2 bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
          <div><?= htmlspecialchars(t('table_col_no')) ?></div>
          <div><?= htmlspecialchars(t('table_col_topic')) ?></div>
          <div class="text-center"><?= htmlspecialchars(t('table_col_result')) ?></div>
          <div><?= htmlspecialchars(t('table_col_notes')) ?></div>
        </div>
        <div class="divide-y divide-steel-100">
          <?php foreach ($selectedItems as $i => $si): ?>
            <div class="grid grid-cols-[3rem_1fr_7rem_1fr] gap-3 px-3 py-3 items-center <?= $si['result'] === 'fail' ? 'bg-red-50' : 'bg-green-50' ?>">
              <div class="font-tabular text-sm text-steel-500"><?= $i + 1 ?></div>
              <div>
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-medium text-navy-900"><?= htmlspecialchars($si['topic']) ?></span>
                  <?php if ($si['is_custom']): ?>
                    <span class="status-pill bg-status-infoBg text-status-infoText"><?= htmlspecialchars(t('custom_item_badge')) ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($si['note'])): ?>
                  <div class="text-xs text-steel-500"><?= htmlspecialchars($si['note']) ?></div>
                <?php endif; ?>
              </div>
              <div class="text-center">
                <span class="status-pill <?= $si['result'] === 'fail' ? 'bg-status-dangerBg text-status-dangerText' : 'bg-status-successBg text-status-successText' ?>">
                  <?= $si['result'] === 'fail' ? htmlspecialchars(t('result_fail')) : htmlspecialchars(t('result_pass')) ?>
                </span>
              </div>
              <?php
              // Notes: when this item's result was flipped, show both comments
              // labelled by the result each belongs to, so the Fail reason stays
              // visible next to the Pass reason that replaced it.
              $hasPrevNote = !empty($si['prev_result']) && trim((string)$si['note_before']) !== '';
              $labelFor = static fn(?string $r): string => $r === 'fail' ? t('result_fail') : t('result_pass');
              $labelClassFor = static fn(?string $r): string => $r === 'fail' ? 'text-status-dangerText' : 'text-status-successText';
              ?>
              <div class="text-sm text-steel-600 min-w-0 space-y-0.5">
                <?php if ($hasPrevNote): ?>
                  <div class="flex items-start gap-1.5">
                    <span class="text-[11px] font-semibold shrink-0 leading-5 <?= $labelClassFor($si['prev_result']) ?>"><?= htmlspecialchars($labelFor($si['prev_result'])) ?>:</span>
                    <span class="text-steel-500 break-words"><?= nl2br(htmlspecialchars((string)$si['note_before'])) ?></span>
                  </div>
                <?php endif; ?>
                <div class="flex items-start gap-1.5">
                  <?php if ($hasPrevNote): ?>
                    <span class="text-[11px] font-semibold shrink-0 leading-5 <?= $labelClassFor($si['result']) ?>"><?= htmlspecialchars($labelFor($si['result'])) ?>:</span>
                  <?php endif; ?>
                  <span class="break-words"><?= !empty($si['comment']) ? nl2br(htmlspecialchars($si['comment'])) : '&mdash;' ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500"><?= htmlspecialchars(t('no_items_selected_yet')) ?></div>
    <?php endif; ?>
  </div>

  <!-- History -->
  <div class="max-w-2xl mb-8">
    <h2 class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('history_title')) ?></h2>
    <?php if (empty($data['history'])): ?>
      <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-4 text-sm text-steel-500"><?= htmlspecialchars(t('no_history')) ?></div>
    <?php else: ?>
      <div class="bg-white border border-steel-200 rounded-xl shadow-soft divide-y divide-steel-100">
        <?php foreach (array_reverse($data['history']) as $h): ?>
          <div class="px-4 py-3 text-sm">
            <div class="flex items-center justify-between">
              <span class="font-medium text-navy-900"><?= htmlspecialchars(historyActionLabel($h['action'])) ?></span>
              <span class="text-xs text-steel-400 font-tabular"><?= htmlspecialchars(date('d M Y H:i', strtotime($h['created_at']))) ?></span>
            </div>
            <div class="text-xs text-steel-500 mt-0.5"><?= htmlspecialchars($h['action_by_name']) ?><?= historyNoteHtml($h['note'] ?? null) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Decision panel -->
  <?php if ($canDecide): ?>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 mb-8 max-w-2xl">
    <div class="text-sm font-semibold text-navy-900 mb-4"><?= htmlspecialchars(t('decision_panel_title')) ?></div>

    <div x-show="errorMsg" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 mb-4" x-text="errorMsg"></div>

    <div x-show="!rejecting">
      <div class="flex gap-3">
        <button type="button" @click="approve()" :disabled="submitting" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium disabled:opacity-50">✅ <?= htmlspecialchars(t('approve_button')) ?></button>
        <button type="button" @click="startReject()" :disabled="submitting" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm font-medium disabled:opacity-50">❌ <?= htmlspecialchars(t('reject_button')) ?></button>
      </div>
    </div>

    <div x-show="rejecting" x-cloak>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('reject_reason_label')) ?></label>
      <textarea x-model="rejectNote" rows="3" placeholder="<?= htmlspecialchars(t('reject_reason_placeholder')) ?>" class="w-full border border-steel-300 rounded px-3 py-2 text-sm mb-3"></textarea>
      <div class="flex gap-3">
        <button type="button" @click="reject()" :disabled="submitting" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm font-medium disabled:opacity-50">❌ <?= htmlspecialchars(t('reject_button')) ?></button>
        <button type="button" @click="cancelReject()" :disabled="submitting" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('cancel')) ?></button>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php
$extraScripts = '<script src="' . APP_BASE_URL . '/assets/js/manager_review.js"></script>';
include __DIR__ . '/../includes/footer.php';
