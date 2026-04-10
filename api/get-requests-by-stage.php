<?php
// api/get-requests-by-stage.php
// Returns ALL fields including material (pt7), expenditure (pt7), mode (pt8),
// quotationFile base64, fileNumber, all stage remarks, latestQuery, approvalHistory.
//
// Sendback statuses per stage (what each stage sees as "sent back to me"):
//   da         ← sent_back_to_da         (from AR)
//   ar         ← sent_back_to_ar         (from DR)
//   dr         ← sent_back_to_dr         (from DRC Office)
//   drc_office ← sent_back_to_drc_office (from DR (R&C))
//   drc_rc     ← sent_back_to_drc_rc     (from DRC)
//   drc        ← sent_back_to_drc        (from Director)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

error_reporting(0);
ini_set('display_errors', 0);


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/../config/database.php';

// Catch fatal errors and return as JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Access-Control-Allow-Origin: *');
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['success' => false, 'message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
        exit();
    }
});

$stage    = $_GET['stage']    ?? '';
$type     = $_GET['type']     ?? 'pending';
$withFile = ($_GET['withFile'] ?? '1') !== '0';

// ── Status Mappings (Same as before) ──────────────────
$sentBackByMe = [
    'ar'         => 'sent_back_to_da',
    'dr'         => 'sent_back_to_ar',
    'drc_office' => 'sent_back_to_dr',
    'drc_rc'     => 'sent_back_to_drc_office',
    'drc'        => 'sent_back_to_drc_rc',
    'director'   => 'sent_back_to_drc',
];

