<?php
require_once 'includes/User.php';




// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if admin is logged in


$user = new User();
$message = '';
$type = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'] ?? 0;
        $adminId = $_SESSION['admin_id'] ?? 0;
        
        if ($userId) {
            // Prevent admin from deleting themselves
            if ($userId == $adminId) {
                $message = "You cannot delete your own account!";
                $type = 'error';
            } else {
                try {
                    // Get user info for confirmation from users table
                    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $userInfo = $stmt->fetch();
                    
                    if ($userInfo) {
                        // Delete the user from users table
                        $deleteStmt = $db->prepare("DELETE FROM users WHERE id = ?");
                        $deleteStmt->execute([$userId]);
                        
                        // Check if deletion was successful
                        if ($deleteStmt->rowCount() > 0) {
                            $message = "User deleted successfully!";
                            $type = 'success';
                            
                            // Log the deletion
                            $logStmt = $db->prepare("
                                INSERT INTO user_logs (user_id, action, description, ip_address)
                                VALUES (:user_id, :action, :description, :ip_address)
                            ");
                            
                            $logStmt->execute([
                                ':user_id' => $adminId,
                                ':action' => 'user_deleted',
                                ':description' => 'Deleted user: ' . $userInfo['full_name'] . ' (ID: ' . $userId . ')',
                               
                                ':ip_address' => $_SERVER['REMOTE_ADDR']
                            ]);
                        } else {
                            $message = "Failed to delete user!";
                            $type = 'error';
                        }
                    } else {
                        $message = "User not found!";
                        $type = 'error';
                    }
                } catch (PDOException $e) {
                    $message = "Database error: " . $e->getMessage();
                    $type = 'error';
                }
            }
        }
    } elseif (isset($_POST['change_status'])) {
        $userId = $_POST['user_id'] ?? 0;
        $status = $_POST['status'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? 0;
        
        if ($userId && $status) {
            // Prevent admin from suspending themselves
            if ($userId == $adminId && $status == 'suspended') {
                $message = "You cannot suspend your own account!";
                $type = 'error';
            } else {
                try {
                    // Update user status in users table
                    $updateStmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$status, $userId]);
                    
                    if ($updateStmt->rowCount() > 0) {
                        $message = "User status updated successfully!";
                        $type = 'success';
                        
                        // Get user info for logging
                        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $userInfo = $stmt->fetch();
                        
                        if ($userInfo) {
                            // Log the status change
                            $logStmt = $db->prepare("
                                INSERT INTO user_logs (user_id, action, description, performed_by, ip_address)
                                VALUES (:user_id, :action, :description, :performed_by, :ip_address)
                            ");
                            
                            $logStmt->execute([
                                ':user_id' => $adminId,
                                ':action' => 'status_changed',
                                ':description' => 'Changed status of ' . $userInfo['full_name'] . ' to ' . ucfirst($status),
                                ':performed_by' => $_SESSION['admin_name'],
                                ':ip_address' => $_SERVER['REMOTE_ADDR']
                            ]);
                        }
                    } else {
                        $message = "Failed to update user status!";
                        $type = 'error';
                    }
                } catch (PDOException $e) {
                    $message = "Database error: " . $e->getMessage();
                    $type = 'error';
                }
            }
        }
    }
}

// Get filters
$filters = [];
if (isset($_GET['type'])) {
    $filters['user_type'] = $_GET['type'];
}
if (isset($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['branch'])) {
    $filters['branch_name'] = $_GET['branch'];
}
if (isset($_GET['search'])) {
    $filters['search'] = $_GET['search'];
}

