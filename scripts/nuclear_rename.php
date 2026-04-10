<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    echo "🏗️ Starting Global Rename (Nuclear Fix)...\n";
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = [
        'budget_requests'  => 'actual_exp',
        'projects'         => 'actual_exp',
        'head_allocations' => 'actual_exp'
    ];

    foreach ($tables as $table => $newCol) {
        echo "Processing $table... ";
        
        // Check current columns
        $s = $db->query("DESCRIBE $table");
        $cols = $s->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('actual_exp', $cols)) {
            echo "Column 'actual_exp' already exists. Skipping.\n";
            continue;
        }

        // Try to find any variants of actual_exp (including case insensitive)
        $oldCol = null;
        foreach ($cols as $c) {
            if (stripos($c, 'actual_exp') !== false) {
                $oldCol = $c;
                break;
            }
        }

        if ($oldCol) {
            echo "Found '$oldCol'. Renaming to 'actual_exp'... ";
            $db->exec("ALTER TABLE $table CHANGE `$oldCol` `actual_exp` DECIMAL(15,2) DEFAULT 0");
            echo "✅ Done.\n";
        } else {
            echo "Column missing! Adding 'actual_exp'... ";
            $db->exec("ALTER TABLE $table ADD COLUMN `actual_exp` DECIMAL(15,2) DEFAULT 0");
            echo "✅ Added.\n";
        }
    }

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "🏆 Global rename in database COMPLETE.\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
