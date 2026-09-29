<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/keycloak.php';

requireRole('admin');

// Keycloak on = users/roles come from Keycloak (created on first login), so
// only activate/deactivate stays here. Reset password only matters while the
// local login form is still allowed.
$kcManaged = keycloakEnabled();
$canResetPassword = localLoginAllowed();

$me = currentUser();
$pdo = getDbConnection();
$users = $pdo->query('SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY id ASC')->fetchAll();

$usersForJs = array_map(static function (array $u): array {
    return [
        'id' => (int)$u['id'],
        'full_name' => $u['full_name'],
        'username' => $u['username'],
        'role' => $u['role'],
        'is_active' => (int)$u['is_active'],
    ];
}, $users);

$i18nMap = [
    'invalid_input' => t('invalid_input'),
    'password_too_short' => t('password_too_short'),
    'username_taken' => t('username_taken'),
    'cannot_deactivate_self' => t('cannot_deactivate_self'),
    'confirm_deactivate_user' => t('confirm_deactivate_user'),
    'confirm_activate_user' => t('confirm_activate_user'),
    'activate' => t('activate'),
    'deactivate' => t('deactivate'),
    'server_error' => t('server_error'),
    'invalid_csrf' => t('invalid_csrf'),
    'forbidden' => t('forbidden'),
    'managed_by_keycloak' => t('managed_by_keycloak'),
];

