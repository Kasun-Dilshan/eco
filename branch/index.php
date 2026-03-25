<?php
require_once '../config.php';
require_once '../db.php';
require_once '../admin/includes/User.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] === USER_ADMIN) {
    header('Location: ../admin/login.php');
    exit();
}

$user = new User($_SESSION['user_id']);

// Check if user has access to branch dashboard
if (!$user->hasPermission('view_applications')) {
    die('Access denied. You need permission to view applications.');
}

// Get user statistics
try {
    $stmt = $GLOBALS['db']->prepare("
        SELECT 
            COUNT(*) as total_applications,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_applications,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_applications,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_applications
        FROM investors 
        WHERE assigned_to = ? OR branch_name = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['branch_name'] ?? '']);
    $stats = $stmt->fetch();
    
    // Get recent applications
    $stmt = $GLOBALS['db']->prepare("
        SELECT i.*, 
               DATE_FORMAT(i.created_at, '%Y-%m-%d %H:%i') as formatted_created,
               u.full_name as created_by_name
        FROM investors i
        LEFT JOIN users u ON i.assigned_to = u.id
        WHERE i.assigned_to = ? OR i.branch_name = ?
        ORDER BY i.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['branch_name'] ?? '']);
    $recent_applications = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Dashboard | EcoWealth Finance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Similar styles to admin dashboard but simplified */
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: #f0fdf4;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
        }
        
        .branch-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .header h1 {
            background: linear-gradient(90deg, #f0fdf4, #00ff88);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-size: 28px;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #22c55e, #00ff88);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: rgba(34, 197, 94, 0.4);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: #00ff88;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #a7f3d0;
            font-size: 16px;
        }
        
        .recent-applications {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #22c55e;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .applications-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .applications-table th {
            text-align: left;
            padding: 12px 15px;
            background: rgba(26, 77, 51, 0.5);
            color: #a7f3d0;
            font-weight: 600;
        }
        
        .applications-table td {
            padding: 12px 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
        }
        
        .applications-table tr:hover {
            background: rgba(34, 197, 94, 0.05);
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge.pending { background: #f59e0b; color: white; }
        .badge.approved { background: #10b981; color: white; }
        .badge.rejected { background: #ef4444; color: white; }
        
        .btn {
            padding: 8px 16px;
            background: #22c55e;
            color: white;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background: #16a34a;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: #22c55e;
        }
        
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid rgba(34, 197, 94, 0.2);
            color: #a7f3d0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="branch-container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-leaf"></i> EcoWealth Branch Portal</h1>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong><br>
                    <small><?php echo htmlspecialchars($_SESSION['branch_name'] ?? 'No Branch'); ?></small>
                </div>
                <a href="../admin/logout.php" class="btn btn-secondary">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_applications']; ?></div>
                <div class="stat-label">Total Applications</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['pending_applications']; ?></div>
                <div class="stat-label">Pending Review</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['approved_applications']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['rejected_applications']; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions" style="margin-bottom: 30px;">
            <a href="applications.php" class="btn">
                <i class="fas fa-file-alt"></i> View All Applications
            </a>
            <?php if ($user->hasPermission('export_applications')): ?>
            <a href="#" class="btn btn-secondary">
                <i class="fas fa-download"></i> Export Reports
            </a>
            <?php endif; ?>
            <?php if ($user->hasPermission('send_emails')): ?>
            <a href="../admin/send_email.php" class="btn btn-secondary">
                <i class="fas fa-envelope"></i> Send Email
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Recent Applications -->
        <div class="recent-applications">
            <div class="section-title">Recent Applications</div>
            
            <?php if (empty($recent_applications)): ?>
                <p style="text-align: center; color: #a7f3d0; padding: 40px;">
                    <i class="fas fa-inbox" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px; display: block;"></i>
                    No applications found
                </p>
            <?php else: ?>
                <table class="applications-table">
                    <thead>
                        <tr>
                            <th>Application ID</th>
                            <th>Investor Name</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_applications as $app): ?>
                        <tr>
                            <td><strong><?php echo str_pad($app['id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($app['full_name']); ?></td>
                            <td><?php echo $app['formatted_created']; ?></td>
                            <td>
                                <span class="badge <?php echo $app['status']; ?>">
                                    <?php echo ucfirst($app['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="view_application.php?id=<?php echo $app['id']; ?>" class="btn" style="padding: 6px 12px; font-size: 12px;">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>© <?php echo date('Y'); ?> EcoWealth Finance. Branch Portal v1.0</p>
            <p>Logged in as: <?php echo htmlspecialchars($_SESSION['username']); ?> | 
               User Type: <?php echo ucfirst(str_replace('_', ' ', $_SESSION['user_type'])); ?> | 
               IP: <?php echo $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; ?></p>
        </div>
    </div>
    
    <script>
        // Auto-refresh every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>