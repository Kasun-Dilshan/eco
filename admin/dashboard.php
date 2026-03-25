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

// Function to get greeting based on time
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

// Get current date and time
$currentDate = date('l, F j, Y');
$currentTime = date('h:i A');

// Get dashboard statistics
try {
    $stats = [];
    
    // Total applications
    $stmt = $db->prepare("SELECT COUNT(*) FROM investors");
    $stmt->execute();
    $stats['total'] = $stmt->fetchColumn();
    
    // Pending applications
    $stmt = $db->prepare("SELECT COUNT(*) FROM investors WHERE status = 'pending'");
    $stmt->execute();
    $stats['pending'] = $stmt->fetchColumn();
    
    // Approved applications
    $stmt = $db->prepare("SELECT COUNT(*) FROM investors WHERE status = 'approved'");
    $stmt->execute();
    $stats['approved'] = $stmt->fetchColumn();
    
    // Rejected applications
    $stmt = $db->prepare("SELECT COUNT(*) FROM investors WHERE status = 'rejected'");
    $stmt->execute();
    $stats['rejected'] = $stmt->fetchColumn();
    
    // Recent applications (last 7 days)
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM investors 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $stats['recent'] = $stmt->fetchColumn();
    
    // Get total investment amount
    $stmt = $db->prepare("SELECT SUM(investment_amount) FROM investors WHERE status = 'approved'");
    $stmt->execute();
    $stats['investment'] = $stmt->fetchColumn() ?? 0;
    
    // Get recent applications for table
    $stmt = $db->prepare("
        SELECT i.*,
               DATE_FORMAT(i.created_at, '%M %d, %Y') as formatted_date
        FROM investors i
        ORDER BY i.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recentApplications = $stmt->fetchAll();
    
    // Get monthly application chart data
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%b') as month,
            COUNT(*) as count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM investors
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
        ORDER BY YEAR(created_at), MONTH(created_at)
    ");
    $stmt->execute();
    $monthlyData = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}


try {
    $stmt = $db->prepare("SELECT 
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        COUNT(*) as total
    FROM investors");
    $stmt->execute();
    $stats = $stmt->fetch();
    
    // Calculate percentages
    $total = $stats['total'] > 0 ? $stats['total'] : 1;
    $pendingPercent = ($stats['pending'] / $total) * 100;
    $inProgressPercent = ($stats['in_progress'] / $total) * 100;
    $approvedPercent = ($stats['approved'] / $total) * 100;
    $rejectedPercent = ($stats['rejected'] / $total) * 100;
    
} catch (PDOException $e) {
    // Default values if there's an error
    $stats = [
        'pending' => 0,
        'in_progress' => 0,
        'approved' => 0,
        'rejected' => 0,
        'total' => 0
    ];
    $pendingPercent = $inProgressPercent = $approvedPercent = $rejectedPercent = 0;
}

// Pagination logic
$recordsPerPage = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $recordsPerPage;

// Get total number of applications
$totalStmt = $db->query("SELECT COUNT(*) as total FROM investors");
$totalApplications = $totalStmt->fetch()['total'];
$totalPages = ceil($totalApplications / $recordsPerPage);

// Get paginated applications
$stmt = $db->prepare("
    SELECT id, full_name, email, nic_no, status, 
           DATE_FORMAT(created_at, '%b %d, %Y') as formatted_date
    FROM investors 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $recordsPerPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$recentApplications = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Serendib Green Plantation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --primary-dark: #052012;
            --primary-light: #1a4d33;
            --secondary: #1e8d5c;
            --accent: #22c55e;
            --accent-glow: rgba(34, 197, 94, 0.4);
            --neon: #00ff88;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --text: #ffffff;
            --text-light: #e5f7f0;
            --text-muted: #a7f3d0;
            --card-bg: rgba(10, 47, 29, 0.85);
            --card-border: rgba(34, 197, 94, 0.25);
            --sidebar-bg: rgba(5, 32, 18, 0.95);
            --hover-bg: rgba(34, 197, 94, 0.15);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #052012 0%, #0a2f1d 100%);
            color: var(--text);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 10% 20%, rgba(34, 197, 94, 0.1) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(0, 255, 136, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(30, 141, 92, 0.05) 0%, transparent 60%);
            z-index: -1;
        }
        
        /* Floating elements */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .floating-shape {
            position: absolute;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            opacity: 0.1;
            border-radius: 50%;
            animation: float 20s infinite linear;
        }
        
        .leaf-shape {
            position: absolute;
            background: linear-gradient(135deg, var(--secondary), var(--accent));
            clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
            opacity: 0.15;
            animation: floatLeaf 25s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translateY(0) translateX(0) rotate(0deg); }
            25% { transform: translateY(-100px) translateX(50px) rotate(90deg); }
            50% { transform: translateY(0) translateX(100px) rotate(180deg); }
            75% { transform: translateY(100px) translateX(50px) rotate(270deg); }
            100% { transform: translateY(0) translateX(0) rotate(360deg); }
        }
        
        @keyframes floatLeaf {
            0% { transform: translateY(0) translateX(0) rotate(0deg) scale(1); }
            33% { transform: translateY(-80px) translateX(60px) rotate(120deg) scale(1.2); }
            66% { transform: translateY(80px) translateX(120px) rotate(240deg) scale(0.8); }
            100% { transform: translateY(0) translateX(0) rotate(360deg) scale(1); }
        }
        
        /* Admin Container */
        .admin-container {
            display: flex;
            min-height: 100vh;
            backdrop-filter: blur(10px);
        }
        
        /* Premium Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            box-shadow: 8px 0 30px rgba(0, 0, 0, 0.25);
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 30px 25px;
            border-bottom: 1px solid var(--card-border);
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.2) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .logo-icon::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(255, 255, 255, 0.1) 50%,
                transparent 70%
            );
            transform: rotate(45deg);
            animation: shine 3s infinite;
        }
        
        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        
        .logo-icon i {
            font-size: 24px;
            color: white;
            z-index: 1;
        }
        
        .logo-text {
            flex: 1;
        }
        
        .logo-text h2 {
            font-size: 20px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text-light), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        
        .logo-text span {
            font-size: 12px;
            color: var(--text-muted);
            letter-spacing: 1px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .avatar-container {
            position: relative;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            font-weight: 700;
            border: 3px solid var(--card-border);
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }
        
        .status-indicator {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 14px;
            height: 14px;
            background: var(--success);
            border: 2px solid var(--primary-dark);
            border-radius: 50%;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }
        
        .user-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(0, 255, 136, 0.1));
            border: 1px solid var(--card-border);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
        }
        
        /* Sidebar Navigation */
        .sidebar-nav {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
        }
        
        .sidebar-nav ul {
            list-style: none;
            padding: 0 15px;
        }
        
        .sidebar-nav li {
            margin-bottom: 8px;
            position: relative;
        }
        
        .sidebar-nav li.active::before {
            content: '';
            position: absolute;
            left: -15px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 30px;
            background: linear-gradient(180deg, var(--accent), var(--neon));
            border-radius: 0 2px 2px 0;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(34, 197, 94, 0.1), transparent);
            transition: left 0.6s ease;
        }
        
        .sidebar-nav a:hover::before {
            left: 100%;
        }
        
        .sidebar-nav a:hover {
            background: var(--hover-bg);
            color: var(--text);
            transform: translateX(5px);
        }
        
        .sidebar-nav .active a {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(0, 255, 136, 0.15));
            color: var(--text);
            box-shadow: 0 5px 15px rgba(34, 197, 94, 0.2);
        }
        
        .nav-icon {
            width: 24px;
            text-align: center;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        
        .sidebar-nav .active .nav-icon {
            color: var(--neon);
            transform: scale(1.1);
        }
        
        .nav-badge {
            margin-left: auto;
            padding: 4px 10px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            font-size: 12px;
            font-weight: 700;
            border-radius: 10px;
            min-width: 24px;
            text-align: center;
            box-shadow: 0 3px 8px rgba(34, 197, 94, 0.3);
        }
        
        /* Sidebar Footer */
        .sidebar-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--card-border);
            background: rgba(5, 32, 18, 0.8);
        }
        
        .date-time-widget {
            background: rgba(26, 77, 51, 0.3);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .date-time-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .date-time-item:last-child {
            margin-bottom: 0;
        }
        
        .date-time-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(0, 255, 136, 0.1));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
        }
        
        .date-time-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        
        .date-time-value {
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }
        
        /* Header */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--card-border);
            position: relative;
        }
        
        .content-header::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 120px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
            border-radius: 2px;
        }
        
        .page-title {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .page-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
        }
        
        .page-icon i {
            font-size: 28px;
            color: white;
        }
        
        .content-header h1 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text-light), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -1px;
        }
        
        .header-subtitle {
            color: var(--text-muted);
            font-size: 16px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .greeting-dot {
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        /* Premium Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(20px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 30px;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
        }
        
        .stat-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.05) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
            border-color: var(--accent);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
        }
        
        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: white;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .stat-trend.up {
            color: var(--success);
        }
        
        .stat-trend.down {
            color: var(--error);
        }
        
        .stat-content {
            position: relative;
            z-index: 1;
        }
        
        .stat-value {
            font-size: 42px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text-light), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 10px;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 15px;
        }
        
        .stat-progress {
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .stat-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--neon));
            border-radius: 3px;
            transition: width 1s ease;
        }
        
        /* Content Cards */
        .content-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            margin-bottom: 30px;
            overflow: hidden;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px;
            border-bottom: 1px solid var(--card-border);
        }
        
        .card-header h2 {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
        }
        
        .card-header h2 i {
            color: var(--accent);
        }
        
        .btn-view-all {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-view-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(34, 197, 94, 0.3);
        }
        
        /* Premium Table */
        .table-responsive {
            overflow-x: auto;
            padding: 0 30px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 25px 0;
        }
        
        .data-table thead tr {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(0, 255, 136, 0.05));
        }
        
        .data-table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 700;
            color: var(--text);
            border-bottom: 2px solid var(--card-border);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
        }
        
        .data-table tbody tr:hover {
            background: var(--hover-bg);
        }
        
        .data-table td {
            padding: 20px;
            color: var(--text-light);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.1));
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .status-approved {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(5, 150, 105, 0.1));
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .status-rejected {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(220, 38, 38, 0.1));
            color: var(--error);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-action:hover {
            background: var(--accent);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
        }
        
        .quick-action {
            background: linear-gradient(135deg, rgba(10, 47, 29, 0.8), rgba(6, 78, 59, 0.6));
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 25px;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .quick-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s ease;
        }
        
        .quick-action:hover::before {
            left: 100%;
        }
        
        .quick-action:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 30px rgba(34, 197, 94, 0.25);
        }
        
        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: white;
            margin-bottom: 20px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        
        .quick-action span {
            display: block;
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 10px;
        }
        
        .quick-action p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.5;
        }
        
        /* Investment Summary */
        .investment-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .investment-card {
            background: linear-gradient(135deg, rgba(10, 47, 29, 0.9), rgba(6, 78, 59, 0.8));
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .investment-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.05) 0%, transparent 70%);
        }
        
        .investment-value {
            font-size: 48px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--neon), #22c55e);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 15px;
            line-height: 1;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 250px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .admin-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .sidebar-nav ul {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .sidebar-nav li {
                flex: 1;
                min-width: 160px;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }
            
            .content-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .page-title {
                flex-direction: column;
                text-align: center;
            }
            
            .header-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .stat-value {
                font-size: 36px;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .table-responsive {
                padding: 0 15px;
            }
        }
        
        @media (max-width: 480px) {
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 32px;
            }
            
            .data-table th,
            .data-table td {
                padding: 12px;
                font-size: 12px;
            }
            
            .sidebar-nav li {
                min-width: 100%;
            }
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(10, 47, 29, 0.3);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--accent), var(--neon));
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--neon), var(--accent));
        }

        /* Pagination Styles */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 30px;
    padding: 20px;
    background: rgba(26, 77, 51, 0.3);
    border-radius: 12px;
    border: 1px solid var(--border);
}

