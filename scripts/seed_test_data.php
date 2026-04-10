<?php
/**
 * Seeding script for IFMS Backend
 * Inserts 10 projects and sample budget requests for pi@ifms.edu
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = getMySQLConnection();
    $piEmail = 'pi@ifms.edu';
    $piName = 'Professor PI';
    $department = 'Computer Science';

    echo "🌱 Starting database seeding for $piEmail...\n";

    $db->beginTransaction();

    for ($i = 1; $i <= 10; $i++) {
        $projectId = bin2hex(random_bytes(12));
        $gpNumber = "GP/2025/" . str_pad($i, 3, '0', STR_PAD_LEFT);
        $projectName = "Research Project " . str_pad($i, 2, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');

        // 1. Insert Project
        $sqlProj = "INSERT INTO projects (id, gpNumber, projectName, piName, piEmail, department, totalSanctionedAmount, totalReleasedAmount, amountBookedByPI, status, createdAt, updatedAt) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 'active', ?, ?)";
        $db->prepare($sqlProj)->execute([
            $projectId, $gpNumber, $projectName, $piName, $piEmail, $department, 1000000.00, $now, $now
        ]);

        // 2. Insert Head Allocations
        $heads = [
            ['id' => 'H1', 'name' => 'Consumable', 'type' => 'consumable', 'amt' => 200000],
            ['id' => 'H2', 'name' => 'Non-Consumable (Equipment)', 'type' => 'non-consumable', 'amt' => 500000],
            ['id' => 'H3', 'name' => 'Travel', 'type' => 'travel', 'amt' => 100000],
            ['id' => 'H4', 'name' => 'Contingency', 'type' => 'contingency', 'amt' => 100000],
            ['id' => 'H5', 'name' => 'Manpower', 'type' => 'manpower', 'amt' => 100000],
        ];

        foreach ($heads as $h) {
            $haId = bin2hex(random_bytes(12));
            $sqlHA = "INSERT INTO head_allocations (id, projectId, gpNumber, headId, headName, headType, sanctionedAmount, releasedAmount, bookedAmount, createdAt, updatedAt) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)";
            $db->prepare($sqlHA)->execute([
                $haId, $projectId, $gpNumber, $h['id'], $h['name'], $h['type'], $h['amt'], $h['amt'], $now, $now
            ]);
        }

        // 3. Create a Budget Request for each project at a DIFFERENT stage
        $stages = ['da', 'ar', 'dr', 'drc_office', 'drc_rc', 'drc', 'director'];
        $stage = $stages[($i - 1) % count($stages)];
        
        // Status mapping for the chosen stage
        $statusMap = [
            'da'         => 'pending',
            'ar'         => 'da_approved',
            'dr'         => 'ar_approved',
            'drc_office' => 'dr_approved',
            'drc_rc'     => 'drc_office_forwarded',
            'drc'        => 'drc_rc_forwarded',
            'director'   => 'drc_forwarded'
        ];
        
        $requestId = bin2hex(random_bytes(12));
        $reqNum = "REQ-" . date('Ymd') . "-" . str_pad($i, 4, '0', STR_PAD_LEFT);
        $reqStatus = $statusMap[$stage];
        
        $sqlReq = "INSERT INTO budget_requests (id, requestNumber, projectId, gpNumber, projectTitle, piName, piEmail, department, headId, headName, headType, requestedAmount, status, currentStage, createdAt, updatedAt) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'H1', 'Consumable', 'consumable', ?, ?, ?, ?, ?)";
        
        $db->prepare($sqlReq)->execute([
            $requestId, $reqNum, $projectId, $gpNumber, $projectName, $piName, $piEmail, $department, 15000.00, $reqStatus, $stage, $now, $now
        ]);

        echo "✅ Created Project: $gpNumber at stage: $stage\n";
    }

    $db->commit();
    echo "🎉 Seeding complete!\n";

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "❌ Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}
