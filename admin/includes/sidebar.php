<?php
// Common sidebar for all admin pages
$currentUser = $user->getUser($_SESSION['user_id']);
?>
<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-leaf"></i>
            <span>ECO WEALTH ADMIN</span>
        </div>
        <p style="color: var(--text-muted); font-size: 13px;">v1.0.0</p>
    </div>
    
    <div class="user-info">
        <div class="user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
        <div class="user-role"><?php echo ucfirst($currentUser['user_type']); ?></div>
        <?php if ($currentUser['branch_name']): ?>
        <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
            <i class="fas fa-building"></i> <?php echo htmlspecialchars($currentUser['branch_name']); ?>
        </div>
        <?php endif; ?>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="<?php echo $activeNav === 'dashboard' ? 'active' : ''; ?>">
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <div class="nav-label">Applications</div>
            <li class="<?php echo $activeNav === 'applications' ? 'active' : ''; ?>">
                <a href="applications.php">
                    <i class="fas fa-file-alt"></i>
                    <span>All Applications</span>
                </a>
            </li>
            
            <?php if ($user->hasPermission('view_reports') || $_SESSION['user_type'] === 'admin'): ?>
            <li>
                <a href="reports.php">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </li>
            <?php endif; ?>
            
            <?php if ($user->hasPermission('view_users') || $_SESSION['user_type'] === 'admin'): ?>
            <div class="nav-label">User Management</div>
            <li class="<?php echo $activeNav === 'users' ? 'active' : ''; ?>">
                <a href="users.php">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
            </li>
            <li>
                <a href="user_activity.php">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
            </li>
            <?php endif; ?>
            
            <div class="nav-label">Account</div>
            <li>
                <a href="profile.php">
                    <i class="fas fa-user-circle"></i>
                    <span>My Profile</span>
                </a>
            </li>
            <li>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <div style="padding: 20px; margin-top: auto; border-top: 1px solid rgba(34, 197, 94, 0.2);">
        <div style="font-size: 12px; color: var(--text-muted); text-align: center;">
            <p>Last login: <?php echo $currentUser['last_login'] ? date('M d, Y H:i', strtotime($currentUser['last_login'])) : 'Never'; ?></p>
            <p style="margin-top: 5px;">© <?php echo date('Y'); ?> EcoWealth Finance</p>
        </div>
    </div>
</div>