<?php
/**
 * Export ฐานข้อมูลปัจจุบัน (โครงสร้าง + ข้อมูล) เป็นไฟล์ .sql เพื่อนำไป Import ผ่าน phpMyAdmin บนเครื่องอื่น
 * (ไม่ผ่านเว็บ รันจาก command line เท่านั้น — อ่านค่า DB จาก includes/config.php)
 *
 * วิธีใช้ (Windows/XAMPP) ทีละคำสั่ง:
 *   cd C:\xampp\htdocs\Checklist_Design
 *   C:\xampp\php\php.exe db\export_database.php
 *
 * ผลลัพธ์: db\backup\checklist_design_YYYYMMDD_HHMMSS.sql
 *
 * ตัวเลือก:
 *   --structure-only          เฉพาะโครงสร้างตาราง ไม่เอาข้อมูล (ฐานข้อมูลเปล่า)
 *   --exclude-data=a,b        ข้ามข้อมูลของตารางที่ระบุ (เอาเฉพาะโครงสร้าง) เช่น notifications,checklist_history
 *   --target-db=NAME          ใช้ชื่อฐานข้อมูลนี้ในไฟล์ .sql แทนชื่อเดิม (กรณีเครื่องปลายทางใช้ชื่ออื่น)
 *   --no-create-db            ไม่ใส่คำสั่ง CREATE DATABASE / USE — ใช้กับ shared hosting ที่สร้าง DB เองไม่ได้
 *                             (เลือกฐานข้อมูลใน phpMyAdmin ก่อนแล้วค่อย Import)
 *   --no-drop                 ไม่ใส่ DROP TABLE IF EXISTS (จะ error ถ้าตารางมีอยู่แล้ว แทนที่จะลบทับ)
 *   --gzip                    บีบอัดเป็น .sql.gz (phpMyAdmin รองรับการ Import ไฟล์ .gz)
 *   --out=PATH                กำหนดที่เก็บไฟล์เอง
 *
 * หมายเหตุสำคัญ:
 *   - ไฟล์นี้มีข้อมูลผู้ใช้ (รวม password hash) — อย่า commit เข้า git / อย่าวางไว้บนเว็บ ลบทิ้งเมื่อใช้เสร็จ
 *   - รูปและ PDF ไม่ได้อยู่ในฐานข้อมูล ต้องคัดลอกโฟลเดอร์ assets\uploads ไปเครื่องปลายทางด้วย
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db.php';

$opts = getopt('', ['structure-only', 'exclude-data:', 'target-db:', 'no-create-db', 'no-drop', 'gzip', 'out:', 'help']);
if (array_key_exists('help', $opts)) {
    echo "Usage: php db/export_database.php [--structure-only] [--exclude-data=a,b] [--target-db=NAME] [--no-create-db] [--no-drop] [--gzip] [--out=PATH]\n";
    exit(0);
}

$structureOnly = array_key_exists('structure-only', $opts);
$noCreateDb    = array_key_exists('no-create-db', $opts);
$noDrop        = array_key_exists('no-drop', $opts);
$gzip          = array_key_exists('gzip', $opts);
$targetDb      = isset($opts['target-db']) ? trim((string)$opts['target-db']) : DB_NAME;
$excludeData   = isset($opts['exclude-data'])
    ? array_filter(array_map('trim', explode(',', (string)$opts['exclude-data'])))
    : [];

if (!preg_match('/^[A-Za-z0-9_\-]+$/', $targetDb)) {
    fwrite(STDERR, "Invalid --target-db name\n");
    exit(1);
}

$backupDir = __DIR__ . '/backup';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}
$outPath = isset($opts['out'])
    ? (string)$opts['out']
    : $backupDir . '/' . DB_NAME . '_' . date('Ymd_His') . ($structureOnly ? '_structure' : '') . '.sql' . ($gzip ? '.gz' : '');

$fh = $gzip ? gzopen($outPath, 'wb9') : fopen($outPath, 'wb');
if (!$fh) {
    fwrite(STDERR, "Cannot write to $outPath\n");
    exit(1);
}
$write = static function (string $s) use ($fh, $gzip): void {
    $gzip ? gzwrite($fh, $s) : fwrite($fh, $s);
};

function q(string $ident): string
{
    return '`' . str_replace('`', '``', $ident) . '`';
}

try {
    $pdo = getDbConnection();
    $pdo->exec("SET NAMES utf8mb4");

    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote(DB_NAME) . " AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) {
        throw new RuntimeException('No tables found in database ' . DB_NAME);
    }

    $write("-- ------------------------------------------------------------------\n");
    $write("-- Die Design Checklist Approval System — database export\n");
    $write("-- Source database : " . DB_NAME . "\n");
    $write("-- Exported at     : " . date('Y-m-d H:i:s') . " (" . date_default_timezone_get() . ")\n");
    $write("-- Mode            : " . ($structureOnly ? 'structure only' : 'structure + data') . "\n");
    $write("-- Import: phpMyAdmin > Import (จากหน้าแรกของเซิร์ฟเวอร์ ไม่ใช่ในฐานข้อมูลใดฐานข้อมูลหนึ่ง)\n");
    $write("--         ถ้าไฟล์นี้สร้างด้วย --no-create-db ให้เลือกฐานข้อมูลปลายทางก่อนแล้วจึง Import\n");
    $write("-- ------------------------------------------------------------------\n\n");
    $write("SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    if (!$noCreateDb) {
        $write('CREATE DATABASE IF NOT EXISTS ' . q($targetDb) . " DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
        $write('USE ' . q($targetDb) . ";\n\n");
    }

    $summary = [];
    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE ' . q($table))->fetch(PDO::FETCH_NUM)[1];

        $write("-- ------------------------------------------------------------------\n");
        $write("-- Table: $table\n");
        $write("-- ------------------------------------------------------------------\n");
        if (!$noDrop) {
            $write('DROP TABLE IF EXISTS ' . q($table) . ";\n");
        }
        $write($create . ";\n\n");

        $rowCount = 0;
        if (!$structureOnly && !in_array($table, $excludeData, true)) {
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM ' . q($table)) as $c) {
                $cols[] = q($c['Field']);
            }
            $colList = implode(', ', $cols);

            $rows = $pdo->query('SELECT * FROM ' . q($table));
            $batch = [];
            $flush = static function () use (&$batch, $write, $table, $colList): void {
                if ($batch) {
                    $write('INSERT INTO ' . q($table) . " ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            };
            while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string)$v;
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $batch[] = '(' . implode(', ', $vals) . ')';
                $rowCount++;
                if (count($batch) >= 100) {
                    $flush();
                }
            }
            $flush();
            if ($rowCount > 0) {
                $write("\n");
            }
        }
        $summary[$table] = $rowCount;
    }

    $write("SET FOREIGN_KEY_CHECKS = 1;\n");
    $gzip ? gzclose($fh) : fclose($fh);

    echo "Exported " . count($tables) . " tables to:\n  " . realpath($outPath) . "\n";
    foreach ($summary as $t => $n) {
        echo sprintf("  %-28s %s\n", $t, ($structureOnly || in_array($t, $excludeData, true)) ? '(structure only)' : $n . ' rows');
    }
    echo "\nอย่าลืม: คัดลอกโฟลเดอร์ assets\\uploads ไปเครื่องปลายทางด้วย (รูป/PDF ไม่อยู่ในไฟล์ .sql)\n";
    echo "ไฟล์นี้มีข้อมูลผู้ใช้ ห้าม commit / ห้ามวางบนเว็บ และควรลบทิ้งเมื่อใช้เสร็จ\n";
    exit(0);
} catch (Throwable $e) {
    if (is_resource($fh)) {
        $gzip ? gzclose($fh) : fclose($fh);
    }
    @unlink($outPath);
    fwrite(STDERR, 'FAILED ' . $e->getMessage() . "\n");
    exit(1);
}
