<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    echo "🚀 Adding missing columns to budget_requests...\n";

    // 1. Add piResponse column if it doesn't exist
    $stmt = $db->query("SHOW COLUMNS FROM budget_requests LIKE 'piResponse'");
    if ($stmt->rowCount() == 0) {
        echo "➕ Adding 'piResponse' column...\n";
        $db->exec("ALTER TABLE budget_requests ADD COLUMN piResponse TEXT AFTER hasOpenQuery");
        echo "✅ 'piResponse' column added.\n";
    } else {
        echo "ℹ️ 'piResponse' column already exists.\n";
    }

    echo "🏆 Database update successful!\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