$pageTitle = t('nav_manage_users');
$extraHead = '<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div x-data="userManager(
  <?= jsonForAttr($usersForJs) ?>,
  document.querySelector('meta[name=csrf-token]').content,
  <?= (int)$me['id'] ?>,
  <?= jsonForAttr($i18nMap) ?>
)">

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_manage_users')) ?></h1>
      <p class="text-sm text-steel-500 mt-1"><?= htmlspecialchars(t('users_page_subtitle')) ?></p>
    </div>
    <?php if (!$kcManaged): ?>
    <button type="button" @click="openAdd()" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded">
      <?= htmlspecialchars(t('add_user')) ?>
    </button>
    <?php endif; ?>
  </div>

  <?php if ($kcManaged): ?>
  <div class="mb-4 text-sm text-accent-700 bg-accent-50 border border-accent-100 rounded-lg px-4 py-3">
    <?= htmlspecialchars(t('users_managed_by_keycloak')) ?>
  </div>
  <?php endif; ?>

  <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
    <table id="usersTable" class="table-compact w-full text-sm">
      <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
        <tr>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('full_name')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('username')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('role')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('status')) ?></th>
          <th class="text-right px-4 py-3"><?= htmlspecialchars(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-steel-100">
        <?php foreach ($users as $u): ?>
        <tr>
          <td class="px-4 py-3"><?= htmlspecialchars($u['full_name']) ?></td>
          <td class="px-4 py-3 font-tabular"><?= htmlspecialchars($u['username']) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars(roleLabel($u['role'])) ?></td>
          <td class="px-4 py-3">
            <?php if ((int)$u['is_active'] === 1): ?>
              <span class="status-pill bg-status-successBg text-status-successText"><?= htmlspecialchars(t('active')) ?></span>
            <?php else: ?>
              <span class="status-pill bg-status-dangerBg text-status-dangerText"><?= htmlspecialchars(t('inactive')) ?></span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <?php
                $isActive = (int)$u['is_active'] === 1;
                $toggleLabel = htmlspecialchars($isActive ? t('deactivate') : t('activate'));
            ?>
            <div class="flex items-center justify-end gap-1 -my-1.5">
              <?php if (!$kcManaged): ?>
              <button type="button" @click="openEdit(users.find(x => x.id === <?= (int)$u['id'] ?>))" class="p-1.5 rounded-lg text-accent-600 hover:bg-accent-50" title="<?= htmlspecialchars(t('edit')) ?>" aria-label="<?= htmlspecialchars(t('edit')) ?>">
                <?= actionIconSvg('edit') ?>
              </button>
              <?php endif; ?>
              <?php if ($canResetPassword): ?>
              <button type="button" @click="openReset(<?= (int)$u['id'] ?>)" class="p-1.5 rounded-lg text-steel-500 hover:text-steel-700 hover:bg-steel-100" title="<?= htmlspecialchars(t('reset_password')) ?>" aria-label="<?= htmlspecialchars(t('reset_password')) ?>">
                <?= actionIconSvg('reset_password') ?>
              </button>
              <?php endif; ?>
              <button type="button" @click="toggleActive(users.find(x => x.id === <?= (int)$u['id'] ?>))" :disabled="togglingId !== null" class="p-1.5 rounded-lg <?= $isActive ? 'text-red-600 hover:bg-red-50' : 'text-green-600 hover:bg-green-50' ?> disabled:opacity-50" title="<?= $toggleLabel ?>" aria-label="<?= $toggleLabel ?>">
                <?= actionIconSvg($isActive ? 'deactivate' : 'activate') ?>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Add / Edit modal -->
  <div x-cloak x-show="modalOpen" class="fixed inset-0 z-30 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeModal()" class="bg-white rounded-xl w-full max-w-md border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900" x-text="mode === 'add' ? <?= jsonForAttr(t('add_user')) ?> : <?= jsonForAttr(t('edit_user')) ?>"></h2>
        <button type="button" @click="closeModal()" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>
      <form @submit.prevent="submitForm()" class="px-5 py-4 space-y-4">
        <div x-show="formError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2" x-text="formError"></div>

        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('full_name')) ?></label>
          <input type="text" x-model="form.full_name" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>

        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('username')) ?></label>
          <input type="text" x-model="form.username" class="w-full border border-steel-300 rounded px-3 py-2 text-sm font-tabular focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>

        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('role')) ?></label>
          <select x-model="form.role" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
            <option value="admin"><?= htmlspecialchars(t('role_admin')) ?></option>
            <option value="engineer"><?= htmlspecialchars(t('role_engineer')) ?></option>
            <option value="manager"><?= htmlspecialchars(t('role_manager')) ?></option>
          </select>
        </div>

        <div x-show="mode === 'add'" x-cloak>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('password')) ?></label>
          <input type="password" x-model="form.password" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
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

  <!-- Reset password modal -->
  <div x-cloak x-show="resetModalOpen" class="fixed inset-0 z-30 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeReset()" class="bg-white rounded-xl w-full max-w-sm border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900"><?= htmlspecialchars(t('reset_password')) ?></h2>
        <button type="button" @click="closeReset()" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>
      <form @submit.prevent="submitReset()" class="px-5 py-4 space-y-4">
        <div x-show="resetError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2" x-text="resetError"></div>
        <div>
          <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('new_password')) ?></label>
          <input type="password" x-model="resetPassword" class="w-full border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="closeReset()" class="px-4 py-2 text-sm border border-steel-300 rounded text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('cancel')) ?></button>
          <button type="submit" :disabled="resetSaving" class="px-4 py-2 text-sm bg-accent-500 hover:bg-accent-600 text-white rounded disabled:opacity-50">
            <span x-show="!resetSaving"><?= htmlspecialchars(t('save')) ?></span>
            <span x-show="resetSaving" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Activate / deactivate confirm modal -->
  <div x-cloak x-show="confirmOpen" @keydown.escape.window="confirmOpen && closeConfirm()" class="fixed inset-0 z-40 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeConfirm()" class="bg-white rounded-xl w-full max-w-sm border border-steel-200 shadow-softLg">
      <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
        <h2 class="font-semibold text-navy-900" x-text="confirmActivating ? i18n.activate : i18n.deactivate"></h2>
        <button type="button" @click="closeConfirm()" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
      </div>
      <div class="px-5 py-5 flex items-start gap-3">
        <div class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center" :class="confirmActivating ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'">
          <span x-show="confirmActivating"><?= actionIconSvg('activate') ?></span>
          <span x-show="!confirmActivating"><?= actionIconSvg('deactivate') ?></span>
        </div>
        <p class="text-sm text-navy-900 pt-2" x-text="confirmMessage"></p>
      </div>
      <div class="px-5 py-3 border-t border-steel-200 flex justify-end gap-2">
        <button type="button" @click="closeConfirm()" :disabled="togglingId !== null" class="px-4 py-2 text-sm border border-steel-300 rounded text-steel-600 hover:bg-steel-50 disabled:opacity-50"><?= htmlspecialchars(t('cancel')) ?></button>
        <button type="button" x-ref="confirmOk" @click="confirmToggle()" :disabled="togglingId !== null" class="px-4 py-2 text-sm text-white rounded disabled:opacity-50" :class="confirmActivating ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'">
          <span x-show="togglingId === null" x-text="confirmActivating ? i18n.activate : i18n.deactivate"></span>
          <span x-show="togglingId !== null" x-cloak><?= htmlspecialchars(t('saving')) ?></span>
        </button>
      </div>
    </div>
  </div>

  <!-- Notice modal (replaces browser alert) -->
  <div x-cloak x-show="noticeOpen" @keydown.escape.window="noticeOpen && closeNotice()" class="fixed inset-0 z-40 flex items-center justify-center bg-navy-950/50 px-4">
    <div @click.outside="closeNotice()" class="bg-white rounded-xl w-full max-w-sm border border-steel-200 shadow-softLg">
      <div class="px-5 py-5 flex items-start gap-3">
        <div class="shrink-0 w-9 h-9 rounded-full bg-red-50 text-red-600 flex items-center justify-center">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          </svg>
        </div>
        <p class="text-sm text-navy-900 pt-2 whitespace-pre-line" x-text="noticeMessage"></p>
      </div>
      <div class="px-5 py-3 border-t border-steel-200 flex justify-end">
        <button type="button" x-ref="noticeOk" @click="closeNotice()" class="px-4 py-2 text-sm bg-accent-500 hover:bg-accent-600 text-white rounded"><?= htmlspecialchars(t('ok')) ?></button>
      </div>
    </div>
  </div>

