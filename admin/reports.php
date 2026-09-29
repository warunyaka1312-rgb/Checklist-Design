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
    'status' => trim((string)($_GET['status'] ?? '')),
    'customer_id' => trim((string)($_GET['customer_id'] ?? '')),
    'model_id' => trim((string)($_GET['model_id'] ?? '')),
    'die_type' => trim((string)($_GET['die_type'] ?? '')),
    'engineer_id' => trim((string)($_GET['engineer_id'] ?? '')),
    'manager_id' => trim((string)($_GET['manager_id'] ?? '')),
];

$rows = fetchReportRows($pdo, $filters);

$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name ASC')->fetchAll();
$models = $pdo->query('SELECT id, name FROM die_models ORDER BY name ASC')->fetchAll();
$engineers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'engineer' ORDER BY full_name ASC")->fetchAll();
$managers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'manager' ORDER BY full_name ASC")->fetchAll();

$pageTitle = t('nav_reports');
$extraHead = '<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
  <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('nav_reports')) ?></h1>
  <p class="text-sm text-steel-500 mt-1"><?= htmlspecialchars(t('reports_page_subtitle')) ?></p>
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
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_status')) ?></label>
      <select name="status" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <?php foreach (['draft', 'pending', 'approved', 'rejected'] as $s): ?>
          <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars(t('status_' . $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_die_type')) ?></label>
      <select name="die_type" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <option value="solid" <?= $filters['die_type'] === 'solid' ? 'selected' : '' ?>><?= htmlspecialchars(t('solid_die')) ?></option>
        <option value="hollow" <?= $filters['die_type'] === 'hollow' ? 'selected' : '' ?>><?= htmlspecialchars(t('hollow_die')) ?></option>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_customer')) ?></label>
      <select name="customer_id" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $filters['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_model')) ?></label>
      <select name="model_id" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <?php foreach ($models as $m): ?>
          <option value="<?= (int)$m['id'] ?>" <?= $filters['model_id'] === (string)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_engineer')) ?></label>
      <select name="engineer_id" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <?php foreach ($engineers as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= $filters['engineer_id'] === (string)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('filter_manager')) ?></label>
      <select name="manager_id" class="w-full border border-steel-300 rounded px-3 py-2 text-sm">
        <option value=""><?= htmlspecialchars(t('filter_all')) ?></option>
        <?php foreach ($managers as $m): ?>
          <option value="<?= (int)$m['id'] ?>" <?= $filters['manager_id'] === (string)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="flex items-center gap-3 mt-4">
    <button type="submit" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded"><?= htmlspecialchars(t('apply_filter')) ?></button>
    <a href="reports.php" class="px-4 py-2 border border-steel-300 rounded text-sm text-steel-600 hover:bg-steel-50"><?= htmlspecialchars(t('reset_filter')) ?></a>
  </div>
</form>

<?php if (empty($rows)): ?>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500">
    <?= htmlspecialchars(t('no_results_found')) ?>
  </div>
<?php else: ?>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft overflow-hidden">
    <table id="dataTable" class="w-full text-sm">
      <thead class="bg-steel-50 border-b border-steel-200 text-steel-500 text-xs uppercase tracking-wide">
        <tr>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('die_no_label')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_model')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_customer')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('die_type_solid_hollow')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('creator')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_manager')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('status')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('created_at')) ?></th>
          <th class="text-right px-4 py-3"><?= htmlspecialchars(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-steel-100">
        <?php foreach ($rows as $row): ?>
        <tr>
          <td class="px-4 py-3 font-tabular font-medium text-navy-900"><?= htmlspecialchars($row['die_no']) ?></td>
          <td class="px-4 py-3 font-tabular"><?= htmlspecialchars($row['model_name']) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($row['customer_name']) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars(t($row['die_type'] . '_die')) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($row['creator_name']) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($row['manager_name'] ?? '-') ?></td>
          <td class="px-4 py-3"><?= statusBadgeHtml($row['status']) ?></td>
          <td class="px-4 py-3 font-tabular text-steel-500"><?= htmlspecialchars(date('d M Y H:i', strtotime($row['created_at']))) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap space-x-3">
            <a href="<?= htmlspecialchars(APP_BASE_URL) ?>/api/export_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank" class="text-accent-600 hover:underline"><?= htmlspecialchars(t('export_pdf')) ?></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php
$exportCsvLabel = json_encode(t('export_csv'), JSON_UNESCAPED_UNICODE);
$extraScripts = <<<HTML
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (jQuery('#dataTable').length) {
    jQuery('#dataTable').DataTable({
      order: [[7, 'desc']],
      pageLength: 25,
      dom: 'Bfrtip',
      buttons: [
        { extend: 'csvHtml5', text: {$exportCsvLabel}, title: 'checklist_report', exportOptions: { columns: ':not(:last-child)' } },
      ],
    });
  }
});
</script>
HTML;

include __DIR__ . '/../includes/footer.php';
