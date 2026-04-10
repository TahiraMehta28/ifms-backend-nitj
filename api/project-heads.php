<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $db = getMySQLConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $id = $_GET['id'] ?? null;
            if ($id) {
                $stmt = $db->prepare("SELECT * FROM master_project_heads WHERE id = ?");
                $stmt->execute([$id]);
                $head = $stmt->fetch();
                if (!$head) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Project head not found']);
                    exit;
                }
                $head['isActive'] = (bool)$head['isActive'];
                echo json_encode(['success' => true, 'data' => $head]);
            } else {
                $type = $_GET['type'] ?? '';
                $sql = "SELECT * FROM master_project_heads WHERE isActive = 1";
                $params = [];
                if ($type) {
                    $sql .= " AND type = ?";
                    $params[] = $type;
                }
                $sql .= " ORDER BY name ASC";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $heads = $stmt->fetchAll();
                foreach($heads as &$h) $h['isActive'] = (bool)$h['isActive'];
                echo json_encode(['success' => true, 'data' => $heads, 'count' => count($heads)]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['name']) || empty($data['type'])) throw new Exception('Name and type are required');
            
            $stmt = $db->prepare("SELECT id FROM master_project_heads WHERE name = ?");
            $stmt->execute([$data['name']]);
            if ($stmt->fetch()) throw new Exception('Project head with this name already exists');

            $stmt = $db->prepare("INSERT INTO master_project_heads (name, type, description, isActive, createdAt, updatedAt) VALUES (?, ?, ?, 1, NOW(), NOW())");
            $stmt->execute([
                htmlspecialchars(strip_tags($data['name'])),
                htmlspecialchars(strip_tags($data['type'])),
                htmlspecialchars(strip_tags($data['description'] ?? ''))
            ]);
            
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Project head created successfully', 'id' => $db->lastInsertId()]);
            break;

        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID is required');
            $data = json_decode(file_get_contents('php://input'), true);
            
            $updates = [];
            $params = [];
            if (isset($data['name'])) { $updates[] = "name = ?"; $params[] = htmlspecialchars(strip_tags($data['name'])); }
            if (isset($data['type'])) { $updates[] = "type = ?"; $params[] = htmlspecialchars(strip_tags($data['type'])); }
            if (isset($data['description'])) { $updates[] = "description = ?"; $params[] = htmlspecialchars(strip_tags($data['description'])); }
            if (isset($data['isActive'])) { $updates[] = "isActive = ?"; $params[] = (int)$data['isActive']; }
            
            if (empty($updates)) throw new Exception('No data provided for update');
            
            $updates[] = "updatedAt = NOW()";
            $sql = "UPDATE master_project_heads SET " . implode(", ", $updates) . " WHERE id = ?";
            $params[] = $id;
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Project head updated successfully']);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID is required');
            $stmt = $db->prepare("UPDATE master_project_heads SET isActive = 0, updatedAt = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Project head deleted successfully']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("project-heads API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>