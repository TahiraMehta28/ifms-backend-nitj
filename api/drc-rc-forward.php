<?php
// api/drc-rc-forward.php
// DR (R&C) forwards to DRC.
// ✅ NOW saves approvalType ("admin" | "admin_cum_financial") set by DR (R&C)
// ✅ Accepts both 'drc_office_forwarded' AND 'sent_back_to_drc_rc' (from DRC) for re-processing.

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

$input        = json_decode(file_get_contents('php://input'), true);
$requestId    = trim($input['requestId']    ?? '');
$remarks      = trim($input['remarks']      ?? '');
$actionBy     = trim($input['actionBy']     ?? 'DR R&C');
$approvalType = trim($input['approvalType'] ?? ''); // "admin" | "admin_cum_financial"

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

$allowedApprovalTypes = ['admin', 'admin_cum_financial'];
if (!in_array($approvalType, $allowedApprovalTypes)) {
    echo json_encode([
        'success' => false,
        'message' => 'approvalType is required. Must be "admin" or "admin_cum_financial".',
    ]); exit();
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
    if ($req['currentStage'] !== 'drc_rc') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DR (R&C) stage']); exit();
    }

    $allowedStatuses = ['drc_office_forwarded', 'sent_back_to_drc_rc'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot forward request with status: {$req['status']}"]); exit();
    }

    $now = date('Y-m-d H:i:s');
    $timestamp = date('c');

    $db->beginTransaction();

    // 2. Update Request
    $updateStmt = $db->prepare("UPDATE budget_requests SET 
                                 status = 'drc_rc_forwarded', 
                                 currentStage = 'drc', 
                                 drcRcRemarks = ?, 
                                 approvalType = ?, 
                                 updatedAt = ? 
                               WHERE id = ?");
    $updateStmt->execute([$remarks, $approvalType, $now, $requestId]);

    // 3. Insert History
    $histStmt = $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks, approvalType) 
                              VALUES (?, 'drc_rc', 'forwarded', ?, ?, ?, ?)");
    $histStmt->execute([$requestId, $actionBy, $timestamp, $remarks, $approvalType]);

    $db->commit();

    $approvalTypeLabel = $approvalType === 'admin' ? 'Admin Approval' : 'Admin cum Financial Approval';

    echo json_encode([
        'success' => true,
        'message' => "Request forwarded to DRC. Approval type: {$approvalTypeLabel}.",
        'data'    => [
            'status'       => 'drc_rc_forwarded',
            'currentStage' => 'drc',
            'approvalType' => $approvalType,
        ],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>