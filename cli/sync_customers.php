<?php
/**
 * Sync รายชื่อลูกค้าจาก Customer API (SAP) เข้าตาราง customers — สำหรับรันอัตโนมัติผ่าน
 * Windows Task Scheduler หรือ cron (ไม่ใช่ผ่านเว็บ)
 *
 * Windows (XAMPP):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Checklist_Design\cli\sync_customers.php
 * Linux cron (ทุกวัน 02:00):
 *   0 2 * * * /usr/bin/php /path/to/Checklist_Design/cli/sync_customers.php
 *
 * Exit code: 0 = สำเร็จ, 1 = ล้มเหลว (Task Scheduler จะแสดงเป็น "Last Run Result")
 * บันทึกผลทุกครั้งที่ logs/customer_sync.log
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/customer_api.php';

$logFile = ROOT_PATH . '/logs/customer_sync.log';

function writeSyncLog(string $file, string $message): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

try {
    $r = syncCustomersFromApi(getDbConnection());
    writeSyncLog(
        $logFile,
        sprintf('OK inserted=%d updated=%d linked=%d skipped=%d', $r['inserted'], $r['updated'], $r['linked'], $r['skipped'])
    );
    exit(0);
} catch (Throwable $e) {
    writeSyncLog($logFile, 'FAILED ' . $e->getMessage());
    exit(1);
}
