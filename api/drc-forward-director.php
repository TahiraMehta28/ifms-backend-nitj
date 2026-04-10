<?php
// api/drc-forward-director.php
// DRC → forward to Director.
// ✅ approvalType is set by DR (R&C) and already stored on the document.
//    DRC does NOT set or send approvalType — it is read directly from the DB.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

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
$requestId = trim($input['requestId']   ?? '');
$remarks   = trim($input['remarks']     ?? '');
$by        = trim($input['forwardedBy'] ?? 'DRC');

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
    if ($req['currentStage'] !== 'drc') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DRC stage']); exit();
    }

    $allowedStatuses = ['drc_rc_forwarded', 'sent_back_to_drc'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status for DRC forward action']); exit();
    }

    // 2. Read approvalType (set by DR R&C)
    $approvalType = trim((string)($req['approvalType'] ?? ''));
    $allowedApprovalTypes = ['admin', 'admin_cum_financial'];

    if (!in_array($approvalType, $allowedApprovalTypes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Approval type has not been set by DR (R&C). Please send this request back to DR (R&C) to set the approval type before forwarding.',
        ]); exit();
    }

    $now = date('Y-m-d H:i:s');
    $timestamp = date('c');

    $db->beginTransaction();

    // 3. Update Request
    $updateStmt = $db->prepare("UPDATE budget_requests SET 
                                 status = 'drc_forwarded', 
                                 currentStage = 'director', 
                                 drcRemarks = ?, 
                                 updatedAt = ? 
                               WHERE id = ?");
    $updateStmt->execute([$remarks, $now, $requestId]);

    // 4. Insert History
    $histStmt = $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks, approvalType) 
                              VALUES (?, 'drc', 'forwarded', ?, ?, ?, ?)");
    $histStmt->execute([$requestId, $by, $timestamp, $remarks, $approvalType]);

    $db->commit();

    $approvalTypeLabel = $approvalType === 'admin' ? 'Admin Approval' : 'Admin cum Financial Approval';

    echo json_encode([
        'success' => true,
        'message' => "Forwarded to Director. Approval type: {$approvalTypeLabel} (set by DR R&C).",
        'data'    => [
            'status'       => 'drc_forwarded',
            'currentStage' => 'director',
            'approvalType' => $approvalType,
        ],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>