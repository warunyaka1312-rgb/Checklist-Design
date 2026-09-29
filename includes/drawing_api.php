<?php
/**
 * Drawing image API — ดึงรูปภาพแบบ (Drawing) จากระบบภายนอกมาเก็บเป็นไฟล์ในระบบนี้
 *
 * ขั้นตอน:
 *   1) GET {IMAGE_FETCH_API_URL}{td_no}/  พร้อม header X-Api-Key
 *   2) API ตอบ JSON ที่มี td_image_url (ชี้ไปยังไฟล์รูปจริง)
 *   3) ดาวน์โหลดรูป -> ตรวจชนิดไฟล์จริง (png/jpg) และขนาด -> บันทึกลง UPLOAD_PATH_IMAGES
 *      แล้วคืนชื่อไฟล์/URL รูปแบบเดียวกับ upload_api.php (เก็บใน checklists.design_image_path ตามเดิม)
 *
 * ค่าที่ต้องตั้งใน includes/.env : IMAGE_FETCH_API_URL, IMAGE_FETCH_API_KEY, IMAGE_FETCH_API_TIMEOUT
 * API key อยู่ฝั่ง server เท่านั้น — ไม่ส่งไปที่เบราว์เซอร์
 */

require_once __DIR__ . '/config.php';

class DrawingApiException extends RuntimeException
{
    public string $messageKey;
    public int $httpStatus;

    public function __construct(string $messageKey, int $httpStatus, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $messageKey);
        $this->messageKey = $messageKey;
        $this->httpStatus = $httpStatus;
    }
}

/**
 * บันทึกผลการดึงรูปลง logs/drawing_fetch.log (โฟลเดอร์ logs ถูกบล็อกด้วย .htaccess)
 * ใช้ตรวจสอบเมื่อสร้าง checklist แล้วรูปไม่ขึ้น — ห้ามใส่ API key ในข้อความ log
 */
function logDrawingFetch(string $line): void
{
    $dir = ROOT_PATH . '/logs';
    if (!is_dir($dir)) {
        return;
    }
    @file_put_contents($dir . '/drawing_fetch.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function isDrawingApiConfigured(): bool
{
    return (string)env('IMAGE_FETCH_API_URL') !== '' && (string)env('IMAGE_FETCH_API_KEY') !== '';
}

/**
 * GET แบบจำกัดขนาดและไม่ตาม redirect (กัน header X-Api-Key หลุดไปโฮสต์อื่น)
 * @return array{status:int, body:string}
 */
function drawingHttpGet(string $url, string $apiKey, int $timeout, int $maxBytes): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $apiKey, 'Accept: application/json, image/*'],
        CURLOPT_NOPROGRESS     => false,
        // ยกเลิกการดาวน์โหลดทันทีถ้าไฟล์ใหญ่เกินกำหนด
        CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) use ($maxBytes) {
            return ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0;
        },
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo  = curl_errno($ch);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        // CURLE_ABORTED_BY_CALLBACK (42) = ไฟล์ใหญ่เกินกำหนด
        if ($errNo === 42) {
            throw new DrawingApiException('file_too_large', 422, 'Response exceeds size limit');
        }
        throw new DrawingApiException('drawing_api_failed', 502, 'Request failed: ' . $err);
    }
    return ['status' => $status, 'body' => (string)$body];
}

/**
 * @return array{filename:string, url:string, source_url:string}
 */
