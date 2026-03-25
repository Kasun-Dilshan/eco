<?php
require_once '../config.php';
require_once '../db.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// Include User class
require_once 'includes/User.php';

$user = new User();


$error = '';
$success = '';
$form_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $form_data = $_POST;
    
    // Create user
    $result = $user->createUser([
        'username' => $form_data['username'],
        'email' => $form_data['email'],
        'full_name' => $form_data['full_name'],
        'branch_name' => $form_data['branch_name'] ?? '',
        'phone' => $form_data['phone'] ?? '',
        'user_type' => $form_data['user_type'],
        'status' => $form_data['status'],
        'password' => $form_data['password'],
        'permissions' => $form_data['permissions'] ?? []
    ]);
    
    if ($result['success']) {
        $success = 'User created successfully! User ID: ' . $result['user_id'];
        $form_data = []; // Clear form
    } else {
        $error = $result['message'];
    }
}

// Get all permissions
$permissions = $user->getPermissions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New User | Serendib Green Plantation Admin</title>
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
        
        /* Form Container */
        .form-container {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 30px;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
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
        
        .form-group .required::after {
            content: " *";
            color: var(--error);
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        
        .form-group input[type="password"] {
            font-family: monospace;
        }
        
        /* Permissions Section */
        .permissions-section {
            margin: 30px 0;
            padding: 25px;
            background: rgba(26, 77, 51, 0.3);
            border-radius: 12px;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .permission-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: rgba(26, 77, 51, 0.5);
            border-radius: 6px;
            border: 1px solid rgba(34, 197, 94, 0.1);
            transition: all 0.3s ease;
        }
        
        .permission-item:hover {
            background: rgba(34, 197, 94, 0.1);
            border-color: var(--accent);
        }
        
        .permission-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .btn-full {
            width: 100%;
        }
        
        .form-section-title {
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
        
        .password-strength {
            margin-top: 8px;
            font-size: 12px;
            padding: 5px 10px;
            border-radius: 4px;
            display: none;
        }
        
        .password-strength.weak {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            display: block;
        }
        
        .password-strength.medium {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
            display: block;
        }
        
        .password-strength.strong {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            display: block;
        }
        
        /* Branch Suggestions */
        .branch-suggestions {
            list-style: none;
            margin-top: 5px;
            background: rgba(26, 77, 51, 0.9);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 6px;
            max-height: 200px;
            overflow-y: auto;
            display: none;
            position: absolute;
            z-index: 1000;
            width: calc(100% - 30px);
        }
        
        .branch-suggestions li {
            padding: 10px 15px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .branch-suggestions li:hover {
            background: rgba(34, 197, 94, 0.2);
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .permissions-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
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
                    <li>
                        <a href="users.php">
                            <i class="fas fa-users"></i> User Management
                        </a>
                    </li>
                    <li class="active">
                        <a href="add_user.php">
                            <i class="fas fa-user-plus"></i> Add User
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
                        <a href="settings.php">
                            <i class="fas fa-cog"></i> Settings
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
                <h1>Add New User</h1>
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
            
            <div class="form-container">
                <form method="POST" action="" id="userForm">
                    <div class="form-section-title">
                        <i class="fas fa-user-circle"></i> Basic Information
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username" class="required">Username</label>
                            <input type="text" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" 
                                   required maxlength="50">
                            <small style="color: var(--text-muted); display: block; margin-top: 5px;">
                                Letters, numbers, and underscores only
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="required">Email Address</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="full_name" class="required">Full Name</label>
                            <input type="text" id="full_name" name="full_name" 
                                   value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                                   pattern="[0-9+\-\s\(\)]{10,}">
                        </div>
                        
                        <div class="form-group">
                            <label for="branch_name">Branch Name</label>
                            <input type="text" id="branch_name" name="branch_name" 
                                   value="<?php echo htmlspecialchars($form_data['branch_name'] ?? ''); ?>"
                                   list="branch-list">
                            <datalist id="branch-list">
                                <option value="Colombo Main Branch">
                                <option value="Kandy Branch">
                                <option value="Galle Branch">
                                <option value="Jaffna Branch">
                                <option value="Kurunegala Branch">
                                <option value="Anuradhapura Branch">
                                <option value="Matara Branch">
                                <option value="Ratnapura Branch">
                            </datalist>
                        </div>
                    </div>
                    
                    <div class="form-section-title">
                        <i class="fas fa-key"></i> Account Settings
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="user_type" class="required">User Type</label>
                            <select id="user_type" name="user_type" required>
                                <option value="">Select User Type</option>
                                <option value="<?php echo User::USER_ADMIN; ?>" 
                                    <?php echo ($form_data['user_type'] ?? '') === User::USER_ADMIN ? 'selected' : ''; ?>>
                                    Administrator
                                </option>
                                <option value="<?php echo User::USER_STAFF; ?>" 
                                    <?php echo ($form_data['user_type'] ?? '') === User::USER_STAFF ? 'selected' : ''; ?>>
                                    Staff Member
                                </option>
                                <option value="<?php echo User::USER_AGENT; ?>" 
                                    <?php echo ($form_data['user_type'] ?? '') === User::USER_AGENT ? 'selected' : ''; ?>>
                                    Agent
                                </option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status" class="required">Account Status</label>
                            <select id="status" name="status" required>
                                <option value="<?php echo User::STATUS_ACTIVE; ?>"
                                    <?php echo ($form_data['status'] ?? User::STATUS_ACTIVE) === User::STATUS_ACTIVE ? 'selected' : ''; ?>>
                                    Active
                                </option>
                                <option value="<?php echo User::STATUS_INACTIVE; ?>"
                                    <?php echo ($form_data['status'] ?? '') === User::STATUS_INACTIVE ? 'selected' : ''; ?>>
                                    Inactive
                                </option>
                                <option value="<?php echo User::STATUS_SUSPENDED; ?>"
                                    <?php echo ($form_data['status'] ?? '') === User::STATUS_SUSPENDED ? 'selected' : ''; ?>>
                                    Suspended
                                </option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="password" class="required">Password</label>
                            <input type="password" id="password" name="password" 
                                   value="<?php echo htmlspecialchars($form_data['password'] ?? ''); ?>" 
                                   required minlength="8">
                            <div id="password-strength" class="password-strength"></div>
                            <small style="color: var(--text-muted); display: block; margin-top: 5px;">
                                Minimum 8 characters with letters and numbers
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password" class="required">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                            <div id="password-match" style="margin-top: 5px; font-size: 12px;"></div>
                        </div>
                    </div>
                    
                    <div class="permissions-section">
                        <div class="form-section-title">
                            <i class="fas fa-shield-alt"></i> Permissions
                        </div>
                        <p style="color: var(--text-muted); margin-bottom: 15px;">
                            Select the permissions for this user. Administrators have all permissions automatically.
                        </p>
                        
                        <div class="permissions-grid" id="permissions-container">
                            <?php foreach ($permissions as $permission => $description): ?>
                                <div class="permission-item">
                                    <input type="checkbox" id="permission_<?php echo $permission; ?>" 
                                           name="permissions[]" value="<?php echo $permission; ?>"
                                           <?php echo (in_array($permission, $form_data['permissions'] ?? [])) ? 'checked' : ''; ?>>
                                    <label for="permission_<?php echo $permission; ?>" style="cursor: pointer;">
                                        <?php echo $description; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="button" class="btn btn-secondary" onclick="selectAllPermissions()">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="deselectAllPermissions()">
                                <i class="fas fa-square"></i> Deselect All
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                        <button type="submit" name="create_user" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const passwordStrength = document.getElementById('password-strength');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordMatch = document.getElementById('password-match');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            let message = '';
            let className = '';
            
            // Check length
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            
            // Check complexity
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            // Determine strength level
            if (strength < 3) {
                message = 'Weak password';
                className = 'weak';
            } else if (strength < 5) {
                message = 'Medium password';
                className = 'medium';
            } else {
                message = 'Strong password';
                className = 'strong';
            }
            
            // Update display
            passwordStrength.textContent = message;
            passwordStrength.className = 'password-strength ' + className;
        });
        
        // Password match checker
        confirmPasswordInput.addEventListener('input', function() {
            const password = passwordInput.value;
            const confirm = this.value;
            
            if (!confirm) {
                passwordMatch.textContent = '';
                passwordMatch.style.color = '';
                return;
            }
            
            if (password === confirm) {
                passwordMatch.textContent = '✓ Passwords match';
                passwordMatch.style.color = '#22c55e';
            } else {
                passwordMatch.textContent = '✗ Passwords do not match';
                passwordMatch.style.color = '#ef4444';
            }
        });
        
        // Select/Deselect all permissions
        function selectAllPermissions() {
            const checkboxes = document.querySelectorAll('#permissions-container input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        }
        
        function deselectAllPermissions() {
            const checkboxes = document.querySelectorAll('#permissions-container input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
        // Auto-disable permissions for admin
        const userTypeSelect = document.getElementById('user_type');
        userTypeSelect.addEventListener('change', function() {
            const isAdmin = this.value === '<?php echo User::USER_ADMIN; ?>';
            const checkboxes = document.querySelectorAll('#permissions-container input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.disabled = isAdmin;
                if (isAdmin) {
                    checkbox.checked = true;
                }
            });
        });
        
        // Form validation
        document.getElementById('userForm').addEventListener('submit', function(e) {
            const password = passwordInput.value;
            const confirm = confirmPasswordInput.value;
            
            // Check password match
            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match!');
                confirmPasswordInput.focus();
                return;
            }
            
            // Check password strength
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                passwordInput.focus();
                return;
            }
            
            // Check username format
            const username = document.getElementById('username').value;
            if (!/^[a-zA-Z0-9_]+$/.test(username)) {
                e.preventDefault();
                alert('Username can only contain letters, numbers, and underscores!');
                return;
            }
        });
        
        // Auto-generate username from email
        document.getElementById('email').addEventListener('blur', function() {
            const usernameInput = document.getElementById('username');
            const fullNameInput = document.getElementById('full_name');
            
            // Only auto-fill if username is empty
            if (usernameInput.value.trim() === '') {
                const email = this.value;
                if (email.includes('@')) {
                    const username = email.split('@')[0].toLowerCase();
                    usernameInput.value = username.replace(/[^a-z0-9_]/g, '_');
                }
            }
            
            // Auto-fill full name from email if empty
            if (fullNameInput.value.trim() === '') {
                const email = this.value;
                if (email.includes('@')) {
                    const namePart = email.split('@')[0];
                    const name = namePart.replace(/[._0-9]/g, ' ').trim();
                    if (name.length > 2) {
                        // Capitalize first letter of each word
                        const formattedName = name.split(' ')
                            .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
                            .join(' ');
                        fullNameInput.value = formattedName;
                    }
                }
            }
        });
    </script>
</body>
</html>