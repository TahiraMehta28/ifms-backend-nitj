<?php
/**
 * update-actual-expenditure.php  v3
 *
 * ROOT CAUSE FIX (v2 bug):
 *   v2 re-aggregated amountBookedByPI using  '$sum' => '$amount'
 *   but create-budget-requests.php stores the booked amount as 'requestedAmount'
 *   (not 'amount'), so every time DA filled an actual expenditure, the
 *   re-aggregation summed nulls and reset amountBookedByPI to 0.
 *
 *   v3 uses '$requestedAmount' as the canonical booked field.
 *   It also falls back to '$amount' as a secondary field for legacy docs.
 *
 * Remaining formula (computed at read-time by get-pi-projects.php):
 *   Remaining = Released - Booked + (Booked - Actual) = Released - Actual
 *   (unused booking is returned to the available pool)
 *
 * Accepts:
 *   { requestId,  actual_exp }  ← per-request (DA dashboard, preferred)
 *   { projectId,  actual_exp }  ← legacy project-level
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Project.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$input             = json_decode(file_get_contents('php://input'), true);
$requestId         = trim($input['requestId']   ?? '');
$projectId         = trim($input['projectId']   ?? '');
$actual_exp = $input['actual_exp'] ?? $input['actualExpenditure'] ?? null;

if ($actual_exp === null || $actual_exp === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'actual_exp is required']);
    exit();
}
$actual_exp = floatval($actual_exp);
if ($actual_exp < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'actual_exp cannot be negative']);
    exit();
}

try {
    $db = getMySQLConnection();
    $now = date('Y-m-d H:i:s');


    $db->beginTransaction();

    /* ── PATH A: Per-request update (Preferred) ─────────────────────────── */
    if ($requestId !== '') {
        $stmtReq = $db->prepare("SELECT projectId, status, requestedAmount, headId, headName FROM budget_requests WHERE id = ? FOR UPDATE");
        $stmtReq->execute([$requestId]);
        $br = $stmtReq->fetch();

        if (!$br) throw new Exception('Budget request not found');
        if ($br['status'] !== 'approved') throw new Exception('Actual expenditure can only be entered for approved requests');

        if ($projectId === '') $projectId = $br['projectId'];

        // Validate individual request cap
        if ($actual_exp > floatval($br['requestedAmount']) + 0.01) {
            throw new Exception('Actual expenditure exceeds booked amount for this request');
        }

        // Update Request
        $stmtUpd = $db->prepare("UPDATE budget_requests SET actual_exp = ?, actual_expEnteredBy = 'DA Officer', actual_expEnteredAt = ?, updatedAt = ? WHERE id = ?");
        $stmtUpd->execute([$actual_exp, $now, $now, $requestId]);

        $projectModel = new Project($db);
        $totals = $projectModel->syncFinancialTotals($projectId);

        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Expenditure saved and project totals synchronized.',
            'data' => array_merge($totals, ['requestId' => $requestId, 'projectId' => $projectId])
        ]);
        exit();
    }

    /* ── PATH B: Legacy project-level update ────────────────────────────── */
    if ($projectId === '') throw new Exception('Either requestId or projectId is required');

    $db->prepare("UPDATE projects SET actual_exp = ?, updatedAt = ? WHERE id = ?")
       ->execute([$actual_exp, $now, $projectId]);

    $projectModel = new Project($db);
    $projectModel->syncFinancialTotals($projectId);

    $db->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Actual expenditure updated (project level)',
        'data' => ['projectId' => $projectId, 'actual_exp' => $actual_exp]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("update-actual-expenditure error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>