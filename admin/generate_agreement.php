<?php
require_once '../config.php';
require_once '../db.php';

// Check if application ID is provided via GET parameter
$applicationId = $_GET['id'] ?? $_GET['application_id'] ?? 0;

if (!$applicationId) {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error | EcoWealth Finance</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary: #0a2f1d;
                --secondary: #1a4d33;
                --accent: #22c55e;
                --error: #ef4444;
                --text: #0a2f1d;
                --text-muted: #6b7280;
            }
            
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
                font-family: "Segoe UI", sans-serif;
            }
            
            body {
                background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
                color: var(--text);
            }
            
            .error-container {
                background: white;
                border-radius: 15px;
                padding: 40px;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                max-width: 500px;
                border: 2px solid var(--error);
            }
            
            .error-icon {
                font-size: 64px;
                color: var(--error);
                margin-bottom: 20px;
            }
            
            h1 {
                color: var(--primary);
                margin-bottom: 15px;
            }
            
            p {
                color: var(--text-muted);
                margin-bottom: 25px;
            }
            
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: var(--accent);
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                transition: all 0.3s;
                border: none;
                cursor: pointer;
            }
            
            .btn:hover {
                background: #059669;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
            }
            
            ul {
                text-align: left;
                color: var(--text-muted);
                margin: 20px 0;
                padding-left: 20px;
            }
            
            li {
                margin-bottom: 8px;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>Application ID Required</h1>
            <p>Please provide a valid application ID to generate an agreement.</p>
            <p><strong>Possible reasons:</strong></p>
            <ul>
                <li>The link you clicked is incomplete</li>
                <li>Application ID is missing from the URL</li>
                <li>You need to access this page from the admin panel</li>
            </ul>
            <a href="../index.php" class="btn">Return to Home</a>
        </div>
    </body>
    </html>';
    exit();
}

// Get investor data
try {
    $stmt = $db->prepare("
        SELECT i.*, 
               DATE_FORMAT(i.dob, '%M %d, %Y') as formatted_dob,
               DATE_FORMAT(i.signing_date, '%M %d, %Y') as formatted_signing
        FROM investors i 
        WHERE i.id = ?
    ");
    $stmt->execute([$applicationId]);
    $investor = $stmt->fetch();
    
    if (!$investor) {
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Error | EcoWealth Finance</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                :root {
                    --primary: #0a2f1d;
                    --secondary: #1a4d33;
                    --accent: #22c55e;
                    --warning: #f59e0b;
                    --text: #0a2f1d;
                    --text-muted: #6b7280;
                }
                
                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                    font-family: "Segoe UI", sans-serif;
                }
                
                body {
                    background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                    color: var(--text);
                }
                
                .error-container {
                    background: white;
                    border-radius: 15px;
                    padding: 40px;
                    text-align: center;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                    max-width: 500px;
                    border: 2px solid var(--warning);
                }
                
                .error-icon {
                    font-size: 64px;
                    color: var(--warning);
                    margin-bottom: 20px;
                }
                
                h1 {
                    color: var(--primary);
                    margin-bottom: 15px;
                }
                
                p {
                    color: var(--text-muted);
                    margin-bottom: 25px;
                }
                
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background: var(--accent);
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    transition: all 0.3s;
                    border: none;
                    cursor: pointer;
                }
                
                .btn:hover {
                    background: #059669;
                    transform: translateY(-2px);
                    box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
                }
                
                ul {
                    text-align: left;
                    color: var(--text-muted);
                    margin: 20px 0;
                    padding-left: 20px;
                }
                
                li {
                    margin-bottom: 8px;
                }
                
                strong {
                    color: var(--primary);
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-icon">🔍</div>
                <h1>Investor Not Found</h1>
                <p>No investor found with Application ID: <strong>' . htmlspecialchars($applicationId) . '</strong></p>
                <p><strong>Possible reasons:</strong></p>
                <ul>
                    <li>The application ID is incorrect</li>
                    <li>The investor record has been deleted</li>
                    <li>Database connection issue</li>
                </ul>
                <a href="../index.php" class="btn">Return to Home</a>
            </div>
        </body>
        </html>';
        exit();
    }
    
    // Get beneficiaries
    $benStmt = $db->prepare("
        SELECT * FROM beneficiaries 
        WHERE investor_id = ? 
        ORDER BY percentage DESC
    ");
    $benStmt->execute([$applicationId]);
    $beneficiaries = $benStmt->fetchAll();
    
} catch (PDOException $e) {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Error | EcoWealth Finance</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary: #0a2f1d;
                --secondary: #1a4d33;
                --accent: #22c55e;
                --error: #ef4444;
                --text: #0a2f1d;
                --text-muted: #6b7280;
            }
            
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
                font-family: "Segoe UI", sans-serif;
            }
            
            body {
                background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
                color: var(--text);
            }
            
            .error-container {
                background: white;
                border-radius: 15px;
                padding: 40px;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                max-width: 500px;
                border: 2px solid var(--error);
            }
            
            .error-icon {
                font-size: 64px;
                color: var(--error);
                margin-bottom: 20px;
            }
            
            h1 {
                color: var(--primary);
                margin-bottom: 15px;
            }
            
            p {
                color: var(--text-muted);
                margin-bottom: 25px;
            }
            
            .error-details {
                background: #fee2e2;
                border: 1px solid #fca5a5;
                border-radius: 8px;
                padding: 15px;
                margin: 20px 0;
                text-align: left;
                font-family: monospace;
                font-size: 14px;
                color: #dc2626;
                max-height: 200px;
                overflow-y: auto;
            }
            
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: var(--accent);
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                transition: all 0.3s;
                border: none;
                cursor: pointer;
            }
            
            .btn:hover {
                background: #059669;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>Database Error</h1>
            <p>An error occurred while accessing the database.</p>
            <div class="error-details">' . htmlspecialchars($e->getMessage()) . '</div>
            <a href="../index.php" class="btn">Return to Home</a>
        </div>
    </body>
    </html>';
    exit();
}

