<?php
/**
 * File upload endpoint for the checklist workflow.
 * POST multipart/form-data: type=image|pdf, file=<binary>
 * Auth: session cookie, role engineer or admin. Requires X-CSRF-Token header.
 *
 * The file extension is never trusted — the actual content is inspected with
 * fileinfo (finfo) and only a fixed whitelist of real MIME types is accepted.
 */

require_once __DIR__ . '/../includes/auth.php';

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

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!verifyCsrfToken($csrf)) {
    respond(['success' => false, 'message' => 'invalid_csrf'], 403);
}

$type = $_POST['type'] ?? '';
if (!in_array($type, ['image', 'pdf'], true)) {
    respond(['success' => false, 'message' => 'invalid_input'], 422);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    respond(['success' => false, 'message' => 'upload_failed'], 422);
}

$file = $_FILES['file'];

$maxSize = $type === 'image' ? 5 * 1024 * 1024 : 20 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    respond(['success' => false, 'message' => 'file_too_large'], 422);
}

$allowedMimes = $type === 'image'
    ? ['image/jpeg' => 'jpg', 'image/png' => 'png']
    : ['application/pdf' => 'pdf'];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if ($mime === false || !isset($allowedMimes[$mime])) {
    respond(['success' => false, 'message' => 'invalid_file_type'], 422);
}

$ext = $allowedMimes[$mime];
$filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destDir = $type === 'image' ? UPLOAD_PATH_IMAGES : UPLOAD_PATH_PDF;
$destUrl = $type === 'image' ? UPLOAD_URL_IMAGES : UPLOAD_URL_PDF;

if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
    respond(['success' => false, 'message' => 'server_error'], 500);
}

$destPath = $destDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond(['success' => false, 'message' => 'server_error'], 500);
}

respond(['success' => true, 'filename' => $filename, 'url' => $destUrl . '/' . $filename]);
