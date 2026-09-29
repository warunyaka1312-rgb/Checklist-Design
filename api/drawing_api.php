<?php
/**
 * JSON API: ดึงรูปภาพแบบ (Drawing) จากระบบภายนอกตาม td_no (ค่า Tech ของ checklist)
 * POST application/json: {"td_no": "3013-001"}
 * ตอบ {success:true, filename, url} — รูปแบบเดียวกับ upload_api.php (type=image)
 * Auth: session cookie, role engineer หรือ admin. ต้องมี X-CSRF-Token header
 * API key ของระบบภายนอกอยู่ใน includes/.env และไม่ถูกส่งออกไปที่เบราว์เซอร์
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/drawing_api.php';

header('Content-Type: application/json; charset=utf-8');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'unknown_action'], 400);
}

if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    respond(['success' => false, 'message' => 'invalid_csrf'], 403);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$tdNo = is_array($input) ? (string)($input['td_no'] ?? '') : '';

try {
    $r = fetchDrawingImage($tdNo);
    respond(['success' => true, 'filename' => $r['filename'], 'url' => $r['url']]);
} catch (DrawingApiException $e) {
    respond([
        'success' => false,
        'message' => $e->messageKey,
        'detail'  => APP_DEBUG ? $e->getMessage() : null,
    ], $e->httpStatus);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => 'server_error',
        'detail'  => APP_DEBUG ? $e->getMessage() : null,
    ], 500);
}
