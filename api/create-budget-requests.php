<?php
// api/create-budget-requests.php  — v4
//
// BALANCE RULE (consistent with get-pi-projects.php):
//   booked    = SUM(requestedAmount) WHERE status != 'rejected'
//   available = releasedAmount - booked
//
//   • Pending/in-stage requests DEDUCT from available immediately.
//   • Rejected requests are excluded → their amount becomes available again.
//   • Approved requests stay deducted permanently.
//   • No "unusedBooking" add-back — that was a bug causing overcounting.
//
// UPLOAD: multipart/form-data (fast path) or legacy JSON (backward compat).

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

// ── Parse request ────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = str_contains($contentType, 'multipart/form-data');

if ($isMultipart) {
    $get = fn(string $k, string $default = '') => trim($_POST[$k] ?? $default);
    $projectId             = $get('projectId');
    $gpNumber              = $get('gpNumber');
    $fileNumber            = $get('fileNumber');
    $projectTitle          = $get('projectTitle');
    $projectType           = $get('projectType');
    $piName                = $get('piName');
    $piEmail               = $get('piEmail');
    $department            = $get('department');
    $headId                = $get('headId');
    $headName              = $get('headName');
    $headType              = $get('headType');
    $amount                = floatval($_POST['amount'] ?? 0);
    $purpose               = $get('purpose');
    $description           = $get('description');
    $material              = $get('material');
    $mode                  = $get('mode');
    $invoiceNumber         = $get('invoiceNumber');
    $projectCompletionDate = $get('projectEndDate');

    if (empty($_FILES['quotation']) || $_FILES['quotation']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['quotation']['error'] ?? 'no file';
        echo json_encode(['success' => false, 'message' => "Quotation PDF upload failed (error: {$uploadErr})"]); exit();
    }
    $uploadedFile = $_FILES['quotation'];
    if ($uploadedFile['type'] !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'Only PDF files are allowed']); exit();
    }
    $quotationBytes = file_get_contents($uploadedFile['tmp_name']);
    $quotation      = 'data:application/pdf;base64,' . base64_encode($quotationBytes);
    $quotationName  = $uploadedFile['name'];

} else {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit();
    }
    $get = fn(string $k, string $default = '') => trim($input[$k] ?? $default);
    $projectId             = $get('projectId');
    $gpNumber              = $get('gpNumber');
    $fileNumber            = $get('fileNumber');
    $projectTitle          = $get('projectTitle');
    $projectType           = $get('projectType');
    $piName                = $get('piName');
    $piEmail               = $get('piEmail');
    $department            = $get('department');
    $headId                = $get('headId');
    $headName              = $get('headName');
    $headType              = $get('headType');
    $amount                = floatval($input['amount'] ?? 0);
    $purpose               = $get('purpose');
    $description           = $get('description');
    $material              = $get('material');
    $mode                  = $get('mode');
    $invoiceNumber         = $get('invoiceNumber');
    $projectCompletionDate = $get('projectEndDate');
    $quotation             = $input['quotation']     ?? '';
    $quotationName         = $input['quotationName'] ?? '';
}

// ── Validation ────────────────
if (!$projectId || !$gpNumber || !$piEmail || !$headId || !$headName) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields (projectId, gpNumber, piEmail, headId, headName)']); exit();
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']); exit();
}
if (!$quotation) {
    echo json_encode(['success' => false, 'message' => 'Quotation PDF is required']); exit();
}

