<?php
// api/director-approve.php
// Director → final approve (> ₹25k DRC chain)
// stage: director → completed  |  status: drc_forwarded → approved

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
$requestId = $input['requestId']  ?? '';
$remarks   = $input['remarks']    ?? '';
$by        = $input['approvedBy'] ?? 'Director';

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
    if ($req['currentStage'] !== 'director' || $req['status'] !== 'drc_forwarded') {
        echo json_encode(['success' => false, 'message' => 'Request is not at Director stage']); exit();
    }

    $now = date('Y-m-d H:i:s');
    $timestamp = date('c');

    $db->beginTransaction();

    // 2. Update Request
    $updateStmt = $db->prepare("UPDATE budget_requests SET 
                                 status = 'approved', 
                                 currentStage = 'completed', 
                                 directorRemarks = ?, 
                                 updatedAt = ? 
                               WHERE id = ?");
    $updateStmt->execute([$remarks, $now, $requestId]);

    // 3. Insert History
    $histStmt = $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks) 
                              VALUES (?, 'director', 'approved', ?, ?, ?)");
    $histStmt->execute([$requestId, $by, $timestamp, $remarks]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Budget request fully approved by Director.',
        'data'    => ['status' => 'approved', 'currentStage' => 'completed'],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>