<?php
require_once '../config.php';
require_once '../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$applicationId = $_GET['id'] ?? 0;
if (!$applicationId) {
    header('Location: applications.php');
    exit();
}

try {
    // Get application details
    $stmt = $db->prepare("
        SELECT *, 
               DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created,
               DATE_FORMAT(dob, '%M %d, %Y') as formatted_dob,
               DATE_FORMAT(signing_date, '%M %d, %Y') as formatted_signing,
               DATE_FORMAT(declaration_date, '%M %d, %Y') as formatted_declaration
        FROM investors 
        WHERE id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    
    if (!$application) {
        die("Application not found");
    }
    
    // Get beneficiaries
    $stmt = $db->prepare("SELECT * FROM beneficiaries WHERE investor_id = ? ORDER BY percentage DESC");
    $stmt->execute([$applicationId]);
    $beneficiaries = $stmt->fetchAll();
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="EcoWealth_Application_' . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    // Create Excel content using simple HTML table (works with Excel)
    $html = '
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            table { border-collapse: collapse; width: 100%; }
            th { background-color: #0a2f1d; color: white; padding: 10px; text-align: left; }
            td { border: 1px solid #ddd; padding: 8px; }
            .section-title { background-color: #1a4d33; color: white; font-weight: bold; padding: 10px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <h1>ECO WEALTH FINANCE - APPLICATION SUMMARY</h1>
        <h2>Application ID: EWF-' . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . '</h2>
        
        <div class="section-title">Application Information</div>
        <table>
            <tr><th>Field</th><th>Value</th></tr>
            <tr><td>Application ID</td><td>EWF-' . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . '</td></tr>
            <tr><td>Status</td><td>' . ucfirst($application['status']) . '</td></tr>
            <tr><td>Submission Date</td><td>' . $application['formatted_created'] . '</td></tr>
            <tr><td>Generated Date</td><td>' . date('F d, Y H:i:s') . '</td></tr>
        </table>
        
        <div class="section-title">Personal Information</div>
        <table>
            <tr><th>Field</th><th>Value</th></tr>
            <tr><td>Full Name</td><td>' . htmlspecialchars($application['full_name']) . '</td></tr>
            <tr><td>Name with Initials</td><td>' . htmlspecialchars($application['name_with_initials']) . '</td></tr>
            <tr><td>NIC No</td><td>' . htmlspecialchars($application['nic_no']) . '</td></tr>
            <tr><td>Date of Birth</td><td>' . $application['formatted_dob'] . '</td></tr>
            <tr><td>Email</td><td>' . htmlspecialchars($application['email']) . '</td></tr>
            <tr><td>Phone</td><td>' . htmlspecialchars($application['tel_no']) . '</td></tr>
            <tr><td>Address</td><td>' . nl2br(htmlspecialchars($application['address'])) . '</td></tr>
        </table>
        
        <div class="section-title">Professional Information</div>
        <table>
            <tr><td>Occupation</td><td>' . htmlspecialchars($application['occupation']) . '</td></tr>
            <tr><td>Employer</td><td>' . htmlspecialchars($application['employer_name']) . '</td></tr>
            <tr><td>Investment Years</td><td>' . $application['years'] . ' years</td></tr>
            <tr><td>Signing Date</td><td>' . $application['formatted_signing'] . '</td></tr>
        </table>
        
        <div class="section-title">Bank Details</div>
        <table>
            <tr><td>Account No</td><td>' . htmlspecialchars($application['account_no']) . '</td></tr>
            <tr><td>Bank</td><td>' . htmlspecialchars($application['bank_name']) . '</td></tr>
            <tr><td>Branch</td><td>' . htmlspecialchars($application['branch_name']) . '</td></tr>
            <tr><td>Declaration Date</td><td>' . $application['formatted_declaration'] . '</td></tr>
        </table>';
    
    if (!empty($beneficiaries)) {
        $html .= '
        <div class="section-title">Beneficiaries</div>
        <table>
            <tr><th>Name</th><th>NIC No</th><th>Percentage</th></tr>';
        
        foreach ($beneficiaries as $beneficiary) {
            $html .= '<tr>
                <td>' . htmlspecialchars($beneficiary['beneficiary_name']) . '</td>
                <td>' . htmlspecialchars($beneficiary['beneficiary_nic']) . '</td>
                <td>' . $beneficiary['percentage'] . '%</td>
            </tr>';
        }
        
        $html .= '</table>';
    }
    
    $html .= '
        <div class="section-title">Uploaded Documents</div>
        <table>
            <tr><th>Document Type</th><th>File Name</th></tr>';
    
    $files = [
        'Signature' => $application['signature_upload'],
        'Investor ID' => $application['investor_id_doc'],
        'Beneficiary ID' => $application['beneficiary_id_doc'],
        'Passbook' => $application['passbook_doc'],
        'Payment Slip' => $application['payment_slip_doc'],
        'Final Signature' => $application['final_signature']
    ];
    
    foreach ($files as $label => $filename) {
        if ($filename) {
            $html .= '<tr>
                <td>' . $label . '</td>
                <td>' . htmlspecialchars(basename($filename)) . '</td>
            </tr>';
        }
    }
    
    $html .= '</table>
        
        <div style="margin-top: 30px; font-size: 10px; color: #666;">
            <p>EcoWealth Finance - Sustainable Investment Solutions</p>
            <p>Generated on ' . date('F d, Y H:i:s') . '</p>
        </div>
    </body>
    </html>';
    
    echo $html;
    
} catch (Exception $e) {
    die("Error generating Excel: " . $e->getMessage());
}
?>