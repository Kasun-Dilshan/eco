<?php
require_once dirname(__DIR__) . '/../config.php';
require_once dirname(__DIR__) . '/../db.php';

class User {
    private $db;
    
    // User types constants
    const USER_ADMIN = 'admin';
    const USER_STAFF = 'staff';
    const USER_AGENT = 'agent';
    
    
    // User status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SUSPENDED = 'suspended';
    
    public function __construct() {
        global $db;
        $this->db = $db;
    }
    
    /**
     * Initialize database tables
     */
    public function initDatabase() {
        try {
            $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(100) NOT NULL UNIQUE,
                full_name VARCHAR(255) NOT NULL,
                branch_name VARCHAR(100),
                phone VARCHAR(20),
                user_type ENUM('admin', 'staff', 'agent') DEFAULT 'staff',
                status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
                password_hash VARCHAR(255) NOT NULL,
                last_login DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            CREATE TABLE IF NOT EXISTS user_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                permission VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_permission (user_id, permission),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            CREATE TABLE IF NOT EXISTS user_activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                description TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            
            $this->db->exec($sql);
            
            // Create default admin user if not exists
            $this->createDefaultAdmin();
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Database initialization error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create default admin user
     */
    private function createDefaultAdmin() {
        // Check if admin already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = 'admin' OR user_type = 'admin'");
        $stmt->execute();
        
        if (!$stmt->fetch()) {
            $data = [
                'username' => 'admin',
                'email' => 'admin@ecowealth.com',
                'full_name' => 'System Administrator',
                'user_type' => self::USER_ADMIN,
                'password' => 'Admin@123', // Default password
                'status' => self::STATUS_ACTIVE
            ];
            
            $this->createUser($data);
        }
    }
    
    /**
     * Create a new user
     */
    public function createUser($data) {
        try {
            // Validate required fields
            $required = ['username', 'email', 'full_name', 'user_type', 'password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required.'];
                }
            }
            
            // Check if username already exists
            $checkStmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->execute([$data['username']]);
            if ($checkStmt->fetch()) {
                return ['success' => false, 'message' => 'Username already exists.'];
            }
            
            // Check if email already exists
            $checkStmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$data['email']]);
            if ($checkStmt->fetch()) {
                return ['success' => false, 'message' => 'Email already exists.'];
            }
            
            // Validate user type
            $validTypes = [self::USER_ADMIN, self::USER_STAFF, self::USER_AGENT];
            if (!in_array($data['user_type'], $validTypes)) {
                return ['success' => false, 'message' => 'Invalid user type.'];
            }
            
            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // Start transaction
            $this->db->beginTransaction();
            
            // Insert user
            $stmt = $this->db->prepare("
                INSERT INTO users (username, email, full_name, branch_name, phone, user_type, status, password_hash)
                VALUES (:username, :email, :full_name, :branch_name, :phone, :user_type, :status, :password_hash)
            ");
            
            $stmt->execute([
                ':username' => $data['username'],
                ':email' => $data['email'],
                ':full_name' => $data['full_name'],
                ':branch_name' => $data['branch_name'] ?? null,
                ':phone' => $data['phone'] ?? null,
                ':user_type' => $data['user_type'],
                ':status' => $data['status'] ?? self::STATUS_ACTIVE,
                ':password_hash' => $hashedPassword
            ]);
            
            $userId = $this->db->lastInsertId();
            
            // Assign permissions
            if (!empty($data['permissions'])) {
                $permissionStmt = $this->db->prepare("
                    INSERT INTO user_permissions (user_id, permission)
                    VALUES (:user_id, :permission)
                ");
                
                foreach ($data['permissions'] as $permission) {
                    $permissionStmt->execute([
                        ':user_id' => $userId,
                        ':permission' => $permission
                    ]);
                }
            }
            
            // Log the action
            $this->logActivity($userId, 'user_created', 'New user account created');
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'User created successfully.',
                'user_id' => $userId
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Check if user has specific permission
     */
    public function hasPermission($permission, $userId = null) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? 0;
        }
        
        if (!$userId) return false;
        
        // Admin has all permissions
        $userType = $this->getUserType($userId);
        if ($userType === self::USER_ADMIN) {
            return true;
        }
        
        // Check specific permission
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM user_permissions 
            WHERE user_id = ? AND permission = ?
        ");
        
        $stmt->execute([$userId, $permission]);
        $result = $stmt->fetch();
        
        return $result && $result['count'] > 0;
    }
    
