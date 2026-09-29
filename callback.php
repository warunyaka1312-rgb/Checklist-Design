<?php
/**
 * Keycloak redirect target (KC_REDIRECT_URI, default {APP_BASE_URL}/callback.php).
 * Must be listed under "Valid redirect URIs" of the Keycloak client.
 */
require_once __DIR__ . '/includes/keycloak.php';

startSecureSession();

try {
    $role = keycloakHandleCallback();
    header('Location: ' . dashboardUrlForRole($role));
    exit;
} catch (KeycloakLoginException $e) {
    logKeycloak('Login rejected: ' . $e->getMessage());
    $_SESSION['login_error'] = ['key' => $e->langKey, 'detail' => $e->detail];
} catch (Throwable $e) {
    logKeycloak('Login failed: ' . $e->getMessage());
    $_SESSION['login_error'] = ['key' => 'error_sso_failed', 'detail' => ''];
}

header('Location: ' . APP_BASE_URL . '/index.php');
exit;
