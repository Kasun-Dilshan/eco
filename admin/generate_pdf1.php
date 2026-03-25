<?php
require_once '../config.php';
require_once '../db.php';



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
    
    // Create a temporary directory for processing
    $tempDir = sys_get_temp_dir() . '/ewf_app_' . $applicationId . '_' . time();
    if (!mkdir($tempDir, 0777, true)) {
        die("Failed to create temporary directory");
    }
    
    // Create PDF report
    $pdfContent = generatePDFContent($application, $beneficiaries);
    file_put_contents($tempDir . '/Application_Report.pdf', $pdfContent);
    
    // Create Excel summary
    $excelContent = generateExcelContent($application, $beneficiaries);
    file_put_contents($tempDir . '/Application_Summary.xlsx', $excelContent);
    
    // Create README file
    $readmeContent = generateReadmeContent($application, $beneficiaries);
    file_put_contents($tempDir . '/README.txt', $readmeContent);
    
    // Copy all uploaded files
    $files = [
        'signature_upload' => 'Signature',
        'investor_id_doc' => 'Investor_ID',
        'beneficiary_id_doc' => 'Beneficiary_ID',
        'passbook_doc' => 'Passbook',
        'payment_slip_doc' => 'Payment_Slip',
        'final_signature' => 'Final_Signature'
    ];
    
    foreach ($files as $field => $label) {
        if (!empty($application[$field])) {
            $sourceFile = '../uploads/' . $application[$field];
            if (file_exists($sourceFile)) {
                $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);
                $destFile = $tempDir . '/' . $label . '.' . $extension;
                copy($sourceFile, $destFile);
            }
        }
    }
    
    // Create ZIP file
    $zipFilename = $tempDir . '/EcoWealth_Application_' . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipFilename, ZipArchive::CREATE) === TRUE) {
        // Add all files to ZIP
        $filesToAdd = glob($tempDir . '/*');
        foreach ($filesToAdd as $file) {
            if ($file != $zipFilename) { // Don't add the zip file to itself
                $zip->addFile($file, basename($file));
            }
        }
        
        $zip->close();
        
        // Send ZIP to browser
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="EcoWealth_Application_' . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . '_Full.zip"');
        header('Content-Length: ' . filesize($zipFilename));
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($zipFilename);
        
        // Clean up temporary files
        array_map('unlink', glob($tempDir . '/*'));
        rmdir($tempDir);
        
        exit();
        
    } else {
        die("Failed to create ZIP file");
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper function to generate PDF content
function generatePDFContent($application, $beneficiaries) {
    $html = '
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
            .header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 3px solid #0a2f1d; }
            .header h1 { color: #0a2f1d; margin: 0; font-size: 28px; }
            .header h2 { color: #1a4d33; margin: 5px 0; font-size: 16px; }
            .application-id { background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #0a2f1d; }
            .section { margin: 25px 0; }
            .section-title { background: #1a4d33; color: white; padding: 10px 15px; font-size: 14px; font-weight: bold; margin-bottom: 15px; border-radius: 4px; }
            .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px; }
            .info-row { margin-bottom: 8px; }
            .info-label { font-weight: bold; color: #555; margin-bottom: 3px; }
            .info-value { color: #333; }
            .table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            .table th { background: #0a2f1d; color: white; padding: 10px; text-align: left; font-weight: bold; }
            .table td { padding: 10px; border: 1px solid #ddd; }
            .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 10px; color: #666; text-align: center; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>ECO WEALTH FINANCE</h1>
            <h2>Green Investment Application - Complete Report</h2>
        </div>
        
        <div class="application-id">
            <strong>Application ID:</strong> EWF-' . str_pad($application['id'], 6, '0', STR_PAD_LEFT) . '<br>
            <strong>Status:</strong> ' . ucfirst($application['status']) . '<br>
            <strong>Generated:</strong> ' . date('F d, Y H:i:s') . '
        </div>
        
        <div class="section">
            <div class="section-title">Personal Information</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Full Name</div>
                    <div class="info-value">' . htmlspecialchars($application['full_name']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">NIC No</div>
                    <div class="info-value">' . htmlspecialchars($application['nic_no']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date of Birth</div>
                    <div class="info-value">' . $application['formatted_dob'] . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email</div>
                    <div class="info-value">' . htmlspecialchars($application['email']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Phone</div>
                    <div class="info-value">' . htmlspecialchars($application['tel_no']) . '</div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Beneficiaries</div>';
    
    if (!empty($beneficiaries)) {
        $html .= '<table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>NIC No</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($beneficiaries as $beneficiary) {
            $html .= '<tr>
                <td>' . htmlspecialchars($beneficiary['beneficiary_name']) . '</td>
                <td>' . htmlspecialchars($beneficiary['beneficiary_nic']) . '</td>
                <td>' . $beneficiary['percentage'] . '%</td>
            </tr>';
        }
        
        $html .= '</tbody></table>';
    } else {
        $html .= '<p>No beneficiaries found</p>';
    }
    
    $html .= '</div>
        
        <div class="footer">
            <p><strong>EcoWealth Finance</strong> | Sustainable Investment Solutions</p>
            <p>This is a complete application report. See included files for all documents.</p>
        </div>
    </body>
    </html>';
    
    return $html;
}

// Helper function to generate Excel content (simplified version)
function generateExcelContent($application, $beneficiaries) {
    // Create simple CSV format for Excel
    $csv = "ECO WEALTH FINANCE - APPLICATION SUMMARY\n";
    $csv .= "Application ID,EWF-" . str_pad($application['id'], 6, '0', STR_PAD_LEFT) . "\n";
    $csv .= "Status," . ucfirst($application['status']) . "\n";
    $csv .= "Generated," . date('Y-m-d H:i:s') . "\n\n";
    
    $csv .= "PERSONAL INFORMATION\n";
    $csv .= "Full Name," . $application['full_name'] . "\n";
    $csv .= "NIC No," . $application['nic_no'] . "\n";
    $csv .= "Email," . $application['email'] . "\n";
    $csv .= "Phone," . $application['tel_no'] . "\n";
    $csv .= "Date of Birth," . $application['formatted_dob'] . "\n\n";
    
    $csv .= "BENEFICIARIES\n";
    $csv .= "Name,NIC No,Percentage\n";
    foreach ($beneficiaries as $beneficiary) {
        $csv .= $beneficiary['beneficiary_name'] . "," . $beneficiary['beneficiary_nic'] . "," . $beneficiary['percentage'] . "%\n";
    }
    $csv .= "\n";
    
    $csv .= "UPLOADED DOCUMENTS\n";
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
            $csv .= $label . "," . basename($filename) . "\n";
        }
    }
    
    return $csv;
}

// Helper function to generate README content
function generateReadmeContent($application, $beneficiaries) {
    $content = "ECO WEALTH FINANCE - APPLICATION PACKAGE\n";
    $content .= "==========================================\n\n";
    $content .= "APPLICATION DETAILS\n";
    $content .= "-------------------\n";
    $content .= "Application ID: EWF-" . str_pad($application['id'], 6, '0', STR_PAD_LEFT) . "\n";
    $content .= "Investor Name: " . $application['full_name'] . "\n";
    $content .= "NIC No: " . $application['nic_no'] . "\n";
    $content .= "Email: " . $application['email'] . "\n";
    $content .= "Phone: " . $application['tel_no'] . "\n";
    $content .= "Status: " . ucfirst($application['status']) . "\n";
    $content .= "Submission Date: " . $application['formatted_created'] . "\n";
    $content .= "Package Generated: " . date('F d, Y H:i:s') . "\n\n";
    
    $content .= "BENEFICIARIES\n";
    $content .= "-------------\n";
    if (!empty($beneficiaries)) {
        foreach ($beneficiaries as $beneficiary) {
            $content .= "- " . $beneficiary['beneficiary_name'] . " (NIC: " . $beneficiary['beneficiary_nic'] . ") - " . $beneficiary['percentage'] . "%\n";
        }
    } else {
        $content .= "No beneficiaries\n";
    }
    $content .= "\n";
    
    $content .= "FILES INCLUDED\n";
    $content .= "--------------\n";
    $content .= "1. Application_Report.pdf - Complete application summary in PDF format\n";
    $content .= "2. Application_Summary.xlsx - Data in spreadsheet format\n";
    $content .= "3. README.txt - This file\n";
    
    $files = [
        'Signature' => $application['signature_upload'],
        'Investor ID' => $application['investor_id_doc'],
        'Beneficiary ID' => $application['beneficiary_id_doc'],
        'Passbook' => $application['passbook_doc'],
        'Payment Slip' => $application['payment_slip_doc'],
        'Final Signature' => $application['final_signature']
    ];
    
    $fileCount = 4;
    foreach ($files as $label => $filename) {
        if ($filename) {
            $content .= $fileCount . ". " . $label . " - " . basename($filename) . "\n";
            $fileCount++;
        }
    }
    
    $content .= "\n";
    $content .= "PACKAGE INFORMATION\n";
    $content .= "-------------------\n";
    $content .= "This package contains all files related to the investment application.\n";
    $content .= "Files are organized for easy reference and record keeping.\n";
    $content .= "\n";
    $content .= "EcoWealth Finance - Sustainable Investing for a Brighter Future\n";
    $content .= "© " . date('Y') . " EcoWealth Finance. All rights reserved.\n";
    
    return $content;
}
?>