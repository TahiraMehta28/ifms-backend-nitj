<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    
    echo "--- principal_investigators ---\n";
    $stmt = $db->query("SELECT * FROM principal_investigators");
    $piCount = 0;
    while ($row = $stmt->fetch()) {
        echo "{$row['name']} ({$row['department']})\n";
        $piCount++;
    }
    echo "Total PIs: $piCount\n";

    echo "\n--- master_project_heads ---\n";
    $stmt = $db->query("SELECT * FROM master_project_heads");
    $headCount = 0;
    while ($row = $stmt->fetch()) {
        echo "{$row['name']} ({$row['type']})\n";
        $headCount++;
    }
    echo "Total Heads: $headCount\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
