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

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

ob_start();

try {
    $db = getMySQLConnection();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Only GET method is allowed');

    $projectId = $_GET['projectId'] ?? null;
    if (!$projectId) throw new Exception('Project ID is required');

    // 1. Fetch from head_allocations
    $stmt = $db->prepare("SELECT * FROM head_allocations WHERE projectId = ? ORDER BY headName ASC");
    $stmt->execute([$projectId]);
    $allocs = $stmt->fetchAll();

    $allocationsArray = [];
    $totalSanctioned = 0;
    $totalReleased = 0;

    if (!empty($allocs)) {
        foreach ($allocs as $alloc) {
            $sanc = floatval($alloc['sanctionedAmount']);
            $rel = floatval($alloc['releasedAmount']);
            $allocationsArray[] = [
                'id' => $alloc['headId'] ?: $alloc['id'],
                'headId' => $alloc['headId'],
                'headName' => $alloc['headName'],
                'headType' => $alloc['headType'] ?: 'recurring',
                'sanctionedAmount' => $sanc,
                'releasedAmount' => $rel,
                'remainingAmount' => $sanc - $rel,
                'status' => $alloc['status'] ?: 'sanctioned',
                'releaseHistory' => [] // Detailed history fetched in separate API
            ];
            $totalSanctioned += $sanc;
            $totalReleased += $rel;
        }
    } else {
        // 2. Fallback: Initialize from project_heads_list
        $stmtP = $db->prepare("SELECT gpNumber FROM projects WHERE id = ?");
        $stmtP->execute([$projectId]);
        $project = $stmtP->fetch();
        
        if ($project) {
            $stmtH = $db->prepare("SELECT * FROM project_heads_list WHERE projectId = ?");
            $stmtH->execute([$projectId]);
            $heads = $stmtH->fetchAll();
            
            foreach ($heads as $h) {
                $headId = $h['headId'] ?: bin2hex(random_bytes(12));
                $sanc = floatval($h['sanctionedAmount']);
                
                // Insert into head_allocations
                $stmtIns = $db->prepare("
                    INSERT INTO head_allocations 
                    (id, projectId, gpNumber, headId, headName, headType, sanctionedAmount, releasedAmount, remainingAmount, status, createdAt, updatedAt)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'sanctioned', NOW(), NOW())
                ");
                $stmtIns->execute([
                    bin2hex(random_bytes(12)), $projectId, $project['gpNumber'], $headId, 
                    $h['headName'], $h['headType'], $sanc, $sanc
                ]);
                
                $allocationsArray[] = [
                    'id' => $headId,
                    'headId' => $headId,
                    'headName' => $h['headName'],
                    'headType' => $h['headType'],
                    'sanctionedAmount' => $sanc,
                    'releasedAmount' => 0,
                    'remainingAmount' => $sanc,
                    'status' => 'sanctioned',
                    'releaseHistory' => []
                ];
                $totalSanctioned += $sanc;
            }
        }
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => $allocationsArray,
        'totalSanctioned' => $totalSanctioned,
        'totalReleased' => $totalReleased,
        'totalRemaining' => $totalSanctioned - $totalReleased
    ]);

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    error_log("Fund Allocations API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>