<?php
session_start();
require_once '../config.php';
require_once '../db.php';



$approverLevel = $_SESSION['approver_level'];

try {
    // Get pending applications for this approver's level
    $pendingQuery = "
        SELECT i.*, 
               COUNT(DISTINCT b.id) as beneficiary_count,
               DATE_FORMAT(i.created_at, '%M %d, %Y') as formatted_date,
               DATE_FORMAT(i.created_at, '%Y-%m-%d') as sort_date
        FROM investors i
        LEFT JOIN beneficiaries b ON i.id = b.investor_id
        WHERE i.approval_status = ? 
        AND i.current_approver_level = ?
        GROUP BY i.id
        ORDER BY i.created_at ASC
    ";
    
    $pendingStmt = $db->prepare($pendingQuery);
    
    // Determine status based on approver level
    $statusMap = [
        1 => 'pending',
        2 => 'first_approval',
        3 => 'second_approval'
    ];
    
    $currentStatus = $statusMap[$approverLevel] ?? 'pending';
    $pendingStmt->execute([$currentStatus, $approverLevel]);
    $applications = $pendingStmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Applications | EcoWealth</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        <?php include 'approval_dashboard.php'; ?>
        /* Add additional styles specific to this page */
        
        .filter-bar {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: #a7f3d0;
            font-weight: 600;
            font-size: 14px;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: white;
            font-size: 14px;
        }
        
        .btn-filter {
            background: #22c55e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            align-self: flex-end;
        }
        
        .btn-filter:hover {
            background: #16a34a;
        }
        
        .table-container {
            overflow-x: auto;
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 20px;
        }
        
        .applications-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .applications-table th {
            background: #1a4d33;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid rgba(34, 197, 94, 0.3);
        }
        
        .applications-table td {
            padding: 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
            color: #a7f3d0;
        }
        
        .applications-table tr:hover {
            background: rgba(34, 197, 94, 0.05);
        }
        
        .applications-table .actions {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .page-link {
            padding: 8px 15px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: #a7f3d0;
            text-decoration: none;
        }
        
        .page-link.active {
            background: #22c55e;
            color: white;
            border-color: #22c55e;
        }
        
        .page-link:hover:not(.active) {
            background: rgba(34, 197, 94, 0.15);
        }
        
        @media (max-width: 768px) {
            .applications-table {
                display: block;
            }
            
            .applications-table thead {
                display: none;
            }
            
            .applications-table tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid rgba(34, 197, 94, 0.2);
                border-radius: 8px;
                padding: 15px;
            }
            
            .applications-table td {
                display: block;
                border: none;
                padding: 5px 0;
            }
            
            .applications-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #22c55e;
                display: block;
                margin-bottom: 5px;
            }
            
            .applications-table .actions {
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar (same as dashboard) -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-leaf"></i> EcoWealth</h2>
                <div class="approver-info">
                    <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['approver_name']); ?></strong></p>
                    <span class="approver-role"><?php echo str_replace('_', ' ', $_SESSION['approver_role']); ?></span>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="approval_dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="active">
                        <a href="pending_applications.php">
                            <i class="fas fa-clock"></i> Pending Applications
                        </a>
                    </li>
                    <li>
                        <a href="approved_applications.php">
                            <i class="fas fa-check-circle"></i> Approved
                        </a>
                    </li>
                    <li>
                        <a href="rejected_applications.php">
                            <i class="fas fa-times-circle"></i> Rejected
                        </a>
                    </li>
                    <li>
                        <a href="approver_logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1>Pending Applications</h1>
                <a href="approval_dashboard.php" class="logout-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date Range</label>
                    <input type="date" id="startDate" placeholder="Start Date">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" id="searchInput" placeholder="Search by name or NIC...">
                </div>
                <button class="btn-filter" onclick="applyFilters()">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <button class="btn-filter" onclick="resetFilters()" style="background: #6b7280;">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
            
            <!-- Applications Table -->
            <div class="table-container">
                <?php if (empty($applications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>No Pending Applications</h3>
                        <p>All applications at your level have been processed.</p>
                        <a href="approval_dashboard.php" class="btn" style="margin-top: 15px;">
                            <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                        </a>
                    </div>
                <?php else: ?>
                    <table class="applications-table" id="applicationsTable">
                        <thead>
                            <tr>
                                <th>Application ID</th>
                                <th>Investor Name</th>
                                <th>NIC No</th>
                                <th>Email</th>
                                <th>Beneficiaries</th>
                                <th>Submitted Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $app): ?>
                            <tr>
                                <td data-label="Application ID">
                                    <strong><?php echo str_pad($app['id'], 6, '0', STR_PAD_LEFT); ?></strong>
                                </td>
                                <td data-label="Investor Name"><?php echo htmlspecialchars($app['full_name']); ?></td>
                                <td data-label="NIC No"><?php echo htmlspecialchars($app['nic_no']); ?></td>
                                <td data-label="Email"><?php echo htmlspecialchars($app['email']); ?></td>
                                <td data-label="Beneficiaries"><?php echo $app['beneficiary_count']; ?></td>
                                <td data-label="Submitted Date"><?php echo $app['formatted_date']; ?></td>
                                <td data-label="Status">
                                    <span class="status-badge" style="background: <?php echo getStatusColor($currentStatus); ?>; padding: 4px 10px; border-radius: 12px; font-size: 12px;">
                                        <?php echo strtoupper(str_replace('_', ' ', $currentStatus)); ?>
                                    </span>
                                </td>
                                <td data-label="Actions" class="actions">
                                    <a href="view_application.php?app_id=<?php echo $app['id']; ?>" class="action-btn" style="background: rgba(34, 197, 94, 0.2); color: #22c55e;">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="approve_application.php?app_id=<?php echo $app['id']; ?>&action=approve" class="action-btn" style="background: rgba(16, 185, 129, 0.2); color: #10b981;">
                                        <i class="fas fa-check"></i> Approve
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <div class="pagination">
                        <a href="#" class="page-link active">1</a>
                        <a href="#" class="page-link">2</a>
                        <a href="#" class="page-link">3</a>
                        <span style="color: #a7f3d0; padding: 8px 15px;">...</span>
                        <a href="#" class="page-link">10</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Filter functionality
        function applyFilters() {
            const searchInput = document.getElementById('searchInput').value.toLowerCase();
            const startDate = document.getElementById('startDate').value;
            const rows = document.querySelectorAll('#applicationsTable tbody tr');
            
            rows.forEach(row => {
                let showRow = true;
                const name = row.cells[1].textContent.toLowerCase();
                const nic = row.cells[2].textContent.toLowerCase();
                const date = row.cells[5].textContent;
                
                // Search filter
                if (searchInput && !name.includes(searchInput) && !nic.includes(searchInput)) {
                    showRow = false;
                }
                
                // Date filter
                if (startDate) {
                    // Convert displayed date to YYYY-MM-DD format for comparison
                    const rowDate = new Date(date).toISOString().split('T')[0];
                    if (rowDate < startDate) {
                        showRow = false;
                    }
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('startDate').value = '';
            const rows = document.querySelectorAll('#applicationsTable tbody tr');
            rows.forEach(row => row.style.display = '');
        }
        
        // Helper function for status color
        function getStatusColor(status) {
            switch (status) {
                case 'pending': return '#f59e0b';
                case 'first_approval': return '#3b82f6';
                case 'second_approval': return '#8b5cf6';
                case 'final_approval': return '#10b981';
                case 'approved': return '#10b981';
                case 'rejected': return '#ef4444';
                default: return '#6b7280';
            }
        }
    </script>
</body>
</html>