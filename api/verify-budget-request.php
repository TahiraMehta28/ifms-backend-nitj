<?php
/**
 * Verify Budget Request API (Admin)
 * 
 * Admin approves or rejects a budget request
 * On approval, updates:
 * - head_allocations.bookedAmount
 * - projects.amountBookedByPI
 * 
 * Method: POST
 * Body: {
 *   requestId, action ("approve" or "reject"), 
 *   adminName, adminRemarks
 * }
 */

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
ob_start();

try {
    $db = getMySQLConnection();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Only POST method is allowed');

    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid JSON');

    // Validation
    if (empty($data['requestId'])) throw new Exception('Request ID is required');
    if (empty($data['action']) || !in_array($data['action'], ['approve', 'reject'])) throw new Exception('Valid action required');
    if (empty($data['adminName'])) throw new Exception('Admin name is required');

    $requestId = $data['requestId'];
    $action = $data['action'];
    $adminName = htmlspecialchars(strip_tags($data['adminName']));
    $adminRemarks = htmlspecialchars(strip_tags($data['adminRemarks'] ?? ''));

    $db->beginTransaction();

    // 1. Get the budget request
    $stmt = $db->prepare("SELECT * FROM budget_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) throw new Exception('Budget request not found');

    if ($request['status'] !== 'pending_admin_verification') {
        throw new Exception('This request has already been processed or is in a different stage');
    }

    $projectId = $request['projectId'];
    $headId = $request['headId'];
    $requestedAmount = floatval($request['requestedAmount']);
    $now = date('Y-m-d H:i:s');

    if ($action === 'approve') {
        // 2. Get and validate head allocation
        $stmt = $db->prepare("SELECT * FROM head_allocations WHERE projectId = ? AND headId = ? FOR UPDATE");
        $stmt->execute([$projectId, $headId]);
        $headAlloc = $stmt->fetch();
        if (!$headAlloc) throw new Exception('Head allocation not found');

        $available = floatval($headAlloc['releasedAmount']) - floatval($headAlloc['bookedAmount']);
        if ($requestedAmount > $available) {
            throw new Exception("Insufficient balance. Available: ₹" . number_format($available, 2));
        }

        // 3. Update head_allocations
        $stmt = $db->prepare("UPDATE head_allocations SET bookedAmount = bookedAmount + ?, updatedAt = ? WHERE projectId = ? AND headId = ?");
        $stmt->execute([$requestedAmount, $now, $projectId, $headId]);

        // 4. Update project
        $stmt = $db->prepare("UPDATE projects SET amountBookedByPI = amountBookedByPI + ?, updatedAt = ? WHERE id = ?");
        $stmt->execute([$requestedAmount, $now, $projectId]);

        $newStatus = 'approved';
        $statusNote = "Approved by {$adminName}";
    } else {
        $newStatus = 'rejected';
        $statusNote = "Rejected by {$adminName}" . ($adminRemarks ? ": {$adminRemarks}" : "");
    }

    // 5. Update budget request status
    $stmt = $db->prepare("UPDATE budget_requests SET status = ?, adminVerifiedBy = ?, adminVerifiedAt = ?, adminRemarks = ?, updatedAt = ? WHERE id = ?");
    $stmt->execute([$newStatus, $adminName, $now, $adminRemarks, $now, $requestId]);

    // 6. Log to approval_history
    $stmt = $db->prepare("INSERT INTO approval_history (requestId, stage, action, `by`, timestamp, remarks) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$requestId, 'admin_verification', $action, $adminName, $now, $adminRemarks]);

    $db->commit();
    ob_end_clean();

    echo json_encode([
        'success' => true,
        'message' => ($action === 'approve' ? "Approved successfully." : "Rejected successfully."),
        'data' => [
            'requestId' => $requestId,
            'requestNumber' => $request['requestNumber'],
            'status' => $newStatus
        ]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (ob_get_length()) ob_end_clean();
    error_log("Verify Budget Request Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>