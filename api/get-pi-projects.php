<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$piEmail = $_GET['piEmail'] ?? '';
if (empty($piEmail)) {
    echo json_encode(['success' => false, 'message' => 'piEmail is required']); exit();
}

try {
    $db = getMySQLConnection();

    // ── 1. Fetch all active projects for this PI ───────────────
    $stmt = $db->prepare("SELECT * FROM projects WHERE piEmail = ? AND status NOT IN ('rejected', 'completed')");
    $stmt->execute([$piEmail]);
    $projectsRaw = $stmt->fetchAll();
    
    if (empty($projectsRaw)) {
        echo json_encode(['success' => true, 'data' => [], 'count' => 0]);
        exit();
    }

    $projectIds = array_map(fn($p) => $p['id'], $projectsRaw);
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

    // ── 2. Fetch all budget requests for these projects to calculate totals in PHP ──────────
    $sqlRequests = "SELECT projectId, status, requestedAmount, actual_exp 
                    FROM budget_requests 
                    WHERE projectId IN ($placeholders)";
    $stmtR = $db->prepare($sqlRequests);
    $stmtR->execute($projectIds);
    $allRequests = $stmtR->fetchAll();

    $projectTotals = [];
    $headTotals = [];
    foreach ($allRequests as $req) {
        $pid = $req['projectId'];
        if (!isset($projectTotals[$pid])) $projectTotals[$pid] = ['booked' => 0, 'actual' => 0];
        
        if ($req['status'] !== 'rejected') {
            $projectTotals[$pid]['booked'] += floatval($req['requestedAmount']);
        }
        if ($req['status'] === 'approved') {
            $projectTotals[$pid]['actual'] += floatval($req['actual_exp']);
        }
    }

    // ── 3. Head Totals Calc ────────────────────────────
    $sqlHeadReqs = "SELECT projectId, headId, headName, status, requestedAmount, actual_exp 
                    FROM budget_requests 
                    WHERE projectId IN ($placeholders)";
    $stmtHTR = $db->prepare($sqlHeadReqs);
    $stmtHTR->execute($projectIds);
    $headReqs = $stmtHTR->fetchAll();

    foreach ($headReqs as $hr) {
        $hKey = $hr['projectId'] . '|' . ($hr['headId'] ?: $hr['headName']);
        if (!isset($headTotals[$hKey])) $headTotals[$hKey] = ['booked' => 0, 'actual' => 0];

        if ($hr['status'] !== 'rejected') {
            $headTotals[$hKey]['booked'] += floatval($hr['requestedAmount']);
        }
        if ($hr['status'] === 'approved') {
            $headTotals[$hKey]['actual'] += floatval($hr['actual_exp']);
        }
    }

    // ── 4. Fetch Head Allocations ──────────────────────────────
    $sqlAlloc = "SELECT * FROM head_allocations WHERE projectId IN ($placeholders)";
    $stmtAlloc = $db->prepare($sqlAlloc);
    $stmtAlloc->execute($projectIds);
    $allocsByProject = [];
    while ($ha = $stmtAlloc->fetch()) { $allocsByProject[$ha['projectId']][] = $ha; }

    // ── 5. Main Process Loop ──────────────────────────────────────────────
    $projects = [];
    $now = date('Y-m-d H:i:s');

    foreach ($projectsRaw as $project) {
        $projectId = $project['id'];
        $released  = floatval($project['totalReleasedAmount'] ?? 0);
        $booked    = floatval($projectTotals[$projectId]['booked'] ?? 0);
        $actual    = floatval($projectTotals[$projectId]['actual'] ?? 0);

        $availableBalance = max(0.0, $released - $booked);

        // Sync denormalised project fields if they drifted
        if (abs($booked - floatval($project['amountBookedByPI'] ?? -1)) > 0.001
            || abs($actual - floatval($project['actual_exp'] ?? -1)) > 0.001) {
            $db->prepare("UPDATE projects SET amountBookedByPI = ?, actual_exp = ?, updatedAt = ? WHERE id = ?")
               ->execute([$booked, $actual, $now, $projectId]);
        }

        // ── Heads ──────────────────────────────────────────────────────────
        $heads = [];
        $projectHeads = $allocsByProject[$projectId] ?? [];

        foreach ($projectHeads as $alloc) {
            $headReleased = floatval($alloc['releasedAmount'] ?? 0);
            if ($headReleased <= 0) continue;

            $headId   = (string)($alloc['headId']   ?? '');
            $headName = (string)($alloc['headName'] ?? '');

            $hKey1 = $projectId . '|' . $headId;
            $hKey2 = $projectId . '|' . $headName;
            $ht = $headTotals[$hKey1] ?? $headTotals[$hKey2] ?? ['booked' => 0, 'actual' => 0];

            $headBooked = floatval($ht['booked']);
            $headActual = floatval($ht['actual']);
            $headAvail = max(0.0, $headReleased - $headBooked);

            // Sync head allocation document if drifted
            if (abs($headBooked - floatval($alloc['bookedAmount'] ?? -1)) > 0.001
                || abs($headActual - floatval($alloc['actual_exp'] ?? -1)) > 0.001) {
                $db->prepare("UPDATE head_allocations SET bookedAmount = ?, actual_exp = ?, updatedAt = ? WHERE id = ?")
                   ->execute([$headBooked, $headActual, $now, $alloc['id']]);
            }

            $heads[] = [
                'id'                => $alloc['id'],
                'headId'            => $headId,
                'headName'          => $headName,
                'headType'          => $alloc['headType'] ?? '',
                'sanctionedAmount'  => floatval($alloc['sanctionedAmount'] ?? 0),
                'releasedAmount'    => $headReleased,
                'bookedAmount'      => $headBooked,
                'actual_exp' => $headActual,
                'availableBalance'  => $headAvail,
            ];
        }

        $projects[] = [
            'id'                    => $projectId,
            'gpNumber'              => $project['gpNumber']      ?? '',
            'projectName'           => $project['projectName']   ?? '',
            'modeOfProject'         => $project['modeOfProject'] ?? '',
            'piName'                => $project['piName']        ?? '',
            'piEmail'               => $project['piEmail']       ?? '',
            'department'            => $project['department']    ?? '',
            'projectStartDate'      => $project['projectStartDate'] ? date('Y-m-d', strtotime($project['projectStartDate'])) : null,
            'projectEndDate'        => $project['projectEndDate'] ? date('Y-m-d', strtotime($project['projectEndDate'])) : null,
            'totalSanctionedAmount' => floatval($project['totalSanctionedAmount'] ?? 0),
            'totalReleasedAmount'   => $released,
            'amountBookedByPI'      => $booked,
            'actual_exp'     => $actual,
            'availableBalance'      => $availableBalance,
            'status'                => $project['status'] ?? 'active',
            'heads'                 => $heads,
        ];
    }

    echo json_encode(['success' => true, 'data' => $projects, 'count' => count($projects)]);

} catch (Exception $e) {
    error_log("get-pi-projects error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>