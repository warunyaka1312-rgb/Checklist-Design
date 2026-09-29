<?php
/**
 * Keycloak SSO (OpenID Connect, Authorization Code + PKCE).
 *
 * Keycloak decides who the user is AND their role: the client roles of
 * KC_CLIENT_ID (admin / engineer / manager) are mapped on every login, and the
 * local users row is created on first login / overwritten after that. A user
 * with none of those roles is refused. is_active and approver assignments are
 * still managed locally (Admin > Users / Approvers).
 *
 * Flow:
 *   index.php?sso=1  -> keycloakBeginLogin()    : redirect to Keycloak
 *   callback.php     -> keycloakHandleCallback() : verify state, exchange code,
 *                                                  verify id_token (RS256/JWKS),
 *                                                  start the local session
 *   logout.php       -> keycloakLogoutUrl()      : also end the Keycloak session
 *
 * Settings come from includes/.env (see .env.example): KC_SERVER, KC_REALM,
 * KC_CLIENT_ID, KC_CLIENT_SECRET, KC_REDIRECT_URI, KC_SSL_VERIFY, KC_TIMEOUT,
 * KC_ALLOW_LOCAL_LOGIN. No Composer / external library.
 */

require_once __DIR__ . '/auth.php';

/**
 * Thrown for failures the login page should explain to the user. $langKey is
 * a lang_*.php key; $detail is optional extra text (e.g. the username).
 */
class KeycloakLoginException extends RuntimeException
{
    public function __construct(public readonly string $langKey, public readonly string $detail = '', string $logMessage = '')
    {
        parent::__construct($logMessage !== '' ? $logMessage : $langKey);
    }
}

function keycloakEnabled(): bool
{
    return (string)env('KC_SERVER') !== ''
        && (string)env('KC_REALM') !== ''
        && (string)env('KC_CLIENT_ID') !== '';
}

/** Local username/password form: always when Keycloak is off, otherwise only if KC_ALLOW_LOCAL_LOGIN=true. */
function localLoginAllowed(): bool
{
    return !keycloakEnabled() || keycloakEnvBool('KC_ALLOW_LOCAL_LOGIN', false);
}

function keycloakIssuer(): string
{
    return rtrim((string)env('KC_SERVER'), '/') . '/realms/' . rawurlencode((string)env('KC_REALM'));
}

function keycloakRedirectUri(): string
{
    $uri = (string)env('KC_REDIRECT_URI');
    return $uri !== '' ? $uri : APP_BASE_URL . '/callback.php';
}

