<?php
require_once __DIR__ . '/config/database.php';

try {
    $db = getMySQLConnection();
    $now = date('Y-m-d H:i:s');
    
    // 1. Create a Sample Project
    $projectId = 'proj_001_' . time();
    $gpNumber = 'GP/2026/' . rand(100, 999);
    
    $projSql = "INSERT INTO projects (id, gpNumber, projectName, piName, piEmail, department, status, createdAt, updatedAt) 
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)";
    $projStmt = $db->prepare($projSql);
    $projStmt->execute([
        $projectId, 
        $gpNumber, 
        'Smart Agriculture IoT System', 
        'Dr. Rajesh Kumar', 
        'rajesh.kumar@nitj.ac.in', 
        'Computer Science', 
        $now, 
        $now
    ]);
    
    echo "✅ Sample Project Created: $gpNumber\n";
    
    // 2. Create a Head Allocation
    $headId = 'CONS_01';
    $headName = 'Consumables';
    $headSql = "INSERT INTO head_allocations (id, projectId, gpNumber, headId, headName, headType, sanctionedAmount, releasedAmount, bookedAmount, status, createdAt, updatedAt) 
                VALUES (?, ?, ?, ?, ?, 'consumable', 500000, 200000, 0, 'active', ?, ?)";
    $headStmt = $db->prepare($headSql);
    $headStmt->execute([
        'ha_' . time(),
        $projectId,
        $gpNumber,
        $headId,
        $headName,
        $now,
        $now
    ]);
    
    echo "✅ Head Allocation (Consumables) Created.\n";

    // 3. Create a Budget Request
    $requestId = 'req_001_' . time();
    $reqNumber = 'BR/2026/001';
    $reqSql = "INSERT INTO budget_requests (id, requestNumber, projectId, gpNumber, projectTitle, piName, headId, headName, headType, requestedAmount, purpose, status, currentStage, createdAt, updatedAt) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'consumable', 15000, 'Purchase of Arduino sensors for field testing', 'pending', 'da', ?, ?)";
    $reqStmt = $db->prepare($reqSql);
    $reqStmt->execute([
        $requestId,
        $reqNumber,
        $projectId,
        $gpNumber,
        'Smart Agriculture IoT System',
        'Dr. Rajesh Kumar',
        $headId,
        $headName,
        $now,
        $now
    ]);

    echo "✅ Sample Budget Request Created: $reqNumber\n";
    echo "\n🚀 You can now refresh your DA Dashboard to see this request!\n";

} catch (Exception $e) {
    echo "❌ Error populating data: " . $e->getMessage() . "\n";
}
