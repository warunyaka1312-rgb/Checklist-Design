<?php
/**
 * Type-ahead search for the Customer box on the checklist form.
 *
 * Searches the SAP Customer API by CardName and merges the hits with the
 * customers already in our own table, so the engineer sees one list:
 *   - customers that exist locally (these carry an id and are linked straight away)
 *   - customers that only exist in SAP so far (id = null, card_code set) — picking
 *     one makes api/checklist_api.php create the local row on save
 * Anything the engineer types that matches neither is still accepted by the form
 * and inserted as a plain local customer.
 *
 * GET api/customer_search_api.php?q=<term>
 *   -> {success:true, items:[{id,name,card_code,source}], truncated:bool,
 *       api_available:bool, api_error:?string}
 *
 * Read-only, so no CSRF token: it only reflects data the caller may already see.
 * The SAP list is fetched whole (one endpoint, ~800 rows) and cached on disk,
 * so typing does not hit the remote API on every keystroke.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_api.php';

header('Content-Type: application/json; charset=utf-8');

const CUSTOMER_SEARCH_LIMIT = 30;
const CUSTOMER_CACHE_TTL = 600; // seconds

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$me = currentUser();
if (!$me || !in_array($me['role'], ['engineer', 'admin'], true)) {
    respond(['success' => false, 'message' => 'forbidden'], 403);
}

/**
 * The SAP list, cached on disk. Returns [] and sets $error when the API cannot
 * be reached and no usable cache exists — the caller then falls back to local
 * customers only, so the form keeps working while SAP is down.
 */
function cachedApiCustomers(?string &$error): array
{
    $error = null;
    $dir = ROOT_PATH . '/logs/cache';
    $file = $dir . '/customers_api.json';

    if (is_file($file) && (time() - filemtime($file)) < CUSTOMER_CACHE_TTL) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    try {
        $rows = fetchCustomersFromApi();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Write via a temp file so a concurrent read never sees a half-written cache.
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($rows, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $file);
        }
        return $rows;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        // Stale cache beats nothing when the API is unreachable.
        if (is_file($file)) {
            $stale = json_decode((string)file_get_contents($file), true);
            if (is_array($stale)) {
                return $stale;
            }
        }
        return [];
    }
}

$query = trim((string)($_GET['q'] ?? ''));
$pdo = getDbConnection();

// --- local customers -------------------------------------------------------
if ($query === '') {
    $localStmt = $pdo->prepare('SELECT id, name, card_code FROM customers WHERE is_active = 1 ORDER BY name ASC LIMIT ?');
    $localStmt->bindValue(1, CUSTOMER_SEARCH_LIMIT + 1, PDO::PARAM_INT);
    $localStmt->execute();
} else {
    $localStmt = $pdo->prepare(
        'SELECT id, name, card_code FROM customers WHERE is_active = 1 AND name LIKE ? ORDER BY name ASC LIMIT ?'
    );
    // Escape the LIKE wildcards so a literal % or _ in the term stays literal.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query) . '%';
    $localStmt->bindValue(1, $like);
    $localStmt->bindValue(2, CUSTOMER_SEARCH_LIMIT + 1, PDO::PARAM_INT);
    $localStmt->execute();
}

$items = [];
$seenNames = [];   // lowercased name => index in $items
$seenCodes = [];   // card_code       => index in $items

foreach ($localStmt->fetchAll() as $row) {
    $key = mb_strtolower($row['name'], 'UTF-8');
    $items[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'card_code' => $row['card_code'],
        'source' => 'db',
    ];
    $seenNames[$key] = count($items) - 1;
    if ($row['card_code'] !== null && $row['card_code'] !== '') {
        $seenCodes[$row['card_code']] = count($items) - 1;
    }
}

// --- SAP customers ---------------------------------------------------------
// Only once something has been typed: the box says "type to search", and an
// empty query would otherwise return 30 arbitrary names out of ~800.
$apiError = null;
$apiRows = $query === '' ? [] : cachedApiCustomers($apiError);

foreach ($apiRows as $row) {
    $name = trim((string)($row['CardName'] ?? ''));
    $code = trim((string)($row['CardCode'] ?? ''));
    if ($name === '' || $code === '') {
        continue;
    }
    // Only customers SAP still lists as active, matching how the sync sets is_active.
    if (($row['Status'] ?? '') !== 'Active') {
        continue;
    }
    // Filter on CardName, which is what the engineer is typing.
    if ($query !== '' && mb_stripos($name, $query, 0, 'UTF-8') === false) {
        continue;
    }

    $key = mb_strtolower($name, 'UTF-8');
    if (isset($seenCodes[$code])) {
        continue; // already listed as a local row with this SAP code
    }
    if (isset($seenNames[$key])) {
        // Same customer, known locally without a code yet — remember the code so
        // saving links the existing row instead of making a second one.
        $idx = $seenNames[$key];
        if (($items[$idx]['card_code'] ?? null) === null) {
            $items[$idx]['card_code'] = $code;
        }
        continue;
    }

    $items[] = [
        'id' => null,           // not in our table yet; created on save
        'name' => $name,
        'card_code' => $code,
        'source' => 'api',
    ];
    $seenNames[$key] = count($items) - 1;
    $seenCodes[$code] = count($items) - 1;
}

// Names that begin with the term are the likelier intent, so show them first.
if ($query !== '') {
    usort($items, static function (array $a, array $b) use ($query): int {
        $aStarts = mb_stripos($a['name'], $query, 0, 'UTF-8') === 0 ? 0 : 1;
        $bStarts = mb_stripos($b['name'], $query, 0, 'UTF-8') === 0 ? 0 : 1;
        return $aStarts === $bStarts
            ? strnatcasecmp($a['name'], $b['name'])
            : $aStarts <=> $bStarts;
    });
}

$truncated = count($items) > CUSTOMER_SEARCH_LIMIT;

respond([
    'success' => true,
    'items' => array_slice($items, 0, CUSTOMER_SEARCH_LIMIT),
    'truncated' => $truncated,
    'api_available' => $apiError === null,
    'api_error' => APP_DEBUG ? $apiError : null,
]);