// Get all users
$users = $user->getAllUsers($filters);
$branches = $user->getBranches();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/admin-style.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --error: #ef4444;
            --warning: #f59e0b;
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            min-height: 100vh;
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background: rgba(10, 47, 29, 0.9);
            border-right: 1px solid rgba(34, 197, 94, 0.2);
            padding: 20px 0;
        }
        
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            margin-bottom: 20px;
        }
        
        .sidebar-header h2 {
            color: #00ff88;
            font-size: 20px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-nav ul {
            list-style: none;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .sidebar-nav a:hover {
            background: rgba(34, 197, 94, 0.1);
            color: var(--text);
            border-left-color: var(--accent);
        }
        
        .sidebar-nav .active a {
            background: rgba(34, 197, 94, 0.15);
            color: var(--text);
            border-left-color: var(--accent);
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
        }
        
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .content-header h1 {
            font-size: 28px;
            background: linear-gradient(90deg, var(--text), #00ff88);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            background: #16a34a;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--accent);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: var(--error);
            color: white;
            border: none;
        }
        
        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
            border: none;
        }
        
        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert.success {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid #10b981;
            color: var(--text);
        }
        
        .alert.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--error);
            color: var(--text);
        }
        
        .alert.warning {
            background: rgba(245, 158, 11, 0.2);
            border: 1px solid var(--warning);
            color: var(--text);
        }
        
        .alert.info {
            background: rgba(59, 130, 246, 0.2);
            border: 1px solid #3b82f6;
            color: var(--text);
        }
        
        .filters {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            transition: all 0.3s ease;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2);
        }
        
        .search-box {
            display: flex;
            gap: 10px;
        }
        
        .search-box input {
            flex: 1;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .info-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .user-table th {
            background: rgba(26, 77, 51, 0.9);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text);
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            position: sticky;
            top: 0;
        }
        
        .user-table td {
            padding: 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
            vertical-align: middle;
        }
        
        .user-table tr {
            transition: all 0.3s ease;
        }
        
        .user-table tr:hover {
            background: rgba(34, 197, 94, 0.05);
            transform: translateY(-1px);
        }
        
        .user-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-admin { 
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 2px 4px rgba(139, 92, 246, 0.3);
        }
        .badge-staff { 
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
        }
        .badge-agent { 
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active { 
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }
        .status-inactive { 
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
            box-shadow: 0 2px 4px rgba(107, 114, 128, 0.3);
        }
        .status-suspended { 
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .btn-small {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .btn-view {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .btn-edit {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .btn-view:hover {
            background: rgba(59, 130, 246, 0.4);
            border-color: #60a5fa;
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-edit:hover {
            background: rgba(245, 158, 11, 0.4);
            border-color: #fbbf24;
            color: white;
            transform: translateY(-1px);
        }
        
        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.4);
            border-color: #f87171;
            color: white;
            transform: translateY(-1px);
        }
        
        .status-select-form {
            display: inline-block;
            min-width: 120px;
        }
        
        .status-select {
            padding: 6px 12px;
            background: rgba(26, 77, 51, 0.5);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .status-select:hover {
            background: rgba(34, 197, 94, 0.2);
            border-color: var(--accent);
        }
        
        .status-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2);
        }
        
        .delete-form {
            display: inline-block;
        }
        
        .no-users {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        .no-users i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .user-info {
            min-width: 200px;
        }
        
        .user-info strong {
            display: block;
            margin-bottom: 5px;
            color: var(--text);
            font-size: 14px;
        }
        
        .user-info small {
            display: block;
            color: var(--text-muted);
            font-size: 12px;
            margin-bottom: 2px;
        }
        
        .current-user-tag {
            display: inline-block;
            background: rgba(34, 197, 94, 0.2);
            color: var(--accent);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 5px;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            border-color: var(--accent);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .user-row-current {
            background: rgba(34, 197, 94, 0.1) !important;
            position: relative;
        }
        
        .user-row-current::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--accent);
            border-radius: 3px 0 0 3px;
        }
        
        .delete-confirm-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: rgba(10, 47, 29, 0.95);
            border: 1px solid var(--accent);
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        .modal-title {
            color: var(--accent);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        
        .modal-buttons .btn {
            flex: 1;
            justify-content: center;
        }
        
        .delete-warning {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .delete-warning ul {
            margin: 10px 0 10px 20px;
            color: #fca5a5;
        }
        
        .delete-warning li {
            margin-bottom: 5px;
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .user-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .modal-buttons {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .content-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .header-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .btn {
                flex: 1;
                min-width: 120px;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-leaf"></i> Serendib Green Plantation Admin</h2>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></p>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="active">
                        <a href="users.php">
                            <i class="fas fa-users"></i> User Management
                        </a>
                    </li>
                    <li>
                        <a href="applications.php">
                            <i class="fas fa-file-alt"></i> Applications
                        </a>
                    </li>
                    <li>
                        <a href="reports.php">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </li>
                    <li>
                        <a href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="content-header">
                <h1>User Management</h1>
                <div class="header-actions">
                    <a href="add_user.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New User
                    </a>
                    <a href="repair_db.php" class="btn btn-warning">
                        <i class="fas fa-tools"></i> Repair DB
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert <?php echo $type; ?>">
                    <i class="fas fa-<?php 
                        if ($type === 'success') echo 'check-circle';
                        elseif ($type === 'warning') echo 'exclamation-triangle';
                        elseif ($type === 'info') echo 'info-circle';
                        else echo 'exclamation-circle';
                    ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- User Statistics -->
            <div class="stats-grid">
                <?php
                // Calculate statistics
                $totalUsers = count($users);
                $activeUsers = array_filter($users, fn($u) => $u['status'] === 'active');
                $adminUsers = array_filter($users, fn($u) => $u['user_type'] === 'admin');
                $staffUsers = array_filter($users, fn($u) => $u['user_type'] === 'staff');
                ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalUsers; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($activeUsers); ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($adminUsers); ?></div>
                    <div class="stat-label">Admins</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($staffUsers); ?></div>
                    <div class="stat-label">Staff Members</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters">
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="type"><i class="fas fa-user-tag"></i> User Type</label>
                            <select id="type" name="type">
                                <option value="">All Types</option>
                                <option value="admin" <?php echo ($_GET['type'] ?? '') == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="staff" <?php echo ($_GET['type'] ?? '') == 'staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="agent" <?php echo ($_GET['type'] ?? '') == 'agent' ? 'selected' : ''; ?>>Agent</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="status"><i class="fas fa-circle"></i> Status</label>
                            <select id="status" name="status">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($_GET['status'] ?? '') == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($_GET['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="suspended" <?php echo ($_GET['status'] ?? '') == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="branch"><i class="fas fa-building"></i> Branch</label>
                            <select id="branch" name="branch">
                                <option value="">All Branches</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo htmlspecialchars($branch); ?>" 
                                        <?php echo ($_GET['branch'] ?? '') == $branch ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branch); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="search"><i class="fas fa-search"></i> Search</label>
                            <div class="search-box">
                                <input type="text" id="search" name="search" 
                                       placeholder="Search by name, email, or username"
                                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                <button type="submit" class="btn btn-secondary" style="padding: 10px 20px;">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="users.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div><br>
            
            <!-- Users Table -->
               <div class="header-actions">
                    <a href="allusers.php" class="btn btn-primary">
                         All User Details
                    </a>
                    
                </div><br>
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-users"></i> Users (<?php echo $totalUsers; ?>)
                    <span style="font-size: 12px; color: var(--text-muted); margin-left: auto;">
                        <i class="fas fa-info-circle"></i> Click on user actions to manage
                    </span>
                </div>
                
                <?php if (empty($users)): ?>
                    <div class="no-users">
                        <i class="fas fa-user-slash"></i>
                        <h3>No users found</h3>
                        <p>Try adjusting your filters or add a new user.</p>
                        <a href="add_user.php" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Add New User
                        </a>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User Information</th>
                                    <th>Type</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th>Applications</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $adminId = $_SESSION['admin_id'] ?? 0;
                                foreach ($users as $userRow): 
                                    $isCurrentUser = ($userRow['id'] == $adminId);
                                ?>
                                <tr class="<?php echo $isCurrentUser ? 'user-row-current' : ''; ?>">
                                    <td>
                                        <span style="font-family: monospace; color: var(--text-muted);">
                                            #<?php echo str_pad($userRow['id'], 4, '0', STR_PAD_LEFT); ?>
                                        </span>
                                    </td>
                                    <td class="user-info">
                                        <strong><?php echo htmlspecialchars($userRow['full_name']); ?></strong>
                                        <small>
                                            <i class="fas fa-user-circle"></i> 
                                            <?php echo htmlspecialchars($userRow['username']); ?>
                                        </small>
                                        <small>
                                            <i class="fas fa-envelope"></i> 
                                            <?php echo htmlspecialchars($userRow['email']); ?>
                                        </small>
                                        <small>
                                            <i class="fas fa-phone"></i> 
                                            <?php echo htmlspecialchars($userRow['phone'] ?? 'N/A'); ?>
                                        </small>
                                        <?php if ($isCurrentUser): ?>
                                            <span class="current-user-tag">
                                                <i class="fas fa-user-check"></i> Current User
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="user-badge badge-<?php echo $userRow['user_type']; ?>">
                                            <i class="fas fa-<?php 
                                                echo $userRow['user_type'] === 'admin' ? 'crown' : 
                                                    ($userRow['user_type'] === 'staff' ? 'user-tie' : 'user'); 
                                            ?>"></i>
                                            <?php echo ucfirst($userRow['user_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($userRow['branch_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $userRow['status']; ?>">
                                            <i class="fas fa-circle"></i>
                                            <?php echo ucfirst($userRow['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: bold; font-size: 14px;">
                                            <?php echo $userRow['total_applications']; ?>
                                        </span>
                                        <small style="display: block; color: var(--text-muted); font-size: 11px;">
                                            Total Applications
                                        </small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <!-- View Button -->
                                            <a href="user1.php?id=<?php echo $userRow['id']; ?>" 
                                               class="btn-small btn-view" 
                                               title="View User Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            
                                            <!-- Edit Button -->
                                            <a href="edit_user.php?id=<?php echo $userRow['id']; ?>" 
                                               class="btn-small btn-edit" 
                                               title="Edit User">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            
                                            <!-- Status Change Form -->
                                            <form method="POST" action="" class="status-select-form" 
                                                  onsubmit="return confirmStatusChange(this)">
                                                <input type="hidden" name="user_id" value="<?php echo $userRow['id']; ?>">
                                                <input type="hidden" name="change_status" value="1">
                                                <select name="status" class="status-select" 
                                                        <?php echo $isCurrentUser ? 'disabled' : ''; ?>>
                                                    <option value="">Change Status</option>
                                                    <option value="active" <?php echo $userRow['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo $userRow['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                    <option value="suspended" <?php echo $userRow['status'] == 'suspended' ? 'selected' : ''; ?>>Suspend</option>
                                                </select>
                                            </form>
                                            
                                            <!-- Delete Button -->
                                            <button type="button" 
                                                    class="btn-small btn-delete delete-btn"
                                                    onclick="showDeleteModal(<?php echo $userRow['id']; ?>, '<?php echo htmlspecialchars(addslashes($userRow['full_name'])); ?>', '<?php echo $userRow['user_type']; ?>', '<?php echo $userRow['status']; ?>', <?php echo $userRow['total_applications']; ?>, <?php echo $isCurrentUser ? 'true' : 'false'; ?>)"
                                                    <?php echo $isCurrentUser ? 'disabled' : ''; ?>
                                                    title="<?php echo $isCurrentUser ? 'Cannot delete your own account' : 'Delete User'; ?>">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="delete-confirm-modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>
                <h3>Confirm User Deletion</h3>
            </div>
            
            <div id="modalUserInfo"></div>
            
            <div class="delete-warning">
                <p><strong>Warning: This action cannot be undone!</strong></p>
                <p>The following will be permanently deleted:</p>
                <ul>
                    <li>User account and all associated data</li>
                    <li>Login credentials</li>
                    <li>User permissions and settings</li>
                </ul>
                <p>Are you absolutely sure you want to proceed?</p>
            </div>
            
            <div style="margin: 20px 0;">
                <label for="confirmDelete" style="display: block; margin-bottom: 10px; color: var(--text-muted);">
                    <i class="fas fa-keyboard"></i> Type "DELETE" to confirm:
                </label>
                <input type="text" id="confirmDelete" 
                       style="width: 100%; padding: 10px; border-radius: 6px; 
                              background: rgba(26, 77, 51, 0.5); 
                              border: 1px solid rgba(239, 68, 68, 0.3);
                              color: var(--text);"
                       placeholder="Type DELETE here">
                <small style="display: block; margin-top: 5px; color: #fca5a5;">
                    <i class="fas fa-info-circle"></i> This is case-sensitive
                </small>
            </div>
            
            <div class="modal-buttons">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <input type="hidden" name="delete_user" value="1">
                    <button type="button" class="btn btn-secondary" onclick="hideDeleteModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                        <i class="fas fa-trash-alt"></i> Delete User
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Delete Modal Functions
        let currentDeleteUserId = null;
        
        function showDeleteModal(userId, userName, userType, userStatus, applications, isCurrentUser) {
            if (isCurrentUser) {
                alert("You cannot delete your own account!");
                return;
            }
            
            currentDeleteUserId = userId;
            
            // Set user info in modal
            const userInfo = `
                <div style="background: rgba(26, 77, 51, 0.5); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 10px 0; color: var(--text);">User Details:</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px;">
                        <div>
                            <strong>Name:</strong> ${userName}
                        </div>
                        <div>
                            <strong>Type:</strong> 
                            <span style="background: ${getBadgeColor(userType)}; 
                                  color: white; padding: 2px 8px; border-radius: 10px; 
                                  font-size: 12px; margin-left: 5px;">
                                ${userType.charAt(0).toUpperCase() + userType.slice(1)}
                            </span>
                        </div>
                        <div>
                            <strong>Status:</strong>
                            <span style="background: ${getStatusColor(userStatus)}; 
                                  color: white; padding: 2px 8px; border-radius: 10px; 
                                  font-size: 12px; margin-left: 5px;">
                                ${userStatus.charAt(0).toUpperCase() + userStatus.slice(1)}
                            </span>
                        </div>
                        <div>
                            <strong>Applications:</strong> ${applications}
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('modalUserInfo').innerHTML = userInfo;
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteModal').style.display = 'flex';
            
            // Clear and focus confirmation input
            const confirmInput = document.getElementById('confirmDelete');
            confirmInput.value = '';
            confirmInput.focus();
            
            // Enable/disable delete button
            updateDeleteButton();
        }
        
        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            currentDeleteUserId = null;
            document.getElementById('confirmDelete').value = '';
            document.getElementById('confirmDeleteBtn').disabled = true;
        }
        
        // Helper functions for badge colors
        function getBadgeColor(userType) {
            switch(userType) {
                case 'admin': return '#8b5cf6';
                case 'staff': return '#3b82f6';
                case 'agent': return '#10b981';
                default: return '#6b7280';
            }
        }
        
        function getStatusColor(status) {
            switch(status) {
                case 'active': return '#10b981';
                case 'inactive': return '#6b7280';
                case 'suspended': return '#ef4444';
                default: return '#6b7280';
            }
        }
        
        // Confirm status change
        function confirmStatusChange(form) {
            const select = form.querySelector('select[name="status"]');
            const userId = form.querySelector('input[name="user_id"]').value;
            const currentRow = form.closest('tr');
            const currentStatus = currentRow.querySelector('.status-badge').textContent.toLowerCase().trim();
            const newStatus = select.value;
            const userName = currentRow.querySelector('.user-info strong').textContent;
            
            if (newStatus === '' || newStatus === currentStatus) {
                return false;
            }
            
            // Check if trying to change own status
            const adminId = <?php echo json_encode($_SESSION['admin_id'] ?? 0); ?>;
            if (parseInt(userId) === parseInt(adminId)) {
                alert("You cannot change your own status!");
                select.value = currentStatus;
                return false;
            }
            
            let confirmMessage = `Are you sure you want to change ${userName}'s status from "${currentStatus}" to "${newStatus}"?`;
            
            if (newStatus === 'suspended') {
                confirmMessage += '\n\n⚠️ This user will be prevented from accessing the system!';
            }
            
            if (!confirm(confirmMessage)) {
                select.value = currentStatus;
                return false;
            }
            
            // Show loading on the select
            const originalHTML = select.innerHTML;
            select.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            select.disabled = true;
            
            // Add loading to the form
            form.style.opacity = '0.7';
            
            return true;
        }
        
        // Update delete button based on confirmation input
        document.getElementById('confirmDelete').addEventListener('input', function(e) {
            updateDeleteButton();
        });
        
        function updateDeleteButton() {
            const confirmInput = document.getElementById('confirmDelete');
            const deleteBtn = document.getElementById('confirmDeleteBtn');
            const confirmText = confirmInput.value.trim();
            
            deleteBtn.disabled = confirmText !== 'DELETE';
            
            if (!deleteBtn.disabled) {
                deleteBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete User';
                deleteBtn.style.background = '#ef4444';
            } else {
                deleteBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm First';
                deleteBtn.style.background = '#6b7280';
            }
        }
        
        // Handle delete form submission
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const confirmText = document.getElementById('confirmDelete').value.trim();
            if (confirmText !== 'DELETE') {
                alert('Please type "DELETE" exactly to confirm.');
                return;
            }
            
            // Show loading
            const deleteBtn = document.getElementById('confirmDeleteBtn');
            const originalHTML = deleteBtn.innerHTML;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin spinner"></i> Deleting...';
            deleteBtn.disabled = true;
            
            // Submit the form
            this.submit();
        });
        
        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideDeleteModal();
            }
        });
        
        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });
        
        // Auto-refresh page after actions
        <?php if ($message && $type === 'success'): ?>
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        <?php endif; ?>
        
        // Add fade-in animation for page load
        document.addEventListener('DOMContentLoaded', function() {
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.3s ease';
            
            setTimeout(() => {
                document.body.style.opacity = '1';
            }, 100);
        });
    </script>
</body>
</html>