<?php
require_once '../config.php';
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || (($_SESSION['user_type'] ?? null) !== 'admin')) {
    header('Location: dashboard.php');
    exit();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Vary: Cookie');

$message = '';
$type = '';
$users = [];
$branches = [];
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_user'])) {
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId && $userId === $currentUserId) {
            $message = 'You cannot delete your own account.';
            $type = 'error';
        } elseif ($userId) {
            try {
                $stmt = $db->prepare('SELECT full_name FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $userInfo = $stmt->fetch();

                if (!$userInfo) {
                    $message = 'User not found.';
                    $type = 'error';
                } else {
                    $deleteStmt = $db->prepare('DELETE FROM users WHERE id = ?');
                    $deleteStmt->execute([$userId]);

                    if ($deleteStmt->rowCount() > 0) {
                        $message = 'User deleted successfully.';
                        $type = 'success';

                        try {
                            $logStmt = $db->prepare('INSERT INTO user_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)');
                            $logStmt->execute([
                                $currentUserId,
                                'user_deleted',
                                'Deleted user: ' . $userInfo['full_name'] . ' (ID: ' . $userId . ')',
                                $_SERVER['REMOTE_ADDR'] ?? '',
                            ]);
                        } catch (PDOException $logError) {
                            error_log('manage_users.php delete log skipped: ' . $logError->getMessage());
                        }
                    } else {
                        $message = 'Failed to delete user.';
                        $type = 'error';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $type = 'error';
            }
        }
    } elseif (isset($_POST['change_status'])) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if ($userId && $userId === $currentUserId && $status === 'suspended') {
            $message = 'You cannot suspend your own account.';
            $type = 'error';
        } elseif ($userId && in_array($status, ['active', 'inactive', 'suspended'], true)) {
            try {
                $updateStmt = $db->prepare('UPDATE users SET status = ? WHERE id = ?');
                $updateStmt->execute([$status, $userId]);

                if ($updateStmt->rowCount() > 0) {
                    $message = 'User status updated successfully.';
                    $type = 'success';
                } else {
                    $message = 'No changes made or user not found.';
                    $type = 'error';
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $type = 'error';
            }
        }
    }
}

$filters = [
    'type' => $_GET['type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'branch' => $_GET['branch'] ?? '',
    'search' => trim($_GET['search'] ?? ''),
];

try {
    $where = [];
    $params = [];

    if ($filters['type'] !== '') {
        $where[] = 'user_type = ?';
        $params[] = $filters['type'];
    }
    if ($filters['status'] !== '') {
        $where[] = 'status = ?';
        $params[] = $filters['status'];
    }
    if ($filters['branch'] !== '') {
        $where[] = 'branch_name = ?';
        $params[] = $filters['branch'];
    }
    if ($filters['search'] !== '') {
        $where[] = '(username LIKE ? OR email LIKE ? OR full_name LIKE ?)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $orderBy = 'id DESC';
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'created_at'");
        if ($colCheck && $colCheck->fetch()) {
            $orderBy = 'created_at DESC';
        }
    } catch (PDOException $e) {
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare("SELECT * FROM users $whereClause ORDER BY $orderBy");
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $branchStmt = $db->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name != '' ORDER BY branch_name");
    $branches = $branchStmt ? $branchStmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
} catch (PDOException $e) {
    error_log('manage_users.php load error: ' . $e->getMessage());
    $message = 'Failed to load users: ' . $e->getMessage();
    $type = 'error';
}

$totalUsers = count($users);
$activeUsers = 0;
$adminUsers = 0;
$staffUsers = 0;
foreach ($users as $row) {
    if (($row['status'] ?? '') === 'active') {
        $activeUsers++;
    }
    if (($row['user_type'] ?? '') === 'admin') {
        $adminUsers++;
    }
    if (($row['user_type'] ?? '') === 'staff') {
        $staffUsers++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | Serendib Green Plantation Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #0a2f1d; --secondary: #1a4d33; --accent: #22c55e; --neon: #00ff88; --error: #ef4444; --text: #f0fdf4; --text-muted: #a7f3d0; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%); color: var(--text); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: rgba(10, 47, 29, 0.9); border-right: 1px solid rgba(34, 197, 94, 0.2); padding: 20px 0; }
        .sidebar-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(34, 197, 94, 0.2); margin-bottom: 20px; }
        .sidebar-header h2 { color: var(--neon); font-size: 20px; }
        .sidebar-nav ul { list-style: none; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 15px 20px; color: var(--text-muted); text-decoration: none; border-left: 3px solid transparent; }
        .sidebar-nav a:hover, .sidebar-nav .active a { background: rgba(34, 197, 94, 0.15); color: var(--text); border-left-color: var(--accent); }
        .main-content { flex: 1; padding: 20px; overflow-y: auto; }
        .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 1px solid rgba(34, 197, 94, 0.2); flex-wrap: wrap; gap: 15px; }
        .content-header h1 { font-size: 28px; color: var(--neon); }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 14px; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-secondary { background: rgba(26, 77, 51, 0.7); color: var(--text); border: 1px solid rgba(34, 197, 94, 0.2); }
        .btn-danger { background: var(--error); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert.success { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; }
        .alert.error { background: rgba(239, 68, 68, 0.2); border: 1px solid var(--error); }
        .filters { background: rgba(26, 77, 51, 0.5); padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid rgba(34, 197, 94, 0.2); }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-group label { display: block; margin-bottom: 5px; color: var(--text-muted); font-size: 13px; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.3); background: rgba(10, 47, 29, 0.8); color: var(--text); }
        .data-table { width: 100%; border-collapse: collapse; background: rgba(26, 77, 51, 0.5); border-radius: 12px; overflow: hidden; }
        .data-table th, .data-table td { padding: 14px; text-align: left; border-bottom: 1px solid rgba(34, 197, 94, 0.15); vertical-align: top; }
        .data-table th { background: rgba(10, 47, 29, 0.8); color: var(--neon); font-size: 13px; text-transform: uppercase; }
        .data-table tr:hover { background: rgba(34, 197, 94, 0.08); }
        .status-badge, .type-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-active { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .status-inactive { background: rgba(156, 163, 175, 0.2); color: #9ca3af; }
        .status-suspended { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .type-admin { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; }
        .type-staff { background: rgba(59, 130, 246, 0.2); color: #93c5fd; }
        .type-agent, .type-branch_manager { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .no-data { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: rgba(26, 77, 51, 0.5); padding: 20px; border-radius: 12px; border: 1px solid rgba(34, 197, 94, 0.2); text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; color: var(--neon); }
        .stat-label { font-size: 13px; color: var(--text-muted); margin-top: 5px; }
        .user-meta { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .current-tag { color: var(--neon); font-size: 11px; font-weight: 700; margin-top: 6px; display: inline-block; }
        .status-form select { padding: 6px 8px; border-radius: 6px; border: 1px solid rgba(34, 197, 94, 0.3); background: rgba(10, 47, 29, 0.8); color: var(--text); }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-leaf"></i> Admin Portal</h2>
            <p><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></p>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="active"><a href="manage_users.php"><i class="fas fa-users"></i> Users</a></li>
                <li><a href="sales_persons.php"><i class="fas fa-user-tie"></i> Sales Persons</a></li>
                <li><a href="allusers.php"><i class="fas fa-list"></i> All User Details</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <div class="content-header">
            <h1><i class="fas fa-users"></i> User Management</h1>
            <div class="header-actions">
                <a href="add_user.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add User</a>
                <a href="repair_db.php" class="btn btn-secondary"><i class="fas fa-tools"></i> Repair DB</a>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert <?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-number"><?php echo $totalUsers; ?></div><div class="stat-label">Total Users</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $activeUsers; ?></div><div class="stat-label">Active</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $adminUsers; ?></div><div class="stat-label">Admins</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $staffUsers; ?></div><div class="stat-label">Staff</div></div>
        </div>

        <div class="filters">
            <form method="GET">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>User Type</label>
                        <select name="type">
                            <option value="">All Types</option>
                            <option value="admin" <?php echo $filters['type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="staff" <?php echo $filters['type'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="agent" <?php echo $filters['type'] === 'agent' ? 'selected' : ''; ?>>Agent</option>
                            <option value="branch_manager" <?php echo $filters['type'] === 'branch_manager' ? 'selected' : ''; ?>>Branch Manager</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filters['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $filters['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Branch</label>
                        <select name="branch">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo $filters['branch'] === $branch ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branch); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Name, email, or username" value="<?php echo htmlspecialchars($filters['search']); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
                <a href="manage_users.php" class="btn btn-secondary">Clear</a>
            </form>
        </div>

        <?php if (empty($users)): ?>
            <div class="no-data">
                <i class="fas fa-user-slash" style="font-size: 48px; margin-bottom: 15px;"></i>
                <h3>No users found</h3>
                <p style="margin: 10px 0 20px;">Add a user or adjust your filters.</p>
                <a href="add_user.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add User</a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Type</th>
                            <th>Branch</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $userRow): ?>
                            <?php $isCurrentUser = ((int) $userRow['id'] === $currentUserId); ?>
                            <tr>
                                <td>#<?php echo str_pad((int) $userRow['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($userRow['full_name'] ?? ''); ?></strong>
                                    <div class="user-meta">@<?php echo htmlspecialchars($userRow['username'] ?? ''); ?></div>
                                    <div class="user-meta"><?php echo htmlspecialchars($userRow['email'] ?? ''); ?></div>
                                    <?php if ($isCurrentUser): ?><span class="current-tag">Current User</span><?php endif; ?>
                                </td>
                                <td>
                                    <span class="type-badge type-<?php echo htmlspecialchars($userRow['user_type'] ?? 'staff'); ?>">
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $userRow['user_type'] ?? 'staff'))); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($userRow['branch_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($userRow['status'] ?? 'inactive'); ?>">
                                        <?php echo htmlspecialchars(ucfirst($userRow['status'] ?? 'inactive')); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="user1.php?id=<?php echo (int) $userRow['id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
                                        <a href="edit_user.php?id=<?php echo (int) $userRow['id']; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                        <form method="POST" class="status-form" style="display:inline;" onsubmit="return confirm('Change user status?');">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $userRow['id']; ?>">
                                            <input type="hidden" name="change_status" value="1">
                                            <select name="status" onchange="this.form.submit()" <?php echo $isCurrentUser ? 'disabled' : ''; ?>>
                                                <option value="">Status</option>
                                                <option value="active" <?php echo ($userRow['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo ($userRow['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="suspended" <?php echo ($userRow['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                            </select>
                                        </form>
                                        <?php if (!$isCurrentUser): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user permanently?');">
                                                <input type="hidden" name="user_id" value="<?php echo (int) $userRow['id']; ?>">
                                                <button type="submit" name="delete_user" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
