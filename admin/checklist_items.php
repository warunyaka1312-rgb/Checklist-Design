<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';

requireRole('admin');

$pdo = getDbConnection();
$items = $pdo->query('SELECT id, die_type, topic, note, is_active, sort_order FROM checklist_items ORDER BY die_type ASC, sort_order ASC, id ASC')->fetchAll();

$itemsByType = ['solid' => [], 'hollow' => []];
foreach ($items as $it) {
    $itemsByType[$it['die_type']][] = $it;
}

$i18nMap = [
    'invalid_input' => t('invalid_input'),
    'name_taken' => t('name_taken'),
    'server_error' => t('server_error'),
    'invalid_csrf' => t('invalid_csrf'),
    'forbidden' => t('forbidden'),
    'confirm_delete' => t('confirm_delete'),
    'confirm_deactivate_instead' => t('confirm_deactivate_instead'),
];

$pageTitle = t('nav_manage_checklist_items');
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';

/**
 * Renders one die-type tab panel: a single flat, ordered list of items.
 * @param array<int,array<string,mixed>> $itemsOfType
 */
function renderItemsPanel(array $itemsOfType): void
{
    $lastIndex = count($itemsOfType) - 1;
    ?>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft mb-4">
      <?php if (empty($itemsOfType)): ?>
        <div class="px-4 py-6 text-sm text-steel-500 text-center"><?= htmlspecialchars(t('no_items')) ?></div>
      <?php else: ?>
        <table class="w-full text-sm">
          <thead class="text-steel-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-2 w-16"></th>
              <th class="text-left px-4 py-2"><?= htmlspecialchars(t('item_topic')) ?></th>
              <th class="text-left px-4 py-2"><?= htmlspecialchars(t('item_note')) ?></th>
              <th class="text-left px-4 py-2 whitespace-nowrap"><?= htmlspecialchars(t('status')) ?></th>
              <th class="text-right px-4 py-2"><?= htmlspecialchars(t('actions')) ?></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-steel-100">
            <?php foreach ($itemsOfType as $index => $item):
                $itemJson = jsonForAttr([
                    'id' => (int)$item['id'],
                    'die_type' => $item['die_type'],
                    'topic' => $item['topic'],
                    'note' => $item['note'],
                ]);
            ?>
            <tr>
              <td class="px-4 py-1.5 whitespace-nowrap">
                <button type="button" @click="moveItem(<?= (int)$item['id'] ?>, 'up')" :disabled="busy || <?= $index === 0 ? 'true' : 'false' ?>" class="text-steel-400 hover:text-steel-700 disabled:opacity-30 disabled:cursor-not-allowed" title="<?= htmlspecialchars(t('move_up')) ?>">&uarr;</button>
                <button type="button" @click="moveItem(<?= (int)$item['id'] ?>, 'down')" :disabled="busy || <?= $index === $lastIndex ? 'true' : 'false' ?>" class="text-steel-400 hover:text-steel-700 disabled:opacity-30 disabled:cursor-not-allowed" title="<?= htmlspecialchars(t('move_down')) ?>">&darr;</button>
              </td>
              <td class="px-4 py-1.5 font-medium text-navy-900"><?= htmlspecialchars($item['topic']) ?></td>
              <td class="px-4 py-1.5 text-steel-500 max-w-xs truncate" title="<?= htmlspecialchars((string)$item['note']) ?>"><?= htmlspecialchars((string)$item['note']) ?></td>
              <td class="px-4 py-1.5 whitespace-nowrap">
                <?php if ((int)$item['is_active'] === 1): ?>
                  <span class="status-pill bg-status-successBg text-status-successText"><?= htmlspecialchars(t('active')) ?></span>
                <?php else: ?>
                  <span class="status-pill bg-status-dangerBg text-status-dangerText"><?= htmlspecialchars(t('inactive')) ?></span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-1.5 text-right whitespace-nowrap">
                <?php
                    $isActive = (int)$item['is_active'] === 1;
                    $toggleLabel = htmlspecialchars($isActive ? t('deactivate') : t('activate'));
                ?>
                <div class="flex items-center justify-end gap-1 -my-1.5">
                  <button type="button" @click="openEditItem(<?= $itemJson ?>)" class="p-1.5 rounded-lg text-accent-600 hover:bg-accent-50" title="<?= htmlspecialchars(t('edit')) ?>" aria-label="<?= htmlspecialchars(t('edit')) ?>">
                    <?= actionIconSvg('edit') ?>
                  </button>
                  <button type="button" @click="toggleItemActive(<?= (int)$item['id'] ?>, <?= (int)$item['is_active'] ?>)" :disabled="busy" class="p-1.5 rounded-lg <?= $isActive ? 'text-red-600 hover:bg-red-50' : 'text-green-600 hover:bg-green-50' ?> disabled:opacity-50" title="<?= $toggleLabel ?>" aria-label="<?= $toggleLabel ?>">
                    <?= actionIconSvg($isActive ? 'deactivate' : 'activate') ?>
                  </button>
                  <button type="button" @click="removeItem(<?= (int)$item['id'] ?>)" :disabled="busy" class="p-1.5 rounded-lg text-steel-500 hover:text-red-600 hover:bg-red-50 disabled:opacity-50" title="<?= htmlspecialchars(t('delete')) ?>" aria-label="<?= htmlspecialchars(t('delete')) ?>">
                    <?= actionIconSvg('delete') ?>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
}
?>

