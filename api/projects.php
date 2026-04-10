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
require_once __DIR__ . '/../models/Project.php';

$db = getMySQLConnection();
$project = new Project($db);

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $id = $_GET['id'] ?? null;
            
            if ($id) {
                $projectData = $project->getOne($id);
                if (!$projectData) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Project not found']);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => Project::formatDocument($projectData)]);
            } else {
                if (!empty($search) || !empty($status)) {
                    $projects = $project->search($search, $status);
                } else {
                    $projects = $project->getAll();
                }
                $formattedProjects = array_map([Project::class, 'formatDocument'], $projects);
                echo json_encode([
                    'success' => true,
                    'data' => array_values($formattedProjects),
                    'count' => count($formattedProjects)
                ]);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) throw new Exception('No data received');
            
            $requiredFields = ['projectName', 'piName', 'department'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) throw new Exception("Missing required field: $field");
            }
            
            $result = $project->create($data);
            http_response_code(201);
            echo json_encode([
                'success' => true, 
                'message' => 'Project created successfully',
                'gpNumber' => $result['gpNumber'],
                'id' => $result['insertedId']
            ]);
            break;

        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Project ID is required');
            $data = json_decode(file_get_contents('php://input'), true);
            $success = $project->update($id, $data);
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Project updated successfully']);
            } else {
                throw new Exception('Failed to update project');
            }
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('Project ID is required');
            if ($project->delete($id)) {
                echo json_encode(['success' => true, 'message' => 'Project deleted successfully']);
            } else {
                throw new Exception('Failed to delete project');
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>