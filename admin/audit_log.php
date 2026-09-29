<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';
require_once __DIR__ . '/../includes/report_helpers.php';

requireRole('admin');

$me = currentUser();
$pdo = getDbConnection();

$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
    'action' => trim((string)($_GET['action'] ?? '')),
    'action_by' => trim((string)($_GET['action_by'] ?? '')),
];

$rows = fetchAuditLog($pdo, $filters);

$users = $pdo->query('SELECT id, full_name, role FROM users ORDER BY full_name ASC')->fetchAll();

$pageTitle = t('nav_audit_log');
$extraHead = '<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
  <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_audit_log')) ?></h1>
  <p class="text-sm text-steel-500 mt-1"><?= htmlspecialchars(t('audit_log_page_subtitle')) ?></p>
</div>

<form method="get" class="bg-white border border-steel-200 rounded-xl shadow-soft p-5 mb-6">
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_date_from')) ?></label>
      <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>" class="w-full border border-steel-300 rounded px-3 py-2 text-sm font-tabular">
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_date_to')) ?></label>
      <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>" class="w-full border border-steel-300 rounded px-3 py-2 text-sm font-tabular">
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_action')) ?></label>
      <select name="action" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <?php foreach (['submitted', 'approved', 'rejected', 'edited'] as $a): ?>
          <option value="<?= $a ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= htmlspecialchars(historyActionLabel($a)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_user')) ?></label>
      <select name="action_by" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $filters['action_by'] === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars(roleLabel($u['role'])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="flex items-center gap-3 mt-4">
    <button type="submit" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded"><?= htmlspecialchars(t('apply_filter')) ?></button>
    <a href="audit_log.php" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('reset_filter')) ?></a>
  </div>
</form>

<p class="text-xs text-steel-400 mb-3"><?= htmlspecialchars(t('audit_log_page_subtitle')) ?></p>

<?php if (empty($rows)): ?>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500">
    <?= htmlspecialchars(t('no_results_found')) ?>
  </div>
<?php else: ?>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
    <table id="dataTable" class="w-full text-sm">
      <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
        <tr>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('audit_time_col')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('audit_checklist_col')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('audit_action_col')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('audit_status_change_col')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('audit_by_col')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('audit_note_col')) ?></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-steel-100">
        <?php foreach ($rows as $row): ?>
        <tr>
          <td class="px-4 py-3 font-tabular text-steel-500 whitespace-nowrap"><?= htmlspecialchars(date('d M Y H:i', strtotime($row['created_at']))) ?></td>
          <td class="px-4 py-3">
            <a href="<?= htmlspecialchars(APP_BASE_URL) ?>/admin/reports.php" class="font-tabular font-medium text-navy-900 hover:underline"><?= htmlspecialchars($row['die_no']) ?></a>
          </td>
          <td class="px-4 py-3"><?= htmlspecialchars(historyActionLabel($row['action'])) ?></td>
          <td class="px-4 py-3 text-steel-600"><?= htmlspecialchars(statusLabelText($row['old_status'] ?? null)) ?> &rarr; <?= htmlspecialchars(statusLabelText($row['new_status'] ?? null)) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($row['action_by_name']) ?> <span class="text-xs text-steel-400">(<?= htmlspecialchars(roleLabel($row['action_by_role'])) ?>)</span></td>
          <td class="px-4 py-3 text-steel-600"><?= $row['note'] ? nl2br(htmlspecialchars($row['note'])) : '<span class="text-steel-300">-</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php
$extraScripts = <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (jQuery('#dataTable').length) {
    jQuery('#dataTable').DataTable({
      order: [],
      pageLength: 25,
    });
  }
});
</script>
HTML;

include __DIR__ . '/../includes/footer.php';
