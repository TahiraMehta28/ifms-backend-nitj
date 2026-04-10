<?php
/**
 * get-project-report.php — v6
 *
 * FIXES:
 *  - Release history: reads from fund_releases collection (correct fields)
 *  - Booked = sum of effectiveAmounts (actual if DA filled, else booked)
 *  - Per-release breakdown shown in report
 *  - Actual expenditure per-request with timestamp when DA entered it
 *  - Available balance = Released - Effective Booked (never goes negative)
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
    $projectId = trim($_GET['projectId'] ?? '');
    if (empty($projectId)) throw new Exception('projectId is required');

    /* ── Utilities ───────────────────────────────────────────────────────── */
    $fmtDT = function ($val) {
        if (!$val) return null;
        try {
            $dt = new DateTime($val);
            return $dt->format('d M Y, h:i A');
        } catch (Exception $e) { return $val; }
    };
    $fmtPlainDate = function ($v) {
        if (!$v) return null;
        try { return (new DateTime($v))->format('d M Y'); }
        catch (Exception $e) { return $v; }
    };

    /* ── 1. Load project ─────────────────────────────────────────────────── */
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) throw new Exception('Project not found');

    $sanctioned = floatval($project['totalSanctionedAmount']);
    $released   = floatval($project['totalReleasedAmount']);

    $projectData = [
        'id'                    => $project['id'],
        'gpNumber'              => $project['gpNumber'],
        'projectName'           => $project['projectName'],
        'piName'                => $project['piName'],
        'piEmail'               => $project['piEmail'],
        'department'            => $project['department'],
        'modeOfProject'         => $project['modeOfProject'],
        'projectAgencyName'     => $project['projectAgencyName'],
        'sanctionOrderNo'       => $project['sanctionOrderNo'],
        'nameOfScheme'          => $project['nameOfScheme'],
        'bankDetails'           => $project['bankDetails'] ?: 'Canara Bank',
        'status'                => $project['status'],
        'projectStartDate'      => $fmtPlainDate($project['projectStartDate']),
        'projectEndDate'        => $fmtPlainDate($project['projectEndDate']),
        'originalEndDate'       => $fmtPlainDate($project['originalEndDate']),
        'hasExtension'          => (bool)$project['hasExtension'],
        'totalYears'            => floatval($project['totalYears']),
        'totalSanctionedAmount' => $sanctioned,
        'totalReleasedAmount'   => $released,
        'createdAt'             => $fmtDT($project['createdAt']),
        'updatedAt'             => $fmtDT($project['updatedAt']),
    ];

    /* ── 2. Fund Releases ────────────────────────────────────────────────── */
    $stmt = $db->prepare("SELECT * FROM fund_releases WHERE projectId = ? ORDER BY createdAt ASC");
    $stmt->execute([$projectId]);
    $releases = $stmt->fetchAll();

    $releaseTimeline = [];
    $releaseHistory  = [];

    foreach ($releases as $fr) {
        $frId = $fr['id'];
        
        // Fetch headwise breakdown for this release
        $stmtH = $db->prepare("SELECT * FROM headwise_releases WHERE fundReleaseId = ?");
        $stmtH->execute([$frId]);
        $hw = $stmtH->fetchAll();
        
        $headwiseReleases = [];
        foreach ($hw as $hr) {
            $headwiseReleases[] = [
                'headId'             => $hr['headId'],
                'headName'           => $hr['headName'],
                'headType'           => $hr['headType'],
                'sanctionedAmount'   => floatval($hr['sanctionedAmount']),
                'previouslyReleased' => 0, // Calculated in UI or from audit logs if needed
                'releaseAmount'      => floatval($hr['releaseAmount']),
                'newTotalReleased'   => 0
            ];
        }

        $ts = strtotime($fr['createdAt']);
        $entry = [
            'releaseId'        => $frId,
            'releaseNumber'    => $fr['releaseNumber'],
            'letterNumber'     => $fr['letterNumber'],
            'letterDate'       => $fmtPlainDate($fr['letterDate']),
            'releasedAt'       => $fmtDT($fr['createdAt']),
            'timestamp'        => $ts,
            'totalReleased'    => floatval($fr['totalReleasedAmount']),
            'releasedBy'       => $fr['piName'], // Or releasedBy logic if field added
            'remarks'          => '',
            'headwiseReleases' => $headwiseReleases,
        ];
        $releaseTimeline[] = $entry;
        $releaseHistory[]  = $entry;
    }

    /* ── 3. Budget Requests & Effective Logic ────────────────────────────── */
    $stmt = $db->prepare("SELECT * FROM budget_requests WHERE projectId = ? ORDER BY createdAt ASC");
    $stmt->execute([$projectId]);
    $brDocs = $stmt->fetchAll();

    $releaseGroups = [];
    foreach ($releaseTimeline as $rt) {
        $releaseGroups[$rt['releaseId']] = array_merge($rt, [
            'heads' => [], 'totalEffective' => 0, 'totalActual' => 0, 'totalRawBooked' => 0
        ]);
    }
    $releaseGroups['__none__'] = [
        'releaseId' => '__none__', 'releaseNumber' => 'Pre-Release', 'totalReleased' => 0,
        'heads' => [], 'totalEffective' => 0, 'totalActual' => 0, 'totalRawBooked' => 0, 'timestamp' => 0
    ];

    $allBR = [];
    foreach ($brDocs as $br) {
        $reqId = $br['id'];
        $reqTs = strtotime($br['createdAt']);

        // Fetch approval history
        $stmtH = $db->prepare("SELECT * FROM approval_history WHERE requestId = ? ORDER BY id ASC");
        $stmtH->execute([$reqId]);
        $history = [];
        foreach ($stmtH->fetchAll() as $h) {
            $history[] = [
                'stage' => $h['stage'], 'action' => $h['action'], 'by' => $h['by'],
                'remarks' => $h['remarks'], 'timestamp' => $h['timestamp']
            ];
        }

        $assignedId = '__none__';
        $bestTs = -1;
        foreach ($releaseTimeline as $rt) {
            if ($rt['timestamp'] <= $reqTs && $rt['timestamp'] > $bestTs) {
                $bestTs = $rt['timestamp'];
                $assignedId = $rt['releaseId'];
            }
        }

        $isRejected = ($br['status'] === 'rejected');
        $bookedAmt = floatval($br['requestedAmount']);
        $actualAmt = floatval($br['actual_exp']);
        $isFilled  = $actualAmt > 0;
        $effectiveAmt = ($isRejected) ? 0 : ($isFilled ? $actualAmt : $bookedAmt);

        $entry = [
            'id' => $reqId,
            'requestNumber' => $br['requestNumber'],
            'headId' => $br['headId'],
            'headName' => $br['headName'],
            'headType' => $br['headType'],
            'amount' => $bookedAmt,
            'effectiveAmount' => $effectiveAmt,
            'actual_exp' => $actualAmt,
            'actualEnteredAt' => $fmtDT($br['actual_expEnteredAt']),
            'expenditureFilled' => $isFilled,
            'purpose' => $br['purpose'],
            'status' => $br['status'],
            'currentStage' => $br['currentStage'],
            'approvalHistory' => $history,
            'assignedReleaseId' => $assignedId,
            'createdAt' => $fmtDT($br['createdAt']),
        ];
        $allBR[] = $entry;

        $hid = $br['headId'];
        if (!isset($releaseGroups[$assignedId]['heads'][$hid])) {
            $releaseGroups[$assignedId]['heads'][$hid] = [
                'headId' => $hid, 'headName' => $br['headName'], 'headType' => $br['headType'],
                'bookedAmount' => 0, 'rawBookedAmount' => 0, 'actual_exp' => 0, 'requests' => []
            ];
        }
        $releaseGroups[$assignedId]['heads'][$hid]['bookedAmount']      += $effectiveAmt;
        $releaseGroups[$assignedId]['heads'][$hid]['rawBookedAmount']   += $bookedAmt;
        $releaseGroups[$assignedId]['heads'][$hid]['actual_exp'] += $actualAmt;
        $releaseGroups[$assignedId]['heads'][$hid]['requests'][]         = $entry;

        $releaseGroups[$assignedId]['totalEffective'] += $effectiveAmt;
        $releaseGroups[$assignedId]['totalActual']    += $actualAmt;
        $releaseGroups[$assignedId]['totalRawBooked'] += $bookedAmt;
    }

    $formattedReleaseGroups = [];
    $grandEffective = 0; $grandActual = 0;
    foreach ($releaseGroups as $grp) {
        if ($grp['releaseId'] === '__none__' && empty($grp['heads'])) continue;
        $cap = $grp['totalReleased'] > 0 ? min($grp['totalEffective'], $grp['totalReleased']) : $grp['totalEffective'];
        $grandEffective += $cap; $grandActual += $grp['totalActual'];
        $formattedReleaseGroups[] = [
            'releaseId' => $grp['releaseId'], 'releaseNumber' => $grp['releaseNumber'],
            'letterNumber' => $grp['letterNumber'] ?? '', 'letterDate' => $grp['letterDate'] ?? '',
            'releasedAt' => $grp['releasedAt'] ?? null, 'totalReleased' => $grp['totalReleased'],
            'totalBooked' => $cap, 'totalActual' => $grp['totalActual'],
            'remainingInRelease' => max(0, $grp['totalReleased'] - $cap),
            'heads' => array_values($grp['heads'])
        ];
    }

    /* ── 4. Allocations ──────────────────────────────────────────────────── */
    $stmt = $db->prepare("SELECT * FROM head_allocations WHERE projectId = ?");
    $stmt->execute([$projectId]);
    $allocs = $stmt->fetchAll();
    $allocations = [];
    foreach ($allocs as $a) {
        $hid = $a['headId'];
        $headBRs = array_values(array_filter($allBR, fn($b) => $b['headId'] === $hid));
        $headRel = floatval($a['releasedAmount']);
        $headBooked = array_sum(array_column($headBRs, 'effectiveAmount'));

        $allocations[] = [
            'headId' => $hid, 'headName' => $a['headName'], 'headType' => $a['headType'],
            'sanctionedAmount' => floatval($a['sanctionedAmount']),
            'releasedAmount' => $headRel, 'bookedAmount' => $headBooked,
            'actual_exp' => array_sum(array_column($headBRs, 'actual_exp')),
            'availableBalance' => max(0, $headRel - $headBooked),
            'status' => $a['status'], 'bookings' => $headBRs
        ];
    }

    /* ── 5. Extensions ───────────────────────────────────────────────────── */
    $stmt = $db->prepare("SELECT * FROM project_extensions WHERE projectId = ? ORDER BY createdAt ASC");
    $stmt->execute([$projectId]);
    $extensions = [];
    foreach ($stmt->fetchAll() as $ext) {
        $extensions[] = [
            'originalEndDate' => $fmtPlainDate($ext['originalEndDate']),
            'extendedEndDate' => $fmtPlainDate($ext['extendedEndDate']),
            'additionalYears' => floatval($ext['additionalYears']),
            'remarks' => $ext['remarks'], 'extendedBy' => $ext['extendedBy'],
            'extendedAt' => $fmtDT($ext['createdAt'])
        ];
    }

    /* ── 6. Summary ──────────────────────────────────────────────────────── */
    $summary = [
        'totalSanctioned' => $sanctioned, 'totalReleased' => $released,
        'unreleasedAmount' => $sanctioned - $released, 'totalBooked' => $grandEffective,
        'totalActual' => $grandActual, 'piBalance' => max(0, $released - $grandEffective),
        'utilizationPct' => $released > 0 ? round(($grandEffective / $released) * 100, 1) : 0,
        'totalRequests' => count($allBR),
        'approvedRequests' => count(array_filter($allBR, fn($b) => $b['status'] === 'approved')),
        'pendingRequests'  => count(array_filter($allBR, fn($b) => in_array($b['status'], ['pending','da_approved','ar_approved']))),
        'rejectedRequests' => count(array_filter($allBR, fn($b) => $b['status'] === 'rejected')),
        'totalReleases' => count($releaseHistory), 'totalExtensions' => count($extensions)
    ];

    ob_end_clean();
    echo json_encode(['success' => true, 'data' => [
        'project' => $projectData, 'allocations' => $allocations,
        'releaseHistory' => $releaseHistory, 'releaseGroups' => $formattedReleaseGroups,
        'budgetRequests' => $allBR, 'extensions' => $extensions, 'summary' => $summary
    ]], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    error_log("Report Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>