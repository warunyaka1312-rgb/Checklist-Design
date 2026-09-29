<?php
// Pin the response encoding before anything is echoed. The page is UTF-8
// throughout, but a server whose default_charset is empty (or set to a Thai
// code page) leaves the browser to guess, and on a Thai Windows client it
// guesses TIS-620/cp874 — which renders every UTF-8 Thai string as mojibake.
// Sending the charset ourselves takes that guess away.
header('Content-Type: text/html; charset=UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';

requireRole(['engineer', 'admin']);

$me = currentUser();
$pdo = getDbConnection();

$models = $pdo->query('SELECT id, name FROM die_models WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$customers = $pdo->query('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$materials = $pdo->query('SELECT id, name FROM materials WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$tempers = $pdo->query('SELECT id, name FROM tempers WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
// Only users on the curated approver list (Admin > Master Data > ผู้อนุมัติ).
$managers = $pdo->query(
    "SELECT u.id, u.full_name
       FROM approvers a
       JOIN users u ON u.id = a.user_id
      WHERE a.is_active = 1 AND u.role = 'manager' AND u.is_active = 1
      ORDER BY a.sort_order ASC, u.full_name ASC"
)->fetchAll();
$items = fetchActiveChecklistItems($pdo);

$modelsForJs = array_map(static fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $models);
$customersForJs = array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $customers);
$materialsForJs = array_map(static fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $materials);
$tempersForJs = array_map(static fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $tempers);
$managersForJs = array_map(static fn($m) => ['id' => (int)$m['id'], 'full_name' => $m['full_name']], $managers);

$i18nMap = [
    'invalid_input' => t('invalid_input'),
    'name_taken' => t('name_taken'),
    'server_error' => t('server_error'),
    'invalid_csrf' => t('invalid_csrf'),
    'forbidden' => t('forbidden'),
    'fail_needs_comment' => t('fail_needs_comment'),
    'incomplete_results' => t('incomplete_results'),
    'pdf_required' => t('pdf_required'),
    'create_checklist' => t('create_checklist'),
    'locked' => t('locked'),
    'not_found' => t('not_found'),
    'file_too_large' => t('file_too_large'),
    'invalid_file_type' => t('invalid_file_type'),
    'upload_failed' => t('upload_failed'),
    'fetch_from_tech_program_not_implemented_yet' => t('fetch_from_tech_program_not_implemented_yet'),
    'tech_fetch_filled' => t('tech_fetch_filled'),
    'tech_fetch_nothing' => t('tech_fetch_nothing'),
    'label_model' => t('label_model'),
    'label_material' => t('label_material'),
    'label_temper' => t('label_temper'),
    'die_type_solid_hollow' => t('die_type_solid_hollow'),
    'tech_drawing_not_found' => t('tech_drawing_not_found'),
    'techdrawing_api_failed' => t('techdrawing_api_failed'),
    'drawing_tech_required' => t('drawing_tech_required'),
    'manager_not_assignable' => t('manager_not_assignable'),
];

$pageTitle = t('nav_create_checklist');
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';

$config = [
    'mode' => 'new',
    'checklistId' => null,
    'csrfToken' => csrfToken(),
    'models' => $modelsForJs,
    // Engineers may type a Model that is not in master data yet; the save
    // API adds it to die_models (see resolveDieModelId there).
    'allowNewModel' => true,
    'allowNewCustomer' => true,
    // Typing in the Customer box searches SAP by CardName through this
    // endpoint, not just the customers already in our table.
    'customerSearchUrl' => APP_BASE_URL . '/api/customer_search_api.php',
    // One SAP lookup behind the Tech button fills Model/Material/Temper/die type.
    'techFetchUrl' => APP_BASE_URL . '/api/techdrawing_api.php',
    'customers' => $customersForJs,
    'materials' => $materialsForJs,
    'tempers' => $tempersForJs,
    'managers' => $managersForJs,
    'items' => $items,
    'readOnly' => false,
    'i18n' => $i18nMap,
    'initial' => [
        'die_no' => '',
        'tech' => '',
        'model_id' => null,
        'customer_id' => null,
        'die_type' => 'solid',
        'material_id' => null,
        'temper_id' => null,
        'assigned_manager_id' => null,
        'status' => 'draft',
        'selected_item_ids' => [],
        'results' => new stdClass(),
        'design_pdf_path' => null,
        'design_pdf_url' => null,
    ],
];
?>

<div x-data="checklistForm(<?= jsonForAttr($config) ?>)">

  <div class="flex items-center justify-between mb-4">
    <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_create_checklist')) ?></h1>
    <button type="button" @click="save('draft')" :disabled="savingDraft || submitting" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50 disabled:opacity-50">
      <span x-show="!savingDraft"><?= htmlspecialchars(t('save_draft')) ?></span>
      <span x-show="savingDraft" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
    </button>
  </div>

  <!-- Step indicator (3 steps: creation now ends after filling results — the
       PDF attach + submit-for-approval step happens afterwards, on the
       checklist's own page). -->
  <div class="flex items-center gap-2 mb-6 text-xs text-steel-500">
    <template x-for="n in 3" :key="n">
      <div class="flex items-center gap-2">
        <div
          :class="step === n ? 'bg-accent-500 text-white' : (step > n ? 'bg-green-600 text-white' : 'bg-steel-200 text-steel-500')"
          class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold shrink-0"
          x-text="n"
        ></div>
        <span x-show="n < 3" class="w-8 h-px bg-steel-300"></span>
      </div>
    </template>
    <div class="ml-4 hidden sm:flex gap-6">
      <span :class="step === 1 ? 'text-navy-900 font-medium' : ''"><?= htmlspecialchars(t('step1_title')) ?></span>
      <span :class="step === 2 ? 'text-navy-900 font-medium' : ''"><?= htmlspecialchars(t('step2_title')) ?></span>
      <span :class="step === 3 ? 'text-navy-900 font-medium' : ''"><?= htmlspecialchars(t('step3_title')) ?></span>
    </div>
  </div>

  <div x-show="formError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 mb-4 max-w-2xl" x-text="formError"></div>

  <!-- Step 1: basic info -->
  <div x-show="step === 1" class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 space-y-4 max-w-2xl">
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('label_tech')) ?></label>
      <div class="flex gap-2">
        <input type="text" x-model="tech" placeholder="<?= htmlspecialchars(t('tech_example_placeholder')) ?>" class="flex-1 border border-steel-300 rounded px-3 py-2 text-sm font-tabular focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        <!-- Icon-only: the standard "pull data down from a server" arrow, with
             the old wording kept as the tooltip and accessible name so nothing
             is lost. Swaps to a spinning sync icon while the lookup runs. -->
        <button
          type="button"
          @click="fetchFromTechProgramClicked()"
          :disabled="techFetching"
          class="shrink-0 w-10 flex items-center justify-center border border-steel-300 rounded text-accent-600 hover:bg-accent-50 disabled:opacity-50"
          :title="techFetching ? <?= jsonForAttr(t('searching')) ?> : <?= jsonForAttr(t('fetch_from_tech_program')) ?>"
          :aria-label="techFetching ? <?= jsonForAttr(t('searching')) ?> : <?= jsonForAttr(t('fetch_from_tech_program')) ?>"
          :aria-busy="techFetching"
        >
          <svg x-show="!techFetching" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
          </svg>
          <svg x-show="techFetching" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3a9 9 0 1 0 9 9" />
          </svg>
        </button>
      </div>
      <!-- One line for both outcomes: green when the lookup filled the fields
           in, red when the Tech is unknown or SAP is unreachable. -->
      <div x-show="techFetchMessage" x-cloak class="text-xs mt-1" :class="techFetchOk ? 'text-green-700' : 'text-red-600'" x-text="techFetchMessage"></div>
    </div>

    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('die_no_label')) ?></label>
      <input type="text" x-model="dieNo" placeholder="<?= htmlspecialchars(t('die_no_example_placeholder')) ?>" class="w-full border border-steel-300 rounded px-3 py-2 text-sm font-tabular focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
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
        class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500"
      >
      <div x-show="modelDropdownOpen" x-cloak class="absolute z-10 mt-1 w-full bg-white border border-steel-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
        <template x-for="m in filteredModels" :key="m.id">
          <div @mousedown.prevent="selectModel(m)" class="px-3 py-2 text-sm cursor-pointer hover:bg-accent-50" x-text="m.name"></div>
        </template>
        <!-- Unlike Customer, a Model that is not in master data yet can be used
             straight away: this row keeps what was typed, and the save API adds
             it to die_models. -->
        <div
          x-show="modelIsNew"
          x-cloak
          @mousedown.prevent="useTypedModel()"
          class="px-3 py-2 text-sm cursor-pointer hover:bg-accent-50 text-accent-600 border-t border-steel-100"
        >
          <span><?= htmlspecialchars(t('add_model_option')) ?>:</span>
          <span class="font-medium" x-text="modelQuery.trim()"></span>
        </div>
        <div x-show="filteredModels.length === 0 && !modelIsNew" class="px-3 py-2 text-xs text-steel-500"><?= htmlspecialchars(t('no_matching_options')) ?></div>
      </div>
      <!-- Not an error here — just a heads-up that saving will extend the
           master data with this name. -->
      <div x-show="modelIsNew" x-cloak class="text-xs text-accent-600 mt-1"><?= htmlspecialchars(t('no_matching_models_will_add')) ?></div>
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
        class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500"
      >
      <div x-show="customerDropdownOpen" x-cloak class="absolute z-10 mt-1 w-full bg-white border border-steel-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
        <!-- Rows come from customer_search_api.php, which merges our own
             customers with SAP matches on CardName. A SAP-only row has id null
             and is marked, so it is clear the pick will create the local row. -->
        <template x-for="(c, i) in filteredCustomers" :key="c.id || ('sap-' + (c.card_code || i))">
          <div @mousedown.prevent="selectCustomer(c)" class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-accent-50">
            <span class="min-w-0 break-words" x-text="c.name"></span>
            <span x-show="c.source === 'api'" class="ml-auto shrink-0 text-[10px] font-medium px-1.5 py-0.5 rounded bg-status-infoBg text-status-infoText" x-text="c.card_code || '<?= htmlspecialchars(t('customer_from_sap')) ?>'"></span>
          </div>
        </template>
        <div x-show="customerSearching" x-cloak class="px-3 py-2 text-xs text-steel-500"><?= htmlspecialchars(t('searching')) ?></div>
        <!-- A customer in neither list can still be used: this row keeps what
             was typed and the save API adds it to customers. -->
        <div
          x-show="customerIsNew"
          x-cloak
          @mousedown.prevent="useTypedCustomer()"
          class="px-3 py-2 text-sm cursor-pointer hover:bg-accent-50 text-accent-600 border-t border-steel-100"
        >
          <span><?= htmlspecialchars(t('add_customer_option')) ?>:</span>
          <span class="font-medium" x-text="customerQuery.trim()"></span>
        </div>
        <div x-show="filteredCustomers.length === 0 && !customerIsNew && !customerSearching" class="px-3 py-2 text-xs text-steel-500"><?= htmlspecialchars(t('no_matching_options')) ?></div>
      </div>
      <div x-show="customerIsNew" x-cloak class="text-xs text-accent-600 mt-1"><?= htmlspecialchars(t('no_matching_customers_will_add')) ?></div>
      <div x-show="customerSearchFailed" x-cloak class="text-xs text-warn-600 mt-1"><?= htmlspecialchars(t('customer_api_unavailable')) ?></div>
      <div x-show="!customerConfirmed" x-cloak class="text-xs text-red-600 mt-1"><?= htmlspecialchars(t('no_matching_options')) ?></div>
    </div>

    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('die_type_solid_hollow')) ?></label>
      <div class="flex gap-6">
        <label class="flex items-center gap-2 text-sm"><input type="radio" value="solid" x-model="dieType" @change="onDieTypeChange()"> <?= htmlspecialchars(t('solid_die')) ?></label>
        <label class="flex items-center gap-2 text-sm"><input type="radio" value="hollow" x-model="dieType" @change="onDieTypeChange()"> <?= htmlspecialchars(t('hollow_die')) ?></label>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('label_material')) ?></label>
        <!-- Selected by NAME, not id: a value the SAP lookup filled in may not
             be in master data yet, so it has no id until the checklist is saved. -->
        <select x-model="materialName" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
          <template x-for="m in materials" :key="m.id ?? m.name"><option :value="m.name" x-text="m.name"></option></template>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('label_temper')) ?></label>
        <select x-model="temperName" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
          <template x-for="t in tempers" :key="t.id ?? t.name"><option :value="t.name" x-text="t.name"></option></template>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('label_manager')) ?></label>
      <select x-model.number="managerId" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        <option value=""><?= htmlspecialchars(t('please_select')) ?></option>
        <template x-for="m in managers" :key="m.id"><option :value="m.id" x-text="m.full_name"></option></template>
      </select>
    </div>
  </div>

  <!-- Step 2: select items -->
  <div x-show="step === 2" class="space-y-4">
    <div class="flex justify-end gap-2">
      <button type="button" @click="selectAllItems()" class="px-3 py-1.5 border border-steel-300 rounded text-xs text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('select_all')) ?></button>
      <button type="button" @click="deselectAllItems()" class="px-3 py-1.5 border border-steel-300 rounded text-xs text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('deselect_all')) ?></button>
    </div>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft">
      <div class="divide-y divide-steel-100">
        <template x-for="item in currentItems" :key="item.id">
          <label class="flex items-start gap-3 px-4 py-3 cursor-pointer hover:bg-steel-50">
            <input type="checkbox" :checked="isSelected(item.id)" @change="toggleItem(item.id)" class="mt-1">
            <div>
              <div class="text-sm font-medium text-navy-900" x-text="item.topic"></div>
              <div class="text-xs text-steel-500" x-text="item.note"></div>
            </div>
          </label>
        </template>
      </div>
    </div>

    <!-- Custom items: topics not in the master list, kept only on this checklist -->
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-4">
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
  </div>

  <!-- Step 3: fill results.
       One row per item, laid out like the paper form: No / หัวข้อ / รายละเอียด /
       Pass / Fail / หมายเหตุ. Pass and Fail are separate clickable cells tinted
       green and pink, so the sheet reads the same way the Excel one does; the
       comment is a single column because an item has one result and one note. -->
  <div x-show="step === 3" class="space-y-4">
    <div x-show="selectedItemsDetailed.length" class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
      <!-- Horizontal scroll rather than a reflow: the columns only make sense
           read across, so on a narrow screen the table scrolls instead. -->
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
                <div class="text-sm font-medium text-navy-900 break-words" x-text="item.topic"></div>
                <span x-show="item.isCustom" x-cloak class="status-pill bg-status-infoBg text-status-infoText mt-1"><?= htmlspecialchars(t('custom_item_badge')) ?></span>
              </div>

              <div class="bg-white px-3 py-2 text-xs text-steel-500 break-words" x-text="item.note"></div>

              <!-- Whole cell is the target, so it behaves like ticking the box
                   on the paper form rather than hunting for a small button. -->
              <button
                type="button"
                @click="setResult(item.key, 'pass')"
                :class="resultOf(item.key).result === 'pass' ? 'bg-green-600 text-white font-semibold' : 'bg-green-50 text-green-700 hover:bg-green-100'"
                class="px-3 py-2 text-sm text-center transition-colors"
              ><?= htmlspecialchars(t('result_pass')) ?></button>

              <button
                type="button"
                @click="setResult(item.key, 'fail')"
                :class="resultOf(item.key).result === 'fail' ? 'bg-red-600 text-white font-semibold' : 'bg-red-50 text-red-700 hover:bg-red-100'"
                class="px-3 py-2 text-sm text-center transition-colors"
              ><?= htmlspecialchars(t('result_fail')) ?></button>

              <div class="bg-white px-2 py-1.5">
                <textarea
                  x-model="resultOf(item.key).comment"
                  rows="1"
                  placeholder="<?= htmlspecialchars(t('comment_placeholder')) ?>"
                  :class="failCommentMissing(item.key) ? 'border-red-400' : 'border-steel-300'"
                  class="w-full border rounded px-2 py-1.5 text-sm resize-y focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500"
                ></textarea>
                <div x-show="failCommentMissing(item.key)" x-cloak class="text-[11px] text-red-600 mt-0.5"><?= htmlspecialchars(t('fail_needs_comment')) ?></div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
    <div x-show="selectedItemsDetailed.length === 0" class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500"><?= htmlspecialchars(t('no_items_selected_yet')) ?></div>
  </div>

  <!-- Navigation -->
  <div class="flex items-center justify-between mt-6 max-w-2xl">
    <button type="button" @click="goBack()" x-show="step > 1" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('back')) ?></button>
    <div class="flex-1"></div>
    <button type="button" @click="goNext()" x-show="step < 3" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white rounded text-sm"><?= htmlspecialchars(t('next')) ?></button>
    <!-- Creation now ends here: this creates the checklist record right away
         (as a draft). PDF attach + submit-for-approval happens afterwards on
         the checklist's own page (engineer/checklist_view.php). -->
    <button type="button" @click="createChecklist()" x-show="step === 3" :disabled="savingDraft || submitting" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white rounded text-sm disabled:opacity-50">
      <span x-show="!savingDraft"><?= htmlspecialchars(t('create_checklist')) ?></span>
      <span x-show="savingDraft" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
    </button>
  </div>

</div>

<?php
$extraScripts = '<script src="' . APP_BASE_URL . '/assets/js/checklist_form.js"></script>';
include __DIR__ . '/../includes/footer.php';
