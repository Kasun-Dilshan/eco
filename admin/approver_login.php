<?php
session_start();
require_once '../config.php';
require_once '../db.php';

// Redirect to dashboard if already logged in
if (isset($_SESSION['approver_id'])) {
    header('Location:dashboard.php');
    exit();
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate inputs
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            // Check if approver exists
            $stmt = $db->prepare("
                SELECT id, name, email, password, role, level 
                FROM approvers 
                WHERE email = ? OR name = ?
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
                    
                    // Update last login
                    $updateStmt = $db->prepare("
                        UPDATE approvers 
                        SET last_login = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$approver['id']]);
                    
                    // Log login activity
                    $logStmt = $db->prepare("
                        INSERT INTO approver_logs (approver_id, action, ip_address)
                        VALUES (?, 'login', ?)
                    ");
                    $logStmt->execute([$approver['id'], $_SERVER['REMOTE_ADDR']]);
                    
                    // Redirect to approver dashboard
                    header('Location: approver_dashboard.php');
                    exit();
                    
                } else {
                    $error = 'Invalid password. Please try again.';
                }
            } else {
                $error = 'Approver not found. Please check your credentials.';
            }
            
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again later.';
            error_log("Approver login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approver Login | Serendib Green Investment</title>
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
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.3);
        }
        
        .logo-icon i {
            font-size: 30px;
            color: white;
        }
        
        .logo-text h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-align: left;
            line-height: 1.2;
        }
        
        .logo-text .tagline {
            font-size: 14px;
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
        
        .level-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 10px;
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
            text-align: center;
        }
        
        .security-info i {
            color: var(--accent);
            margin-right: 8px;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
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
                font-size: 28px;
            }
            
            .login-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo-section">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="logo-text">
                        <h1>SERENDIB</h1>
                        <div class="tagline">Approver Portal</div>
                    </div>
                </div>
                <h2 class="login-title">Approver Login</h2>
                <p class="login-subtitle">Sign in to review and approve applications</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Username or Email</label>
                    <div class="input-container">
                        <i class="fas fa-user"></i>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               placeholder="Enter your username or email" 
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
                    <span>Sign In as Approver</span>
                </button>
            </form>
            
            <div class="security-info">
                <p><i class="fas fa-shield-alt"></i> Secure approver access only</p>
                <p><i class="fas fa-info-circle"></i> Contact admin if you forgot your credentials</p>
            </div>
        </div>
    </div>

    <script>
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
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
            submitBtn.disabled = true;
            
            // Auto re-enable after 5 seconds in case of error
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        });
        
        // Auto-focus username field if empty
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            if (!usernameField.value) {
                usernameField.focus();
            }
        });
    </script>
</body>
</html>