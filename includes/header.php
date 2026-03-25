<?php
session_start();
ob_start();

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Set timezone
date_default_timezone_set('Asia/Colombo');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Function to sanitize input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Function to validate NIC
function validateNIC($nic) {
    // Sri Lankan NIC validation (old 10-digit or new 12-digit)
    $nic = strtoupper($nic);
    if (preg_match('/^[0-9]{9}[VX]$/', $nic)) {
        return true; // Old NIC
    }
    if (preg_match('/^[0-9]{12}$/', $nic)) {
        return true; // New NIC
    }
    return false;
}

// Function to validate email
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Function to generate reference number
function generateReferenceNumber() {
    $prefix = 'EW';
    $date = date('Ymd');
    $random = strtoupper(bin2hex(random_bytes(3)));
    return $prefix . $date . $random;
}

// File upload validation
function validateUploadedFile($file, $type = 'image') {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed with error code: ' . $file['error'];
        return $errors;
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = 'File size exceeds maximum limit of 5MB';
    }
    
    // Check file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, ALLOWED_IMAGE_TYPES)) {
        $errors[] = 'Only JPG, PNG, GIF, and PDF files are allowed';
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowed_mimes = [
        'image/jpeg', 'image/png', 'image/gif', 
        'image/jpg', 'application/pdf'
    ];
    
    if (!in_array($mime_type, $allowed_mimes)) {
        $errors[] = 'Invalid file type detected';
    }
    
    return $errors;
}

// Function to log application activity
function logActivity($investor_id, $action, $details = null) {
    $conn = getDBConnection();
    $sql = "INSERT INTO application_logs (investor_id, action, details) 
            VALUES (:investor_id, :action, :details)";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':investor_id' => $investor_id,
            ':action' => $action,
            ':details' => $details
        ]);
        return true;
    } catch(PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}
?>