<div x-data="itemsManager(document.querySelector('meta[name=csrf-token]').content, <?= jsonForAttr($i18nMap) ?>)">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_manage_checklist_items')) ?></h1>
      <p class="text-sm text-steel-500 mt-1"><?= htmlspecialchars(t('checklist_items_page_subtitle')) ?></p>
    </div>
  </div>

  <div class="flex gap-1 border-b border-steel-200 mb-6">
    <button
      type="button"
      @click="activeTab = 'solid'"
      :class="activeTab === 'solid' ? 'border-accent-500 text-accent-600' : 'border-transparent text-steel-500 hover:text-steel-700'"
      class="px-4 py-2 text-sm font-medium border-b-2 -mb-px"
    ><?= htmlspecialchars(t('solid_die')) ?></button>
    <button
      type="button"
      @click="activeTab = 'hollow'"
      :class="activeTab === 'hollow' ? 'border-accent-500 text-accent-600' : 'border-transparent text-steel-500 hover:text-steel-700'"
      class="px-4 py-2 text-sm font-medium border-b-2 -mb-px"
    ><?= htmlspecialchars(t('hollow_die')) ?></button>
  </div>

  <div x-show="activeTab === 'solid'">
    <div class="flex justify-end mb-4">
      <button type="button" @click="openAddItem('solid')" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded"><?= htmlspecialchars(t('add_item')) ?></button>
    </div>
    <?php renderItemsPanel($itemsByType['solid']); ?>
  </div>

  <div x-show="activeTab === 'hollow'" x-cloak>
    <div class="flex justify-end mb-4">
      <button type="button" @click="openAddItem('hollow')" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded"><?= htmlspecialchars(t('add_item')) ?></button>
    </div>
    <?php renderItemsPanel($itemsByType['hollow']); ?>
  </div>

  <!-- Item modal -->
  <div x-cloak x-show="itemModalOpen" class="fixed inset-0 z-30 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeItemModal()" class="bg-white rounded-xl w-full max-w-md border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900" x-text="itemMode === 'add' ? <?= jsonForAttr(t('add_item')) ?> : <?= jsonForAttr(t('edit_item')) ?>"></h2>
        <button type="button" @click="closeItemModal()" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>
      <form @submit.prevent="submitItem()" class="px-5 py-4 space-y-4">
        <div x-show="itemError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2" x-text="itemError"></div>
        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('item_topic')) ?></label>
          <input type="text" x-model="itemForm.topic" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>
        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('item_note')) ?></label>
          <textarea x-model="itemForm.note" rows="3" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="closeItemModal()" class="px-4 py-2 text-sm border border-steel-300 rounded text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('cancel')) ?></button>
          <button type="submit" :disabled="itemSaving" class="px-4 py-2 text-sm bg-accent-500 hover:bg-accent-600 text-white rounded disabled:opacity-50">
            <span x-show="!itemSaving"><?= htmlspecialchars(t('save')) ?></span>
            <span x-show="itemSaving" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
          </button>
        </div>
      </form>
    </div>
  </div>

