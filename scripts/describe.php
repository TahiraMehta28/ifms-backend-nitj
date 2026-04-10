<?php
require 'backend/config/database.php';
$db = getMySQLConnection();
$stmt = $db->query('DESCRIBE budget_requests');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