try {
    $db = getMySQLConnection();

    $requestId = $_GET['requestId'] ?? null;
    $where = [];
    $params = [];

    if ($requestId) {
        $where[] = "br.id = ?";
        $params[] = $requestId;
    } elseif ($type === 'completed') {
        $where[] = "br.status IN ('approved', 'rejected')";
    } elseif ($stage === 'all' || $type === 'all') {
        // No extra filters
    } elseif ($type === 'sentback') {
        if (isset($sentBackByMe[$stage])) {
            $where[] = "br.status = ?";
            $params[] = $sentBackByMe[$stage];
        } else {
            $where[] = "br.status LIKE 'sent_back_to_%'";
        }
    } elseif ($type === 'pending') {
        switch ($stage) {
            case 'da':
                $where[] = "(br.currentStage = 'da' AND br.status IN ('pending', 'sent_back_to_da'))";
                break;
            case 'ar':
                $where[] = "(br.currentStage = 'ar' AND br.status IN ('da_approved', 'sent_back_to_ar'))";
                break;
            case 'dr':
                $where[] = "(br.currentStage = 'dr' AND br.status IN ('ar_approved', 'sent_back_to_dr'))";
                break;
            case 'drc_office':
                $where[] = "(br.currentStage = 'drc_office' AND br.status IN ('dr_approved', 'sent_back_to_drc_office'))";
                break;
            case 'drc_rc':
                $where[] = "(br.currentStage = 'drc_rc' AND br.status IN ('drc_office_forwarded', 'sent_back_to_drc_rc'))";
                break;
            case 'drc':
                $where[] = "(br.currentStage = 'drc' AND br.status IN ('drc_rc_forwarded', 'sent_back_to_drc'))";
                break;
            case 'director':
                $where[] = "(br.currentStage = 'director' AND br.status = 'drc_forwarded')";
                break;
        }
    } elseif ($type === 'forwarded') {
        switch ($stage) {
            case 'da':
                $where[] = "br.status NOT IN ('pending', 'sent_back_to_da', 'rejected')";
                break;
            case 'ar':
                $where[] = "br.status NOT IN ('pending', 'sent_back_to_da', 'da_approved', 'sent_back_to_ar', 'rejected')";
                break;
            case 'dr':
                $where[] = "br.status IN ('dr_approved', 'approved')";
                break;
            case 'drc_office':
                $where[] = "br.status IN ('drc_office_forwarded', 'sent_back_to_dr')";
                break;
            case 'drc_rc':
                $where[] = "br.status IN ('drc_rc_forwarded', 'sent_back_to_drc_office')";
                break;
            case 'drc':
                $where[] = "br.status IN ('drc_forwarded', 'sent_back_to_drc_rc')";
                break;
            case 'director':
                $where[] = "br.status = 'approved'";
                break;
        }
    }

    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $limit  = intval($_GET['limit']  ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $summary = ($_GET['summary'] ?? '0') === '1';

    try {
        // ── Main Query ──────────────────
        $fileCol = ($withFile && !$summary) ? ", br.quotation" : "";
        $sql = "SELECT br.id, br.requestNumber, br.projectId, br.gpNumber, br.fileNumber, 
                       br.projectTitle, br.piName, br.piEmail, br.department, br.headId, 
                       br.headName, br.headType, br.projectType, br.requestedAmount, 
                       br.status, br.previousStatus, br.currentStage, br.hasOpenQuery, 
                       br.daRemarks, br.arRemarks, br.drRemarks, br.drcOfficeRemarks, 
                       br.drcRcRemarks, br.drcRemarks, br.directorRemarks, 
                       br.rejectedBy, br.rejectedAtStage, br.rejectionRemarks,
                       br.createdAt, br.updatedAt, br.actual_exp, br.requestedAmount as amount,
                       br.purpose, br.description, br.material, br.expenditure, br.mode, 
                       br.invoiceNumber, br.approvalType,
                       br.quotationFileName $fileCol,
                       p.projectName as projectTitle_table, 
                       p.projectEndDate as projectEndDate_table, 
                       p.totalSanctionedAmount as projectSanctioned_table
                FROM budget_requests br
                LEFT JOIN projects p ON br.projectId = p.id
                $whereSql
                ORDER BY br.createdAt DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo json_encode(['success' => true, 'data' => [], 'count' => 0]);
            exit();
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "Query Failed: " . $e->getMessage(), 'sql' => $sql]);
        exit();
    }

    $requestIds = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

    // ── Fetch Approval History ──────────────────
    $histStmt = $db->prepare("SELECT * FROM approval_history WHERE requestId IN ($placeholders) ORDER BY id ASC");
    $histStmt->execute($requestIds);
    $allHistory = $histStmt->fetchAll();
    $histMap = [];
    foreach ($allHistory as $h) {
        $histMap[$h['requestId']][] = [
            'stage'        => $h['stage'],
            'action'       => $h['action'],
            'by'           => $h['by'],
            'timestamp'    => $h['timestamp'],
            'remarks'      => $h['remarks'],
            'approvalType' => $h['approvalType'] ?? null,
        ];
    }

    // ── Fetch Queries ──────────────────
    $queryStmt = $db->prepare("SELECT * FROM budget_request_queries WHERE requestId IN ($placeholders) AND resolved = 0 ORDER BY id DESC");
    $queryStmt->execute($requestIds);
    $allQueries = $queryStmt->fetchAll();
    $queryMap = [];
    foreach ($allQueries as $q) {
        if (!isset($queryMap[$q['requestId']])) {
            $queryMap[$q['requestId']] = [
                'query'         => $q['query'],
                'raisedBy'      => $q['by'],
                'raisedByLabel' => $q['byLabel'],
                'raisedAt'      => $q['timestamp'],
                'raisedStage'   => $q['stage'],
                'resolved'      => (bool)$q['resolved'],
                'piResponse'    => $q['piResponse']
            ];
        }
    }

    // ── Fetch Head Allocations (for specific head info) ──────────────────
    // In MySQL, we can join this or fetch separately. Let's fetch separately to match pMap logic.
    $projectIds = array_values(array_unique(array_column($rows, 'projectId')));
    if (!empty($projectIds)) {
        $pPlaceholders = implode(',', array_fill(0, count($projectIds), '?'));
        $headStmt = $db->prepare("SELECT * FROM head_allocations WHERE projectId IN ($pPlaceholders)");
        $headStmt->execute($projectIds);
        $allHeads = $headStmt->fetchAll();
        $headMap = [];
        foreach ($allHeads as $ha) {
            $key = $ha['headId'] ?: $ha['headName'];
            $sanctioned = floatval($ha['releasedAmount'] ?: $ha['sanctionedAmount']);
            $headMap[$ha['projectId']][$key] = [
                'sanctioned' => $sanctioned,
                'booked'     => floatval($ha['bookedAmount']),
                'type'       => $ha['headType']
            ];
        }
    }

    $result = [];
    foreach ($rows as $r) {
        $pid = $r['projectId'];
        $hKey = $r['headId'] ?: $r['headName'];
        $headInfo = $headMap[$pid][$hKey] ?? null;

        $history = $histMap[$r['id']] ?? [];
        $latestRemark = '';
        if (!empty($history)) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (!empty($history[$i]['remarks'])) {
                    $latestRemark = $history[$i]['remarks'];
                    break;
                }
            }
        }

        $result[] = [
            'id'               => $r['id'],
            'requestNumber'    => $r['requestNumber'],
            'projectId'        => $pid,
            'gpNumber'         => $r['gpNumber'],
            'fileNumber'       => $r['fileNumber'],
            'projectTitle'     => $r['projectTitle'],
            'piName'           => $r['piName'],
            'piEmail'          => $r['piEmail'],
            'department'       => $r['department'],
            'headId'           => $r['headId'],
            'headName'         => $r['headName'],
            'headType'         => $r['headType'],
            'projectType'      => $r['projectType'],
            'amount'           => floatval($r['requestedAmount']),
            'requestedAmount'  => floatval($r['requestedAmount']),
            'projectEndDate'   => $r['projectEndDate_table'] ?? $r['projectCompletionDate'] ?? null,
            'totalSanctionedAmount' => floatval($r['projectSanctioned_table'] ?? 0),
            'headSanctionedAmount' => floatval($headInfo['sanctioned'] ?? 0),
            'headBookedAmount'     => floatval($headInfo['booked'] ?? 0),
            'actual_exp'=> floatval($r['actual_exp'] ?? 0),
            'invoiceNumber'    => $r['invoiceNumber'] ?? '',
            'purpose'          => $r['purpose'] ?? '',
            'description'      => $summary ? '' : ($r['description'] ?? ''),
            'material'         => $summary ? '' : ($r['material'] ?? ''),
            'expenditure'      => $summary ? '' : ($r['expenditure'] ?? ''),
            'mode'             => $summary ? '' : ($r['mode'] ?? ''),

            'quotationFile'    => ($withFile && !$summary) ? ($r['quotation'] ?? '') : '',
            'quotationFileName'=> $r['quotationFileName'] ?? '',
            'status'           => $r['status'],
            'previousStatus'   => $r['previousStatus'],
            'currentStage'     => $r['currentStage'],
            'approvalType'     => $r['approvalType'] ?? '',

            'hasOpenQuery'     => (bool)$r['hasOpenQuery'],
            'isSentBack'       => str_starts_with($r['status'], 'sent_back_to_'),
            'daRemarks'        => $summary ? '' : $r['daRemarks'],
            'arRemarks'        => $summary ? '' : $r['arRemarks'],
            'drRemarks'        => $summary ? '' : $r['drRemarks'],
            'drcOfficeRemarks' => $summary ? '' : $r['drcOfficeRemarks'],
            'drcRcRemarks'     => $summary ? '' : $r['drcRcRemarks'],
            'drcRemarks'       => $summary ? '' : $r['drcRemarks'],
            'directorRemarks'  => $summary ? '' : $r['directorRemarks'],
            'latestQuery'      => $queryMap[$r['id']] ?? null,
            'approvalHistory'  => $history,  // always include — needed for timeline in all modes
            'latestRemark'     => $latestRemark,
            'approvalType'     => $r['approvalType'] ?? null,
            'specialApproval'  => (bool)($r['specialApproval'] ?? false),
            'rejectedBy'       => $r['rejectedBy'],
            'rejectedAtStage'  => $r['rejectedAtStage'],
            'rejectionRemarks' => $r['rejectionRemarks'],
            'createdAt'        => $r['createdAt'],
            'updatedAt'        => $r['updatedAt'],
        ];
    }

    echo json_encode(['success' => true, 'data' => $result, 'count' => count($result)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>