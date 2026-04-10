<?php
require 'backend/config/database.php';
$db = getMySQLConnection();
echo "--- ALL BUDGET REQUESTS ---\n";
$stmt = $db->query("SELECT id, gpNumber, status, currentStage, requestedAmount, headType FROM budget_requests");
$reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($reqs as $r) {
    echo "ID: {$r['id']} | GP: {$r['gpNumber']} | Status: {$r['status']} | Stage: {$r['currentStage']} | Amount: {$r['requestedAmount']} | Head: {$r['headType']}\n";
}
?>
