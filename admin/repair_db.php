<?php
require_once '../config.php';
require_once '../db.php';
require_once 'includes/User.php';

// Check if admin is logged in

$user = new User();
$message = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['repair'])) {
        $result = $user->repairDatabase();
        if ($result['success']) {
            $message = $result['message'];
            $type = 'success';
        } else {
            $message = $result['message'];
            $type = 'error';
        }
    }
    
    if (isset($_POST['init'])) {
        $result = $user->initDatabase();
        if ($result) {
            $message = 'Database initialized successfully.';
            $type = 'success';
        } else {
            $message = 'Database initialization failed.';
            $type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Repair | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --error: #ef4444;
            --text: #f0fdf4;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            max-width: 600px;
            width: 100%;
            background: rgba(10, 47, 29, 0.9);
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: var(--accent);
            font-size: 28px;
            margin-bottom: 10px;
        }
        
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
            border: 1px solid #10b981;
        }
        
        .alert.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--error);
        }
        
        .card {
            background: rgba(26, 77, 51, 0.5);
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid rgba(34, 197, 94, 0.1);
        }
        
        .card h3 {
            color: var(--accent);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card p {
            color: rgba(240, 253, 244, 0.8);
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
        }
        
        .btn {
            padding: 12px 24px;
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
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d97706;
        }
        
        .back-link {
            display: block;
            text-align: center;
            margin-top: 30px;
            color: rgba(240, 253, 244, 0.7);
            text-decoration: none;
        }
        
        .back-link:hover {
            color: var(--accent);
        }
        
        .warning-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-database"></i> Database Repair Tool</h1>
            <p>Fix database issues and initialize tables</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert <?php echo $type; ?>">
                <i class="fas fa-<?php echo $type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="warning-box">
            <p><i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Only use this tool if you're experiencing database errors. Back up your data before proceeding.</p>
        </div>
        
        <div class="card">
            <h3><i class="fas fa-tools"></i> Repair Database Tables</h3>
            <p>This will check and repair any missing tables or columns in the user management system.</p>
            <form method="POST" action="">
                <button type="submit" name="repair" class="btn btn-warning">
                    <i class="fas fa-wrench"></i> Repair Database
                </button>
            </form>
        </div>
        
        <div class="card">
            <h3><i class="fas fa-plus-circle"></i> Initialize Database</h3>
            <p>Create all necessary tables for the user management system. Use this if you're setting up for the first time.</p>
            <form method="POST" action="">
                <button type="submit" name="init" class="btn btn-primary">
                    <i class="fas fa-database"></i> Initialize Database
                </button>
            </form>
        </div>
        
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</body>
</html>