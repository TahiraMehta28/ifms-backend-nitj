<?php
// api/drc-office-forward.php
// DRC Office forwards to DR (R&C).
// Accepts both 'dr_approved' AND 'sent_back_to_drc_office' (from DR R&C) for re-processing.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input     = json_decode(file_get_contents('php://input'), true);
$requestId = $input['requestId'] ?? '';
$remarks   = trim($input['remarks'] ?? '');
$actionBy  = $input['actionBy'] ?? 'DRC Office';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

try {
    $db = getMySQLConnection();

    // 1. Fetch Request
    $stmt = $db->prepare("SELECT * FROM budget_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'drc_office') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DRC Office stage']); exit();
    }

    $allowedStatuses = ['dr_approved', 'sent_back_to_drc_office'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot forward request with status: {$req['status']}"]); exit();
    }

    $now = date('Y-m-d H:i:s');
    $timestamp = date('c');

    $db->beginTransaction();

    // 2. Update Request
    $updateStmt = $db->prepare("UPDATE budget_requests SET 
                                 status = 'drc_office_forwarded', 
                                 currentStage = 'drc_rc', 
                                 drcOfficeRemarks = ?, 
                                 updatedAt = ? 
                               WHERE id = ?");
    $updateStmt->execute([$remarks, $now, $requestId]);

    // 3. Insert History
    $histStmt = $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks) 
                              VALUES (?, 'drc_office', 'forwarded', ?, ?, ?)");
    $histStmt->execute([$requestId, $actionBy, $timestamp, $remarks]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Request forwarded to DR (R&C).',
        'data'    => ['status' => 'drc_office_forwarded', 'currentStage' => 'drc_rc'],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>