<?php
/**
 * Admin > Master Data > ผู้อนุมัติ — curates which users appear in the
 * "Manager ผู้อนุมัติ" dropdown on the Create Checklist page.
 *
 * Rows point at users rather than holding their own names, so an approver
 * keeps one identity for login, notifications and the approval screens. Adding
 * someone therefore means picking an existing Manager user, not typing a name;
 * new people are still created in Admin > จัดการผู้ใช้งาน first.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';

requireRole('admin');

$pdo = getDbConnection();

$approvers = $pdo->query(
    "SELECT a.id, a.user_id, a.is_active, u.full_name, u.username, u.is_active AS user_active,
            (SELECT COUNT(*) FROM checklists c WHERE c.assigned_manager_id = a.user_id) AS usage_count
       FROM approvers a
       JOIN users u ON u.id = a.user_id
      ORDER BY a.sort_order ASC, u.full_name ASC"
)->fetchAll();

// Manager users not on the list yet — the only thing "add" can offer.
$addable = $pdo->query(
    "SELECT u.id, u.full_name, u.username
       FROM users u
       LEFT JOIN approvers a ON a.user_id = u.id
      WHERE u.role = 'manager' AND u.is_active = 1 AND a.id IS NULL
      ORDER BY u.full_name ASC"
)->fetchAll();

$i18nMap = [
    'invalid_input' => t('invalid_input'),
    'server_error' => t('server_error'),
    'invalid_csrf' => t('invalid_csrf'),
    'forbidden' => t('forbidden'),
    'in_use' => t('approver_in_use'),
    'confirm_delete' => t('confirm_delete'),
    'confirm_deactivate_instead' => t('confirm_deactivate_instead'),
];

$pageTitle = t('nav_manage_approvers');
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div x-data="approverManager(
  document.querySelector('meta[name=csrf-token]').content,
  <?= jsonForAttr($i18nMap) ?>
)">

  <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
    <div>
      <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_manage_approvers')) ?></h1>
      <p class="text-sm text-steel-500 mt-1"><?= htmlspecialchars(t('approvers_page_subtitle')) ?></p>
    </div>
    <?php if (!empty($addable)): ?>
      <form @submit.prevent="add()" class="flex items-center gap-2">
        <select x-model.number="newUserId" class="border border-steel-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
          <option value="0"><?= htmlspecialchars(t('select_user_to_add')) ?></option>
          <?php foreach ($addable as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['username']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <button type="submit" :disabled="busy || !newUserId" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded disabled:opacity-50">
          <?= htmlspecialchars(t('add_approver')) ?>
        </button>
      </form>
    <?php else: ?>
      <p class="text-xs text-steel-500 max-w-xs"><?= htmlspecialchars(t('no_manager_users_left')) ?></p>
    <?php endif; ?>
  </div>

  <div x-show="formError" x-cloak class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 mb-4" x-text="formError"></div>

  <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
        <tr>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('name')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('status')) ?></th>
          <th class="text-right px-4 py-3"><?= htmlspecialchars(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-steel-100">
        <?php foreach ($approvers as $a): ?>
        <tr>
          <td class="px-4 py-3">
            <div class="font-medium text-navy-900"><?= htmlspecialchars($a['full_name']) ?></div>
            <div class="text-xs text-steel-500"><?= htmlspecialchars($a['username']) ?></div>
          </td>
          <td class="px-4 py-3">
            <?php if ((int)$a['is_active'] === 1 && (int)$a['user_active'] === 1): ?>
              <span class="status-pill bg-status-successBg text-status-successText"><?= htmlspecialchars(t('active')) ?></span>
            <?php else: ?>
              <span class="status-pill bg-status-dangerBg text-status-dangerText"><?= htmlspecialchars(t('inactive')) ?></span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right whitespace-nowrap space-x-3">
            <button type="button" @click="toggleActive(<?= (int)$a['id'] ?>, <?= (int)$a['is_active'] ?>)" :disabled="busy" class="<?= (int)$a['is_active'] === 1 ? 'text-red-600' : 'text-green-600' ?> hover:underline disabled:opacity-50">
              <?= (int)$a['is_active'] === 1 ? htmlspecialchars(t('deactivate')) : htmlspecialchars(t('activate')) ?>
            </button>
            <button type="button" @click="remove(<?= (int)$a['id'] ?>)" :disabled="busy" class="text-steel-500 hover:underline disabled:opacity-50"><?= htmlspecialchars(t('delete')) ?></button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($approvers)): ?>
        <tr><td colspan="3" class="px-4 py-6 text-center text-steel-500"><?= htmlspecialchars(t('no_data')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<?php
$extraScripts = '<script src="' . APP_BASE_URL . '/assets/js/approver_manager.js"></script>';
include __DIR__ . '/../includes/footer.php';
