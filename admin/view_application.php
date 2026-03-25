<?php
session_start();
require_once '../config.php';
require_once '../db.php';


// Check if user is logged in as admin OR approver
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['approver_id'])) {
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
    // Admin login
    $userId = $_SESSION['admin_id'];
    $username = $_SESSION['admin_name'] ?? 'Admin';
    $fullName = $_SESSION['admin_name'] ?? 'Admin';
    $userType = $_SESSION['admin_role'] ?? 'admin';
    $approverLevel = 0; // Admin has no approver level
    
    // Fetch additional admin data
    try {
        $stmt = $db->prepare("
            SELECT email, role, last_login
            FROM admin_users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch();
    } catch (PDOException $e) {
        $userData = [];
    }
}

$applicationId = $_GET['id'] ?? 0;
if (!$applicationId) {
    header('Location: applications.php');
    exit();
}

$error = '';
$success = '';
$currentApproverLevel = 1;

// Handle status update with 3-tier approval system
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $newStatus = $_POST['status'] ?? '';
        $adminNotes = $_POST['admin_notes'] ?? '';
        
        // Use appropriate name based on login type
        if ($isApprover) {
            $adminName = $_SESSION['approver_name'] ?? 'Approver';
        } else {
            $adminName = $_SESSION['admin_name'] ?? 'Admin';
        }
        
        $approverLevel = $_POST['approver_level'] ?? $currentApproverLevel;
        $nextAction = $_POST['next_action'] ?? ''; // 'approve', 'reject', 'send_to_next'
        
        // Validate action
        if (empty($nextAction)) {
            $error = "Please select an action (Approve, Reject, or Send to Next Approver)";
        } else {
            try {
                $db->beginTransaction();
                
                // Handle file upload for approver
$uploadedFile = null;
if (isset($_FILES['approver_file']) && $_FILES['approver_file']['error'] === UPLOAD_ERR_OK) {
    $fileName = 'approver_' . $approverLevel . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $_FILES['approver_file']['name']);
    $uploadDir = '../uploads/approvers/';
    
    // Create approvers directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $destination = $uploadDir . $fileName;
    
    // Validate file (PDF only for approvers)
    $fileExt = strtolower(pathinfo($_FILES['approver_file']['name'], PATHINFO_EXTENSION));
    if ($fileExt !== 'pdf') {
        throw new Exception("Approver documents must be in PDF format.");
    }
    
    if (move_uploaded_file($_FILES['approver_file']['tmp_name'], $destination)) {
        $uploadedFile = $fileName;
        
        // Save approver document to database
        $docStmt = $db->prepare("
            INSERT INTO approver_documents 
            (investor_id, approver_level, file_name, uploaded_by, uploaded_at)
            VALUES (:investor_id, :approver_level, :file_name, :uploaded_by, NOW())
        ");
        
        $docStmt->execute([
            ':investor_id' => $applicationId,
            ':approver_level' => $approverLevel,
            ':file_name' => $uploadedFile,
            ':uploaded_by' => $adminName
        ]);
    } else {
        throw new Exception("Failed to upload approver document.");
    }
}
                
                // Determine next approver level and status based on action
                $nextApproverLevel = $approverLevel;
                $finalStatus = 'pending'; // Default
                
                if ($nextAction === 'send_to_next') {
                    if ($approverLevel < 3) {
                        $nextApproverLevel = $approverLevel + 1;
                        $finalStatus = 'in_progress';
                        $actionDescription = "Sent to Approver Level $nextApproverLevel";
                    } else {
                        // All approvers have approved, final approval
                        $nextApproverLevel = 3; // Keep at level 3
                        $finalStatus = 'approved';
                        $actionDescription = "Final Approved by Level 3 Approver";
                    }
                } elseif ($nextAction === 'approve') {
                    if ($approverLevel == 3) {
                        $finalStatus = 'approved';
                        $actionDescription = "Final Approved by Level $approverLevel";
                    } else {
                        // For lower levels, we need to send to next level
                        $nextApproverLevel = $approverLevel + 1;
                        $finalStatus = 'in_progress';
                        $actionDescription = "Approved by Level $approverLevel, sent to Level $nextApproverLevel";
                    }
                } elseif ($nextAction === 'reject') {
                    $finalStatus = 'rejected';
                    $actionDescription = "Rejected by Level $approverLevel Approver";
                }
                
                // Update application status, approver level, and notes
                $updateStmt = $db->prepare("
                    UPDATE investors 
                    SET status = :status, 
                        approver_level = :approver_level,
                        admin_notes = :admin_notes, 
                        reviewed_at = NOW(), 
                        reviewed_by = :reviewed_by,
                        current_approver = :current_approver,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                
                $updateStmt->execute([
                    ':status' => $finalStatus,
                    ':approver_level' => $nextApproverLevel,
                    ':admin_notes' => $adminNotes,
                    ':reviewed_by' => $adminName,
                    ':current_approver' => $adminName,
                    ':id' => $applicationId
                ]);
                
                
                // Log the status change
                $logStmt = $db->prepare("
                    INSERT INTO application_logs (investor_id, action, description, performed_by)
                    VALUES (:investor_id, :action, :description, :performed_by)
                ");
                
                $logDescription = "Status: " . ucfirst($finalStatus) . 
                                 " | Approver Level: $approverLevel" .
                                 " | Action: " . ucfirst(str_replace('_', ' ', $nextAction)) .
                                 ($adminNotes ? " | Notes: " . substr($adminNotes, 0, 200) : '');
                
                $logStmt->execute([
                    ':investor_id' => $applicationId,
                    ':action' => 'status_updated',
                    ':description' => $logDescription,
                    ':performed_by' => $adminName
                ]);
                
                $db->commit();
                
                $success = "Application status updated. " . $actionDescription;
                
                // Refresh the page to show updated data
                header("Refresh: 2; url=view_application.php?id=" . $applicationId);
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to update status: " . $e->getMessage();
            }
        }
    }
    
    // Handle notes auto-save
    if (isset($_POST['save_notes'])) {
        $adminNotes = $_POST['admin_notes'] ?? '';
        try {
            $notesStmt = $db->prepare("
                UPDATE investors 
                SET admin_notes = :admin_notes, 
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $notesStmt->execute([
                ':admin_notes' => $adminNotes,
                ':id' => $applicationId
            ]);
            
            // Return JSON for AJAX
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit();
            }
            
        } catch (PDOException $e) {
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit();
            }
            $error = "Failed to save notes: " . $e->getMessage();
        }
    }
    
    // Handle full application download
    if (isset($_POST['download_full'])) {
        header("Location: download_full_application.php?id=" . $applicationId);
        exit();
    }
}
// ... continue with the rest of your code ...

