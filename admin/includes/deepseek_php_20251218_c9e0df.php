<?php
// includes/User.php

class User {
    private $db;
    
    // User types
    const USER_ADMIN = 'admin';
    const USER_STAFF = 'staff';
    const USER_INVESTOR = 'investor';
    
    // Status
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SUSPENDED = 'suspended';
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Login user
     */
    public function login($username, $password) {
        try {
            // First, try to find user by username or email
            $stmt = $this->db->prepare("
                SELECT * FROM users 
                WHERE (username = :username OR email = :email) 
                LIMIT 1
            ");
            
            $stmt->execute([
                ':username' => $username,
                ':email' => $username
            ]);
            
            $user = $stmt->fetch();
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Invalid username or password'
                ];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid username or password'
                ];
            }
            
            // Check if user is active
            if ($user['status'] !== 'active') {
                return [
                    'success' => false,
                    'message' => 'Your account is not active. Please contact admin.'
                ];
            }
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['admin_logged_in'] = ($user['user_type'] === self::USER_ADMIN);
            
            // Update last login time
            $updateStmt = $this->db->prepare("
                UPDATE users 
                SET last_login = NOW() 
                WHERE id = :id
            ");
            
            $updateStmt->execute([':id' => $user['id']]);
            
            // Log the login
            $logStmt = $this->db->prepare("
                INSERT INTO user_logs (user_id, action, ip_address, user_agent)
                VALUES (:user_id, 'login', :ip_address, :user_agent)
            ");
            
            $logStmt->execute([
                ':user_id' => $user['id'],
                ':ip_address' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            return [
                'success' => true,
                'message' => 'Login successful',
                'user_type' => $user['user_type']
            ];
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error occurred. Please try again.'
            ];
        }
    }
    
    /**
     * Create new user
     */
    public function createUser($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO users (username, email, password_hash, full_name, user_type, status)
                VALUES (:username, :email, :password_hash, :full_name, :user_type, :status)
            ");
            
            $result = $stmt->execute([
                ':username' => $data['username'],
                ':email' => $data['email'],
                ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                ':full_name' => $data['full_name'],
                ':user_type' => $data['user_type'],
                ':status' => $data['status'] ?? 'active'
            ]);
            
            return $result ? $this->db->lastInsertId() : false;
            
        } catch (PDOException $e) {
            error_log("Create user error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user by ID
     */
    public function getUserById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Get user error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all users
     */
    public function getAllUsers($limit = 50) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM users 
                ORDER BY created_at DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get all users error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update user
     */
    public function updateUser($id, $data) {
        try {
            $sql = "UPDATE users SET ";
            $params = [];
            $updates = [];
            
            if (isset($data['full_name'])) {
                $updates[] = "full_name = :full_name";
                $params[':full_name'] = $data['full_name'];
            }
            
            if (isset($data['email'])) {
                $updates[] = "email = :email";
                $params[':email'] = $data['email'];
            }
            
            if (isset($data['user_type'])) {
                $updates[] = "user_type = :user_type";
                $params[':user_type'] = $data['user_type'];
            }
            
            if (isset($data['status'])) {
                $updates[] = "status = :status";
                $params[':status'] = $data['status'];
            }
            
            if (isset($data['password']) && !empty($data['password'])) {
                $updates[] = "password_hash = :password_hash";
                $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($updates)) {
                return false;
            }
            
            $sql .= implode(", ", $updates) . " WHERE id = :id";
            $params[':id'] = $id;
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
            
        } catch (PDOException $e) {
            error_log("Update user error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete user
     */
    public function deleteUser($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id AND user_type != 'admin'");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("Delete user error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if username exists
     */
    public function usernameExists($username, $excludeId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM users WHERE username = :username";
            $params = [':username' => $username];
            
            if ($excludeId) {
                $sql .= " AND id != :id";
                $params[':id'] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result['count'] > 0;
            
        } catch (PDOException $e) {
            error_log("Username exists check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if email exists
     */
    public function emailExists($email, $excludeId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM users WHERE email = :email";
            $params = [':email' => $email];
            
            if ($excludeId) {
                $sql .= " AND id != :id";
                $params[':id'] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result['count'] > 0;
            
        } catch (PDOException $e) {
            error_log("Email exists check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log user action
     */
    public function logAction($userId, $action, $details = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_logs (user_id, action, ip_address, user_agent, description)
                VALUES (:user_id, :action, :ip_address, :user_agent, :description)
            ");
            
            return $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':ip_address' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':description' => $details
            ]);
            
        } catch (PDOException $e) {
            error_log("Log action error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user logs
     */
    public function getUserLogs($userId, $limit = 50) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM user_logs 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get user logs error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get total user count
     */
    public function getTotalUsers() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM users");
            $result = $stmt->fetch();
            return $result['total'] ?? 0;
        } catch (PDOException $e) {
            error_log("Get total users error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get active user count
     */
    public function getActiveUsers() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
            $result = $stmt->fetch();
            return $result['total'] ?? 0;
        } catch (PDOException $e) {
            error_log("Get active users error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Verify user password
     */
    public function verifyPassword($userId, $password) {
        try {
            $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return false;
            }
            
            return password_verify($password, $user['password_hash']);
            
        } catch (PDOException $e) {
            error_log("Verify password error: " . $e->getMessage());
            return false;
        }
    }
}
?>