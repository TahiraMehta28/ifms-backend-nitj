<?php
/**
 * release-funds-headwise.php — v7 (CORS FIX)
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit();
}

$projectId    = trim($input['projectId']    ?? '');
$gpNumber     = trim($input['gpNumber']     ?? '');
$letterNumber = trim($input['letterNumber'] ?? '');
$letterDate   = trim($input['letterDate']   ?? '');
$remarks      = trim($input['remarks']      ?? '');
$releasedBy   = trim($input['releasedBy']   ?? 'Admin');
$releases     = $input['releases'] ?? [];

if (!$projectId || empty($releases)) {
    echo json_encode(['success' => false, 'message' => 'projectId and releases are required']); exit();
}

try {
    $db = getMySQLConnection();
    $db->beginTransaction();

    /* ── 1. Fetch Project data ───────────────────────────────────────────── */
    $stmtProj = $db->prepare("SELECT projectName, gpNumber, totalSanctionedAmount, totalReleasedAmount FROM projects WHERE id = ? FOR UPDATE");
    $stmtProj->execute([$projectId]);
    $project = $stmtProj->fetch();

    if (!$project) throw new Exception('Project not found');

    $totalSanctioned = floatval($project['totalSanctionedAmount'] ?? 0);
    $totalReleased   = floatval($project['totalReleasedAmount']   ?? 0);

    /* ── 2. Build release number ─────────────────────────────────────────── */
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM fund_releases WHERE projectId = ?");
    $stmtCount->execute([$projectId]);
    $releaseCount = $stmtCount->fetchColumn();
    $releaseNumber = 'REL/' . ($project['gpNumber'] ?: $gpNumber) . '/' . str_pad($releaseCount + 1, 3, '0', STR_PAD_LEFT);

    $totalThisRelease = 0;
    $validatedReleases = [];
    $now = date('Y-m-d H:i:s');

    /* ── 3. Validate & Accumulate ────────────────────────────────────────── */
    foreach ($releases as $rel) {
        $headId   = trim($rel['headId']   ?? '');
        $headName = trim($rel['headName'] ?? '');
        $headType = trim($rel['headType'] ?? '');
        $amount   = floatval($rel['amount'] ?? 0);

        if ($amount <= 0) continue;

        // Fetch current head allocation
        $stmtAlloc = $db->prepare("SELECT sanctionedAmount, releasedAmount FROM head_allocations WHERE projectId = ? AND (headId = ? OR headName = ?) FOR UPDATE");
        $stmtAlloc->execute([$projectId, $headId, $headName]);
        $hAlloc = $stmtAlloc->fetch();

        $headSanctioned = 0;
        if ($hAlloc) {
            $headSanctioned = floatval($hAlloc['sanctionedAmount']);
            $headAvail = $headSanctioned - floatval($hAlloc['releasedAmount']);
            if ($amount > $headAvail + 0.01) {
                throw new Exception("Release amount for \"{$headName}\" (₹" . number_format($amount, 2) . ") exceeds available sanctioned balance (₹" . number_format($headAvail, 2) . ")");
            }
        }

        $totalThisRelease += $amount;
        $validatedReleases[] = [
            'headId' => $headId, 'headName' => $headName, 'headType' => $headType, 'amount' => $amount, 'sanctionedAmount' => $headSanctioned
        ];
    }

    if ($totalThisRelease > ($totalSanctioned - $totalReleased) + 0.01) {
        throw new Exception("Total release exceeds yet-to-release project balance");
    }

    /* ── 4. Insert Fund Release ──────────────────────────────────────────── */
    $relId = bin2hex(random_bytes(12));
    $stmtRel = $db->prepare("INSERT INTO fund_releases (id, projectId, gpNumber, releaseNumber, letterNumber, letterDate, totalReleasedAmount, remarks, releasedBy, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtRel->execute([$relId, $projectId, $project['gpNumber'], $releaseNumber, $letterNumber, $letterDate, $totalThisRelease, $remarks, $releasedBy, $now, $now]);

    foreach ($validatedReleases as $vr) {
        $db->prepare("INSERT INTO headwise_releases (fundReleaseId, headId, headName, headType, sanctionedAmount, releaseAmount) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$relId, $vr['headId'], $vr['headName'], $vr['headType'], $vr['sanctionedAmount'], $vr['amount']]);

        /* ── 5. Permanent Audit Log ────────────────────────────────────────── */
        $auditSql = "INSERT INTO release_audit_log (projectId, gpNumber, projectName, auditKey, releaseNumber, letterNumber, letterDate, headId, headName, headType, amountReleased, releasedBy, remarks, releaseDate, loggedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $db->prepare($auditSql)->execute([
            $projectId, $project['gpNumber'], $project['projectName'], $releaseNumber.'::'.$vr['headId'],
            $releaseNumber, $letterNumber, $letterDate, $vr['headId'], $vr['headName'], $vr['headType'],
            $vr['amount'], $releasedBy, $remarks, $now, $now
        ]);

        /* ── 6. Update head_allocations ────────────────────────────────────── */
        $db->prepare("UPDATE head_allocations SET releasedAmount = releasedAmount + ?, updatedAt = ?, status = IF(releasedAmount >= sanctionedAmount, 'fully_released', 'partially_released') WHERE projectId = ? AND (headId = ? OR headName = ?)")
           ->execute([$vr['amount'], $now, $projectId, $vr['headId'], $vr['headName']]);

        /* ── 7. Sync fund_allocation_items ─────────────────────────────────── */
        $db->prepare("UPDATE fund_allocation_items SET releasedAmount = releasedAmount + ?, status = IF(releasedAmount >= sanctionedAmount, 'fully_released', 'partially_released') WHERE headId = ? OR headName = ?")
           ->execute([$vr['amount'], $vr['headId'], $vr['headName']]);
    }

    /* ── 8. Update Project Total ─────────────────────────────────────────── */
    $db->prepare("UPDATE projects SET totalReleasedAmount = totalReleasedAmount + ?, updatedAt = ? WHERE id = ?")
       ->execute([$totalThisRelease, $now, $projectId]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Funds released successfully. Relational data synchronized.',
        'data' => [
            'releaseNumber' => $releaseNumber,
            'totalThisRelease' => $totalThisRelease,
            'headsReleased' => count($validatedReleases)
        ]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("release-funds-headwise error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>