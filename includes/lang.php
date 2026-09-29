<?php
/**
 * TH/EN dictionary loader + helpers. Language choice is stored in the PHP
 * session (not a cookie), so it always reflects what the signed-in user
 * picked during this session. Dictionaries live in lang_th.php / lang_en.php.
 */

require_once __DIR__ . '/auth.php';

function currentLang(): string
{
    startSecureSession();
    $lang = $_SESSION['lang'] ?? 'th';
    return in_array($lang, ['th', 'en'], true) ? $lang : 'th';
}

function applyLangSwitchFromRequest(): void
{
    startSecureSession();
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en'], true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
}

function t(string $key): string
{
    static $dict = null;
    if ($dict === null) {
        $dict = [
            'th' => require __DIR__ . '/lang_th.php',
            'en' => require __DIR__ . '/lang_en.php',
        ];
    }

    $lang = currentLang();
    return $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
}

function roleLabel(string $role): string
{
    $map = [
        'admin' => t('role_admin'),
        'engineer' => t('role_engineer'),
        'manager' => t('role_manager'),
    ];
    return $map[$role] ?? $role;
}
