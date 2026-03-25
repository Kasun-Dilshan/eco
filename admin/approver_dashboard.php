<?php
session_start();
require_once '../config.php';
require_once '../db.php';

// Check if approver is logged in
if (!isset($_SESSION['approver_id'])) {
    header('Location: approver_login.php');
    exit();
}

// Get approver information
$approverId = $_SESSION['approver_id'];
$approverName = $_SESSION['approver_name'] ?? 'Approver';
$approverRole = $_SESSION['approver_role'] ?? 'first_approver';
$approverLevel = $_SESSION['approver_level'] ?? 1;

// Get pending applications for this approver level
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as pending_count 
        FROM investors 
        WHERE approver_level = ? 
        AND status = 'pending'
    ");
    $stmt->execute([$approverLevel]);
    $pendingResult = $stmt->fetch();
    $pendingCount = $pendingResult['pending_count'] ?? 0;



    $stmt = $db->prepare("
        SELECT COUNT(*) as in_progress_count 
        FROM investors 
        WHERE approver_level = ? 
        AND status = 'in_progress'
    ");
    $stmt->execute([$approverLevel]);
    $pendingResult = $stmt->fetch();
    $pendingCount = $pendingResult['in_progress_count'] ?? 0;

    
    
    // Get approved applications by this approver
    $stmt = $db->prepare("
        SELECT COUNT(*) as approved_count 
        FROM application_logs 
        WHERE performed_by = ? 
        AND action = 'status_updated' 
        AND description LIKE '%Approved%'
    ");
    $stmt->execute([$approverName]);
    $approvedResult = $stmt->fetch();
    $approvedCount = $approvedResult['approved_count'] ?? 0;
    
    // Get recent applications for this level
    $stmt = $db->prepare("
        SELECT i.id, i.full_name, i.nic_no, i.investment_type, 
               DATE_FORMAT(i.created_at, '%M %d, %Y') as created_date,
               COUNT(b.id) as beneficiary_count
        FROM investors i
        LEFT JOIN beneficiaries b ON i.id = b.investor_id
        WHERE i.approver_level = ? 
        AND i.status = 'pending,in_progress_count'
        GROUP BY i.id
        ORDER BY i.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$approverLevel]);
    $recentApplications = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $pendingCount = 0;
    $approvedCount = 0;
    $recentApplications = [];
}