function keycloakEnvBool(string $key, bool $default): bool
{
    $raw = env($key);
    if ($raw === false || $raw === '') {
        return $default;
    }
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

/** logs/keycloak.log — never write tokens or the client secret here. */
function logKeycloak(string $line): void
{
    $dir = ROOT_PATH . '/logs';
    if (!is_dir($dir)) {
        return;
    }
    @file_put_contents($dir . '/keycloak.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------
// Login / callback / logout
// ---------------------------------------------------------------------

/** Redirect the browser to the Keycloak login page. Does not return. */
function keycloakBeginLogin(): never
{
    startSecureSession();

    $doc = keycloakDiscovery();
    $verifier = keycloakB64Url(random_bytes(32));
    $state = keycloakB64Url(random_bytes(24));
    $nonce = keycloakB64Url(random_bytes(24));

    $_SESSION['oidc'] = [
        'state' => $state,
        'nonce' => $nonce,
        'code_verifier' => $verifier,
        'created_at' => time(),
    ];

    $params = [
        'client_id' => (string)env('KC_CLIENT_ID'),
        'redirect_uri' => keycloakRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => keycloakB64Url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'ui_locales' => function_exists('currentLang') ? currentLang() : 'th',
    ];

    header('Location: ' . $doc['authorization_endpoint'] . '?' . http_build_query($params));
    exit;
}

/**
 * Complete the round-trip from Keycloak and start the local session.
 *
 * @return string the role of the signed-in user
 * @throws KeycloakLoginException
 */
function keycloakHandleCallback(): string
{
    startSecureSession();

    $oidc = $_SESSION['oidc'] ?? null;
    unset($_SESSION['oidc']); // single use, whatever happens next

    if (!empty($_GET['error'])) {
        throw new KeycloakLoginException('error_sso_failed', '', 'Keycloak returned error: ' . $_GET['error'] . ' ' . ($_GET['error_description'] ?? ''));
    }

    $code = (string)($_GET['code'] ?? '');
    $state = (string)($_GET['state'] ?? '');
    if ($code === '' || $state === '') {
        throw new KeycloakLoginException('error_sso_failed', '', 'Callback missing code or state');
    }
    if (!is_array($oidc) || empty($oidc['state']) || !hash_equals((string)$oidc['state'], $state)) {
        throw new KeycloakLoginException('error_sso_failed', '', 'State mismatch (expired session or possible CSRF)');
    }
    if (time() - (int)($oidc['created_at'] ?? 0) > 600) {
        throw new KeycloakLoginException('error_sso_failed', '', 'Login attempt older than 10 minutes');
    }

    $tokens = keycloakExchangeCode($code, (string)$oidc['code_verifier']);
    $claims = keycloakVerifyIdToken((string)$tokens['id_token'], (string)$oidc['nonce']);

    $username = trim((string)($claims['preferred_username'] ?? ''));
    if ($username === '') {
        throw new KeycloakLoginException('error_sso_failed', '', 'id_token has no preferred_username');
    }

    $role = keycloakAppRole(keycloakClientRoles($claims, (string)($tokens['access_token'] ?? '')));
    if ($role === null) {
        throw new KeycloakLoginException('error_sso_no_role', $username, 'No Checklist client role in Keycloak for "' . $username . '"');
    }

    $user = keycloakSyncLocalUser($username, keycloakFullName($claims, $username), $role);
    if ((int)$user['is_active'] !== 1) {
        throw new KeycloakLoginException('error_inactive_account', '', 'Inactive local user "' . $username . '"');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['auth_source'] = 'keycloak';
    // Kept only as id_token_hint so logout can end the Keycloak session too.
    $_SESSION['kc_id_token'] = (string)$tokens['id_token'];

    logKeycloak('Login OK: ' . $username . ' (' . $user['role'] . ')');

    return $user['role'];
}

// ---------------------------------------------------------------------
// Roles & local user sync
// ---------------------------------------------------------------------

/**
 * Client roles of this app (resource_access.{KC_ROLE_CLIENT}.roles). Keycloak
 * puts them in the access_token by default, and in the id_token only when the
 * "client roles" mapper has "Add to ID token" on — so read both.
 *
 * @return string[]
 */
function keycloakClientRoles(array $idClaims, string $accessToken): array
{
    $client = (string)(env('KC_ROLE_CLIENT') ?: env('KC_CLIENT_ID'));
    $roles = (array)($idClaims['resource_access'][$client]['roles'] ?? []);

    if ($accessToken !== '') {
        $access = keycloakVerifyJwt($accessToken);
        if (($access['azp'] ?? '') !== (string)env('KC_CLIENT_ID')) {
            throw new RuntimeException('access_token: azp mismatch');
        }
        $roles = array_merge($roles, (array)($access['resource_access'][$client]['roles'] ?? []));
    }

    return array_values(array_unique(array_map('strval', $roles)));
}

/**
 * Map Keycloak client roles to this app's single role. Role names can be
 * renamed via KC_ROLE_ADMIN / KC_ROLE_MANAGER / KC_ROLE_ENGINEER. When a user
 * holds several, the highest wins: admin > manager > engineer.
 */
function keycloakAppRole(array $kcRoles): ?string
{
    $map = [
        'admin' => (string)(env('KC_ROLE_ADMIN') ?: 'admin'),
        'manager' => (string)(env('KC_ROLE_MANAGER') ?: 'manager'),
        'engineer' => (string)(env('KC_ROLE_ENGINEER') ?: 'engineer'),
    ];
    foreach ($map as $appRole => $kcRole) {
        if (in_array($kcRole, $kcRoles, true)) {
            return $appRole;
        }
    }
    return null;
}

function keycloakFullName(array $claims, string $fallback): string
{
    $name = trim((string)($claims['name'] ?? ''));
    if ($name === '') {
        $name = trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? ''));
    }
    return mb_substr($name !== '' ? $name : $fallback, 0, 150);
}

/**
 * Create the local users row on first login, otherwise overwrite role and
 * full_name with what Keycloak says. The row is still needed because
 * checklists, approvers and notifications reference users.id. is_active stays
 * local so an admin can still block someone from this app only.
 *
 * @return array{id: int|string, username: string, full_name: string, role: string, is_active: int|string}
 */
function keycloakSyncLocalUser(string $username, string $fullName, string $role): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT id, username, full_name, role, is_active FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        // Random, never-shown password: Keycloak users don't sign in with the local form.
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$username, $hash, $fullName, $role]);
        logKeycloak('Created local user "' . $username . '" (' . $role . ')');
        return ['id' => (int)$pdo->lastInsertId(), 'username' => $username, 'full_name' => $fullName, 'role' => $role, 'is_active' => 1];
    }

    if ($user['role'] !== $role || $user['full_name'] !== $fullName) {
        $pdo->prepare('UPDATE users SET role = ?, full_name = ? WHERE id = ?')
            ->execute([$role, $fullName, $user['id']]);
        if ($user['role'] !== $role) {
            logKeycloak('Role of "' . $username . '" changed ' . $user['role'] . ' -> ' . $role . ' (from Keycloak)');
        }
        $user['role'] = $role;
        $user['full_name'] = $fullName;
    }
    return $user;
}

