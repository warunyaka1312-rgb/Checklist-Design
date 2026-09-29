<?php
require_once __DIR__ . '/includes/keycloak.php';

// Read before logoutUser() clears the session (it needs the stored id_token).
$keycloakLogout = keycloakLogoutUrl();

logoutUser();
header('Location: ' . ($keycloakLogout ?? APP_BASE_URL . '/index.php'));
exit;
