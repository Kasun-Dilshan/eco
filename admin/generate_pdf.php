<?php
require_once '../config.php';
require_once '../db.php';

// Check for agreement ID
$agreementId = $_GET['agreement_id'] ?? 0;
if (!$agreementId) {
    die("Invalid agreement ID");
}

try {
    // Get agreement and investor details
    $stmt = $db->prepare("
        SELECT a.*, i.* 
        FROM agreements a 
        JOIN investors i ON a.investor_id = i.id 
        WHERE a.id = ?
    ");
    $stmt->execute([$agreementId]);
    $agreement = $stmt->fetch();
    
    if (!$agreement) {
        die("Agreement not found");
    }
    
    // Update download count
    $db->prepare("UPDATE agreements SET download_count = download_count + 1 WHERE id = ?")->execute([$agreementId]);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get investment type name
function getInvestmentTypeName($type) {
    $types = [
        'green_bonds' => 'Green Bonds',
        'sustainable_etf' => 'Sustainable ETF',
        'renewable_energy' => 'Renewable Energy Projects',
        'esg_funds' => 'ESG Mutual Funds',
        'carbon_credits' => 'Carbon Credits',
        'green_real_estate' => 'Green Real Estate',
        'sustainable_agriculture' => 'Sustainable Agriculture',
        'water_management' => 'Water Management',
        'other' => 'Other'
    ];
    return $types[$type] ?? 'Unknown';
}

// Generate PDF content
$html = '
<!DOCTYPE html>
<html>
<head>
    <title>Investment Agreement - ' . $agreement['agreement_number'] . '</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            line-height: 1.6; 
            margin: 40px; 
            color: #333;
        }
        .header { 
            text-align: center; 
            margin-bottom: 40px; 
            border-bottom: 3px solid #0a2f1d;
            padding-bottom: 20px;
        }
        .logo { 
            color: #0a2f1d; 
            font-weight: bold; 
            font-size: 28px; 
            margin-bottom: 10px;
            letter-spacing: 2px;
        }
        .agreement-number { 
            font-size: 18px; 
            color: #666; 
            margin-bottom: 30px;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            display: inline-block;
        }
        h1 {
            color: #0a2f1d;
            margin: 20px 0;
            font-size: 24px;
        }
        .section { 
            margin-bottom: 30px; 
        }
        .section-title { 
            font-weight: bold; 
            font-size: 18px; 
            margin-bottom: 15px; 
            border-bottom: 2px solid #0a2f1d; 
            padding-bottom: 5px;
            color: #0a2f1d;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: bold;
            color: #555;
        }
        .signature-section { 
            margin-top: 100px; 
        }
        .signature-line { 
            border-top: 1px solid #000; 
            width: 300px; 
            margin: 40px 0 10px; 
        }
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(10, 47, 29, 0.1);
            z-index: -1;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="watermark">ECO WEALTH</div>
    
    <div class="header">
        <div class="logo">ECO WEALTH FINANCE</div>
        <div class="agreement-number">Agreement No: ' . htmlspecialchars($agreement['agreement_number']) . '</div>
        <h1>INVESTMENT AGREEMENT</h1>
        <p style="color: #666;">Date: ' . date('F d, Y') . '</p>
    </div>
    
    <div class="section">
        <div class="section-title">1. PARTIES</div>
        <p>This Investment Agreement ("Agreement") is made on ' . date('F d, Y') . ' between:</p>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="color: #0a2f1d; margin-bottom: 15px;">Investor:</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Full Name:</span><br>
                    ' . htmlspecialchars($agreement['full_name']) . '
                </div>
                <div class="info-item">
                    <span class="info-label">NIC Number:</span><br>
                    ' . htmlspecialchars($agreement['nic_no']) . '
                </div>
                <div class="info-item">
                    <span class="info-label">Email Address:</span><br>
                    ' . htmlspecialchars($agreement['email']) . '
                </div>
                <div class="info-item">
                    <span class="info-label">Phone Number:</span><br>
                    ' . htmlspecialchars($agreement['tel_no']) . '
                </div>
            </div>
            <div class="info-item">
                <span class="info-label">Address:</span><br>
                ' . nl2br(htmlspecialchars($agreement['address'])) . '
            </div>
        </div>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="color: #0a2f1d; margin-bottom: 15px;">Company:</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Company Name:</span><br>
                    EcoWealth Finance Ltd.
                </div>
                <div class="info-item">
                    <span class="info-label">Registration No:</span><br>
                    EW-2023-001
                </div>
                <div class="info-item">
                    <span class="info-label">Registered Office:</span><br>
                    123 Green Street, Colombo 01, Sri Lanka
                </div>
                <div class="info-item">
                    <span class="info-label">Email:</span><br>
                    info@ecowealth.com
                </div>
            </div>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">2. INVESTMENT DETAILS</div>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Investment Type:</span><br>
                    ' . getInvestmentTypeName($agreement['investment_type']) . '
                </div>
                <div class="info-item">
                    <span class="info-label">Investment Period:</span><br>
                    ' . $agreement['years'] . ' years
                </div>
                <div class="info-item">
                    <span class="info-label">Agreement Status:</span><br>
                    ' . ucfirst($agreement['status']) . '
                </div>
                <div class="info-item">
                    <span class="info-label">Generated On:</span><br>
                    ' . date('F d, Y', strtotime($agreement['generated_at'])) . '
                </div>
            </div>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">3. TERMS AND CONDITIONS</div>
        <ol style="margin-left: 20px;">
            <li>The Investor agrees to invest in sustainable projects managed by EcoWealth Finance Ltd.</li>
            <li>The Company agrees to manage the investment professionally and provide quarterly reports.</li>
            <li>Investment returns are subject to market conditions and project performance.</li>
            <li>This agreement is valid for the specified investment period of ' . $agreement['years'] . ' years.</li>
            <li>Early withdrawal may be subject to penalties as per company policy.</li>
            <li>All investment decisions will be made in accordance with sustainable investment principles.</li>
            <li>The Company will provide annual sustainability impact reports.</li>
            <li>Any disputes shall be resolved through arbitration in Colombo, Sri Lanka.</li>
            <li>This agreement shall be governed by the laws of Sri Lanka.</li>
            <li>All communications shall be in writing and sent to the addresses provided above.</li>
        </ol>
    </div>
    
    <div class="section">
        <div class="section-title">4. DECLARATIONS</div>
        <p>The Investor hereby declares that:</p>
        <ol style="margin-left: 20px;">
            <li>All information provided is true and accurate.</li>
            <li>I understand the risks associated with this investment.</li>
            <li>I have read and understood all terms and conditions.</li>
            <li>I am investing with my own funds.</li>
            <li>I authorize EcoWealth Finance to manage my investment as per this agreement.</li>
        </ol>
    </div>
    
    <div class="signature-section">
        <div style="display: flex; justify-content: space-between; margin-top: 50px;">
            <div style="width: 45%;">
                <div class="signature-line"></div>
                <p><strong>Signature of Investor</strong></p>
                <p>Name: ' . htmlspecialchars($agreement['full_name']) . '</p>
                <p>NIC: ' . htmlspecialchars($agreement['nic_no']) . '</p>
                <p>Date: ___________________</p>
            </div>
            
            <div style="width: 45%;">
                <div class="signature-line"></div>
                <p><strong>For EcoWealth Finance Ltd.</strong></p>
                <p>Authorized Signatory</p>
                <p>Name: ___________________</p>
                <p>Position: Investment Manager</p>
                <p>Date: ___________________</p>
            </div>
        </div>
    </div>
    
    <div class="footer">
        <p>EcoWealth Finance Ltd. | 123 Green Street, Colombo 01, Sri Lanka</p>
        <p>Tel: +94 11 234 5678 | Email: info@ecowealth.com | Web: www.ecowealth.com</p>
        <p>This document is computer generated. No signature required for draft version.</p>
        <p>Page 1 of 1 | Generated on: ' . date('F d, Y H:i:s') . '</p>
    </div>
</body>
</html>
';

// Output PD


// If you don't have a PDF library, you can output HTML with PDF headers
// For actual PDF generation, you'll need a library like TCPDF, mPDF, or Dompdf

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="agreement_' . $agreement['agreement_number'] . '.pdf"');

// For now, output HTML with instructions
echo '<!DOCTYPE html>
<html>
<head>
    <title>PDF Generation</title>
    <style>
        body { font-family: Arial; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; text-align: center; }
        .btn { background: #0a2f1d; color: white; padding: 15px 30px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Agreement PDF Generation</h1>
        <p>To generate actual PDF files, install a PDF generation library:</p>
        <p><strong>Option 1:</strong> Install mPDF via Composer: <code>composer require mpdf/mpdf</code></p>
        <p><strong>Option 2:</strong> Install TCPDF from: <a href="https://github.com/tecnickcom/TCPDF">https://github.com/tecnickcom/TCPDF</a></p>
        <br>
        <button class="btn" onclick="window.print()">Print this Agreement</button>
        <br><br>
        <a href="view_agreement.php?id=' . $agreementId . '">Back to Agreement</a>
    </div>
</body>
</html>';

// If you have mPDF installed, uncomment this:
/*
require_once '../vendor/autoload.php';
$mpdf = new \Mpdf\Mpdf();
$mpdf->WriteHTML($html);
$mpdf->Output('agreement_' . $agreement['agreement_number'] . '.pdf', 'D');
*/