.pagination-info {
    color: var(--text-muted);
    font-size: 14px;
}

.pagination-controls {
    display: flex;
    gap: 8px;
    align-items: center;
}

.pagination-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: rgba(26, 77, 51, 0.5);
    color: var(--text);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 1px solid var(--border);
}

.pagination-btn:hover {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
    transform: translateY(-2px);
}

.pagination-btn.active {
    background: linear-gradient(135deg, var(--accent), var(--neon));
    color: white;
    border-color: transparent;
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: rgba(26, 77, 51, 0.2);
}

.pagination-btn.disabled:hover {
    background: rgba(26, 77, 51, 0.2);
    color: var(--text);
    transform: none;
    border-color: var(--border);
}

.page-size-selector {
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-size-selector label {
    color: var(--text-muted);
    font-size: 14px;
}

.page-size-selector select {
    background: rgba(26, 77, 51, 0.5);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: 6px 12px;
    font-size: 14px;
}

.page-size-selector select:focus {
    outline: none;
    border-color: var(--accent);
}

.header-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.header-right .pagination-info {
    color: var(--text-muted);
    font-size: 13px;
    background: rgba(34, 197, 94, 0.1);
    padding: 4px 12px;
    border-radius: 20px;
    border: 1px solid rgba(34, 197, 94, 0.2);
}

/* Responsive pagination */
@media (max-width: 768px) {
    .pagination-container {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .pagination-controls {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .header-right {
        flex-direction: column;
        gap: 10px;
        align-items: flex-end;
    }
}
    </style>
</head>
<body>
    <!-- Floating Background Elements -->
    <div class="floating-shapes">
        <div class="floating-shape" style="width: 100px; height: 100px; top: 10%; left: 5%; animation-delay: 0s;"></div>
        <div class="floating-shape" style="width: 150px; height: 150px; top: 70%; left: 85%; animation-delay: 2s;"></div>
        <div class="floating-shape" style="width: 80px; height: 80px; top: 30%; left: 90%; animation-delay: 4s;"></div>
        <div class="leaf-shape" style="width: 40px; height: 40px; top: 20%; left: 80%; animation-delay: 1s;"></div>
        <div class="leaf-shape" style="width: 60px; height: 60px; top: 60%; left: 15%; animation-delay: 3s;"></div>
    </div>
    
    <div class="admin-container">
        <!-- Premium Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <div class="logo-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <div class="logo-text">
                        <h2>Serendib Green Plantation</h2>
                        <span>ADMIN PORTAL</span>
                    </div>
                </div>
                
                <div class="user-profile">
                    <div class="avatar-container">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 1)); ?>
                        </div>
                        <div class="status-indicator"></div>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                        <div class="user-role">
                            <i class="fas fa-user-tag"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $userType)); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li class="active">
                        <a href="dashboard.php">
                            <i class="nav-icon fas fa-tachometer-alt-fast"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="applications.php">
                            <i class="nav-icon fas fa-file-alt"></i>
                            <span>Applications</span>
                            <?php if ($stats['pending'] > 0): ?>
                                <span class="nav-badge"><?php echo $stats['pending']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="send_email.php">
                            <i class="nav-icon fas fa-envelope"></i>
                            <span>Send Email</span>
                        </a>
                    </li>
                    <li>
                        <a href="reports.php">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <span>Reports</span>
                        </a>
                    </li>
                    <li>
    <a href="agreement_templates.php">
        <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
        <span>Agreement Templates</span>
    </a>
