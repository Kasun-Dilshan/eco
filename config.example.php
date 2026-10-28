<?php

// Copy this file to config.php and update values for your hosting environment.

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// URL path when app is in a subfolder (e.g. '/eco-wealth-portal'). Use '' if at domain root.
define('BASE_PATH', '');

// User Types
define('USER_ADMIN', 'admin');
define('USER_STAFF', 'staff');
define('USER_BRANCH', 'branch');

// Application Settings
define('SITE_NAME', 'SERENDIB GREEN PLANTATION PVT LTD');
define('SITE_URL', 'https://yourdomain.com');
define('ADMIN_EMAIL', 'admin@ecowealth.com');

// Approver notification emails (scalar defines — safe on all PHP 7+/8+)
// Level 3 overrides DB when set. Leave blank to use approvers table.
define('APPROVER_LEVEL_1_EMAIL', '');
define('APPROVER_LEVEL_2_EMAIL', 'isurindi@serendibgroups.com');
define('APPROVER_LEVEL_3_EMAIL', 'seneth@serendibgroups.com');

// File Upload Settings
define('MAX_FILE_SIZE_MB', 3);
define('MAX_FILE_SIZE', MAX_FILE_SIZE_MB * 1024 * 1024); // 3MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf']);
define('UPLOAD_DIR', dirname(__FILE__) . '/uploads/');

// Admin Settings
define('ADMIN_SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// Agreement Template Settings
define('AGREEMENT_DOCX_TEMPLATE_PATH_HPP', dirname(__FILE__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'HPP_agreement.docx');
define('AGREEMENT_DOCX_TEMPLATE_PATH_HPP_PREMIUM', dirname(__FILE__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'HPP_premium_agreement.docx');
define('AGREEMENT_DOCX_TEMPLATE_PATH', AGREEMENT_DOCX_TEMPLATE_PATH_HPP);

function agreementTemplateIdForInvestmentType(string $investmentType): int {
    switch (strtoupper(trim($investmentType))) {
        case 'HPP': return 1;
        case 'GSP': return 2;
        case 'GSI': return 3;
        case 'GOLD': return 4;
        case 'SFPS': return 5;
        case 'SFHPS': return 6;
        default: return 1;
    }
}

function agreementDocxTemplatePathForInvestmentType(string $investmentType): string {
    return agreementDocxTemplatePathForInvestment($investmentType, 0, 1);
}

function agreementDocxTemplatePathForInvestment(string $investmentType, float $deposit = 0, int $years = 1): string {
    $type = strtoupper(trim($investmentType));
    $fallback = defined('AGREEMENT_DOCX_TEMPLATE_PATH') ? AGREEMENT_DOCX_TEMPLATE_PATH : '';

    if ($type === 'HPP' && function_exists('isHppPremiumAgreement') && isHppPremiumAgreement($deposit, $years, $type)) {
        $premium = defined('AGREEMENT_DOCX_TEMPLATE_PATH_HPP_PREMIUM') ? constant('AGREEMENT_DOCX_TEMPLATE_PATH_HPP_PREMIUM') : null;
        if ($premium && file_exists($premium)) {
            return $premium;
        }
    }

    $map = [
        'HPP' => defined('AGREEMENT_DOCX_TEMPLATE_PATH_HPP') ? constant('AGREEMENT_DOCX_TEMPLATE_PATH_HPP') : null,
        'GSP' => defined('AGREEMENT_DOCX_TEMPLATE_PATH_GSP') ? constant('AGREEMENT_DOCX_TEMPLATE_PATH_GSP') : null,
        'GSI' => defined('AGREEMENT_DOCX_TEMPLATE_PATH_GSI') ? constant('AGREEMENT_DOCX_TEMPLATE_PATH_GSI') : null,
        'GOLD' => defined('AGREEMENT_DOCX_TEMPLATE_PATH_GOLD') ? constant('AGREEMENT_DOCX_TEMPLATE_PATH_GOLD') : null,
        'SFPS' => defined('AGREEMENT_DOCX_TEMPLATE_PATH_SFPS') ? constant('AGREEMENT_DOCX_TEMPLATE_PATH_SFPS') : null,
        'SFHPS' => defined('AGREEMENT_DOCX_TEMPLATE_PATH_SFHPS') ? constant('AGREEMENT_DOCX_TEMPLATE_PATH_SFHPS') : null,
    ];

    $p = $map[$type] ?? null;
    return $p && file_exists($p) ? $p : $fallback;
}

date_default_timezone_set('Asia/Colombo');

// Production: hide errors from visitors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (!file_exists(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}
if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
    error_log('EcoWealth: uploads directory is missing or not writable: ' . UPLOAD_DIR);
}

$htaccess_content = "Order deny,allow\nDeny from all\n<Files ~ \"\.(jpg|jpeg|png|pdf)$\">\n    Allow from all\n</Files>";
$htaccess_path = UPLOAD_DIR . '.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path, $htaccess_content);
}

function debug_log($message, $data = null) {
    $log_file = dirname(__FILE__) . '/debug.log';
    $log_message = date('Y-m-d H:i:s') . " - " . $message;
    if ($data) {
        $log_message .= " - " . print_r($data, true);
    }
    $log_message .= "\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdminAuth() {
    if (!isAdminLoggedIn()) {
        $base = defined('BASE_PATH') ? BASE_PATH : '';
        header('Location: ' . $base . '/admin/');
        exit();
    }
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

define('USER_BRANCH_MANAGER', 'branch_manager');

define('USER_ACTIVE', 'active');
define('USER_INACTIVE', 'inactive');
define('USER_SUSPENDED', 'suspended');

$logs_dir = dirname(__FILE__) . '/logs/';
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}
?>
