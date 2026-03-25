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

// Check permission
if (!$user->hasPermission('view_applications')) {
    die('Access denied. You need permission to view applications.');
}

// Get filters
$filters = [
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// Build query
$where = [];
$params = [];

// Branch-specific filter
if ($_SESSION['branch_name']) {
    $where[] = "(i.assigned_to = ? OR i.branch_name = ?)";
    $params[] = $_SESSION['user_id'];
    $params[] = $_SESSION['branch_name'];
} else {
    $where[] = "i.assigned_to = ?";
    $params[] = $_SESSION['user_id'];
}

if ($filters['status']) {
    $where[] = "i.status = ?";
    $params[] = $filters['status'];
}

if ($filters['search']) {
    $where[] = "(i.full_name LIKE ? OR i.nic_no LIKE ? OR i.email LIKE ?)";
    $search_term = '%' . $filters['search'] . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($filters['date_from']) {
    $where[] = "DATE(i.created_at) >= ?";
    $params[] = $filters['date_from'];
}

if ($filters['date_to']) {
    $where[] = "DATE(i.created_at) <= ?";
    $params[] = $filters['date_to'];
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get applications
try {
    $sql = "
        SELECT i.*, 
               DATE_FORMAT(i.created_at, '%Y-%m-%d %H:%i') as formatted_created,
               u.full_name as assigned_to_name
        FROM investors i
        LEFT JOIN users u ON i.assigned_to = u.id
        $where_clause
        ORDER BY i.created_at DESC
    ";
    
    $stmt = $GLOBALS['db']->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applications | EcoWealth Branch</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Similar styles to admin applications page but simplified */
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
        
        .filters {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #a7f3d0;
            font-weight: 600;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: #f0fdf4;
        }
        
        .applications-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            overflow: hidden;
        }
        
        .applications-table th {
            padding: 15px;
            text-align: left;
            background: rgba(26, 77, 51, 0.9);
            color: #a7f3d0;
            font-weight: 600;
        }
        
        .applications-table td {
            padding: 15px;
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
            color: white;
        }
        
        .badge.pending { background: #f59e0b; }
        .badge.approved { background: #10b981; }
        .badge.rejected { background: #ef4444; }
        .badge.reviewed { background: #3b82f6; }
        
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
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #a7f3d0;
        }
    </style>
</head>
<body>
    <div class="branch-container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-file-alt"></i> Applications</h1>
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $filters['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="reviewed" <?php echo $filters['status'] == 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                            <option value="approved" <?php echo $filters['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $filters['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?php echo $filters['date_from']; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?php echo $filters['date_to']; ?>">
                    </div>
                </div>
                
                <div class="filter-row">
                    <div class="filter-group" style="grid-column: 1 / -1;">
                        <label>Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" 
                               placeholder="Search by name, NIC, or email">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" class="btn">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="applications.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Applications Table -->
        <?php if (empty($applications)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px;"></i>
                <h3>No Applications Found</h3>
                <p>Try adjusting your filters or check back later.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="applications-table">
                    <thead>
                        <tr>
                            <th>Application ID</th>
                            <th>Investor Name</th>
                            <th>NIC No</th>
                            <th>Email</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                        <tr>
                            <td><strong><?php echo str_pad($app['id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($app['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($app['nic_no']); ?></td>
                            <td><?php echo htmlspecialchars($app['email']); ?></td>
                            <td><?php echo $app['formatted_created']; ?></td>
                            <td>
                                <span class="badge <?php echo $app['status']; ?>">
                                    <?php echo ucfirst($app['status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($app['assigned_to_name'] ?? 'Unassigned'); ?></td>
                            <td>
                                <a href="view_application.php?id=<?php echo $app['id']; ?>" class="btn btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                
                                <?php if ($user->hasPermission('edit_applications') && $app['status'] === 'pending'): ?>
                                <a href="../admin/view_application.php?id=<?php echo $app['id']; ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Quick status filter
        document.querySelectorAll('.badge').forEach(badge => {
            badge.addEventListener('click', function() {
                const status = this.classList[1];
                const url = new URL(window.location);
                url.searchParams.set('status', status);
                window.location.href = url.toString();
            });
        });
    </script>
</body>
</html>