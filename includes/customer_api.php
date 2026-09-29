<?php
/**
 * Customer API (SAP) -> ตาราง customers
 * ดึงรายชื่อลูกค้าจาก API ภายนอกแล้ว sync เข้าตาราง customers ในฐานข้อมูลของระบบ
 * (checklist ยังอ้างอิง customers.id ตามเดิม — หน้า engineer ไม่ต้องแก้)
 *
 * ต้องตั้งค่าใน includes/.env :
 *   CUSTOMER_API_URL=...
 *   CUSTOMER_API_KEY=          (เว้นว่างได้ถ้า API ไม่ต้องใช้ key)
 *   CUSTOMER_API_TIMEOUT=15
 */

require_once __DIR__ . '/db.php';

function fetchCustomersFromApi(): array
{
    $url = env('CUSTOMER_API_URL');
    if (!$url) {
        throw new RuntimeException('CUSTOMER_API_URL is not set in includes/.env');
    }
    $timeout = (int)(env('CUSTOMER_API_TIMEOUT') ?: 15);
    $key = env('CUSTOMER_API_KEY');

    $headers = ['Accept: application/json'];
    if ($key) {
        $headers[] = 'Authorization: Bearer ' . $key; // ปรับตามที่ API จริงต้องการ
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Customer API request failed: ' . $err);
    }
    if ($status !== 200) {
        throw new RuntimeException('Customer API returned HTTP ' . $status);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Customer API returned invalid JSON');
    }
    return $data;
}

/**
 * Sync ลูกค้าจาก API เข้า customers (upsert ด้วย card_code)
 * @return array{inserted:int, updated:int, linked:int, skipped:int}
 */
function syncCustomersFromApi(PDO $pdo): array
{
    $rows = fetchCustomersFromApi();
    if (count($rows) === 0) {
        // กันกรณี API ตอบ [] ผิดปกติ — ไม่ทำอะไรเลย
        throw new RuntimeException('Customer API returned an empty list; sync aborted');
    }

    $result = ['inserted' => 0, 'updated' => 0, 'linked' => 0, 'skipped' => 0];
    $now = date('Y-m-d H:i:s'); // เวลาตาม timezone ของแอป (Asia/Bangkok) ไม่ขึ้นกับ timezone ของ MySQL

    $findByCode = $pdo->prepare('SELECT id FROM customers WHERE card_code = ?');
    $findByName = $pdo->prepare('SELECT id FROM customers WHERE name = ? AND card_code IS NULL');
    $update = $pdo->prepare(
        'UPDATE customers SET card_code = ?, name = ?, tax_id = ?, address = ?, is_active = ?, synced_at = ? WHERE id = ?'
    );
    $insert = $pdo->prepare(
        'INSERT INTO customers (card_code, name, tax_id, address, is_active, synced_at) VALUES (?, ?, ?, ?, ?, ?)'
    );

    // uq_customers_name ห้ามชื่อซ้ำ — ถ้าชนกับลูกค้ารหัสอื่น ให้ต่อรหัสท้ายชื่อแทน
    $run = static function (PDOStatement $stmt, array $params, int $nameIndex, string $code): void {
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $params[$nameIndex] .= ' [' . $code . ']';
            $stmt->execute($params);
        }
    };

    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $code = trim((string)($r['CardCode'] ?? ''));
            $name = trim((string)($r['CardName'] ?? ''));
            if ($code === '' || $name === '') {
                $result['skipped']++;
                continue;
            }
            $tax    = trim((string)($r['LicTradNum'] ?? '')) ?: null;
            $addr   = trim((string)($r['MailAddres'] ?? '')) ?: null; // สะกดตาม API (ไม่มี s ท้าย)
            $active = (($r['Status'] ?? '') === 'Active') ? 1 : 0;

            $findByCode->execute([$code]);
            $id = $findByCode->fetchColumn();
            if ($id) {
                $run($update, [$code, $name, $tax, $addr, $active, $now, $id], 1, $code);
                $result['updated']++;
                continue;
            }

            // ลูกค้าที่ admin เพิ่มมือไว้ก่อน (ชื่อตรงกัน) -> ผูก card_code ให้ เพื่อรักษา checklist เดิม
            $findByName->execute([$name]);
            $id = $findByName->fetchColumn();
            if ($id) {
                $run($update, [$code, $name, $tax, $addr, $active, $now, $id], 1, $code);
                $result['linked']++;
                continue;
            }

            $run($insert, [$code, $name, $tax, $addr, $active, $now], 1, $code);
            $result['inserted']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}
