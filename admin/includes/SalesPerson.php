<?php

class SalesPerson {
    private $db;

    public function __construct() {
        global $db;
        $this->db = $db;
    }

    public function getAll($filters = []) {
        try {
            $where = ['1=1'];
            $params = [];

            if (!empty($filters['branch'])) {
                $where[] = 'LOWER(TRIM(sp.branch)) = LOWER(TRIM(?))';
                $params[] = $filters['branch'];
            }

            if (!empty($filters['status'])) {
                $where[] = 'sp.status = ?';
                $params[] = $filters['status'];
            }

            if (!empty($filters['search'])) {
                $where[] = '(sp.name LIKE ? OR sp.epf_no LIKE ? OR sp.sales_code LIKE ? OR sp.phone_number LIKE ? OR sp.email LIKE ?)';
                $search = '%' . $filters['search'] . '%';
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            $sql = "
                SELECT sp.*,
                       u.full_name AS created_by_name,
                       rsp.name AS reported_sales_person_name,
                       rsp.sales_code AS reported_sales_person_code
                FROM sales_persons sp
                LEFT JOIN users u ON sp.created_by = u.id
                LEFT JOIN sales_persons rsp ON sp.reported_sales_person_id = rsp.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY sp.name ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("SalesPerson::getAll Error: " . $e->getMessage());
            return [];
        }
    }

    public function getById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM sales_persons WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("SalesPerson::getById Error: " . $e->getMessage());
            return null;
        }
    }

    public function create($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sales_persons (
                    name, branch, epf_no, sales_code,
                    phone_number, email, sales_rank, reported_sales_person_id,
                    status, created_by
                )
                VALUES (
                    :name, :branch, :epf_no, :sales_code,
                    :phone_number, :email, :sales_rank, :reported_sales_person_id,
                    :status, :created_by
                )
            ");

            $stmt->execute([
                ':name' => trim($data['name']),
                ':branch' => trim($data['branch']),
                ':epf_no' => trim($data['epf_no']),
                ':sales_code' => trim($data['sales_code']),
                ':phone_number' => $this->nullableTrim($data['phone_number'] ?? null),
                ':email' => $this->nullableTrim($data['email'] ?? null),
                ':sales_rank' => $this->nullableTrim($data['sales_rank'] ?? null),
                ':reported_sales_person_id' => $this->nullableId($data['reported_sales_person_id'] ?? null),
                ':status' => $data['status'] ?? 'active',
                ':created_by' => $data['created_by'] ?? null,
            ]);

            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'message' => 'Sales person added successfully',
            ];
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'EPF number or sales code already exists'];
            }
            return ['success' => false, 'message' => 'Failed to add sales person: ' . $e->getMessage()];
        }
    }

    public function update($id, $data) {
        try {
            $reportedId = $this->nullableId($data['reported_sales_person_id'] ?? null);
            if ($reportedId !== null && (int) $reportedId === (int) $id) {
                return ['success' => false, 'message' => 'A sales person cannot report to themselves'];
            }

            $stmt = $this->db->prepare("
                UPDATE sales_persons
                SET name = :name,
                    branch = :branch,
                    epf_no = :epf_no,
                    sales_code = :sales_code,
                    phone_number = :phone_number,
                    email = :email,
                    sales_rank = :sales_rank,
                    reported_sales_person_id = :reported_sales_person_id,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':id' => $id,
                ':name' => trim($data['name']),
                ':branch' => trim($data['branch']),
                ':epf_no' => trim($data['epf_no']),
                ':sales_code' => trim($data['sales_code']),
                ':phone_number' => $this->nullableTrim($data['phone_number'] ?? null),
                ':email' => $this->nullableTrim($data['email'] ?? null),
                ':sales_rank' => $this->nullableTrim($data['sales_rank'] ?? null),
                ':reported_sales_person_id' => $reportedId,
                ':status' => $data['status'] ?? 'active',
            ]);

            return ['success' => true, 'message' => 'Sales person updated successfully'];
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'EPF number or sales code already exists'];
            }
            return ['success' => false, 'message' => 'Failed to update sales person: ' . $e->getMessage()];
        }
    }

    public function delete($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM sales_persons WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Sales person deleted successfully'];
            }
            return ['success' => false, 'message' => 'Sales person not found'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Failed to delete sales person: ' . $e->getMessage()];
        }
    }

    public function getBranches() {
        try {
            $stmt = $this->db->query("
                SELECT DISTINCT branch FROM sales_persons
                WHERE branch IS NOT NULL AND branch != ''
                ORDER BY branch
            ");
            return array_column($stmt->fetchAll(), 'branch');
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getActiveByBranch($branch) {
        $branch = trim((string)$branch);
        if ($branch === '') {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT sp.*, u.full_name AS created_by_name
                FROM sales_persons sp
                LEFT JOIN users u ON sp.created_by = u.id
                WHERE sp.status = 'active'
                  AND LOWER(TRIM(sp.branch)) = LOWER(TRIM(?))
                ORDER BY sp.name ASC
            ");
            $stmt->execute([$branch]);
            $results = $stmt->fetchAll();

            if (!empty($results)) {
                return $results;
            }

            $stmt = $this->db->prepare("
                SELECT sp.*, u.full_name AS created_by_name
                FROM sales_persons sp
                LEFT JOIN users u ON sp.created_by = u.id
                WHERE sp.status = 'active'
                  AND (
                    LOWER(sp.branch) LIKE LOWER(?)
                    OR LOWER(?) LIKE CONCAT('%', LOWER(TRIM(sp.branch)), '%')
                  )
                ORDER BY sp.name ASC
            ");
            $likeBranch = '%' . $branch . '%';
            $stmt->execute([$likeBranch, $branch]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("SalesPerson::getActiveByBranch Error: " . $e->getMessage());
            return [];
        }
    }

    public function belongsToBranch(array $salesPerson, $branch) {
        $personBranch = strtolower(trim((string)($salesPerson['branch'] ?? '')));
        $selectedBranch = strtolower(trim((string)$branch));

        if ($personBranch === '' || $selectedBranch === '') {
            return false;
        }

        return $personBranch === $selectedBranch
            || strpos($personBranch, $selectedBranch) !== false
            || strpos($selectedBranch, $personBranch) !== false;
    }

    public function getInvestmentsBySalesCode($salesCode) {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    id,
                    full_name,
                    nic_no,
                    tel_no,
                    email,
                    investment_type,
                    investment_amount,
                    status,
                    years,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_date
                FROM investors
                WHERE sales_person_code = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([trim($salesCode)]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("SalesPerson::getInvestmentsBySalesCode Error: " . $e->getMessage());
            return [];
        }
    }

    private function nullableTrim($value) {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableId($value) {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }
}
