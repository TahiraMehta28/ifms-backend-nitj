<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    
    // 1. Check for duplicates in budget_requests
    $stmt = $db->query("SHOW COLUMNS FROM budget_requests");
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $counts = [];
    foreach ($fields as $f) {
        $name = $f['Field'];
        if (!isset($counts[$name])) $counts[$name] = 0;
        $counts[$name]++;
    }
    
    echo "Scanning columns...\n";
    foreach ($counts as $name => $count) {
        if ($count > 1) {
            echo "⚠️ Found duplicate column: $name ($count times)\n";
            
            // To fix this, we need to be careful. 
            // MySQL doesn't easily let you drop just ONE of the same-named columns.
            // Usually, if you have two, it's a very broken state.
            
            // Procedure: Rename table, create new, copy data.
            echo "🚀 Performing table normalization...\n";
            
            $db->exec("RENAME TABLE budget_requests TO budget_requests_old");
            
            // Get creation SQL from schema.sql (or just run the command)
            $schemaFile = __DIR__ . '/mysql/schema.sql';
            if (file_exists($schemaFile)) {
                $schemaSql = file_get_contents($schemaFile);
                // Extract CREATE TABLE budget_requests block
                preg_match('/CREATE TABLE IF NOT EXISTS budget_requests \((.*?)\);/s', $schemaSql, $matches);
                if ($matches) {
                    $createSql = $matches[0];
                    echo "Creating fresh budget_requests table...\n";
                    $db->exec($createSql);
                    
                    // Copy data safely
                    echo "Copying data from old table...\n";
                    // We need to list columns explicitly to avoid the duplicate during SELECT *
                    $colsStmt = $db->query("SHOW COLUMNS FROM budget_requests");
                    $finalCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
                    $colList = implode(', ', $finalCols);
                    
                    $db->exec("INSERT INTO budget_requests ($colList) SELECT $colList FROM budget_requests_old");
                    
                    echo "✅ Normalization complete. Dropping old table...\n";
                    $db->exec("DROP TABLE budget_requests_old");
                } else {
                    echo "❌ Could not find CREATE TABLE statement in schema.sql\n";
                    $db->exec("RENAME TABLE budget_requests_old TO budget_requests");
                }
            } else {
                echo "❌ schema.sql not found at $schemaFile\n";
                $db->exec("RENAME TABLE budget_requests_old TO budget_requests");
            }
        }
    }
    
    echo "Done.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (isset($db)) {
        // Try to revert rename if it failed
        try { $db->exec("RENAME TABLE budget_requests_old TO budget_requests"); } catch (Exception $ex) {}
    }
}