    /**
     * Get all available permissions
     */
    public function getPermissions() {
        $permissions = [
            // User Management
            'view_users' => 'View Users',
            'add_users' => 'Add Users',
            'edit_users' => 'Edit Users',
            'delete_users' => 'Delete Users',
            'change_user_status' => 'Change User Status',
            
            // Application Management
            'view_applications' => 'View Applications',
            'review_applications' => 'Review Applications',
            'approve_applications' => 'Approve Applications',
            'reject_applications' => 'Reject Applications',
            'export_applications' => 'Export Applications',
            
            // Reports
            'view_reports' => 'View Reports',
            'generate_reports' => 'Generate Reports',
            'export_reports' => 'Export Reports',
            
            // Settings
            'manage_settings' => 'Manage Settings',
            'manage_branches' => 'Manage Branches',
            
            // Communication
            'send_emails' => 'Send Emails',
            'send_sms' => 'Send SMS',
            'view_notifications' => 'View Notifications',
            
            // File Management
            'upload_files' => 'Upload Files',
            'download_files' => 'Download Files',
            'delete_files' => 'Delete Files'
        ];
        
        return $permissions;
    }
    
    /**
     * Get user type
     */
    private function getUserType($userId) {
        $stmt = $this->db->prepare("SELECT user_type FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return $result['user_type'] ?? null;
    }
    
    /**
     * Log user activity
     */
    private function logActivity($userId, $action, $description = '') {
        $stmt = $this->db->prepare("
            INSERT INTO user_activity_logs (user_id, action, description, ip_address)
            VALUES (:user_id, :action, :description, :ip_address)
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    }
    
    /**
     * Get user by ID
     */
    public function getUser($userId) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Get user permissions
            $user['permissions'] = $this->getUserPermissions($userId);
        }
        
        return $user;
    }
    
