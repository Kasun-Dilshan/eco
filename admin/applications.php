<?php
session_start();
require_once '../config.php';
require_once '../db.php';


// Check if user is logged in as admin or approver
if (!isset($_SESSION['user_id']) && !isset($_SESSION['approver_id'])) {
    header('Location: index.php');
    exit();
}

// Determine login type
$isApprover = isset($_SESSION['approver_id']);
$loginType = $isApprover ? 'approver' : 'admin';

// Get user information based on login type
if ($isApprover) {
    $userId = $_SESSION['approver_id'];
    $username = $_SESSION['approver_name'] ?? 'Approver';
    $fullName = $_SESSION['approver_name'] ?? 'Approver';
    $userType = $_SESSION['approver_role'] ?? 'approver';
    $approverLevel = $_SESSION['approver_level'] ?? 1;
    
    // Fetch additional approver data
    try {
        $stmt = $db->prepare("
            SELECT email, role, level, status, last_login, 
                   DATE_FORMAT(created_at, '%M %d, %Y') as join_date
            FROM approvers 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch();
    } catch (PDOException $e) {
        $userData = [];
    }
} else {
    // Admin/User login
    $userId = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'User';
    $fullName = $_SESSION['full_name'] ?? $username;
    $userType = $_SESSION['user_type'] ?? 'user';
    
    // Fetch additional user data
    try {
        $stmt = $db->prepare("
            SELECT email, user_type, status, last_login, 
                   DATE_FORMAT(created_at, '%M %d, %Y') as join_date
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch();
    } catch (PDOException $e) {
        $userData = [];
    }
}


// Handle delete action
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // Verify application exists
    $stmt = $db->prepare("SELECT * FROM investors WHERE id = ?");
    $stmt->execute([$delete_id]);
    $application = $stmt->fetch();
    
    if ($application) {
        // Delete beneficiaries first (if they exist)
        $stmt = $db->prepare("DELETE FROM beneficiaries WHERE investor_id = ?");
        $stmt->execute([$delete_id]);
        
        // Delete the application
        $stmt = $db->prepare("DELETE FROM investors WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        $_SESSION['delete_success'] = "Application #EWF-" . str_pad($delete_id, 6, '0', STR_PAD_LEFT) . " has been deleted successfully.";
        
        // Redirect to avoid resubmission
        header("Location: applications.php");
        exit();
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query based on filters - FIXED: Removed reviewed_by join
$whereClause = '';
$params = [];

if (!empty($search)) {
    $whereClause = "WHERE (i.full_name LIKE ? OR i.email LIKE ? OR i.nic_no LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_fill(0, 3, $searchTerm);
}

if ($filter !== 'all') {
    if ($whereClause) {
        $whereClause .= " AND i.status = ?";
    } else {
        $whereClause = "WHERE i.status = ?";
    }
    $params[] = $filter;
}

// Get total count for pagination - FIXED: Simplified query
$countQuery = "SELECT COUNT(*) FROM investors i $whereClause";
$stmt = $db->prepare($countQuery);
$stmt->execute($params);
$totalApplications = $stmt->fetchColumn();
$totalPages = ceil($totalApplications / $limit);

// Get applications with pagination - FIXED: Removed reviewed_by join
$query = "
    SELECT i.*, 
           COUNT(b.id) as beneficiary_count
    FROM investors i
    LEFT JOIN beneficiaries b ON i.id = b.investor_id
    $whereClause
    GROUP BY i.id
    ORDER BY i.created_at DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applications | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: #f0fdf4;
            min-height: 100vh;
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: rgba(10, 47, 29, 0.9);
            border-right: 1px solid rgba(34, 197, 94, 0.2);
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .sidebar-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #00ff88;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 14px;
            color: #a7f3d0;
        }
        
        .sidebar-nav {
            flex: 1;
            padding: 20px 0;
        }
        
        .sidebar-nav ul {
            list-style: none;
        }
        
        .sidebar-nav li {
            margin-bottom: 5px;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #f0fdf4;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .sidebar-nav a:hover {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }
        
        .sidebar-nav a.active {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border-left: 3px solid #22c55e;
        }
        
        .sidebar-nav a i {
            width: 24px;
            margin-right: 10px;
            font-size: 16px;
        }
        
        .sidebar-nav .badge {
            background: #22c55e;
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: auto;
        }
        
        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 20px;
        }
        
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .content-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #f0fdf4;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        /* Button Styles */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, #22c55e, #00ff88);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(34, 197, 94, 0.4);
        }
        
        .btn-secondary {
            background: rgba(26, 77, 51, 0.7);
            color: #f0fdf4;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: #22c55e;
        }
        
        .btn-danger {
            background: linear-gradient(90deg, #ef4444, #dc2626);
            color: white;
        }
        
        .btn-danger:hover {
            background: linear-gradient(90deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4);
        }
        
        /* Stats Summary */
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-item {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-item h3 {
            font-size: 24px;
            margin-bottom: 5px;
            color: #22c55e;
        }
        
        .stat-item p {
            color: #a7f3d0;
            font-size: 14px;
        }
        
        /* Success Message */
        .alert-success {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            color: #10b981;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-close {
            background: none;
            border: none;
            color: #10b981;
            font-size: 18px;
            cursor: pointer;
        }
        
        /* Filters */
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 16px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 6px;
            color: #f0fdf4;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .filter-btn:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: #22c55e;
        }
        
        .filter-btn.active {
            background: #22c55e;
            color: white;
            border-color: #22c55e;
        }
        
        /* Search Box */
        .search-box {
            position: relative;
            margin-bottom: 20px;
            max-width: 400px;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            color: #f0fdf4;
            font-size: 16px;
        }
        
        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(167, 243, 208, 0.6);
        }
        
        /* Applications Table */
        .content-card {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: rgba(26, 77, 51, 0.7);
            border-bottom: 2px solid rgba(34, 197, 94, 0.2);
        }
        
        .data-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #f0fdf4;
            font-size: 14px;
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            font-size: 14px;
        }
        
        .data-table tbody tr {
            transition: all 0.3s ease;
        }
        
        .data-table tbody tr:hover {
            background: rgba(34, 197, 94, 0.08);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .status-approved {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .status-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #f0fdf4;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-action:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: #22c55e;
            color: #22c55e;
        }
        
        .btn-action-delete {
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .btn-action-delete:hover {
            background: rgba(239, 68, 68, 0.15);
            border-color: #ef4444;
            color: #ef4444;
        }
        
        /* Confirmation Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: rgba(10, 47, 29, 0.95);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        .modal h3 {
            font-size: 22px;
            margin-bottom: 15px;
            color: #f0fdf4;
        }
        
        .modal p {
            color: #a7f3d0;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .page-link {
            padding: 8px 16px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 6px;
            color: #f0fdf4;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: #22c55e;
        }
        
        .page-link.active {
            background: #22c55e;
            color: white;
            border-color: #22c55e;
        }
        
        .text-center {
            text-align: center;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            background: #22c55e;
            color: white;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Serendib Green Plantation Admin</h2>
                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                        <div style="padding: 8px 20px; background: rgba(34, 197, 94, 0.15); border-radius: 20px; font-size: 14px;">
                            <i class="fas fa-user-tag"></i> Role: <?php echo ucfirst(str_replace('_', ' ', $userType)); ?>
                        </div>
                       
                    </div>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="active">
                        <a href="applications.php">
                            <i class="fas fa-file-alt"></i> Applications
                            <?php 
                            // Get pending count
                            $stmt = $db->prepare("SELECT COUNT(*) FROM investors WHERE status = 'pending'");
                            $stmt->execute();
                            $pending = $stmt->fetchColumn();
                            if ($pending > 0): ?>
                                <span class="badge"><?php echo $pending; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="send_email.php">
                            <i class="fas fa-envelope"></i> Send Email
                        </a>
                    </li>
                    <li>
                        <a href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="content-header">
                <h1>Applications Management</h1>
                <div class="header-actions">
                    <button type="button" onclick="exportApplications()" class="btn btn-primary">
                        <i class="fas fa-file-export"></i> Export All
                    </button>
                    <button type="button" onclick="showBulkDelete()" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Bulk Delete
                    </button>
                </div>
            </div>
            
            <?php if (isset($_SESSION['delete_success'])): ?>
                <div class="alert-success" id="successMessage">
                    <div>
                        <i class="fas fa-check-circle"></i> 
                        <?php echo $_SESSION['delete_success']; ?>
                    </div>
                    <button type="button" class="alert-close" onclick="document.getElementById('successMessage').style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['delete_success']); ?>
            <?php endif; ?>
            
            <!-- Stats Summary -->
            <div class="stats-summary">
                <div class="stat-item">
                    <h3><?php echo $totalApplications; ?></h3>
                    <p>Total Applications</p>
                </div>
                <?php
                $stmt = $db->prepare("SELECT COUNT(*) FROM investors WHERE status = 'pending'");
                $stmt->execute();
                $pending = $stmt->fetchColumn();
                ?>
                <div class="stat-item">
                    <h3><?php echo $pending; ?></h3>
                    <p>Pending Review</p>
                </div>


                  <?php
                $stmt = $db->prepare("SELECT COUNT(*) FROM investors WHERE status = 'in_progress'");
                $stmt->execute();
                $pending = $stmt->fetchColumn();
                ?>
                <div class="stat-item">
                    <h3><?php echo $pending; ?></h3>
                    <p>In Progress</p>
                </div>
                <?php
                $stmt = $db->prepare("SELECT COUNT(*) FROM investors WHERE status = 'approved'");
                $stmt->execute();
                $approved = $stmt->fetchColumn();
                ?>
                <div class="stat-item">
                    <h3><?php echo $approved; ?></h3>
                    <p>Approved</p>
                </div>
                <div class="stat-item">
                    <h3><?php echo $totalPages; ?></h3>
                    <p>Total Pages</p>
                </div>
            </div>
            
            <!-- Filters and Search -->
            <div class="filter-buttons">
                <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">
                    All Applications
                </a>
                <a href="?filter=pending" class="filter-btn <?php echo $filter == 'pending' ? 'active' : ''; ?>">
                    Pending (<?php echo $pending; ?>)
                </a>
                  <a href="?filter=in_progress" class="filter-btn <?php echo $filter == 'in_progress' ? 'active' : ''; ?>">
                    In Progress (<?php echo $pending; ?>)
                </a>
                <a href="?filter=approved" class="filter-btn <?php echo $filter == 'approved' ? 'active' : ''; ?>">
                    Approved (<?php echo $approved; ?>)
                </a>
                <a href="?filter=rejected" class="filter-btn <?php echo $filter == 'rejected' ? 'active' : ''; ?>">
                    Rejected
                </a>
            </div>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search by name, email, or NIC..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       onkeyup="if(event.key === 'Enter') searchApplications()">
            </div>
            
            <!-- Applications Table -->
            <div class="content-card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Beneficiaries</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">No applications found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($applications as $app): ?>
                                    <?php
                                    $statusClass = '';
                                    switch ($app['status']) {
                                        case 'approved': $statusClass = 'status-approved'; break;
                                        case 'rejected': $statusClass = 'status-rejected'; break;
                                        default: $statusClass = 'status-pending';
                                    }
                                    ?>
                                    <tr>
                                        <td>EWF-<?php echo str_pad($app['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($app['full_name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($app['nic_no']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['email']); ?></td>
                                        <td><?php echo htmlspecialchars($app['tel_no']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst($app['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge"><?php echo $app['beneficiary_count']; ?> beneficiaries</span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($app['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_application.php?id=<?php echo $app['id']; ?>" 
                                                   class="btn-action" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                               
                                                <a href="send_email.php?application_id=<?php echo $app['id']; ?>" 
                                                   class="btn-action" title="Send Email">
                                                    <i class="fas fa-envelope"></i>
                                                </a>
                                                <!-- Delete Button -->
                                                <a href="javascript:void(0)" 
                                                   onclick="confirmDelete(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars(addslashes($app['full_name'])); ?>')" 
                                                   class="btn-action btn-action-delete" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" 
                           class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= min($totalPages, 10); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" 
                           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" 
                           class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
            <p id="deleteMessage">Are you sure you want to delete this application? This action cannot be undone.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="performDelete()">
                    <i class="fas fa-trash"></i> Delete Application
                </button>
            </div>
        </div>
    </div>
    
    <!-- Bulk Delete Modal -->
    <div class="modal-overlay" id="bulkDeleteModal">
        <div class="modal">
            <h3><i class="fas fa-exclamation-triangle"></i> Bulk Delete</h3>
            <p>This will delete all applications that match your current filters:</p>
            <ul style="margin-bottom: 20px; padding-left: 20px; color: #f0fdf4;">
                <li>Filter: <strong><?php echo ucfirst($filter); ?> Applications</strong></li>
                <li>Search: <strong><?php echo $search ? htmlspecialchars($search) : 'None'; ?></strong></li>
                <li>Total to delete: <strong><?php echo $totalApplications; ?> applications</strong></li>
            </ul>
            <p style="color: #ef4444; font-weight: bold;">
                <i class="fas fa-exclamation-circle"></i> This action cannot be undone!
            </p>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBulkModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="performBulkDelete()">
                    <i class="fas fa-trash"></i> Delete All <?php echo $totalApplications; ?> Applications
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let applicationToDelete = null;
        
        function searchApplications() {
            const search = document.getElementById('searchInput').value;
            const filter = '<?php echo $filter; ?>';
            window.location.href = `?filter=${filter}&search=${encodeURIComponent(search)}`;
        }
        
        function updateStatus(id, status) {
            if (confirm(`Are you sure you want to ${status} this application?`)) {
                fetch('update_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${id}&status=${status}&action=update`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Status updated successfully!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error updating status.');
                    console.error('Error:', error);
                });
            }
        }
        
        function exportApplications() {
            const filter = '<?php echo $filter; ?>';
            const search = '<?php echo urlencode($search); ?>';
            window.location.href = `export_applications.php?filter=${filter}&search=${search}`;
        }
        
        // Delete Functions
        function confirmDelete(id, name) {
            applicationToDelete = id;
            const appId = 'EWF-' + String(id).padStart(6, '0');
            document.getElementById('deleteMessage').innerHTML = 
                `Are you sure you want to delete the application <strong>${appId}</strong> for <strong>${name}</strong>?<br><br>
                 This will permanently delete:<br>
                 • The main application record<br>
                 • All beneficiary records<br>
                 • Any associated files<br><br>
                 <span style="color: #ef4444; font-weight: bold;">This action cannot be undone!</span>`;
            
            document.getElementById('deleteModal').classList.add('active');
        }
        
        function performDelete() {
            if (applicationToDelete) {
                window.location.href = `?delete_id=${applicationToDelete}&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page; ?>`;
            }
        }
        
        function closeModal() {
            document.getElementById('deleteModal').classList.remove('active');
            applicationToDelete = null;
        }
        
        // Bulk Delete Functions
        function showBulkDelete() {
            if (<?php echo $totalApplications; ?> === 0) {
                alert('There are no applications to delete with the current filters.');
                return;
            }
            
            document.getElementById('bulkDeleteModal').classList.add('active');
        }
        
        function performBulkDelete() {
            const filter = '<?php echo $filter; ?>';
            const search = '<?php echo urlencode($search); ?>';
            
            if (confirm('FINAL WARNING: This will delete ALL ' + <?php echo $totalApplications; ?> + ' applications matching your current filters. This action cannot be undone!')) {
                // Redirect to bulk delete handler
                window.location.href = `bulk_delete.php?filter=${filter}&search=${search}`;
            }
        }
        
        function closeBulkModal() {
            document.getElementById('bulkDeleteModal').classList.remove('active');
        }
        
        // Close modals on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeBulkModal();
            }
        });
        
        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            const successMsg = document.getElementById('successMessage');
            if (successMsg) {
                successMsg.style.opacity = '0';
                setTimeout(() => {
                    successMsg.style.display = 'none';
                }, 300);
            }
        }, 5000);
    </script>
</body>
</html>