</li>
<li>
    <a href="agreements.php">
        <span class="nav-icon"><i class="fas fa-file-contract"></i></span>
        <span>Agreements</span>
    </a>
</li>
                    <li>
                        <a href="users.php">
                            <i class="nav-icon fas fa-users"></i>
                            <span>Users</span>
                        </a>
                    </li>
                    <li>
                        <a href="settings.php">
                            <i class="nav-icon fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                    <li>
                        <a href="logout.php" style="color: #ef4444;">
                            <i class="nav-icon fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <div class="sidebar-footer">
                <div class="date-time-widget">
                    <div class="date-time-item">
                        <div class="date-time-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div>
                            <div class="date-time-label">Today's Date</div>
                            <div class="date-time-value" id="currentDate"><?php echo $currentDate; ?></div>
                        </div>
                    </div>
                    <div class="date-time-item">
                        <div class="date-time-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div class="date-time-label">Current Time</div>
                            <div class="date-time-value" id="currentTime"><?php echo $currentTime; ?></div>
                        </div>
                    </div>
                    <div class="date-time-item">
                        <div class="date-time-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div>
                            <div class="date-time-label">Status</div>
                            <div class="date-time-value" style="color: var(--success);">Active</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="content-header">
                <div class="page-title">
                    <div class="page-icon">
                        <i class="fas fa-tachometer-alt-fast"></i>
                    </div>
                    <div>
                        <h1>Dashboard Overview</h1>
                        <div class="header-subtitle">
                            <span class="greeting-dot"></span>
                            <span><?php echo getGreeting(); ?>, <?php echo htmlspecialchars($username); ?>!</span>
                        </div>
                    </div>
                </div>
                
                <div class="header-actions">
                    <button class="btn-view-all" onclick="exportData()">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                </div>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> 12%
                        </div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Applications</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 85%"></div>
                        </div>
                    </div>
                </div>

                
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-trend">
                            <i class="fas fa-exclamation-circle"></i> Review
                        </div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">Pending Review</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 45%"></div>
                        </div>
                    </div>
                </div>
                 <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-trend">
                            <i class="fas fa-exclamation-circle"></i> Review
                        </div>
                    </div>
                    <div class="stat-content">
                         <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                        <div class="stat-label">In Progress</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 45%"></div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> 8%
                        </div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['approved']; ?></div>
                        <div class="stat-label">Approved</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 65%"></div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-trend down">
                            <i class="fas fa-arrow-down"></i> 3%
                        </div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['rejected']; ?></div>
                        <div class="stat-label">Rejected</div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 15%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
           <!-- Recent Applications -->