// Get application details with approver information
try {
    $stmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created,
               DATE_FORMAT(updated_at, '%M %d, %Y %H:%i') as formatted_updated,
               DATE_FORMAT(reviewed_at, '%M %d, %Y %H:%i') as formatted_reviewed,
               DATE_FORMAT(dob, '%M %d, %Y') as formatted_dob,
               DATE_FORMAT(signing_date, '%M %d, %Y') as formatted_signing,
               DATE_FORMAT(declaration_date, '%M %d, %Y') as formatted_declaration,
               COALESCE(approver_level, 1) as approver_level,
               current_approver
        FROM investors 
        WHERE id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    
    if (!$application) {
        header('Location: applications.php');
        exit();
    }
    
    // Set current approver level
    $currentApproverLevel = $application['approver_level'] ?? 1;
    
    // Get beneficiaries
    $stmt = $db->prepare("SELECT * FROM beneficiaries WHERE investor_id = ? ORDER BY percentage DESC");
    $stmt->execute([$applicationId]);
    $beneficiaries = $stmt->fetchAll();
    
    // Get application logs
    $stmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created
        FROM application_logs 
        WHERE investor_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$applicationId]);
    $logs = $stmt->fetchAll();
    
    // Get approver documents
    $docStmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(uploaded_at, '%M %d, %Y %H:%i') as formatted_uploaded
        FROM approver_documents 
        WHERE investor_id = ? 
        ORDER BY approver_level, uploaded_at DESC
    ");
    $docStmt->execute([$applicationId]);
    $approverDocs = $docStmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Details | Serendub Green Plantation Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        <style>
        /* ... Keep all existing CSS styles ... */
        
        /* New styles for 3-tier approval system */
        .approval-progress {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }
        
        .approval-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 40px 0;
        }
        
        .approval-steps::before {
            content: '';
            position: absolute;
            top: 25px;
            left: 50px;
            right: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
            z-index: 1;
        }
        
        .approval-step {
            text-align: center;
            flex: 1;
            position: relative;
            z-index: 2;
        }
        
        .step-number {
            width: 50px;
            height: 50px;
            background: rgba(26, 77, 51, 0.7);
            border: 3px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        
        .approval-step.active .step-number {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-color: var(--accent);
            box-shadow: 0 0 20px var(--accent-glow);
            transform: scale(1.1);
        }
        
        .approval-step.completed .step-number {
            background: var(--success);
            border-color: var(--success);
        }
        
        .step-label {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 5px;
        }
        
        .step-status {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .approver-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-muted);
        }
        
        /* Approval Actions */
        .approval-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .action-btn {
            padding: 15px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .action-btn.approve {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
        }
        
        .action-btn.send-next {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border: none;
        }
        
        .action-btn.reject {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
        }
        
        .action-btn i {
            font-size: 24px;
        }
        
        /* Approver Documents Section */
        .approver-documents {
            margin-top: 30px;
        }
        
        .document-item {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .document-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .document-level {
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 14px;
        }
        
        /* File Upload for Approvers */
        .file-upload-container {
            margin: 20px 0;
            padding: 20px;
            background: rgba(26, 77, 51, 0.3);
            border: 2px dashed var(--border);
            border-radius: 12px;
            text-align: center;
        }
        
        .file-upload-container:hover {
            border-color: var(--accent);
            background: rgba(34, 197, 94, 0.1);
        }
        
        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .file-upload-label i {
            font-size: 40px;
            color: var(--accent);
        }
        
        .file-input {
            display: none;
        }
        
        .file-preview {
            margin-top: 10px;
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .file-preview i {
            color: var(--success);
            margin-right: 5px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .approval-steps {
                flex-direction: column;
                gap: 30px;
            }
            
            .approval-steps::before {
                display: none;
            }
            
            .approval-step {
                display: flex;
                align-items: center;
                gap: 15px;
                text-align: left;
            }
            
            .step-number {
                margin: 0;
                flex-shrink: 0;
            }
            
            .approval-actions {
                grid-template-columns: 1fr;
            }
        }

        
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
        
        .admin-container {
            display: flex;
            min-height: 100vh;
            backdrop-filter: blur(10px);
        }
        
        /* Sidebar - Premium Redesign */
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
        
        .admin-info {
            padding: 12px 15px;
            background: rgba(34, 197, 94, 0.08);
            border-radius: 8px;
            border: 1px solid var(--border);
        }
        
        .admin-name {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 3px;
        }
        
        .admin-role {
            font-size: 12px;
            color: var(--text-muted);
            background: rgba(34, 197, 94, 0.15);
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
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
        
        .sidebar-nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(34, 197, 94, 0.1), transparent);
            transition: left 0.5s ease;
        }
        
        .sidebar-nav a:hover::before {
            left: 100%;
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
        
        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }
        
        /* Header Actions */
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
        
        /* Button Styles */
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
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn:hover::before {
            left: 100%;
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
        
        .btn-download {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        
        .btn-download:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
        }
        
        .btn-print {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-print:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        /* Alerts - Enhanced */
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
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
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
        
        .alert.info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.1));
            border-color: var(--info);
            border-left: 5px solid var(--info);
        }
        
        .alert.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.1));
            border-color: var(--warning);
            border-left: 5px solid var(--warning);
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Application Header - Premium */
        .application-header {
            background: linear-gradient(135deg, rgba(10, 47, 29, 0.9), rgba(6, 78, 59, 0.9));
            border-radius: 18px;
            padding: 35px;
            margin-bottom: 35px;
            border: 1px solid var(--border);
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .application-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }
        
        .application-id {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(90deg, #00ff88, #22c55e);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .application-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .application-status.pending { 
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white; 
        }
        .application-status.reviewed { 
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white; 
        }
        .application-status.approved { 
            background: linear-gradient(135deg, #10b981, #059669);
            color: white; 
        }
        .application-status.rejected { 
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white; 
        }
        
        .header-meta {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .meta-item i {
            color: var(--accent);
            font-size: 16px;
        }
        
        /* Info Grid - Enhanced */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .info-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        }
        
        .info-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
            border-color: rgba(34, 197, 94, 0.4);
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--neon);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .section-title i {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(0, 255, 136, 0.1));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
        }
        
        .info-row {
            margin-bottom: 20px;
            display: flex;
            padding: 12px 0;
            border-bottom: 1px dashed rgba(34, 197, 94, 0.1);
            transition: all 0.3s ease;
        }
        
        .info-row:hover {
            background: rgba(34, 197, 94, 0.05);
            border-radius: 8px;
            padding: 12px;
            transform: translateX(5px);
        }
        
        .info-label {
            font-weight: 600;
            color: var(--text-muted);
            min-width: 160px;
            font-size: 14px;
        }
        
        .info-value {
            flex: 1;
            color: var(--text);
            word-break: break-word;
            font-size: 15px;
        }
        
        /* File List - Enhanced */
        .file-list {
            list-style: none;
        }
        
        .file-item {
            padding: 15px;
            background: rgba(26, 77, 51, 0.5);
            border-radius: 10px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        
        .file-item:hover {
            background: rgba(34, 197, 94, 0.1);
            border-color: var(--accent);
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
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(0, 255, 136, 0.1));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
        }
        
        .file-name {
            font-weight: 500;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            background: rgba(26, 77, 51, 0.5);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-action:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }
        
        /* Timeline - Enhanced */
        .timeline {
            position: relative;
            padding-left: 40px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, var(--accent), var(--neon));
            border-radius: 2px;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
            padding: 20px;
            background: rgba(26, 77, 51, 0.3);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .timeline-item:hover {
            background: rgba(34, 197, 94, 0.1);
            transform: translateX(5px);
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -30px;
            top: 25px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--accent);
            border: 3px solid var(--primary);
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.3);
        }
        
        .timeline-date {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .timeline-action {
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 5px;
            font-size: 15px;
        }
        
        /* Download Section - Premium */
        .download-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 35px;
            margin-top: 40px;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }
        
        .download-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.05) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .download-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 25px;
            position: relative;
            z-index: 1;
        }
        
        .download-card {
            background: linear-gradient(135deg, rgba(10, 47, 29, 0.8), rgba(6, 78, 59, 0.6));
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 25px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }
        
        .download-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s ease;
        }
        
        .download-card:hover::before {
            left: 100%;
        }
        
        .download-card:hover {
            transform: translateY(-8px) scale(1.02);
            border-color: var(--accent);
            box-shadow: 0 15px 40px rgba(34, 197, 94, 0.25);
        }
        
        .download-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 30px;
            color: white;
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.3);
        }
        
        .download-title {
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--text);
            font-size: 18px;
        }
        
        .download-desc {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        /* Status Form - Enhanced */
        .status-form {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 35px;
            margin-top: 40px;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 12px;
            font-weight: 600;
            color: var(--text);
            font-size: 15px;
        }
        
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 16px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 15px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
            background: rgba(26, 77, 51, 0.9);
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .btn-group {
            display: flex;
            gap: 20px;
            margin-top: 30px;
        }
        
        .btn-full {
            width: 100%;
            padding: 16px;
            font-size: 16px;
        }
        
        /* Badge Styles */
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
        
        .badge.percentage {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }
        
        /* Loading Overlay - Premium */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(10px);
        }
        
        .loading-content {
            text-align: center;
            color: white;
            max-width: 400px;
            padding: 40px;
            background: rgba(10, 47, 29, 0.9);
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .spinner-container {
            position: relative;
            width: 80px;
            height: 80px;
            margin: 0 auto 25px;
        }
        
        .spinner {
            position: absolute;
            width: 100%;
            height: 100%;
            border: 4px solid transparent;
            border-top: 4px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        .spinner::before {
            content: '';
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 4px solid transparent;
            border-top: 4px solid var(--neon);
            border-radius: 50%;
            animation: spin 2s linear infinite reverse;
        }
        
        .spinner::after {
            content: '';
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            bottom: 20px;
            border: 4px solid transparent;
            border-top: 4px solid #3b82f6;
            border-radius: 50%;
            animation: spin 3s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 250px;
            }
            
            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
            
            .sidebar-nav ul {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .sidebar-nav li {
                margin-bottom: 0;
                flex: 1;
                min-width: 150px;
            }
            
            .sidebar-nav a {
                justify-content: center;
                text-align: center;
                flex-direction: column;
                padding: 15px;
            }
            
            .nav-icon {
                margin-bottom: 5px;
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
                gap: 10px;
            }
            
            .header-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .info-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .info-label {
                min-width: auto;
            }
            
            .download-options {
                grid-template-columns: 1fr;
            }
            
            .application-header {
                padding: 25px;
            }
            
            .application-id {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .btn {
                padding: 10px 16px;
                font-size: 13px;
            }
            
            .alert {
                padding: 15px;
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .sidebar-nav li {
                min-width: 100%;
            }
        }
        
        /* Animation for page elements */
        .animate-in {
            animation: fadeInUp 0.6s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Status color indicators */
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-indicator.pending { background: #f59e0b; box-shadow: 0 0 10px #f59e0b; }
        .status-indicator.reviewed { background: #3b82f6; box-shadow: 0 0 10px #3b82f6; }
        .status-indicator.approved { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .status-indicator.rejected { background: #ef4444; box-shadow: 0 0 10px #ef4444; }
        
        /* Floating particles */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .particle {
            position: absolute;
            background: var(--accent);
            border-radius: 50%;
            opacity: 0.1;
            animation: float 20s infinite linear;
        }
        
        @keyframes float {
            0% {
                transform: translateY(0) translateX(0) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 0.1;
            }
            90% {
                opacity: 0.1;
            }
            100% {
                transform: translateY(-100vh) translateX(100px) rotate(360deg);
                opacity: 0;
            }
        }
        
        /* Tooltip */
        .tooltip {
            position: relative;
        }
        
        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 8px 12px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            font-size: 12px;
            border-radius: 6px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 8px;
        }
        
        .tooltip:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: rgba(0, 0, 0, 0.8);
            margin-bottom: -2px;
        }
        /* ... Keep all existing CSS styles ... */
        
        /* New styles for 3-tier approval system */
        .approval-progress {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }
        
        .approval-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 40px 0;
        }
        
        .approval-steps::before {
            content: '';
            position: absolute;
            top: 25px;
            left: 50px;
            right: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--neon));
            z-index: 1;
        }
        
        .approval-step {
            text-align: center;
            flex: 1;
            position: relative;
            z-index: 2;
        }
        
        .step-number {
            width: 50px;
            height: 50px;
            background: rgba(26, 77, 51, 0.7);
            border: 3px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        
        .approval-step.active .step-number {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-color: var(--accent);
            box-shadow: 0 0 20px var(--accent-glow);
            transform: scale(1.1);
        }
        
        .approval-step.completed .step-number {
            background: var(--success);
            border-color: var(--success);
        }
        
        .step-label {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 5px;
        }
        
        .step-status {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .approver-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-muted);
        }
        
        /* Approval Actions */
        .approval-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .action-btn {
            padding: 15px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .action-btn.approve {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
        }
        
        .action-btn.send-next {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border: none;
        }
        
        .action-btn.reject {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
        }
        
        .action-btn i {
            font-size: 24px;
        }
        
        /* Approver Documents Section */
        .approver-documents {
            margin-top: 30px;
        }
        
        .document-item {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .document-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .document-level {
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 14px;
        }
        
        /* File Upload for Approvers */
        .file-upload-container {
            margin: 20px 0;
            padding: 20px;
            background: rgba(26, 77, 51, 0.3);
            border: 2px dashed var(--border);
            border-radius: 12px;
            text-align: center;
        }
        
        .file-upload-container:hover {
            border-color: var(--accent);
            background: rgba(34, 197, 94, 0.1);
        }
        
        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .file-upload-label i {
            font-size: 40px;
            color: var(--accent);
        }
        
        .file-input {
            display: none;
        }
        
        .file-preview {
            margin-top: 10px;
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .file-preview i {
            color: var(--success);
            margin-right: 5px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .approval-steps {
                flex-direction: column;
                gap: 30px;
            }
            
            .approval-steps::before {
                display: none;
            }
            
            .approval-step {
                display: flex;
                align-items: center;
                gap: 15px;
                text-align: left;
            }
            
            .step-number {
                margin: 0;
                flex-shrink: 0;
            }
            
            .approval-actions {
                grid-template-columns: 1fr;
            }
        }
    /* ... Keep all existing CSS styles ... */
        /* I'm keeping your existing CSS as it was, but adding the missing toast function styles */
        
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            animation: slideInRight 0.3s ease, fadeOut 0.3s ease 4.7s;
            max-width: 400px;
        }
        
        .toast.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .toast.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .toast.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .toast.info {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes fadeOut {
            from {
                opacity: 1;
            }
            to {
                opacity: 0;
            }
        }
        
        /* Fix for action buttons */
        .action-btn {
            cursor: pointer !important;
        }
        
        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
        }
        
        /* Status form submit button fix */
        .status-form button[type="submit"] {
            cursor: pointer !important;
        }
        
        .status-form button[type="submit"]:disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
        }

        /* Approver Documents - Enhanced */
.document-item {
    background: rgba(26, 77, 51, 0.5);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.document-item:hover {
    background: rgba(34, 197, 94, 0.1);
    border-color: var(--accent);
    transform: translateX(5px);
    box-shadow: 0 6px 20px rgba(34, 197, 94, 0.15);
}

.document-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, var(--accent), var(--neon));
    opacity: 0.7;
}

.document-level {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--accent), var(--neon));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 800;
    font-size: 16px;
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
}

.file-actions {
    display: flex;
    gap: 8px;
}

.btn-action {
    width: 40px;
    height: 40px;
    background: rgba(26, 77, 51, 0.5);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.btn-action:hover {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
}
    </style>
</head>
<body>

 <div class="particles" id="particles"></div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner-container">
                <div class="spinner"></div>
            </div>
            <h3 style="margin-bottom: 10px; color: var(--neon);">Processing Request</h3>
            <p style="color: var(--text-muted); margin-bottom: 5px;">Preparing your download...</p>
            <p style="color: var(--text-muted); font-size: 14px;">This may take a few moments</p>
        </div>
    </div>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <div class="logo-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h2>Serendib Green Plantation</h2>
                    
                </div>
                
            </div>
            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                        <div style="padding: 8px 20px; background: rgba(34, 197, 94, 0.15); border-radius: 20px; font-size: 14px;">
                            <i class="fas fa-user-tag"></i> Role: <?php echo ucfirst(str_replace('_', ' ', $userType)); ?>
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
                    <li class="active">
                        <a href="applications.php">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span>Applications</span>
                            <span class="badge" style="background: var(--accent); margin-left: auto;"><?php echo getApplicationCount($db); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="send_email.php?application_id=<?php echo $applicationId; ?>">
                            <span class="nav-icon"><i class="fas fa-envelope"></i></span>
                            <span>Send Email</span>
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
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <h1>Application Details</h1>
                        <p style="color: var(--text-muted); font-size: 14px;">Manage and review investment applications</p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <a href="applications.php" class="btn btn-secondary tooltip" data-tooltip="Back to Applications">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <button onclick="downloadFullApplication()" class="btn btn-download tooltip" data-tooltip="Download All Files + PDF">
                        <i class="fas fa-download"></i> Download Full
                    </button>
                    <button onclick="printApplication()" class="btn btn-print tooltip" data-tooltip="Print Summary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="send_email.php?application_id=<?php echo $applicationId; ?>" class="btn btn-primary tooltip" data-tooltip="Send Email to Investor">
                        <i class="fas fa-envelope"></i> Email
                    </a>
                </div>
            </div>
            
            <!-- Display Messages -->
            <?php if ($success): ?>
                <div class="alert success animate-in">
                    <i class="fas fa-check-circle" style="font-size: 20px;"></i>
                    <div>
                        <strong>Success!</strong> <?php echo $success; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert error animate-in">
                    <i class="fas fa-exclamation-circle" style="font-size: 20px;"></i>
                    <div>
                        <strong>Error!</strong> <?php echo $error; ?>
                    </div>
                </div>
            <?php endif; ?>
            
                        
            <!-- Application Header -->
            <div class="application-header animate-in">
                <div class="application-id">
                    <span>Application #EWF-<?php echo str_pad($applicationId, 6, '0', STR_PAD_LEFT); ?></span>
                    <span class="application-status <?php echo $application['status']; ?>">
                        <span class="status-indicator <?php echo $application['status']; ?>"></span>
                        <?php echo ucfirst($application['status']); ?>
                    </span>
                </div>
                
                <div style="margin: 15px 0;">
                    <h2 style="color: var(--text); margin-bottom: 5px;"><?php echo htmlspecialchars($application['full_name']); ?></h2>
                    <p style="color: var(--text-muted);"><?php echo htmlspecialchars($application['email']); ?> • <?php echo htmlspecialchars($application['nic_no']); ?></p>
                </div>
                
                <div class="header-meta">
                    <div class="meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Submitted: <?php echo $application['formatted_created']; ?></span>
                    </div>
                    <?php if ($application['reviewed_at']): ?>
                    <div class="meta-item">
                        <i class="fas fa-user-check"></i>
                        <span>Reviewed: <?php echo $application['formatted_reviewed']; ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-user-tie"></i>
                        <span>By: <?php echo htmlspecialchars($application['reviewed_by'] ?? 'Admin'); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <i class="fas fa-sync-alt"></i>
                        <span>Updated: <?php echo $application['formatted_updated']; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Application Details Grid -->
            <div class="info-grid">
                <!-- Personal Information -->
                <div class="info-section animate-in" style="animation-delay: 0.1s;">
                    <div class="section-title">
                        <i class="fas fa-user-circle"></i>
                        <span>Personal Information</span>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($application['full_name']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Name with Initials</div>
                        <div class="info-value"><?php echo htmlspecialchars($application['name_with_initials']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">NIC Number</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($application['nic_no']); ?>
                            <button onclick="copyText('<?php echo $application['nic_no']; ?>')" class="btn-action" style="margin-left: 10px; padding: 2px 8px;">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date of Birth</div>
                        <div class="info-value"><?php echo $application['formatted_dob']; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Email Address</div>
                        <div class="info-value">
                            <a href="mailto:<?php echo htmlspecialchars($application['email']); ?>" style="color: var(--accent); text-decoration: none;">
                                <?php echo htmlspecialchars($application['email']); ?>
                            </a>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Phone Number</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($application['tel_no']); ?>" style="color: var(--accent); text-decoration: none;">
                                <?php echo htmlspecialchars($application['tel_no']); ?>
                            </a>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($application['address'])); ?></div>
                    </div>
                </div>
                
                <!-- Professional Information -->
                <div class="info-section animate-in" style="animation-delay: 0.2s;">
                    <div class="section-title">
                        <i class="fas fa-briefcase"></i>
                        <span>Professional Information</span>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Occupation</div>
                        <div class="info-value"><?php echo htmlspecialchars($application['occupation']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Employer</div>
                        <div class="info-value"><?php echo htmlspecialchars($application['employer_name']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Investment Years</div>
                        <div class="info-value">
                            <span class="badge percentage"><?php echo $application['years']; ?> years</span>
                        </div>
                    </div>


                     <div class="info-row">
                        <div class="info-label">Investment Amount</div>
                        <div class="info-value">
                            <span class="badge percentage">Rs.<?php echo $application['investment_amount']; ?> </span>
                        </div>
                    </div>

                     <div class="info-row">
                        <div class="info-label">Investment Type</div>
                        <div class="info-value">
                            <span class="badge percentage"><?php echo $application['investment_type']; ?> </span>
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="info-label">Signing Date</div>
                        <div class="info-value"><?php echo $application['formatted_signing']; ?></div>
                    </div>
                </div>
                
                <!-- Bank Details -->
                <div class="info-section animate-in" style="animation-delay: 0.3s;">
                    <div class="section-title">
                        <i class="fas fa-university"></i>
                        <span>Bank Details</span>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Account Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($application['account_no']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Bank Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($application['bank_name']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Branch</div>
                        <div class="info-value"><?php echo htmlspecialchars($application['branch_name']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Declaration Date</div>
                        <div class="info-value"><?php echo $application['formatted_declaration']; ?></div>
                    </div>
                </div>
                
                <!-- Beneficiaries -->
                <div class="info-section animate-in" style="animation-delay: 0.4s;">
                    <div class="section-title">
                        <i class="fas fa-users"></i>
                        <span>Beneficiaries</span>
                        <span class="badge" style="background: var(--accent); margin-left: auto;"><?php echo count($beneficiaries); ?></span>
                    </div>
                    <?php if (!empty($beneficiaries)): ?>
                        <?php foreach ($beneficiaries as $beneficiary): ?>
                            <div class="info-row" style="align-items: center;">
                                <div style="flex: 1;">
                                    <strong><?php echo htmlspecialchars($beneficiary['beneficiary_name']); ?></strong><br>
                                    <small style="color: var(--text-muted);">NIC: <?php echo htmlspecialchars($beneficiary['beneficiary_nic']); ?></small>
                                </div>
                                <span class="badge percentage"><?php echo $beneficiary['percentage']; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                        <div class="info-row" style="border-top: 2px solid var(--accent); margin-top: 10px; padding-top: 15px;">
                            <div style="font-weight: 700; color: var(--text);">Total Percentage:</div>
                            <span class="badge percentage" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">100%</span>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                            <i class="fas fa-users-slash" style="font-size: 40px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <p>No beneficiaries added</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Uploaded Files -->
                <div class="info-section animate-in" style="animation-delay: 0.5s;">
                    <div class="section-title">
                        <i class="fas fa-file-upload"></i>
                        <span>Uploaded Files</span>
                        <span class="badge" style="background: var(--info); margin-left: auto;">6 files</span>
                    </div>
                    <ul class="file-list">
                        <?php
                        $files = [
                            'Signature' => ['icon' => 'fas fa-signature', 'file' => $application['signature_upload']],
                            'Investor ID' => ['icon' => 'fas fa-id-card', 'file' => $application['investor_id_doc']],
                            'Beneficiary ID' => ['icon' => 'fas fa-id-card-alt', 'file' => $application['beneficiary_id_doc']],
                            'Passbook' => ['icon' => 'fas fa-book', 'file' => $application['passbook_doc']],
                            'Payment Slip' => ['icon' => 'fas fa-receipt', 'file' => $application['payment_slip_doc']],
                            'Final Signature' => ['icon' => 'fas fa-signature', 'file' => $application['final_signature']]
                        ];
                        foreach ($files as $label => $fileInfo):
                            if ($fileInfo['file']):
                        ?>
                            <li class="file-item">
                                <div class="file-info">
                                    <div class="file-icon">
                                        <i class="<?php echo $fileInfo['icon']; ?>"></i>
                                    </div>
                                    <div>
                                        <div class="file-name"><?php echo $label; ?></div>
                                        <small style="color: var(--text-muted); font-size: 12px;">
                                            <?php echo htmlspecialchars(basename($fileInfo['file'])); ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="../uploads/<?php echo $fileInfo['file']; ?>" target="_blank" class="btn-action tooltip" data-tooltip="View File">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../uploads/<?php echo $fileInfo['file']; ?>" download class="btn-action tooltip" data-tooltip="Download File">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </li>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </ul>
                </div><br><br><br>
                
                <!-- Activity Timeline -->
                <div class="info-section animate-in" style="animation-delay: 0.6s;">
                    <div class="section-title">
                        <i class="fas fa-history"></i>
                        <span>Activity Timeline</span>
                    </div>
                    <div class="timeline">
                        <?php if (empty($logs)): ?>
                            <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                                <i class="fas fa-stream" style="font-size: 40px; margin-bottom: 10px; opacity: 0.5;"></i>
                                <p>No activity recorded yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <i class="fas fa-clock"></i>
                                        <?php echo $log['formatted_created']; ?>
                                        <?php if ($log['performed_by']): ?>
                                            • by <?php echo htmlspecialchars($log['performed_by']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-action"><?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?></div>
                                    <?php if ($log['description']): ?>
                                        <div style="color: var(--text-muted); font-size: 14px;"><?php echo htmlspecialchars($log['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Download Options Section -->
            <div class="download-section animate-in" style="animation-delay: 0.7s;">
                <div class="section-title">
                    <i class="fas fa-download"></i>
                    <span>Download Options</span>
                </div>
                <p style="color: var(--text-muted); margin-bottom: 25px; font-size: 15px;">
                    Download application data in various formats for review, archiving, or sharing.
                </p>
                <div class="download-options">
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-title">PDF Report</div>
                        <div class="download-desc">Complete application summary with professional formatting</div>
                        <a href="generate_pdf.php?id=<?php echo $applicationId; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                    
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-archive"></i>
                        </div>
                        <div class="download-title">All Files</div>
                        <div class="download-desc">All uploaded documents in a single ZIP archive</div>
                        <a href="download_application.php?id=<?php echo $applicationId; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download ZIP
                        </a>
                    </div>
                    
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <div class="download-title">Excel Summary</div>
                        <div class="download-desc">Application data in spreadsheet format for analysis</div>
                        <a href="generate_excel.php?id=<?php echo $applicationId; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download Excel
                        </a>
                    </div>
                    
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-print"></i>
                        </div>
                        <div class="download-title">Print Summary</div>
                        <div class="download-desc">Printable version with optimized layout for printing</div>
                        <button onclick="printApplication()" class="btn btn-primary">
                            <i class="fas fa-print"></i> Print Now
                        </button>
                    </div>
                </div>
            </div>





























<!-- 3-Tier Approval Progress -->
<div class="approval-progress animate-in" style="animation-delay: 0.1s;">
    <div class="section-title">
        <i class="fas fa-user-check"></i>
        <span>3-Tier Approval System</span>
        <span class="badge" style="background: var(--info); margin-left: auto;">
            Level <?php echo $application['approver_level']; ?> of 3
        </span>
    </div>
    
    <div class="approval-steps">
        <?php
        $currentLevel = $application['approver_level'];
        $status = $application['status'];
        
        // Check if current user is an approver for this level
        $isApproverForThisLevel = false;
        
        if ($isApprover) {
            // For approver login
            $isApproverForThisLevel = ($approverLevel == $currentLevel);
        } else {
            // For admin login
            // Admins can always approve (you can change this if needed)
            $isApproverForThisLevel = true;
        }
        
        // Get approvers for each level from approvers table
        $levelApprovers = [];
        try {
            for ($i = 1; $i <= 3; $i++) {
                $stmt = $db->prepare("
                    SELECT name, email, role 
                    FROM approvers 
                    WHERE level = ? AND status = 'active'
                ");
                $stmt->execute([$i]);
                $levelApprovers[$i] = $stmt->fetchAll();
            }
        } catch (PDOException $e) {
            // If approvers table doesn't exist, use default
            $levelApprovers = [
                1 => [['name' => 'First Approver', 'email' => 'approver1@company.com', 'role' => 'approver']],
                2 => [['name' => 'Second Approver', 'email' => 'approver2@company.com', 'role' => 'approver']],
                3 => [['name' => 'Final Approver', 'email' => 'approver3@company.com', 'role' => 'approver']]
            ];
        }
        
        for ($i = 1; $i <= 3; $i++):
            $stepClass = '';
            $stepStatus = 'Pending';
            $stepApprover = '';
            
            // Determine step status
            if ($i < $currentLevel) {
                $stepClass = 'completed';
                $stepStatus = 'Completed';
            } elseif ($i == $currentLevel) {
                $stepClass = 'active';
                if ($status == 'approved' && $currentLevel == 3) {
                    $stepStatus = 'Completed';
                    $stepClass = 'completed';
                } else {
                    $stepStatus = 'Current';
                }
            }
            
            // Get approver for this level
            if (!empty($levelApprovers[$i])) {
                $stepApprover = $levelApprovers[$i][0]['name'];
            }
            
            // Get who actually approved from logs
            $actualApprover = '';
            foreach ($logs as $log) {
                if (strpos($log['description'], "Level $i") !== false) {
                    $actualApprover = $log['performed_by'];
                    break;
                }
            }
        ?>
        <div class="approval-step <?php echo $stepClass; ?>">
            <div class="step-number"><?php echo $i; ?></div>
            <div>
                <div class="step-label">
                    <?php 
                    $levelNames = [1 => 'First Approver', 2 => 'Second Approver', 3 => 'Final Approver'];
                    echo $levelNames[$i] ?? "Level $i Approver"; 
                    ?>
                </div>
                <div class="step-status"><?php echo $stepStatus; ?></div>
                
                <?php if ($actualApprover && $i < $currentLevel): ?>
                    <div class="approver-info">
                        <i class="fas fa-user-check" style="color: var(--success);"></i>
                        <span style="color: var(--success);">
                            <?php echo htmlspecialchars($actualApprover); ?>
                        </span>
                    </div>
                <?php elseif ($stepApprover): ?>
                    <div class="approver-info">
                        <i class="fas fa-user-tie"></i>
                        <span>
                            <?php echo htmlspecialchars($stepApprover); ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <!-- Show current user indicator if they are an approver for this level -->
                <?php if ($i == $currentLevel && $isApproverForThisLevel && $status != 'approved' && $status != 'rejected'): ?>
                    <div class="current-user-indicator" style="margin-top: 5px; padding: 3px 8px; background: linear-gradient(135deg, var(--accent), var(--neon)); color: white; border-radius: 12px; font-size: 11px; display: inline-block; box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);">
                        <i class="fas fa-user-check"></i> You can approve
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endfor; ?>
    </div>
    
    <?php if ($status != 'approved' && $status != 'rejected'): ?>
        <?php if ($isApproverForThisLevel): ?>
            <!-- Show approval actions for approvers -->
            <div class="approval-actions">
                <button type="button" class="action-btn approve" onclick="setAction('approve')">
                    <i class="fas fa-check-circle"></i>
                    <span>
                        <?php 
                        if ($currentLevel == 3) {
                            echo "Final Approval";
                        } else {
                            echo "Approve Level $currentLevel";
                        }
                        ?>
                    </span>
                    <small>
                        <?php 
                        if ($currentLevel == 3) {
                            echo "Complete all 3 approval levels";
                        } else {
                            echo "Mark Level $currentLevel as approved";
                        }
                        ?>
                    </small>
                </button>
                
                <?php if ($currentLevel < 3): ?>
                <button type="button" class="action-btn send-next" onclick="setAction('send_to_next')">
                    <i class="fas fa-arrow-right"></i>
                    <span>Send to Level <?php echo $currentLevel + 1; ?></span>
                    <small>Forward to next approver</small>
                </button>
                <?php endif; ?>
                
                <button type="button" class="action-btn reject" onclick="setAction('reject')">
                    <i class="fas fa-times-circle"></i>
                    <span>Reject Application</span>
                    <small>Stop the approval process</small>
                </button>
            </div>
            
           
        <?php else: ?>
            <!-- Show message if not authorized -->
            <div class="blocked-message" style="text-align: center; padding: 40px; background: linear-gradient(135deg, rgba(26, 77, 51, 0.3), rgba(10, 47, 29, 0.4)); border-radius: 15px; border: 2px dashed var(--border); margin-top: 20px;">
                <div style="font-size: 60px; color: var(--text-muted); margin-bottom: 20px;">
                    <i class="fas fa-user-lock"></i>
                </div>
                <h3 style="color: var(--text); margin-bottom: 15px; font-size: 24px;">
                    <?php echo $isApprover ? "Not Authorized for Level $currentLevel" : "Admin Access Required"; ?>
                </h3>
                <div style="max-width: 600px; margin: 0 auto 25px;">
                    <p style="color: var(--text-muted); margin-bottom: 15px; font-size: 16px; line-height: 1.6;">
                        <?php if ($isApprover): ?>
                            You are logged in as Level <?php echo $approverLevel; ?> Approver, 
                            but this application requires Level <?php echo $currentLevel; ?> approval.
                        <?php else: ?>
                            You are logged in as Admin. 
                            <?php if ($currentLevel > 0): ?>
                                This application is currently at Level <?php echo $currentLevel; ?> of the approval process.
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    
                    <!-- Show assigned approvers for current level -->
                    <?php if (!empty($levelApprovers[$currentLevel])): ?>
                        <div style="background: rgba(34, 197, 94, 0.1); padding: 20px; border-radius: 12px; border: 1px solid rgba(34, 197, 94, 0.3); margin-top: 20px;">
                            <h4 style="color: var(--neon); margin-bottom: 10px; font-size: 18px;">
                                <i class="fas fa-users"></i> Assigned Approver for Level <?php echo $currentLevel; ?>
                            </h4>
                            <div style="color: var(--text);">
                                <?php foreach ($levelApprovers[$currentLevel] as $approver): ?>
                                    <div style="padding: 12px 15px; background: rgba(26, 77, 51, 0.5); border-radius: 8px; margin-bottom: 8px; border-left: 4px solid var(--accent);">
                                        <i class="fas fa-user-check" style="color: var(--accent); margin-right: 10px;"></i>
                                        <strong><?php echo htmlspecialchars($approver['name']); ?></strong>
                                        <?php if ($approver['email']): ?>
                                            <div style="font-size: 14px; color: var(--text-muted); margin-top: 5px;">
                                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($approver['email']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <!-- Show final status when approved/rejected -->
        <div class="final-status" style="text-align: center; padding: 40px; background: <?php echo $status == 'approved' ? 'linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05))' : 'linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05))'; ?>; border-radius: 18px; border: 2px solid <?php echo $status == 'approved' ? 'var(--success)' : 'var(--error)'; ?>; margin-top: 20px;">
            <div style="font-size: 70px; color: <?php echo $status == 'approved' ? 'var(--success)' : 'var(--error)'; ?>; margin-bottom: 20px;">
                <i class="fas <?php echo $status == 'approved' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
            </div>
            <h3 style="color: var(--text); margin-bottom: 10px; font-size: 28px; font-weight: 800;">
                Application <?php echo strtoupper($status); ?>
            </h3>
            
            <div style="background: rgba(26, 77, 51, 0.3); padding: 20px; border-radius: 12px; max-width: 600px; margin: 20px auto;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                    <div>
                        <div style="color: var(--text-muted); font-size: 14px; margin-bottom: 5px;">Status</div>
                        <div style="color: <?php echo $status == 'approved' ? 'var(--success)' : 'var(--error)'; ?>; font-weight: 700; font-size: 18px;">
                            <?php echo ucfirst($status); ?>
                        </div>
                    </div>
                    <div>
                        <div style="color: var(--text-muted); font-size: 14px; margin-bottom: 5px;">Reviewed By</div>
                        <div style="color: var(--text); font-weight: 600;">
                            <?php echo htmlspecialchars($application['reviewed_by'] ?? 'System'); ?>
                        </div>
                    </div>
                    <div>
                        <div style="color: var(--text-muted); font-size: 14px; margin-bottom: 5px;">Date</div>
                        <div style="color: var(--text); font-weight: 600;">
                            <?php echo $application['formatted_reviewed'] ?? date('F d, Y'); ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($application['admin_notes']): ?>
                    <div style="background: rgba(26, 77, 51, 0.5); padding: 15px; border-radius: 10px; margin-top: 15px; text-align: left;">
                        <div style="color: var(--text-muted); font-size: 14px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-sticky-note"></i> Review Notes
                        </div>
                        <div style="color: var(--text); line-height: 1.5;">
                            <?php echo nl2br(htmlspecialchars($application['admin_notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>





<!-- Approver Documents Section -->
<div class="info-section animate-in" style="animation-delay: 0.2s; margin-top: 30px;">
    <div class="section-title">
        <i class="fas fa-file-contract"></i>
        <span>Approver Documents</span>
        <span class="badge" style="background: linear-gradient(135deg, var(--info), #3b82f6); margin-left: auto;">
            <?php echo count($approverDocs); ?> documents
        </span>
    </div>
    
    <?php if (empty($approverDocs)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 60px; margin-bottom: 20px; opacity: 0.3;">
                <i class="fas fa-file-upload"></i>
            </div>
            <h4 style="color: var(--text); margin-bottom: 10px; font-size: 18px;">
                No Approver Documents Yet
            </h4>
            <p style="color: var(--text-muted); max-width: 400px; margin: 0 auto;">
                Documents will appear here when approvers upload signed approval documents.
            </p>
        </div>
    <?php else: ?>
        <div class="approver-documents">
            <?php 
            $levelColors = [
                1 => 'linear-gradient(135deg, #3b82f6, #1d4ed8)',  // Blue
                2 => 'linear-gradient(135deg, #8b5cf6, #7c3aed)',  // Purple
                3 => 'linear-gradient(135deg, #10b981, #059669)'   // Green
            ];
            
            foreach ($approverDocs as $doc): 
            ?>
            <div class="document-item" style="border-left: 4px solid rgba(34, 197, 94, 0.5);">
                <div class="document-info">
                    <div class="document-level" style="background: <?php echo $levelColors[$doc['approver_level']] ?? 'linear-gradient(135deg, var(--accent), var(--neon))'; ?>;">
                        <?php echo $doc['approver_level']; ?>
                    </div>
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                            <div style="font-weight: 700; color: var(--text);">
                                Level <?php echo $doc['approver_level']; ?> Approval Document
                            </div>
                            <span style="font-size: 12px; padding: 3px 10px; background: rgba(34, 197, 94, 0.1); color: var(--accent); border-radius: 12px;">
                                <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($doc['uploaded_by']); ?>
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <i class="fas fa-file-pdf" style="color: var(--error);"></i>
                                <span style="color: var(--text-muted); font-size: 13px;">
                                    <?php echo htmlspecialchars($doc['file_name']); ?>
                                </span>
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted);">
                                <i class="fas fa-calendar-alt"></i> <?php echo $doc['formatted_uploaded']; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="file-actions">
                    <a href="../uploads/approvers/<?php echo $doc['file_name']; ?>" target="_blank" class="btn-action tooltip" data-tooltip="View Document">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="../uploads/approvers/<?php echo $doc['file_name']; ?>" download class="btn-action tooltip" data-tooltip="Download Document">
                        <i class="fas fa-download"></i>
                    </a>
                    <?php if ($isApprover && $approverLevel == $doc['approver_level'] || !$isApprover): ?>
                        <button onclick="confirmDeleteDocument(<?php echo $doc['id']; ?>)" class="btn-action tooltip" data-tooltip="Delete Document" style="color: var(--error); background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3);">
                            <i class="fas fa-trash"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Summary of approver documents -->
        <div style="margin-top: 25px; padding: 20px; background: linear-gradient(135deg, rgba(10, 47, 29, 0.4), rgba(6, 78, 59, 0.3)); border-radius: 12px; border: 1px solid var(--border);">
            <h4 style="color: var(--neon); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-chart-bar"></i> Document Summary
            </h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                <?php 
                $levelCounts = [];
                foreach ($approverDocs as $doc) {
                    $levelCounts[$doc['approver_level']] = ($levelCounts[$doc['approver_level']] ?? 0) + 1;
                }
                
                for ($i = 1; $i <= 3; $i++):
                    $count = $levelCounts[$i] ?? 0;
                ?>
                <div style="text-align: center; padding: 15px; background: rgba(26, 77, 51, 0.5); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="font-size: 28px; font-weight: 800; color: <?php echo $i == 1 ? '#3b82f6' : ($i == 2 ? '#8b5cf6' : '#10b981'); ?>; margin-bottom: 5px;">
                        <?php echo $count; ?>
                    </div>
                    <div style="color: var(--text-muted); font-size: 14px;">Level <?php echo $i; ?> Documents</div>
                </div>
                <?php endfor; ?>
                
                <div style="text-align: center; padding: 15px; background: rgba(34, 197, 94, 0.1); border-radius: 10px; border: 1px solid rgba(34, 197, 94, 0.3);">
                    <div style="font-size: 28px; font-weight: 800; color: var(--accent); margin-bottom: 5px;">
                        <?php echo count($approverDocs); ?>
                    </div>
                    <div style="color: var(--text-muted); font-size: 14px;">Total Documents</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>














<?php
// If you don't have an approver_assignments table, create it with this SQL:


?>






















 
     
            
          
            
            <!-- Status Update Form -->
            <div class="status-form animate-in" style="animation-delay: 0.3s;" id="status-form">
                <div class="section-title">
                    <i class="fas fa-edit"></i>
                    <span>Update Application Status</span>
                    <span class="badge" style="background: linear-gradient(135deg, var(--accent), var(--neon)); margin-left: auto;">
                        Current Approver: Level <?php echo $currentLevel; ?>
                    </span>
                </div>
                
                <form method="POST" action="" enctype="multipart/form-data" id="statusUpdateForm">
                    <input type="hidden" name="approver_level" id="approverLevel" value="<?php echo $currentLevel; ?>">
                    <input type="hidden" name="next_action" id="nextAction" value="">
                    <input type="hidden" name="status" id="statusField" value="pending">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_notes">
                                <i class="fas fa-sticky-note"></i> Review Notes
                                <small style="color: var(--text-muted); font-weight: normal; float: right;" id="notesCounter">0/1000</small>
                            </label>
                            <textarea id="admin_notes" name="admin_notes" 
                                      placeholder="Add your review notes, comments, or feedback about this application..."
                                      oninput="updateNotesCounter()"><?php echo htmlspecialchars($application['admin_notes'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-file-pdf"></i> Upload Approval Document (PDF)
                                <small style="color: var(--text-muted); font-weight: normal;">Optional - Upload signed approval document</small>
                            </label>
                            <div class="file-upload-container">
                                <label class="file-upload-label" for="approver_file">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span style="font-weight: 600; color: var(--accent);">Click to upload PDF document</span>
                                    <span style="color: var(--text-muted); font-size: 14px;">Max file size: 10MB | PDF only</span>
                                </label>
                                <input type="file" id="approver_file" name="approver_file" class="file-input" accept=".pdf,.jpg,.jpeg,.png" onchange="previewFile(this)">
                                
                                <div class="file-preview" id="filePreview"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="btn-group">
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="update_status" class="btn btn-primary btn-full" id="submitBtn" disabled>
                            <i class="fas fa-paper-plane"></i> Submit for Approval
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
// Add permission check before showing action buttons
function checkApprovalPermissions() {
    const currentLevel = <?php echo $currentLevel; ?>;
    const status = '<?php echo $status; ?>';
    const isApprover = <?php echo $isApproverForThisLevel ? 'true' : 'false'; ?>;
    
    if (status === 'approved' || status === 'rejected') {
        return false;
    }
    
    if (!isApprover) {
        showToast('You do not have permission to approve this application', 'error');
        return false;
    }
    
    return true;
}

// Update the setAction function
function setAction(action) {
    // Check permissions first
    if (!checkApprovalPermissions()) {
        return;
    }
    
    // Rest of your existing setAction code...
    selectedAction = action;
    // ... continue with original code
}





























        // Initialize variables
        let selectedAction = '';
        let notesAutoSaveTimer;
        
        // Toast notification function
        function showToast(message, type = 'info') {
            // Remove existing toasts
            const existingToasts = document.querySelectorAll('.toast');
            existingToasts.forEach(toast => toast.remove());
            
            // Create new toast
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(toast);
            
            // Remove toast after 5 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 5000);
        }
        
        // Set action for approval
        function setAction(action) {
            selectedAction = action;
            document.getElementById('nextAction').value = action;
            
            // Update status field based on action
            let status = 'pending';
            if (action === 'approve' && <?php echo $currentLevel; ?> == 3) {
                status = 'approved';
            } else if (action === 'send_to_next' && <?php echo $currentLevel; ?> == 3) {
                status = 'approved';
            } else if (action === 'reject') {
                status = 'rejected';
            }
            document.getElementById('statusField').value = status;
            
            // Update submit button text
            const submitBtn = document.getElementById('submitBtn');
            let btnText = '';
            let btnIcon = '';
            
            switch(action) {
                case 'approve':
                    btnText = <?php echo $currentLevel; ?> == 3 ? 'Final Approve Application' : 'Approve (Level <?php echo $currentLevel; ?>)';
                    btnIcon = 'fa-check-circle';
                    break;
                case 'send_to_next':
                    if (<?php echo $currentLevel; ?> < 3) {
                        btnText = 'Send to Level ' + (<?php echo $currentLevel; ?> + 1);
                    } else {
                        btnText = 'Final Approval';
                    }
                    btnIcon = 'fa-arrow-right';
                    break;
                case 'reject':
                    btnText = 'Reject Application';
                    btnIcon = 'fa-times-circle';
                    break;
            }
            
            submitBtn.innerHTML = `<i class="fas ${btnIcon}"></i> ${btnText}`;
            submitBtn.disabled = false;
            
            // Show confirmation for reject
            if (action === 'reject') {
                if (!confirm('⚠️ Are you sure you want to REJECT this application?\n\nThis action will notify the investor and cannot be undone.')) {
                    resetForm();
                    return;
                }
            }
            
            // Show confirmation for final approval
            if (action === 'approve' && <?php echo $currentLevel; ?> == 3) {
                if (!confirm('✅ Are you sure you want to give FINAL APPROVAL to this application?\n\nThis will complete the approval process and notify the investor.')) {
                    resetForm();
                    return;
                }
            }
            
            // Scroll to form
            document.getElementById('status-form').scrollIntoView({ behavior: 'smooth' });
            
            showToast(`Action set to: ${btnText}`, 'info');
        }
        
        function resetForm() {
            selectedAction = '';
            document.getElementById('nextAction').value = '';
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit for Approval';
            showToast('Action cleared', 'info');
        }
        
        function previewFile(input) {
            const preview = document.getElementById('filePreview');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileSize = (file.size / 1024 / 1024).toFixed(2); // MB
                
                if (file.type !== 'application/pdf') {
                    showToast('Only PDF files are allowed for approver documents', 'error');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                if (fileSize > 10) {
                    showToast('File size must be less than 10MB', 'error');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                preview.innerHTML = `
                    <i class="fas fa-check-circle"></i>
                    <strong>${file.name}</strong> (${fileSize} MB)
                `;
                preview.style.color = 'var(--success)';
                showToast('File ready for upload', 'success');
            } else {
                preview.innerHTML = '';
            }
        }
        
        function updateNotesCounter() {
            const textarea = document.getElementById('admin_notes');
            const counter = document.getElementById('notesCounter');
            const length = textarea.value.length;
            counter.textContent = `${length}/1000`;
            
            // Auto-save notes after typing stops
            clearTimeout(notesAutoSaveTimer);
            notesAutoSaveTimer = setTimeout(saveNotes, 2000);
        }
        
        function saveNotes() {
            const formData = new FormData();
            formData.append('save_notes', 'true');
            formData.append('admin_notes', document.getElementById('admin_notes').value);
            formData.append('ajax', 'true');
            
            fetch('view_application.php?id=<?php echo $applicationId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Notes saved automatically');
                } else {
                    console.error('Failed to save notes:', data.error);
                }
            })
            .catch(error => {
                console.error('Error saving notes:', error);
            });
        }
        
        // Initialize form on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize notes counter
            updateNotesCounter();
            
            // Add form validation
            const form = document.getElementById('statusUpdateForm');
            form.addEventListener('submit', function(e) {
                if (!selectedAction) {
                    e.preventDefault();
                    showToast('Please select an approval action first (Approve, Reject, or Send to Next)', 'error');
                    return;
                }
                
                const notes = document.getElementById('admin_notes').value;
                if (!notes.trim() && selectedAction !== 'reject') {
                    if (!confirm('You haven\'t added any review notes. Continue without notes?')) {
                        e.preventDefault();
                        return;
                    }
                }
                
                // Show loading
                const loadingOverlay = document.getElementById('loadingOverlay');
                loadingOverlay.style.display = 'flex';
            });
            
            // Initialize particles
            createParticles();
        });
        
        // Create floating particles
        function createParticles() {
            const container = document.getElementById('particles');
            for (let i = 0; i < 20; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.width = Math.random() * 5 + 2 + 'px';
                particle.style.height = particle.style.width;
                particle.style.left = Math.random() * 100 + 'vw';
                particle.style.top = '100vh';
                particle.style.animationDelay = Math.random() * 10 + 's';
                particle.style.animationDuration = Math.random() * 20 + 10 + 's';
                particle.style.backgroundColor = Math.random() > 0.5 ? 'var(--accent)' : 'var(--neon)';
                container.appendChild(particle);
            }
        }
        
        // Copy text to clipboard
        function copyText(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copied to clipboard', 'success');
            }).catch(err => {
                showToast('Failed to copy', 'error');
            });
        }
        
        // Copy application data
        function copyApplicationData() {
            const data = `Application #EWF-<?php echo str_pad($applicationId, 6, '0', STR_PAD_LEFT); ?>
Name: <?php echo $application['full_name']; ?>
NIC: <?php echo $application['nic_no']; ?>
Email: <?php echo $application['email']; ?>
Status: <?php echo ucfirst($application['status']); ?>
Approver Level: <?php echo $currentLevel; ?>/3`;
            
            copyText(data);
        }
        
        // Print application
        function printApplication() {
            window.print();
        }
        
        // Download full application
        function downloadFullApplication() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.style.display = 'flex';
            
            // Create form to submit POST request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download_full_application.php?id=<?php echo $applicationId; ?>';
            
            // Add hidden input for POST request
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'download_full';
            input.value = '1';
            form.appendChild(input);
            
            document.body.appendChild(form);
            form.submit();
            
            // Hide loading after 5 seconds
            setTimeout(() => {
                loadingOverlay.style.display = 'none';
            }, 5000);
        }

        function previewFile(input) {
    const preview = document.getElementById('filePreview');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileSize = (file.size / 1024 / 1024).toFixed(2); // MB
        
        // Allow multiple file types
        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        if (!allowedTypes.includes(file.type)) {
            showToast('Only PDF, JPEG, and PNG files are allowed for approver documents', 'error');
            input.value = '';
            preview.innerHTML = '';
            return;
        }
        
        if (fileSize > 10) {
            showToast('File size must be less than 10MB', 'error');
            input.value = '';
            preview.innerHTML = '';
            return;
        }
        
        // Show file icon based on type
        let fileIcon = 'fa-file-pdf';
        if (file.type.includes('image')) {
            fileIcon = 'fa-file-image';
        }
        
        preview.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: rgba(16, 185, 129, 0.1); border-radius: 8px;">
                <i class="fas ${fileIcon}" style="color: var(--success); font-size: 20px;"></i>
                <div>
                    <strong style="color: var(--text);">${file.name}</strong><br>
                    <small style="color: var(--text-muted);">Size: ${fileSize} MB • Type: ${file.type.split('/')[1].toUpperCase()}</small>
                </div>
            </div>
        `;
        preview.style.color = 'var(--success)';
        showToast('File ready for upload', 'success');
    } else {
        preview.innerHTML = '';
    }
}
        
    </script>
</body>
</html>

<?php
// Helper function to get application count
function getApplicationCount($db) {
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM investors");
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}
?>