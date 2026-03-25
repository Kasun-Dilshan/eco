<?php
session_start();
require_once '../config.php';
require_once '../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Set JSON header
header('Content-Type: application/json');

// Get POST data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status = isset($_POST['status']) ? $_POST['status'] : '';
$approver = isset($_POST['approver']) ? $_POST['approver'] : '';

// Validate inputs
if ($id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid application ID'
    ]);
    exit();
}

if (!in_array($status, ['approved', 'rejected', 'pending'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status value'
    ]);
    exit();
}

// Set default approver if not provided
if (empty($approver)) {
    $approver = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin';
}

try {
    // Check if the application exists
    $checkStmt = $db->prepare("SELECT id, email, status, investment_type FROM investors WHERE id = ?");
    $checkStmt->execute([$id]);
    $application = $checkStmt->fetch();
    
    if (!$application) {
        echo json_encode([
            'success' => false,
            'message' => 'Application not found'
        ]);
        exit();
    }
    
    $currentStatus = $application['status'];
    
    // Update application status with approver info
    $updateStmt = $db->prepare("
        UPDATE investors 
        SET status = ?, 
            current_approver = ?,
            approval_date = IF(? IN ('approved', 'rejected'), NOW(), NULL),
            updated_at = NOW() 
        WHERE id = ?
    ");
    $updateStmt->execute([$status, $approver, $status, $id]);

    $planCode = strtoupper(trim((string)($application['investment_type'] ?? '')));
    $applicationRef = ($planCode !== '' ? ($planCode . '-') : '') . str_pad($id, 6, '0', STR_PAD_LEFT);
    
    // Log the status change
    $logStmt = $db->prepare("
        INSERT INTO application_logs (investor_id, action, description, performed_by)
        VALUES (?, ?, ?, ?)
    ");
    
    $description = "Status changed from {$currentStatus} to {$status}";
    $logStmt->execute([$id, 'status_update', $description, $approver]);
    
    // Send email notification
    if (in_array($status, ['approved', 'rejected'])) {
        $subject = "Your EcoWealth Application Status Update";
        $statusClass = ($status === 'approved') ? 'status-approved' : 'status-rejected';
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .header { background: #0a2f1d; color: white; padding: 20px; }
                .content { padding: 20px; }
                .footer { background: #f4f4f4; padding: 10px; text-align: center; }
                .status-approved { color: #10b981; font-weight: bold; }
                .status-rejected { color: #ef4444; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>EcoWealth Finance</h2>
            </div>
            <div class='content'>
                <h3>Application Status Update</h3>
                <p>Dear Investor,</p>
                <p>Your investment application (ID: {$applicationRef}) has been <span class='{$statusClass}'>{$status}</span>.</p>";
        
        if ($status === 'approved') {
            $message .= "<p>Congratulations! Your application has been approved.</p>
                        <p>Approved by: {$approver}</p>
                        <p>Our team will contact you shortly with further details.</p>";
        } elseif ($status === 'rejected') {
            $message .= "<p>We regret to inform you that your application has been rejected.</p>
                        <p>For more information, please contact our support team.</p>";
        }
        
        $message .= "
                <p>Thank you for choosing EcoWealth Finance.</p>
            </div>
            <div class='footer'>
                <p>© " . date('Y') . " EcoWealth Finance. All rights reserved.</p>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . ADMIN_EMAIL . "\r\n";
        $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        mail($application['email'], $subject, $message, $headers);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'status' => $status,
        'approver' => $approver
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in admin_update.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again.'
    ]);
}
?>