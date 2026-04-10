<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    $now = date('Y-m-d H:i:s');

    echo "🚀 Starting Test Environment Setup...\n";

    // 1. Create principal_investigators table
    echo "Creating 'principal_investigators' table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS principal_investigators (
        id VARCHAR(24) PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        phone VARCHAR(20),
        department VARCHAR(255),
        designation VARCHAR(255),
        created_at DATETIME,
        updated_at DATETIME
    )";
    $db->exec($sql);

    // 2. Ensure master_project_heads table exists (should be there based on schema.sql but let's be sure)
    echo "Ensuring 'master_project_heads' table exists...\n";
    $sql = "CREATE TABLE IF NOT EXISTS master_project_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) UNIQUE NOT NULL,
        type VARCHAR(100) NOT NULL,
        description TEXT,
        isActive TINYINT(1) DEFAULT 1,
        createdAt DATETIME,
        updatedAt DATETIME
    )";
    $db->exec($sql);

    // 3. Seed Principal Investigators
    echo "Seeding Principal Investigators...\n";
    $pis = [
        ['pi_001', 'Dr. Arpan Saini', 'arpan.saini@nitj.ac.in', '9876543210', 'Computer Science', 'Assistant Professor'],
        ['pi_002', 'Dr. Geeta Sikka', 'geeta.sikka@nitj.ac.in', '9876543211', 'Computer Science', 'Professor'],
        ['pi_003', 'Dr. RK Sharma', 'rksharma@nitj.ac.in', '9876543212', 'Electrical Engineering', 'Associate Professor'],
        ['pi_004', 'Dr. SK Sinha', 'sksinha@nitj.ac.in', '9876543213', 'Mechanical Engineering', 'Professor'],
        ['pi_005', 'Dr. Anita Devi', 'anita.devi@nitj.ac.in', '9876543214', 'Civil Engineering', 'Assistant Professor'],
    ];

    $piStmt = $db->prepare("INSERT IGNORE INTO principal_investigators (id, name, email, phone, department, designation, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($pis as $pi) {
        $piStmt->execute([...$pi, $now, $now]);
    }

    // 4. Seed Master Project Heads
    echo "Seeding Master Project Heads...\n";
    $heads = [
        ['Consumables', 'recurring', 'Project consumables and chemicals'],
        ['Equipment', 'non-recurring', 'High-end research equipment'],
        ['Travel', 'recurring', 'Domestic and international travel'],
        ['Contingency', 'recurring', 'Unforeseen expenses and office supplies'],
        ['Manpower', 'recurring', 'Salaries for JRF/SRF/RA'],
        ['Overhead', 'recurring', 'Institutional overhead charges'],
        ['Software', 'non-recurring', 'Software licenses and tools'],
    ];

    $headStmt = $db->prepare("INSERT IGNORE INTO master_project_heads (name, type, description, isActive, createdAt, updatedAt) VALUES (?, ?, ?, 1, ?, ?)");
    foreach ($heads as $head) {
        $headStmt->execute([...$head, $now, $now]);
    }

    echo "✅ Test Environment Setup Complete!\n";
    echo "   - PIs seeded: " . count($pis) . "\n";
    echo "   - Project Heads seeded: " . count($heads) . "\n";

} catch (Exception $e) {
    echo "❌ Error during setup: " . $e->getMessage() . "\n";
}
