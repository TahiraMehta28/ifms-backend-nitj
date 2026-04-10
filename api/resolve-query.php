<?php
// api/resolve-query.php
// ✅ PI can now update ALL purchase fields:
//    purpose, description, invoiceNumber, material, mode, piResponse
// ✅ expenditure is NOT updated by PI — it stays as set by AR/DR
// ✅ fileNumber is ALWAYS kept as the EXISTING one — never changed
// ✅ Restores previousStatus so request goes back to the correct reviewer stage

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }


require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit();
}

$requestId     = trim($input['requestId']     ?? '');
$piEmail       = trim($input['piEmail']       ?? '');
$piResponse    = trim($input['piResponse']    ?? '');
$purpose       = trim($input['purpose']       ?? '');
$description   = trim($input['description']   ?? '');
$invoiceNumber = trim($input['invoiceNumber'] ?? '');
$material      = trim($input['material']      ?? '');
$mode          = trim($input['mode']          ?? '');
$newQuotation     = $input['newQuotation']     ?? '';
$newQuotationName = trim($input['newQuotationName'] ?? '');

if (!$requestId)  { echo json_encode(['success' => false, 'message' => 'requestId required']);  exit(); }
if (!$piResponse) { echo json_encode(['success' => false, 'message' => 'piResponse required']); exit(); }

try {
    $db = getMySQLConnection();
    
    // Fetch request metadata
    $stmt = $db->prepare("SELECT status, currentStage, previousStatus, piEmail, gpNumber, fileNumber, hasOpenQuery FROM budget_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']); exit();
    }
    if (!$req['hasOpenQuery'] && $req['status'] !== 'query_raised') {
        echo json_encode(['success' => false, 'message' => 'No open query on this request']); exit();
    }
    if ($piEmail && $req['piEmail'] !== $piEmail) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit();
    }

    $db->beginTransaction();

    $now = date('Y-m-d H:i:s');
    $restoredStatus = !empty($req['previousStatus']) ? $req['previousStatus'] : 'pending';
    
    // 1. Update budget_requests
    $updateSql = "UPDATE budget_requests 
                  SET status = ?, 
                      previousStatus = NULL, 
                      hasOpenQuery = 0, 
                      piResponse = ?, 
                      updatedAt = ?";
    $params = [$restoredStatus, $piResponse, $now];

    if (!empty($purpose)) { $updateSql .= ", purpose = ?"; $params[] = $purpose; }
    if (!empty($description)) { $updateSql .= ", description = ?"; $params[] = $description; }
    if (!empty($invoiceNumber)) { $updateSql .= ", invoiceNumber = ?"; $params[] = $invoiceNumber; }
    if (!empty($material)) { $updateSql .= ", material = ?"; $params[] = $material; }
    if (!empty($mode)) { $updateSql .= ", mode = ?"; $params[] = $mode; }

    if (!empty($newQuotation)) {
        $updateSql .= ", quotation = ?, quotationFileName = ?";
        $params[] = $newQuotation;
        
        $safeGp = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $req['gpNumber']);
        $safeFN = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $req['fileNumber']);
        $generatedName = "Quotation_Resubmitted_{$safeGp}_{$safeFN}.pdf";
        $params[] = $newQuotationName ?: $generatedName;
    }

    $updateSql .= " WHERE id = ?";
    $params[] = $requestId;
    
    $db->prepare($updateSql)->execute($params);

    // 2. Mark queries as resolved
    $sqlResolved = "UPDATE budget_request_queries 
                    SET resolved = 1, piResponse = ?, resolvedAt = ? 
                    WHERE requestId = ? AND resolved = 0";
    $db->prepare($sqlResolved)->execute([$piResponse, $now, $requestId]);

    // 3. Add to history
    $sqlHist = "INSERT INTO approval_history (requestId, stage, action, `by`, remarks, timestamp, approvalType) 
                VALUES (?, ?, 'query_resolved', ?, ?, ?, NULL)";
    $db->prepare($sqlHist)->execute([
        $requestId, $req['currentStage'], $piEmail ?: 'pi', $piResponse, $now
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Query resolved. Response submitted. Request returned to reviewer.",
        'data'    => ['status' => $restoredStatus, 'currentStage' => $req['currentStage']],
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("resolve-query error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>