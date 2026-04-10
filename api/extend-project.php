<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $db = getMySQLConnection();
    $db->beginTransaction();

    $projectId       = $_POST['projectId']       ?? null;
    $gpNumber        = $_POST['gpNumber']        ?? null;
    $originalEndDate = $_POST['originalEndDate'] ?? null;
    $extendedEndDate = $_POST['extendedEndDate'] ?? null;
    $additionalYears = $_POST['additionalYears'] ?? '0';
    $remarks         = $_POST['remarks']         ?? '';
    $extendedBy      = $_POST['extendedBy']      ?? 'admin_user';

    if (!$projectId || !$gpNumber || !$extendedEndDate) {
        throw new Exception('projectId, gpNumber and extendedEndDate are required');
    }

    $pdfFilePath = null;
    $pdfFileSize = null;
    $pdfOrigName = null;

    if (isset($_FILES['extensionPdf']) && $_FILES['extensionPdf']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['extensionPdf'];
        if ($file['type'] !== 'application/pdf') throw new Exception('Only PDF files are allowed');
        
        $uploadDir = __DIR__ . '/../../uploads/extensions/' . $gpNumber . '/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $storedName   = 'extension_' . time() . '_' . uniqid() . '.pdf';
        $physicalPath = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $physicalPath)) throw new Exception('Failed to save PDF');

        $pdfFilePath = '/uploads/extensions/' . $gpNumber . '/' . $storedName;
        $pdfFileSize = $file['size'];
        $pdfOrigName = $file['name'];
    }

    $extensionId = bin2hex(random_bytes(12));
    $now = date('Y-m-d H:i:s');

    // 1. Insert extension record
    $stmt = $db->prepare("INSERT INTO project_extensions (id, projectId, gpNumber, originalEndDate, extendedEndDate, additionalYears, remarks, extendedBy, extendedAt, extensionPdfPath, extensionPdfOriginalName, extensionPdfSize) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$extensionId, $projectId, $gpNumber, $originalEndDate, $extendedEndDate, $additionalYears, $remarks, $extendedBy, $now, $pdfFilePath, $pdfOrigName, $pdfFileSize]);

    // 2. Update project
    $updateFields = [
        "projectEndDate = ?",
        "hasExtension = 1",
        "lastExtensionId = ?",
        "lastExtendedAt = ?",
        "updatedAt = ?"
    ];
    $params = [$extendedEndDate, $extensionId, $now, $now];

    if ($pdfFilePath) {
        $updateFields[] = "extensionLetterFile = ?";
        $updateFields[] = "extensionLetterFileName = ?";
        $params[] = $pdfFilePath;
        $params[] = $pdfOrigName;
    }

    $params[] = $projectId;
    $sql = "UPDATE projects SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $db->prepare($sql)->execute($params);

    $db->commit();

    echo json_encode([
        'success'     => true,
        'message'     => 'Project extended successfully',
        'extensionId' => $extensionId,
        'pdfPath'     => $pdfFilePath,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Extend Project Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>