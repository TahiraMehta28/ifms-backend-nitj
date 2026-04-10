<?php
// api/update-request-fields.php
// ✅ Point 7(a) material    — NEVER editable by any reviewer (PI-only field)
// ✅ Point 7(b) expenditure — AR and DR only
// ✅ Point 8    mode        — DRC R&C and DRC only

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit(); }

$requestId = trim($input['requestId'] ?? '');
$stage     = trim($input['stage']     ?? '');

if (!$requestId || !$stage) {
    echo json_encode(['success' => false, 'message' => 'requestId and stage required']); exit();
}

$point7bStages = ['ar', 'dr'];
$point8Stages  = ['drc_rc', 'drc'];
$allAllowed    = array_merge($point7bStages, $point8Stages);

if (!in_array($stage, $allAllowed)) {
    echo json_encode(['success' => false, 'message' => "Stage '{$stage}' cannot edit these fields."]); exit();
}

if (array_key_exists('material', $input)) {
    echo json_encode(['success' => false, 'message' => "Point 7(a) — material is set by the PI and cannot be edited by any reviewer."]); exit();
}

try {
    $db = getMySQLConnection();
    
    $updateFields = [];
    $params = [];

    // ── Point 7(b): expenditure — AR and DR only ─────────────────────────────
    if (in_array($stage, $point7bStages)) {
        if (array_key_exists('expenditure', $input)) {
            $updateFields[] = "expenditure = ?";
            $params[] = htmlspecialchars(strip_tags(trim($input['expenditure'])));
        }
    }

    // ── Point 8: mode — DRC R&C and DRC only ─────────────────────────────────
    if (in_array($stage, $point8Stages)) {
        if (array_key_exists('mode', $input)) {
            $updateFields[] = "mode = ?";
            $params[] = htmlspecialchars(strip_tags(trim($input['mode'])));
        }
    }

    if (empty($updateFields)) {
        echo json_encode(['success' => false, 'message' => 'No valid fields provided or unauthorized field for stage ' . $stage]); exit();
    }

    $updateFields[] = "updatedAt = NOW()";
    $params[] = $requestId;

    $sql = "UPDATE budget_requests SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Saved successfully.',
        'updatedCount' => $stmt->rowCount()
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>