/**
 * Keycloak end-session URL for a user who signed in via Keycloak, or null to
 * fall back to the local login page. Call BEFORE logoutUser() wipes the session.
 */
function keycloakLogoutUrl(): ?string
{
    startSecureSession();
    $idToken = (string)($_SESSION['kc_id_token'] ?? '');
    if ($idToken === '' || !keycloakEnabled()) {
        return null;
    }

    try {
        $endpoint = (string)(keycloakDiscovery()['end_session_endpoint'] ?? '');
    } catch (Throwable $e) {
        logKeycloak('Logout: discovery failed — ' . $e->getMessage());
        return null;
    }
    if ($endpoint === '') {
        return null;
    }

    return $endpoint . '?' . http_build_query([
        'client_id' => (string)env('KC_CLIENT_ID'),
        'id_token_hint' => $idToken,
        'post_logout_redirect_uri' => APP_BASE_URL . '/index.php',
    ]);
}

// ---------------------------------------------------------------------
// Protocol helpers
// ---------------------------------------------------------------------

/** @return array<string, mixed> OIDC discovery document (cached per request) */
function keycloakDiscovery(): array
{
    static $doc = null;
    if ($doc !== null) {
        return $doc;
    }

    $data = json_decode(keycloakHttp(keycloakIssuer() . '/.well-known/openid-configuration'), true);
    if (!is_array($data) || empty($data['authorization_endpoint']) || empty($data['token_endpoint']) || empty($data['jwks_uri'])) {
        throw new RuntimeException('Invalid OIDC discovery document');
    }
    return $doc = $data;
}

/** @return array<string, mixed> token response (contains id_token) */
function keycloakExchangeCode(string $code, string $codeVerifier): array
{
    $body = keycloakHttp(keycloakDiscovery()['token_endpoint'], [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => keycloakRedirectUri(),
        'client_id' => (string)env('KC_CLIENT_ID'),
        'client_secret' => (string)env('KC_CLIENT_SECRET'),
        'code_verifier' => $codeVerifier,
    ]);

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['id_token'])) {
        throw new RuntimeException('Token response missing id_token');
    }
    return $data;
}

/**
 * Verify an RS256 id_token against the realm JWKS and check iss/aud/exp/nonce.
 *
 * @return array<string, mixed> verified claims
 */
function keycloakVerifyIdToken(string $idToken, string $nonce): array
{
    $claims = keycloakVerifyJwt($idToken, 'id_token');

    $aud = $claims['aud'] ?? null;
    if (!in_array((string)env('KC_CLIENT_ID'), is_array($aud) ? $aud : [$aud], true)) {
        throw new RuntimeException('id_token: audience mismatch');
    }
    if (!hash_equals($nonce, (string)($claims['nonce'] ?? ''))) {
        throw new RuntimeException('id_token: nonce mismatch');
    }

    return $claims;
}

