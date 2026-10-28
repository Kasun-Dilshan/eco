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

require_once 'includes/SalesPerson.php';
require_once 'includes/User.php';

$salesPerson = new SalesPerson();
$user = new User();
$message = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_sales_person'])) {
        $result = $salesPerson->delete((int) ($_POST['sales_person_id'] ?? 0));
        $message = $result['message'];
        $type = $result['success'] ? 'success' : 'error';
    } elseif (isset($_POST['change_status'])) {
        $id = (int) ($_POST['sales_person_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['active', 'inactive'], true)) {
            $existing = $salesPerson->getById($id);
            if ($existing) {
                $result = $salesPerson->update($id, array_merge($existing, ['status' => $status]));
                $message = $result['message'];
                $type = $result['success'] ? 'success' : 'error';
            }
        }
    }
}

$filters = [
    'branch' => $_GET['branch'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? '',
];

$salesPersons = $salesPerson->getAll($filters);
$branches = array_unique(array_merge($user->getBranches(), $salesPerson->getBranches()));
sort($branches);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Persons | Serendib Green Plantation Admin</title>
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
        .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 1px solid rgba(34, 197, 94, 0.2); }
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
        .data-table th, .data-table td { padding: 14px; text-align: left; border-bottom: 1px solid rgba(34, 197, 94, 0.15); }
        .data-table th { background: rgba(10, 47, 29, 0.8); color: var(--neon); font-size: 13px; text-transform: uppercase; }
        .data-table tr:hover { background: rgba(34, 197, 94, 0.08); }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-active { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .status-inactive { background: rgba(156, 163, 175, 0.2); color: #9ca3af; }
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .no-data { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: rgba(26, 77, 51, 0.5); padding: 20px; border-radius: 12px; border: 1px solid rgba(34, 197, 94, 0.2); text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; color: var(--neon); }
        .stat-label { font-size: 13px; color: var(--text-muted); margin-top: 5px; }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="sidebar">
        <div class="sidebar-header"><h2><i class="fas fa-leaf"></i> Admin Portal</h2></div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="active"><a href="sales_persons.php"><i class="fas fa-user-tie"></i> Sales Persons</a></li>
                <li><a href="manage_users.php"><i class="fas fa-users"></i> Users</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <div class="content-header">
            <h1><i class="fas fa-user-tie"></i> Sales Persons</h1>
            <div class="header-actions">
                <a href="add_sales_person.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Sales Person</a>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert <?php echo $type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($salesPersons); ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($salesPersons, fn($sp) => $sp['status'] === 'active')); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($branches); ?></div>
                <div class="stat-label">Branches</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Branch</label>
                        <select name="branch">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo ($filters['branch'] === $branch) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branch); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All</option>
                            <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filters['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Name, EPF, code, phone, or email" value="<?php echo htmlspecialchars($filters['search']); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="sales_persons.php" class="btn btn-secondary">Clear</a>
            </form>
        </div>

        <?php if (empty($salesPersons)): ?>
            <div class="no-data">
                <i class="fas fa-user-slash" style="font-size: 48px; margin-bottom: 15px;"></i>
                <h3>No sales persons found</h3>
                <p style="margin: 10px 0 20px;">Add your first sales person to get started.</p>
                <a href="add_sales_person.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Sales Person</a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Branch</th>
                            <th>EPF No</th>
                            <th>Sales Code</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Rank</th>
                            <th>Reports To</th>
                            <th>Status</th>
                            <th>Added By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($salesPersons as $sp): ?>
                        <tr>
                            <td>#<?php echo str_pad($sp['id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td><strong><?php echo htmlspecialchars($sp['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($sp['branch']); ?></td>
                            <td><?php echo htmlspecialchars($sp['epf_no']); ?></td>
                            <td><code><?php echo htmlspecialchars($sp['sales_code']); ?></code></td>
                            <td><?php echo htmlspecialchars($sp['phone_number'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($sp['email'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($sp['sales_rank'] ?? '—'); ?></td>
                            <td>
                                <?php if (!empty($sp['reported_sales_person_name'])): ?>
                                    <?php echo htmlspecialchars($sp['reported_sales_person_name']); ?>
                                    <small>(<?php echo htmlspecialchars($sp['reported_sales_person_code'] ?? ''); ?>)</small>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $sp['status']; ?>">
                                    <?php echo ucfirst($sp['status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($sp['created_by_name'] ?? '—'); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="edit_sales_person.php?id=<?php echo $sp['id']; ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this sales person?');">
                                        <input type="hidden" name="sales_person_id" value="<?php echo $sp['id']; ?>">
                                        <button type="submit" name="delete_sales_person" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
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
