<?php
/**
 * Get Release History API
 * Fetches head-wise release history from fund_releases collection
 */

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
    
    $projectId = $_GET['projectId'] ?? null;
    $headId = $_GET['headId'] ?? null;
    
    if (!$projectId) throw new Exception('projectId parameter is required');
    
    // Fetch audit logs for this project
    $where = ["projectId = ?"];
    $params = [$projectId];
    
    if ($headId) {
        $where[] = "headId = ?";
        $params[] = $headId;
    }
    
    $query = "SELECT * FROM release_audit_log WHERE " . implode(" AND ", $where) . " ORDER BY releaseDate DESC, id ASC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    // Group logs by releaseNumber to recreate the nested structure
    $grouped = [];
    foreach ($logs as $log) {
        $rn = $log['releaseNumber'];
        if (!isset($grouped[$rn])) {
            $grouped[$rn] = [
                'id' => $rn, // Use releaseNumber as stable ID for grouping
                'releaseNumber' => $rn,
                'gpNumber' => $log['gpNumber'],
                'letterNumber' => $log['letterNumber'],
                'letterDate' => $log['letterDate'],
                'remarks' => $log['remarks'],
                'totalReleaseAmount' => 0,
                'releasedBy' => $log['releasedBy'],
                'releasedAt' => $log['releaseDate'],
                'headwiseReleases' => []
            ];
        }
        
        $amt = floatval($log['amountReleased']);
        $grouped[$rn]['totalReleaseAmount'] += $amt;
        $grouped[$rn]['headwiseReleases'][] = [
            'id' => $log['headId'],
            'headId' => $log['headId'],
            'headName' => $log['headName'],
            'headType' => $log['headType'],
            'releaseAmount' => $amt,
        ];
    }
    
    $formattedReleases = array_values($grouped);
    
    // Calculate summaries for parity
    $totalAmountReleased = 0;
    $headsInvolved = [];
    foreach ($logs as $log) {
        $amt = floatval($log['amountReleased']);
        $totalAmountReleased += $amt;
        
        $hName = $log['headName'];
        if (!isset($headsInvolved[$hName])) {
            $headsInvolved[$hName] = [
                'headName' => $hName,
                'headType' => $log['headType'],
                'totalReleased' => 0,
                'releaseCount' => 0
            ];
        }
        $headsInvolved[$hName]['totalReleased'] += $amt;
        $headsInvolved[$hName]['releaseCount']++;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $formattedReleases,
        'summary' => [
            'totalReleases' => count($formattedReleases),
            'totalAmountReleased' => $totalAmountReleased,
            'headsInvolved' => array_values($headsInvolved)
        ],
        'filters' => [
            'projectId' => $projectId,
            'headId' => $headId
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get Release History Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>