// Handle form submission
$message = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $templateId = $_POST['template_id'] ?? 1;
    $notes = $_POST['notes'] ?? '';
    $generatedBy = 'System';
    
    try {
        // Create agreements table if it doesn't exist
        $createTable = $db->exec("
            CREATE TABLE IF NOT EXISTS agreements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                investor_id INT NOT NULL,
                agreement_number VARCHAR(50) NOT NULL UNIQUE,
                template_id INT NOT NULL DEFAULT 1,
                content TEXT,
                status ENUM('draft', 'sent', 'signed', 'expired') DEFAULT 'draft',
                generated_by VARCHAR(100),
                generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                signed_at TIMESTAMP NULL,
                signed_by VARCHAR(100),
                FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE
            )
        ");
        
        // Generate agreement number
        $agreementNumber = 'AGR-' . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd') . rand(100, 999);
        
        // Insert agreement record
        $stmt = $db->prepare("
            INSERT INTO agreements (
                investor_id, agreement_number, template_id, 
                generated_by, generated_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $applicationId,
            $agreementNumber,
            $templateId,
            $generatedBy
        ]);
        
        $agreementId = $db->lastInsertId();
        
        // Log the generation
        $logStmt = $db->prepare("
            INSERT INTO application_logs (investor_id, action, description, performed_by)
            VALUES (?, ?, ?, ?)
        ");
        
        $logStmt->execute([
            $applicationId,
            'agreement_generated',
            'Generated agreement ' . $agreementNumber,
            $generatedBy
        ]);
        
        // Redirect to view agreement
        header('Location: view_agreement.php?id=' . $agreementId);
        exit();
        
    } catch (PDOException $e) {
        $message = 'Error generating agreement: ' . $e->getMessage();
        $type = 'error';
    }
}