try {
    $db = getMySQLConnection();

    // ── 1. Load project ────────────────
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']); exit();
    }
    if (in_array($project['status'], ['rejected', 'completed'])) {
        echo json_encode(['success' => false, 'message' => 'Project is not active']); exit();
    }

    $projectReleased = floatval($project['totalReleasedAmount']);
    if ($projectReleased <= 0) {
        echo json_encode(['success' => false, 'message' => 'No funds have been released for this project yet']); exit();
    }

    // ── 2. Calculate Project level booked ────────────────
    $bookedStmt = $db->prepare("SELECT SUM(requestedAmount) as booked FROM budget_requests WHERE projectId = ? AND status != 'rejected'");
    $bookedStmt->execute([$projectId]);
    $projectBooked = floatval($bookedStmt->fetch()['booked'] ?? 0);
    $projectAvailable = max(0.0, $projectReleased - $projectBooked);

    if ($amount > $projectAvailable) {
        echo json_encode([
            'success' => false,
            'message' => sprintf('Amount ₹%.2f exceeds project available balance ₹%.2f', $amount, $projectAvailable)
        ]); exit();
    }

    // ── 3. Load Head Allocation ────────────────
    // Fallback search: by ID then by HeadId then by Name
    $headStmt = $db->prepare("SELECT * FROM head_allocations WHERE projectId = ? AND (id = ? OR headId = ? OR headName = ?)");
    $headStmt->execute([$projectId, $headId, $headId, $headName]);
    $headAlloc = $headStmt->fetch();

    if (!$headAlloc) {
        echo json_encode(['success' => false, 'message' => "Budget head '{$headName}' not found for this project"]); exit();
    }

    $headReleased = floatval($headAlloc['releasedAmount']);
    if ($headReleased <= 0) {
        echo json_encode(['success' => false, 'message' => "No funds released under head '{$headName}'"]); exit();
    }

    // ── 4. Calculate Head level booked ────────────────
    $hBookedStmt = $db->prepare("SELECT SUM(requestedAmount) as booked FROM budget_requests WHERE projectId = ? AND (headId = ? OR headName = ?) AND status != 'rejected'");
    $hBookedStmt->execute([$projectId, $headAlloc['headId'], $headAlloc['headName']]);
    $headBooked = floatval($hBookedStmt->fetch()['booked'] ?? 0);
    $headAvailable = max(0.0, $headReleased - $headBooked);

    if ($amount > $headAvailable) {
        echo json_encode([
            'success' => false,
            'message' => sprintf('Amount ₹%.2f exceeds head "%s" available balance ₹%.2f', $amount, $headAlloc['headName'], $headAvailable)
        ]); exit();
    }

    // ── 5. Insert Request ────────────────
    $db->beginTransaction();

    $newRequestId = bin2hex(random_bytes(12)); // 24-char hex
    $stmtCount = $db->query("SELECT COUNT(*) FROM budget_requests");
    $count = $stmtCount->fetchColumn();
    $requestNumber = 'BR/' . date('Y') . '/' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    $now = date('Y-m-d H:i:s');

    $sql = "INSERT INTO budget_requests (
                id, requestNumber, projectId, gpNumber, fileNumber, projectTitle, projectType, piName, piEmail, department,
                headId, headName, headType, requestedAmount, purpose, description, material, mode, invoiceNumber,
                projectCompletionDate, quotation, quotationFileName, snapshotProjectReleased, snapshotProjectBooked,
                snapshotProjectAvailable, snapshotHeadReleased, snapshotHeadBooked, snapshotHeadAvailable,
                status, currentStage, createdAt, updatedAt
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'da', ?, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $newRequestId, $requestNumber, $projectId, $gpNumber, $fileNumber, $projectTitle, $projectType, $piName, $piEmail, $department,
        $headAlloc['headId'], $headAlloc['headName'], $headType ?: $headAlloc['headType'], $amount, $purpose, $description, $material, $mode, $invoiceNumber,
        $projectCompletionDate, $quotation, $quotationName, $projectReleased, $projectBooked,
        $projectAvailable, $headReleased, $headBooked, $headAvailable,
        $now, $now
    ]);

    // Update denormalized booked amounts
    $db->prepare("UPDATE projects SET amountBookedByPI = amountBookedByPI + ?, updatedAt = ? WHERE id = ?")->execute([$amount, $now, $projectId]);
    $db->prepare("UPDATE head_allocations SET bookedAmount = bookedAmount + ?, updatedAt = ? WHERE id = ?")->execute([$amount, $now, $headAlloc['id']]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Budget request created successfully',
        'data'    => [
            'id' => $newRequestId,
            'requestNumber' => $requestNumber,
            'updatedBalances' => [
                'projectAvailable' => max(0.0, $projectAvailable - $amount),
                'headAvailable' => max(0.0, $headAvailable - $amount)
            ]
        ]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('create-budget-requests error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>