<?php


// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'eco_wealth_portal');
define('DB_USER', 'root');
define('DB_PASS', '');




// User Types
define('USER_ADMIN', 'admin');
define('USER_STAFF', 'staff');
define('USER_BRANCH', 'branch');


// Application Settings
define('SITE_NAME', 'EcoWealth Finance');
define('SITE_URL', 'http://localhost/eco-wealth-portal');
define('ADMIN_EMAIL', 'admin@ecowealth.com');

// File Upload Settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf']);
define('UPLOAD_DIR', dirname(__FILE__) . '/uploads/');

// Admin Settings
define('ADMIN_SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// Timezone
date_default_timezone_set('Asia/Colombo');

// Error Reporting (Turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create uploads directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0777, true)) {
        die('Failed to create uploads directory. Please check permissions.');
    }
}

// Check if uploads directory is writable
if (!is_writable(UPLOAD_DIR)) {
    die('Uploads directory is not writable. Please check permissions.');
}

// Create .htaccess for uploads directory
$htaccess_content = "Order deny,allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|pdf)$\">\n    Allow from all\n</Files>";
$htaccess_path = UPLOAD_DIR . '.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path, $htaccess_content);
}

// Debug function
function debug_log($message, $data = null) {
    $log_file = dirname(__FILE__) . '/debug.log';
    $log_message = date('Y-m-d H:i:s') . " - " . $message;
    if ($data) {
        $log_message .= " - " . print_r($data, true);
    }
    $log_message .= "\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Function to check if user is admin
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Function to require admin authentication
function requireAdminAuth() {
    if (!isAdminLoggedIn()) {
        header('Location: /eco-wealth-portal/admin/');
        exit();
    }
}

// Function to sanitize input
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Function to generate random password
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}


// Add these to config.php
// User settings
//define('MAX_LOGIN_ATTEMPTS', 5);
//define('LOCKOUT_TIME', 15); // minutes
//define('PASSWORD_MIN_LENGTH', 8);
//define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

// User types

define('USER_BRANCH_MANAGER', 'branch_manager');


// User statuses
define('USER_ACTIVE', 'active');
define('USER_INACTIVE', 'inactive');
define('USER_SUSPENDED', 'suspended');

// Create logs directory
$logs_dir = dirname(__FILE__) . '/logs/';
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0777, true);
}
?>