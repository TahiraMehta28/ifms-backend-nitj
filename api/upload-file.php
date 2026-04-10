<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            if (!isset($_FILES['file'])) throw new Exception('No file uploaded');
            
            $file = $_FILES['file'];
            $projectId = $_POST['projectId'] ?? null;
            $gpNumber = $_POST['gpNumber'] ?? null;
            $fileType = $_POST['fileType'] ?? 'sanction_letter';
            $uploadedBy = $_POST['uploadedBy'] ?? 'admin_user';
            
            if (!$projectId || !$gpNumber) throw new Exception('Project ID and GP Number are required');
            
            if ($file['type'] !== 'application/pdf') throw new Exception('Only PDF files are allowed');
            if ($file['size'] > 10 * 1024 * 1024) throw new Exception('File size exceeds 10MB');
            
            $uploadBaseDir = __DIR__ . '/../../uploads/projects/';
            $uploadDir = $uploadBaseDir . $gpNumber . '/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = $fileType . '_' . time() . '.' . $fileExt;
            $filePath = $uploadDir . $fileName;
            
            if (!move_uploaded_file($file['tmp_name'], $filePath)) throw new Exception('Failed to upload file');
            
            $dbPath = '/uploads/projects/' . $gpNumber . '/' . $fileName;
            $fileId = bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');

            // Store file record
            $stmt = $db->prepare("INSERT INTO project_files (id, projectId, gpNumber, fileName, storedFileName, fileType, filePath, fileSize, mimeType, uploadedBy, uploadedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$fileId, $projectId, $gpNumber, $file['name'], $fileName, $fileType, $dbPath, $file['size'], $file['type'], $uploadedBy, $now]);
            
            // Update project record
            $stmt = $db->prepare("UPDATE projects SET sanctionedLetterFile = ?, sanctionedLetterFileName = ?, updatedAt = ? WHERE id = ?");
            $stmt->execute([$dbPath, $file['name'], $now, $projectId]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'File uploaded successfully', 'fileId' => $fileId, 'fileName' => $file['name'], 'filePath' => $dbPath]);
            break;

        case 'GET':
            $projectId = $_GET['projectId'] ?? null;
            $gpNumber = $_GET['gpNumber'] ?? null;
            $fileId = $_GET['id'] ?? null;
            
            header('Content-Type: application/json');
            if ($fileId) {
                $stmt = $db->prepare("SELECT * FROM project_files WHERE id = ?");
                $stmt->execute([$fileId]);
                $file = $stmt->fetch();
                if (!$file) throw new Exception('File not found');
                echo json_encode(['success' => true, 'data' => $file]);
            } else {
                $query = "SELECT * FROM project_files WHERE 1=1";
                $params = [];
                if ($projectId) { $query .= " AND projectId = ?"; $params[] = $projectId; }
                if ($gpNumber) { $query .= " AND gpNumber = ?"; $params[] = $gpNumber; }
                $query .= " ORDER BY uploadedAt DESC";
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $files = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $files, 'count' => count($files)]);
            }
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('File ID is required');
            
            $stmt = $db->prepare("SELECT * FROM project_files WHERE id = ?");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            if (!$file) throw new Exception('File not found');
            
            $physicalPath = __DIR__ . '/../../' . $file['filePath'];
            if (file_exists($physicalPath)) unlink($physicalPath);
            
            $db->prepare("DELETE FROM project_files WHERE id = ?")->execute([$id]);
            // Update project to remove file reference
            $db->prepare("UPDATE projects SET sanctionedLetterFile = NULL, sanctionedLetterFileName = NULL, updatedAt = NOW() WHERE id = ?")->execute([$file['projectId']]);
            
            echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
            break;

        default:
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            break;
    }

} catch (Exception $e) {
    error_log("File Upload Error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>