<?php
/**
 * Central application configuration — EXAMPLE FILE.
 * ทุกไฟล์ในระบบต้อง include ไฟล์นี้ไฟล์เดียวสำหรับค่า config — ห้าม hardcode ซ้ำที่อื่น
 *
 * วิธีใช้: คัดลอกไฟล์นี้เป็น "config.php" ในโฟลเดอร์เดียวกัน แล้วแก้ค่าด้านล่างให้ตรงกับ
 * เครื่อง/hosting จริงของคุณ ไฟล์ config.php (ที่มีรหัสผ่านจริง) ไม่ควร commit เข้า git —
 * ไฟล์ config.example.php นี้ต่างหากที่ commit เก็บไว้เป็นแม่แบบ
 */

/**
 * โหลดค่าจากไฟล์ includes/.env (ถ้ามี) เข้า putenv()/$_ENV เพื่อให้เรียกด้วย
 * env('KEY') ที่อื่นในระบบได้ — ใช้สำหรับค่า config ที่ยังไม่พร้อม hardcode ลง
 * config.php เช่นค่าเชื่อมต่อ API ภายนอกในอนาคต (ดู includes/.env.example)
 *
 * ไม่ใช้ library ภายนอก/Composer — parse บรรทัดแบบ KEY=VALUE อย่างง่าย ข้ามบรรทัด
 * ว่างและบรรทัดที่ขึ้นต้นด้วย # ถ้าไม่มีไฟล์ .env ก็ข้ามไปเฉย ๆ ไม่ error เพราะยังไม่มี
 * ฟีเจอร์ใดบังคับใช้ค่านี้จริงตอนนี้
 */
function loadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // ตัด quote ล้อมค่าออก ถ้ามี (รองรับ "value" หรือ 'value')
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[-1] === '"') ||
            ($value[0] === "'" && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if ($key === '') {
            continue;
        }

        // บาง server (เช่น aaPanel/BaoTa) ปิด putenv() ไว้ใน disable_functions
        // จึงเก็บลง $_ENV เป็นหลัก แล้วค่อย putenv() เฉพาะเมื่อเรียกใช้ได้
        $_ENV[$key] = $value;
        if (function_exists('putenv')) {
            @putenv($key . '=' . $value);
        }
    }
}

/**
 * อ่านค่า config จาก .env — ใช้แทน getenv() ทั้งระบบ เพราะบาง server ปิด
 * putenv()/getenv() ไว้ ทำให้ getenv() อ่านค่าจาก .env ไม่ได้
 * คืนค่า false เมื่อไม่พบ (พฤติกรรมเดียวกับ getenv())
 */
function env(string $key)
{
    if (array_key_exists($key, $_ENV)) {
        return (string)$_ENV[$key];
    }
    if (function_exists('getenv')) {
        return getenv($key);
    }
    return false;
}

loadEnvFile(__DIR__ . '/.env');

// ---- Database ----
// อ่านค่าจาก includes/.env (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET) — ไม่ต้องแก้ไฟล์นี้
// ค่าหลัง ?: เป็นค่าสำรองของ XAMPP local (root / ไม่มีรหัสผ่าน) กรณีไม่ได้ตั้งไว้ใน .env
// เมื่อ deploy ขึ้น shared hosting ให้ใส่ค่าที่ cPanel/hosting สร้างฐานข้อมูลให้มาลงใน .env
define('DB_HOST', env('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(env('DB_PORT') ?: 3306)); // ถ้าเครื่องตั้ง MySQL ไว้ port อื่น (ดู xampp/mysql/bin/my.ini) ให้ตั้ง DB_PORT ใน .env
define('DB_NAME', env('DB_NAME') ?: 'checklist_design');
define('DB_USER', env('DB_USER') ?: 'root');
define('DB_PASS', (string)env('DB_PASS')); // ไม่ตั้ง = ไม่มีรหัสผ่าน
define('DB_CHARSET', env('DB_CHARSET') ?: 'utf8mb4');

// ---- Application ----
define('APP_NAME', 'Die Design Checklist Approval System');
// ปรับ APP_BASE_URL ให้ตรงกับ path จริงที่เข้าเว็บ (ไม่มี "/" ปิดท้าย)
// ตัวอย่าง local: http://localhost/Checklist_Design
// ตัวอย่าง production: https://yourdomain.com หรือ https://yourdomain.com/checklist ถ้าอยู่ในโฟลเดอร์ย่อย
// ตั้งค่าได้ที่ APP_BASE_URL ใน includes/.env — ไม่ตั้ง = ค่า local ด้านล่าง
define('APP_BASE_URL', rtrim(env('APP_BASE_URL') ?: 'http://localhost/Checklist_Design', '/'));

// ---- Paths ----
// ค่าเหล่านี้คำนวณจาก ROOT_PATH โดยอัตโนมัติ ไม่ต้องแก้ — ทำงานได้ทั้ง Windows/XAMPP และ Linux shared hosting
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH_IMAGES', ROOT_PATH . '/assets/uploads/images');
define('UPLOAD_PATH_PDF', ROOT_PATH . '/assets/uploads/pdf');
define('UPLOAD_URL_IMAGES', APP_BASE_URL . '/assets/uploads/images');
define('UPLOAD_URL_PDF', APP_BASE_URL . '/assets/uploads/pdf');

// ---- Session / Security ----
// เปลี่ยนค่านี้เป็นสตริงสุ่มที่ไม่ซ้ำใครก่อนขึ้น production จริง (เช่นรันคำสั่ง
// `php -r "echo bin2hex(random_bytes(32));"` แล้ว copy ผลลัพธ์มาใส่)
define('APP_SECRET_KEY', 'change-this-secret-key-before-deploy');

// ---- Error reporting ----
// ต้องตั้งเป็น false บน production เสมอ เพื่อไม่ให้ error/stack trace หลุดออกไปแสดงบนหน้าเว็บ
define('APP_DEBUG', true);

if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

date_default_timezone_set('Asia/Bangkok');
