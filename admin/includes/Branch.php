<?php
// admin/includes/Branch.php

class Branch {
    private $db;
    
    public function __construct() {
        global $db;
        $this->db = $db;
    }
    
    public function getAllBranches() {
        try {
            $stmt = $this->db->prepare("
                SELECT id, name, code, address, phone, email, manager_id, 
                       status, created_at, updated_at
                FROM branches 
                WHERE status = 'active'
                ORDER BY name ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Branch::getAllBranches Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getBranchById($id) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM branches WHERE id = ?
            ");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Branch::getBranchById Error: " . $e->getMessage());
            return null;
        }
    }
    
    public function createBranch($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO branches (name, code, address, phone, email, manager_id, status)
                VALUES (:name, :code, :address, :phone, :email, :manager_id, :status)
            ");
            
            $stmt->execute([
                ':name' => $data['name'],
                ':code' => $data['code'],
                ':address' => $data['address'],
                ':phone' => $data['phone'],
                ':email' => $data['email'],
                ':manager_id' => $data['manager_id'] ?? null,
                ':status' => $data['status'] ?? 'active'
            ]);
            
            return [
                'success' => true,
                'branch_id' => $this->db->lastInsertId(),
                'message' => 'Branch created successfully'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to create branch: ' . $e->getMessage()
            ];
        }
    }
    
    public function updateBranch($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE branches 
                SET name = :name, code = :code, address = :address, 
                    phone = :phone, email = :email, manager_id = :manager_id,
                    status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $id,
                ':name' => $data['name'],
                ':code' => $data['code'],
                ':address' => $data['address'],
                ':phone' => $data['phone'],
                ':email' => $data['email'],
                ':manager_id' => $data['manager_id'] ?? null,
                ':status' => $data['status'] ?? 'active'
            ]);
            
            return [
                'success' => true,
                'message' => 'Branch updated successfully'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to update branch: ' . $e->getMessage()
            ];
        }
    }
    
    public function deleteBranch($id) {
        try {
            $stmt = $this->db->prepare("
                UPDATE branches 
                SET status = 'inactive', updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([':id' => $id]);
            
            return [
                'success' => true,
                'message' => 'Branch deactivated successfully'
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to deactivate branch: ' . $e->getMessage()
            ];
        }
    }
}
?>