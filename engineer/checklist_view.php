<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';
require_once __DIR__ . '/../includes/drawing_api.php';
require_once __DIR__ . '/../includes/keycloak.php';

requireRole(['engineer', 'admin']);

$me = currentUser();
$pdo = getDbConnection();

$id = (int)($_GET['id'] ?? 0);
$data = $id > 0 ? fetchChecklistWithDetails($pdo, $id) : null;
if (!$data) {
    http_response_code(404);
    die('Checklist not found.');
}

$checklist = $data['checklist'];
$isOwner = (int)$checklist['created_by'] === (int)$me['id'];
$isAdmin = $me['role'] === 'admin';
$canEdit = ($isOwner || $isAdmin) && in_array($checklist['status'], ['draft', 'rejected'], true);

$models = $pdo->query('SELECT id, name FROM die_models WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$customers = $pdo->query('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$managers = assignableManagers($pdo);
$materials = $pdo->query('SELECT id, name FROM materials WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$tempers = $pdo->query('SELECT id, name FROM tempers WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$items = fetchActiveChecklistItems($pdo);

// Make sure the checklist's current model/customer/manager are selectable even if since deactivated.
$ensureOption = static function (array &$list, ?int $id, ?string $name, string $nameKey = 'name') {
    if ($id === null) {
        return;
    }
    foreach ($list as $row) {
        if ((int)$row['id'] === $id) {
            return;
        }
    }
    $list[] = ['id' => $id, $nameKey => $name ?? ('#' . $id)];
};

$modelNameStmt = $pdo->prepare('SELECT name FROM die_models WHERE id = ?');
$modelNameStmt->execute([$checklist['model_id']]);
$ensureOption($models, (int)$checklist['model_id'], $modelNameStmt->fetchColumn() ?: null);

$customerNameStmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
$customerNameStmt->execute([$checklist['customer_id']]);
$ensureOption($customers, (int)$checklist['customer_id'], $customerNameStmt->fetchColumn() ?: null);

if ($checklist['assigned_manager_id']) {
    $managerNameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $managerNameStmt->execute([$checklist['assigned_manager_id']]);
    $ensureOption($managers, (int)$checklist['assigned_manager_id'], $managerNameStmt->fetchColumn() ?: null, 'full_name');
}

$materialNameStmt = $pdo->prepare('SELECT name FROM materials WHERE id = ?');
$materialNameStmt->execute([$checklist['material_id']]);
$ensureOption($materials, (int)$checklist['material_id'], $materialNameStmt->fetchColumn() ?: null);

if (!empty($checklist['temper_id'])) {
    $temperNameStmt = $pdo->prepare('SELECT name FROM tempers WHERE id = ?');
    $temperNameStmt->execute([$checklist['temper_id']]);
    $ensureOption($tempers, (int)$checklist['temper_id'], $temperNameStmt->fetchColumn() ?: null);
}

$modelsForJs = array_map(static fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $models);
$materialsForJs = array_map(static fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $materials);
$tempersForJs = array_map(static fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $tempers);
$customersForJs = array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $customers);
$managersForJs = array_map(static fn($m) => ['id' => (int)$m['id'], 'full_name' => $m['full_name']], $managers);

$initialResults = new stdClass();
foreach ($data['results'] as $key => $r) {
    $entry = [
        'result' => $r['result'],
        'comment' => $r['comment'],
        // The result + comment as they stood right before the last
        // Pass<->Fail flip, so the results table can show the reason an item
        // failed next to the reason it now passes (migration 007).
        'prev_result' => $r['prev_result'] ?? null,
        'note_before' => $r['note_before'] ?? null,
        'image_path' => $r['image_path'],
        'image_url' => $r['image_path'] ? UPLOAD_URL_IMAGES . '/' . $r['image_path'] : null,
    ];
    if (!empty($r['is_custom_item'])) {
        $entry['is_custom_item'] = true;
        $entry['custom_topic'] = $r['custom_topic'];
        $entry['custom_note'] = $r['custom_note'];
    }
    $initialResults->$key = $entry;
}

$i18nMap = [
    'invalid_input' => t('invalid_input'),
    'name_taken' => t('name_taken'),
    'server_error' => t('server_error'),
    'invalid_csrf' => t('invalid_csrf'),
    'forbidden' => t('forbidden'),
    'fail_needs_comment' => t('fail_needs_comment'),
    'incomplete_results' => t('incomplete_results'),
    'pdf_required' => t('pdf_required'),
    'pass_to_fail_reason_required' => t('pass_to_fail_reason_required'),
    'changed_from_pass_hint' => t('changed_from_pass_hint'),
    'fail_to_pass_comment_required' => t('fail_to_pass_comment_required'),
    'changed_from_fail_hint' => t('changed_from_fail_hint'),
    'locked' => t('locked'),
    'not_found' => t('not_found'),
    'file_too_large' => t('file_too_large'),
    'invalid_file_type' => t('invalid_file_type'),
    'upload_failed' => t('upload_failed'),
    'drawing_tech_required' => t('drawing_tech_required'),
    'drawing_not_found' => t('drawing_not_found'),
    'drawing_no_image' => t('drawing_no_image'),
    'drawing_api_failed' => t('drawing_api_failed'),
    'drawing_api_not_configured' => t('drawing_api_not_configured'),
    'result_pass' => t('result_pass'),
    'result_fail' => t('result_fail'),
];

$pageTitle = t('view_checklist_title') . ' - ' . $checklist['die_no'];
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';

// สาเหตุที่ดึงรูปอัตโนมัติตอนสร้างไม่สำเร็จ (ส่งมาจาก checklist_form.js ผ่าน ?img_msg=) — รับเฉพาะคีย์ที่กำหนดไว้
$imgMsgKey = (string)($_GET['img_msg'] ?? '');
$imageFetchNotice = in_array($imgMsgKey, ['drawing_not_found', 'drawing_no_image', 'drawing_api_failed', 'drawing_api_not_configured'], true)
    ? t($imgMsgKey)
    : '';

$config = [
    'mode' => 'view',
    'checklistId' => (int)$checklist['id'],
    'csrfToken' => csrfToken(),
    'models' => $modelsForJs,
    'materials' => $materialsForJs,
    'tempers' => $tempersForJs,
    'customers' => $customersForJs,
    'managers' => $managersForJs,
    'items' => $items,
    'readOnly' => !$canEdit,
    'imageApiEnabled' => isDrawingApiConfigured(),
    'imageFetchNotice' => $imageFetchNotice,
    'i18n' => $i18nMap,
    'initial' => [
        'die_no' => $checklist['die_no'],
        'tech' => $checklist['tech'],
        'model_id' => (int)$checklist['model_id'],
        'customer_id' => (int)$checklist['customer_id'],
        'die_type' => $checklist['die_type'],
        'material_id' => (int)$checklist['material_id'],
        'temper_id' => $checklist['temper_id'] ? (int)$checklist['temper_id'] : null,
        'assigned_manager_id' => $checklist['assigned_manager_id'] ? (int)$checklist['assigned_manager_id'] : null,
        'status' => $checklist['status'],
        'selected_item_ids' => $data['selected_item_ids'],
        'results' => $initialResults,
        'design_pdf_path' => $checklist['design_pdf_path'],
        'design_pdf_url' => $checklist['design_pdf_path'] ? UPLOAD_URL_PDF . '/' . $checklist['design_pdf_path'] : null,
        'design_image_path' => $checklist['design_image_path'] ?? null,
        'design_image_url' => ($checklist['design_image_path'] ?? null) ? UPLOAD_URL_IMAGES . '/' . $checklist['design_image_path'] : null,
    ],
];
?>

<div x-data="checklistForm(<?= jsonForAttr($config) ?>)">

  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
      <h1 class="text-xl font-semibold text-navy-900 font-tabular"><?= htmlspecialchars($checklist['die_no']) ?></h1>
      <?= statusBadgeHtml($checklist['status']) ?>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= htmlspecialchars(APP_BASE_URL) ?>/api/export_pdf.php?id=<?= (int)$checklist['id'] ?>" target="_blank" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50">
        <?= htmlspecialchars(t('export_pdf')) ?>
      </a>
      <template x-if="!readOnly">
        <button type="button" @click="save('draft')" :disabled="savingDraft || submitting" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50 disabled:opacity-50">
          <span x-show="!savingDraft"><?= htmlspecialchars(t('save_draft')) ?></span>
          <span x-show="savingDraft" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
        </button>
      </template>
    </div>
  </div>

  <template x-if="readOnly">
    <div class="bg-steel-100 border border-steel-300 text-steel-600 text-sm rounded px-4 py-3 mb-4"><?= htmlspecialchars(t('read_only_notice')) ?></div>
  </template>

  <div x-show="formError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 mb-4 max-w-2xl" x-text="formError"></div>

  <!-- Basic info: laid out horizontally (a row of fields) to keep this card short -->
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 mb-4">
    <div class="text-sm font-semibold text-navy-900 mb-4"><?= htmlspecialchars(t('step1_title')) ?></div>

    <template x-if="!readOnly">
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('die_no_label')) ?></label>
          <input type="text" x-model="dieNo" class="w-full border border-steel-300 rounded px-3 py-2 text-sm font-tabular focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>
        <div class="relative">
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('label_model')) ?></label>
          <input
            type="text"
            x-model="modelQuery"
            @focus="modelDropdownOpen = true"
            @blur="onModelBlur()"
            placeholder="<?= htmlspecialchars(t('search_model_placeholder')) ?>"
            autocomplete="off"
            :class="modelConfirmed ? 'border-steel-300' : 'border-red-400'"
            class="w-full border rounded px-3 py-2 text-sm"
          >
          <div x-show="modelDropdownOpen" x-cloak class="absolute z-10 mt-1 w-full bg-white border border-steel-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
            <template x-for="m in filteredModels" :key="m.id">
              <div @mousedown.prevent="selectModel(m)" class="px-3 py-2 text-sm cursor-pointer hover:bg-accent-50" x-text="m.name"></div>
            </template>
            <div x-show="filteredModels.length === 0" class="px-3 py-2 text-xs text-steel-500"><?= htmlspecialchars(t('no_matching_options')) ?></div>
          </div>
          <div x-show="!modelConfirmed" x-cloak class="text-xs text-red-600 mt-1"><?= htmlspecialchars(t('no_matching_options')) ?></div>
        </div>
        <div class="relative">
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('label_customer')) ?></label>
          <input
            type="text"
            x-model="customerQuery"
            @focus="customerDropdownOpen = true"
            @blur="onCustomerBlur()"
            placeholder="<?= htmlspecialchars(t('search_customer_placeholder')) ?>"
            autocomplete="off"
            :class="customerConfirmed ? 'border-steel-300' : 'border-red-400'"
            class="w-full border rounded px-3 py-2 text-sm"
          >
          <div x-show="customerDropdownOpen" x-cloak class="absolute z-10 mt-1 w-full bg-white border border-steel-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
            <template x-for="c in filteredCustomers" :key="c.id">
              <div @mousedown.prevent="selectCustomer(c)" class="px-3 py-2 text-sm cursor-pointer hover:bg-accent-50" x-text="c.name"></div>
            </template>
            <div x-show="filteredCustomers.length === 0" class="px-3 py-2 text-xs text-steel-500"><?= htmlspecialchars(t('no_matching_options')) ?></div>
          </div>
          <div x-show="!customerConfirmed" x-cloak class="text-xs text-red-600 mt-1"><?= htmlspecialchars(t('no_matching_options')) ?></div>
        </div>
        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('die_type_solid_hollow')) ?></label>
          <div class="flex gap-4 h-[38px] items-center">
            <label class="flex items-center gap-1.5 text-sm"><input type="radio" value="solid" x-model="dieType" @change="onDieTypeChange()"> <?= htmlspecialchars(t('solid_die')) ?></label>
            <label class="flex items-center gap-1.5 text-sm"><input type="radio" value="hollow" x-model="dieType" @change="onDieTypeChange()"> <?= htmlspecialchars(t('hollow_die')) ?></label>
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('label_manager')) ?></label>
          <select x-model.number="managerId" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
            <template x-for="m in managers" :key="m.id"><option :value="m.id" x-text="m.full_name"></option></template>
          </select>
        </div>
      </div>
    </template>

    <template x-if="readOnly">
      <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-6 gap-y-3 text-sm">
        <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('die_no_label')) ?></dt><dd class="font-tabular font-medium text-navy-900" x-text="dieNo"></dd></div>
        <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_model')) ?></dt><dd class="font-tabular" x-text="modelName"></dd></div>
        <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_customer')) ?></dt><dd x-text="customerName"></dd></div>
        <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('die_type_solid_hollow')) ?></dt><dd x-text="dieType === 'solid' ? '<?= htmlspecialchars(t('solid_die')) ?>' : '<?= htmlspecialchars(t('hollow_die')) ?>'"></dd></div>
        <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_material')) ?></dt><dd class="font-tabular" x-text="materialName"></dd></div>
        <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_temper')) ?></dt><dd class="font-tabular" x-text="temperName"></dd></div>
        <div><dt class="text-xs text-steel-500"><?= htmlspecialchars(t('label_manager')) ?></dt><dd x-text="managerName"></dd></div>
      </dl>
    </template>
  </div>

  <!-- Design drawing picture (from API, not yet connected) + PDF attachment -->
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 mb-4">
    <div class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('design_picture_title')) ?></div>
    <div class="grid grid-cols-1 md:grid-cols-[minmax(0,320px)_1fr] gap-6">
      <!-- Picture frame: shows the manually uploaded drawing image once the
           drawing-image API is wired up this can also be filled from there. -->
      <div>
        <div x-show="!designImageUrl" class="flex flex-col items-center justify-center gap-2 h-48 border-2 border-dashed border-steel-300 rounded bg-steel-50 text-steel-400 text-center px-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-9 h-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
          <span x-show="!imageApiEnabled" class="text-xs"><?= htmlspecialchars(t('design_picture_api_not_connected')) ?></span>
          <template x-if="!readOnly">
            <div class="flex flex-wrap items-center justify-center gap-2 mt-1">
              <button type="button" x-show="imageApiEnabled" @click="fetchDesignImageFromApi()" :disabled="designImageFetching" class="px-3 py-1.5 border border-accent-500 rounded text-xs text-accent-600 bg-white hover:bg-accent-50 disabled:opacity-50">
                <span x-show="!designImageFetching"><?= htmlspecialchars(t('fetch_from_api')) ?></span>
                <span x-show="designImageFetching" x-cloak><?= htmlspecialchars(t('fetching_from_api')) ?></span>
              </button>
              <label class="inline-block px-3 py-1.5 border border-steel-300 rounded text-xs text-accent-600 cursor-pointer bg-white hover:bg-accent-50">
                <?= htmlspecialchars(t('choose_image_file')) ?>
                <input type="file" accept="image/png,image/jpeg" class="hidden" @change="uploadDesignImage($event)">
              </label>
            </div>
          </template>
        </div>
        <!-- Own x-data island: the viewer needs no state from checklistForm, and
             Alpine still resolves designImageUrl from the parent scope. The same
             markup therefore drops into manager/checklist_view.php unchanged. -->
        <div x-data="{ zoomOpen: false }">
          <div x-show="designImageUrl" x-cloak class="relative h-48 rounded overflow-hidden border border-steel-200 bg-steel-50">
            <!-- The thumbnail is letterboxed into 192px, so a drawing is only
                 readable once opened full screen. -->
            <img
              :src="designImageUrl"
              @click="zoomOpen = true"
              class="w-full h-full object-contain cursor-zoom-in"
              title="<?= htmlspecialchars(t('zoom_image')) ?>"
              alt="<?= htmlspecialchars(t('design_picture_title')) ?>"
            >
            <template x-if="!readOnly">
              <button type="button" @click="removeDesignImage()" class="absolute top-1.5 right-1.5 w-6 h-6 flex items-center justify-center rounded-full bg-white/90 border border-steel-200 text-red-600 hover:bg-red-50 text-sm leading-none">&times;</button>
            </template>
          </div>

          <!-- Full-screen viewer. Clicking the backdrop or pressing Escape closes
               it; clicking the image itself does not, so a mis-aimed click while
               reading the drawing is not punished. -->
          <div
            x-show="zoomOpen"
            x-cloak
            @keydown.escape.window="zoomOpen = false"
            @click="zoomOpen = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-navy-950/85 p-4 cursor-zoom-out no-print"
            role="dialog"
            aria-modal="true"
          >
            <img :src="designImageUrl" @click.stop class="max-w-full max-h-full object-contain bg-white rounded shadow-softLg cursor-default">
            <a
              :href="designImageUrl"
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
        <div x-show="designImageUploading" x-cloak class="text-xs text-steel-500 mt-1"><?= htmlspecialchars(t('uploading')) ?></div>
        <div x-show="designImageError" x-cloak class="text-xs text-red-600 mt-1" x-text="designImageError"></div>
      </div>

      <!-- PDF is still required before submitting for approval; kept compact here
           (a link that opens in a new tab) instead of an always-embedded iframe. -->
      <div class="flex flex-col justify-center">
        <div class="text-xs font-medium text-steel-500 mb-2"><?= htmlspecialchars(t('attached_pdf_title')) ?></div>
        <template x-if="!readOnly">
          <div>
            <div x-show="!pdfPath" class="flex items-center gap-2">
              <label class="inline-block px-4 py-2 border border-steel-300 rounded text-sm text-accent-600 cursor-pointer hover:bg-accent-50">
                <?= htmlspecialchars(t('choose_pdf_file')) ?>
                <input type="file" accept="application/pdf" class="hidden" @change="uploadPdf($event)">
              </label>
              <span x-show="pdfUploading" x-cloak class="text-xs text-steel-500"><?= htmlspecialchars(t('uploading')) ?></span>
            </div>
            <div x-show="pdfPath" x-cloak class="flex items-center gap-3">
              <a :href="pdfUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 border border-steel-300 rounded text-sm text-steel-700 hover:bg-steel-50 hover:border-steel-400"><?= pdfIconSvg() ?><span><?= htmlspecialchars(t('open_attached_file')) ?></span></a>
              <button type="button" @click="removePdf()" class="text-xs text-red-600 hover:underline"><?= htmlspecialchars(t('remove_file')) ?></button>
            </div>
            <div x-show="pdfError" x-cloak class="text-xs text-red-600 mt-2" x-text="pdfError"></div>
          </div>
        </template>
        <template x-if="readOnly">
          <div>
            <a x-show="pdfUrl" :href="pdfUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 border border-steel-300 rounded text-sm text-steel-700 hover:bg-steel-50 hover:border-steel-400"><?= pdfIconSvg() ?><span><?= htmlspecialchars(t('open_attached_file')) ?></span></a>
            <span x-show="!pdfUrl" class="text-sm text-steel-400"><?= htmlspecialchars(t('no_pdf_attached')) ?></span>
          </div>
        </template>
      </div>
    </div>
  </div>

  <!-- Additional items: topics not in the master checklist. Which master
       items apply was already decided when the checklist was created, so
       editing here only covers ad-hoc extras, not re-picking the master list. -->
  <template x-if="!readOnly">
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-4 mb-4">
      <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-semibold text-navy-900"><?= htmlspecialchars(t('additional_items_title')) ?></div>
        <button type="button" @click="addCustomItem()" class="text-accent-600 hover:underline text-sm"><?= htmlspecialchars(t('add_custom_item')) ?></button>
      </div>
      <div class="space-y-3">
        <template x-for="key in customItemKeys" :key="key">
          <div class="flex items-start gap-2">
            <div class="flex-1 space-y-1">
              <input type="text" x-model="results[key].custom_topic" placeholder="<?= htmlspecialchars(t('custom_item_topic_placeholder')) ?>" class="w-full border border-steel-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
              <input type="text" x-model="results[key].custom_note" placeholder="<?= htmlspecialchars(t('custom_item_note_placeholder')) ?>" class="w-full border border-steel-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
            </div>
            <button type="button" @click="removeCustomItem(key)" class="text-xs text-red-600 hover:underline mt-2"><?= htmlspecialchars(t('remove')) ?></button>
          </div>
        </template>
      </div>
    </div>
  </template>

  <!-- Results: same sheet layout as engineer/checklist_new.php —
       No / หัวข้อ / รายละเอียด / Pass / Fail / หมายเหตุ, one row per item.
       Pass and Fail are cells rather than a pill plus an expander, so editing
       happens inline and the read-only view reads identically to the form the
       engineer filled in. -->
  <div class="space-y-3 mb-4">
    <div class="text-sm font-semibold text-navy-900"><?= htmlspecialchars(t('step3_title')) ?></div>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden" x-show="selectedItemsDetailed.length">
      <div class="overflow-x-auto">
        <div class="min-w-[56rem]">
          <div class="grid grid-cols-[3rem_minmax(9rem,1.1fr)_minmax(10rem,1.4fr)_5rem_5rem_minmax(12rem,1.5fr)] gap-px bg-steel-200 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
            <div class="bg-steel-50 px-3 py-2"><?= htmlspecialchars(t('table_col_no')) ?></div>
            <div class="bg-steel-50 px-3 py-2"><?= htmlspecialchars(t('table_col_topic')) ?></div>
            <div class="bg-steel-50 px-3 py-2"><?= htmlspecialchars(t('table_col_detail')) ?></div>
            <div class="bg-steel-50 px-3 py-2 text-center"><?= htmlspecialchars(t('result_pass')) ?></div>
            <div class="bg-steel-50 px-3 py-2 text-center"><?= htmlspecialchars(t('result_fail')) ?></div>
            <div class="bg-steel-50 px-3 py-2"><?= htmlspecialchars(t('table_col_notes')) ?></div>
          </div>

          <template x-for="(item, idx) in selectedItemsDetailed" :key="item.key">
            <div class="grid grid-cols-[3rem_minmax(9rem,1.1fr)_minmax(10rem,1.4fr)_5rem_5rem_minmax(12rem,1.5fr)] gap-px bg-steel-100 border-b border-steel-100 last:border-b-0">
              <div class="bg-white px-3 py-2 text-sm font-tabular text-steel-500" x-text="idx + 1"></div>

              <div class="bg-white px-3 py-2">
                <div class="flex items-start gap-2 flex-wrap">
                  <span class="text-sm font-medium text-navy-900 break-words" x-text="item.topic"></span>
                  <span x-show="!readOnly && rowNeedsAttention(item.key)" x-cloak class="w-2 h-2 rounded-full bg-amber-500 shrink-0 mt-1.5"></span>
                </div>
                <span x-show="item.isCustom" x-cloak class="status-pill bg-status-infoBg text-status-infoText mt-1"><?= htmlspecialchars(t('custom_item_badge')) ?></span>
              </div>

              <div class="bg-white px-3 py-2 text-xs text-steel-500 break-words" x-text="item.note"></div>

              <!-- Same cells in both modes; read-only just disables them, so an
                   approved checklist reads exactly like the filled-in form. -->
              <button
                type="button"
                :disabled="readOnly"
                @click="setResult(item.key, 'pass')"
                :class="resultOf(item.key).result === 'pass' ? 'bg-green-600 text-white font-semibold' : (readOnly ? 'bg-green-50/50 text-green-700/40' : 'bg-green-50 text-green-700 hover:bg-green-100')"
                class="px-3 py-2 text-sm text-center transition-colors disabled:cursor-default"
              ><?= htmlspecialchars(t('result_pass')) ?></button>

              <button
                type="button"
                :disabled="readOnly"
                @click="setResult(item.key, 'fail')"
                :class="resultOf(item.key).result === 'fail' ? 'bg-red-600 text-white font-semibold' : (readOnly ? 'bg-red-50/50 text-red-700/40' : 'bg-red-50 text-red-700 hover:bg-red-100')"
                class="px-3 py-2 text-sm text-center transition-colors disabled:cursor-default"
              ><?= htmlspecialchars(t('result_fail')) ?></button>

              <div class="bg-white px-2 py-1.5">
                <template x-if="!readOnly">
                  <div>
                    <!-- Turning a saved Fail into a Pass: the old reason stays
                         visible here for reference, but the box below is emptied
                         so a fresh explanation has to be typed rather than the
                         old text being re-saved unchanged. -->
                    <div x-show="showSavedNote(item.key)" x-cloak class="mb-1 rounded bg-steel-50 border border-steel-200 px-2 py-1">
                      <div class="text-[10px] uppercase tracking-wide text-steel-400"><?= htmlspecialchars(t('previous_note_label')) ?></div>
                      <div class="text-xs text-steel-600 break-words" x-text="savedNoteFor(item.key)"></div>
                    </div>
                    <textarea
                      x-model="resultOf(item.key).comment"
                      rows="1"
                      placeholder="<?= htmlspecialchars(t('comment_placeholder')) ?>"
                      :class="(failCommentMissing(item.key) || failToPassNeedsNewComment(item.key)) ? 'border-red-400' : 'border-steel-300'"
                      class="w-full border rounded px-2 py-1.5 text-sm resize-y focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500"
                    ></textarea>
                    <div x-show="changedFromPassToFail(item.key)" x-cloak class="text-[11px] text-amber-600 mt-0.5"><?= htmlspecialchars(t('changed_from_pass_hint')) ?></div>
                    <div x-show="failCommentMissing(item.key)" x-cloak class="text-[11px] text-red-600 mt-0.5"><?= htmlspecialchars(t('fail_needs_comment')) ?></div>
                    <div x-show="failToPassNeedsNewComment(item.key)" x-cloak class="text-[11px] text-red-600 mt-0.5"><?= htmlspecialchars(t('fail_to_pass_comment_required')) ?></div>
                  </div>
                </template>

                <!-- Read-only: a flipped item shows both comments, labelled by
                     the result each belongs to. -->
                <template x-if="readOnly">
                  <div class="text-sm text-steel-600 min-w-0 space-y-0.5 px-1 py-0.5">
                    <div x-show="hasPreviousNote(item.key)" x-cloak class="flex items-start gap-1.5">
                      <span class="text-[11px] font-semibold shrink-0 leading-5" :class="resultLabelClass(resultOf(item.key).prev_result)" x-text="resultLabel(resultOf(item.key).prev_result) + ':'"></span>
                      <span class="text-steel-500 break-words" x-text="previousNote(item.key)"></span>
                    </div>
                    <div class="flex items-start gap-1.5">
                      <span x-show="hasPreviousNote(item.key)" x-cloak class="text-[11px] font-semibold shrink-0 leading-5" :class="resultLabelClass(resultOf(item.key).result)" x-text="resultLabel(resultOf(item.key).result) + ':'"></span>
                      <span class="break-words" x-text="resultOf(item.key).comment || '—'"></span>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
    <div x-show="selectedItemsDetailed.length === 0" class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500"><?= htmlspecialchars(t('no_items_selected_yet')) ?></div>
  </div>

  <template x-if="!readOnly">
    <div class="flex justify-end gap-2 mb-8 max-w-2xl">
      <button type="button" @click="save('pending')" :disabled="savingDraft || submitting" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white rounded text-sm disabled:opacity-50">
        <span x-show="!submitting"><?= htmlspecialchars($checklist['status'] === 'rejected' ? t('resubmit_for_approval') : t('submit_for_approval')) ?></span>
        <span x-show="submitting" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
      </button>
    </div>
  </template>

  <!-- Attach-PDF modal: pops up when "Submit for Approval" is clicked and no
       design PDF has been attached yet. -->
  <div x-show="pdfModalOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closePdfModal()" class="bg-white rounded-xl w-full max-w-md border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900"><?= htmlspecialchars(t('attach_pdf_modal_title')) ?></h2>
        <button type="button" @click="closePdfModal()" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>
      <div class="px-5 py-4 space-y-4">
        <p class="text-sm text-steel-600"><?= htmlspecialchars(t('attach_pdf_modal_body')) ?></p>
        <div x-show="!pdfPath">
          <label class="inline-block px-4 py-2 border border-steel-300 rounded text-sm text-accent-600 cursor-pointer hover:bg-accent-50">
            <?= htmlspecialchars(t('choose_pdf_file')) ?>
            <input type="file" accept="application/pdf" class="hidden" @change="uploadPdf($event)">
          </label>
          <span x-show="pdfUploading" x-cloak class="text-xs text-steel-500 ml-2"><?= htmlspecialchars(t('uploading')) ?></span>
        </div>
        <div x-show="pdfPath" x-cloak class="flex items-center gap-3">
          <a :href="pdfUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 border border-steel-300 rounded text-sm text-steel-700 hover:bg-steel-50 hover:border-steel-400"><?= pdfIconSvg() ?><span><?= htmlspecialchars(t('open_attached_file')) ?></span></a>
          <button type="button" @click="removePdf()" class="text-xs text-red-600 hover:underline"><?= htmlspecialchars(t('remove_file')) ?></button>
        </div>
        <div x-show="pdfError" x-cloak class="text-xs text-red-600" x-text="pdfError"></div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="closePdfModal()" class="px-4 py-2 text-sm border border-steel-300 rounded text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('cancel')) ?></button>
          <button type="button" @click="continueSubmitFromModal()" :disabled="!pdfPath || submitting" class="px-4 py-2 text-sm bg-accent-500 hover:bg-accent-600 text-white rounded disabled:opacity-50">
            <span x-show="!submitting"><?= htmlspecialchars(t('continue_submit')) ?></span>
            <span x-show="submitting" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
          </button>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- History -->
<div class="max-w-2xl">
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
          <div class="text-xs text-steel-500 mt-0.5">
            <?= htmlspecialchars($h['action_by_name']) ?>
            &middot; <?= htmlspecialchars(statusLabelText($h['old_status'] ?? null)) ?> &rarr; <?= htmlspecialchars(statusLabelText($h['new_status'] ?? null)) ?>
            <?= historyNoteHtml($h['note'] ?? null) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php
$extraScripts = '<script src="' . APP_BASE_URL . '/assets/js/checklist_form.js"></script>';
include __DIR__ . '/../includes/footer.php';
