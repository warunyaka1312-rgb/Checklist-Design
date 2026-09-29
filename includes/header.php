<?php
/**
 * Top-level HTML shell + top navbar (replaces the old header+sidebar combo —
 * navigation now lives here as a horizontal, role-filtered nav instead of a
 * left <aside>). Expected to be included by pages that have already called
 * requireLogin()/requireRole(). Optional variables a page may set before
 * including this file:
 *   $pageTitle    string  shown in <title>
 *   $extraHead    string  raw HTML appended just before </head>
 *
 * includes/sidebar.php is still included by every page right after this
 * file for backward compatibility; it now only opens <main> (no more
 * <aside>) since navigation moved up here.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lang.php';

applyLangSwitchFromRequest();

$__user = currentUser();
$__lang = currentLang();
$__role = $_SESSION['role'] ?? '';
$__currentScript = basename($_SERVER['SCRIPT_NAME']);

$__notifConfig = ['csrfToken' => csrfToken(), 'apiUrl' => APP_BASE_URL . '/api/notifications_api.php', 'initialUnreadCount' => 0, 'initialItems' => []];
if ($__user) {
    $__pdo = getDbConnection();

    $__notifListStmt = $__pdo->prepare('SELECT id, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
    $__notifListStmt->execute([$__user['id']]);
    $__notifConfig['initialItems'] = array_map(static function (array $n): array {
        return [
            'id' => (int)$n['id'],
            'message' => $n['message'],
            'link' => $n['link'],
            'is_read' => (int)$n['is_read'],
            'time_label' => date('d M Y H:i', strtotime($n['created_at'])),
        ];
    }, $__notifListStmt->fetchAll());

    $__notifCountStmt = $__pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $__notifCountStmt->execute([$__user['id']]);
    $__notifConfig['initialUnreadCount'] = (int)$__notifCountStmt->fetchColumn();
}

/**
 * Build the role-filtered nav item list once, shared by both the desktop
 * horizontal nav and the mobile collapse panel below.
 * Each item: ['href'=>?string, 'label'=>string, 'icon'=>string (svg path d),
 *             'active'=>bool, 'children'=>?array (same shape, no grandchildren)]
 */
$__navItems = [];

if (in_array($__role, ['admin', 'engineer', 'manager'], true)) {
    $__navItems[] = [
        'href' => APP_BASE_URL . '/' . $__role . '/dashboard.php',
        'label' => t('nav_dashboard'),
        'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 0 0 1 1h3m10-11l2 2m-2-2v10a1 1 0 0 1-1 1h-3m-6 0a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1m-6 0h6',
        'active' => $__currentScript === 'dashboard.php',
    ];
}

if ($__role === 'engineer') {
    $__navItems[] = [
        'href' => APP_BASE_URL . '/engineer/checklist_new.php',
        'label' => t('nav_create_checklist'),
        'icon' => 'M12 4.5v15m7.5-7.5h-15',
        'active' => $__currentScript === 'checklist_new.php',
    ];
}

if ($__role === 'manager') {
    $__navItems[] = [
        'href' => APP_BASE_URL . '/manager/pending_approval.php',
        'label' => t('nav_pending_approval'),
        'icon' => 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'active' => $__currentScript === 'pending_approval.php',
    ];
    $__navItems[] = [
        'href' => APP_BASE_URL . '/manager/approval_history.php',
        'label' => t('nav_approval_history'),
        'icon' => 'M4 4v6h6M20 20v-6h-6M5 15a7 7 0 0 0 12.9 3M19 9A7 7 0 0 0 6.1 6',
        'active' => $__currentScript === 'approval_history.php',
    ];
}

