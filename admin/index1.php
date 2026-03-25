<?php
require_once '../config.php';
require_once '../db.php';
require_once 'includes/User.php';



// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        $user = new User();
        $result = $user->login($username, $password);
        
        if ($result['success']) {
            header('Location: dashboard.php');
            exit();
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | EcoWealth Finance</title>
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
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
        }
        
        .login-card {
            background: rgba(10, 47, 29, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        
        .logo {
            color: var(--neon);
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            display: block;
        }
        
        .logo i {
            margin-right: 10px;
        }
        
        .subtitle {
            color: var(--text-muted);
            margin-bottom: 30px;
            font-size: 16px;
        }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .alert.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--error);
            color: var(--text);
        }
        
        .alert.success {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid var(--success);
            color: var(--text);
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
        }
        
        .form-group input {
            width: 100%;
            padding: 14px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            font-size: 16px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn:hover {
            background: #16a34a;
            transform: translateY(-2px);
        }
        
        .forgot-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
        }
        
        .forgot-link:hover {
            color: var(--accent);
        }
        
        .floating-elements {
            position: fixed;
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
        
        @keyframes float {
            0% { transform: translateY(0) translateX(0); }
            25% { transform: translateY(-20px) translateX(10px); }
            50% { transform: translateY(0) translateX(20px); }
            75% { transform: translateY(20px) translateX(10px); }
            100% { transform: translateY(0) translateX(0); }
        }
    </style>
</head>
<body>
    <div class="floating-elements">
        <div class="floating-element" style="top: 10%; left: 5%; animation-delay: 0s;"></div>
        <div class="floating-element" style="top: 20%; left: 90%; animation-delay: 1s;"></div>
        <div class="floating-element" style="top: 50%; left: 15%; animation-delay: 2s;"></div>
        <div class="floating-element" style="top: 80%; left: 80%; animation-delay: 3s;"></div>
    </div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <i class="fas fa-leaf"></i> ECO WEALTH FINANCE
            </div>
            <p class="subtitle">Admin Portal - Secure Login</p>
            
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username" 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           placeholder="Enter username or email"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" 
                           placeholder="Enter your password"
                           required>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
            
            <a href="forgot_password.php" class="forgot-link">
                <i class="fas fa-key"></i> Forgot Password?
            </a>
        </div>
    </div>
    
    <script>
        // Add floating elements
        function createFloatingElements() {
            const container = document.querySelector('.floating-elements');
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
        
        createFloatingElements();
        
        // Auto-focus username field
        document.getElementById('username').focus();
    </script>
</body>
</html>