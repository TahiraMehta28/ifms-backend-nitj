<?php
/**
 * Project Model - Updated with Financial Year GP Number Format
 * GP Format: GP/YY-YY/XXX (e.g., GP/25-26/001)
 * Financial Year: April to March
 * 
 * New Fields: amountBookedByPI, actual_exp
 */
class Project {
    private $db;

    public function __construct($db) {
        $this->db = $db; // PDO instance
    }

    private function generateGPNumber() {
        $currentDate = new DateTime();
        $currentMonth = (int) $currentDate->format('n');
        $currentYear = (int) $currentDate->format('Y');
        
        if ($currentMonth >= 4) {
            $fyStart = $currentYear;
            $fyEnd = $currentYear + 1;
        } else {
            $fyStart = $currentYear - 1;
            $fyEnd = $currentYear;
        }
        
        $financialYear = sprintf("%02d-%02d", $fyStart % 100, $fyEnd % 100);
        $prefix = "GP/{$financialYear}/%";
        
        $stmt = $this->db->prepare("SELECT gpNumber FROM projects WHERE gpNumber LIKE ? ORDER BY gpNumber DESC LIMIT 1");
        $stmt->execute([$prefix]);
        $lastProject = $stmt->fetch();

        if ($lastProject && isset($lastProject['gpNumber'])) {
            $parts = explode('/', $lastProject['gpNumber']);
            $lastNumber = intval(end($parts));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf("GP/%s/%03d", $financialYear, $newNumber);
    }

    private function calculateDuration($startDate, $endDate) {
        if (!$startDate || !$endDate) return 0;
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $interval = $start->diff($end);
        return round($interval->y + ($interval->m / 12) + ($interval->d / 365.25), 2);
    }

    public function create($data) {
        try {
            if (!$data || !is_array($data)) throw new Exception("Invalid data");

            $gpNumber = !empty($data['gpNumber']) ? $data['gpNumber'] : $this->generateGPNumber();
            $projectId = bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');

            $startDate = null;
            if (!empty($data['projectStartYear'])) {
                $startDate = $data['projectStartYear'] . '-' . str_pad($data['projectStartMonth'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($data['projectStartDate'], 2, '0', STR_PAD_LEFT);
            }
            $endDate = null;
            if (!empty($data['projectEndYear'])) {
                $endDate = $data['projectEndYear'] . '-' . str_pad($data['projectEndMonth'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($data['projectEndDate'], 2, '0', STR_PAD_LEFT);
            }

            $totalYears = ($startDate && $endDate) ? $this->calculateDuration($startDate, $endDate) : floatval($data['totalYears'] ?? 0);

            $this->db->beginTransaction();

            $sql = "INSERT INTO projects (id, gpNumber, isOldProject, modeOfProject, projectName, projectAgencyName, sanctionOrderNo, nameOfScheme, piName, piEmail, department, projectStartDate, projectEndDate, originalEndDate, totalYears, totalSanctionedAmount, totalAllocatedAmount, totalReleasedAmount, status, createdAt, updatedAt) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $projectId, $gpNumber, !empty($data['isOldProject']) ? 1 : 0, 
                $data['modeOfProject'] ?? '', $data['projectName'] ?? '', $data['projectAgencyName'] ?? '',
                $data['sanctionOrderNo'] ?? '', $data['nameOfScheme'] ?? '', $data['piName'] ?? '',
                $data['piEmail'] ?? '', $data['department'] ?? '', $startDate, $endDate, $endDate,
                $totalYears, floatval($data['totalSanctionedAmount'] ?? 0), 0,
                $data['status'] ?? 'pending', $now, $now
            ]);

            if (isset($data['allocations']) && is_array($data['allocations'])) {
                $totalAlloc = 0;
                foreach ($data['allocations'] as $alloc) {
                    $amt = floatval($alloc['sanctionedAmount'] ?? 0);
                    $totalAlloc += $amt;
                    $this->db->prepare("INSERT INTO project_heads_list (projectId, headId, headName, headType, sanctionedAmount) VALUES (?, ?, ?, ?, ?)")
                             ->execute([$projectId, $alloc['headId'], $alloc['headName'], $alloc['headType'], $amt]);
                    
                    $this->db->prepare("INSERT INTO head_allocations (id, projectId, gpNumber, headId, headName, headType, sanctionedAmount, releasedAmount, bookedAmount, status, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 'active', ?, ?)")
                             ->execute([bin2hex(random_bytes(12)), $projectId, $gpNumber, $alloc['headId'], $alloc['headName'], $alloc['headType'], $amt, $now, $now]);
                }
                $this->db->prepare("UPDATE projects SET totalAllocatedAmount = ? WHERE id = ?")->execute([$totalAlloc, $projectId]);
            }

            $this->db->commit();
            return ['insertedId' => $projectId, 'gpNumber' => $gpNumber];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getAll() {
        $stmt = $this->db->query("SELECT * FROM projects ORDER BY createdAt DESC");
        $projects = $stmt->fetchAll();
        foreach ($projects as &$p) {
            $p['allocations'] = $this->getAllocations($p['id']);
            $p['files'] = $this->getFiles($p['id']);
        }
        return $projects;
    }

    public function getOne($id) {
        $stmt = $this->db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if ($project) {
            $project['allocations'] = $this->getAllocations($id);
            $project['files'] = $this->getFiles($id);
        }
        return $project;
    }

    private function getAllocations($projectId) {
        $stmt = $this->db->prepare("SELECT * FROM head_allocations WHERE projectId = ?");
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    private function getFiles($projectId) {
        $stmt = $this->db->prepare("SELECT * FROM project_files WHERE projectId = ?");
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public function search($searchTerm = "", $status = "") {
        $sql = "SELECT * FROM projects WHERE 1=1";
        $params = [];
        if (!empty($searchTerm)) {
            $sql .= " AND (projectName LIKE ? OR gpNumber LIKE ? OR piName LIKE ? OR piEmail LIKE ?)";
            $params = array_merge($params, ["%$searchTerm%", "%$searchTerm%", "%$searchTerm%", "%$searchTerm%"]);
        }
        if (!empty($status)) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY createdAt DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $projects = $stmt->fetchAll();
        foreach ($projects as &$p) {
            $p['allocations'] = $this->getAllocations($p['id']);
        }
        return $projects;
    }

    public function update($id, $data) {
        $now = date('Y-m-d H:i:s');
        $sql = "UPDATE projects SET updatedAt = ?";
        $params = [$now];
        
        $fields = ['projectName', 'piName', 'piEmail', 'department', 'status', 'totalSanctionedAmount'];
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $sql .= ", $f = ?";
                $params[] = $data[$f];
            }
        }
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id) {
        $this->db->beginTransaction();
        $this->db->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM head_allocations WHERE projectId = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM project_heads_list WHERE projectId = ?")->execute([$id]);
        return $this->db->commit();
    }

    /**
     * Recalculates amountBookedByPI and actual_exp for a project and all its head allocations.
     * Rule: Booked = SUM(requestedAmount) for status != 'rejected'
     *       Actual = SUM(actual_exp) for status = 'approved'
     */
    public function syncFinancialTotals($projectId) {
        $now = date('Y-m-d H:i:s');
        
        // 1. Recalculate Project Level Totals
        // Booked = Sum(requestedAmount) for all non-rejected requests
        $stmtBooked = $this->db->prepare("SELECT SUM(requestedAmount) FROM budget_requests WHERE projectId = ? AND status != 'rejected'");
        $stmtBooked->execute([$projectId]);
        $bookedTotal = floatval($stmtBooked->fetchColumn() ?: 0);

        // Actual = Sum(actual_exp) for all non-rejected requests (includes pending)
        $stmtActual = $this->db->prepare("SELECT SUM(actual_exp) FROM budget_requests WHERE projectId = ? AND status != 'rejected'");
        $stmtActual->execute([$projectId]);
        $actualTotal = floatval($stmtActual->fetchColumn() ?: 0);

        // Update Project Table
        $this->db->prepare("UPDATE projects SET amountBookedByPI = ?, actual_exp = ?, updatedAt = ? WHERE id = ?")
                 ->execute([$bookedTotal, $actualTotal, $now, $projectId]);

        // 2. Recalculate all Head Level Totals for this project
        $stmtHeads = $this->db->prepare("SELECT id, headId, headName FROM head_allocations WHERE projectId = ?");
        $stmtHeads->execute([$projectId]);
        $heads = $stmtHeads->fetchAll();

        foreach ($heads as $h) {
            $hId   = $h['headId'];
            $hName = $h['headName'];
            $haId  = $h['id'];

            // Booked for this head (status != 'rejected')
            $stmtHBooked = $this->db->prepare("SELECT SUM(requestedAmount) FROM budget_requests WHERE projectId = ? AND (headId = ? OR headName = ?) AND status != 'rejected'");
            $stmtHBooked->execute([$projectId, $hId, $hName]);
            $hBooked = floatval($stmtHBooked->fetchColumn() ?: 0);

            // Actual for this head (status != 'rejected')
            $stmtHActual = $this->db->prepare("SELECT SUM(actual_exp) FROM budget_requests WHERE projectId = ? AND (headId = ? OR headName = ?) AND status != 'rejected'");
            $stmtHActual->execute([$projectId, $hId, $hName]);
            $hActual = floatval($stmtHActual->fetchColumn() ?: 0);

            // Update head_allocations
            $this->db->prepare("UPDATE head_allocations SET bookedAmount = ?, actual_exp = ?, updatedAt = ? WHERE id = ?")
                     ->execute([$hBooked, $hActual, $now, $haId]);
        }

        return ['amountBookedByPI' => $bookedTotal, 'actual_exp' => $actualTotal];
    }

    public static function formatDocument($doc) {
        if (!$doc) return null;
        if (isset($doc['allocations'])) {
            foreach ($doc['allocations'] as &$a) { if (isset($a['id'])) $a['id'] = (string)$a['id']; }
        }
        return $doc;
    }
}
?>