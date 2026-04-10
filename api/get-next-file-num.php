<?php
// api/get-next-file-num.php
// Generates the NEXT file number for a GP Number.
//
// Logic:
//   - Find the highest FILE-NNN index already used for this gpNumber
//   - Return that index + 1
//
// This means:
//   - First BookBudget for GP/25-26/014  → FILE-001
//   - Query raised, PI re-uploads        → FILE-002
//   - Another query, PI re-uploads again → FILE-003
//   - Never duplicates, never skips.
//
// Format: {gpNumber}/FILE-{NNN}
// Example: GP/25-26/014/FILE-002

require_once __DIR__ . '/../config/database.php';

// Add missing CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$gpNumber = trim($_GET['gpNumber'] ?? '');
if (!$gpNumber) {
    echo json_encode(['success' => false, 'message' => 'gpNumber is required']);
    exit();
}

try {
    $db = getMySQLConnection();

    // Fetch all fileNumber values for this GP to find the highest index
    $stmt = $db->prepare("SELECT fileNumber FROM budget_requests WHERE gpNumber = ? AND fileNumber IS NOT NULL AND fileNumber != ''");
    $stmt->execute([$gpNumber]);
    $results = $stmt->fetchAll();

    $maxIndex = 0;
    foreach ($results as $row) {
        $fn = (string)$row['fileNumber'];
        if (preg_match('/FILE-(\d+)$/i', $fn, $m)) {
            $idx = (int)$m[1];
            if ($idx > $maxIndex) $maxIndex = $idx;
        }
    }

    $nextIndex  = $maxIndex + 1;
    $fileNumber = rtrim($gpNumber, '/') . '/FILE-' . str_pad($nextIndex, 3, '0', STR_PAD_LEFT);

    echo json_encode([
        'success'    => true,
        'fileNumber' => $fileNumber,
        'index'      => $nextIndex,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("get-next-file-num error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>