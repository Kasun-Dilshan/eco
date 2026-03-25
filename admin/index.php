<?php
session_start();
require_once '../config.php';
require_once '../db.php';

// Redirect to appropriate dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
} elseif (isset($_SESSION['approver_id'])) {
    header('Location:dashboard.php');
    exit();
}

$error = '';
$success = '';
$login_type = 'admin'; // Default login type

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_type = $_POST['login_type'] ?? 'admin';
    
    // Validate inputs
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            if ($login_type === 'admin') {
                // Admin/User Login
                $stmt = $db->prepare("
                    SELECT id, username, password_hash, full_name, user_type, 
                           last_login, failed_attempts, status, login_attempts
                    FROM users 
                    WHERE username = ? AND user_type IN ('admin', 'branch_manager', 'staff')
                ");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Check account status
                    if ($user['status'] == 'suspended' || $user['status'] == 'inactive') {
                        $error = 'Your account is suspended or inactive. Please contact administrator.';
                    } 
                    // Check if account is locked due to too many failed attempts
                    elseif ($user['failed_attempts'] >= 5) {
                        $error = 'Account locked due to too many failed attempts. Please contact administrator.';
                    } 
                    // Verify password
                    elseif (password_verify($password, $user['password_hash'])) {
                        // Successful login - reset failed attempts
                        $updateStmt = $db->prepare("
                            UPDATE users 
                            SET last_login = NOW(), 
                                failed_attempts = 0,
                                last_login_ip = ?,
                                login_attempts = login_attempts + 1
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
                        
                        // Store user data in session
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['user_type'] = $user['user_type'];
                        $_SESSION['last_login'] = $user['last_login'];
                        $_SESSION['login_type'] = 'admin';
                        
                        // Log login activity
                        $logStmt = $db->prepare("
                            INSERT INTO user_logs (user_id, action, description, ip_address)
                            VALUES (?, ?, ?, ?)
                        ");
                        $logStmt->execute([
                            $user['id'],
                            'login',
                            'User logged in successfully',
                            $_SERVER['REMOTE_ADDR']
                        ]);
                        
                        // Redirect to dashboard
                        header('Location: dashboard.php');
                        exit();
                        
                    } else {
                        // Invalid password - increment failed attempts
                        $updateStmt = $db->prepare("
                            UPDATE users 
                            SET failed_attempts = failed_attempts + 1,
                                last_login_ip = ?
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
                        
                        $remainingAttempts = 5 - ($user['failed_attempts'] + 1);
                        if ($remainingAttempts > 0) {
                            $error = "Invalid password. {$remainingAttempts} attempt(s) remaining.";
                        } else {
                            $error = "Account locked due to too many failed attempts.";
                        }
                    }
                } else {
                    $error = 'Invalid username or password';
                }
                
            } elseif ($login_type === 'approver') {
                // Approver Login
                $stmt = $db->prepare("
                    SELECT id, name, email, password, role, level, status
                    FROM approvers 
                    WHERE (email = ? OR name = ?) AND status = 'active'
                ");
                $stmt->execute([$username, $username]);
                $approver = $stmt->fetch();
                
                if ($approver) {
                    // Verify password
                    if (password_verify($password, $approver['password'])) {
                        // Successful login
                        $_SESSION['approver_id'] = $approver['id'];
                        $_SESSION['approver_name'] = $approver['name'];
                        $_SESSION['approver_email'] = $approver['email'];
                        $_SESSION['approver_role'] = $approver['role'];
                        $_SESSION['approver_level'] = $approver['level'];
                        $_SESSION['login_type'] = 'approver';
                        
                        // Update last login
                        $updateStmt = $db->prepare("
                            UPDATE approvers 
                            SET last_login = NOW(),
                                last_login_ip = ?
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$_SERVER['REMOTE_ADDR'], $approver['id']]);
                        
                        // Log login activity
                        $logStmt = $db->prepare("
                            INSERT INTO approver_logs (approver_id, action, ip_address)
                            VALUES (?, 'login', ?)
                        ");
                        $logStmt->execute([$approver['id'], $_SERVER['REMOTE_ADDR']]);
                        
                        // Redirect to approver dashboard
                        header('Location: dashboard.php');
                        exit();
                        
                    } else {
                        $error = 'Invalid password. Please try again.';
                    }
                } else {
                    $error = 'Approver not found or account is inactive.';
                }
            }
            
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again later.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

















?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Serendib Green Investment System</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(34, 197, 94, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 255, 136, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(16, 185, 129, 0.1) 0%, transparent 50%);
            z-index: -1;
        }
        
        .login-container {
            max-width: 450px;
            width: 100%;
            margin: 0 auto;
        }
        
        .login-card {
            background: rgba(10, 47, 29, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--neon), transparent);
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(34, 197, 94, 0.4);
        }
        
        .logo-icon i {
            font-size: 36px;
            color: white;
        }
        
        .logo-text h1 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-align: left;
            line-height: 1.2;
        }
        
        .logo-text .tagline {
            font-size: 16px;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .login-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            text-align: center;
            margin-bottom: 10px;
        }
        
        .login-subtitle {
            font-size: 16px;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 35px;
        }
        
        .login-type-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: rgba(26, 77, 51, 0.5);
            border-radius: 12px;
            padding: 5px;
        }
        
        .login-type-btn {
            flex: 1;
            padding: 15px;
            background: transparent;
            border: none;
            border-radius: 10px;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .login-type-btn.active {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            box-shadow: 0 5px 15px rgba(34, 197, 94, 0.3);
        }
        
        .login-type-btn:hover:not(.active) {
            background: rgba(34, 197, 94, 0.1);
            color: var(--text);
        }
        
        .login-type-btn i {
            font-size: 18px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.5s ease;
        }
        
        .alert.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            border: 1px solid var(--error);
            border-left: 5px solid var(--error);
        }
        
        .alert.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.1));
            border: 1px solid var(--success);
            border-left: 5px solid var(--success);
        }
        
        .alert i {
            font-size: 20px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--text);
            font-size: 15px;
        }
        
        .input-container {
            position: relative;
        }
        
        .input-container i {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent);
            font-size: 18px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 18px 20px 18px 55px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            font-size: 16px;
            color: var(--text);
            transition: all 0.3s ease;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
            background: rgba(26, 77, 51, 0.9);
        }
        
        .input-highlight {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--neon);
            transition: width 0.3s ease;
            box-shadow: 0 0 10px var(--neon);
        }
        
        input:focus ~ .input-highlight {
            width: 100%;
        }
        
        .show-password {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 18px;
            transition: color 0.3s ease;
        }
        
        .show-password:hover {
            color: var(--accent);
        }
        
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 10px;
            position: relative;
            overflow: hidden;
        }
        
        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .login-btn:hover::before {
            left: 100%;
        }
        
        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(34, 197, 94, 0.5);
        }
        
        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .additional-options {
            margin-top: 25px;
            text-align: center;
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .additional-options a {
            color: var(--accent);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .additional-options a:hover {
            color: var(--neon);
            text-decoration: underline;
        }
        
        .security-info {
            margin-top: 30px;
            padding: 15px;
            background: rgba(26, 77, 51, 0.3);
            border-radius: 10px;
            border: 1px solid rgba(34, 197, 94, 0.2);
            font-size: 13px;
            color: var(--text-muted);
        }
        
        .security-info i {
            color: var(--accent);
            margin-right: 8px;
        }
        
        .floating-elements {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .floating-element {
            position: absolute;
            width: 10px;
            height: 10px;
            background: var(--neon);
            border-radius: 50%;
            opacity: 0.5;
            filter: blur(1px);
            animation: float 15s infinite linear;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes float {
            0% { transform: translateY(0) translateX(0); }
            25% { transform: translateY(-20px) translateX(10px); }
            50% { transform: translateY(0) translateX(20px); }
            75% { transform: translateY(20px) translateX(10px); }
            100% { transform: translateY(0) translateX(0); }
        }
        
        @media (max-width: 576px) {
            .login-card {
                padding: 40px 25px;
            }
            
            .logo {
                flex-direction: column;
                gap: 10px;
            }
            
            .logo-text h1 {
                text-align: center;
                font-size: 32px;
            }
            
            .login-title {
                font-size: 24px;
            }
        }
        
        .powered-by {
            text-align: center;
            margin-top: 25px;
            font-size: 12px;
            color: var(--text-muted);
            opacity: 0.7;
        }
        
        .login-info {
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(34, 197, 94, 0.1);
            border-radius: 10px;
            border: 1px solid rgba(34, 197, 94, 0.3);
            font-size: 14px;
            color: var(--text);
            text-align: center;
        }
        
        .login-info i {
            color: var(--accent);
            margin-right: 8px;
        }
        
        .level-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-left: 8px;
        }
        
        .level-1 {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }
        
        .level-2 {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .level-3 {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
    </style>
</head>
<body>
    <div class="floating-elements" id="floatingElements"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="logo-section">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <div class="logo-text">
                        <h1>SERENDIB</h1>
                        <div class="tagline">Green Investment System</div>
                    </div>
                </div>
                <h2 class="login-title">Welcome Back</h2>
                <p class="login-subtitle">Sign in to access your account</p>
            </div>
            
            <div class="login-type-selector">
                <button type="button" class="login-type-btn <?php echo $login_type === 'admin' ? 'active' : ''; ?>" onclick="setLoginType('admin')">
                    <i class="fas fa-users-cog"></i> Admin Login
                </button>
                <button type="button" class="login-type-btn <?php echo $login_type === 'approver' ? 'active' : ''; ?>" onclick="setLoginType('approver')">
                    <i class="fas fa-user-check"></i> Approver Login
                </button>
            </div>
            
            <div class="login-info">
                <i class="fas fa-info-circle"></i>
                <span id="loginInfoText">
                    <?php echo $login_type === 'admin' ? 'Login as System Administrator or Staff Member' : 'Login as Application Approver'; ?>
                </span>
            </div>
            
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="login_type" id="loginType" value="<?php echo $login_type; ?>">
                
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> 
                        <span id="usernameLabel">
                            <?php echo $login_type === 'admin' ? 'Username' : 'Username or Email'; ?>
                        </span>
                    </label>
                    <div class="input-container">
                        <i class="fas fa-user"></i>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               placeholder="<?php echo $login_type === 'admin' ? 'Enter your username' : 'Enter your username or email'; ?>" 
                               required 
                               autofocus
                               autocomplete="username">
                        <div class="input-highlight"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <div class="input-container">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password" 
                               required
                               autocomplete="current-password">
                        <button type="button" class="show-password" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                        <div class="input-highlight"></div>
                    </div>
                </div>
                
                <button type="submit" class="login-btn" id="loginButton">
                    <i class="fas fa-sign-in-alt"></i>
                    <span id="loginButtonText">
                        <?php echo $login_type === 'admin' ? 'Sign In to Dashboard' : 'Sign In as Approver'; ?>
                    </span>
                </button>
            </form>
            
            <div class="additional-options">
                <a href="forgot-password.php" class="forgot-password">
                    <i class="fas fa-key"></i> Forgot Password?
                </a>
                <span style="margin: 0 10px;">•</span>
                <a href="contact.php" class="contact-support">
                    <i class="fas fa-headset"></i> Need Help?
                </a>
            </div>
            
            <div class="security-info">
                <p><i class="fas fa-shield-alt"></i> Secure login with password hashing and IP tracking</p>
                <p><i class="fas fa-lock"></i> Account lock after 5 failed attempts</p>
                <p><i class="fas fa-history"></i> All login activity is logged for security</p>
            </div>
            
            <div class="powered-by">
                <p>© <?php echo date('Y'); ?> Serendib Group of Companies. All rights reserved.</p>
            </div>
        </div>
    </div>

    <script>
        // Function to set login type
        function setLoginType(type) {
            document.getElementById('loginType').value = type;
            
            // Update active button
            document.querySelectorAll('.login-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update labels and text
            if (type === 'admin') {
                document.getElementById('usernameLabel').textContent = 'Username';
                document.getElementById('username').placeholder = 'Enter your username';
                document.getElementById('loginButtonText').textContent = 'Sign In to Dashboard';
                document.getElementById('loginInfoText').textContent = 'Login as System Administrator or Staff Member';
            } else {
                document.getElementById('usernameLabel').textContent = 'Username or Email';
                document.getElementById('username').placeholder = 'Enter your username or email';
                document.getElementById('loginButtonText').textContent = 'Sign In as Approver';
                document.getElementById('loginInfoText').textContent = 'Login as Application Approver';
            }
        }
        
        // Create floating elements
        function createFloatingElements() {
            const container = document.getElementById('floatingElements');
            if (!container) return;
            
            for (let i = 0; i < 15; i++) {
                const element = document.createElement('div');
                element.className = 'floating-element';
                element.style.top = `${Math.random() * 100}%`;
                element.style.left = `${Math.random() * 100}%`;
                element.style.animationDelay = `${Math.random() * 10}s`;
                element.style.animationDuration = `${15 + Math.random() * 15}s`;
                container.appendChild(element);
            }
        }
        
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Form submission handling
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('loginButton');
            const originalText = submitBtn.innerHTML;
            const loginType = document.getElementById('loginType').value;
            
            // Show loading state
            if (loginType === 'admin') {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
            } else {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating Approver...';
            }
            submitBtn.disabled = true;
            
            // Auto re-enable after 5 seconds in case of error
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            if (!usernameField.value) {
                usernameField.focus();
            }
            
            createFloatingElements();
            
            // Add input focus effects
            const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.querySelector('.input-highlight').style.width = '100%';
                });
                
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        this.parentElement.querySelector('.input-highlight').style.width = '0';
                    }
                });
            });
        });




    </script>
</body>
</html>