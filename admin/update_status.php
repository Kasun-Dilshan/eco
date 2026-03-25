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

try {
    // First, check if the application exists
    $checkStmt = $db->prepare("SELECT id, email, investment_type FROM investors WHERE id = ?");
    $checkStmt->execute([$id]);
    $application = $checkStmt->fetch();
    
    if (!$application) {
        echo json_encode([
            'success' => false,
            'message' => 'Application not found'
        ]);
        exit();
    }
    
    // Get current status for logging
    $currentStmt = $db->prepare("SELECT status FROM investors WHERE id = ?");
    $currentStmt->execute([$id]);
    $currentStatus = $currentStmt->fetchColumn();
    
    // Update application status
    $updateStmt = $db->prepare("UPDATE investors SET status = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$status, $id]);

    $planCode = strtoupper(trim((string)($application['investment_type'] ?? '')));
    $applicationRef = ($planCode !== '' ? ($planCode . '-') : '') . str_pad($id, 6, '0', STR_PAD_LEFT);
    
    // Log the status change
    $logStmt = $db->prepare("
        INSERT INTO application_logs (investor_id, action, description, performed_by)
        VALUES (?, ?, ?, ?)
    ");
    
    $adminName = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin';
    $description = "Status changed from {$currentStatus} to {$status}";
    
    $logStmt->execute([
        $id,
        'status_update',
        $description,
        $adminName
    ]);
    
    // Send email notification if status changed to approved or rejected
    if (in_array($status, ['approved', 'rejected'])) {
        $subject = "Your EcoWealth Application Status Update";
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
                <p>Your investment application (ID: {$applicationRef}) has been <span class='status-{$status}'>{$status}</span>.</p>";
        
        if ($status === 'approved') {
            $message .= "<p>Congratulations! Your application has been approved. Our team will contact you shortly with further details.</p>";
        } elseif ($status === 'rejected') {
            $message .= "<p>We regret to inform you that your application has been rejected. For more information, please contact our support team.</p>";
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
        'status' => $status
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in admin_update.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please check server logs.'
    ]);
} catch (Exception $e) {
    error_log("General error in admin_update.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
?>