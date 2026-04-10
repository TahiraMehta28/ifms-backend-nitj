<?php
// api/drc-special-approve.php
// DRC Special Approval — DRC directly approves without forwarding to Director.
// ✅ approvalType is set by DR (R&C) and read from DB — DRC cannot change it.
// ✅ Sets status = "approved", currentStage stays "drc"
// ✅ specialApproval = true flags this for certificate generation

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

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = trim($input['requestId']  ?? '');
$remarks    = trim($input['remarks']    ?? '');
$approvedBy = trim($input['approvedBy'] ?? 'DRC');

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId is required']); exit();
}
if (empty($remarks)) {
    echo json_encode(['success' => false, 'message' => 'Remarks are required for special approval.']); exit();
}

try {
    $db = getMySQLConnection();
    $db->beginTransaction();

    // 1. Fetch request
    $stmt = $db->prepare("SELECT * FROM budget_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) throw new Exception('Request not found');
    if ($req['currentStage'] !== 'drc') throw new Exception('Request is not at DRC stage');

    $allowedStatuses = ['drc_rc_forwarded', 'sent_back_to_drc'];
    if (!in_array($req['status'], $allowedStatuses)) {
        throw new Exception("Cannot approve request with current status: {$req['status']}");
    }

    $approvalType = trim((string)($req['approvalType'] ?? ''));
    if (!in_array($approvalType, ['admin', 'admin_cum_financial'])) {
        throw new Exception('Approval type has not been set by DR (R&C).');
    }

    $projectId = $req['projectId'];
    $headId = $req['headId'];
    $amount = floatval($req['requestedAmount']);
    $now = date('Y-m-d H:i:s');

    // 2. Book the funds (Consistency check: Approved means Booked)
    // Fetch head allocation
    $stmt = $db->prepare("SELECT * FROM head_allocations WHERE projectId = ? AND headId = ? FOR UPDATE");
    $stmt->execute([$projectId, $headId]);
    $headAlloc = $stmt->fetch();
    if (!$headAlloc) throw new Exception('Head allocation not found');

    $available = floatval($headAlloc['releasedAmount']) - floatval($headAlloc['bookedAmount']);
    if ($amount > $available) {
        throw new Exception("Insufficient balance for special approval. Available: ₹" . number_format($available, 2));
    }

    // Update head_allocations
    $db->prepare("UPDATE head_allocations SET bookedAmount = bookedAmount + ?, updatedAt = ? WHERE projectId = ? AND headId = ?")
       ->execute([$amount, $now, $projectId, $headId]);

    // Update Project
    $db->prepare("UPDATE projects SET amountBookedByPI = amountBookedByPI + ?, updatedAt = ? WHERE id = ?")
       ->execute([$amount, $now, $projectId]);

    // 3. Update Request Status
    $db->prepare("UPDATE budget_requests SET status = 'approved', currentStage = 'drc', drcRemarks = ?, specialApproval = 1, approvedBy = ?, approvedAt = ?, drcApprovedAt = ?, updatedAt = ? WHERE id = ?")
       ->execute([$remarks, $approvedBy, $now, $now, $now, $requestId]);

    // 4. Log to history
    $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks, approvalType, specialApproval) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$requestId, 'drc', 'special_approved', $approvedBy, $now, $remarks, $approvalType, 1]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Request approved by DRC (Special Approval). Balances updated.",
        'data'    => [
            'status'          => 'approved',
            'currentStage'    => 'drc',
            'approvalType'    => $approvalType,
            'specialApproval' => true,
        ],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>