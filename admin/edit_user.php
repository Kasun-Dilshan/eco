<?php
require_once '../config.php';
require_once '../db.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_id = $_GET['id'] ?? 0;
if (!$user_id) {
    header('Location: users.php');
    exit();
}

// Don't allow editing own account here (use profile.php)
$current_user_id = $_SESSION['user_id'] ?? 0;
if ($user_id == $current_user_id) {
    header('Location: profile.php');
    exit();
}

$error = '';
$success = '';

// Get user data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    if (!$user_data) {
        header('Location: users.php');
        exit();
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get user permissions
$user_permissions = [];
try {
    $stmt = $db->prepare("
        SELECT p.*, pc.category_name 
        FROM user_permissions up 
        JOIN permissions p ON up.permission_id = p.id 
        JOIN permission_categories pc ON p.category_id = pc.id
        WHERE up.user_id = ?
        ORDER BY pc.category_name, p.permission_name
    ");
    $stmt->execute([$user_id]);
    $user_permissions = $stmt->fetchAll();
    
    // Organize user permissions by category
    $user_permissions_by_category = [];
    foreach ($user_permissions as $perm) {
        $user_permissions_by_category[$perm['category_name']][] = $perm;
    }
} catch (PDOException $e) {
    // If permissions table doesn't exist, just continue
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = $_POST;
    
    // Validate required fields
    $required_fields = ['full_name', 'email', 'user_type', 'status'];
    foreach ($required_fields as $field) {
        if (empty($form_data[$field])) {
            $error = "Please fill in all required fields.";
            break;
        }
    }
    
    if (!$error) {
        try {
            $db->beginTransaction();
            
            // Update user
            $update_stmt = $db->prepare("
                UPDATE users 
                SET full_name = :full_name,
                    email = :email,
                    branch_name = :branch_name,
                    phone = :phone,
                    user_type = :user_type,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $update_data = [
                ':full_name' => $form_data['full_name'],
                ':email' => $form_data['email'],
                ':branch_name' => $form_data['branch_name'] ?? null,
                ':phone' => $form_data['phone'] ?? null,
                ':user_type' => $form_data['user_type'],
                ':status' => $form_data['status'],
                ':id' => $user_id
            ];
            
            // Only update password if provided
            if (!empty($form_data['password'])) {
                if ($form_data['password'] !== ($form_data['confirm_password'] ?? '')) {
                    $error = "Passwords do not match.";
                } else {
                    $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);
                    $update_stmt = $db->prepare("
                        UPDATE users 
                        SET full_name = :full_name,
                            email = :email,
                            branch_name = :branch_name,
                            phone = :phone,
                            user_type = :user_type,
                            status = :status,
                            password = :password,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $update_data[':password'] = $hashed_password;
                }
            }
            
            if (!$error) {
                $update_stmt->execute($update_data);
                
                // Handle permissions update
                if (isset($form_data['permissions'])) {
                    // Clear existing permissions
                    $db->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$user_id]);
                    
                    // Insert new permissions
                    $perm_stmt = $db->prepare("
                        INSERT INTO user_permissions (user_id, permission_id) 
                        SELECT :user_id, id FROM permissions WHERE permission_key = :permission_key
                    ");
                    
                    foreach ($form_data['permissions'] as $permission_key) {
                        $perm_stmt->execute([
                            ':user_id' => $user_id,
                            ':permission_key' => $permission_key
                        ]);
                    }
                }
                
                $db->commit();
                
                $success = 'User updated successfully!';
                // Refresh user data
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_data = $stmt->fetch();
                
                // Refresh user permissions
                $stmt = $db->prepare("
                    SELECT p.*, pc.category_name 
                    FROM user_permissions up 
                    JOIN permissions p ON up.permission_id = p.id 
                    JOIN permission_categories pc ON p.category_id = pc.id
                    WHERE up.user_id = ?
                    ORDER BY pc.category_name, p.permission_name
                ");
                $stmt->execute([$user_id]);
                $user_permissions = $stmt->fetchAll();
                
                // Reorganize by category
                $user_permissions_by_category = [];
                foreach ($user_permissions as $perm) {
                    $user_permissions_by_category[$perm['category_name']][] = $perm;
                }
                
            }
            
        } catch (PDOException $e) {
            $db->rollBack();
            $error = "Failed to update user: " . $e->getMessage();
        }
    }
}

// Get all permissions
$permissions = [];
try {
    $stmt = $db->prepare("
        SELECT p.*, pc.category_name 
        FROM permissions p
        JOIN permission_categories pc ON p.category_id = pc.id
        ORDER BY pc.category_name, p.permission_name
    ");
    $stmt->execute();
    $permissions_raw = $stmt->fetchAll();
    
    // Organize by category
    foreach ($permissions_raw as $perm) {
        $permissions[$perm['category_name']][] = $perm;
    }
} catch (PDOException $e) {
    // If permissions table doesn't exist, just continue
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --accent-glow: rgba(34, 197, 94, 0.4);
            --neon: #00ff88;
            --success: #10b981;
            --error: #ef4444;
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
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
            color: var(--neon);
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
        
        /* Main Content */
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
            background: linear-gradient(90deg, var(--text), var(--neon));
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
        
        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert.success {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid var(--success);
            color: var(--text);
        }
        
        .alert.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--error);
            color: var(--text);
        }
        
        /* User Info Box */
        .user-info-box {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            padding: 10px;
            background: rgba(10, 47, 29, 0.3);
            border-radius: 6px;
        }
        
        .info-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--text);
        }
        
        /* Permissions Summary */
        .permissions-summary {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .summary-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
        }
        
        .permission-count {
            background: var(--accent);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .category-summary {
            margin-bottom: 20px;
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
        }
        
        .category-name {
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }
        
        .category-count {
            background: rgba(34, 197, 94, 0.2);
            color: var(--accent);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .permissions-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
        }
        
        .permission-item-summary {
            padding: 10px;
            background: rgba(26, 77, 51, 0.3);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 6px;
            font-size: 12px;
        }
        
        .permission-name {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 3px;
        }
        
        .permission-desc {
            color: var(--text-muted);
            font-size: 11px;
        }
        
        /* Form Container */
        .form-container {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
        }
        
        .form-group label.required::after {
            content: " *";
            color: var(--error);
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        
        .password-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
        }
        
        .password-strength {
            height: 4px;
            background: rgba(26, 77, 51, 0.7);
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
        }
        
        /* Permissions Edit Section */
        .permissions-section {
            margin: 30px 0;
        }
        
        .select-all {
            padding: 15px;
            background: rgba(26, 77, 51, 0.3);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .permission-category {
            margin-bottom: 25px;
        }
        
        .category-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 15px;
            padding-left: 10px;
            border-left: 3px solid var(--accent);
        }
        
        .permission-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .permission-item {
            display: flex;
            gap: 10px;
            padding: 15px;
            background: rgba(26, 77, 51, 0.3);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .permission-item:hover {
            background: rgba(34, 197, 94, 0.1);
            border-color: var(--accent);
        }
        
        .permission-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 3px;
        }
        
        .permission-label {
            flex: 1;
        }
        
        .permission-name-edit {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 5px;
        }
        
        .permission-desc-edit {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn-full {
            flex: 1;
            justify-content: center;
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
            
            .user-info-box {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .permissions-list {
                grid-template-columns: 1fr;
            }
            
            .permission-list {
                grid-template-columns: 1fr;
            }
            
            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-leaf"></i> EcoWealth Admin</h2>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></p>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="applications.php">
                            <i class="fas fa-file-alt"></i> Applications
                        </a>
                    </li>
                    <li class="active">
                        <a href="users.php">
                            <i class="fas fa-users"></i> Users
                        </a>
                    </li>
                    <li>
                        <a href="profile.php">
                            <i class="fas fa-user-cog"></i> Profile
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="content-header">
                <h1><i class="fas fa-user-edit"></i> Edit User: <?php echo htmlspecialchars($user_data['full_name']); ?></h1>
                <div class="header-actions">
                    <a href="users.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                </div>
            </div>
            
            <!-- Display Messages -->
            <?php if ($success): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <!-- User Info Box -->
            <div class="user-info-box">
                <div class="info-item">
                    <div class="info-label">User ID</div>
                    <div class="info-value">#<?php echo $user_id; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Username</div>
                    <div class="info-value"><?php echo htmlspecialchars($user_data['username']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">User Type</div>
                    <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $user_data['user_type'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value" style="color: <?php echo getStatusColor($user_data['status']); ?>">
                        <?php echo ucfirst($user_data['status']); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Created</div>
                    <div class="info-value"><?php echo date('M d, Y', strtotime($user_data['created_at'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Last Login</div>
                    <div class="info-value"><?php echo $user_data['last_login'] ? date('M d, Y H:i', strtotime($user_data['last_login'])) : 'Never'; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Total Permissions</div>
                    <div class="info-value"><?php echo count($user_permissions); ?></div>
                </div>
            </div>
            
            <!-- Current Permissions Summary -->
            <?php if (!empty($user_permissions_by_category)): ?>
            <div class="permissions-summary">
                <div class="summary-header">
                    <h2 class="summary-title"><i class="fas fa-shield-alt"></i> Current Permissions</h2>
                    <div class="permission-count"><?php echo count($user_permissions); ?> Permissions</div>
                </div>
                
                <?php foreach ($user_permissions_by_category as $category_name => $category_permissions): ?>
                    <div class="category-summary">
                        <div class="category-header">
                            <div class="category-name"><?php echo ucfirst($category_name); ?></div>
                            <div class="category-count"><?php echo count($category_permissions); ?></div>
                        </div>
                        <div class="permissions-list">
                            <?php foreach ($category_permissions as $perm): ?>
                                <div class="permission-item-summary">
                                    <div class="permission-name"><?php echo $perm['permission_name']; ?></div>
                                    <div class="permission-desc"><?php echo $perm['description']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($user_data['user_type'])): ?>
            <div class="permissions-summary">
                <div class="summary-header">
                    <h2 class="summary-title"><i class="fas fa-shield-alt"></i> Permission Information</h2>
                </div>
                <div style="text-align: center; padding: 30px;">
                    <i class="fas fa-info-circle" style="font-size: 48px; color: var(--accent); margin-bottom: 15px;"></i>
                    <p>This user has <strong>default permissions</strong> based on their user type:</p>
                    <div style="background: rgba(34, 197, 94, 0.1); padding: 15px; border-radius: 8px; margin-top: 15px;">
                        <strong><?php echo ucfirst(str_replace('_', ' ', $user_data['user_type'])); ?> Role</strong>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- User Form -->
            <div class="form-container">
                <form method="POST" action="">
                    <h2 class="form-title">Edit User Information</h2>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" disabled>
                            <small class="password-hint">Username cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="required">Email Address</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user_data['email']); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="full_name" class="required">Full Name</label>
                            <input type="text" id="full_name" name="full_name" 
                                   value="<?php echo htmlspecialchars($user_data['full_name']); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_name">Branch Name</label>
                            <input type="text" id="branch_name" name="branch_name" 
                                   value="<?php echo htmlspecialchars($user_data['branch_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="user_type" class="required">User Type</label>
                            <select id="user_type" name="user_type" required>
                                <option value="admin" <?php echo $user_data['user_type'] == 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                <option value="branch_manager" <?php echo $user_data['user_type'] == 'branch_manager' ? 'selected' : ''; ?>>Branch Manager</option>
                                <option value="staff" <?php echo $user_data['user_type'] == 'staff' ? 'selected' : ''; ?>>Staff</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status" class="required">Account Status</label>
                            <select id="status" name="status" required>
                                <option value="active" <?php echo $user_data['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $user_data['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="suspended" <?php echo $user_data['status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" 
                                   minlength="8"
                                   oninput="checkPasswordStrength(this.value)"
                                   placeholder="Leave blank to keep current">
                            <div class="password-strength">
                                <div class="password-strength-bar" id="passwordStrengthBar"></div>
                            </div>
                            <div class="password-hint" id="passwordHint">
                                Leave blank to keep current password
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" 
                                   oninput="checkPasswordMatch()"
                                   placeholder="Leave blank if not changing">
                            <div class="password-hint" id="passwordMatch"></div>
                        </div>
                    </div>
                    
                    <!-- Permissions Edit Section - Only show if permissions exist -->
                    <?php if (!empty($permissions)): ?>
                    <div class="permissions-section">
                        <h2 class="form-title">Manage Permissions</h2>
                        <p class="password-hint">Select or deselect permissions to update user access rights.</p>
                        
                        <div class="select-all">
                            <label>
                                <input type="checkbox" id="selectAllPermissions" onchange="toggleAllPermissions()"
                                       <?php 
                                       // Count total permissions
                                       $total_perms = 0;
                                       foreach ($permissions as $category_perms) {
                                           $total_perms += count($category_perms);
                                       }
                                       echo count($user_permissions) == $total_perms ? 'checked' : ''; 
                                       ?>>
                                <strong>Select All Permissions</strong>
                            </label>
                        </div>
                        
                        <?php foreach ($permissions as $category => $category_permissions): ?>
                            <div class="permission-category">
                                <h3 class="category-title"><?php echo ucfirst($category); ?> 
                                    <small style="color: var(--text-muted); font-weight: normal;">
                                        (<?php echo count($category_permissions); ?> permissions)
                                    </small>
                                </h3>
                                <div class="permission-list">
                                    <?php foreach ($category_permissions as $perm): 
                                        $is_checked = false;
                                        foreach ($user_permissions as $user_perm) {
                                            if ($user_perm['permission_key'] == $perm['permission_key']) {
                                                $is_checked = true;
                                                break;
                                            }
                                        }
                                    ?>
                                    <div class="permission-item">
                                        <input type="checkbox" 
                                               id="perm_<?php echo $perm['permission_key']; ?>"
                                               name="permissions[]"
                                               value="<?php echo $perm['permission_key']; ?>"
                                               <?php echo $is_checked ? 'checked' : ''; ?>>
                                        <div class="permission-label">
                                            <div class="permission-name-edit"><?php echo $perm['permission_name']; ?></div>
                                            <div class="permission-desc-edit"><?php echo $perm['description']; ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary btn-full">
                            <i class="fas fa-save"></i> Update User
                        </button>
                        <a href="users.php" class="btn btn-secondary btn-full">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrengthBar');
            const hint = document.getElementById('passwordHint');
            
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthBar.style.background = '#ddd';
                hint.textContent = 'Leave blank to keep current password';
                hint.style.color = '#a7f3d0';
                return;
            }
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            let width, color, message;
            switch (strength) {
                case 0:
                case 1:
                    width = '20%';
                    color = '#ef4444';
                    message = 'Very Weak';
                    break;
                case 2:
                    width = '40%';
                    color = '#f59e0b';
                    message = 'Weak';
                    break;
                case 3:
                    width = '60%';
                    color = '#eab308';
                    message = 'Fair';
                    break;
                case 4:
                    width = '80%';
                    color = '#84cc16';
                    message = 'Good';
                    break;
                case 5:
                    width = '100%';
                    color = '#10b981';
                    message = 'Strong';
                    break;
            }
            
            strengthBar.style.width = width;
            strengthBar.style.background = color;
            hint.textContent = 'Strength: ' + message;
            hint.style.color = color;
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (password.length === 0 && confirmPassword.length === 0) {
                matchDiv.textContent = '';
                return;
            }
            
            if (password === confirmPassword) {
                matchDiv.textContent = '✓ Passwords match';
                matchDiv.style.color = '#10b981';
            } else {
                matchDiv.textContent = '✗ Passwords do not match';
                matchDiv.style.color = '#ef4444';
            }
        }
        
        function toggleAllPermissions() {
            const selectAll = document.getElementById('selectAllPermissions');
            const checkboxes = document.querySelectorAll('input[name="permissions[]"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }
        
        // Update select all checkbox when individual permissions change
        document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('input[name="permissions[]"]');
                const checkedCount = document.querySelectorAll('input[name="permissions[]"]:checked').length;
                const selectAll = document.getElementById('selectAllPermissions');
                
                if (checkedCount === allCheckboxes.length) {
                    selectAll.checked = true;
                } else {
                    selectAll.checked = false;
                }
            });
        });
        
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Only validate if password is being changed
            if (password.length > 0 || confirmPassword.length > 0) {
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match. Please check and try again.');
                    document.getElementById('confirm_password').focus();
                    return false;
                }
                
                if (password.length < 8) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters.');
                    document.getElementById('password').focus();
                    return false;
                }
            }
            
            return true;
        });
    </script>
</body>
</html>

<?php
// Helper function to get status color
function getStatusColor($status) {
    switch ($status) {
        case 'active': return '#10b981';
        case 'inactive': return '#6b7280';
        case 'suspended': return '#ef4444';
        default: return '#6b7280';
    }
}
?>