</div>

<?php
$extraScripts = <<<'HTML'
<script>
function itemsManager(csrfToken, i18n) {
  return {
    activeTab: 'solid',
    csrfToken: csrfToken,
    i18n: i18n,
    busy: false,

    itemModalOpen: false,
    itemMode: 'add',
    itemError: '',
    itemSaving: false,
    itemForm: { id: null, die_type: 'solid', topic: '', note: '' },

    async callApi(entity, action, payload) {
      const res = await fetch(`../api/master_data_api.php?entity=${entity}&action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
        body: JSON.stringify(payload),
      });
      return res.json();
    },

    reload() {
      window.location.reload();
    },

    // ---- items ----
    openAddItem(dieType) {
      this.itemMode = 'add';
      this.itemForm = { id: null, die_type: dieType, topic: '', note: '' };
      this.itemError = '';
      this.itemModalOpen = true;
    },
    openEditItem(item) {
      this.itemMode = 'edit';
      this.itemForm = { id: item.id, die_type: item.die_type, topic: item.topic, note: item.note || '' };
      this.itemError = '';
      this.itemModalOpen = true;
    },
    closeItemModal() {
      this.itemModalOpen = false;
    },
    async submitItem() {
      this.itemError = '';
      if (!this.itemForm.topic.trim()) {
        this.itemError = this.i18n.invalid_input;
        return;
      }
      this.itemSaving = true;
      try {
        const action = this.itemMode === 'add' ? 'create' : 'update';
        const data = await this.callApi('items', action, this.itemForm);
        if (!data.success) {
          this.itemError = this.i18n[data.message] || data.message;
          this.itemSaving = false;
          return;
        }
        this.reload();
      } catch (e) {
        this.itemError = this.i18n.server_error;
        this.itemSaving = false;
      }
    },
    async toggleItemActive(id, currentlyActive) {
      if (this.busy) return;
      this.busy = true;
      try {
        const action = currentlyActive ? 'deactivate' : 'activate';
        const data = await this.callApi('items', action, { id });
        if (data.success) { this.reload(); } else { alert(this.i18n[data.message] || data.message); }
      } finally {
        this.busy = false;
      }
    },
    async removeItem(id) {
      if (this.busy || !confirm(this.i18n.confirm_delete)) return;
      this.busy = true;
      try {
        const data = await this.callApi('items', 'delete', { id });
        if (data.success) { this.reload(); return; }
        if (data.message === 'in_use') {
          if (confirm(this.i18n.confirm_deactivate_instead)) {
            const data2 = await this.callApi('items', 'deactivate', { id });
            if (data2.success) { this.reload(); } else { alert(this.i18n[data2.message] || data2.message); }
          }
          return;
        }
        alert(this.i18n[data.message] || data.message);
      } finally {
        this.busy = false;
      }
    },
    async moveItem(id, direction) {
      if (this.busy) return;
      this.busy = true;
      try {
        const data = await this.callApi('items', 'move', { id, direction });
        if (data.success) { this.reload(); } else { alert(this.i18n[data.message] || data.message); }
      } finally {
        this.busy = false;
      }
    },
  };
}
</script>
HTML;

include __DIR__ . '/../includes/footer.php';
