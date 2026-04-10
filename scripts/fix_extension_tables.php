<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    echo "🔧 Fixing Project Extension system...\n";

    // 1. Create project_extensions table
    echo "Creating 'project_extensions' table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS project_extensions (
        id VARCHAR(24) PRIMARY KEY,
        projectId VARCHAR(24) NOT NULL,
        gpNumber VARCHAR(50) NOT NULL,
        originalEndDate DATE,
        extendedEndDate DATE,
        additionalYears DECIMAL(5, 2),
        remarks TEXT,
        extendedBy VARCHAR(100),
        extendedAt DATETIME,
        extensionPdfPath VARCHAR(255),
        extensionPdfOriginalName VARCHAR(255),
        extensionPdfSize INT
    )";
    $db->exec($sql);

    // 2. Add columns to projects table
    echo "Enriching 'projects' table for extensions...\n";
    $cols = [
        'hasExtension' => "TINYINT(1) DEFAULT 0",
        'lastExtensionId' => "VARCHAR(24)",
        'lastExtendedAt' => "DATETIME",
        'extensionLetterFile' => "VARCHAR(255)",
        'extensionLetterFileName' => "VARCHAR(255)"
    ];

    foreach ($cols as $col => $type) {
        $check = $db->query("SHOW COLUMNS FROM projects LIKE '$col'");
        if (!$check->fetch()) {
            $db->exec("ALTER TABLE projects ADD COLUMN $col $type");
            echo "   - Added '$col' column\n";
        }
    }

    echo "✅ Extension system fix complete!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