</div>

<?php
$extraScripts = <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
function userManager(initialUsers, csrfToken, currentUserId, i18n) {
  return {
    users: initialUsers,
    csrfToken: csrfToken,
    currentUserId: currentUserId,
    i18n: i18n,

    modalOpen: false,
    mode: 'add',
    saving: false,
    formError: '',
    form: { id: null, full_name: '', username: '', role: 'engineer', password: '' },

    resetModalOpen: false,
    resetTargetId: null,
    resetPassword: '',
    resetError: '',
    resetSaving: false,

    togglingId: null,

    confirmOpen: false,
    confirmUser: null,
    confirmActivating: false,
    confirmMessage: '',

    noticeOpen: false,
    noticeMessage: '',

    showNotice(message) {
      this.noticeMessage = message;
      this.noticeOpen = true;
      this.$nextTick(() => this.$refs.noticeOk && this.$refs.noticeOk.focus());
    },

    closeNotice() {
      this.noticeOpen = false;
    },

    openAdd() {
      this.mode = 'add';
      this.form = { id: null, full_name: '', username: '', role: 'engineer', password: '' };
      this.formError = '';
      this.modalOpen = true;
    },

    openEdit(user) {
      this.mode = 'edit';
      this.form = { id: user.id, full_name: user.full_name, username: user.username, role: user.role, password: '' };
      this.formError = '';
      this.modalOpen = true;
    },

    closeModal() {
      this.modalOpen = false;
    },

    async submitForm() {
      this.formError = '';
      if (!this.form.full_name.trim() || !this.form.username.trim()) {
        this.formError = this.i18n.invalid_input;
        return;
      }
      if (this.mode === 'add' && this.form.password.length < 6) {
        this.formError = this.i18n.password_too_short;
        return;
      }

      this.saving = true;
      try {
        const action = this.mode === 'add' ? 'create' : 'update';
        const res = await fetch(`../api/users_api.php?action=${action}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
          body: JSON.stringify(this.form),
        });
        const data = await res.json();
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

    toggleActive(user) {
      if (user.id === this.currentUserId) {
        this.showNotice(this.i18n.cannot_deactivate_self);
        return;
      }
      if (this.togglingId !== null) return;
      this.confirmUser = user;
      this.confirmActivating = user.is_active !== 1;
      const tpl = this.confirmActivating ? this.i18n.confirm_activate_user : this.i18n.confirm_deactivate_user;
      this.confirmMessage = tpl.replace('{name}', user.full_name || user.username);
      this.confirmOpen = true;
      this.$nextTick(() => this.$refs.confirmOk && this.$refs.confirmOk.focus());
    },

    closeConfirm() {
      if (this.togglingId !== null) return;
      this.confirmOpen = false;
    },

    async confirmToggle() {
      const user = this.confirmUser;
      if (!user || this.togglingId !== null) return;
      this.togglingId = user.id;
      try {
        const res = await fetch('../api/users_api.php?action=toggle_active', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
          body: JSON.stringify({ id: user.id }),
        });
        const data = await res.json();
        if (data.success) {
          window.location.reload();
          return;
        }
        this.togglingId = null;
        this.confirmOpen = false;
        this.showNotice(this.i18n[data.message] || data.message);
      } catch (e) {
        this.togglingId = null;
        this.confirmOpen = false;
        this.showNotice(this.i18n.server_error);
      }
    },

    openReset(id) {
      this.resetTargetId = id;
      this.resetPassword = '';
      this.resetError = '';
      this.resetModalOpen = true;
    },

    closeReset() {
      this.resetModalOpen = false;
    },

    async submitReset() {
      if (this.resetPassword.length < 6) {
        this.resetError = this.i18n.password_too_short;
        return;
      }
      this.resetSaving = true;
      try {
        const res = await fetch('../api/users_api.php?action=reset_password', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
          body: JSON.stringify({ id: this.resetTargetId, password: this.resetPassword }),
        });
        const data = await res.json();
        if (!data.success) {
          this.resetError = this.i18n[data.message] || data.message;
          this.resetSaving = false;
          return;
        }
        this.resetSaving = false;
        this.resetModalOpen = false;
      } catch (e) {
        this.resetError = this.i18n.server_error;
        this.resetSaving = false;
      }
    },
  };
}

document.addEventListener('DOMContentLoaded', function () {
  jQuery('#usersTable').DataTable({
    order: [],
    pageLength: 10,
  });
});
</script>
HTML;

include __DIR__ . '/../includes/footer.php';
