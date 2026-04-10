<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $db = getMySQLConnection();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Only POST method is allowed');
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['projectId']) || empty($data['allocations'])) {
        throw new Exception('Project ID and allocations are required');
    }
    
    $projectId = $data['projectId'];
    $gpNumber = $data['gpNumber'] ?? '';
    $newAllocations = $data['allocations'];
    
    $db->beginTransaction();

    /* ── 1. Fetch Fixed Project Totals ──────────────────────────────────── */
    $stmtProj = $db->prepare("SELECT totalSanctionedAmount, totalReleasedAmount, gpNumber FROM projects WHERE id = ? FOR UPDATE");
    $stmtProj->execute([$projectId]);
    $project = $stmtProj->fetch();
    
    if (!$project) throw new Exception('Project not found');
    
    $FIXED_TOTAL_SANCTIONED = floatval($project['totalSanctionedAmount'] ?? 0);
    $FIXED_TOTAL_RELEASED = floatval($project['totalReleasedAmount'] ?? 0);
    
    /* ── 2. Fetch Current Head Allocations ───────────────────────────────── */
    $stmtCurr = $db->prepare("SELECT headId, headName, sanctionedAmount, releasedAmount FROM head_allocations WHERE projectId = ?");
    $stmtCurr->execute([$projectId]);
    $currentAllocs = [];
    while ($row = $stmtCurr->fetch()) {
        $currentAllocs[$row['headId']] = $row;
    }

    /* ── 3. Validate & Accumulate ────────────────────────────────────────── */
    $totalNewSanctioned = 0;
    $totalNewReleased = 0;
    $formatted = [];
    $now = date('Y-m-d H:i:s');

    foreach ($newAllocations as $alloc) {
        $headId = $alloc['id'];
        $headName = $alloc['headName'];
        $newSanc = floatval($alloc['sanctionedAmount']);
        $newRel = floatval($alloc['releasedAmount']);
        
        if (!isset($currentAllocs[$headId])) throw new Exception("Head not found in allocations: $headName");
        
        $currRel = floatval($currentAllocs[$headId]['releasedAmount']);
        
        if ($newSanc < $currRel - 0.01) {
            throw new Exception("Cannot reduce sanctioned for $headName (₹" . number_format($newSanc, 2) . ") below already released amount (₹" . number_format($currRel, 2) . ")");
        }
        
        if ($newRel > $newSanc + 0.01) {
            throw new Exception("Released (₹" . number_format($newRel, 2) . ") exceeds sanctioned (₹" . number_format($newSanc, 2) . ") for $headName");
        }
        
        $totalNewSanctioned += $newSanc;
        $totalNewReleased   += $newRel;
        
        $formatted[] = [
            'id' => $headId,
            'headName' => $headName,
            'headType' => $alloc['headType'] ?? '',
            'sanctionedAmount' => $newSanc,
            'releasedAmount' => $newRel,
            'remainingAmount' => $newSanc - $newRel,
            'status' => ($newRel >= $newSanc) ? 'fully_released' : ($newRel > 0 ? 'partially_released' : 'sanctioned')
        ];
    }

    if (abs($totalNewSanctioned - $FIXED_TOTAL_SANCTIONED) > 0.01) {
        throw new Exception("New total sanctioned (₹" . number_format($totalNewSanctioned, 2) . ") must equal project total (₹" . number_format($FIXED_TOTAL_SANCTIONED, 2) . ")");
    }

    if ($totalNewReleased > $FIXED_TOTAL_RELEASED + 0.01) {
        throw new Exception("New total released (₹" . number_format($totalNewReleased, 2) . ") exceeds project total released (₹" . number_format($FIXED_TOTAL_RELEASED, 2) . ")");
    }

    /* ── 4. Apply Updates ───────────────────────────────────────────────── */
    foreach ($formatted as $f) {
        $db->prepare("UPDATE head_allocations SET sanctionedAmount = ?, releasedAmount = ?, remainingAmount = ?, status = ?, updatedAt = ? WHERE projectId = ? AND headId = ?")
           ->execute([$f['sanctionedAmount'], $f['releasedAmount'], $f['remainingAmount'], $f['status'], $now, $projectId, $f['id']]);

        $db->prepare("UPDATE fund_allocation_items SET sanctionedAmount = ?, releasedAmount = ?, remainingAmount = ?, status = ? WHERE headId = ?")
           ->execute([$f['sanctionedAmount'], $f['releasedAmount'], $f['remainingAmount'], $f['status'], $f['id']]);
    }

    // Sync Fund Allocations table
    $db->prepare("UPDATE fund_allocations SET totalAllocated = ?, totalReleased = ?, updatedAt = ? WHERE projectId = ?")
       ->execute([$totalNewSanctioned, $totalNewReleased, $now, $projectId]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Allocations redistributed successfully.',
        'data' => [
            'totalSanctioned' => $totalNewSanctioned,
            'totalReleased' => $totalNewReleased,
            'headsCount' => count($formatted)
        ]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("Update Allocations Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>