/**
 * Verify an RS256 JWT from this realm: signature (JWKS), iss and exp.
 *
 * @return array<string, mixed> verified claims
 */
function keycloakVerifyJwt(string $jwt, string $label = 'access_token'): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException($label . ': invalid JWT format');
    }
    [$h64, $p64, $s64] = $parts;

    $header = json_decode(keycloakB64UrlDecode($h64), true);
    $claims = json_decode(keycloakB64UrlDecode($p64), true);
    if (!is_array($header) || !is_array($claims)) {
        throw new RuntimeException($label . ': malformed segments');
    }
    if (($header['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException($label . ': only RS256 is accepted, got ' . ($header['alg'] ?? '?'));
    }

    $pem = keycloakSigningKeyPem((string)($header['kid'] ?? ''));
    if ($pem === null) {
        throw new RuntimeException($label . ': signing key not found in JWKS');
    }
    if (openssl_verify($h64 . '.' . $p64, keycloakB64UrlDecode($s64), $pem, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException($label . ': bad signature');
    }

    if (rtrim((string)($claims['iss'] ?? ''), '/') !== rtrim((string)keycloakDiscovery()['issuer'], '/')) {
        throw new RuntimeException($label . ': issuer mismatch');
    }
    if ((int)($claims['exp'] ?? 0) < time() - 30) { // 30s clock skew
        throw new RuntimeException($label . ': expired');
    }

    return $claims;
}

/** PEM public key for the JWKS entry with this kid, or null. */
function keycloakSigningKeyPem(string $kid): ?string
{
    static $jwks = null; // id_token and access_token share one fetch
    if ($kid === '') {
        return null;
    }
    $jwks ??= json_decode(keycloakHttp(keycloakDiscovery()['jwks_uri']), true);
    foreach (($jwks['keys'] ?? []) as $key) {
        if (($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA' && !empty($key['n']) && !empty($key['e'])) {
            return keycloakRsaJwkToPem((string)$key['n'], (string)$key['e']);
        }
    }
    return null;
}

/**
 * GET (or form POST when $postFields is given) over cURL. TLS verification
 * follows KC_SSL_VERIFY so an internal CA / self-signed cert can still work.
 */
function keycloakHttp(string $url, ?array $postFields = null): string
{
    $sslVerify = keycloakEnvBool('KC_SSL_VERIFY', true);
    $timeout = (int)(env('KC_TIMEOUT') ?: 10);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($postFields !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $http >= 400) {
        $hint = $body !== false ? mb_substr((string)$body, 0, 300) : $err;
        throw new RuntimeException('Keycloak HTTP ' . $http . ' for ' . strtok($url, '?') . ': ' . $hint);
    }
    return (string)$body;
}

function keycloakB64Url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function keycloakB64UrlDecode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return (string)base64_decode(strtr($data, '-_', '+/'), true);
}

/** RSA JWK (n, e) -> PEM SubjectPublicKeyInfo usable by openssl_verify(). */
function keycloakRsaJwkToPem(string $nB64u, string $eB64u): string
{
    $asn1Len = static function (int $n): string {
        if ($n < 128) {
            return chr($n);
        }
        $bytes = '';
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    };
    $asn1Int = static function (string $bytes) use ($asn1Len): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) >= 0x80) {
            $bytes = "\x00" . $bytes; // keep it positive
        }
        return "\x02" . $asn1Len(strlen($bytes)) . $bytes;
    };
    $asn1Seq = static fn(string $body): string => "\x30" . $asn1Len(strlen($body)) . $body;

    $rsaKey = $asn1Seq($asn1Int(keycloakB64UrlDecode($nB64u)) . $asn1Int(keycloakB64UrlDecode($eB64u)));
    $algId = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00"; // rsaEncryption, NULL
    $bitString = "\x03" . $asn1Len(strlen($rsaKey) + 1) . "\x00" . $rsaKey;

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($asn1Seq($algId . $bitString)), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}
