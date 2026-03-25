<?php
require_once '../config.php';
require_once '../db.php';

// Check if admin is logged in

$userId = $_GET['id'] ?? 0;
if (!$userId) {
    header('Location: users.php');
    exit();
}

$error = '';
$success = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $newStatus = $_POST['status'] ?? '';
        $adminNotes = $_POST['admin_notes'] ?? '';
        $adminName = $_SESSION['admin_name'] ?? 'Admin';
        
        // Validate status
        $allowedStatuses = ['pending', 'reviewed', 'approved', 'rejected'];
        if (!in_array($newStatus, $allowedStatuses)) {
            $error = "Invalid status selected.";
        } else {
            try {
                $db->beginTransaction();
                
                // Update user status and notes
                $updateStmt = $db->prepare("
                    UPDATE investors 
                    SET status = :status, 
                        admin_notes = :admin_notes, 
                        reviewed_at = NOW(), 
                        reviewed_by = :reviewed_by,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                
                $updateStmt->execute([
                    ':status' => $newStatus,
                    ':admin_notes' => $adminNotes,
                    ':reviewed_by' => $adminName,
                    ':id' => $userId
                ]);
                
                // Log the status change
                $logStmt = $db->prepare("
                    INSERT INTO application_logs (investor_id, action, description, performed_by)
                    VALUES (:investor_id, :action, :description, :performed_by)
                ");
                
                $logStmt->execute([
                    ':investor_id' => $userId,
                    ':action' => 'status_updated',
                    ':description' => "Status changed to " . ucfirst($newStatus) . ". Notes: " . ($adminNotes ?: 'No notes'),
                    ':performed_by' => $adminName
                ]);
                
                $db->commit();
                
                $success = "User status updated successfully to: " . ucfirst($newStatus);
                
                // Refresh the page to show updated data
                header("Refresh: 2; url=view_user.php?id=" . $userId);
                
            } catch (PDOException $e) {
                $db->rollBack();
                $error = "Failed to update status: " . $e->getMessage();
            }
        }
    }
}

