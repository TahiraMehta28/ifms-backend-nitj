<?php
require_once __DIR__ . '/../config/database.php';
try {
    $db = getMySQLConnection();
    $stmt = $db->query("DESCRIBE budget_requests");
    echo "Columns in budget_requests:\n";
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
        echo "- $col\n";
    }
    
    $stmt = $db->query("DESCRIBE projects");
    echo "\nColumns in projects:\n";
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
        echo "- $col\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