// Function to get greeting
function getGreeting() {
    $hour = date('H');
    if ($hour < 12) {
        return "Good Morning";
    } elseif ($hour < 17) {
        return "Good Afternoon";
    } else {
        return "Good Evening";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approver Dashboard | Serendib Green Investment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --neon: #00ff88;
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
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .header-left h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 5px;
        }
        
        .header-left .role {
            color: var(--text-muted);
            font-size: 16px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .approver-info {
            text-align: right;
        }
        
        .approver-name {
            font-weight: 700;
            font-size: 18px;
        }
        
        .approver-level {
            color: var(--neon);
            font-weight: 600;
            font-size: 14px;
        }
        
        .logout-btn {
            background: rgba(239, 68, 68, 0.2);
            color: white;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        /* Welcome Section */
        .welcome-section {
            background: rgba(10, 47, 29, 0.8);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 40px;
        }
        
        .greeting {
            font-size: 32px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 10px;
        }
        
        .welcome-message {
            font-size: 18px;
            color: var(--text-muted);
            max-width: 600px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 18px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
        }
        
        .stat-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
            color: white;
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 16px;
            color: var(--text-muted);
        }
        
        /* Recent Applications */
        .applications-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 20px;
            padding: 40px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
        }
        
        .view-all {
            color: var(--accent);
            text-decoration: none;
        }
        
        .applications-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .applications-table th {
            text-align: left;
            padding: 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            color: var(--text-muted);
            font-weight: 600;
        }
        
        .applications-table td {
            padding: 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
        }
        
        .applications-table tr:hover {
            background: rgba(34, 197, 94, 0.05);
        }
        
        .action-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            background: #16a34a;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .header-right {
                flex-direction: column;
                text-align: center;
            }
            
            .applications-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-let">
                <h1>Approver Dashbofard</h1>
                <div class="role">Level <?php echo $approverLevel; ?> Approver</div>
            </div>
            <div class="header-right">
                <div class="approver-info">
                    <div class="approver-name"><?php echo htmlspecialchars($approverName); ?></div>
                    <div class="approver-level"><?php echo ucfirst(str_replace('_', ' ', $approverRole)); ?></div>
                </div>
                <a href="approver_logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
        
        <!-- Welcome Section -->
        <div class="welcome-section">
            <div class="greeting">
                <?php echo getGreeting(); ?>, <?php echo htmlspecialchars(explode(' ', $approverName)[0]); ?>!
            </div>
            <div class="welcome-message">
                Welcome to the approver portal. You are currently at Level <?php echo $approverLevel; ?> 
                of the approval process. Here you can review and process investment applications.
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $pendingCount; ?></div>
                <div class="stat-label">Pending Applications</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $approvedCount; ?></div>
                <div class="stat-label">Applications Approved</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="stat-value">Level <?php echo $approverLevel; ?></div>
                <div class="stat-label">Your Approval Level</div>
            </div>
        </div>
        
        <!-- Recent Applications -->
        <div class="applications-section">
            <div class="section-header">
                <div class="section-title">Pending Applications (Level <?php echo $approverLevel; ?>)</div>
                <a href="view_application.php" class="view-all">View All →</a>
            </div>
            
            <?php if (empty($recentApplications)): ?>
                <div class="no-data">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <h3>No Pending Applications</h3>
                    <p>There are no applications waiting for your approval at this level.</p>
                </div>
            <?php else: ?>
                <table class="applications-table">
                    <thead>
                        <tr>
                            <th>Application ID</th>
                            <th>Investor Name</th>
                            <th>NIC No</th>
                            <th>Investment Type</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $investmentTypeNames = [
                            'HPP' => 'High profit plan',
                            'GSP' => 'Green saving plan',
                            'GSI' => 'Green silver plan',
                            'GOLD' => 'Gold plan',
                            'SFPS' => 'Seraa farm profit share plan',
                            'SFHPS' => 'Seraa farm high profit share plan'
                        ];
                        foreach ($recentApplications as $application): ?>
                        <tr>
                            <td>SGI-<?php echo str_pad($application['id'], 6, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo htmlspecialchars($application['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($application['nic_no']); ?></td>
                            <td><?php echo htmlspecialchars($investmentTypeNames[$application['investment_type']] ?? ($application['investment_type'] ?? 'N/A')); ?></td>
                            <td><?php echo $application['created_date']; ?></td>
                            <td>
                                <a href="view_application.php?id=<?php echo $application['id']; ?>" class="action-btn">
                                    <i class="fas fa-eye"></i> Review
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <div style="margin-top: 40px; text-align: center;">
            <h3 style="margin-bottom: 20px; color: var(--text-muted);">Quick Actions</h3>
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                <a href="approver_applications.php" style="padding: 12px 25px; background: var(--accent); color: white; border-radius: 8px; text-decoration: none;">
                    <i class="fas fa-list"></i> All Applications
                </a>
                <a href="approver_profile.php" style="padding: 12px 25px; background: rgba(34, 197, 94, 0.2); color: var(--text); border-radius: 8px; text-decoration: none;">
                    <i class="fas fa-user-cog"></i> My Profile
                </a>
                <a href="approver_guidelines.php" style="padding: 12px 25px; background: rgba(34, 197, 94, 0.2); color: var(--text); border-radius: 8px; text-decoration: none;">
                    <i class="fas fa-book"></i> Guidelines
                </a>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh dashboard every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>