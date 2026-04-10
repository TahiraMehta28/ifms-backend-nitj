<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    echo "🔧 Fixing Fund Release tables...\n";

    // 1. Add missing columns to fund_releases
    echo "Updating 'fund_releases' table...\n";
    
    // Check if remarks exists
    $check = $db->query("SHOW COLUMNS FROM fund_releases LIKE 'remarks'");
    if (!$check->fetch()) {
        $db->exec("ALTER TABLE fund_releases ADD COLUMN remarks TEXT AFTER totalReleasedAmount");
        echo "   - Added 'remarks' column\n";
    }

    // Check if releasedBy exists
    $check = $db->query("SHOW COLUMNS FROM fund_releases LIKE 'releasedBy'");
    if (!$check->fetch()) {
        $db->exec("ALTER TABLE fund_releases ADD COLUMN releasedBy VARCHAR(100) AFTER remarks");
        echo "   - Added 'releasedBy' column\n";
    }

    // 2. Create release_audit_log if it doesn't exist
    echo "Creating 'release_audit_log' table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS release_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        projectId VARCHAR(24),
        gpNumber VARCHAR(50),
        projectName VARCHAR(255),
        auditKey VARCHAR(255),
        releaseNumber VARCHAR(50),
        letterNumber VARCHAR(100),
        letterDate VARCHAR(100),
        headId VARCHAR(50),
        headName VARCHAR(255),
        headType VARCHAR(20),
        amountReleased DECIMAL(15, 2),
        releasedBy VARCHAR(100),
        remarks TEXT,
        releaseDate DATETIME,
        loggedAt DATETIME
    )";
    $db->exec($sql);

    echo "✅ Database fix complete!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
