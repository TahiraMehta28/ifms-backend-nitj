<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    $tables = [
        'principal_investigators', 
        'master_project_heads', 
        'projects', 
        'fund_releases', 
        'headwise_releases', 
        'head_allocations',
        'budget_requests'
    ];
    
    foreach ($tables as $table) {
        echo "\n--- $table ---\n";
        try {
            $stmt = $db->query("DESCRIBE $table");
            while ($row = $stmt->fetch()) {
                echo "{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Key']}\n";
            }
        } catch (Exception $e) {
            echo "Error describing $table: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
}
