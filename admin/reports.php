<?php
require_once '../config.php';
require_once '../db.php';



// Get date filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? 'all';
$investment_type = $_GET['investment_type'] ?? 'all';

// Initialize variables
$stats = [];
$chart_data = [];
$applications = [];

try {
    // Build WHERE clause for filters
    $where = [];
    $params = [];
    
    if ($status_filter !== 'all') {
        $where[] = "status = ?";
        $params[] = $status_filter;
    }
    
    if ($investment_type !== 'all') {
        $where[] = "investment_type = ?";
        $params[] = $investment_type;
    }
    
    if ($start_date && $end_date) {
        $where[] = "DATE(created_at) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    $where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    // Get total statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(investment_amount) as total_investment,
            AVG(investment_amount) as avg_investment,
            MIN(investment_amount) as min_investment,
            MAX(investment_amount) as max_investment,
            COUNT(DISTINCT bank_name) as unique_banks,
            COUNT(DISTINCT occupation) as unique_occupations
        FROM investors 
        $where_clause
    ";
    
    $stmt = $db->prepare($stats_query);
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
    // Get applications for the list
    $applications_query = "
        SELECT 
            id, 
            full_name,
            nic_no,
            email,
            status,
            investment_amount,
            investment_type,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as created_date,
            DATE_FORMAT(status, '%Y-%m-%d') as status
        FROM investors 
        $where_clause
        ORDER BY created_at DESC
        LIMIT 100
    ";
    
    $stmt = $db->prepare($applications_query);
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
    
    // Get chart data for monthly growth
    $chart_query = "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as applications,
            SUM(investment_amount) as total_amount,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_apps,
            SUM(CASE WHEN status = 'approved' THEN investment_amount ELSE 0 END) as approved_amount
        FROM investors 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ";
    
    $stmt = $db->prepare($chart_query);
    $stmt->execute();
    $chart_data_raw = $stmt->fetchAll();
    
    // Process chart data
    $chart_months = [];
    $chart_applications = [];
    $chart_amounts = [];
    $chart_approved = [];
    $chart_approved_amounts = [];
    
    foreach ($chart_data_raw as $row) {
        $chart_months[] = date('M Y', strtotime($row['month'] . '-01'));
        $chart_applications[] = (int)$row['applications'];
        $chart_amounts[] = (float)$row['total_amount'];
        $chart_approved[] = (int)$row['approved_apps'];
        $chart_approved_amounts[] = (float)$row['approved_amount'];
    }
    
    // Get top occupations
    $occupation_query = "
        SELECT 
            occupation,
            COUNT(*) as count,
            AVG(investment_amount) as avg_amount
        FROM investors 
        WHERE occupation IS NOT NULL AND occupation != ''
        GROUP BY occupation
        ORDER BY count DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($occupation_query);
    $stmt->execute();
    $top_occupations = $stmt->fetchAll();
    
    // Get bank distribution
    $bank_query = "
        SELECT 
            bank_name,
            COUNT(*) as count,
            SUM(investment_amount) as total_amount
        FROM investors 
        WHERE bank_name IS NOT NULL AND bank_name != ''
        GROUP BY bank_name
        ORDER BY total_amount DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($bank_query);
    $stmt->execute();
    $bank_distribution = $stmt->fetchAll();
    
    // Get yearly growth
    $yearly_query = "
        SELECT 
            YEAR(created_at) as year,
            COUNT(*) as applications,
            SUM(investment_amount) as total_amount,
            (SUM(investment_amount) - LAG(SUM(investment_amount)) OVER (ORDER BY YEAR(created_at))) / LAG(SUM(investment_amount)) OVER (ORDER BY YEAR(created_at)) * 100 as growth_rate
        FROM investors 
        GROUP BY YEAR(created_at)
        ORDER BY year
    ";
    
    $stmt = $db->prepare($yearly_query);
    $stmt->execute();
    $yearly_growth = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Function to generate PDF report
function generatePDFReport($type, $data, $filters) {
    require_once('../libs/tcpdf/tcpdf.php');
    
    // Create PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document properties
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('EcoWealth Finance');
    $pdf->SetTitle('Business Report - ' . ucfirst($type));
    $pdf->SetSubject('Business Analytics Report');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Add header
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(10, 47, 29);
    $pdf->Cell(0, 10, 'ECO WEALTH FINANCE', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(26, 77, 51);
    $pdf->Cell(0, 10, 'Business Intelligence Report', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 10, 'Generated on: ' . date('F d, Y H:i:s'), 0, 1);
    
    if (!empty($filters)) {
        $pdf->Cell(0, 10, 'Filters: ' . $filters, 0, 1);
    }
    
    $pdf->Ln(10);
    
    // Add content based on report type
    switch($type) {
        case 'summary':
            addSummaryReport($pdf, $data);
            break;
        case 'financial':
            addFinancialReport($pdf, $data);
            break;
        case 'demographics':
            addDemographicsReport($pdf, $data);
            break;
        case 'detailed':
            addDetailedReport($pdf, $data);
            break;
    }
    
    // Add footer
    $pdf->SetY(-25);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');
    
    // Output PDF
    $filename = 'EcoWealth_Report_' . $type . '_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// Helper functions for different report types
function addSummaryReport($pdf, $data) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(10, 47, 29);
    $pdf->Cell(0, 10, 'Executive Summary', 0, 1);
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    // Key Metrics
    $metrics = [
        ['Total Applications', $data['total_applications']],
        ['Approved Applications', $data['approved']],
        ['Total Investment', 'Rs. ' . number_format($data['total_investment'], 2)],
        ['Average Investment', 'Rs. ' . number_format($data['avg_investment'], 2)],
        ['Unique Banks', $data['unique_banks']],
        ['Unique Occupations', $data['unique_occupations']]
    ];
    
    foreach ($metrics as $metric) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(100, 8, $metric[0] . ':');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 8, $metric[1], 0, 1);
    }
}

function addFinancialReport($pdf, $data) {
    // Similar structure for financial report
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(10, 47, 29);
    $pdf->Cell(0, 10, 'Financial Analysis', 0, 1);
    $pdf->Ln(5);
    
    // Add financial tables and charts
}

// Handle PDF generation request
if (isset($_GET['generate_pdf'])) {
    $report_type = $_GET['report_type'] ?? 'summary';
    $filters = "Period: $start_date to $end_date | Status: $status_filter";
    generatePDFReport($report_type, $stats, $filters);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --accent-glow: rgba(34, 197, 94, 0.4);
            --neon: #00ff88;
            --success: #10b981;
            --error: #ef4444;
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
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 250px;
            background: rgba(10, 47, 29, 0.9);
            border-right: 1px solid rgba(34, 197, 94, 0.2);
            padding: 20px 0;
        }
        
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            margin-bottom: 20px;
        }
        
        .sidebar-header h2 {
            color: var(--neon);
            font-size: 20px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-nav ul {
            list-style: none;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .sidebar-nav a:hover {
            background: rgba(34, 197, 94, 0.1);
            color: var(--text);
            border-left-color: var(--accent);
        }
        
        .sidebar-nav .active a {
            background: rgba(34, 197, 94, 0.15);
            color: var(--text);
            border-left-color: var(--accent);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
        }
        
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .content-header h1 {
            font-size: 28px;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            background: #16a34a;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(34, 197, 94, 0.3);
        }
        
        .btn-secondary {
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--accent);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-2px);
        }
        
        .btn-info {
            background: #3b82f6;
            color: white;
        }
        
        .btn-info:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
        
        /* Filter Section */
        .filter-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(34, 197, 94, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
            color: var(--accent);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 10px;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-muted);
        }
        
        /* Charts Section */
        .charts-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }
        
        .chart-container {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
            height: 350px;
        }
        
        /* Tables Section */
        .tables-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .table-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }
        
        .table-container {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
        }
        
        .table-container h3 {
            margin-bottom: 15px;
            color: var(--neon);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: rgba(34, 197, 94, 0.2);
            color: var(--text);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid rgba(34, 197, 94, 0.3);
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
            color: var(--text-muted);
        }
        
        .data-table tr:hover {
            background: rgba(34, 197, 94, 0.05);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-pending { background: #f59e0b; color: white; }
        .status-reviewed { background: #3b82f6; color: white; }
        .status-approved { background: #10b981; color: white; }
        .status-rejected { background: #ef4444; color: white; }
        
        /* Report Generation Section */
        .reports-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .report-card {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
        }
        
        .report-icon {
            font-size: 48px;
            color: var(--accent);
            margin-bottom: 15px;
        }
        
        .report-card h3 {
            margin-bottom: 10px;
            color: var(--text);
        }
        
        .report-card p {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            }
            
            .charts-grid,
            .table-grid,
            .reports-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 300px;
            }
            
            .header-actions {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chart-line"></i> EcoWealth Analytics</h2>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></p>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="applications.php">
                            <i class="fas fa-file-alt"></i> Applications
                        </a>
                    </li>
                    <li class="active">
                        <a href="reports.php">
                            <i class="fas fa-chart-bar"></i> Reports & Analytics
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
                <h1>Business Intelligence Dashboard</h1>
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $start_date; ?>" required>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> End Date</label>
                            <input type="date" name="end_date" value="<?php echo $end_date; ?>" required>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter"></i> Status</label>
                            <select name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="reviewed" <?php echo $status_filter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-money-bill-wave"></i> Investment Type</label>
                            <select name="investment_type">
                                <option value="all" <?php echo $investment_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="standard" <?php echo $investment_type === 'standard' ? 'selected' : ''; ?>>Standard</option>
                                <option value="premium" <?php echo $investment_type === 'premium' ? 'selected' : ''; ?>>Premium</option>
                                <option value="enterprise" <?php echo $investment_type === 'enterprise' ? 'selected' : ''; ?>>Enterprise</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="reports.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_applications'] ?? 0; ?></div>
                    <div class="stat-label">Total Applications</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                    <div class="stat-label">Approved Applications</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">Rs. <?php echo number_format($stats['total_investment'] ?? 0, 2); ?></div>
                    <div class="stat-label">Total Investment</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value">Rs. <?php echo number_format($stats['avg_investment'] ?? 0, 2); ?></div>
                    <div class="stat-label">Average Investment</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['unique_banks'] ?? 0; ?></div>
                    <div class="stat-label">Unique Banks</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['unique_occupations'] ?? 0; ?></div>
                    <div class="stat-label">Unique Occupations</div>
                </div>
            </div>
            
            <!-- Charts Section -->
            <div class="charts-section">
                <h2 style="margin-bottom: 20px; color: var(--neon);">
                    <i class="fas fa-chart-bar"></i> Business Growth Analytics
                </h2>
                <div class="charts-grid">
                    <div class="chart-container">
                        <canvas id="monthlyApplicationsChart"></canvas>
                    </div>
                    <div class="chart-container">
                        <canvas id="investmentTrendChart"></canvas>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusDistributionChart"></canvas>
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Tables Section -->
            <div class="tables-section">
                <h2 style="margin-bottom: 20px; color: var(--neon);">
                    <i class="fas fa-table"></i> Data Analysis
                </h2>
                <div class="table-grid">
                    <div class="table-container">
                        <h3><i class="fas fa-user-tie"></i> Top Occupations</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Occupation</th>
                                    <th>Count</th>
                                    <th>Avg. Investment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_occupations as $occupation): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($occupation['occupation']); ?></td>
                                    <td><?php echo $occupation['count']; ?></td>
                                    <td>Rs. <?php echo number_format($occupation['avg_amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="table-container">
                        <h3><i class="fas fa-university"></i> Bank Distribution</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Bank</th>
                                    <th>Accounts</th>
                                    <th>Total Investment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bank_distribution as $bank): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($bank['bank_name']); ?></td>
                                    <td><?php echo $bank['count']; ?></td>
                                    <td>Rs. <?php echo number_format($bank['total_amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="table-container">
                        <h3><i class="fas fa-chart-line"></i> Yearly Growth</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Year</th>
                                    <th>Applications</th>
                                    <th>Total Amount</th>
                                    <th>Growth Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($yearly_growth as $year): ?>
                                <tr>
                                    <td><?php echo $year['year']; ?></td>
                                    <td><?php echo $year['applications']; ?></td>
                                    <td>Rs. <?php echo number_format($year['total_amount'], 2); ?></td>
                                    <td>
                                        <?php if ($year['growth_rate'] !== null): ?>
                                            <span style="color: <?php echo $year['growth_rate'] >= 0 ? '#10b981' : '#ef4444'; ?>">
                                                <?php echo number_format($year['growth_rate'], 2); ?>%
                                            </span>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="table-container">
                        <h3><i class="fas fa-history"></i> Recent Applications</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($applications, 0, 5) as $app): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($app['full_name']); ?></td>
                                    <td><?php echo $app['created_date']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $app['status']; ?>">
                                            <?php echo ucfirst($app['status']); ?>
                                        </span>
                                    </td>
                                    <td>Rs. <?php echo number_format($app['investment_amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Report Generation Section -->
            <div class="reports-section">
                <h2 style="margin-bottom: 20px; color: var(--neon);">
                    <i class="fas fa-file-pdf"></i> Generate Reports
                </h2>
                <div class="reports-grid">
                    <div class="report-card">
                        <div class="report-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h3>Executive Summary</h3>
                        <p>Generate a comprehensive executive summary report with key metrics and insights.</p>
                        <a href="?generate_pdf=1&report_type=summary&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status_filter; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                    
                    <div class="report-card">
                        <div class="report-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3>Financial Report</h3>
                        <p>Detailed financial analysis including revenue, growth, and investment patterns.</p>
                        <a href="?generate_pdf=1&report_type=financial&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status_filter; ?>" class="btn btn-warning">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                    
                    <div class="report-card">
                        <div class="report-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3>Demographics Report</h3>
                        <p>Client demographics analysis including occupations, locations, and age groups.</p>
                        <a href="?generate_pdf=1&report_type=demographics&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status_filter; ?>" class="btn btn-info">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                    
                    <div class="report-card">
                        <div class="report-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3>Detailed Report</h3>
                        <p>Complete detailed report with all data, charts, and comprehensive analysis.</p>
                        <a href="?generate_pdf=1&report_type=detailed&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status_filter; ?>" class="btn btn-danger">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Chart Colors
        const primaryColor = '#22c55e';
        const successColor = '#10b981';
        const warningColor = '#f59e0b';
        const dangerColor = '#ef4444';
        const infoColor = '#3b82f6';
        
        // Monthly Applications Chart
        const monthlyApplicationsCtx = document.getElementById('monthlyApplicationsChart').getContext('2d');
        const monthlyApplicationsChart = new Chart(monthlyApplicationsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_months); ?>,
                datasets: [{
                    label: 'Applications',
                    data: <?php echo json_encode($chart_applications); ?>,
                    borderColor: primaryColor,
                    backgroundColor: primaryColor + '20',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Approved',
                    data: <?php echo json_encode($chart_approved); ?>,
                    borderColor: successColor,
                    backgroundColor: successColor + '20',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: '#f0fdf4'
                        }
                    },
                    title: {
                        display: true,
                        text: 'Monthly Applications Trend',
                        color: '#a7f3d0',
                        font: {
                            size: 16
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(34, 197, 94, 0.1)'
                        },
                        ticks: {
                            color: '#a7f3d0'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(34, 197, 94, 0.1)'
                        },
                        ticks: {
                            color: '#a7f3d0'
                        }
                    }
                }
            }
        });
        
        // Investment Trend Chart
        const investmentTrendCtx = document.getElementById('investmentTrendChart').getContext('2d');
        const investmentTrendChart = new Chart(investmentTrendCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_months); ?>,
                datasets: [{
                    label: 'Total Investment',
                    data: <?php echo json_encode($chart_amounts); ?>,
                    backgroundColor: primaryColor + '80',
                    borderColor: primaryColor,
                    borderWidth: 2
                }, {
                    label: 'Approved Investment',
                    data: <?php echo json_encode($chart_approved_amounts); ?>,
                    backgroundColor: successColor + '80',
                    borderColor: successColor,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: '#f0fdf4'
                        }
                    },
                    title: {
                        display: true,
                        text: 'Investment Amount Trend',
                        color: '#a7f3d0',
                        font: {
                            size: 16
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(34, 197, 94, 0.1)'
                        },
                        ticks: {
                            color: '#a7f3d0'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(34, 197, 94, 0.1)'
                        },
                        ticks: {
                            color: '#a7f3d0',
                            callback: function(value) {
                                return 'Rs. ' + (value / 1000).toFixed(0) + 'K';
                            }
                        }
                    }
                }
            }
        });
        
        // Status Distribution Chart
        const statusDistributionCtx = document.getElementById('statusDistributionChart').getContext('2d');
        const statusDistributionChart = new Chart(statusDistributionCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Reviewed', 'Approved', 'Rejected'],
                datasets: [{
                    data: [
                        <?php echo $stats['pending'] ?? 0; ?>,
                        <?php echo $stats['reviewed'] ?? 0; ?>,
                        <?php echo $stats['approved'] ?? 0; ?>,
                        <?php echo $stats['rejected'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        warningColor,
                        infoColor,
                        successColor,
                        dangerColor
                    ],
                    borderWidth: 2,
                    borderColor: '#0a2f1d'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: '#f0fdf4',
                            padding: 20
                        }
                    },
                    title: {
                        display: true,
                        text: 'Application Status Distribution',
                        color: '#a7f3d0',
                        font: {
                            size: 16
                        }
                    }
                }
            }
        });
        
        // Monthly Revenue Chart (Projected Growth)
        const monthlyRevenueCtx = document.getElementById('monthlyRevenueChart').getContext('2d');
        
        // Calculate projected revenue (simple linear projection)
        const actualAmounts = <?php echo json_encode($chart_approved_amounts); ?>;
        const projectedMonths = <?php echo json_encode(array_slice($chart_months, -6)); ?>;
        const projectedData = [];
        
        if (actualAmounts.length >= 3) {
            // Simple projection: average of last 3 months * 1.1 (10% growth assumption)
            const lastThree = actualAmounts.slice(-3);
            const avgLastThree = lastThree.reduce((a, b) => a + b, 0) / lastThree.length;
            
            for (let i = 0; i < 6; i++) {
                projectedData.push(avgLastThree * Math.pow(1.1, i + 1));
            }
        }
        
        const monthlyRevenueChart = new Chart(monthlyRevenueCtx, {
            type: 'line',
            data: {
                labels: projectedMonths,
                datasets: [{
                    label: 'Actual Revenue',
                    data: actualAmounts.slice(-6),
                    borderColor: primaryColor,
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    tension: 0.4
                }, {
                    label: 'Projected Revenue',
                    data: projectedData,
                    borderColor: warningColor,
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    borderDash: [5, 5],
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: '#f0fdf4'
                        }
                    },
                    title: {
                        display: true,
                        text: 'Revenue Forecast (Next 6 Months)',
                        color: '#a7f3d0',
                        font: {
                            size: 16
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(34, 197, 94, 0.1)'
                        },
                        ticks: {
                            color: '#a7fdf4'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(34, 197, 94, 0.1)'
                        },
                        ticks: {
                            color: '#a7fdf4',
                            callback: function(value) {
                                return 'Rs. ' + (value / 1000).toFixed(0) + 'K';
                            }
                        }
                    }
                }
            }
        });
        
        // Export Charts as Image
        function exportChart(chartId, filename) {
            const chart = document.getElementById(chartId);
            const link = document.createElement('a');
            link.download = filename + '.png';
            link.href = chart.toDataURL('image/png');
            link.click();
        }
        
        // Auto-refresh every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>