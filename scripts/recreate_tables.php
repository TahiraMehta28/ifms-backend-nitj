<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    echo "🚀 Starting robust database recovery...\n";
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    // 1. Back up budget_requests (if backup doesn't exist yet)
    $stmt = $db->query("SHOW TABLES LIKE 'br_backup'");
    if ($stmt->rowCount() == 0) {
        echo "📦 Creating fresh backup of current budget_requests...\n";
        $db->exec("CREATE TABLE br_backup AS SELECT * FROM budget_requests");
    } else {
        echo "⚠️ Existing backup 'br_backup' found. Using it.\n";
    }

    // 2. Drop existing table
    echo "🗑️ Dropping corrupted/old budget_requests table...\n";
    $db->exec("DROP TABLE IF EXISTS budget_requests");

    // 3. Recreate from schema.sql
    echo "🏗️ Recreating budget_requests from corrected schema...\n";
    $schemaFile = __DIR__ . '/mysql/schema.sql';
    $schemaSql = file_get_contents($schemaFile);
    
    preg_match('/CREATE TABLE IF NOT EXISTS budget_requests \((.*?)\);/s', $schemaSql, $matches);
    if (!$matches) throw new Exception("Could not find CREATE TABLE budget_requests in $schemaFile");
    
    $createSql = $matches[0];
    $db->exec($createSql);

    // 4. Restore data (Intelligent Column Mapping)
    echo "💉 Restoring data with intelligent column mapping...\n";
    
    // Get columns from NEW table
    $stmtNew = $db->query("DESCRIBE budget_requests");
    $newCols = $stmtNew->fetchAll(PDO::FETCH_COLUMN);
    
    // Get columns from BACKUP table
    $stmtOld = $db->query("DESCRIBE br_backup");
    $oldCols = $stmtOld->fetchAll(PDO::FETCH_COLUMN);
    
    // Find intersection
    $commonCols = array_intersect($newCols, $oldCols);
    $colList = implode(', ', $commonCols);
    
    echo "Common columns found: " . count($commonCols) . "\n";
    
    if (count($commonCols) > 0) {
        $db->exec("INSERT IGNORE INTO budget_requests ($colList) SELECT $colList FROM br_backup");
        echo "✅ Data migrated successfully.\n";
    } else {
        echo "⚠️ No common columns found. Data migration skipped.\n";
    }

    // 5. Cleanup
    echo "🧹 Cleaning up...\n";
    $db->exec("DROP TABLE br_backup");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "🏆 Database recovery successful! PI Dashboard should now be visible.\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    if (isset($db)) {
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        // We leave br_backup alone for manual recovery if it exists
    }
}
