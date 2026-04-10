<?php
require 'backend/config/database.php';
$db = getMySQLConnection();
print_r($db->query("SELECT id, requestedAmount, headType, status, currentStage FROM budget_requests WHERE currentStage='drc_office' OR currentStage='dr'")->fetchAll(PDO::FETCH_ASSOC));
?>
