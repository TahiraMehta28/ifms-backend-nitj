<?php
// api/reject-request.php — FIXED
// Universal rejection for ALL stages: da | ar | dr | drc_office | drc_rc | drc | director
// ✅ FIX: reads 'requestedAmount' ?? 'amount' consistently

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Project.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$stage      = $input['stage']      ?? '';
$remarks    = trim($input['remarks']    ?? '');
$rejectedBy = $input['rejectedBy'] ?? 'Unknown';

if (!$requestId || !$stage) {
    echo json_encode(['success' => false, 'message' => 'requestId and stage required']); exit();
}
if (empty($remarks)) {
    echo json_encode(['success' => false, 'message' => 'Remarks are required for rejection']); exit();
}

// Stage → required status before rejection is allowed
$stageStatusMap = [
    'da'         => ['pending'],
    'ar'         => ['da_approved'],
    'dr'         => ['ar_approved'],
    'drc_office' => ['dr_approved'],
    'drc_rc'     => ['drc_office_forwarded', 'sent_back_to_drc_rc'],
    'drc'        => ['drc_rc_forwarded', 'sent_back_to_drc'],
    'director'   => ['drc_forwarded'],
];

// Stage → field in budget_requests to store the remarks in
$remarkField = [
    'da'         => 'daRemarks',
    'ar'         => 'arRemarks',
    'dr'         => 'drRemarks',
    'drc_office' => 'drcOfficeRemarks',
    'drc_rc'     => 'drcRcRemarks',
    'drc'        => 'drcRemarks',
    'director'   => 'directorRemarks',
];

if (!array_key_exists($stage, $stageStatusMap)) {
    echo json_encode(['success' => false, 'message' => "Invalid stage: $stage"]); exit();
}

try {
    $db = getMySQLConnection();
    
    // Fetch request metadata
    $stmt = $db->prepare("SELECT projectId, status, currentStage FROM budget_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['status'] === 'rejected') {
        echo json_encode(['success' => false, 'message' => 'Already rejected']); exit();
    }
    if ($req['status'] === 'approved') {
        echo json_encode(['success' => false, 'message' => 'Cannot reject an approved request']); exit();
    }
    if ($req['currentStage'] !== $stage) {
        echo json_encode(['success' => false, 'message' => "Request is at stage '{$req['currentStage']}', not '{$stage}'"]); exit();
    }
    if (!in_array($req['status'], $stageStatusMap[$stage])) {
        echo json_encode(['success' => false, 'message' => "Invalid status '{$req['status']}' for rejection at $stage"]); exit();
    }

    $db->beginTransaction();

    $now = date('Y-m-d H:i:s');
    $column = $remarkField[$stage];

    // 1. Update request status
    $sql = "UPDATE budget_requests 
            SET status = 'rejected', 
                $column = ?, 
                rejectedBy = ?, 
                rejectedAtStage = ?, 
                updatedAt = ? 
            WHERE id = ?";
    $stmtUpdate = $db->prepare($sql);
    $stmtUpdate->execute([$remarks, $rejectedBy, $stage, $now, $requestId]);

    // 2. Add to approval history
    $sqlHist = "INSERT INTO approval_history (requestId, stage, action, `by`, remarks, timestamp) 
                VALUES (?, ?, 'rejected', ?, ?, ?)";
    $db->prepare($sqlHist)->execute([$requestId, $stage, $rejectedBy, $remarks, $now]);

    $db->commit();

    // 3. Sync project totals (after commit to ensure query finds the new 'rejected' status)
    $projectModel = new Project($db);
    $projectModel->syncFinancialTotals($req['projectId']);

    echo json_encode([
        'success' => true,
        'message' => "Request rejected at $stage stage.",
        'data'    => ['status' => 'rejected', 'currentStage' => $stage],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("reject-request error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>