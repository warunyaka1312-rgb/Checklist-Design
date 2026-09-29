<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';
require_once __DIR__ . '/../includes/report_helpers.php';

requireRole(['engineer', 'admin']);

$me = currentUser();
$pdo = getDbConnection();

$myKpi = fetchEngineerDashboardSummary($pdo, (int)$me['id']);
$sentTo = fetchEngineerSentToBreakdown($pdo, (int)$me['id']);

$rows = $pdo->query(
    "SELECT c.id, c.die_no, c.die_type, c.status, c.created_at, c.created_by,
            dm.name AS model_name, cu.name AS customer_name,
            creator.full_name AS creator_name,
            mgr.full_name AS manager_name
     FROM checklists c
     JOIN die_models dm ON dm.id = c.model_id
     JOIN customers cu ON cu.id = c.customer_id
     JOIN users creator ON creator.id = c.created_by
     LEFT JOIN users mgr ON mgr.id = c.assigned_manager_id
     ORDER BY c.created_at DESC"
)->fetchAll();

$pageTitle = t('nav_dashboard');
$extraHead = '<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h1 class="text-xl font-semibold text-navy-900"><?= htmlspecialchars(t('dashboard_welcome')) ?>, <?= htmlspecialchars($me['full_name']) ?></h1>
  </div>
  <a href="checklist_new.php" class="px-4 py-2 bg-accent-500 hover:bg-accent-600 text-white text-sm font-medium rounded">
    <?= htmlspecialchars(t('nav_create_checklist')) ?>
  </a>
</div>

<!-- My KPI: this engineer's own checklists this calendar month -->
<div class="mb-8">
  <h2 class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('my_kpi_this_month_title')) ?></h2>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
    <div class="stat-card rounded-xl shadow-soft p-6 text-white bg-[linear-gradient(135deg,#475569,#334155)]">
      <div class="text-xs font-medium uppercase tracking-wide opacity-90"><?= htmlspecialchars(t('card_my_draft')) ?></div>
      <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$myKpi['draft'] ?></div>
    </div>
    <div class="stat-card rounded-xl shadow-soft p-6 text-navy-900 bg-[linear-gradient(135deg,#f59e0b,#b45309)]">
      <div class="text-xs font-medium uppercase tracking-wide opacity-80"><?= htmlspecialchars(t('card_my_pending')) ?></div>
      <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$myKpi['pending'] ?></div>
    </div>
    <div class="stat-card rounded-xl shadow-soft p-6 text-white bg-[linear-gradient(135deg,#198754,#20c997)]">
      <div class="text-xs font-medium uppercase tracking-wide opacity-90"><?= htmlspecialchars(t('card_my_approved')) ?></div>
      <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$myKpi['approved'] ?></div>
    </div>
    <div class="stat-card rounded-xl shadow-soft p-6 text-white bg-[linear-gradient(135deg,#dc3545,#e35d6a)]">
      <div class="text-xs font-medium uppercase tracking-wide opacity-90"><?= htmlspecialchars(t('card_my_rejected')) ?></div>
      <div class="text-3xl font-bold font-tabular mt-2"><?= (int)$myKpi['rejected'] ?></div>
    </div>
  </div>

  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-4">
    <div class="text-xs font-semibold text-steel-500 uppercase tracking-wide mb-3"><?= htmlspecialchars(t('sent_to_title')) ?></div>
    <?php if (empty($sentTo)): ?>
      <div class="text-sm text-steel-500"><?= htmlspecialchars(t('no_sent_to_data')) ?></div>
    <?php else: ?>
      <div class="flex flex-wrap gap-3">
        <?php foreach ($sentTo as $s): ?>
          <div class="flex items-center gap-2 border border-steel-200 rounded-lg px-3 py-2">
            <span class="text-sm text-navy-900 font-medium"><?= htmlspecialchars($s['manager_name']) ?></span>
            <span class="status-pill bg-status-infoBg text-status-infoText font-tabular"><?= (int)$s['count'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-6 text-sm text-steel-500">
    <?= htmlspecialchars(t('no_checklists_yet')) ?>
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
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('status')) ?></th>
          <th class="text-left px-4 py-3"><?= htmlspecialchars(t('label_manager')) ?></th>
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
          <td class="px-4 py-3"><?= statusBadgeHtml($row['status']) ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($row['manager_name'] ?? '-') ?></td>
          <td class="px-4 py-3 font-tabular text-steel-500"><?= htmlspecialchars(date('d M Y H:i', strtotime($row['created_at']))) ?></td>
          <?php
          // Edit and delete are offered on the rows this user owns, and only
          // while the checklist is still theirs to change: a pending one sits
          // in a manager's queue and an approved one is the record of that
          // decision. Same rule the checklist page and the API enforce.
          $canManageRow = ((int)$row['created_by'] === (int)$me['id'] || $me['role'] === 'admin')
              && in_array($row['status'], ['draft', 'rejected'], true);
          ?>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <?php if ($row['status'] === 'approved'): ?>
              <button type="button" class="js-view-summary inline-flex items-center justify-center w-8 h-8 rounded-lg border border-steel-200 text-navy-700 hover:bg-steel-50 hover:border-steel-300 transition-colors mr-1" data-id="<?= (int)$row['id'] ?>" title="<?= htmlspecialchars(t('view_summary')) ?>" aria-label="<?= htmlspecialchars(t('view_summary')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <rect x="4" y="4" width="16" height="16" rx="2"/>
                  <path stroke-linecap="round" d="M8 9h8M8 12.5h8M8 16h5"/>
                </svg>
              </button>
            <?php endif; ?>
            <a href="checklist_view.php?id=<?= (int)$row['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-steel-200 text-accent-600 hover:bg-accent-50 hover:border-accent-200 transition-colors" title="<?= htmlspecialchars(t('view')) ?>" aria-label="<?= htmlspecialchars(t('view')) ?>">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
              </svg>
            </a>
            <?php if ($canManageRow): ?>
              <a href="checklist_view.php?id=<?= (int)$row['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-amber-200 text-amber-600 hover:bg-amber-50 hover:border-amber-300 transition-colors ml-1" title="<?= htmlspecialchars(t('edit')) ?>" aria-label="<?= htmlspecialchars(t('edit')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v4.5A2.25 2.25 0 0 1 17.25 21H5.25A2.25 2.25 0 0 1 3 18.75V6.75A2.25 2.25 0 0 1 5.25 4.5h4.5"/>
                </svg>
              </a>
              <button type="button" class="js-delete-checklist inline-flex items-center justify-center w-8 h-8 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 hover:border-red-300 transition-colors ml-1 disabled:opacity-50" data-id="<?= (int)$row['id'] ?>" data-die-no="<?= htmlspecialchars($row['die_no']) ?>" title="<?= htmlspecialchars(t('delete_checklist')) ?>" aria-label="<?= htmlspecialchars(t('delete_checklist')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                </svg>
              </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- Summary modal: quick read-only view for an approved checklist, opened
     from the "view summary" button above without leaving the list. -->
