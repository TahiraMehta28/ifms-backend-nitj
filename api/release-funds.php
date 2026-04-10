<?php
// api/release-funds.php — REFACTORED TO MYSQL
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Fetch all projects directly, not just those with budget requests
            $sql = "SELECT id, gpNumber, projectName, piName, piEmail, department, totalSanctionedAmount, totalReleasedAmount as projectReleased 
                    FROM projects 
                    WHERE status != 'closed'";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $projects = $stmt->fetchAll();

            $sanctionedProjects = [];
            foreach ($projects as $proj) {
                $gpNumber = $proj['gpNumber'];
                $projectId = $proj['id'];
                
                // Fetch releases for this project
                $relStmt = $db->prepare("SELECT * FROM fund_releases WHERE projectId = ? ORDER BY createdAt DESC");
                $relStmt->execute([$projectId]);
                $releases = $relStmt->fetchAll();

                $releasedTotal = 0;
                $releaseDetails = [];
                foreach ($releases as $rel) {
                    $amt = floatval($rel['totalReleasedAmount']);
                    $releasedTotal += $amt;
                    $releaseDetails[] = [
                        '_id' => $rel['id'],
                        'releaseAmount' => $amt,
                        'letterDate' => $rel['letterDate'],
                        'letterNumber' => $rel['letterNumber'],
                        'releasedBy' => 'Finance Officer',
                        'releasedAt' => $rel['createdAt'],
                        'remarks' => ''
                    ];
                }

                $sanctionedAmount = floatval($proj['totalSanctionedAmount']);
                $sanctionedProjects[] = [
                    '_id' => $projectId,
                    'gpNumber' => $gpNumber,
                    'projectTitle' => $proj['projectName'],
                    'piName' => $proj['piName'],
                    'piEmail' => $proj['piEmail'],
                    'department' => $proj['department'],
                    'sanctionedAmount' => $sanctionedAmount,
                    'releasedAmount' => $releasedTotal,
                    'availableToRelease' => $sanctionedAmount - $releasedTotal,
                    'status' => 'sanctioned',
                    'releases' => $releaseDetails
                ];
            }

            echo json_encode(['success' => true, 'data' => $sanctionedProjects]);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $gpNumber = $input['gpNumber'] ?? null;
            $releaseAmount = floatval($input['releaseAmount'] ?? 0);
            $letterNumber = $input['letterNumber'] ?? '';
            $letterDate = $input['letterDate'] ?? date('Y-m-d');

            if (!$gpNumber || $releaseAmount <= 0 || !$letterNumber) {
                throw new Exception('GP Number, Release Amount, and Letter Number are required');
            }

            $db->beginTransaction();

            // 1. Fetch project
            $pStmt = $db->prepare("SELECT * FROM projects WHERE gpNumber = ? FOR UPDATE");
            $pStmt->execute([$gpNumber]);
            $project = $pStmt->fetch();
            if (!$project) throw new Exception('Project not found');

            // 2. Create release record
            $releaseId = bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');
            $insRel = $db->prepare("INSERT INTO fund_releases (id, projectId, gpNumber, piName, piEmail, letterNumber, letterDate, totalReleasedAmount, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insRel->execute([$releaseId, $project['id'], $gpNumber, $project['piName'], $project['piEmail'], $letterNumber, $letterDate, $releaseAmount, $now, $now]);

            // 3. Update project total released
            $updProj = $db->prepare("UPDATE projects SET totalReleasedAmount = totalReleasedAmount + ?, updatedAt = ? WHERE id = ?");
            $updProj->execute([$releaseAmount, $now, $project['id']]);

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Funds released successfully',
                'data' => ['id' => $releaseId, 'gpNumber' => $gpNumber, 'releaseAmount' => $releaseAmount]
            ]);
            break;

        case 'DELETE':
            $releaseId = $_GET['id'] ?? null;
            if (!$releaseId) throw new Exception('Release ID required');

            $db->beginTransaction();

            // Get release info
            $relStmt = $db->prepare("SELECT * FROM fund_releases WHERE id = ? FOR UPDATE");
            $relStmt->execute([$releaseId]);
            $release = $relStmt->fetch();
            if (!$release) throw new Exception('Release not found');

            // Update project (reverse)
            $db->prepare("UPDATE projects SET totalReleasedAmount = totalReleasedAmount - ?, updatedAt = ? WHERE id = ?")
               ->execute([$release['totalReleasedAmount'], date('Y-m-d H:i:s'), $release['projectId']]);

            // Delete release
            $db->prepare("DELETE FROM fund_releases WHERE id = ?")->execute([$releaseId]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Release record deleted successfully']);
            break;

        default:
            throw new Exception('Invalid method');
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
