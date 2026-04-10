<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $db = getMySQLConnection();

    // Determine current financial year (April to March)
    $now = new DateTime();
    $mon = (int)$now->format('n');
    $year = (int)$now->format('Y');
    
    if ($mon >= 4) {
        $fyS = $year; $fyE = $year + 1;
    } else {
        $fyS = $year - 1; $fyE = $year;
    }
    
    $fy = sprintf("%02d-%02d", $fyS % 100, $fyE % 100);
    
    // Find highest sequence: GP/FY/XXX
    // MySQL: ORDER BY gpNumber DESC is usually enough if formatted consistently
    $stmt = $db->prepare("SELECT gpNumber FROM projects WHERE gpNumber LIKE ? ORDER BY gpNumber DESC LIMIT 1");
    $stmt->execute(["GP/{$fy}/%"]);
    $last = $stmt->fetch();
    
    $nextIdx = 1;
    if ($last) {
        $parts = explode('/', $last['gpNumber']);
        if (count($parts) === 3) {
            $nextIdx = intval($parts[2]) + 1;
        }
    }
    
    $newGP = sprintf("GP/%s/%03d", $fy, $nextIdx);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'gpNumber' => $newGP,
            'financialYear' => $fy,
            'sequenceNumber' => $nextIdx,
            'lastGPNumber' => $last['gpNumber'] ?? null
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("get-next-gp-number: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>