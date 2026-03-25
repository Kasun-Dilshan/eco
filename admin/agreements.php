<?php
require_once '../config.php';
require_once '../db.php';

session_start();



// Initialize variables
$message = '';
$messageType = '';
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_agreement'])) {
        $agreementId = $_POST['agreement_id'] ?? 0;
        $adminName = $_SESSION['admin_name'] ?? 'Admin';
        
        if ($agreementId) {
            try {
                // Get agreement info before deletion
                $stmt = $db->prepare("
                    SELECT a.*, i.full_name, i.nic_no 
                    FROM agreements a 
                    JOIN investors i ON a.investor_id = i.id 
                    WHERE a.id = ?
                ");
                $stmt->execute([$agreementId]);
                $agreement = $stmt->fetch();
                
                if ($agreement) {
                    // Delete the agreement
                    $deleteStmt = $db->prepare("DELETE FROM agreements WHERE id = ?");
                    $deleteStmt->execute([$agreementId]);
                    
                    if ($deleteStmt->rowCount() > 0) {
                        $message = "Agreement deleted successfully!";
                        $messageType = 'success';
                        
                        // Log the deletion
                        $logStmt = $db->prepare("
                            INSERT INTO agreement_logs (agreement_id, action, description, performed_by)
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        $logStmt->execute([
                            $agreementId,
                            'agreement_deleted',
                            'Deleted agreement ' . $agreement['agreement_number'] . ' for ' . $agreement['full_name'],
                            $adminName
                        ]);
                    } else {
                        $message = "Failed to delete agreement!";
                        $messageType = 'error';
                    }
                } else {
                    $message = "Agreement not found!";
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif (isset($_POST['update_status'])) {
        $agreementId = $_POST['agreement_id'] ?? 0;
        $newStatus = $_POST['status'] ?? '';
        $adminName = $_SESSION['admin_name'] ?? 'Admin';
        
        if ($agreementId && $newStatus) {
            try {
                // Get agreement info
                $stmt = $db->prepare("
                    SELECT a.*, i.full_name, i.nic_no 
                    FROM agreements a 
                    JOIN investors i ON a.investor_id = i.id 
                    WHERE a.id = ?
                ");
                $stmt->execute([$agreementId]);
                $agreement = $stmt->fetch();
                
                if ($agreement) {
                    // Update status
                    $updateStmt = $db->prepare("UPDATE agreements SET status = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$newStatus, $agreementId]);
                    
                    if ($updateStmt->rowCount() > 0) {
                        $message = "Agreement status updated to " . ucfirst($newStatus) . "!";
                        $messageType = 'success';
                        
                        // Log the status change
                        $logStmt = $db->prepare("
                            INSERT INTO agreement_logs (agreement_id, action, description, performed_by)
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        $logStmt->execute([
                            $agreementId,
                            'status_updated',
                            'Changed status to ' . $newStatus . ' for agreement ' . $agreement['agreement_number'],
                            $adminName
                        ]);
                    } else {
                        $message = "Failed to update status!";
                        $messageType = 'error';
                    }
                } else {
                    $message = "Agreement not found!";
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif (isset($_POST['send_reminder'])) {
        $agreementId = $_POST['agreement_id'] ?? 0;
        $adminName = $_SESSION['admin_name'] ?? 'Admin';
        
        if ($agreementId) {
            try {
                // Get agreement and investor info
                $stmt = $db->prepare("
                    SELECT a.*, i.full_name, i.email, i.tel_no 
                    FROM agreements a 
                    JOIN investors i ON a.investor_id = i.id 
                    WHERE a.id = ?
                ");
                $stmt->execute([$agreementId]);
                $agreement = $stmt->fetch();
                
                if ($agreement) {
                    // Send email reminder (simulated)
                    $subject = "Reminder: Investment Agreement Pending Signature - " . $agreement['agreement_number'];
                    $body = "Dear " . $agreement['full_name'] . ",\n\n";
                    $body .= "This is a reminder that your investment agreement " . $agreement['agreement_number'];
                    $body .= " is pending your signature.\n\n";
                    $body .= "Please sign the agreement at your earliest convenience.\n\n";
                    $body .= "Best regards,\nEcoWealth Finance Team";
                    
                    // In real implementation, send actual email
                    $message = "Reminder sent to " . $agreement['email'] . "!";
                    $messageType = 'success';
                    
                    // Log the reminder
                    $logStmt = $db->prepare("
                        INSERT INTO agreement_logs (agreement_id, action, description, performed_by)
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    $logStmt->execute([
                        $agreementId,
                        'reminder_sent',
                        'Sent reminder email for agreement ' . $agreement['agreement_number'],
                        $adminName
                    ]);
                } else {
                    $message = "Agreement not found!";
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get agreements with filters
try {
    // Build query with filters
    $query = "
        SELECT a.*, 
               i.full_name, i.nic_no, i.email, i.tel_no, i.investment_type,
               DATE_FORMAT(a.generated_at, '%M %d, %Y %H:%i') as formatted_generated,
               DATE_FORMAT(a.signed_at, '%M %d, %Y %H:%i') as formatted_signed,
               CASE 
                   WHEN DATEDIFF(NOW(), a.generated_at) > 30 AND a.status = 'draft' THEN 'expired'
                   ELSE a.status
               END as display_status
        FROM agreements a 
        JOIN investors i ON a.investor_id = i.id 
        WHERE 1=1
    ";
    
    $params = [];
    
    // Add search filter
    if (!empty($search)) {
        $query .= " AND (
            a.agreement_number LIKE ? OR 
            i.full_name LIKE ? OR 
            i.nic_no LIKE ? OR 
            i.email LIKE ?
        )";
        $searchParam = "%$search%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
    }
    
    // Add status filter
    if (!empty($status) && in_array($status, ['draft', 'sent', 'signed', 'expired'])) {
        if ($status === 'expired') {
            $query .= " AND a.status = 'draft' AND DATEDIFF(NOW(), a.generated_at) > 30";
        } else {
            $query .= " AND a.status = ?";
            $params[] = $status;
        }
    }
    
    // Add ordering and pagination
    $query .= " ORDER BY a.generated_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    // Execute query
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $agreements = $stmt->fetchAll();
    
    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM agreements a 
        JOIN investors i ON a.investor_id = i.id 
        WHERE 1=1
    ";
    
    $countParams = [];
    
    if (!empty($search)) {
        $countQuery .= " AND (
            a.agreement_number LIKE ? OR 
            i.full_name LIKE ? OR 
            i.nic_no LIKE ? OR 
            i.email LIKE ?
        )";
        $searchParam = "%$search%";
        $countParams = array_merge($countParams, [$searchParam, $searchParam, $searchParam, $searchParam]);
    }
    
    if (!empty($status) && in_array($status, ['draft', 'sent', 'signed', 'expired'])) {
        if ($status === 'expired') {
            $countQuery .= " AND a.status = 'draft' AND DATEDIFF(NOW(), a.generated_at) > 30";
        } else {
            $countQuery .= " AND a.status = ?";
            $countParams[] = $status;
        }
    }
    
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalResult = $countStmt->fetch();
    $totalAgreements = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalAgreements / $limit);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Function to get status badge color
function getStatusBadge($status) {
    $badges = [
        'draft' => 'warning',
        'sent' => 'info',
        'signed' => 'success',
        'expired' => 'error'
    ];
    return $badges[$status] ?? 'secondary';
}

// Function to get investment type name
function getInvestmentTypeName($type) {
    $types = [
        'HPP' => 'High profit plan',
        'GSP' => 'Green saving plan',
        'GSI' => 'Green silver plan',
        'GOLD' => 'Gold plan',
        'SFPS' => 'Seraa farm profit share plan',
        'SFHPS' => 'Seraa farm high profit share plan'
    ];
    return $types[$type] ?? 'Unknown';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Agreements | EcoWealth Admin</title>
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
            --sidebar-bg: rgba(10, 47, 29, 0.95);
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
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(34, 197, 94, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 255, 136, 0.08) 0%, transparent 50%);
            z-index: -1;
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
            backdrop-filter: blur(10px);
        }
        
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border);
            padding: 25px 0;
            position: sticky;
            top: 0;
            height: 100vh;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2);
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 0 25px 25px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 25px;
            position: relative;
        }
        
        .sidebar-header::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 25px;
            width: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
            border-radius: 2px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }
        
        .logo-icon i {
            font-size: 20px;
            color: white;
        }
        
        .sidebar-header h2 {
            color: var(--neon);
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .sidebar-nav ul {
            list-style: none;
            padding: 0 15px;
        }
        
        .sidebar-nav li {
            margin-bottom: 5px;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 14px 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 10px;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-nav a:hover {
            background: var(--hover-bg);
            color: var(--text);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.15);
        }
        
        .sidebar-nav .active a {
            background: linear-gradient(90deg, rgba(34, 197, 94, 0.15), rgba(0, 255, 136, 0.1));
            color: var(--text);
            border-left: 4px solid var(--accent);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.2);
        }
        
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }
        
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
            position: relative;
        }
        
        .content-header::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
            border-radius: 2px;
        }
        
        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.3);
        }
        
        .page-icon i {
            font-size: 24px;
            color: white;
        }
        
        .content-header h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -1px;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
        }
        
        .btn-secondary {
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid var(--border);
            backdrop-filter: blur(10px);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.2);
        }
        
        .alert {
            padding: 18px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid transparent;
            backdrop-filter: blur(10px);
            animation: slideDown 0.5s ease;
        }
        
        .alert.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.1));
            border-color: var(--success);
            border-left: 5px solid var(--success);
        }
        
        .alert.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            border-color: var(--error);
            border-left: 5px solid var(--error);
        }
        
        .filters {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }
        
        .filters h3 {
            color: var(--neon);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 12px 16px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border-color: var(--accent);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
            color: white;
        }
        
        .stat-card.draft .stat-icon { background: linear-gradient(135deg, var(--warning), #d97706); }
        .stat-card.sent .stat-icon { background: linear-gradient(135deg, var(--info), #1d4ed8); }
        .stat-card.signed .stat-icon { background: linear-gradient(135deg, var(--success), #059669); }
        .stat-card.expired .stat-icon { background: linear-gradient(135deg, var(--error), #dc2626); }
        
        .stat-number {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .agreements-table {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 15px;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .table-header {
            display: grid;
            grid-template-columns: 1fr 2fr 2fr 1fr 1fr 2fr 1fr;
            padding: 20px;
            background: rgba(26, 77, 51, 0.5);
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            color: var(--text);
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 1fr 2fr 2fr 1fr 1fr 2fr 1fr;
            padding: 20px;
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .table-row:hover {
            background: var(--hover-bg);
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge.draft { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .badge.sent { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .badge.signed { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .badge.expired { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        
        .action-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .action-btn {
            background: none;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
            color: var(--text);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            background: var(--hover-bg);
            border-color: var(--accent);
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            min-width: 200px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            z-index: 1;
        }
        
        .dropdown-content.show {
            display: block;
        }
        
        .dropdown-item {
            display: block;
            padding: 12px 20px;
            color: var(--text);
            text-decoration: none;
            transition: all 0.3s ease;
            border-bottom: 1px solid var(--border);
        }
        
        .dropdown-item:last-child {
            border-bottom: none;
        }
        
        .dropdown-item:hover {
            background: var(--hover-bg);
            color: var(--accent);
        }
        
        .dropdown-item.delete {
            color: var(--error);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            padding: 20px;
        }
        
        .page-link {
            padding: 8px 16px;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            background: var(--hover-bg);
            border-color: var(--accent);
        }
        
        .page-link.active {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            border-color: var(--accent);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        
        .empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 1200px) {
            .table-header,
            .table-row {
                grid-template-columns: 1fr 2fr 1fr 1fr 2fr 1fr;
            }
            .table-header div:nth-child(4),
            .table-row div:nth-child(4) {
                display: none;
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
                padding: 20px;
            }
            
            .table-header,
            .table-row {
                grid-template-columns: 1fr 2fr 1fr 2fr 1fr;
            }
            .table-header div:nth-child(3),
            .table-row div:nth-child(3),
            .table-header div:nth-child(5),
            .table-row div:nth-child(5) {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .table-header,
            .table-row {
                grid-template-columns: 1fr 2fr 1fr;
            }
            .table-header div:nth-child(2),
            .table-row div:nth-child(2),
            .table-header div:nth-child(4),
            .table-row div:nth-child(4),
            .table-header div:nth-child(6),
            .table-row div:nth-child(6) {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <div class="logo-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h2>EcoWealth<br>Admin</h2>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="dashboard.php">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="applications.php">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span>Applications</span>
                        </a>
                    </li>
                    <li class="active">
                        <a href="agreements.php">
                            <span class="nav-icon"><i class="fas fa-file-contract"></i></span>
                            <span>Agreements</span>
                        </a>
                    </li>
                    <li>
                        <a href="reports.php">
                            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                            <span>Reports</span>
                        </a>
                    </li>
                    <li>
                        <a href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span>Settings</span>
                        </a>
                    </li>
                    <li>
                        <a href="logout.php" style="color: #ef4444;">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="content-header">
                <div class="page-title">
                    <div class="page-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <div>
                        <h1>Manage Agreements</h1>
                        <p style="color: var(--text-muted); font-size: 14px;">View and manage investment agreements</p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <a href="applications.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Applications
                    </a>
                    <a href="find_application.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Agreement
                    </a>
                </div>
            </div>
            
            <!-- Display Messages -->
            <?php if ($message): ?>
                <div class="alert <?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>" style="font-size: 20px;"></i>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card draft">
                    <div class="stat-icon">
                        <i class="fas fa-file"></i>
                    </div>
                    <div class="stat-number">
                        <?php 
                        $draftCount = getAgreementCount($db, 'draft');
                        echo $draftCount;
                        ?>
                    </div>
                    <div class="stat-label">Draft Agreements</div>
                </div>
                
                <div class="stat-card sent">
                    <div class="stat-icon">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div class="stat-number">
                        <?php 
                        $sentCount = getAgreementCount($db, 'sent');
                        echo $sentCount;
                        ?>
                    </div>
                    <div class="stat-label">Sent Agreements</div>
                </div>
                
                <div class="stat-card signed">
                    <div class="stat-icon">
                        <i class="fas fa-signature"></i>
                    </div>
                    <div class="stat-number">
                        <?php 
                        $signedCount = getAgreementCount($db, 'signed');
                        echo $signedCount;
                        ?>
                    </div>
                    <div class="stat-label">Signed Agreements</div>
                </div>
                
                <div class="stat-card expired">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number">
                        <?php 
                        $expiredCount = getExpiredAgreementCount($db);
                        echo $expiredCount;
                        ?>
                    </div>
                    <div class="stat-label">Expired Agreements</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters">
                <h3><i class="fas fa-filter"></i> Filter Agreements</h3>
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search"><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="search" name="search" placeholder="Search by agreement number, name, NIC, or email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="status"><i class="fas fa-tag"></i> Status</label>
                            <select id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="sent" <?php echo $status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                                <option value="signed" <?php echo $status === 'signed' ? 'selected' : ''; ?>>Signed</option>
                                <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="agreements.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Agreements Table -->
            <div class="agreements-table">
                <?php if (!empty($agreements)): ?>
                    <div class="table-header">
                        <div>Agreement No.</div>
                        <div>Investor</div>
                        <div>Investment Type</div>
                        <div>Years</div>
                        <div>Status</div>
                        <div>Generated</div>
                        <div>Actions</div>
                    </div>
                    
                    <?php foreach ($agreements as $agreement): ?>
                    <div class="table-row">
                        <div>
                            <strong><?php echo htmlspecialchars($agreement['agreement_number']); ?></strong><br>
                            <small style="color: var(--text-muted); font-size: 12px;">ID: <?php echo $agreement['id']; ?></small>
                        </div>
                        <div>
                            <strong><?php echo htmlspecialchars($agreement['full_name']); ?></strong><br>
                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($agreement['nic_no']); ?></small><br>
                            <small style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars($agreement['email']); ?></small>
                        </div>
                        <div>
                            <?php echo getInvestmentTypeName($agreement['investment_type']); ?>
                        </div>
                        <div>
                            <?php 
                            // Get years from investor table
                            $yearsStmt = $db->prepare("SELECT years FROM investors WHERE id = ?");
                            $yearsStmt->execute([$agreement['investor_id']]);
                            $years = $yearsStmt->fetchColumn();
                            echo $years ? $years . ' years' : 'N/A';
                            ?>
                        </div>
                        <div>
                            <span class="badge <?php echo getStatusBadge($agreement['display_status']); ?>">
                                <i class="fas fa-<?php echo $agreement['display_status'] === 'signed' ? 'check-circle' : ($agreement['display_status'] === 'expired' ? 'clock' : 'file'); ?>"></i>
                                <?php echo ucfirst($agreement['display_status']); ?>
                            </span>
                        </div>
                        <div>
                            <?php echo $agreement['formatted_generated']; ?><br>
                            <?php if ($agreement['signed_at']): ?>
                                <small style="color: var(--success); font-size: 12px;">
                                    <i class="fas fa-signature"></i> Signed: <?php echo $agreement['formatted_signed']; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="action-dropdown">
                                <button class="action-btn" onclick="toggleDropdown(this)">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-content">
                                    <a href="view_agreement.php?id=<?php echo $agreement['id']; ?>" class="dropdown-item">
                                        <i class="fas fa-eye"></i> View Agreement
                                    </a>
                                    <a href="download_agreement.php?id=<?php echo $agreement['id']; ?>" class="dropdown-item">
                                        <i class="fas fa-download"></i> Download PDF
                                    </a>
                                    <?php if ($agreement['display_status'] === 'draft' || $agreement['display_status'] === 'sent'): ?>
                                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Send reminder email to investor?')">
                                            <input type="hidden" name="agreement_id" value="<?php echo $agreement['id']; ?>">
                                            <button type="submit" name="send_reminder" class="dropdown-item">
                                                <i class="fas fa-envelope"></i> Send Reminder
                                            </button>
                                        </form>
                                        <div class="dropdown-item" onclick="showStatusModal(<?php echo $agreement['id']; ?>)">
                                            <i class="fas fa-edit"></i> Change Status
                                        </div>
                                    <?php endif; ?>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this agreement?')">
                                        <input type="hidden" name="agreement_id" value="<?php echo $agreement['id']; ?>">
                                        <button type="submit" name="delete_agreement" class="dropdown-item delete">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <h3>No Agreements Found</h3>
                        <p>No agreements match your search criteria.</p>
                        <a href="agreements.php" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-sync"></i> Reset Filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                        <span class="page-link">...</span>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Status Update Modal -->
    <div id="statusModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--card-bg); border-radius: 15px; padding: 30px; max-width: 400px; width: 90%; border: 1px solid var(--border); backdrop-filter: blur(10px);">
            <h3 style="color: var(--neon); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-edit"></i> Change Agreement Status
            </h3>
            <form method="POST" action="" id="statusForm">
                <input type="hidden" name="agreement_id" id="modalAgreementId">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--text);">New Status</label>
                    <select name="status" style="width: 100%; padding: 12px; background: rgba(26, 77, 51, 0.7); border: 1px solid var(--border); border-radius: 8px; color: var(--text);">
                        <option value="draft">Draft</option>
                        <option value="sent">Sent</option>
                        <option value="signed">Signed</option>
                    </select>
                </div>
                <div style="display: flex; gap: 15px; margin-top: 25px;">
                    <button type="button" onclick="hideStatusModal()" class="btn btn-secondary" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="update_status" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-save"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Toggle dropdown
        function toggleDropdown(button) {
            const dropdown = button.nextElementSibling;
            dropdown.classList.toggle('show');
            
            // Close other dropdowns
            document.querySelectorAll('.dropdown-content').forEach(drop => {
                if (drop !== dropdown) {
                    drop.classList.remove('show');
                }
            });
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.action-dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
            }
        });
        
        // Status modal functions
        function showStatusModal(agreementId) {
            document.getElementById('modalAgreementId').value = agreementId;
            document.getElementById('statusModal').style.display = 'flex';
        }
        
        function hideStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        document.getElementById('statusModal').addEventListener('click', function(event) {
            if (event.target === this) {
                hideStatusModal();
            }
        });
        
        // Auto-hide success messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert.success');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>

<?php
// Helper functions
function getAgreementCount($db, $status) {
    try {
        if ($status === 'expired') {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM agreements 
                WHERE status = 'draft' AND DATEDIFF(NOW(), generated_at) > 30
            ");
            $stmt->execute();
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM agreements WHERE status = ?");
            $stmt->execute([$status]);
        }
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function getExpiredAgreementCount($db) {
    return getAgreementCount($db, 'expired');
}

// Create agreement_logs table if it doesn't exist (run once)
function createAgreementLogsTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS agreement_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agreement_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                description TEXT,
                performed_by VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_agreement_id (agreement_id),
                INDEX idx_action (action)
            )
        ");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Create the table if it doesn't exist
createAgreementLogsTable($db);
?>