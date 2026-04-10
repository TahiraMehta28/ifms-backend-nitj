<?php
// api/get-approval-certificate.php
// Returns full data for the "Admn cum Financial Approval Under MEITY Project" certificate.
// ✅ Uses fileNumber stored on the request (updated when PI re-uploads after query).
// ✅ All fields sourced from budget_requests (filled at BookBudget time).
// ✅ Only returns data if status = 'approved'.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$requestId = trim($_GET['requestId'] ?? '');
if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'requestId required']); exit();
}

// ── Number to words ───────────────────────────────────────────────────────────
function numberToWords(float $amount): string {
    $amount = (int)round($amount);
    if ($amount === 0) return 'Zero Only';
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

    function convertChunk(int $n, array $ones, array $tens): string {
        $r = '';
        if ($n >= 100) { $r .= $ones[(int)($n/100)] . ' Hundred '; $n %= 100; }
        if ($n >= 20)  { $r .= $tens[(int)($n/10)]; $n %= 10; if ($n > 0) $r .= '-'; }
        if ($n > 0)    { $r .= $ones[$n]; }
        return trim($r);
    }

    $r = '';
    if ($amount >= 10000000) { $r .= convertChunk((int)($amount/10000000), $ones, $tens) . ' Crore '; $amount %= 10000000; }
    if ($amount >= 100000)   { $r .= convertChunk((int)($amount/100000),   $ones, $tens) . ' Lakh ';  $amount %= 100000; }
    if ($amount >= 1000)     { $r .= convertChunk((int)($amount/1000),     $ones, $tens) . ' Thousand '; $amount %= 1000; }
    if ($amount > 0)         { $r .= convertChunk((int)$amount,            $ones, $tens); }
    return trim($r) . ' Only';
}

function fmtDate($val): string {
    if (empty($val)) return '';
    try { return (new DateTime((string)$val))->format('d.m.Y'); }
    catch (Exception $e) { return (string)$val; }
}

try {
    $db = getMySQLConnection();
    
    // 1. Fetch request and joined project
    $stmt = $db->prepare("
        SELECT r.*, p.totalSanctionedAmount as projSanc, p.totalReleasedAmount as projRel, p.projectEndDate as projEnd
        FROM budget_requests r
        JOIN projects p ON r.projectId = p.id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();

    if (!$req) throw new Exception('Request not found');
    if ($req['status'] !== 'approved') throw new Exception('Certificate only available for approved requests');

    // 2. Fetch history to find approval date (Director or DR)
    $stmtH = $db->prepare("
        SELECT timestamp, stage 
        FROM approval_history 
        WHERE requestId = ? AND action = 'approved' AND stage IN ('director', 'dr')
        ORDER BY timestamp DESC LIMIT 1
    ");
    $stmtH->execute([$requestId]);
    $h = $stmtH->fetch();

    $approvedDate = $h ? fmtDate($h['timestamp']) : fmtDate($req['updatedAt']);
    
    // 3. Fallbacks
    $amount = floatval($req['requestedAmount']);
    $mode = $req['mode'] ?: 'Through Direct Purchase on GeM portal under GFR 2017 rule (149-I).';
    
    $expenditure = "Yes, as per IFMS Budget-ID/{$req['gpNumber']} dated {$approvedDate}";
    if ($req['headName']) {
        $expenditure .= " under Head \"{$req['headName']}" . ($req['headType'] ? " ({$req['headType']})" : '') . '"';
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'requestId'      => $req['id'],
            'requestNumber'  => $req['requestNumber'],
            'fileNumber'     => $req['fileNumber'],
            'approvedDate'   => $approvedDate,
            'projectTitle'   => $req['projectTitle'],
            'projectType'    => $req['projectType'],
            'gpNumber'       => $req['gpNumber'],
            'piName'         => $req['piName'],
            'piEmail'        => $req['piEmail'],
            'department'     => $req['department'],
            'projectEndDate' => fmtDate($req['projEnd']),
            'totalSanctionedAmount' => floatval($req['projSanc']),
            'totalReleasedAmount'   => floatval($req['projRel']),
            'material'       => $req['material'],
            'headName'       => $req['headName'],
            'headType'       => $req['headType'],
            'amount'         => $amount,
            'amountWords'    => numberToWords($amount),
            'expenditure'    => $expenditure,
            'mode'           => $mode,
            'purpose'        => $req['purpose'],
            'description'    => $req['description'],
            'invoiceNumber'  => $req['invoiceNumber'],
            'daRemarks'      => $req['daRemarks'],
            'arRemarks'      => $req['arRemarks'],
            'drRemarks'      => $req['drRemarks'],
            'approvalType'   => $req['approvalType'],
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>