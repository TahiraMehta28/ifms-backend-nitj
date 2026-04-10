<?php
// api/da-approve.php
// DA processes a request and forwards it to AR.
// Accepts both fresh 'pending' requests AND 'sent_back_to_da' requests from AR.

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
$approvedBy = $input['approvedBy'] ?? 'DA Officer';

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
    if ($req['currentStage'] !== 'da') {
        echo json_encode(['success' => false, 'message' => 'Request is not at DA stage']); exit();
    }

    $allowedStatuses = ['pending', 'sent_back_to_da'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => "Cannot process request with status: {$req['status']}"]); exit();
    }

    $now = date('Y-m-d H:i:s');
    $timestamp = date('c');

    $db->beginTransaction();

    // 2. Update Request
    $updateStmt = $db->prepare("UPDATE budget_requests SET 
                                 status = 'da_approved', 
                                 currentStage = 'ar', 
                                 daRemarks = ?, 
                                 updatedAt = ? 
                               WHERE id = ?");
    $updateStmt->execute([$remarks, $now, $requestId]);

    // 3. Insert History
    $histStmt = $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks) 
                              VALUES (?, 'da', 'approved', ?, ?, ?)");
    $histStmt->execute([$requestId, $approvedBy, $timestamp, $remarks]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Request processed by DA and forwarded to AR.',
        'data'    => ['status' => 'da_approved', 'currentStage' => 'ar'],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>