<div id="summaryModal" class="fixed inset-0 z-40 hidden items-center justify-center bg-navy-950/50 px-4">
  <div class="bg-white rounded-xl w-full max-w-3xl border border-steel-200 shadow-softLg max-h-[85vh] flex flex-col">
    <div class="px-5 py-4 border-b border-steel-200 flex items-center justify-between">
      <h2 class="font-semibold text-navy-900" id="summaryModalTitle"><?= htmlspecialchars(t('view_summary')) ?></h2>
      <button type="button" id="summaryModalClose" class="text-steel-400 hover:text-steel-600 text-xl leading-none">&times;</button>
    </div>
    <div class="px-5 py-4 overflow-y-auto" id="summaryModalBody">
      <div class="text-sm text-steel-500"><?= htmlspecialchars(t('loading')) ?></div>
    </div>
  </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (jQuery('#dataTable').length) {
    jQuery('#dataTable').DataTable({
      order: [[7, 'desc']],
      pageLength: 10,
    });
  }

  var modal = document.getElementById('summaryModal');
  var titleEl = document.getElementById('summaryModalTitle');
  var bodyEl = document.getElementById('summaryModalBody');
  var i18n = SUMMARY_I18N_PLACEHOLDER;

  function openModal() {
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }
  function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }
  document.getElementById('summaryModalClose').addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
  }

  // Delete a checklist the user owns. The button only renders for rows the
  // server already decided are deletable; the API re-checks ownership and
  // status, so a stale page cannot delete something it should not.
  document.querySelectorAll('.js-delete-checklist').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-id');
      var dieNo = btn.getAttribute('data-die-no') || '';
      if (!window.confirm(i18n.confirm_delete_checklist + '\n\n' + dieNo)) return;

      btn.disabled = true;
      fetch('../api/checklist_api.php?action=delete', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content,
        },
        body: JSON.stringify({ id: Number(id) }),
      })
        .then(function (res) { return res.json(); })
        .catch(function () { return { success: false, message: 'server_error' }; })
        .then(function (data) {
          if (data.success) {
            window.location.reload();
            return;
          }
          btn.disabled = false;
          window.alert(i18n[data.message] || data.message || i18n.server_error);
        });
    });
  });

  document.querySelectorAll('.js-view-summary').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-id');
      titleEl.textContent = i18n.view_summary;
      bodyEl.innerHTML = '<div class="text-sm text-steel-500">' + escapeHtml(i18n.loading) + '</div>';
      openModal();

      fetch('../api/checklist_summary_api.php?id=' + encodeURIComponent(id))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.success) {
            bodyEl.innerHTML = '<div class="text-sm text-red-600">' + escapeHtml(i18n.server_error) + '</div>';
            return;
          }
          titleEl.textContent = data.die_no;

          var imgHtml = data.design_image_url
            ? '<img src="' + escapeHtml(data.design_image_url) + '" class="w-full h-48 object-contain bg-steel-50 border border-steel-200 rounded mb-4">'
            : '<div class="w-full h-32 flex items-center justify-center bg-steel-50 border border-dashed border-steel-300 rounded mb-4 text-xs text-steel-400">' + escapeHtml(i18n.design_picture_title) + '</div>';

          var rowsHtml;
          if (!data.items.length) {
            rowsHtml = '<div class="text-sm text-steel-500">' + escapeHtml(i18n.no_items_selected_yet) + '</div>';
          } else {
            var trs = data.items.map(function (it) {
              var pillClass = it.result === 'fail' ? 'bg-status-dangerBg text-status-dangerText' : 'bg-status-successBg text-status-successText';
              var pillText = it.result === 'fail' ? i18n.result_fail : i18n.result_pass;
              var noteHtml;
              if (it.note_before) {
                var prevPillText = it.prev_result === 'fail' ? i18n.result_fail : i18n.result_pass;
                noteHtml = '<div class="text-xs text-steel-500">' + escapeHtml(i18n.label_note_before) + ' (' + escapeHtml(prevPillText) + '): ' + escapeHtml(it.note_before) + '</div>'
                  + '<div class="text-sm text-steel-700 mt-0.5">' + escapeHtml(i18n.label_note_after) + ' (' + escapeHtml(pillText) + '): ' + escapeHtml(it.note || i18n.no_image_short) + '</div>';
              } else {
                noteHtml = escapeHtml(it.note || i18n.no_image_short);
              }
              return '<tr class="border-b border-steel-100">'
                + '<td class="px-3 py-2 text-xs text-steel-500 font-tabular">' + it.no + '</td>'
                + '<td class="px-3 py-2 text-sm text-navy-900">' + escapeHtml(it.topic) + '</td>'
                + '<td class="px-3 py-2 text-center"><span class="status-pill ' + pillClass + '">' + escapeHtml(pillText) + '</span></td>'
                + '<td class="px-3 py-2 text-sm text-steel-600">' + noteHtml + '</td>'
                + '</tr>';
            }).join('');
            rowsHtml = '<table class="w-full text-sm border border-steel-200 rounded overflow-hidden table-fixed">'
              + '<thead class="bg-steel-50 text-steel-500 text-xs uppercase tracking-wide">'
              + '<tr><th class="text-left px-3 py-2 w-12">' + escapeHtml(i18n.table_col_no) + '</th>'
              + '<th class="text-left px-3 py-2 w-[28%]">' + escapeHtml(i18n.table_col_topic) + '</th>'
              + '<th class="text-center px-3 py-2 w-24">' + escapeHtml(i18n.table_col_result) + '</th>'
              + '<th class="text-left px-3 py-2">' + escapeHtml(i18n.table_col_notes) + '</th></tr>'
              + '</thead><tbody>' + trs + '</tbody></table>';
          }

          bodyEl.innerHTML = imgHtml + rowsHtml;
        })
        .catch(function () {
          bodyEl.innerHTML = '<div class="text-sm text-red-600">' + escapeHtml(i18n.server_error) + '</div>';
        });
    });
  });
});
</script>
HTML;

$summaryI18n = json_encode([
    'view_summary' => t('view_summary'),
    'loading' => t('loading'),
    'server_error' => t('server_error'),
    'table_col_no' => t('table_col_no'),
    'table_col_topic' => t('table_col_topic'),
    'table_col_result' => t('table_col_result'),
    'table_col_notes' => t('table_col_notes'),
    'result_pass' => t('result_pass'),
    'result_fail' => t('result_fail'),
    'no_image_short' => t('no_image_short'),
    'design_picture_title' => t('design_picture_title'),
    'no_items_selected_yet' => t('no_items_selected_yet'),
    'label_note_before' => t('label_note_before'),
    'label_note_after' => t('label_note_after'),
    // Used by the delete button below.
    'confirm_delete_checklist' => t('confirm_delete_checklist'),
    'server_error' => t('server_error'),
    'forbidden' => t('forbidden'),
    'locked' => t('locked'),
    'not_found' => t('not_found'),
    'invalid_csrf' => t('invalid_csrf'),
    'invalid_input' => t('invalid_input'),
], JSON_UNESCAPED_UNICODE);
$extraScripts = str_replace('SUMMARY_I18N_PLACEHOLDER', $summaryI18n, $extraScripts);

include __DIR__ . '/../includes/footer.php';
