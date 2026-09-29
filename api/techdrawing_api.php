<?php
/**
 * Looks a Tech number up in the SAP tech-drawing feed for the checklist form's
 * "ดึงจากโปรแกรมพี่ตุ้ม" button, and returns the fields it fills in:
 * Model (SectionCode), Material, Temper and die type.
 *
 * GET api/techdrawing_api.php?tech=220054-001
 *   -> {success:true, drawing:{tech,model,section_name,material,temper,die_type}}
 *   -> {success:false, message:'drawing_tech_required'|'tech_drawing_not_found'|'techdrawing_api_failed'}
 *
 * Read-only, so no CSRF token. The feed itself is cached by the helper, so a
 * lookup normally costs no network call at all.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/techdrawing_api.php';

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

$tech = trim((string)($_GET['tech'] ?? ''));
if ($tech === '') {
    respond(['success' => false, 'message' => 'drawing_tech_required'], 422);
}

$apiError = null;
$drawing = findTechDrawing($tech, $apiError);

if ($drawing === null) {
    // Distinguish "SAP is down" from "SAP has no such drawing" — the engineer
    // can do something about the second one but not the first.
    if ($apiError !== null) {
        respond([
            'success' => false,
            'message' => 'techdrawing_api_failed',
            'detail' => APP_DEBUG ? $apiError : null,
        ], 502);
    }
    respond(['success' => false, 'message' => 'tech_drawing_not_found'], 404);
}

respond(['success' => true, 'drawing' => $drawing]);
