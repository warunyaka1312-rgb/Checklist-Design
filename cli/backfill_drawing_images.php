<?php
/**
 * เติมรูปภาพแบบ (Drawing) ย้อนหลังให้ checklist ที่มี Tech แต่ยังไม่มีรูป — รวมถึงใบที่ส่งอนุมัติ/อนุมัติแล้ว
 * (ไม่ผ่านเว็บ รันจาก command line เท่านั้น)
 *
 * วิธีใช้ (Windows/XAMPP):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Checklist_Design\cli\backfill_drawing_images.php --dry-run
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Checklist_Design\cli\backfill_drawing_images.php
 *
 * ตัวเลือก:
 *   --dry-run    แสดงรายการที่จะดึง โดยไม่เรียก API และไม่แก้ฐานข้อมูล (แนะนำให้รันก่อน)
 *   --limit=N    ทำแค่ N ใบแรก (ไว้ทดลองกับจำนวนน้อยก่อน)
 *   --id=N       ทำเฉพาะ checklist id นี้
 *   --by=USER_ID ผู้ใช้ที่จะบันทึกใน audit trail (ค่าเริ่มต้น: admin ที่ active คนแรก)
 *
 * พฤติกรรม:
 *   - ทำเฉพาะใบที่ tech ไม่ว่าง และ design_image_path ยังว่าง ไม่แตะรูปที่มีอยู่แล้ว
 *   - Tech เดียวกันหลายใบ ใช้ไฟล์รูปเดียวกัน (ดึงจาก API ครั้งเดียว)
 *   - ไม่เปลี่ยน status และไม่เปลี่ยน updated_at ของ checklist
 *   - เพิ่มบรรทัดใน checklist_history (action=edited) ระบุว่าเป็นการดึงรูปย้อนหลังด้วยสคริปต์
 *   - หยุดเองถ้า API เชื่อมต่อไม่ได้ติดกัน 3 ครั้ง
 * Exit code: 0 = เสร็จ, 1 = ตั้งค่า/ระบบผิดพลาด, 2 = หยุดกลางคันเพราะ API เชื่อมต่อไม่ได้
 * บันทึกผลที่ logs/drawing_backfill.log
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/drawing_api.php';

$opts   = getopt('', ['dry-run', 'limit:', 'id:', 'by:', 'help']);
$dryRun = array_key_exists('dry-run', $opts);
$limit  = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 0;
$onlyId = isset($opts['id']) ? (int)$opts['id'] : 0;
$byUser = isset($opts['by']) ? (int)$opts['by'] : 0;

if (array_key_exists('help', $opts)) {
    echo "Usage: php backfill_drawing_images.php [--dry-run] [--limit=N] [--id=N] [--by=USER_ID]\n";
    exit(0);
}

$logFile = ROOT_PATH . '/logs/drawing_backfill.log';
function out(string $file, string $message): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

if (!$dryRun && !isDrawingApiConfigured()) {
    out($logFile, 'FAILED IMAGE_FETCH_API_URL / IMAGE_FETCH_API_KEY are not set in includes/.env');
    exit(1);
}

try {
    $pdo = getDbConnection();

    // ผู้ใช้สำหรับ audit trail (checklist_history.action_by บังคับต้องมี)
    if ($byUser > 0) {
        $u = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $u->execute([$byUser]);
        $byUser = (int)$u->fetchColumn();
    } else {
        $byUser = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    }
    if ($byUser <= 0) {
        out($logFile, 'FAILED no valid user for the audit trail (use --by=USER_ID)');
        exit(1);
    }

    $sql = "SELECT id, die_no, tech, status FROM checklists
            WHERE tech IS NOT NULL AND TRIM(tech) <> ''
              AND (design_image_path IS NULL OR design_image_path = '')";
    $params = [];
    if ($onlyId > 0) {
        $sql .= ' AND id = ?';
        $params[] = $onlyId;
    }
    $sql .= ' ORDER BY id ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $targets = $stmt->fetchAll();

    out($logFile, sprintf('START targets=%d dry_run=%s', count($targets), $dryRun ? 'yes' : 'no'));

    $update = $pdo->prepare(
        "UPDATE checklists SET design_image_path = ?, updated_at = updated_at
         WHERE id = ? AND (design_image_path IS NULL OR design_image_path = '')"
    );
    $history = $pdo->prepare(
        "INSERT INTO checklist_history (checklist_id, action, action_by, note, old_status, new_status)
         VALUES (?, 'edited', ?, ?, ?, ?)"
    );

    $cache = [];   // tech => ['file' => ...] | ['err' => key]
    $stats = ['updated' => 0, 'not_found' => 0, 'no_image' => 0, 'failed' => 0, 'skipped' => 0];
    $consecutiveApiFailures = 0;
    $aborted = false;

    foreach ($targets as $row) {
        $id = (int)$row['id'];
        $tech = trim((string)$row['tech']);
        $label = sprintf('#%d %s (tech=%s, status=%s)', $id, $row['die_no'], $tech, $row['status']);

        if ($dryRun) {
            out($logFile, 'WOULD FETCH ' . $label);
            continue;
        }

        if (!isset($cache[$tech])) {
            try {
                $r = fetchDrawingImage($tech);
                $cache[$tech] = ['file' => $r['filename']];
                $consecutiveApiFailures = 0;
            } catch (DrawingApiException $e) {
                $cache[$tech] = ['err' => $e->messageKey, 'detail' => $e->getMessage()];
                $consecutiveApiFailures = $e->messageKey === 'drawing_api_failed' ? $consecutiveApiFailures + 1 : 0;
            }
            usleep(200000); // เว้นจังหวะเล็กน้อย ไม่ยิง API ถี่เกินไป
        }

        $c = $cache[$tech];
        if (isset($c['err'])) {
            if ($c['err'] === 'drawing_not_found') {
                $stats['not_found']++;
            } elseif ($c['err'] === 'drawing_no_image') {
                $stats['no_image']++;
            } else {
                $stats['failed']++;
            }
            $extra = (($c['detail'] ?? '') !== '' && $c['detail'] !== $c['err']) ? ' [' . $c['detail'] . ']' : '';
            out($logFile, 'SKIP ' . $label . ' -> ' . $c['err'] . $extra);
            if ($consecutiveApiFailures >= 3) {
                out($logFile, 'ABORT API failed 3 times in a row — check the network / API key, then run again');
                $aborted = true;
                break;
            }
            continue;
        }

        $pdo->beginTransaction();
        try {
            $update->execute([$c['file'], $id]);
            if ($update->rowCount() === 1) {
                $history->execute([
                    $id, $byUser,
                    'เติมรูปแบบจาก API ย้อนหลัง (สคริปต์ backfill_drawing_images)',
                    $row['status'], $row['status'],
                ]);
                $stats['updated']++;
                out($logFile, 'OK   ' . $label . ' -> ' . $c['file']);
            } else {
                $stats['skipped']++; // มีคนใส่รูปไปแล้วระหว่างที่สคริปต์รัน
                out($logFile, 'SKIP ' . $label . ' -> image was set meanwhile');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $stats['failed']++;
            out($logFile, 'ERR  ' . $label . ' -> ' . $e->getMessage());
        }
    }

    out($logFile, sprintf(
        'DONE updated=%d not_found=%d no_image=%d failed=%d skipped=%d',
        $stats['updated'], $stats['not_found'], $stats['no_image'], $stats['failed'], $stats['skipped']
    ));
    exit($aborted ? 2 : 0);
} catch (Throwable $e) {
    out($logFile, 'FAILED ' . $e->getMessage());
    exit(1);
}