<div class="content-card">
    <div class="card-header">
        <h2><i class="fas fa-history"></i> Recent Applications</h2>
        <div class="header-right">
            <span class="pagination-info">
                Showing <?php echo $offset + 1; ?> - 
                <?php echo min($offset + $recordsPerPage, $totalApplications); ?> 
                of <?php echo $totalApplications; ?> applications
            </span>
            <a href="applications.php" class="btn-view-all">View All</a>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>NIC No</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentApplications)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px;">
                            <i class="fas fa-folder-open" style="font-size: 48px; color: var(--text-muted); opacity: 0.5; margin-bottom: 15px; display: block;"></i>
                            <p style="color: var(--text-muted);">No applications found</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentApplications as $application): ?>
                        <?php
                        $statusClass = 'status-pending';
                        if ($application['status'] === 'approved') $statusClass = 'status-approved';
                        if ($application['status'] === 'rejected') $statusClass = 'status-rejected';
                        if ($application['status'] === 'in_progress') $statusClass = 'status-in-progress';
                        ?>
                        <tr>
                            <td><strong>EWF-<?php echo str_pad($application['id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($application['full_name']); ?></td>
                            <td>
                                <a href="mailto:<?php echo htmlspecialchars($application['email']); ?>" 
                                   style="color: var(--accent); text-decoration: none;">
                                    <?php echo htmlspecialchars($application['email']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($application['nic_no']); ?></td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $application['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo $application['formatted_date']; ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view_user.php?id=<?php echo $application['id']; ?>" class="btn-action" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="send_email.php?application_id=<?php echo $application['id']; ?>" class="btn-action" title="Send Email">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                    <a href="javascript:void(0)" onclick="downloadApplication(<?php echo $application['id']; ?>)" class="btn-action" title="Download">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-info">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
        </div>
        <div class="pagination-controls">
            <!-- First Page -->
            <?php if ($page > 1): ?>
                <a href="?page=1" class="pagination-btn" title="First Page">
                    <i class="fas fa-angle-double-left"></i>
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">
                    <i class="fas fa-angle-double-left"></i>
                </span>
            <?php endif; ?>
            
            <!-- Previous Page -->
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>" class="pagination-btn" title="Previous">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">
                    <i class="fas fa-angle-left"></i>
                </span>
            <?php endif; ?>
            
            <!-- Page Numbers -->
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
                <?php if ($i == $page): ?>
                    <span class="pagination-btn active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?page=<?php echo $i; ?>" class="pagination-btn"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <!-- Next Page -->
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>" class="pagination-btn" title="Next">
                    <i class="fas fa-angle-right"></i>
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">
                    <i class="fas fa-angle-right"></i>
                </span>
            <?php endif; ?>
            
            <!-- Last Page -->
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $totalPages; ?>" class="pagination-btn" title="Last Page">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php else: ?>
                <span class="pagination-btn disabled">
                    <i class="fas fa-angle-double-right"></i>
                </span>
            <?php endif; ?>
        </div>
        
        <!-- Page Size Selector -->
        <div class="page-size-selector">
            <label for="pageSize">Show:</label>
            <select id="pageSize" onchange="changePageSize(this.value)">
                <option value="10" <?php echo $recordsPerPage == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $recordsPerPage == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $recordsPerPage == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $recordsPerPage == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <?php endif; ?>
</div>
            
            <!-- Quick Actions -->
            <div class="content-card">
                <div class="card-header">
                    <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="applications.php?filter=pending" class="quick-action">
                        <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <span>Review Pending</span>
                        <p>Review and process pending applications</p>
                    </a>
                    
                    <a href="send_email.php" class="quick-action">
                        <div class="action-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <span>Send Email</span>
                        <p>Send notifications to investors</p>
                    </a>
                    
                    <a href="javascript:void(0)" onclick="exportData()" class="quick-action">
                        <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-file-export"></i>
                        </div>
                        <span>Export Data</span>
                        <p>Export application data for analysis</p>
                    </a>
                    
                    <a href="reports.php" class="quick-action">
                        <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <span>Generate Report</span>
                        <p>Create detailed reports and analytics</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Update time and date
        function updateDateTime() {
            const now = new Date();
            
            // Format date
            const dateOptions = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            };
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            
            // Format time
            const timeOptions = { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true 
            };
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateDateTime();
            setInterval(updateDateTime, 60000); // Update every minute
            
            // Add animation to stats cards on load
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'all 0.6s ease';
                    
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 100);
            });
        });
        
        function downloadApplication(id) {
            window.location.href = 'download_application.php?id=' + id;
        }
        
        function exportData() {
            alert('Export feature coming soon!');
        }
        
        // Auto-refresh dashboard every 5 minutes
        setTimeout(() => {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>