if ($__role === 'admin') {
    $__navItems[] = [
        'href' => APP_BASE_URL . '/admin/users.php',
        'label' => t('nav_manage_users'),
        'icon' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z',
        'active' => $__currentScript === 'users.php',
    ];
    $__navItems[] = [
        'href' => APP_BASE_URL . '/admin/reports.php',
        'label' => t('nav_reports'),
        'icon' => 'M9 17v-6m4 6V7m4 10v-3M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z',
        'active' => $__currentScript === 'reports.php',
    ];
    $__navItems[] = [
        'href' => APP_BASE_URL . '/admin/audit_log.php',
        'label' => t('nav_audit_log'),
        'icon' => 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'active' => $__currentScript === 'audit_log.php',
    ];

    $__masterDataScripts = ['customers.php', 'die_models.php', 'materials.php', 'tempers.php', 'approvers.php', 'checklist_items.php', 'api_settings.php'];
    $__navItems[] = [
        'href' => null,
        'label' => t('nav_manage_master_data'),
        'icon' => 'M4 7a2 2 0 0 1 2-2h3l2 2h7a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z',
        'active' => in_array($__currentScript, $__masterDataScripts, true),
        'children' => [
            [
                'href' => APP_BASE_URL . '/admin/customers.php',
                'label' => t('nav_manage_customers'),
                'icon' => 'M4 7h16M4 12h16M4 17h10',
                'active' => $__currentScript === 'customers.php',
            ],
            [
                'href' => APP_BASE_URL . '/admin/die_models.php',
                'label' => t('nav_manage_die_models'),
                'icon' => 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10l-8-4V7m8 10V7',
                'active' => $__currentScript === 'die_models.php',
            ],
            [
                'href' => APP_BASE_URL . '/admin/materials.php',
                'label' => t('nav_manage_materials'),
                'icon' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
                'active' => $__currentScript === 'materials.php',
            ],
            [
                'href' => APP_BASE_URL . '/admin/tempers.php',
                'label' => t('nav_manage_tempers'),
                'icon' => 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm-3-9v2m0 14v2m9-9h-2M5 12H3m14.5-6.5-1.4 1.4M7.9 16.1l-1.4 1.4m0-11.9 1.4 1.4m8.2 8.2 1.4 1.4',
                'active' => $__currentScript === 'tempers.php',
            ],
            [
                'href' => APP_BASE_URL . '/admin/approvers.php',
                'label' => t('nav_manage_approvers'),
                'icon' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z',
                'active' => $__currentScript === 'approvers.php',
            ],
            [
                'href' => APP_BASE_URL . '/admin/checklist_items.php',
                'label' => t('nav_manage_checklist_items'),
                'icon' => 'M9 12l2 2 4-4m5 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                'active' => $__currentScript === 'checklist_items.php',
            ],
            [
                'href' => APP_BASE_URL . '/admin/api_settings.php',
                'label' => t('nav_api_settings'),
                'icon' => 'M12 4.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm0 3v4.5l3 2',
                'active' => $__currentScript === 'api_settings.php',
            ],
        ],
    ];
}

function navIconSvg(string $iconPath, string $classes = 'w-4 h-4 shrink-0'): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" class="' . $classes . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">'
        . '<path stroke-linecap="round" stroke-linejoin="round" d="' . htmlspecialchars($iconPath) . '" /></svg>';
}

/** Row action icons (edit / deactivate / activate / delete / reset_password) for admin tables. */
function actionIconSvg(string $name): string
{
    $paths = [
        'edit' => 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10',
        'deactivate' => 'M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636',
        'activate' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'reset_password' => 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z',
        'delete' => 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.94-2.164-2.209-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.2 1.022-2.2 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
    ];
    return navIconSvg($paths[$name], 'w-5 h-5');
}

function renderTopNavLink(array $item): void
{
    $classes = $item['active']
        ? 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium bg-white/10 text-white'
        : 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-steel-300 hover:bg-white/10 hover:text-white transition-colors';
    ?>
    <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $classes ?>">
      <?= navIconSvg($item['icon']) ?>
      <span class="whitespace-nowrap"><?= htmlspecialchars($item['label']) ?></span>
    </a>
    <?php
}