try {
    // Get user details
    $stmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created,
               DATE_FORMAT(updated_at, '%M %d, %Y %H:%i') as formatted_updated,
               DATE_FORMAT(reviewed_at, '%M %d, %Y %H:%i') as formatted_reviewed,
               DATE_FORMAT(dob, '%M %d, %Y') as formatted_dob,
               DATE_FORMAT(signing_date, '%M %d, %Y') as formatted_signing,
               DATE_FORMAT(declaration_date, '%M %d, %Y') as formatted_declaration
        FROM investors 
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: users.php');
        exit();
    }
    
    // Get beneficiaries
    $stmt = $db->prepare("SELECT * FROM beneficiaries WHERE investor_id = ? ORDER BY percentage DESC");
    $stmt->execute([$userId]);
    $beneficiaries = $stmt->fetchAll();
    
    // Get application logs
    $stmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created
        FROM application_logs 
        WHERE investor_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll();
    
    // Get approver documents
    $stmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(uploaded_at, '%M %d, %Y %H:%i') as formatted_uploaded
        FROM approver_documents 
        WHERE investor_id = ? 
        ORDER BY approver_level, uploaded_at DESC
    ");
    $stmt->execute([$userId]);
    $approverDocs = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details | Serendib Green Plantation Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --accent-glow: rgba(34, 197, 94, 0.4);
            --neon: #00ff88;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
            --card-bg: rgba(10, 47, 29, 0.8);
            --card-border: rgba(34, 197, 94, 0.2);
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
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--card-border);
        }
        
        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-title h1 {
            font-size: 32px;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin: 0;
        }
        
        .back-btn {
            background: var(--card-bg);
            color: var(--text);
            border: 1px solid var(--card-border);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .back-btn:hover {
            background: rgba(34, 197, 94, 0.1);
            transform: translateY(-2px);
        }
        
        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }
        
        .alert.success {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid var(--success);
        }
        
        .alert.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--error);
        }
        
        /* User Profile Header */
        .user-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 2px solid var(--card-border);
            position: relative;
            overflow: hidden;
        }
        
        .user-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
        }
        
        .user-id {
            font-size: 24px;
            font-weight: 800;
            color: var(--neon);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            color: white;
            margin-bottom: 15px;
            border: 3px solid white;
            box-shadow: 0 0 20px var(--accent-glow);
        }
        
        .user-status {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.5px;
            margin-left: 15px;
        }
        
        .status-pending { background: var(--warning); color: white; }
        .status-reviewed { background: var(--info); color: white; }
        .status-approved { background: var(--success); color: white; }
        .status-rejected { background: var(--error); color: white; }
        
        .user-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 15px;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Cards */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 15px;
            padding: 25px;
            backdrop-filter: blur(10px);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--card-border);
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-title i {
            font-size: 22px;
        }
        
        /* Approver Documents Section */
        .approver-docs {
            margin-top: 30px;
        }
        
        .approver-doc-item {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .approver-doc-item:hover {
            background: rgba(34, 197, 94, 0.1);
            transform: translateX(5px);
        }
        
        .doc-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .doc-level {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .doc-details {
            flex: 1;
        }
        
        .doc-title {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 5px;
        }
        
        .doc-meta {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .doc-actions {
            display: flex;
            gap: 10px;
        }
        
        /* Info Tables */
        .info-table {
            width: 100%;
        }
        
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            flex: 0 0 200px;
            font-weight: 600;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .info-value {
            flex: 1;
            color: var(--text);
            font-weight: 500;
        }
        
        /* Beneficiaries Grid */
        .beneficiaries-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .beneficiary-card {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .beneficiary-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        
        .beneficiary-percentage {
            display: inline-block;
            padding: 5px 12px;
            background: var(--accent);
            color: white;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        /* Files List */
        .files-list {
            list-style: none;
        }
        
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: rgba(26, 77, 51, 0.3);
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .file-item:hover {
            background: rgba(34, 197, 94, 0.1);
            transform: translateX(5px);
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .file-icon {
            width: 40px;
            height: 40px;
            background: rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-icon:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, var(--accent), transparent);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
            padding-left: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--accent);
            border: 3px solid var(--primary);
            box-shadow: 0 0 10px var(--accent-glow);
        }
        
        .timeline-date {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        
        .timeline-action {
            font-weight: 700;
            color: var(--text);
            margin-bottom: 5px;
            text-transform: capitalize;
        }
        
        .timeline-desc {
            font-size: 14px;
            color: var(--text-muted);
        }
        
        /* Status Form */
        .status-form {
            margin-top: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
        }
        
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, var(--accent), var(--neon));
            color: white;
            width: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(34, 197, 94, 0.4);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            flex: 1;
            min-width: 200px;
            padding: 15px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-pdf {
            background: linear-gradient(90deg, #dc2626, #ef4444);
            color: white;
        }
        
        .btn-pdf:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(220, 38, 38, 0.4);
        }
        
        .btn-email {
            background: linear-gradient(90deg, #3b82f6, #1d4ed8);
            color: white;
        }
        
        .btn-email:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.4);
        }
        
        .btn-download {
            background: linear-gradient(90deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .btn-download:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(139, 92, 246, 0.4);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Animations */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .info-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .info-label {
                flex: none;
            }
            
            .beneficiaries-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                min-width: auto;
            }
            
            .approver-doc-item {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .doc-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                </div>
                <div>
                    <h1>Investor Details</h1>
                    <p>Complete Investor profile and application information</p>
                </div>
            </div>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <!-- Display Messages -->
        <?php if ($success): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- User Header -->
        <div class="user-header">
            <div class="user-id">
                <span>ID: EWF-<?php echo str_pad($userId, 6, '0', STR_PAD_LEFT); ?></span>
                <span class="user-status status-<?php echo $user['status']; ?>">
                    <?php echo ucfirst($user['status']); ?>
                </span>
            </div>
            
            <h2 style="font-size: 28px; margin-bottom: 5px;"><?php echo htmlspecialchars($user['full_name']); ?></h2>
            <p style="color: var(--text-muted); margin-bottom: 15px;">
                <?php echo htmlspecialchars($user['name_with_initials']); ?>
            </p>
            
            <div class="user-meta">
                <div class="meta-item">
                    <i class="fas fa-id-card"></i>
                    <span><?php echo htmlspecialchars($user['nic_no']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-envelope"></i>
                    <span><?php echo htmlspecialchars($user['email']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-phone"></i>
                    <span><?php echo htmlspecialchars($user['tel_no']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar-day"></i>
                    <span>Joined: <?php echo $user['formatted_created']; ?></span>
                </div>
                <?php if ($user['reviewed_at']): ?>
                <div class="meta-item">
                    <i class="fas fa-user-check"></i>
                    <span>Reviewed: <?php echo $user['formatted_reviewed']; ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="generate_pdf.php?id=<?php echo $userId; ?>" class="btn-action btn-pdf">
                <i class="fas fa-file-pdf"></i> Download PDF Report
            </a>
            <a href="send_email.php?user_id=<?php echo $userId; ?>" class="btn-action btn-email">
                <i class="fas fa-envelope"></i> Send Email
            </a>
            <a href="download_user_files.php?id=<?php echo $userId; ?>" class="btn-action btn-download">
                <i class="fas fa-download"></i> Download All Files
            </a>
        </div>
        
        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div>
                <!-- Personal Information -->
                <div class="card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-circle"></i> Personal Information
                        </h3>
                    </div>
                    <table class="info-table">
                        <tr class="info-row">
                            <td class="info-label">Full Name</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['full_name']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Name with Initials</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['name_with_initials']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">NIC Number</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['nic_no']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Date of Birth</td>
                            <td class="info-value"><?php echo $user['formatted_dob']; ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Email Address</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['email']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Phone Number</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['tel_no']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Residential Address</td>
                            <td class="info-value"><?php echo nl2br(htmlspecialchars($user['address'])); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Professional Information -->
                <div class="card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-briefcase"></i> Professional Information
                        </h3>
                    </div>
                    <table class="info-table">
                        <tr class="info-row">
                            <td class="info-label">Occupation</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['occupation']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Employer Name</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['employer_name']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Investment Period</td>
                            <td class="info-value"><?php echo $user['years']; ?> Years</td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Investment Amount</td>
                            <td class="info-value">Rs. <?php echo number_format($user['investment_amount'], 2); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Investment Type</td>
                            <td class="info-value"><?php
                                $investmentTypeNames = [
                                    'HPP' => 'High profit plan',
                                    'GSP' => 'Green saving plan',
                                    'GSI' => 'Green silver plan',
                                    'GOLD' => 'Gold plan',
                                    'SFPS' => 'Seraa farm profit share plan',
                                    'SFHPS' => 'Seraa farm high profit share plan'
                                ];
                                echo htmlspecialchars($investmentTypeNames[$user['investment_type']] ?? $user['investment_type']);
                            ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Signing Date</td>
                            <td class="info-value"><?php echo $user['formatted_signing']; ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Bank Details -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-university"></i> Bank Details
                        </h3>
                    </div>
                    <table class="info-table">
                        <tr class="info-row">
                            <td class="info-label">Account Number</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['account_no']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Bank Name</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['bank_name']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Branch Name</td>
                            <td class="info-value"><?php echo htmlspecialchars($user['branch_name']); ?></td>
                        </tr>
                        <tr class="info-row">
                            <td class="info-label">Declaration Date</td>
                            <td class="info-value"><?php echo $user['formatted_declaration']; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Right Column -->
            <div>
                <!-- Approver Documents -->
                <div class="card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-contract"></i> Approver Documents
                            <span style="font-size: 14px; color: var(--text-muted); margin-left: 10px;">
                                (<?php echo count($approverDocs); ?>)
                            </span>
                        </h3>
                    </div>
                    <div class="approver-docs">
                        <?php if (!empty($approverDocs)): ?>
                            <?php foreach ($approverDocs as $doc): ?>
                                <div class="approver-doc-item">
                                    <div class="doc-info">
                                        <div class="doc-level">
                                            <?php echo $doc['approver_level']; ?>
                                        </div>
                                        <div class="doc-details">
                                            <div class="doc-title">
                                                Level <?php echo $doc['approver_level']; ?> Approval Document
                                            </div>
                                            <div class="doc-meta">
                                                <span><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($doc['uploaded_by']); ?></span>
                                                <span><i class="fas fa-calendar"></i> <?php echo $doc['formatted_uploaded']; ?></span>
                                                <span><i class="fas fa-file"></i> <?php echo htmlspecialchars($doc['file_name']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="doc-actions">
                                        <a href="../uploads/approvers/<?php echo $doc['file_name']; ?>" target="_blank" class="btn-icon" title="View Document">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="../uploads/approvers/<?php echo $doc['file_name']; ?>" download class="btn-icon" title="Download Document">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-file-signature"></i>
                                <p>No approver documents found</p>
                                <p style="font-size: 14px; margin-top: 10px; color: var(--text-muted);">
                                    Approver documents will appear here once the application goes through the approval process.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Beneficiaries -->
                <div class="card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-users"></i> Beneficiaries
                            <span style="font-size: 14px; color: var(--text-muted); margin-left: 10px;">
                                (<?php echo count($beneficiaries); ?>)
                            </span>
                        </h3>
                    </div>
                    <?php if (!empty($beneficiaries)): ?>
                        <div class="beneficiaries-grid">
                            <?php foreach ($beneficiaries as $beneficiary): ?>
                                <div class="beneficiary-card">
                                    <div class="beneficiary-percentage">
                                        <?php echo $beneficiary['percentage']; ?>%
                                    </div>
                                    <h4 style="margin-bottom: 8px; color: var(--text);">
                                        <?php echo htmlspecialchars($beneficiary['beneficiary_name']); ?>
                                    </h4>
                                    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 5px;">
                                        <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($beneficiary['beneficiary_nic']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No beneficiaries found</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Uploaded Files -->
                <div class="card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-folder-open"></i> Uploaded Files
                        </h3>
                    </div>
                    <?php
                    $files = [
                        ['icon' => 'fa-signature', 'label' => 'Signature', 'field' => 'signature_upload'],
                        ['icon' => 'fa-id-card', 'label' => 'Investor ID', 'field' => 'investor_id_doc'],
                        ['icon' => 'fa-id-card', 'label' => 'Beneficiary ID', 'field' => 'beneficiary_id_doc'],
                        ['icon' => 'fa-book', 'label' => 'Passbook', 'field' => 'passbook_doc'],
                        ['icon' => 'fa-receipt', 'label' => 'Payment Slip', 'field' => 'payment_slip_doc'],
                        ['icon' => 'fa-signature', 'label' => 'Final Signature', 'field' => 'final_signature']
                    ];
                    
                    $hasFiles = false;
                    foreach ($files as $file):
                        if (!empty($user[$file['field']])):
                            $hasFiles = true;
                            break;
                        endif;
                    endforeach;
                    ?>
                    
                    <?php if ($hasFiles): ?>
                        <ul class="files-list">
                            <?php foreach ($files as $file):
                                if (!empty($user[$file['field']])): 
                                    $filename = basename($user[$file['field']]);
                                    $filepath = '../uploads/' . $user[$file['field']];
                            ?>
                                <li class="file-item">
                                    <div class="file-info">
                                        <div class="file-icon">
                                            <i class="fas <?php echo $file['icon']; ?>"></i>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; margin-bottom: 3px;">
                                                <?php echo $file['label']; ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--text-muted);">
                                                <?php echo $filename; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="file-actions">
                                        <a href="<?php echo $filepath; ?>" target="_blank" class="btn-icon" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?php echo $filepath; ?>" download class="btn-icon" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </li>
                            <?php endif; endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-export"></i>
                            <p>No files uploaded</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Activity Timeline -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-history"></i> Activity Timeline
                        </h3>
                    </div>
                    <?php if (!empty($logs)): ?>
                        <div class="timeline">
                            <?php foreach ($logs as $log): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <?php echo $log['formatted_created']; ?>
                                        <?php if ($log['performed_by']): ?>
                                            • by <?php echo htmlspecialchars($log['performed_by']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-action">
                                        <?php echo ucwords(str_replace('_', ' ', $log['action'])); ?>
                                    </div>
                                    <?php if ($log['description']): ?>
                                        <div class="timeline-desc">
                                            <?php echo htmlspecialchars($log['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clock"></i>
                            <p>No activity recorded</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
       
    
    <script>
        // Auto-save notes with debounce
        let notesSaveTimer;
        const notesTextarea = document.getElementById('admin_notes');
        
        if (notesTextarea) {
            notesTextarea.addEventListener('input', function() {
                clearTimeout(notesSaveTimer);
                notesSaveTimer = setTimeout(saveNotes, 2000);
            });
        }
        
        function saveNotes() {
            const formData = new FormData();
            formData.append('save_notes', 'true');
            formData.append('admin_notes', notesTextarea.value);
            formData.append('ajax', 'true');
            
            fetch('view_user.php?id=<?php echo $userId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Notes saved automatically', 'success');
                }
            })
            .catch(error => {
                console.error('Error saving notes:', error);
            });
        }
        
        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'rgba(16, 185, 129, 0.9)' : 'rgba(59, 130, 246, 0.9)'};
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                gap: 10px;
                z-index: 1000;
                animation: slideInRight 0.3s ease;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Add CSS for toast animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
        
        // Confirm before rejecting application
        const statusSelect = document.getElementById('status');
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                if (this.value === 'rejected') {
                    if (!confirm('⚠️ Are you sure you want to REJECT this application?\n\nThis action cannot be undone and the user will be notified.')) {
                        this.value = '<?php echo $user["status"]; ?>';
                    }
                } else if (this.value === 'approved') {
                    if (!confirm('✅ Are you sure you want to APPROVE this application?\n\nThe user will receive notification and account activation.')) {
                        this.value = '<?php echo $user["status"]; ?>';
                    }
                }
            });
        }
        
        // Print user details
        function printUserDetails() {
            const printContent = document.querySelector('.container').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>User Details - <?php echo htmlspecialchars($user['full_name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .no-print { display: none; }
                        .card { border: 1px solid #ddd; padding: 20px; margin: 10px 0; }
                        .info-table { width: 100%; border-collapse: collapse; }
                        .info-table td { padding: 8px; border: 1px solid #ddd; }
                        .header { background: #0a2f1d; color: white; padding: 20px; text-align: center; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>EcoWealth Finance - User Details</h1>
                        <p>Printed: <?php echo date('F d, Y H:i:s'); ?></p>
                    </div>
                    ${printContent}
                </body>
                </html>
            `;
            
            window.print();
            document.body.innerHTML = originalContent;
            location.reload();
        }
        
        // Add print button to header
        const header = document.querySelector('.page-header');
        if (header) {
            const printBtn = document.createElement('a');
            printBtn.href = 'javascript:void(0)';
            printBtn.className = 'back-btn';
            printBtn.style.marginLeft = '10px';
            printBtn.innerHTML = '<i class="fas fa-print"></i> Print';
            printBtn.onclick = printUserDetails;
            header.appendChild(printBtn);
        }
        
        // Initialize page animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate cards on load
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animation = `fadeIn 0.5s ease ${index * 0.1}s both`;
            });
        });
    </script>
</body>
</html>