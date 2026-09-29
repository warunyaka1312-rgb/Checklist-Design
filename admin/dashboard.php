<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/report_helpers.php';

requireRole('admin');

$me = currentUser();
$pdo = getDbConnection();

$summary = fetchDashboardSummary($pdo);
$statusThisMonth = fetchStatusBreakdownThisMonth($pdo);
$trend = fetchMonthlyTrend($pdo, 12);
$rejectRate = fetchMonthlyRejectRate($pdo, 12);
$topFailing = fetchTopFailingItems($pdo, 10);

$chartData = [
    'statusThisMonth' => [
        'labels' => [t('status_pending'), t('status_approved'), t('status_rejected')],
        'values' => [$statusThisMonth['pending'], $statusThisMonth['approved'], $statusThisMonth['rejected']],
    ],
    'trend' => [
        'labels' => array_column($trend, 'label'),
        'values' => array_column($trend, 'count'),
    ],
    'rejectRate' => [
        'labels' => array_column($rejectRate, 'label'),
        'values' => array_column($rejectRate, 'rate'),
    ],
    'topFailing' => [
        'labels' => array_column($topFailing, 'topic'),
        'values' => array_column($topFailing, 'fail_count'),
    ],
];

$pageTitle = t('nav_dashboard');
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<h1 class="text-xl font-semibold text-navy-900 mb-6"><?= htmlspecialchars(t('dashboard_welcome')) ?>, <?= htmlspecialchars($me['full_name']) ?></h1>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
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

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-5">
    <div class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('chart_status_this_month')) ?></div>
    <div class="relative" style="height:260px;"><canvas id="chartStatusThisMonth"></canvas></div>
  </div>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-5">
    <div class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('chart_monthly_trend')) ?></div>
    <div class="relative" style="height:260px;"><canvas id="chartTrend"></canvas></div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-5">
    <div class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('chart_reject_rate')) ?></div>
    <div class="relative" style="height:280px;"><canvas id="chartRejectRate"></canvas></div>
  </div>
  <div class="bg-white border border-steel-200 rounded-xl shadow-soft p-5">
    <div class="text-sm font-semibold text-navy-900 mb-3"><?= htmlspecialchars(t('chart_top_failing_items')) ?></div>
    <div class="relative" style="height:280px;"><canvas id="chartTopFailing"></canvas></div>
  </div>
</div>

<?php
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const chartData = ' . json_encode($chartData, JSON_UNESCAPED_UNICODE) . ';
const noDataLabel = ' . json_encode(t('no_chart_data'), JSON_UNESCAPED_UNICODE) . ';
const chartLabels = ' . json_encode([
    'checklists' => t('chart_label_checklists'),
    'rejectRate' => t('chart_label_reject_rate'),
    'failCount' => t('chart_label_fail_count'),
], JSON_UNESCAPED_UNICODE) . ';

function showNoData(canvasId, label) {
  const el = document.getElementById(canvasId);
  const wrap = el.parentElement;
  wrap.innerHTML = \'<div class="h-full flex items-center justify-center text-sm text-steel-400">\' + label + \'</div>\';
}

document.addEventListener("DOMContentLoaded", function () {
  const statusColors = ["#D97706", "#16A34A", "#DC2626"];

  if (chartData.statusThisMonth.values.reduce((a, b) => a + b, 0) === 0) {
    showNoData("chartStatusThisMonth", noDataLabel);
  } else {
    new Chart(document.getElementById("chartStatusThisMonth"), {
      type: "doughnut",
      data: {
        labels: chartData.statusThisMonth.labels,
        datasets: [{ data: chartData.statusThisMonth.values, backgroundColor: statusColors }],
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } },
    });
  }

  new Chart(document.getElementById("chartTrend"), {
    type: "line",
    data: {
      labels: chartData.trend.labels,
      datasets: [{
        label: chartLabels.checklists,
        data: chartData.trend.values,
        borderColor: "#2563EB",
        backgroundColor: "rgba(37,99,235,0.1)",
        tension: 0.3,
        fill: true,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });

  new Chart(document.getElementById("chartRejectRate"), {
    type: "bar",
    data: {
      labels: chartData.rejectRate.labels,
      datasets: [{ label: chartLabels.rejectRate, data: chartData.rejectRate.values, backgroundColor: "#DC2626" }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + "%" } } },
    },
  });

  if (chartData.topFailing.values.length === 0) {
    showNoData("chartTopFailing", noDataLabel);
  } else {
    new Chart(document.getElementById("chartTopFailing"), {
      type: "bar",
      data: {
        labels: chartData.topFailing.labels,
        datasets: [{ label: chartLabels.failCount, data: chartData.topFailing.values, backgroundColor: "#7C3AED" }],
      },
      options: {
        indexAxis: "y",
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }
});
</script>';

include __DIR__ . '/../includes/footer.php';
