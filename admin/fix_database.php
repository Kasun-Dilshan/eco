<?php
require_once '../config.php';
require_once '../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Add missing columns
if (isset($_POST['fix_database'])) {
    try {
        $db->beginTransaction();
        
        // Add reviewed_by column if not exists
        $sql = "SHOW COLUMNS FROM investors LIKE 'reviewed_by'";
        $result = $db->query($sql);
        if ($result->rowCount() == 0) {
            $db->exec("ALTER TABLE investors ADD COLUMN reviewed_by INT NULL AFTER admin_notes");
            $success .= "Added reviewed_by column.<br>";
        }
        
        // Add reviewed_at column if not exists
        $sql = "SHOW COLUMNS FROM investors LIKE 'reviewed_at'";
        $result = $db->query($sql);
        if ($result->rowCount() == 0) {
            $db->exec("ALTER TABLE investors ADD COLUMN reviewed_at TIMESTAMP NULL AFTER reviewed_by");
            $success .= "Added reviewed_at column.<br>";
        }
        
        // Check if admin_users table exists
        $sql = "SHOW TABLES LIKE 'admin_users'";
        $result = $db->query($sql);
        if ($result->rowCount() == 0) {
            // Create admin_users table
            $sql = "
                CREATE TABLE admin_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    full_name VARCHAR(100) NOT NULL,
                    role ENUM('super_admin', 'admin', 'staff') DEFAULT 'staff',
                    last_login TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ";
            $db->exec($sql);
            $success .= "Created admin_users table.<br>";
            
            // Insert default admin user
            $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $db->prepare("
                INSERT INTO admin_users (username, password_hash, email, full_name, role) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute(['admin', $password_hash, 'admin@ecowealth.com', 'System Administrator', 'super_admin']);
            $success .= "Added default admin user (admin/admin123).<br>";
        }
        
        $db->commit();
        $success .= "Database fixed successfully!";
        
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Database</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .fix-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 500px;
        }
        
        h2 {
            color: #0a2f1d;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: #0a2f1d;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        
        .btn:hover {
            background: #064e3b;
        }
        
        .info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            font-size: 14px;
            color: #004085;
            border: 1px solid #b8daff;
        }
        
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #0a2f1d;
            text-decoration: none;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="fix-box">
        <h2>Fix Database Structure</h2>
        
        <?php if ($success): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="info">
            <strong>This will:</strong>
            <ol style="margin-left: 20px; margin-top: 10px;">
                <li>Add missing columns to investors table</li>
                <li>Create admin_users table if not exists</li>
                <li>Add default admin user (admin/admin123)</li>
                <li>Fix all query errors</li>
            </ol>
        </div>
        
        <form method="POST" action="">
            <button type="submit" name="fix_database" class="btn">Fix Database Now</button>
        </form>
        
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>