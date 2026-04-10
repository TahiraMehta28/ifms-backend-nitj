<?php
require_once __DIR__ . '/../config/database.php';
try {
    $db = getMySQLConnection();
    $stmt = $db->query('SHOW CREATE TABLE approval_history');
    var_dump($stmt->fetch());
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
