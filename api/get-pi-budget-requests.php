<?php
// api/get-pi-budget-requests.php — WITH QUERY + REJECTION + FILE FIELDS

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

ob_start();

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

ob_start();

try {
    $db = getMySQLConnection();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Only GET method allowed');

    $piEmail  = $_GET['piEmail']   ?? '';
    $withFile = ($_GET['withFile'] ?? '1') !== '0';
    if (empty($piEmail)) throw new Exception('PI email is required');

    // 1. Fetch primary budget requests joined with projects
    $stmt = $db->prepare("
        SELECT r.*, p.projectEndDate 
        FROM budget_requests r
        JOIN projects p ON r.projectId = p.id
        WHERE r.piEmail = ?
        ORDER BY r.createdAt DESC
    ");
    $stmt->execute([$piEmail]);
    $rawResults = $stmt->fetchAll();

    $stageLabels = [
        'da' => 'Dealing Assistant', 'ar' => 'Accounts Representative',
        'dr' => 'Deputy Registrar', 'drc_office' => 'DRC Office',
        'drc_rc' => 'DR (R&C)', 'drc' => 'DRC', 'director' => 'Director',
    ];

    $requests = [];
    foreach ($rawResults as $req) {
        $requestId = $req['id'];
        
        // 2. Fetch Queries for this request
        $qStmt = $db->prepare("SELECT * FROM budget_request_queries WHERE requestId = ? ORDER BY timestamp DESC");
        $qStmt->execute([$requestId]);
        $allQueries = $qStmt->fetchAll();
        
        $queries = [];
        $latestQuery = null;
        foreach ($allQueries as $q) {
            $mappedQ = [
                'by'         => $q['raisedBy'],
                'byLabel'    => $q['raisedByLabel'],
                'to'         => 'PI',
                'query'      => $q['queryText'],
                'stage'      => $q['raisedStage'],
                'timestamp'  => $q['timestamp'],
                'resolved'   => (bool)$q['resolved'],
                'piResponse' => $q['piResponse'] ?: '',
                'resolvedAt' => $q['resolvedAt'] ?: ''
            ];
            $queries[] = $mappedQ;
            if (!$latestQuery) $latestQuery = $mappedQ;
        }

        // 3. Fetch Approval History
        $hStmt = $db->prepare("SELECT * FROM approval_history WHERE requestId = ? ORDER BY timestamp ASC");
        $hStmt->execute([$requestId]);
        $history = $hStmt->fetchAll();

        // 4. Quotation logic
        $quotationFile = ($withFile && !empty($req['quotation'])) ? $req['quotation'] : '';
        $quotationFileName = $req['quotationFileName'] ?: ($req['invoiceNumber'] ? 'Quotation_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $req['invoiceNumber']).'.pdf' : 'Quotation.pdf');

        $requests[] = [
            'id'                   => $requestId,
            'requestNumber'        => $req['requestNumber'],
            'projectId'            => $req['projectId'],
            'gpNumber'             => $req['gpNumber'],
            'fileNumber'           => $req['fileNumber'],
            'projectEndDate'       => $req['projectEndDate'],
            'projectTitle'         => $req['projectTitle'],
            'projectType'          => $req['projectType'],
            'piName'               => $req['piName'],
            'piEmail'              => $req['piEmail'],
            'department'           => $req['department'],
            'headId'               => $req['headId'],
            'headName'             => $req['headName'],
            'headType'             => $req['headType'],
            'amount'               => floatval($req['requestedAmount']),
            'requestedAmount'      => floatval($req['requestedAmount']),
            'actual_exp'    => floatval($req['actual_exp']),
            'purpose'              => $req['purpose'],
            'description'          => $req['description'],
            'material'             => $req['material'],
            'expenditure'          => $req['mode'], // Backward compatibility
            'mode'                 => $req['mode'],
            'invoiceNumber'        => $req['invoiceNumber'],
            'status'               => $req['status'],
            'previousStatus'       => $req['previousStatus'],
            'currentStage'         => $req['currentStage'],
            'daRemarks'            => $req['daRemarks'],
            'arRemarks'            => $req['arRemarks'],
            'drRemarks'            => $req['drRemarks'],
            'drcOfficeRemarks'     => $req['drcOfficeRemarks'],
            'drcRcRemarks'         => $req['drcRcRemarks'],
            'drcRemarks'           => $req['drcRemarks'],
            'directorRemarks'      => $req['directorRemarks'],

            'hasOpenQuery'         => (bool)$req['hasOpenQuery'],
            'latestQuery'          => $latestQuery,
            'queries'              => $queries,
            
            'rejectedBy'           => $req['rejectedBy'],
            'rejectedAtStage'      => $req['rejectedAtStage'],
            'rejectedAtStageLabel' => $req['rejectedAtStageLabel'],
            'rejectionRemarks'     => $req['rejectionRemarks'],
            
            'quotationFile'        => $quotationFile,
            'quotationFileName'    => $quotationFileName,
            'approvalHistory'      => $history,
            'createdAt'            => $req['createdAt'],
            'updatedAt'            => $req['updatedAt'],
        ];
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'data' => $requests, 'count' => count($requests)]);

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    error_log("Get PI Budget Requests Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>