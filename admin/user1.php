<?php
require_once '../config.php';
require_once '../db.php';

session_start();



// Get user ID from query parameter
$userId = $_GET['id'] ?? 0;
if (!$userId) {
    header('Location: users.php');
    exit();
}

// Get user details
try {
    $stmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created,
               DATE_FORMAT(updated_at, '%M %d, %Y %H:%i') as formatted_updated,
               DATE_FORMAT(last_login, '%M %d, %Y %H:%i') as formatted_last_login,
               DATE_FORMAT(password_changed_at, '%M %d, %Y %H:%i') as formatted_password_changed,
               DATE_FORMAT(locked_until, '%M %d, %Y %H:%i') as formatted_locked_until
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: users.php');
        exit();
    }
    
    // Get created by user info
    $createdByName = 'System';
    if ($user['created_by']) {
        $createdStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
        $createdStmt->execute([$user['created_by']]);
        $creator = $createdStmt->fetch();
        if ($creator) {
            $createdByName = $creator['full_name'];
        }
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get approvers (if any)
try {
    $approverStmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created,
               DATE_FORMAT(updated_at, '%M %d, %Y %H:%i') as formatted_updated
        FROM approvers 
        WHERE email = ? 
        ORDER BY level ASC
    ");
    $approverStmt->execute([$user['email']]);
    $approvers = $approverStmt->fetchAll();
} catch (PDOException $e) {
    $approvers = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --accent-glow: rgba(34, 197, 94, 0.4);
            --neon: #00ff88;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --info: #3b82f6;
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
            --card-bg: rgba(10, 47, 29, 0.7);
            --border: rgba(34, 197, 94, 0.2);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }
        
        .header h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-back {
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .btn-back:hover {
            background: rgba(34, 197, 94, 0.15);
            transform: translateY(-2px);
        }
        
        .user-profile {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
            font-weight: 700;
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.3);
        }
        
        .profile-info h2 {
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--text);
        }
        
        .user-id {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 15px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
        }
        
        .status-active {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }
        
        .status-inactive {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }
        
        .status-suspended {
            background: linear-gradient(135deg, var(--error), #dc2626);
            color: white;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .info-section {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--neon);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed rgba(34, 197, 94, 0.1);
        }
        
        .info-label {
            color: var(--text-muted);
            font-weight: 600;
        }
        
        .info-value {
            color: var(--text);
            text-align: right;
            max-width: 200px;
            word-break: break-word;
        }
        
        .user-type-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .type-admin {
            background: linear-gradient(135deg, var(--info), #1d4ed8);
            color: white;
        }
        
        .type-manager {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }
        
        .type-staff {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .approvers-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 30px;
            margin-top: 30px;
        }
        
        .approver-card {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .approver-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .approver-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
        }
        
        .level-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
        }
        
        .role-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .no-approvers {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        .no-approvers i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user"></i> User Details</h1>
            <a href="users.php" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>
        
        <div class="user-profile">
            <div class="profile-header">
                <div class="avatar">
                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                    <div class="user-id">User ID: #<?php echo str_pad($user['id'], 6, '0', STR_PAD_LEFT); ?></div>
                    <span class="status-badge <?php echo 'status-' . $user['status']; ?>">
                        <i class="fas fa-circle"></i> <?php echo ucfirst($user['status']); ?>
                    </span>
                </div>
            </div>
            
            <div class="info-grid">
                <!-- Personal Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-user-circle"></i>
                        Personal Information
                    </div>
                    <div class="info-row">
                        <span class="info-label">Username</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['username']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value">
                            <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>" style="color: var(--accent);">
                                <?php echo htmlspecialchars($user['email']); ?>
                            </a>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($user['phone']); ?>" style="color: var(--accent);">
                                <?php echo htmlspecialchars($user['phone']); ?>
                            </a>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Full Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['full_name']); ?></span>
                    </div>
                </div>
                
                <!-- Role & Branch Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-briefcase"></i>
                        Role & Branch
                    </div>
                    <div class="info-row">
                        <span class="info-label">User Type</span>
                        <span class="info-value">
                            <span class="user-type-badge type-<?php echo $user['user_type']; ?>">
                                <?php echo str_replace('_', ' ', ucfirst($user['user_type'])); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Branch Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['branch_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Branch ID</span>
                        <span class="info-value"><?php echo $user['branch_id']; ?></span>
                    </div>
                </div>
                
                <!-- Account Status -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-chart-line"></i>
                        Account Status
                    </div>
                    <div class="info-row">
                        <span class="info-label">Login Attempts</span>
                        <span class="info-value"><?php echo $user['login_attempts']; ?> / <?php echo $user['failed_attempts']; ?> failed</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Login</span>
                        <span class="info-value">
                            <?php echo $user['formatted_last_login'] ?: 'Never'; ?>
                            <?php if($user['last_login_ip']): ?>
                                <br><small>(<?php echo htmlspecialchars($user['last_login_ip']); ?>)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Force Password Change</span>
                        <span class="info-value">
                            <?php echo $user['force_password_change'] ? 
                                '<span style="color: var(--error);"><i class="fas fa-exclamation-triangle"></i> Yes</span>' : 
                                '<span style="color: var(--success);"><i class="fas fa-check"></i> No</span>'; ?>
                        </span>
                    </div>
                    <?php if($user['locked_until']): ?>
                    <div class="info-row">
                        <span class="info-label">Locked Until</span>
                        <span class="info-value" style="color: var(--warning);">
                            <i class="fas fa-lock"></i> <?php echo $user['formatted_locked_until']; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Account Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i>
                        Account Information
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created By</span>
                        <span class="info-value"><?php echo htmlspecialchars($createdByName); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created At</span>
                        <span class="info-value"><?php echo $user['formatted_created']; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Updated</span>
                        <span class="info-value"><?php echo $user['formatted_updated']; ?></span>
                    </div>
                    <?php if($user['password_changed_at']): ?>
                    <div class="info-row">
                        <span class="info-label">Password Changed</span>
                        <span class="info-value"><?php echo $user['formatted_password_changed']; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
       
    </div>
</body>
</html>