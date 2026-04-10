<?php
require_once __DIR__ . '/../config/database.php';
try {
    $db = getMySQLConnection();
    $db->exec('ALTER TABLE approval_history ADD COLUMN approvalType VARCHAR(100);');
    $db->exec('ALTER TABLE approval_history ADD COLUMN specialApproval TINYINT(1);');
    echo "Columns added successfully!";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
