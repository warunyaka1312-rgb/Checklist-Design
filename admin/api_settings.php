<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';

requireRole('admin');

$pdo = getDbConnection();
$settings = $pdo->query('SELECT id, name, endpoint_url, api_key, is_enabled FROM api_settings ORDER BY id ASC')->fetchAll();

$i18nMap = [
    'invalid_input' => t('invalid_input'),
    'server_error' => t('server_error'),
    'invalid_csrf' => t('invalid_csrf'),
    'forbidden' => t('forbidden'),
    'confirm_delete' => t('confirm_delete'),
];

$pageTitle = t('nav_api_settings');
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div x-data="apiSettingsManager(
  document.querySelector('meta[name=csrf-token]').content,
  <?= jsonForAttr($i18nMap) ?>
)">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_api_settings')) ?></h1>
      <p class="text-sm text-steel-500 mt-1"><?= htmlspecialchars(t('api_settings_page_subtitle')) ?></p>
    </div>
    <button type="button" @click="openAdd()" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded">
      <?= htmlspecialchars(t('add_api_setting')) ?>
    </button>
  </div>

  <?php if (empty($settings)): ?>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500">
      <?= htmlspecialchars(t('no_api_settings_yet')) ?>
    </div>
  <?php else: ?>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
        <tr>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('api_name_label')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('api_endpoint_label')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('api_key_label')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('status')) ?></th>
          <th class="text-right px-4 py-3"><?= htmlspecialchars(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-steel-100">
        <?php foreach ($settings as $s): ?>
        <tr>
          <td class="px-4 py-3 font-medium text-navy-900"><?= htmlspecialchars($s['name']) ?></td>
          <td class="px-4 py-3 font-tabular text-steel-600 max-w-xs truncate"><?= htmlspecialchars($s['endpoint_url']) ?></td>
          <td class="px-4 py-3 font-tabular text-steel-400"><?= $s['api_key'] ? '••••••••' . htmlspecialchars(substr($s['api_key'], -4)) : '-' ?></td>
          <td class="px-4 py-3">
            <?php if ((int)$s['is_enabled'] === 1): ?>
              <span class="status-pill bg-status-successBg text-status-successText"><?= htmlspecialchars(t('enabled')) ?></span>
            <?php else: ?>
              <span class="status-pill bg-status-mutedBg text-status-mutedText"><?= htmlspecialchars(t('disabled')) ?></span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right whitespace-nowrap space-x-3">
            <button type="button" @click="openEdit(<?= jsonForAttr([
                'id' => (int)$s['id'],
                'name' => $s['name'],
                'endpoint_url' => $s['endpoint_url'],
                'api_key' => $s['api_key'],
                'is_enabled' => (int)$s['is_enabled'],
            ]) ?>)" class="text-accent-600 hover:underline"><?= htmlspecialchars(t('edit')) ?></button>
            <button type="button" @click="toggleEnabled(<?= (int)$s['id'] ?>, <?= (int)$s['is_enabled'] ?>)" class="<?= (int)$s['is_enabled'] === 1 ? 'text-red-600' : 'text-green-600' ?> hover:underline">
              <?= (int)$s['is_enabled'] === 1 ? htmlspecialchars(t('disabled')) : htmlspecialchars(t('enabled')) ?>
            </button>
            <button type="button" @click="remove(<?= (int)$s['id'] ?>)" class="text-steel-500 hover:underline"><?= htmlspecialchars(t('delete')) ?></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Add / Edit modal -->
  <div x-cloak x-show="modalOpen" class="fixed inset-0 z-30 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeModal()" class="bg-white rounded-xl w-full max-w-md border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900" x-text="mode === 'add' ? <?= jsonForAttr(t('add_api_setting')) ?> : <?= jsonForAttr(t('edit_api_setting')) ?>"></h2>
        <button type="button" @click="closeModal()" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>
      <form @submit.prevent="submitForm()" class="px-5 py-4 space-y-4">
        <div x-show="formError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2" x-text="formError"></div>

        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('api_name_label')) ?></label>
          <input type="text" x-model="form.name" placeholder="<?= htmlspecialchars(t('api_name_placeholder')) ?>" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>

        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('api_endpoint_label')) ?></label>
          <input type="text" x-model="form.endpoint_url" class="w-full border border-steel-300 rounded px-3 py-2 text-sm font-tabular focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>

        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('api_key_label')) ?></label>
          <input type="text" x-model="form.api_key" class="w-full border border-steel-300 rounded px-3 py-2 text-sm font-tabular focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>

        <label class="flex items-center gap-2 cursor-pointer select-none">
          <span class="relative inline-block w-9 h-5">
            <input type="checkbox" x-model="form.is_enabled" class="peer sr-only">
            <span class="absolute inset-0 rounded-full bg-steel-300 peer-checked:bg-accent-500 transition-colors"></span>
            <span class="absolute left-0.5 top-0.5 w-4 h-4 rounded-full bg-white transition-transform peer-checked:translate-x-4"></span>
          </span>
          <span class="text-sm text-steel-700"><?= htmlspecialchars(t('api_enabled_label')) ?></span>
        </label>

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

</div>

<?php
$extraScripts = <<<'HTML'
<script>
function apiSettingsManager(csrfToken, i18n) {
  return {
    csrfToken: csrfToken,
    i18n: i18n,

    modalOpen: false,
    mode: 'add',
    saving: false,
    formError: '',
    form: { id: null, name: '', endpoint_url: '', api_key: '', is_enabled: false },

    openAdd() {
      this.mode = 'add';
      this.form = { id: null, name: '', endpoint_url: '', api_key: '', is_enabled: false };
      this.formError = '';
      this.modalOpen = true;
    },

    openEdit(row) {
      this.mode = 'edit';
      this.form = { id: row.id, name: row.name, endpoint_url: row.endpoint_url, api_key: row.api_key || '', is_enabled: !!row.is_enabled };
      this.formError = '';
      this.modalOpen = true;
    },

    closeModal() {
      this.modalOpen = false;
    },

    async callApi(action, payload) {
      const res = await fetch(`../api/api_settings_api.php?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
        body: JSON.stringify(payload),
      });
      return res.json();
    },

    async submitForm() {
      this.formError = '';
      if (!this.form.name.trim() || !this.form.endpoint_url.trim()) {
        this.formError = this.i18n.invalid_input;
        return;
      }
      this.saving = true;
      try {
        const action = this.mode === 'add' ? 'create' : 'update';
        const data = await this.callApi(action, this.form);
        if (!data.success) {
          this.formError = this.i18n[data.message] || data.message;
          this.saving = false;
          return;
        }
        window.location.reload();
      } catch (e) {
        this.formError = this.i18n.server_error;
        this.saving = false;
      }
    },

    async toggleEnabled(id, currentlyEnabled) {
      const action = currentlyEnabled ? 'deactivate' : 'activate';
      try {
        const data = await this.callApi(action, { id });
        if (data.success) {
          window.location.reload();
        } else {
          alert(this.i18n[data.message] || data.message);
        }
      } catch (e) {
        alert(this.i18n.server_error);
      }
    },

    async remove(id) {
      if (!confirm(this.i18n.confirm_delete)) return;
      try {
        const data = await this.callApi('delete', { id });
        if (data.success) {
          window.location.reload();
        } else {
          alert(this.i18n[data.message] || data.message);
        }
      } catch (e) {
        alert(this.i18n.server_error);
      }
    },
  };
}
</script>
HTML;

include __DIR__ . '/../includes/footer.php';
