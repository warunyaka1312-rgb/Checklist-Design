<?php
/**
 * Export a single checklist to a Thai-readable PDF (FPDF + embedded Sarabun font).
 * Auth: session cookie. Access mirrors the view pages: admin sees any checklist,
 * manager sees any checklist (read-only decisions are gated elsewhere, not here),
 * engineer sees only checklists they created.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/checklist_helpers.php';
require_once __DIR__ . '/../includes/pdf_helpers.php';

requireLogin();

$me = currentUser();
$pdo = getDbConnection();

$id = (int)($_GET['id'] ?? 0);
$data = $id > 0 ? fetchChecklistWithDetails($pdo, $id) : null;
if (!$data) {
    http_response_code(404);
    die('Checklist not found.');
}

$checklist = $data['checklist'];
$isOwner = (int)$checklist['created_by'] === (int)$me['id'];
$canView = $me['role'] === 'admin' || $me['role'] === 'manager' || ($me['role'] === 'engineer' && $isOwner);
if (!$canView) {
    http_response_code(403);
    die('Forbidden.');
}

$modelStmt = $pdo->prepare('SELECT name FROM die_models WHERE id = ?');
$modelStmt->execute([$checklist['model_id']]);
$modelName = $modelStmt->fetchColumn() ?: '-';

$materialStmt = $pdo->prepare('SELECT name FROM materials WHERE id = ?');
$materialStmt->execute([$checklist['material_id']]);
$materialName = $materialStmt->fetchColumn() ?: '-';

$temperName = '-';
if (!empty($checklist['temper_id'])) {
    $temperStmt = $pdo->prepare('SELECT name FROM tempers WHERE id = ?');
    $temperStmt->execute([$checklist['temper_id']]);
    $temperName = $temperStmt->fetchColumn() ?: '-';
}

$customerStmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
$customerStmt->execute([$checklist['customer_id']]);
$customerName = $customerStmt->fetchColumn() ?: '-';

$creatorStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
$creatorStmt->execute([$checklist['created_by']]);
$creatorName = $creatorStmt->fetchColumn() ?: '-';

$managerName = '-';
if ($checklist['assigned_manager_id']) {
    $managerStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $managerStmt->execute([$checklist['assigned_manager_id']]);
    $managerName = $managerStmt->fetchColumn() ?: '-';
}

$decidedByName = null;
if ($checklist['decided_by']) {
    $decidedByStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $decidedByStmt->execute([$checklist['decided_by']]);
    $decidedByName = $decidedByStmt->fetchColumn() ?: null;
}

$items = fetchActiveChecklistItems($pdo);
$itemsById = [];
foreach ($items[$checklist['die_type']] ?? [] as $it) {
    $itemsById[$it['id']] = $it;
}

$selectedItems = [];
foreach ($data['selected_item_ids'] as $itemId) {
    if (!isset($itemsById[$itemId])) {
        continue;
    }
    $r = $data['results'][$itemId] ?? ['result' => null, 'comment' => null, 'image_path' => null];
    $selectedItems[] = [
        'topic' => $itemsById[$itemId]['topic'],
        'note' => $itemsById[$itemId]['note'],
        'is_custom' => false,
        'result' => $r['result'],
        'comment' => $r['comment'],
        'image_path' => $r['image_path'] ? UPLOAD_PATH_IMAGES . '/' . $r['image_path'] : null,
    ];
}
foreach ($data['custom_items'] as $ci) {
    $selectedItems[] = [
        'topic' => $ci['custom_topic'],
        'note' => $ci['custom_note'],
        'is_custom' => true,
        'result' => $ci['result'],
        'comment' => $ci['comment'],
        'image_path' => $ci['image_path'] ? UPLOAD_PATH_IMAGES . '/' . $ci['image_path'] : null,
    ];
}

$statusLabels = [
    'draft' => 'Draft / ฉบับร่าง',
    'pending' => 'Pending / รออนุมัติ',
    'approved' => 'Approved / อนุมัติแล้ว',
    'rejected' => 'Rejected / ถูกตีกลับ',
];

$pdf = new ThaiPdf();
$pdf->AddPage();

// Title
$pdf->SetFont('Sarabun', 'B', 16);
$pdf->Cell(0, 9, pdfText('ใบตรวจสอบแบบแม่พิมพ์ก่อนอนุมัติจ่ายแบบ'), 0, 1, 'C');
$pdf->SetFont('Sarabun', '', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 6, pdfText('Die Design Checklist Approval Record'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(4);

// Basic info block. Every dynamic value goes through pdfText(), since customer
// names, user full names, etc. may contain Thai text just as freely as labels.
$infoRows = [
    ['Die-No', pdfText($checklist['die_no'])],
    ['Model', pdfText($modelName)],
    [pdfText('ลูกค้า / Customer'), pdfText($customerName)],
    [pdfText('ประเภทแม่พิมพ์ / Die Type'), $checklist['die_type'] === 'solid' ? 'Solid Die' : 'Hollow Die'],
    [pdfText('Material'), pdfText($materialName)],
    [pdfText('Temper'), pdfText($temperName)],
    [pdfText('วิศวกรผู้สร้าง / Engineer'), pdfText($creatorName)],
    [pdfText('ผู้อนุมัติที่ได้รับมอบหมาย / Assigned Manager'), pdfText($managerName)],
    [pdfText('สถานะ / Status'), pdfText($statusLabels[$checklist['status']] ?? $checklist['status'])],
    [pdfText('วันที่สร้าง / Created At'), date('d M Y H:i', strtotime($checklist['created_at']))],
];
if ($checklist['decided_at']) {
    $infoRows[] = [pdfText('วันที่ตัดสินใจ / Decided At'), date('d M Y H:i', strtotime($checklist['decided_at']))];
}
if ($decidedByName) {
    $infoRows[] = [pdfText('ผู้อนุมัติ / Decided By'), pdfText($decidedByName)];
}
if (!empty($checklist['revision_note'])) {
    $infoRows[] = [pdfText('เหตุผลที่ถูกตีกลับ / Rejection Reason'), pdfText($checklist['revision_note'])];
}

foreach ($infoRows as [$label, $value]) {
    $pdf->SetFont('Sarabun', 'B', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 5, $label, 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Sarabun', '', 11);
    $pdf->MultiCell(0, 6, $value, 0, 'L');
    $pdf->Ln(1);
}

$pdf->Ln(4);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(4);

// Inspection results
$pdf->SetFont('Sarabun', 'B', 13);
$pdf->Cell(0, 8, pdfText('ผลการตรวจสอบ / Inspection Results'), 0, 1);
$pdf->Ln(1);

if (empty($selectedItems)) {
    $pdf->SetFont('Sarabun', '', 10);
    $pdf->Cell(0, 6, pdfText('ไม่มีหัวข้อที่เลือกตรวจ'), 0, 1);
}

foreach ($selectedItems as $i => $si) {
    $isFail = $si['result'] === 'fail';

    // Keep the item block from splitting across a page break where possible.
    $pdf->SetFont('Sarabun', '', 9);
    $blockHeight = 14 + ($si['comment'] ? 6 : 0) + ($si['image_path'] && is_file($si['image_path']) ? 32 : 0);
    if ($pdf->GetY() + $blockHeight > 280) {
        $pdf->AddPage();
    }

    $topicLabel = $si['topic'] . ($si['is_custom'] ? ' [' . 'หัวข้อเพิ่มเติม' . ']' : '');
    $pdf->SetFont('Sarabun', 'B', 10);
    $pdf->Cell(140, 7, ($i + 1) . '. ' . pdfText($topicLabel), 0, 0);

    if ($isFail) {
        $pdf->SetTextColor(180, 30, 30);
        $pdf->Cell(0, 7, pdfText('FAIL'), 0, 1, 'R');
    } else {
        $pdf->SetTextColor(30, 130, 60);
        $pdf->Cell(0, 7, pdfText('PASS'), 0, 1, 'R');
    }
    $pdf->SetTextColor(0, 0, 0);

    if (!empty($si['note'])) {
        $pdf->SetFont('Sarabun', '', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(0, 5, pdfText($si['note']), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    if (!empty($si['comment'])) {
        $pdf->SetFont('Sarabun', '', 9);
        $pdf->MultiCell(0, 5.5, pdfText('Comment: ') . pdfText($si['comment']), 0, 'L');
    }

    if ($si['image_path'] && is_file($si['image_path'])) {
        $imgY = $pdf->GetY() + 1;
        try {
            $pdf->Image($si['image_path'], 15, $imgY, 50);
            $pdf->SetY($imgY + 32);
        } catch (Throwable $e) {
            // Skip unrenderable images (unsupported format, corrupt file, etc.).
        }
    }

    $pdf->Ln(3);
}

// Footer note
$pdf->SetFont('Sarabun', '', 8);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 5, pdfText('สร้างเอกสารเมื่อ / Generated at: ') . date('d M Y H:i'), 0, 1);

$filename = 'checklist_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $checklist['die_no']) . '.pdf';
$pdf->Output('I', $filename);
