<?php
// api/raise-query.php
// Sets status = 'query_raised', stores previousStatus for restore on resolve.
// currentStage stays the same so the request stays with the reviewer.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input     = json_decode(file_get_contents('php://input'), true);
$requestId = $input['requestId'] ?? '';
$remarks   = trim($input['remarks'] ?? '');
$queryBy   = $input['queryBy']   ?? 'Unknown';
$queryTo   = $input['queryTo']   ?? 'pi';

if (!$requestId) { echo json_encode(['success' => false, 'message' => 'requestId required']); exit(); }
if (empty($remarks)) { echo json_encode(['success' => false, 'message' => 'Query remarks required']); exit(); }

try {
    $db = getMySQLConnection();
    
    // Fetch request metadata
    $stmt = $db->prepare("SELECT status, currentStage FROM budget_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) { echo json_encode(['success' => false, 'message' => 'Request not found']); exit(); }
    if (in_array($req['status'], ['approved', 'rejected'])) {
        echo json_encode(['success' => false, 'message' => 'Cannot raise query on a closed request']); exit();
    }

    $stageLabels = [
        'da' => 'DA', 'ar' => 'AR', 'dr' => 'DR',
        'drc_office' => 'DRC Office', 'drc_rc' => 'DR (R&C)',
        'drc' => 'DRC', 'director' => 'Director',
    ];
    $stageLabel = $stageLabels[$req['currentStage']] ?? strtoupper($req['currentStage']);
    $now = date('Y-m-d H:i:s');

    $db->beginTransaction();

    // 1. Update request status
    $sql = "UPDATE budget_requests 
            SET status = 'query_raised', 
                previousStatus = ?, 
                hasOpenQuery = 1, 
                updatedAt = ? 
            WHERE id = ?";
    $db->prepare($sql)->execute([$req['status'], $now, $requestId]);

    // 2. Insert into budget_request_queries
    $sqlQuery = "INSERT INTO budget_request_queries (requestId, `by`, byLabel, `to`, query, stage, timestamp, resolved) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
    $db->prepare($sqlQuery)->execute([
        $requestId, $queryBy, $stageLabel, $queryTo, $remarks, $req['currentStage'], $now
    ]);

    // 3. Add to approval history
    $sqlHist = "INSERT INTO approval_history (requestId, stage, action, `by`, remarks, timestamp) 
                VALUES (?, ?, 'query_raised', ?, ?, ?)";
    $db->prepare($sqlHist)->execute([$requestId, $req['currentStage'], $queryBy, $remarks, $now]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Query raised to PI by $stageLabel. PI will respond on their dashboard.",
        'data'    => ['status' => 'query_raised', 'queriedBy' => $queryBy],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("raise-query error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>