// Get existing agreements
try {
    $agreementStmt = $db->prepare("
        SELECT a.*, 
               DATE_FORMAT(a.generated_at, '%M %d, %Y %H:%i') as formatted_generated
        FROM agreements a 
        WHERE a.investor_id = ? 
        ORDER BY a.generated_at DESC
    ");
    $agreementStmt->execute([$applicationId]);
    $existingAgreements = $agreementStmt->fetchAll();
} catch (PDOException $e) {
    $existingAgreements = [];
}

// Function to get investment type name
function getInvestmentTypeName($type) {
    $types = [
        'HPP' => 'High profit plan',
        'GSP' => 'Green saving plan',
        'GSI' => 'Green silver plan',
        'GOLD' => 'Gold plan',
        'SFPS' => 'Seraa farm profit share plan',
        'SFHPS' => 'Seraa farm high profit share plan'
    ];
    
    return $types[$type] ?? 'Unknown';
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Agreement | EcoWealth Finance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --accent-glow: rgba(34, 197, 94, 0.4);
            --neon: #00ff88;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --text: #0a2f1d;
            --text-muted: #6b7280;
            --card-bg: rgba(255, 255, 255, 0.95);
            --border: rgba(34, 197, 94, 0.2);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border);
        }
        
        .header h1 {
            font-size: 36px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .header p {
            color: var(--text-muted);
            font-size: 16px;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid transparent;
            backdrop-filter: blur(10px);
            animation: slideDown 0.5s ease;
        }
        
        .alert.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.1));
            border-color: var(--success);
            border-left: 5px solid var(--success);
            color: var(--text);
        }
        
        .alert.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            border-color: var(--error);
            border-left: 5px solid var(--error);
            color: var(--text);
        }
        
        .alert.info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.1));
            border-color: var(--info);
            border-left: 5px solid var(--info);
            color: var(--text);
        }
        
        .alert.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.1));
            border-color: var(--warning);
            border-left: 5px solid var(--warning);
            color: var(--text);
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .info-box {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }
        
        .info-box h3 {
            color: var(--primary);
            font-size: 22px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            padding: 15px;
            background: rgba(10, 47, 29, 0.03);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .info-item:hover {
            background: rgba(34, 197, 94, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(34, 197, 94, 0.1);
        }
        
        .info-label {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-value {
            color: var(--text);
            font-size: 16px;
            font-weight: 500;
        }
        
        .agreements-list {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }
        
        .agreements-list h3 {
            color: var(--primary);
            font-size: 22px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .agreement-item {
            background: rgba(10, 47, 29, 0.03);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .agreement-item:hover {
            background: rgba(34, 197, 94, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(34, 197, 94, 0.1);
        }
        
        .agreement-info h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .agreement-meta {
            display: flex;
            gap: 20px;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .agreement-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .agreement-actions {
            display: flex;
            gap: 10px;
        }
        
        .no-agreements {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 60px 30px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }
        
        .no-agreements i {
            font-size: 64px;
            color: var(--accent);
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-agreements h3 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 24px;
        }
        
        .no-agreements p {
            color: var(--text-muted);
        }
        
        .form-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }
        
        .form-section h3 {
            color: var(--primary);
            font-size: 22px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 12px;
            font-weight: 600;
            color: var(--text);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 15px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .preview-section {
            background: rgba(10, 47, 29, 0.03);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .preview-section h4 {
            color: var(--primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .preview-content {
            color: var(--text);
            line-height: 1.8;
        }
        
        .preview-content ul {
            list-style: none;
            padding-left: 20px;
        }
        
        .preview-content li {
            margin-bottom: 8px;
            position: relative;
        }
        
        .preview-content li:before {
            content: "✓";
            color: var(--success);
            position: absolute;
            left: -20px;
            font-weight: bold;
        }
        
        .action-buttons {
            display: flex;
            gap: 20px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
        }
        
        .btn-secondary {
            background: rgba(26, 77, 51, 0.1);
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--accent);
            transform: translateY(-3px);
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 14px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
            padding: 10px 20px;
            border-radius: 8px;
            background: rgba(34, 197, 94, 0.1);
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: rgba(34, 197, 94, 0.2);
            transform: translateY(-2px);
        }
        
        .text-success {
            color: var(--success);
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 28px;
                flex-direction: column;
                gap: 10px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .agreement-item {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .agreement-meta {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-file-contract"></i> Generate Investment Agreement</h1>
            <p>Create a legal agreement for investor: <strong><?php echo htmlspecialchars($investor['full_name']); ?></strong></p>
            <p style="margin-top: 10px; font-size: 14px; opacity: 0.8;">
                Application ID: <strong><?php echo str_pad($applicationId, 6, '0', STR_PAD_LEFT); ?></strong>
            </p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="alert <?php echo $type; ?>">
                    <i class="fas fa-<?php echo $type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Investor Information -->
            <div class="info-box">
                <h3><i class="fas fa-user-circle"></i> Investor Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-user"></i> Full Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($investor['full_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-id-card"></i> NIC Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($investor['nic_no']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-chart-line"></i> Investment Type</div>
                        <div class="info-value"><?php echo getInvestmentTypeName($investor['investment_type']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-alt"></i> Investment Years</div>
                        <div class="info-value"><?php echo $investor['years']; ?> years</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($investor['email']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($investor['tel_no']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-check"></i> Date of Birth</div>
                        <div class="info-value"><?php echo $investor['formatted_dob']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-signature"></i> Signing Date</div>
                        <div class="info-value"><?php echo $investor['formatted_signing']; ?></div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($existingAgreements)): ?>
            <!-- Existing Agreements -->
            <div class="agreements-list">
                <h3><i class="fas fa-file-alt"></i> Existing Agreements</h3>
                <?php foreach ($existingAgreements as $agreement): ?>
                <div class="agreement-item">
                    <div class="agreement-info">
                        <h4><?php echo htmlspecialchars($agreement['agreement_number']); ?></h4>
                        <div class="agreement-meta">
                            <span><i class="fas fa-calendar"></i> Generated: <?php echo $agreement['formatted_generated']; ?></span>
                            <span><i class="fas fa-user"></i> By: <?php echo htmlspecialchars($agreement['generated_by']); ?></span>
                            <span class="text-<?php echo $agreement['status'] === 'signed' ? 'success' : 'warning'; ?>">
                                <i class="fas fa-<?php echo $agreement['status'] === 'signed' ? 'check-circle' : 'clock'; ?>"></i> 
                                Status: <?php echo ucfirst($agreement['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="agreement-actions">
                        <a href="view_agreement.php?id=<?php echo $agreement['id']; ?>" class="btn btn-small btn-primary">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <a href="download_agreement.php?id=<?php echo $agreement['id']; ?>" class="btn btn-small btn-secondary">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-agreements">
                <i class="fas fa-file-contract"></i>
                <h3>No Agreements Yet</h3>
                <p>Generate your first investment agreement below</p>
            </div>
            <?php endif; ?>
            
            <!-- Generate New Agreement Form -->
            <form method="POST" action="" onsubmit="return validateForm()">
                <input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                
                <div class="form-section">
                    <h3><i class="fas fa-file-contract"></i> Generate New Agreement</h3>
                    
                    <div class="form-group">
                        <label for="template_id"><i class="fas fa-file-alt"></i> Select Agreement Template</label>
                        <select id="template_id" name="template_id" required>
                            <option value="1" selected>Standard Investment Agreement</option>
                            <option value="2">Premium Investment Agreement (with detailed terms)</option>
                            <option value="3">Simple Investment Agreement</option>
                            <option value="4">Legal Binding Agreement</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes"><i class="fas fa-sticky-note"></i> Additional Notes (Optional)</label>
                        <textarea id="notes" name="notes" rows="4" placeholder="Add any special notes or custom terms for this agreement..."></textarea>
                    </div>
                    
                    <div class="preview-section">
                        <h4><i class="fas fa-eye"></i> Template Preview</h4>
                        <div class="preview-content" id="previewContent">
                            <p><strong>Standard Investment Agreement Includes:</strong></p>
                            <ul>
                                <li>Investor personal information</li>
                                <li>Investment type and duration</li>
                                <li>Bank account details</li>
                                <li>Standard terms and conditions</li>
                                <li>Signature sections for both parties</li>
                                <li>Legal declarations</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="button" onclick="window.history.back()" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Go Back
                        </button>
                        <button type="submit" name="generate" class="btn btn-primary" id="generateBtn">
                            <i class="fas fa-file-contract"></i> Generate Agreement Now
                        </button>
                    </div>
                </div>
            </form>
            
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-home"></i> Back to Home
            </a>
        </div>
    </div>
    
    <script>
        // Update preview when template changes
        document.getElementById('template_id').addEventListener('change', function() {
            const preview = document.getElementById('previewContent');
            const templateId = this.value;
            
            switch(templateId) {
                case '1':
                    preview.innerHTML = `
                        <p><strong>Standard Investment Agreement Includes:</strong></p>
                        <ul>
                            <li>Investor personal information</li>
                            <li>Investment type and duration</li>
                            <li>Bank account details</li>
                            <li>Standard terms and conditions</li>
                            <li>Signature sections for both parties</li>
                            <li>Legal declarations</li>
                        </ul>`;
                    break;
                case '2':
                    preview.innerHTML = `
                        <p><strong>Premium Investment Agreement Includes:</strong></p>
                        <ul>
                            <li>All standard agreement elements</li>
                            <li>Detailed investment terms and conditions</li>
                            <li>Performance expectations</li>
                            <li>Withdrawal conditions</li>
                            <li>Risk disclosure statements</li>
                            <li>Quarterly reporting schedule</li>
                        </ul>`;
                    break;
                case '3':
                    preview.innerHTML = `
                        <p><strong>Simple Investment Agreement Includes:</strong></p>
                        <ul>
                            <li>Basic investor information</li>
                            <li>Investment details</li>
                            <li>Essential terms and conditions</li>
                            <li>Simplified signature section</li>
                        </ul>`;
                    break;
                case '4':
                    preview.innerHTML = `
                        <p><strong>Legal Binding Agreement Includes:</strong></p>
                        <ul>
                            <li>Full legal documentation</li>
                            <li>Compliance with financial regulations</li>
                            <li>Dispute resolution mechanisms</li>
                            <li>Jurisdiction and governing law</li>
                            <li>Witness sections</li>
                            <li>Notarization requirements</li>
                        </ul>`;
                    break;
            }
        });
        
        // Form validation
        function validateForm() {
            const generateBtn = document.getElementById('generateBtn');
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            
            // Show loading state
            generateBtn.style.opacity = '0.7';
            generateBtn.style.cursor = 'wait';
            
            // Form is valid, allow submission
            return true;
        }
        
        // Auto-scroll to form if there are validation errors
        <?php if ($message): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelector('.form-section').scrollIntoView({ behavior: 'smooth' });
            });
        <?php endif; ?>
    </script>
</body>
</html>