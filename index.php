<?php
require_once __DIR__ . '/includes/keycloak.php';
require_once __DIR__ . '/includes/lang.php';

applyLangSwitchFromRequest();
startSecureSession();

$__existingUser = currentUser();
if ($__existingUser) {
    header('Location: ' . dashboardUrlForRole($__existingUser['role']));
    exit;
}

$error = null;
$errorDetail = '';

// Error handed back by callback.php after a failed Keycloak round-trip.
if (isset($_SESSION['login_error'])) {
    $error = (string)($_SESSION['login_error']['key'] ?? 'error_sso_failed');
    $errorDetail = (string)($_SESSION['login_error']['detail'] ?? '');
    unset($_SESSION['login_error']);
}

$ssoEnabled = keycloakEnabled();
$localLoginAllowed = localLoginAllowed();

if ($ssoEnabled && isset($_GET['sso'])) {
    try {
        keycloakBeginLogin(); // redirects + exits
    } catch (Throwable $e) {
        logKeycloak('Begin login failed: ' . $e->getMessage());
        $error = 'error_sso_unavailable';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $localLoginAllowed) {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'error_empty_fields';
    } else {
        $result = attemptLogin($username, $password);
        if ($result['success']) {
            header('Location: ' . dashboardUrlForRole($result['role']));
            exit;
        }
        $error = $result['message'];
    }
}

$lang = currentLang();
?>
<!doctype html>
<html lang="<?= $lang === 'th' ? 'th' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(t('login_heading')) ?> · <?= htmlspecialchars(APP_NAME) ?></title>
<?php include __DIR__ . '/includes/head_assets.php'; ?>
</head>
<body class="h-full text-steel-800 antialiased">
<div class="min-h-screen flex flex-col bg-[linear-gradient(135deg,#0f172a_0%,#172033_55%,#1d4ed8_100%)]">

  <div class="flex justify-end px-6 pt-6">
    <div class="flex items-center text-xs border border-white/15 rounded overflow-hidden">
      <a href="?lang=th" class="px-3 py-1.5 <?= $lang === 'th' ? 'bg-accent-500 text-white' : 'text-steel-300 hover:bg-white/10' ?>">TH</a>
      <a href="?lang=en" class="px-3 py-1.5 <?= $lang === 'en' ? 'bg-accent-500 text-white' : 'text-steel-300 hover:bg-white/10' ?>">EN</a>
    </div>
  </div>

  <div class="flex-1 flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-[920px] bg-white rounded-2xl shadow-softLg overflow-hidden grid grid-cols-1 min-[820px]:grid-cols-[42%_58%]">

      <!-- Brand panel -->
      <div class="relative overflow-hidden px-8 py-10 flex flex-col justify-center bg-[linear-gradient(135deg,#0f172a_0%,#172033_55%,#1d4ed8_100%)] text-white min-h-[220px]">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 rounded-full border border-white/15"></div>
        <div class="relative inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white/10 border border-white/20 text-white font-semibold text-lg mb-5">
          DD
        </div>
        <h1 class="relative text-white text-xl font-semibold tracking-tight leading-snug"><?= htmlspecialchars(t('app_name')) ?></h1>
        <p class="relative text-steel-300 text-sm mt-2"><?= htmlspecialchars(t('login_subtitle')) ?></p>
      </div>

      <!-- Form panel -->
      <div class="px-8 py-10">
        <div class="text-[11px] font-semibold text-accent-500 uppercase tracking-widest mb-2"><?= htmlspecialchars(t('app_short')) ?></div>
        <h2 class="text-xl font-semibold text-navy-900 mb-6"><?= htmlspecialchars(t('login_heading')) ?></h2>

        <?php if ($error): ?>
          <div class="text-sm text-status-dangerText bg-status-dangerBg border border-red-100 rounded-lg px-3 py-2 mb-4">
            <?= htmlspecialchars(t($error)) ?>
            <?php if ($errorDetail !== ''): ?>
              <span class="block text-xs mt-1 font-tabular"><?= htmlspecialchars(t('username')) ?>: <?= htmlspecialchars($errorDetail) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($ssoEnabled): ?>
          <div x-data="{ redirecting: false }">
            <a
              href="index.php?sso=1"
              @click="redirecting = true"
              :class="redirecting && 'opacity-60 pointer-events-none'"
              class="flex items-center justify-center gap-2 w-full bg-accent-500 hover:bg-accent-600 active:bg-accent-700 text-white text-sm font-medium rounded-lg px-4 py-2.5 transition-colors"
            >
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
              <span x-show="!redirecting"><?= htmlspecialchars(t('login_sso_button')) ?></span>
              <span x-show="redirecting" x-cloak><?= htmlspecialchars(t('login_sso_redirecting')) ?></span>
            </a>
            <p class="text-xs text-steel-500 mt-3"><?= htmlspecialchars(t('login_sso_hint')) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($localLoginAllowed): ?>
        <?php if ($ssoEnabled): ?>
        <details class="mt-6 border-t border-steel-200 pt-4" <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'open' : '' ?>>
          <summary class="text-xs text-steel-500 cursor-pointer select-none hover:text-steel-700"><?= htmlspecialchars(t('login_local_toggle')) ?></summary>
          <div class="mt-4">
        <?php endif; ?>
        <form method="post" action="index.php" class="space-y-4" x-data="{ submitting: false }" @submit="submitting = true">

          <div>
            <label for="username" class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('username')) ?></label>
            <input
              type="text"
              id="username"
              name="username"
              autocomplete="username"
              <?= $ssoEnabled ? '' : 'autofocus' ?>
              class="w-full border border-steel-300 rounded-lg px-3 py-2.5 text-sm font-tabular focus:outline-none focus:ring-2 focus:ring-accent-500/40 focus:border-accent-500"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            >
          </div>

          <div>
            <label for="password" class="block text-xs font-medium text-steel-500 mb-1"><?= htmlspecialchars(t('password')) ?></label>
            <input
              type="password"
              id="password"
              name="password"
              autocomplete="current-password"
              class="w-full border border-steel-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500/40 focus:border-accent-500"
            >
          </div>

          <button
            type="submit"
            :disabled="submitting"
            class="w-full bg-accent-500 hover:bg-accent-600 active:bg-accent-700 text-white text-sm font-medium rounded-lg px-4 py-2.5 disabled:opacity-60 transition-colors"
          >
            <span x-show="!submitting"><?= htmlspecialchars(t('login_button')) ?></span>
            <span x-show="submitting" x-cloak><?= htmlspecialchars(t('signing_in')) ?></span>
          </button>

        </form>
        <?php if ($ssoEnabled): ?>
          </div>
        </details>
        <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <p class="text-center text-xs text-steel-400 pb-6"><?= htmlspecialchars(t('footer_note')) ?></p>

</div>
</body>
</html>
