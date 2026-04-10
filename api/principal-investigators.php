<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        case 'DELETE':
            handleDelete($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error', 'message' => $e->getMessage()]);
}

function handleGet($db) {
    if (isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM principal_investigators WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $pi = $stmt->fetch();
        if ($pi) {
            echo json_encode(['success' => true, 'data' => $pi]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'PI not found']);
        }
    } else {
        $sql = "SELECT * FROM principal_investigators WHERE 1=1";
        $params = [];
        if (!empty($_GET['search'])) {
            $sql .= " AND (name LIKE ? OR email LIKE ? OR department LIKE ?)";
            $s = "%" . $_GET['search'] . "%";
            $params = [$s, $s, $s];
        }
        if (!empty($_GET['department'])) {
            $sql .= " AND department = ?";
            $params[] = $_GET['department'];
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $result, 'count' => count($result)]);
    }
}

function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Invalid JSON');
    
    $required = ['name', 'email', 'phone', 'department', 'designation'];
    foreach ($required as $field) {
        if (empty($input[$field])) throw new Exception("Field '{$field}' is required");
    }

    $stmt = $db->prepare("SELECT id FROM principal_investigators WHERE email = ?");
    $stmt->execute([strtolower(trim($input['email']))]);
    if ($stmt->fetch()) throw new Exception('PI with this email already exists');

    $id = bin2hex(random_bytes(12));
    $now = date('Y-m-d H:i:s');
    $sql = "INSERT INTO principal_investigators (id, name, email, phone, department, designation, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $id, trim($input['name']), strtolower(trim($input['email'])), 
        trim($input['phone']), trim($input['department']), $input['designation'], $now, $now
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'PI registered successfully',
        'data' => ['id' => $id, 'name' => $input['name']]
    ]);
}

function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $_GET['id'] ?? $input['id'] ?? null;
    if (!$id) throw new Exception('PI ID is required');

    $now = date('Y-m-d H:i:s');
    $sql = "UPDATE principal_investigators SET updated_at = ?";
    $params = [$now];
    
    $fields = ['name', 'email', 'phone', 'department', 'designation'];
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $sql .= ", $f = ?";
            $params[] = $input[$f];
        }
    }
    $sql .= " WHERE id = ?";
    $params[] = $id;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'message' => 'PI updated successfully']);
}

function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    if (!$id) throw new Exception('PI ID is required');
    $stmt = $db->prepare("DELETE FROM principal_investigators WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'PI deleted successfully']);
}
?>
