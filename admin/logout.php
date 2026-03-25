<?php
session_start();
require_once '../config.php';

// Log audit trail
if (isset($_SESSION['admin_id'])) {
    try {
        require_once '../db.php';
        $stmt = $db->prepare("
            INSERT INTO admin_audit_logs (admin_id, action, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['admin_id'],
            'logout',
            'Admin logged out',
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    } catch (Exception $e) {
        // Continue with logout even if logging fails
    }
}

// Destroy all session data
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: index.php');
exit();
?>