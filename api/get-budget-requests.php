<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Catch fatal errors (e.g., missing vendor/autoload.php) and return as JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
    }
});

require_once __DIR__ . '/../config/database.php';

$stage = $_GET['stage'] ?? '';
$type  = $_GET['type']  ?? 'pending';
$requestId = $_GET['requestId'] ?? null;
$limit   = intval($_GET['limit']   ?? 0);
$offset  = intval($_GET['offset']  ?? 0);
$summary = ($_GET['summary'] ?? '0') === '1';

try {
    $db = getMySQLConnection();
    
    // Base columns to select
    $cols = "r.*, p.projectName as projectTitle_join, p.projectEndDate as projectEndDate_join, p.totalSanctionedAmount as projectTotalSanctioned,
             ha.sanctionedAmount as headSanctionedLimit, ha.releasedAmount as headReleasedLimit, ha.bookedAmount as headBookedLimit";
    
    // Build WHERE clause
    $where = ["1=1"];
    $params = [];

    if ($requestId) {
        $where[] = "r.id = ?";
        $params[] = $requestId;
    } elseif ($type === 'completed') {
        $where[] = "r.status IN ('approved', 'rejected')";
    } elseif ($stage === 'all' || $type === 'all') {
        // No specific filter
    } elseif ($type === 'pending') {
        if ($stage === 'da') {
            $where[] = "r.currentStage = 'da' AND r.status = 'pending'";
        } elseif ($stage === 'ar') {
            $where[] = "r.currentStage = 'ar' AND r.status = 'da_approved'";
        } elseif ($stage === 'dr') {
            $where[] = "r.currentStage = 'dr' AND r.status = 'ar_approved'";
        } else {
            $where[] = "r.currentStage IN ('ar', 'dr') AND r.status NOT IN ('approved', 'rejected')";
        }
    }

    $whereSql = "WHERE " . implode(" AND ", $where);
    
    // Sort and Paging
    $limitSql = "";
    if ($limit > 0) {
        $limitSql = " LIMIT $limit OFFSET $offset";
    }

    $query = "SELECT $cols 
              FROM budget_requests r
              JOIN projects p ON r.projectId = p.id
              LEFT JOIN head_allocations ha ON r.projectId = ha.projectId AND (r.headId = ha.headId OR r.headName = ha.headName)
              $whereSql
              ORDER BY r.createdAt DESC
              $limitSql";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        // Format dates
        $createdAt = $r['createdAt'] ? date('Y-m-d H:i:s', strtotime($r['createdAt'])) : '';
        $updatedAt = $r['updatedAt'] ? date('Y-m-d H:i:s', strtotime($r['updatedAt'])) : '';

        // Determine head sanctioned limit (match legacy logic: released if available, else sanctioned)
        $hLimit = floatval($r['headReleasedLimit'] ?: $r['headSanctionedLimit'] ?: 0);

        // Build history (we'll fetch separately if not summary)
        $history = [];
        if (!$summary) {
            $hStmt = $db->prepare("SELECT * FROM approval_history WHERE requestId = ? ORDER BY timestamp ASC");
            $hStmt->execute([$r['id']]);
            $history = $hStmt->fetchAll();
        }

        // Latest Remark
        $latestRemark = '';
        if (!empty($history)) {
            foreach (array_reverse($history) as $h) {
                if (!empty($h['remarks'])) {
                    $latestRemark = $h['remarks'];
                    break;
                }
            }
        }

        $amount = floatval($r['requestedAmount'] ?: $r['amount'] ?: 0);

        $item = [
            'id'              => $r['id'],
            'requestNumber'   => $r['requestNumber'],
            'projectId'       => $r['projectId'],
            'gpNumber'        => $r['gpNumber'],
            'projectTitle'    => $r['projectTitle_join'],
            'piName'          => $r['piName'],
            'piEmail'         => $r['piEmail'],
            'department'      => $r['department'],
            'purpose'         => $r['purpose'],
            'description'     => $summary ? '' : $r['description'],
            'amount'          => $amount,
            'requestedAmount' => $amount,
            'projectType'     => $r['projectType'],
            'invoiceNumber'   => $r['invoiceNumber'],
            'material'        => $summary ? '' : $r['material'],
            'expenditure'     => $summary ? '' : $r['expenditure'],
            'mode'            => $summary ? '' : $r['mode'],
            'fileNumber'      => $r['fileNumber'],
            'projectEndDate'  => $r['projectEndDate_join'],
            'totalSanctionedAmount' => floatval($r['projectTotalSanctioned']),
            'headId'          => $r['headId'],
            'headName'        => $r['headName'],
            'headType'        => $r['headType'],
            'headSanctionedAmount' => $hLimit,
            'headBookedAmount'     => floatval($r['headBookedLimit']),
            'status'          => $r['status'],
            'currentStage'    => $r['currentStage'],
            'daRemarks'       => $r['daRemarks'],
            'arRemarks'       => $r['arRemarks'],
            'drRemarks'       => $r['drRemarks'],
            'drcOfficeRemarks' => $r['drcOfficeRemarks'],
            'drcRcRemarks'     => $r['drcRcRemarks'],
            'drcRemarks'       => $r['drcRemarks'],
            'directorRemarks'  => $r['directorRemarks'],
            'actual_exp' => floatval($r['actual_exp'] ?: 0),
            'approvalHistory' => $history,
            'latestRemark'    => $latestRemark,
            'createdAt'       => $createdAt,
            'updatedAt'       => $updatedAt,
        ];

        $result[] = $item;
    }

    echo json_encode(['success' => true, 'data' => $result, 'count' => count($result)]);

} catch (Throwable $e) {
    error_log("get-budget-requests error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>