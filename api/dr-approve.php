<?php
// api/dr-approve.php
// DR gives FINAL approval ONLY IF: amount <= 25000 AND headType = 'consumable'
// Otherwise, always forwards to DRC Office (even if <= 25k but non-consumable).
// Accepts both 'ar_approved' AND 'sent_back_to_dr' (from DRC Office) for re-processing.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input      = json_decode(file_get_contents('php://input'), true);
$requestId  = $input['requestId']  ?? '';
$remarks    = trim($input['remarks'] ?? '');
$approvedBy = $input['approvedBy'] ?? 'DR';

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

$DR_THRESHOLD = 25000;

try {
    $db = getMySQLConnection();

    // 1. Fetch Request
    $stmt = $db->prepare("SELECT * FROM budget_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if ($req['currentStage'] !== 'dr') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DR stage']); exit();
    }

    $allowedStatuses = ['ar_approved', 'sent_back_to_dr'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot process request with status: {$req['status']}"]); exit();
    }

    $amount = floatval($req['requestedAmount'] ?: $req['amount'] ?: 0);
    $headType = strtolower(trim((string)($req['headType'] ?? '')));
    
    // Logic: DR is final if Consumable AND <= 25k
    $isDRFinal = ($amount <= $DR_THRESHOLD) && ($headType === 'consumable');
    
    $action = $isDRFinal ? 'approved' : 'forwarded';
    $newStatus = $isDRFinal ? 'approved' : 'dr_approved';
    $newStage = $isDRFinal ? 'dr' : 'drc_office';
    $timestamp = date('c');
    $now = date('Y-m-d H:i:s');

    // 2. Start Transaction
    $db->beginTransaction();

    // 3. Update Request
    $updateSql = "UPDATE budget_requests SET 
                    status = ?, 
                    currentStage = ?, 
                    drRemarks = ?, 
                    updatedAt = ? " . ($isDRFinal ? ", approvedAt = ?" : "") . "
                  WHERE id = ?";
    
    $updateParams = [$newStatus, $newStage, $remarks, $now];
    if ($isDRFinal) $updateParams[] = $now;
    $updateParams[] = $requestId;

    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute($updateParams);

    // 4. Insert History
    $histStmt = $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks) 
                              VALUES (?, 'dr', ?, ?, ?, ?)");
    $histStmt->execute([$requestId, $action, $approvedBy, $timestamp, $remarks]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $isDRFinal ? 'Request finally approved by DR.' : 'Request forwarded by DR to DRC Office.',
        'data'    => ['status' => $newStatus, 'currentStage' => $newStage],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>