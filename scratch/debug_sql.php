<?php
// backend/scratch/debug_sql.php
// Debugging the SQL errors in get-requests-by-stage.php

require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    echo "--- PROJECTS TABLE SCHEMA ---\n";
    $q = $db->query("DESCRIBE projects");
    while($r = $q->fetch(PDO::FETCH_ASSOC)) {
        echo $r['Field'] . " (" . $r['Type'] . ")\n";
    }

    echo "\n--- BUDGET_REQUESTS TABLE SCHEMA ---\n";
    $q = $db->query("DESCRIBE budget_requests");
    while($r = $q->fetch(PDO::FETCH_ASSOC)) {
        echo $r['Field'] . " (" . $r['Type'] . ")\n";
    }

    echo "\n--- TESTING JOIN QUERY ---\n";
    $sql = "SELECT br.*, 
                   p.projectName as projectTitle_table, 
                   p.projectEndDate as projectEndDate_table, 
                   p.totalSanctionedAmount as projectSanctioned_table
            FROM budget_requests br
            LEFT JOIN projects p ON br.projectId = p.id
            LIMIT 1";
    
    $stmt = $db->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Query Success!\n";
    print_r($row);

} catch (PDOException $e) {
    echo "SQL ERROR: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
