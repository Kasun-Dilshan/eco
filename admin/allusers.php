<?php
session_start();
require_once '../config.php';
require_once '../db.php';



// Get users data
$users = [];
$approvers = [];

try {
    // Get all users
    $stmt = $db->prepare("SELECT * FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // Get all approvers
    $stmt = $db->prepare("SELECT * FROM approvers ORDER BY level, created_at DESC");
    $stmt->execute();
    $approvers = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users & Approvers Details | Serendib Green Plantation Admin</title>
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
            --hover-bg: rgba(34, 197, 94, 0.1);
            --border: rgba(34, 197, 94, 0.2);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(34, 197, 94, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 255, 136, 0.08) 0%, transparent 50%);
            z-index: -1;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            padding: 40px 20px;
            margin-bottom: 40px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: var(--neon);
            margin-bottom: 15px;
            display: inline-block;
            text-shadow: 0 0 15px var(--accent-glow);
        }
        
        h1 {
            font-size: 42px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 15px;
        }
        
        .subtitle {
            font-size: 18px;
            color: var(--text-muted);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .back-btn {
            position: absolute;
            top: 30px;
            left: 30px;
            background: var(--card-bg);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 20px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: var(--hover-bg);
            border-color: var(--accent);
            transform: translateX(-5px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 24px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: var(--neon);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--neon);
            margin: 40px 0 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .section-title i {
            color: var(--accent);
        }
        
        .tables-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 40px;
        }
        
        @media (min-width: 1200px) {
            .tables-container {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 15px;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .table-header {
            padding: 20px;
            background: rgba(34, 197, 94, 0.1);
            border-bottom: 1px solid var(--border);
        }
        
        .table-header h3 {
            color: var(--text);
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: rgba(26, 77, 51, 0.5);
        }
        
        th {
            padding: 15px;
            text-align: left;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
        }
        
        tr:hover {
            background: var(--hover-bg);
        }
        
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge.active {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }
        
        .badge.inactive {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }
        
        .badge.suspended {
            background: linear-gradient(135deg, var(--error), #dc2626);
            color: white;
        }
        
        .badge.admin {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
        }
        
        .badge.branch_manager {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }
        
        .badge.staff {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .badge.approver {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 60px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--text);
        }
        
        .user-email {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .last-login {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        @media (max-width: 768px) {
            h1 {
                font-size: 32px;
            }
            
            .back-btn {
                position: relative;
                top: 0;
                left: 0;
                margin-bottom: 20px;
            }
            
            .header {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            th, td {
                padding: 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <div class="header">
            <div class="logo">Serendib Green Plantation</div>
            <h1>Users & Approvers Details</h1>
            <p class="subtitle">View all system users and approvers information</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid var(--error); border-radius: 10px; padding: 20px; margin-bottom: 20px; color: var(--text);">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo count($users); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?php echo count($approvers); ?></div>
                <div class="stat-label">Total Approvers</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="stat-number">
                    <?php 
                    $adminCount = 0;
                    foreach ($users as $user) {
                        if ($user['user_type'] == 'admin') $adminCount++;
                    }
                    echo $adminCount;
                    ?>
                </div>
                <div class="stat-label">Administrators</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-number">
                    <?php 
                    $branchManagers = 0;
                    foreach ($users as $user) {
                        if ($user['user_type'] == 'branch_manager') $branchManagers++;
                    }
                    echo $branchManagers;
                    ?>
                </div>
                <div class="stat-label">Branch Managers</div>
            </div>
        </div>
        
        <div class="tables-container">
            <!-- Users Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3><i class="fas fa-users"></i> System Users (<?php echo count($users); ?>)</h3>
                </div>
                <div class="table-wrapper">
                    <?php if (!empty($users)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php 
                                    $firstName = explode(' ', $user['full_name'])[0];
                                    $lastName = explode(' ', $user['full_name'])[count(explode(' ', $user['full_name'])) - 1];
                                    $initials = substr($firstName, 0, 1) . substr($lastName, 0, 1);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar"><?php echo strtoupper($initials); ?></div>
                                                <div class="user-details">
                                                    <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                    <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                                    <div class="last-login">ID: <?php echo $user['id']; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $user['user_type']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $user['user_type'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['branch_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge <?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <?php 
                                                $lastLogin = new DateTime($user['last_login']);
                                                echo $lastLogin->format('M d, Y') . '<br>';
                                                echo '<small>' . $lastLogin->format('h:i A') . '</small>';
                                                ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">Never</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <h3>No Users Found</h3>
                            <p>There are no users registered in the system yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Approvers Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3><i class="fas fa-user-check"></i> Approvers (<?php echo count($approvers); ?>)</h3>
                </div>
                <div class="table-wrapper">
                    <?php if (!empty($approvers)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Approver</th>
                                    <th>Level</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approvers as $approver): ?>
                                    <?php 
                                    $firstName = explode(' ', $approver['name'])[0];
                                    $lastName = explode(' ', $approver['name'])[count(explode(' ', $approver['name'])) - 1];
                                    $initials = substr($firstName, 0, 1) . substr($lastName, 0, 1);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                                    <?php echo strtoupper($initials); ?>
                                                </div>
                                                <div class="user-details">
                                                    <div class="user-name"><?php echo htmlspecialchars($approver['name']); ?></div>
                                                    <div class="user-email"><?php echo htmlspecialchars($approver['email']); ?></div>
                                                    <div class="last-login">ID: <?php echo $approver['id']; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <span class="badge approver" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">
                                                    <?php echo $approver['level']; ?>
                                                </span>
                                                <span>
                                                    <?php 
                                                    if ($approver['level'] == 1) echo 'First';
                                                    elseif ($approver['level'] == 2) echo 'Second';
                                                    elseif ($approver['level'] == 3) echo 'Final';
                                                    else echo 'Level ' . $approver['level'];
                                                    ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $roleName = str_replace('_', ' ', $approver['role']);
                                            echo ucwords($roleName);
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $approver['status']; ?>">
                                                <?php echo ucfirst($approver['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $created = new DateTime($approver['created_at']);
                                            echo $created->format('M d, Y') . '<br>';
                                            echo '<small>' . $created->format('h:i A') . '</small>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-times"></i>
                            <h3>No Approvers Found</h3>
                            <p>There are no approvers configured in the system yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Additional Information -->
        <div class="section-title">
            <i class="fas fa-info-circle"></i>
            <span>Summary Information</span>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 15px; padding: 20px;">
                <h4 style="color: var(--neon); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-users-cog"></i> User Types Distribution
                </h4>
                <?php 
                $userTypes = [];
                foreach ($users as $user) {
                    $type = $user['user_type'];
                    $userTypes[$type] = isset($userTypes[$type]) ? $userTypes[$type] + 1 : 1;
                }
                
                foreach ($userTypes as $type => $count):
                    $percentage = count($users) > 0 ? round(($count / count($users)) * 100) : 0;
                ?>
                <div style="margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span style="color: var(--text); font-weight: 600;">
                            <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                        </span>
                        <span style="color: var(--text-muted);"><?php echo $count; ?> users (<?php echo $percentage; ?>%)</span>
                    </div>
                    <div style="height: 8px; background: rgba(26, 77, 51, 0.5); border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: <?php echo $percentage; ?>%; background: linear-gradient(90deg, var(--accent), var(--neon)); border-radius: 4px;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 15px; padding: 20px;">
                <h4 style="color: var(--neon); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-user-shield"></i> Approvers by Level
                </h4>
                <?php 
                $approverLevels = [];
                foreach ($approvers as $approver) {
                    $level = $approver['level'];
                    $approverLevels[$level] = isset($approverLevels[$level]) ? $approverLevels[$level] + 1 : 1;
                }
                
                ksort($approverLevels);
                
                foreach ($approverLevels as $level => $count):
                ?>
                <div style="margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span style="color: var(--text); font-weight: 600;">
                            Level <?php echo $level; ?> Approvers
                        </span>
                        <span style="color: var(--text-muted);"><?php echo $count; ?> approver(s)</span>
                    </div>
                    <div style="height: 8px; background: rgba(26, 77, 51, 0.5); border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: <?php echo $count * 30; ?>%; background: linear-gradient(90deg, #f59e0b, #d97706); border-radius: 4px;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($approverLevels)): ?>
                <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                    <i class="fas fa-chart-line" style="font-size: 40px; opacity: 0.5;"></i>
                    <p>No approver data available</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>