<?php
session_start();
require_once '../config.php';
require_once '../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$applicationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$zip = isset($_GET['zip']);

if (!$applicationId) {
    header('Location: applications.php');
    exit();
}

try {
    // Get application details
    $stmt = $db->prepare("SELECT * FROM investors WHERE id = ?");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    
    if (!$application) {
        header('Location: applications.php');
        exit();
    }
    
    // Get all file fields
    $files = [
        'signature_upload' => $application['signature_upload'],
        'investor_id_doc' => $application['investor_id_doc'],
        'beneficiary_id_doc' => $application['beneficiary_id_doc'],
        'passbook_doc' => $application['passbook_doc'],
        'payment_slip_doc' => $application['payment_slip_doc'],
        'final_signature' => $application['final_signature']
    ];
    
    // Filter out empty files
    $files = array_filter($files);
    
    // If zip parameter is set, create zip archive
    if ($zip && extension_loaded('zip')) {
        $zipFileName = 'application_' . $applicationId . '_' . time() . '.zip';
        $zipPath = UPLOAD_DIR . $zipFileName;
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            // Add all files to zip
            foreach ($files as $type => $filename) {
                $filePath = UPLOAD_DIR . $filename;
                if (file_exists($filePath)) {
                    $zip->addFile($filePath, $type . '_' . $filename);
                }
            }
            
            // Add application details as text file
            $details = "Application Details\n";
            $details .= "===================\n\n";
            $details .= "Application ID: " . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . "\n";
            $details .= "Full Name: " . $application['full_name'] . "\n";
            $details .= "NIC No: " . $application['nic_no'] . "\n";
            $details .= "Email: " . $application['email'] . "\n";
            $details .= "Phone: " . $application['tel_no'] . "\n";
            $details .= "Status: " . $application['status'] . "\n";
            $details .= "Submitted: " . $application['created_at'] . "\n\n";
            
            $zip->addFromString('application_details.txt', $details);
            $zip->close();
            
            // Send zip file
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            
            // Delete temp zip file
            unlink($zipPath);
            
            exit();
        }
    }
    
    // If no zip or zip failed, just download the first available file
    foreach ($files as $filename) {
        $filePath = UPLOAD_DIR . $filename;
        if (file_exists($filePath)) {
            $fileInfo = pathinfo($filePath);
            $mimeType = mime_content_type($filePath);
            
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            
            // Log download
            $stmt = $db->prepare("
                INSERT INTO admin_audit_logs (admin_id, action, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                'download_file',
                "Downloaded file: {$filename} for application #{$applicationId}",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            exit();
        }
    }
    
    // No files found
    header('Location: view_application.php?id=' . $applicationId . '&error=no_files');
    exit();
    
} catch (PDOException $e) {
    header('Location: view_application.php?id=' . $applicationId . '&error=db_error');
    exit();
}
?>