function renderTopNavDropdown(array $item): void
{
    $btnClasses = $item['active']
        ? 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium bg-white/10 text-white'
        : 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-steel-300 hover:bg-white/10 hover:text-white transition-colors';
    ?>
    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
      <button type="button" @click="open = !open" class="<?= $btnClasses ?>">
        <?= navIconSvg($item['icon']) ?>
        <span class="whitespace-nowrap"><?= htmlspecialchars($item['label']) ?></span>
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
      </button>
      <div x-show="open" x-cloak class="absolute left-0 mt-1 w-60 bg-white border border-steel-200 rounded-xl shadow-softLg py-1 z-40">
        <?php foreach ($item['children'] as $child): ?>
          <?php
          $childClasses = $child['active']
              ? 'flex items-center gap-2 px-3 py-2 text-sm font-medium text-accent-500 bg-accent-50'
              : 'flex items-center gap-2 px-3 py-2 text-sm text-steel-700 hover:bg-steel-50';
          ?>
          <a href="<?= htmlspecialchars($child['href']) ?>" class="<?= $childClasses ?>">
            <?= navIconSvg($child['icon'], 'w-4 h-4 shrink-0 text-steel-400') ?>
            <span><?= htmlspecialchars($child['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

function renderMobileNavItem(array $item): void
{
    if (!empty($item['children'])) {
        ?>
        <div class="px-3 pt-3 pb-1 text-[11px] font-medium uppercase tracking-wide text-steel-400"><?= htmlspecialchars($item['label']) ?></div>
        <?php
        foreach ($item['children'] as $child) {
            renderMobileNavItem($child);
        }
        return;
    }
    $classes = $item['active']
        ? 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium bg-white/10 text-white'
        : 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-steel-300 hover:bg-white/10 hover:text-white';
    ?>
    <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $classes ?>">
      <?= navIconSvg($item['icon']) ?>
      <span><?= htmlspecialchars($item['label']) ?></span>
    </a>
    <?php
}
?>
<!doctype html>
<html lang="<?= $__lang === 'th' ? 'th' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' · ' : '' ?><?= htmlspecialchars(APP_NAME) ?></title>
<?php include __DIR__ . '/head_assets.php'; ?>
<script src="<?= htmlspecialchars(APP_BASE_URL) ?>/assets/js/notifications.js"></script>
<?php if (!empty($extraHead)) { echo $extraHead; } ?>
</head>
<body class="h-full bg-steel-50 text-steel-800 antialiased">
<div class="h-screen flex flex-col overflow-hidden" x-data="notificationCenter(<?= jsonForAttr($__notifConfig) ?>)" x-init="init()">

  <header class="shrink-0 bg-navy-900 shadow-soft relative z-20 no-print" x-data="{ mobileNavOpen: false }" @click.outside="mobileNavOpen = false">
    <div class="h-14 flex items-center gap-2 px-4 sm:px-6">
      <span class="font-semibold text-white tracking-tight whitespace-nowrap mr-2"><?= htmlspecialchars(t('app_short')) ?></span>

      <nav class="hidden lg:flex items-center gap-1">
        <?php foreach ($__navItems as $__item): ?>
          <?php if (!empty($__item['children'])): ?>
            <?php renderTopNavDropdown($__item); ?>
          <?php else: ?>
            <?php renderTopNavLink($__item); ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>

      <div class="flex-1"></div>

      <div class="hidden lg:flex items-center gap-4 shrink-0">
        <div class="flex items-center text-xs border border-white/15 rounded overflow-hidden">
          <a href="?lang=th" class="px-2 py-1 <?= $__lang === 'th' ? 'bg-accent-500 text-white' : 'text-steel-300 hover:bg-white/10' ?>">TH</a>
          <a href="?lang=en" class="px-2 py-1 <?= $__lang === 'en' ? 'bg-accent-500 text-white' : 'text-steel-300 hover:bg-white/10' ?>">EN</a>
        </div>

        <div class="relative">
          <button @click="toggle()" type="button" class="relative p-2 rounded hover:bg-white/10 text-steel-300 hover:text-white" aria-label="<?= htmlspecialchars(t('notifications')) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9" />
            </svg>
            <span
              x-show="unreadCount > 0"
              x-cloak
              x-text="unreadCount > 99 ? '99+' : unreadCount"
              class="absolute -top-0.5 -right-0.5 min-w-[16px] px-1 py-0.5 rounded-full bg-red-600 text-white text-[10px] leading-none text-center font-medium"
            ></span>
          </button>
          <div
            x-cloak
            x-show="notifOpen"
            @click.outside="notifOpen = false"
            class="absolute right-0 mt-2 w-80 bg-white border border-steel-200 rounded-xl shadow-softLg z-30"
          >
            <div class="flex items-center justify-between px-4 py-3 border-b border-steel-100 gap-3">
              <span class="text-xs font-medium text-steel-500 uppercase tracking-wide shrink-0"><?= htmlspecialchars(t('notifications')) ?></span>
              <div class="flex items-center gap-3">
                <button type="button" @click="markAllRead()" x-show="unreadCount > 0" x-cloak class="text-xs text-accent-600 hover:underline whitespace-nowrap"><?= htmlspecialchars(t('mark_all_read')) ?></button>
                <button type="button" @click="markAllReadAndDelete()" x-show="items.length > 0" x-cloak class="text-xs text-red-600 hover:underline whitespace-nowrap"><?= htmlspecialchars(t('mark_all_read_and_delete')) ?></button>
              </div>
            </div>
            <div class="max-h-80 overflow-y-auto divide-y divide-steel-100">
              <template x-for="n in items" :key="n.id">
                <a :href="n.link || '#'" @click="onClickItem(n, $event)" class="block px-4 py-3 hover:bg-steel-50" :class="!n.is_read ? 'bg-accent-50' : ''">
                  <div class="text-sm text-steel-800" x-text="n.message"></div>
                  <div class="text-xs text-steel-400 mt-1 font-tabular" x-text="n.time_label"></div>
                </a>
              </template>
            </div>
            <div x-show="items.length === 0" class="px-4 py-6 text-sm text-steel-400 text-center"><?= htmlspecialchars(t('no_notifications')) ?></div>
          </div>
        </div>

        <div class="h-6 w-px bg-white/15"></div>

        <div class="text-right leading-tight">
          <div class="text-sm font-medium text-white"><?= htmlspecialchars($__user['full_name'] ?? '') ?></div>
          <div class="text-xs text-steel-400"><?= htmlspecialchars(roleLabel($__user['role'] ?? '')) ?></div>
        </div>

        <a href="<?= htmlspecialchars(APP_BASE_URL) ?>/logout.php" class="text-sm px-3 py-1.5 border border-white/15 rounded text-steel-200 hover:bg-white/10 hover:text-white whitespace-nowrap">
          <?= htmlspecialchars(t('logout')) ?>
        </a>
      </div>

      <button type="button" @click="mobileNavOpen = !mobileNavOpen" class="lg:hidden p-2 rounded text-steel-300 hover:bg-white/10 hover:text-white" aria-label="Menu">
        <svg x-show="!mobileNavOpen" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5m-16.5 5.25h16.5m-16.5 5.25h16.5" /></svg>
        <svg x-show="mobileNavOpen" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Mobile nav + account panel -->
    <div x-show="mobileNavOpen" x-cloak class="lg:hidden border-t border-white/10 px-4 pb-4 pt-2 space-y-1 max-h-[calc(100vh-3.5rem)] overflow-y-auto">
      <?php foreach ($__navItems as $__item): ?>
        <?php renderMobileNavItem($__item); ?>
      <?php endforeach; ?>

      <div class="border-t border-white/10 mt-2 pt-3 flex items-center justify-between">
        <div class="text-xs">
          <div class="font-medium text-white"><?= htmlspecialchars($__user['full_name'] ?? '') ?></div>
          <div class="text-steel-400"><?= htmlspecialchars(roleLabel($__user['role'] ?? '')) ?></div>
        </div>
        <div class="flex items-center text-xs border border-white/15 rounded overflow-hidden">
          <a href="?lang=th" class="px-2 py-1 <?= $__lang === 'th' ? 'bg-accent-500 text-white' : 'text-steel-300' ?>">TH</a>
          <a href="?lang=en" class="px-2 py-1 <?= $__lang === 'en' ? 'bg-accent-500 text-white' : 'text-steel-300' ?>">EN</a>
        </div>
      </div>
      <a href="<?= htmlspecialchars(APP_BASE_URL) ?>/logout.php" class="block text-center mt-2 text-sm px-3 py-2 border border-white/15 rounded-lg text-steel-200 hover:bg-white/10">
        <?= htmlspecialchars(t('logout')) ?>
      </a>
    </div>
  </header>

  <div class="flex flex-1 overflow-hidden">
