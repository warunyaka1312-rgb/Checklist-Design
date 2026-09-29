<?php
/**
 * สคริปต์เพิ่ม user role "engineer" และ "manager" ตัวอย่าง (รันครั้งเดียวผ่าน CLI)
 *
 * วิธีใช้:
 *   1. แก้ไขรายชื่อ/รหัสผ่านในอาร์เรย์ $usersToAdd ด้านล่างให้ตรงกับที่ต้องการ
 *   2. รันจาก command line ที่ root ของโปรเจกต์:
 *        php db/add_users.php
 *   3. ถ้า username ไหนมีอยู่แล้วในระบบ สคริปต์จะข้ามให้อัตโนมัติ (ไม่ error ไม่ทับข้อมูลเดิม)
 *
 * ไม่ใช้ library ภายนอก/Composer ตามแพทเทิร์นเดิมของโปรเจกต์ — ใช้ getDbConnection()
 * จาก includes/db.php และ password_hash() (bcrypt) เหมือนที่ includes/auth.php ใช้ตรวจสอบ
 */

require_once __DIR__ . '/../includes/db.php';

// ---- แก้รายชื่อ user ที่ต้องการเพิ่มตรงนี้ ----
$usersToAdd = [
    [
        'username'  => 'engineer1',
        'password'  => 'engineer123',
        'full_name' => 'Engineer One',
        'role'      => 'engineer',
    ],
    [
        'username'  => 'manager1',
        'password'  => 'manager123',
        'full_name' => 'Manager One',
        'role'      => 'manager',
    ],
];
// ------------------------------------------------

if (PHP_SAPI !== 'cli') {
    die('สคริปต์นี้ใช้รันผ่าน command line เท่านั้น (php db/add_users.php)' . PHP_EOL);
}

$pdo = getDbConnection();

$checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$insertStmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)'
);

foreach ($usersToAdd as $u) {
    $username = trim($u['username']);
    $role = $u['role'];

    if (!in_array($role, ['admin', 'engineer', 'manager'], true)) {
        echo "ข้าม {$username}: role '{$role}' ไม่ถูกต้อง (ต้องเป็น admin/engineer/manager)" . PHP_EOL;
        continue;
    }

    $checkStmt->execute([$username]);
    if ($checkStmt->fetchColumn() !== false) {
        echo "ข้าม {$username}: มี username นี้อยู่แล้วในระบบ" . PHP_EOL;
        continue;
    }

    $passwordHash = password_hash($u['password'], PASSWORD_DEFAULT);
    $insertStmt->execute([$username, $passwordHash, $u['full_name'], $role]);

    echo "เพิ่มสำเร็จ: {$username} (role: {$role})" . PHP_EOL;
}

echo 'เสร็จสิ้น.' . PHP_EOL;