    /**
     * Update user
     */
    public function updateUser($userId, $data) {
        try {
            // Build update query
            $fields = [];
            $params = [':id' => $userId];
            
            if (isset($data['email'])) {
                $fields[] = 'email = :email';
                $params[':email'] = $data['email'];
            }
            
            if (isset($data['full_name'])) {
                $fields[] = 'full_name = :full_name';
                $params[':full_name'] = $data['full_name'];
            }
            
            if (isset($data['branch_name'])) {
                $fields[] = 'branch_name = :branch_name';
                $params[':branch_name'] = $data['branch_name'];
            }
            
            if (isset($data['phone'])) {
                $fields[] = 'phone = :phone';
                $params[':phone'] = $data['phone'];
            }
            
            if (isset($data['user_type'])) {
                $fields[] = 'user_type = :user_type';
                $params[':user_type'] = $data['user_type'];
            }
            
            if (isset($data['status'])) {
                $fields[] = 'status = :status';
                $params[':status'] = $data['status'];
            }
            
            if (isset($data['password'])) {
                $fields[] = 'password_hash = :password_hash';
                $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($fields)) {
                return ['success' => false, 'message' => 'No data to update.'];
            }
            
            $fields[] = 'updated_at = NOW()';
            
            $stmt = $this->db->prepare("
                UPDATE users 
                SET " . implode(', ', $fields) . "
                WHERE id = :id
            ");
            
            $stmt->execute($params);
            
            // Update permissions if provided
            if (isset($data['permissions'])) {
                // Remove existing permissions
                $deleteStmt = $this->db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
                $deleteStmt->execute([$userId]);
                
                // Add new permissions
                $permissionStmt = $this->db->prepare("
                    INSERT INTO user_permissions (user_id, permission)
                    VALUES (:user_id, :permission)
                ");
                
                foreach ($data['permissions'] as $permission) {
                    $permissionStmt->execute([
                        ':user_id' => $userId,
                        ':permission' => $permission
                    ]);
                }
            }
            
            $this->logActivity($userId, 'user_updated', 'User profile updated');
            
            return ['success' => true, 'message' => 'User updated successfully.'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete user
     */
    public function deleteUser($userId) {
        try {
            // Don't allow self-deletion
            if ($userId == ($_SESSION['user_id'] ?? 0)) {
                return ['success' => false, 'message' => 'You cannot delete your own account.'];
            }
            
            $this->db->beginTransaction();
            
            // Delete permissions first
            $stmt = $this->db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Delete activity logs
            $stmt = $this->db->prepare("DELETE FROM user_activity_logs WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Delete user
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            $this->logActivity($_SESSION['user_id'] ?? 0, 'user_deleted', "Deleted user ID: $userId");
            
            $this->db->commit();
            
            return ['success' => true, 'message' => 'User deleted successfully.'];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get all users - FIXED VERSION
     */
    public function getAllUsers($filters = []) {
        $where = [];
        $params = [];
        
        if (!empty($filters['user_type'])) {
            $where[] = 'user_type = ?';
            $params[] = $filters['user_type'];
        }
        
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['branch_name'])) {
            $where[] = 'branch_name = ?';
            $params[] = $filters['branch_name'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(username LIKE ? OR email LIKE ? OR full_name LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Fixed SQL query - simplified without GROUP_CONCAT
        $sql = "
            SELECT u.*
            FROM users u
            $whereClause
            ORDER BY u.created_at DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // Get permissions separately for each user
        foreach ($users as &$user) {
            $user['permissions'] = $this->getUserPermissions($user['id']);
            
            // Get additional statistics
            $user['total_applications'] = $this->getUserApplicationsCount($user['id']);
            $user['total_actions'] = $this->getUserActionsCount($user['id']);
        }
        
        return $users;
    }
    
    /**
     * Get user permissions - FIXED VERSION
     */
    public function getUserPermissions($userId) {
        // First, check if the user_permissions table exists and has the permission column
        try {
            $stmt = $this->db->prepare("
                SELECT permission 
                FROM user_permissions 
                WHERE user_id = ?
            ");
            
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            // If table doesn't exist or column doesn't exist, return empty array
            error_log("Error getting user permissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user applications count
     */
    private function getUserApplicationsCount($userId) {
        try {
            // Check if investors table has created_by column
            $stmt = $this->db->prepare("SHOW COLUMNS FROM investors LIKE 'created_by'");
            $stmt->execute();
            $hasCreatedByColumn = $stmt->fetch();
            
            if ($hasCreatedByColumn) {
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM investors WHERE created_by = ?");
                $stmt->execute([$userId]);
                $result = $stmt->fetch();
                return $result['count'] ?? 0;
            }
            
            return 0;
        } catch (PDOException $e) {
            error_log("Error getting user applications count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get user actions count
     */
    private function getUserActionsCount($userId) {
        try {
            // Get user's full name
            $user = $this->getUser($userId);
            if (!$user) return 0;
            
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM application_logs WHERE performed_by = ?");
            $stmt->execute([$user['full_name']]);
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("Error getting user actions count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get user permissions with names
     */
    public function getUserPermissionsWithNames($userId) {
        $permissions = $this->getUserPermissions($userId);
        $allPermissions = $this->getPermissions();
        $result = [];
        
        foreach ($permissions as $permission) {
            if (isset($allPermissions[$permission])) {
                $result[$permission] = $allPermissions[$permission];
            }
        }
        
        return $result;
    }
    
    /**
     * Validate login credentials
     */
    public function validateLogin($username, $password) {
        $stmt = $this->db->prepare("
            SELECT id, username, email, full_name, user_type, status, password_hash
            FROM users 
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }
        
        // Check status
        if ($user['status'] !== self::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'Your account is ' . $user['status'] . '.'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['last_activity'] = time();
        
        // Get user permissions
        $_SESSION['user_permissions'] = $this->getUserPermissions($user['id']);
        
        // Log login
        $this->logActivity($user['id'], 'login', 'User logged in');
        
        // Update last login
        $updateStmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_id'], 'logout', 'User logged out');
        }
        
        // Clear all session variables
        $_SESSION = [];
        
        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            return false;
        }
        
        // Check session timeout
        return $this->checkSessionTimeout();
    }
    
    /**
     * Check session timeout
     */
    public function checkSessionTimeout($timeoutMinutes = 30) {
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }
        
        $timeout = $timeoutMinutes * 60;
        $currentTime = time();
        
        if (($currentTime - $_SESSION['last_activity']) > $timeout) {
            $this->logout();
            return false;
        }
        
        $_SESSION['last_activity'] = $currentTime;
        return true;
    }
    
    /**
     * Get user statistics
     */
    public function getUserStatistics() {
        $stats = [];
        
        // Total users
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM users");
        $stats['total_users'] = $stmt->fetch()['total'];
        
        // Active users
        $stmt = $this->db->query("SELECT COUNT(*) as active FROM users WHERE status = 'active'");
        $stats['active_users'] = $stmt->fetch()['active'];
        
        // Users by type
        $stmt = $this->db->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
        $stats['users_by_type'] = $stmt->fetchAll();
        
        return $stats;
    }
    
    /**
     * Get branches list
     */
    public function getBranches() {
        $stmt = $this->db->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name != '' ORDER BY branch_name");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    
    /**
     * Get user activity logs
     */
    public function getUserActivityLogs($userId = null, $limit = 50) {
        $where = '';
        $params = [];
        
        if ($userId !== null) {
            $where = 'WHERE user_id = ?';
            $params[] = $userId;
        }
        
        $stmt = $this->db->prepare("
            SELECT al.*, u.username, u.full_name
            FROM user_activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $where
            ORDER BY al.created_at DESC
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Change user status
     */
    public function changeUserStatus($userId, $status) {
        try {
            $validStatuses = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED];
            
            if (!in_array($status, $validStatuses)) {
                return ['success' => false, 'message' => 'Invalid status.'];
            }
            
            // Don't allow changing own status to inactive/suspended
            if ($userId == ($_SESSION['user_id'] ?? 0) && $status !== self::STATUS_ACTIVE) {
                return ['success' => false, 'message' => 'You cannot change your own status.'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE users 
                SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([$status, $userId]);
            
            $statusName = ucfirst($status);
            $this->logActivity($_SESSION['user_id'] ?? 0, 'user_status_changed', "Changed user $userId status to $statusName");
            
            return ['success' => true, 'message' => "User status changed to $statusName successfully."];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Reset user password
     */
    public function resetPassword($userId, $newPassword) {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $this->db->prepare("
                UPDATE users 
                SET password_hash = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([$hashedPassword, $userId]);
            
            $this->logActivity($_SESSION['user_id'] ?? 0, 'password_reset', "Reset password for user $userId");
            
            return ['success' => true, 'message' => 'Password reset successfully.'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get user by username or email
     */
    public function getUserByUsernameOrEmail($identifier) {
        $stmt = $this->db->prepare("
            SELECT * FROM users 
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        
        $stmt->execute([$identifier, $identifier]);
        return $stmt->fetch();
    }
    
    /**
     * Check if current user can perform action
     */
    public function canPerform($action, $targetUser = null) {
        $currentUserId = $_SESSION['user_id'] ?? 0;
        $currentUserType = $_SESSION['user_type'] ?? null;
        
        // Admin can do everything
        if ($currentUserType === self::USER_ADMIN) {
            return true;
        }
        
        // Staff can only edit/delete agents
        if ($currentUserType === self::USER_STAFF) {
            if ($targetUser) {
                $targetUserType = $this->getUserType($targetUser);
                return $targetUserType === self::USER_AGENT;
            }
            return false;
        }
        
        // Agents cannot edit/delete anyone
        return false;
    }
    
    /**
     * Initialize or repair database tables
     */
    public function repairDatabase() {
        try {
            // Check if user_permissions table exists
            $stmt = $this->db->query("SHOW TABLES LIKE 'user_permissions'");
            $tableExists = $stmt->fetch();
            
            if (!$tableExists) {
                // Create the table
                $sql = "
                CREATE TABLE user_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    permission VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_permission (user_id, permission),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ";
                $this->db->exec($sql);
                return ['success' => true, 'message' => 'User permissions table created successfully.'];
            } else {
                // Check if permission column exists
                $stmt = $this->db->prepare("SHOW COLUMNS FROM user_permissions LIKE 'permission'");
                $stmt->execute();
                $columnExists = $stmt->fetch();
                
                if (!$columnExists) {
                    // Add permission column
                    $this->db->exec("ALTER TABLE user_permissions ADD COLUMN permission VARCHAR(50) NOT NULL");
                    return ['success' => true, 'message' => 'Permission column added successfully.'];
                }
            }
            
            return ['success' => true, 'message' => 'Database tables are intact.'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database repair failed: ' . $e->getMessage()];
        }
    }
}



?>


