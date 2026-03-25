<?php
session_start();
require_once '../config.php';
require_once '../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$whereClause = '';
$params = [];

if (!empty($search)) {
    $whereClause = "WHERE (i.full_name LIKE ? OR i.email LIKE ? OR i.nic_no LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_fill(0, 3, $searchTerm);
}

if ($filter !== 'all') {
    if ($whereClause) {
        $whereClause .= " AND i.status = ?";
    } else {
        $whereClause = "WHERE i.status = ?";
    }
    $params[] = $filter;
}

try {
    // Get applications
    $query = "
        SELECT i.*, 
               GROUP_CONCAT(CONCAT(b.beneficiary_name, ' (', b.percentage, '%)') SEPARATOR '; ') as beneficiaries,
               COUNT(b.id) as beneficiary_count,
               a.username as reviewed_by_name
        FROM investors i
        LEFT JOIN beneficiaries b ON i.id = b.investor_id
        LEFT JOIN admin_users a ON i.reviewed_by = a.id
        $whereClause
        GROUP BY i.id
        ORDER BY i.created_at DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=ecowealth_applications_' . date('Y-m-d') . '.csv');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
    
    // Add CSV headers
    $headers = [
        'Application ID',
        'Full Name',
        'Name with Initials',
        'NIC No',
        'Phone',
        'Email',
        'Date of Birth',
        'Address',
        'Occupation',
        'Employer',
        'Investment Years',
        'Signing Date',
        'Bank Account',
        'Bank Name',
        'Branch',
        'Declaration Date',
        'Status',
        'Beneficiaries',
        'Beneficiary Count',
        'Reviewed By',
        'Submitted Date',
        'Last Updated'
    ];
    
    fputcsv($output, $headers);
    
    // Add data rows
    foreach ($applications as $app) {
        $row = [
            'EWF-' . str_pad($app['id'], 6, '0', STR_PAD_LEFT),
            $app['full_name'],
            $app['name_with_initials'],
            $app['nic_no'],
            $app['tel_no'],
            $app['email'],
            $app['dob'],
            $app['address'],
            $app['occupation'],
            $app['employer_name'],
            $app['years'],
            $app['signing_date'],
            $app['account_no'],
            $app['bank_name'],
            $app['branch_name'],
            $app['declaration_date'],
            ucfirst($app['status']),
            $app['beneficiaries'],
            $app['beneficiary_count'],
            $app['reviewed_by_name'],
            $app['created_at'],
            $app['updated_at']
        ];
        
        fputcsv($output, $row);
    }
    
    // Close output stream
    fclose($output);
    
    // Log export action
    $stmt = $db->prepare("
        INSERT INTO admin_audit_logs (admin_id, action, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['admin_id'],
        'export_data',
        "Exported applications data (filter: {$filter}, search: {$search})",
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    
    exit();
    
} catch (PDOException $e) {
    // If CSV fails, redirect back with error
    header('Location: applications.php?error=export_failed');
    exit();
}
?>