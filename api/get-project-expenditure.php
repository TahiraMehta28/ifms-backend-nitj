<?php
/**
 * get-project-expenditure.php  v3
 *
 * Groups approved expenditure requests by fund release installment.
 *
 * RULES (same as get-project-bookings.php v4):
 *  - Each request assigned to most-recent release before its createdAt
 *  - effectiveAmount = actual_exp if DA filled, else requestedAmount
 *  - Booked per release capped at that release's totalReleased
 *  - Grand total booked NEVER exceeds totalReleasedAmount
 *
 * GET /api/get-project-expenditure.php?projectId=<id>
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$projectId = trim($_GET['projectId'] ?? '');
if (empty($projectId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'projectId is required']);
    exit();
}

try {
    $db = getMySQLConnection();

    // 1. Load project
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit();
    }

    $released = floatval($project['totalReleasedAmount']);
    $sanctioned = floatval($project['totalSanctionedAmount']);

    // 2. Fetch fund_releases ordered oldest first
    $stmt = $db->prepare("SELECT * FROM fund_releases WHERE projectId = ? ORDER BY createdAt ASC");
    $stmt->execute([$projectId]);
    $fundReleases = $stmt->fetchAll();

    // 3. Fetch all BOOKED requests (not rejected) oldest first
    $stmt = $db->prepare("SELECT * FROM budget_requests WHERE projectId = ? AND status != 'rejected' ORDER BY createdAt ASC");
    $stmt->execute([$projectId]);
    $allRequests = $stmt->fetchAll();

    // 4. Build release timeline
    $releaseTimeline = [];
    foreach ($fundReleases as $fr) {
        $ts = strtotime($fr['createdAt']);
        $releaseTimeline[] = [
            'releaseId' => $fr['id'],
            'releaseNumber' => $fr['releaseNumber'],
            'letterNumber' => $fr['letterNumber'],
            'letterDate' => $fr['letterDate'],
            'timestamp' => $ts,
            'totalReleased' => floatval($fr['totalReleasedAmount']),
        ];
    }

    // 5. Init groups
    $groups = [];
    foreach ($releaseTimeline as $rt) {
        $groups[$rt['releaseId']] = array_merge($rt, [
            'heads' => [], 'totalEffective' => 0, 'totalActual' => 0, 'totalRawBooked' => 0,
            'approvedCount' => 0, 'filledCount' => 0,
        ]);
    }
    $groups['__none__'] = [
        'releaseId' => '__none__', 'releaseNumber' => 'Pre-Release', 'letterNumber' => '', 'letterDate' => '',
        'timestamp' => 0, 'totalReleased' => 0, 'heads' => [], 'totalEffective' => 0, 'totalActual' => 0, 'totalRawBooked' => 0,
        'approvedCount' => 0, 'filledCount' => 0,
    ];

    // 6. Assign requests
    foreach ($allRequests as $req) {
        $reqTs = strtotime($req['createdAt']);

        $assignedId = '__none__';
        $bestTs = -1;
        foreach ($releaseTimeline as $rt) {
            if ($rt['timestamp'] <= $reqTs && $rt['timestamp'] > $bestTs) {
                $bestTs = $rt['timestamp'];
                $assignedId = $rt['releaseId'];
            }
        }

        $hid = $req['headId'];
        $bookedAmt = floatval($req['requestedAmount']);
        $actualAmt = floatval($req['actual_exp']);
        $isFilled = $actualAmt > 0;
        $effectiveAmt = $isFilled ? $actualAmt : $bookedAmt;

        if (!isset($groups[$assignedId]['heads'][$hid])) {
            $groups[$assignedId]['heads'][$hid] = [
                'headId' => $hid, 'headName' => $req['headName'], 'headType' => $req['headType'],
                'bookedAmount' => 0, 'rawBookedAmount' => 0, 'actual_exp' => 0,
                'approvedCount' => 0, 'filledCount' => 0, 'requests' => [],
            ];
        }

        $groups[$assignedId]['heads'][$hid]['bookedAmount']      += $effectiveAmt;
        $groups[$assignedId]['heads'][$hid]['rawBookedAmount']   += $bookedAmt;
        $groups[$assignedId]['heads'][$hid]['actual_exp'] += $actualAmt;
        $groups[$assignedId]['heads'][$hid]['approvedCount']++;
        if ($isFilled) $groups[$assignedId]['heads'][$hid]['filledCount']++;

        $groups[$assignedId]['heads'][$hid]['requests'][] = [
            'requestId' => $req['id'],
            'requestNumber' => $req['requestNumber'],
            'purpose' => $req['purpose'],
            'invoiceNumber' => $req['invoiceNumber'],
            'bookedAmount' => $bookedAmt,
            'actual_exp' => $actualAmt,
            'effectiveAmount' => $effectiveAmt,
            'expenditureFilled' => $isFilled,
            'isSettled' => $isFilled,
            'createdAt' => date('Y-m-d', $reqTs),
        ];

        $groups[$assignedId]['totalEffective'] += $effectiveAmt;
        $groups[$assignedId]['totalActual']    += $actualAmt;
        $groups[$assignedId]['totalRawBooked'] += $bookedAmt;
        $groups[$assignedId]['approvedCount']++;
        if ($isFilled) $groups[$assignedId]['filledCount']++;
    }

    // 7. Format output
    $formattedReleases = [];
    $grandTotalEffective = 0;
    $grandTotalActual = 0;
    $grandApproved = 0;
    $grandFilled = 0;

    foreach ($groups as $grp) {
        if ($grp['releaseId'] === '__none__' && empty($grp['heads'])) continue;

        $headsFormatted = [];
        foreach ($grp['heads'] as $head) {
            $cum = 0;
            $reqs = [];
            foreach ($head['requests'] as $r) {
                $cum += $r['effectiveAmount'];
                $r['cumulActual'] = $cum;
                $reqs[] = $r;
            }
            $headsFormatted[] = array_merge($head, [
                'requests' => $reqs,
                'allFilled' => ($head['filledCount'] === $head['approvedCount'] && $head['approvedCount'] > 0),
            ]);
        }

        $cappedEffective = $grp['totalReleased'] > 0 ? min($grp['totalEffective'], $grp['totalReleased']) : $grp['totalEffective'];
        $grandTotalEffective += $cappedEffective;
        $grandTotalActual += $grp['totalActual'];
        $grandApproved += $grp['approvedCount'];
        $grandFilled += $grp['filledCount'];

        $formattedReleases[] = [
            'releaseId' => $grp['releaseId'],
            'releaseNumber' => $grp['releaseNumber'],
            'letterNumber' => $grp['letterNumber'],
            'letterDate' => $grp['letterDate'],
            'totalReleased' => $grp['totalReleased'],
            'totalBooked' => $cappedEffective,
            'totalRawBooked' => $grp['totalRawBooked'],
            'totalActual' => $grp['totalActual'],
            'remainingInRelease' => max(0, $grp['totalReleased'] - $cappedEffective),
            'approvedCount' => $grp['approvedCount'],
            'filledCount' => $grp['filledCount'],
            'allFilled' => ($grp['filledCount'] === $grp['approvedCount'] && $grp['approvedCount'] > 0),
            'heads' => $headsFormatted,
        ];
    }

    $grandAvailable = max(0, $released - $grandTotalEffective);

    // 8. Atomic Sync back to project table
    $db->prepare("UPDATE projects SET amountBookedByPI = ?, actual_exp = ?, updatedAt = NOW() WHERE id = ?")
       ->execute([$grandTotalEffective, $grandTotalActual, $projectId]);

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $projectId,
            'gpNumber' => $project['gpNumber'],
            'projectName' => $project['projectName'],
            'department' => $project['department'],
            'totalSanctionedAmount' => $sanctioned,
            'totalReleasedAmount' => $released,
            'amountBookedByPI' => $grandTotalEffective,
            'actual_exp' => $grandTotalActual,
            'availableBalance' => $grandAvailable,
            'approvedRequestCount' => $grandApproved,
            'filledExpenditureCount' => $grandFilled,
            'expenditureComplete' => ($grandApproved > 0 && $grandFilled === $grandApproved),
            'releases' => $formattedReleases,
        ],
    ]);

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    error_log("get-project-expenditure: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>