function fetchDrawingImage(string $tdNo): array
{
    $tdNo = trim($tdNo);
    // td_no ไปอยู่ใน path ของ URL — อนุญาตเฉพาะตัวอักษรที่ปลอดภัย
    if ($tdNo === '' || !preg_match('/^[A-Za-z0-9._\-]{1,60}$/', $tdNo)) {
        throw new DrawingApiException('invalid_input', 422, 'Invalid td_no');
    }
    if (!isDrawingApiConfigured()) {
        throw new DrawingApiException('drawing_api_not_configured', 503);
    }

    $base    = rtrim((string)env('IMAGE_FETCH_API_URL'), '/');
    $apiKey  = (string)env('IMAGE_FETCH_API_KEY');
    $timeout = (int)(env('IMAGE_FETCH_API_TIMEOUT') ?: 10);

    $baseParts = parse_url($base);
    if (!$baseParts || empty($baseParts['host']) || !in_array($baseParts['scheme'] ?? '', ['http', 'https'], true)) {
        throw new DrawingApiException('drawing_api_not_configured', 503, 'IMAGE_FETCH_API_URL is invalid');
    }
    $scheme = $baseParts['scheme'];
    $host   = strtolower($baseParts['host']);
    $port   = $baseParts['port'] ?? ($scheme === 'https' ? 443 : 80);

    // ---- 1) ขอข้อมูลรูปจาก API ----
    $meta = drawingHttpGet($base . '/' . rawurlencode($tdNo) . '/', $apiKey, $timeout, 1024 * 1024);
    if ($meta['status'] === 404) {
        throw new DrawingApiException('drawing_not_found', 404);
    }
    if ($meta['status'] !== 200) {
        throw new DrawingApiException('drawing_api_failed', 502, 'Drawing API returned HTTP ' . $meta['status']);
    }
    $json = json_decode($meta['body'], true);
    if (is_array($json) && isset($json[0]) && is_array($json[0])) {
        $json = $json[0]; // เผื่อ API ตอบเป็น list
    }
    $imageUrl = is_array($json) ? trim((string)($json['td_image_url'] ?? '')) : '';
    if ($imageUrl === '') {
        throw new DrawingApiException('drawing_no_image', 404);
    }

    // td_image_url แบบ path สัมพัทธ์ ("/media/...") -> เติม origin เดียวกับ API
    if (str_starts_with($imageUrl, '/')) {
        $imageUrl = $scheme . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '') . $imageUrl;
    }

    // กัน SSRF: ดาวน์โหลดได้เฉพาะโฮสต์/พอร์ตเดียวกับ API เท่านั้น
    $imgParts = parse_url($imageUrl);
    $imgScheme = $imgParts['scheme'] ?? '';
    $imgPort = $imgParts['port'] ?? ($imgScheme === 'https' ? 443 : 80);
    if (
        !$imgParts || !in_array($imgScheme, ['http', 'https'], true)
        || strtolower($imgParts['host'] ?? '') !== $host || $imgPort !== $port
    ) {
        throw new DrawingApiException('drawing_api_failed', 502, 'td_image_url points to a different host');
    }

    // ---- 2) ดาวน์โหลดรูป ----
    $img = drawingHttpGet($imageUrl, $apiKey, $timeout, 5 * 1024 * 1024);
    if ($img['status'] === 404) {
        throw new DrawingApiException('drawing_no_image', 404, 'Image file not found');
    }
    if ($img['status'] !== 200 || $img['body'] === '') {
        throw new DrawingApiException('drawing_api_failed', 502, 'Image download returned HTTP ' . $img['status']);
    }

    // ---- 3) ตรวจชนิดไฟล์จริง (ไม่เชื่อนามสกุล/Content-Type) แล้วบันทึก ----
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($img['body']);
    if ($mime === false || !isset($allowed[$mime])) {
        throw new DrawingApiException('invalid_file_type', 422);
    }

    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!is_dir(UPLOAD_PATH_IMAGES) && !mkdir(UPLOAD_PATH_IMAGES, 0755, true) && !is_dir(UPLOAD_PATH_IMAGES)) {
        throw new DrawingApiException('server_error', 500, 'Cannot create upload directory');
    }
    if (file_put_contents(UPLOAD_PATH_IMAGES . '/' . $filename, $img['body']) === false) {
        throw new DrawingApiException('server_error', 500, 'Cannot write image file');
    }

    return [
        'filename'   => $filename,
        'url'        => UPLOAD_URL_IMAGES . '/' . $filename,
        'source_url' => $imageUrl,
    ];
}
