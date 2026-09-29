<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';

requireRole('admin');

$pdo = getDbConnection();
$customers = $pdo->query('SELECT id, card_code, name, tax_id, is_active, created_at FROM customers ORDER BY name ASC')->fetchAll();
$lastSyncedAt = $pdo->query('SELECT MAX(synced_at) FROM customers')->fetchColumn();

$i18nMap = [
    'invalid_input' => t('invalid_input'),
    'name_taken' => t('name_taken'),
    'server_error' => t('server_error'),
    'invalid_csrf' => t('invalid_csrf'),
    'forbidden' => t('forbidden'),
    'confirm_delete' => t('confirm_delete'),
    'confirm_deactivate_instead' => t('confirm_deactivate_instead'),
    'sync_confirm' => t('sync_confirm'),
    'sync_done' => t('sync_done'),
    'sync_inserted' => t('sync_inserted'),
    'sync_updated' => t('sync_updated'),
    'sync_linked' => t('sync_linked'),
    'sync_skipped' => t('sync_skipped'),
    'sync_failed' => t('sync_failed'),
];

$pageTitle = t('nav_manage_customers');
$extraHead = '<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div x-data="namedEntityManager(
  'customers',
  document.querySelector('meta[name=csrf-token]').content,
  <?= jsonForAttr($i18nMap) ?>
)">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_manage_customers')) ?></h1>
      <p class="text-sm text-steel-500 mt-1"><?= htmlspecialchars(t('customers_page_subtitle')) ?></p>
      <p class="text-xs text-steel-400 mt-0.5"><?= htmlspecialchars(t('last_synced')) ?>: <?= $lastSyncedAt ? htmlspecialchars(date('d/m/Y H:i', strtotime($lastSyncedAt))) : htmlspecialchars(t('never_synced')) ?></p>
    </div>
    <div class="flex items-center gap-2">
      <button type="button" @click="openSync()" :disabled="syncing" class="px-4 py-2 border border-accent-500 text-accent-600 hover:bg-accent-50 text-sm font-medium rounded disabled:opacity-50">
        <span x-show="!syncing"><?= htmlspecialchars(t('sync_from_api')) ?></span>
        <span x-show="syncing" x-cloak><?= htmlspecialchars(t('syncing_from_api')) ?></span>
      </button>
      <button type="button" @click="openAdd()" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded">
        <?= htmlspecialchars(t('add_customer')) ?>
      </button>
    </div>
  </div>

  <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
    <table id="dataTable" class="table-compact w-full text-sm">
      <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
        <tr>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('customer_code')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('name')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('tax_id')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('status')) ?></th>
          <th class="text-right px-4 py-3"><?= htmlspecialchars(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-steel-100">
        <?php foreach ($customers as $c): ?>
        <tr>
          <td class="px-4 py-3 whitespace-nowrap text-steel-500"><?= htmlspecialchars((string)($c['card_code'] ?? '-')) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($c['name']) ?></td>
          <td class="px-4 py-3 whitespace-nowrap text-steel-500"><?= htmlspecialchars((string)($c['tax_id'] ?? '-')) ?></td>
          <td class="px-4 py-3">
            <?php if ((int)$c['is_active'] === 1): ?>
              <span class="status-pill bg-status-successBg text-status-successText"><?= htmlspecialchars(t('active')) ?></span>
            <?php else: ?>
              <span class="status-pill bg-status-dangerBg text-status-dangerText"><?= htmlspecialchars(t('inactive')) ?></span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <?php
                $isActive = (int)$c['is_active'] === 1;
                $toggleLabel = htmlspecialchars($isActive ? t('deactivate') : t('activate'));
            ?>
            <div class="flex items-center justify-end gap-1 -my-1.5">
              <button type="button" @click="openEdit(<?= (int)$c['id'] ?>, <?= jsonForAttr($c['name']) ?>)" class="p-1.5 rounded-lg text-accent-600 hover:bg-accent-50" title="<?= htmlspecialchars(t('edit')) ?>" aria-label="<?= htmlspecialchars(t('edit')) ?>">
                <?= actionIconSvg('edit') ?>
              </button>
              <button type="button" @click="toggleActive(<?= (int)$c['id'] ?>, <?= (int)$c['is_active'] ?>)" :disabled="busy" class="p-1.5 rounded-lg <?= $isActive ? 'text-red-600 hover:bg-red-50' : 'text-green-600 hover:bg-green-50' ?> disabled:opacity-50" title="<?= $toggleLabel ?>" aria-label="<?= $toggleLabel ?>">
                <?= actionIconSvg($isActive ? 'deactivate' : 'activate') ?>
              </button>
              <button type="button" @click="remove(<?= (int)$c['id'] ?>)" :disabled="busy" class="p-1.5 rounded-lg text-steel-500 hover:text-red-600 hover:bg-red-50 disabled:opacity-50" title="<?= htmlspecialchars(t('delete')) ?>" aria-label="<?= htmlspecialchars(t('delete')) ?>">
                <?= actionIconSvg('delete') ?>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div x-cloak x-show="modalOpen" class="fixed inset-0 z-30 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeModal()" class="bg-white rounded-xl w-full max-w-sm border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900" x-text="mode === 'add' ? <?= jsonForAttr(t('add_customer')) ?> : <?= jsonForAttr(t('edit_customer')) ?>"></h2>
        <button type="button" @click="closeModal()" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>
      <form @submit.prevent="submitForm()" class="px-5 py-4 space-y-4">
        <div x-show="formError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2" x-text="formError"></div>
        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('name')) ?></label>
          <input type="text" x-model="form.name" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="closeModal()" class="px-4 py-2 text-sm border border-steel-300 rounded text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('cancel')) ?></button>
          <button type="submit" :disabled="saving" class="px-4 py-2 text-sm bg-accent-500 hover:bg-accent-600 text-white rounded disabled:opacity-50">
            <span x-show="!saving"><?= htmlspecialchars(t('save')) ?></span>
            <span x-show="saving" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <div x-cloak x-show="syncOpen" @keydown.escape.window="syncOpen && closeSync()" class="fixed inset-0 z-30 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeSync()" class="bg-white rounded-xl w-full max-w-md border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900"><?= htmlspecialchars(t('sync_modal_title')) ?></h2>
        <button type="button" @click="closeSync()" x-show="syncPhase !== 'syncing'" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>

      <div class="px-5 py-4">
        <p x-show="syncPhase === 'confirm'" class="text-sm text-steel-600"><?= htmlspecialchars(t('sync_confirm')) ?></p>

        <div x-show="syncPhase === 'syncing'" class="flex items-center gap-3 text-sm text-steel-600">
          <svg class="animate-spin h-5 w-5 text-accent-500" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
          </svg>
          <span><?= htmlspecialchars(t('syncing_from_api')) ?></span>
        </div>

        <template x-if="syncPhase === 'done' && syncResult">
          <div>
            <p class="text-sm font-medium text-status-successText mb-3"><?= htmlspecialchars(t('sync_done')) ?></p>
            <dl class="grid grid-cols-2 gap-2 text-sm">
              <div class="border border-steel-200 rounded-lg px-3 py-2">
                <dt class="text-xs text-steel-500"><?= htmlspecialchars(t('sync_inserted')) ?></dt>
                <dd class="text-lg font-semibold text-navy-900" x-text="syncResult.inserted"></dd>
              </div>
              <div class="border border-steel-200 rounded-lg px-3 py-2">
                <dt class="text-xs text-steel-500"><?= htmlspecialchars(t('sync_updated')) ?></dt>
                <dd class="text-lg font-semibold text-navy-900" x-text="syncResult.updated"></dd>
              </div>
              <div class="border border-steel-200 rounded-lg px-3 py-2">
                <dt class="text-xs text-steel-500"><?= htmlspecialchars(t('sync_linked')) ?></dt>
                <dd class="text-lg font-semibold text-navy-900" x-text="syncResult.linked"></dd>
              </div>
              <div class="border border-steel-200 rounded-lg px-3 py-2">
                <dt class="text-xs text-steel-500"><?= htmlspecialchars(t('sync_skipped')) ?></dt>
                <dd class="text-lg font-semibold text-navy-900" x-text="syncResult.skipped"></dd>
              </div>
            </dl>
          </div>
        </template>

        <div x-show="syncPhase === 'error'" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 whitespace-pre-line" x-text="syncError"></div>
      </div>

      <div class="px-5 py-4 border-t border-steel-200 flex justify-end gap-2">
        <template x-if="syncPhase === 'confirm'">
          <div class="flex gap-2">
            <button type="button" @click="closeSync()" class="px-4 py-2 text-sm border border-steel-300 rounded text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('cancel')) ?></button>
            <button type="button" @click="startSync()" class="px-4 py-2 text-sm bg-accent-500 hover:bg-accent-600 text-white rounded"><?= htmlspecialchars(t('sync_start')) ?></button>
          </div>
        </template>
        <button type="button" x-show="syncPhase === 'syncing'" disabled class="px-4 py-2 text-sm bg-accent-500 text-white rounded opacity-50"><?= htmlspecialchars(t('syncing_from_api')) ?></button>
        <button type="button" x-show="syncPhase === 'done' || syncPhase === 'error'" @click="closeSync()" class="px-4 py-2 text-sm bg-accent-500 hover:bg-accent-600 text-white rounded"><?= htmlspecialchars(t('ok')) ?></button>
      </div>
    </div>
  </div>

</div>

<?php
$extraScripts = '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="' . APP_BASE_URL . '/assets/js/named_entity_manager.js?v=' . filemtime(__DIR__ . '/../assets/js/named_entity_manager.js') . '"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  jQuery("#dataTable").DataTable({
    order: [],
    pageLength: 10,
  });
});
</script>';

include __DIR__ . '/../includes/footer.php';
