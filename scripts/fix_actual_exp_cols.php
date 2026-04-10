<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    
    echo "Updating budget_requests table columns...\n";
    $db->exec("ALTER TABLE budget_requests CHANGE COLUMN actualExpenditureEnteredBy actual_expEnteredBy VARCHAR(100)");
    $db->exec("ALTER TABLE budget_requests CHANGE COLUMN actualExpenditureEnteredAt actual_expEnteredAt DATETIME");
    
    echo "Database sync complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
