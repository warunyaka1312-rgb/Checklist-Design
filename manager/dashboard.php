<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';
require_once __DIR__ . '/../includes/report_helpers.php';

requireRole('manager');

$me = currentUser();
$pdo = getDbConnection();

$summary = fetchDashboardSummary($pdo);
$pendingRows = fetchPendingForManager($pdo, (int)$me['id']);
$historyRows = fetchDecidedByManager($pdo, (int)$me['id']);

$pageTitle = t('nav_dashboard');
$extraHead = '<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<h1 class="text-xl font-semibold text-navy-900 mb-6"><?= htmlspecialchars(t('dashboard_welcome')) ?>, <?= htmlspecialchars($me['full_name']) ?></h1>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <div class="stat-card rounded-xl shadow-soft p-6 text-white bg-[linear-gradient(135deg,#172033,#0f172a)]">
    <div class="text-xs font-medium uppercase tracking-wide opacity-90"><?= htmlspecialchars(t('card_total_checklists')) ?></div>
    <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$summary['total'] ?></div>
    <span class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M9 10h6M9 14h6M9 18h3"/></svg></span>
  </div>
  <div class="stat-card rounded-xl shadow-soft p-6 text-navy-900 bg-[linear-gradient(135deg,#f59e0b,#b45309)]">
    <div class="text-xs font-medium uppercase tracking-wide opacity-80"><?= htmlspecialchars(t('card_pending')) ?></div>
    <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$summary['pending'] ?></div>
    <span class="stat-icon text-navy-900"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg></span>
  </div>
  <div class="stat-card rounded-xl shadow-soft p-6 text-white bg-[linear-gradient(135deg,#198754,#20c997)]">
    <div class="text-xs font-medium uppercase tracking-wide opacity-90"><?= htmlspecialchars(t('card_approved')) ?></div>
    <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$summary['approved'] ?></div>
    <span class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg></span>
  </div>
  <div class="stat-card rounded-xl shadow-soft p-6 text-white bg-[linear-gradient(135deg,#dc3545,#e35d6a)]">
    <div class="text-xs font-medium uppercase tracking-wide opacity-90"><?= htmlspecialchars(t('card_rejected_this_month')) ?></div>
    <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$summary['rejected_this_month'] ?></div>
    <span class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="m9.5 9.5 5 5m0-5-5 5"/></svg></span>
  </div>
</div>

<!-- Pending my approval -->
<div class="mb-8">
  <h2 class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('nav_pending_approval')) ?></h2>
  <?php if (empty($pendingRows)): ?>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500">
      <?= htmlspecialchars(t('no_pending_approval')) ?>
    </div>
  <?php else: ?>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
      <table id="dataTablePending" class="w-full text-sm">
        <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('die_no_label')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_model')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_customer')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('die_type_solid_hollow')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('creator')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('created_at')) ?></th>
            <th class="text-right px-4 py-3"><?= htmlspecialchars(t('actions')) ?></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-steel-100">
          <?php foreach ($pendingRows as $row): ?>
          <tr>
            <td class="px-4 py-3 font-tabular font-medium text-navy-900"><?= htmlspecialchars($row['die_no']) ?></td>
            <td class="px-4 py-3 font-tabular"><?= htmlspecialchars($row['model_name']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($row['customer_name']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars(t($row['die_type'] . '_die')) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($row['creator_name']) ?></td>
            <td class="px-4 py-3 font-tabular text-steel-500"><?= htmlspecialchars(date('d M Y H:i', strtotime($row['created_at']))) ?></td>
            <td class="px-4 py-3 text-right">
              <a href="checklist_view.php?id=<?= (int)$row['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-steel-200 text-accent-600 hover:bg-accent-50 hover:border-accent-200 transition-colors" title="<?= htmlspecialchars(t('view_edit')) ?>" aria-label="<?= htmlspecialchars(t('view_edit')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                </svg>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- My approval history -->
<div class="mb-8">
  <h2 class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('my_approval_history_title')) ?></h2>
  <?php if (empty($historyRows)): ?>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500">
      <?= htmlspecialchars(t('no_approval_history_yet')) ?>
    </div>
  <?php else: ?>
    <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
      <table id="dataTableHistory" class="w-full text-sm">
        <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('die_no_label')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_model')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_customer')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('creator')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('status')) ?></th>
            <th class="text-left px-4 py-3"><?= htmlspecialchars(t('decided_at')) ?></th>
            <th class="text-right px-4 py-3"><?= htmlspecialchars(t('actions')) ?></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-steel-100">
          <?php foreach ($historyRows as $row): ?>
          <tr>
            <td class="px-4 py-3 font-tabular font-medium text-navy-900"><?= htmlspecialchars($row['die_no']) ?></td>
            <td class="px-4 py-3 font-tabular"><?= htmlspecialchars($row['model_name']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($row['customer_name']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($row['creator_name']) ?></td>
            <td class="px-4 py-3"><?= statusBadgeHtml($row['status']) ?></td>
            <td class="px-4 py-3 font-tabular text-steel-500"><?= $row['decided_at'] ? htmlspecialchars(date('d M Y H:i', strtotime($row['decided_at']))) : '-' ?></td>
            <td class="px-4 py-3 text-right">
              <a href="checklist_view.php?id=<?= (int)$row['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-steel-200 text-accent-600 hover:bg-accent-50 hover:border-accent-200 transition-colors" title="<?= htmlspecialchars(t('view_edit')) ?>" aria-label="<?= htmlspecialchars(t('view_edit')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                </svg>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
$extraScripts = <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (jQuery('#dataTablePending').length) {
    jQuery('#dataTablePending').DataTable({
      order: [[5, 'asc']],
      pageLength: 10,
    });
  }
  if (jQuery('#dataTableHistory').length) {
    jQuery('#dataTableHistory').DataTable({
      order: [[5, 'desc']],
      pageLength: 10,
    });
  }
});
</script>
HTML;

include __DIR__ . '/../includes/footer.php';
