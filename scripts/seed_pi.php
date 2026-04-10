<?php
require_once __DIR__ . '/../config/database.php';
try {
    $db = getMySQLConnection();
    $stmt = $db->prepare('INSERT IGNORE INTO principal_investigators (id, name, email, phone, department, designation, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute(['pi_std', 'Dr. Arpan Saini', 'pi@ifms.edu', '0000000000', 'Computer Science', 'Standard PI']);
    echo "✅ Standard PI (pi@ifms.edu) seeded successfully.\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
