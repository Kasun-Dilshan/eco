<?php
require_once '../config.php';
require_once '../db.php';
require_once 'includes/User.php';

// Only run this script if there are no users yet
$user = new User();
$userCount = $user->countUsers();

if ($userCount > 0) {
    die('Admin user already exists. Please login normally.');
}

// Create admin user
$result = $user->createUser([
    'username' => 'admin',
    'email' => 'admin@ecowealth.com',
    'full_name' => 'System Administrator',
    'user_type' => 'admin',
    'status' => 'active',
    'password' => 'Admin@123', // Change this immediately after first login!
    'permissions' => [] // Empty array - admin gets all permissions by default
]);

if ($result['success']) {
    echo "Admin user created successfully!<br>";
    echo "Username: admin<br>";
    echo "Password: Admin@123<br>";
    echo "Email: admin@ecowealth.com<br><br>";
    echo "<strong>IMPORTANT:</strong> Change this password immediately after first login!<br>";
    echo '<a href="index.php">Go to Login Page</a>';
} else {
    echo "Error creating admin user: " . $result['message'];
}
?>