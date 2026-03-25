<?php
require_once '../config.php';
require_once '../db.php';

session_start();



$agreementId = $_GET['id'] ?? 0;
if (!$agreementId) {
    header('Location: agreements.php');
    exit();
}

// Initialize variables
$message = '';
$messageType = '';

// Get agreement details
try {
    $stmt = $db->prepare("
        SELECT a.*, 
               i.*,
               DATE_FORMAT(a.generated_at, '%M %d, %Y %H:%i') as formatted_generated,
               DATE_FORMAT(a.sent_at, '%M %d, %Y %H:%i') as formatted_sent,
               DATE_FORMAT(a.signed_at, '%M %d, %Y %H:%i') as formatted_signed,
               DATE_FORMAT(a.expiration_date, '%M %d, %Y') as formatted_expiration,
               DATEDIFF(a.expiration_date, NOW()) as days_remaining
        FROM agreements a 
        JOIN investors i ON a.investor_id = i.id 
        WHERE a.id = ?
    ");
    $stmt->execute([$agreementId]);
    $agreement = $stmt->fetch();
    
    if (!$agreement) {
        header('Location: agreements.php');
        exit();
    }
    
    // Get agreement logs
    $logStmt = $db->prepare("
        SELECT *, DATE_FORMAT(created_at, '%M %d, %Y %H:%i') as formatted_created
        FROM agreement_logs 
        WHERE agreement_id = ? 
        ORDER BY created_at DESC
    ");
    $logStmt->execute([$agreementId]);
    $logs = $logStmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $newStatus = $_POST['status'] ?? '';
        $adminName = $_SESSION['admin_name'] ?? 'Admin';
        
        if ($newStatus) {
            try {
                $updateStmt = $db->prepare("UPDATE agreements SET status = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->execute([$newStatus, $agreementId]);
                
                // Update timestamps based on status
                if ($newStatus === 'sent') {
                    $db->prepare("UPDATE agreements SET sent_at = NOW() WHERE id = ?")->execute([$agreementId]);
                } elseif ($newStatus === 'signed') {
                    $db->prepare("UPDATE agreements SET signed_at = NOW() WHERE id = ?")->execute([$agreementId]);
                }
                
                // Log the change
                $logStmt = $db->prepare("
                    INSERT INTO agreement_logs (agreement_id, action, description, performed_by)
                    VALUES (?, ?, ?, ?)
                ");
                
                $logStmt->execute([
                    $agreementId,
                    'status_updated',
                    'Status changed to ' . $newStatus,
                    $adminName
                ]);
                
                $message = "Agreement status updated to " . ucfirst($newStatus) . "!";
                $messageType = 'success';
                
                // Refresh page
                header("Refresh: 2; url=view_agreement.php?id=" . $agreementId);
                exit();
                
            } catch (PDOException $e) {
                $message = "Database error: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
    
    if (isset($_POST['send_email'])) {
        // Send email logic here
        $message = "Email sent to " . $agreement['email'] . "!";
        $messageType = 'success';
    }
    
    if (isset($_POST['download_pdf'])) {
        // Generate and download PDF
        header("Location: generate_pdf.php?agreement_id=" . $agreementId);
        exit();
    }
}

// Function to get investment type name
function getInvestmentTypeName($type) {
    $types = [
        'green_bonds' => 'Green Bonds',
        'sustainable_etf' => 'Sustainable ETF',
        'renewable_energy' => 'Renewable Energy',
        'esg_funds' => 'ESG Funds',
        'carbon_credits' => 'Carbon Credits',
        'green_real_estate' => 'Green Real Estate',
        'sustainable_agriculture' => 'Sustainable Agriculture',
        'water_management' => 'Water Management',
        'other' => 'Other'
    ];
    return $types[$type] ?? 'Unknown';
}

// Function to get status badge color
function getStatusBadge($status) {
    $badges = [
        'draft' => 'warning',
        'sent' => 'info',
        'signed' => 'success',
        'expired' => 'error',
        'cancelled' => 'secondary'
    ];
    return $badges[$status] ?? 'secondary';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Agreement | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2f1d;
            --secondary: #1a4d33;
            --accent: #22c55e;
            --accent-glow: rgba(34, 197, 94, 0.4);
            --neon: #00ff88;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --info: #3b82f6;
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .header-left h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--neon), var(--accent));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 5px;
        }
        
        .header-left p {
            color: var(--text-muted);
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
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
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--accent);
        }
        
        .alert {
            padding: 18px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid transparent;
            backdrop-filter: blur(10px);
        }
        
        .alert.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.1));
            border-color: var(--success);
            border-left: 5px solid var(--success);
        }
        
        .alert.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            border-color: var(--error);
            border-left: 5px solid var(--error);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .info-card {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 25px;
            backdrop-filter: blur(10px);
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--neon);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px dashed rgba(34, 197, 94, 0.1);
        }
        
        .info-label {
            color: var(--text-muted);
            font-weight: 600;
        }
        
        .info-value {
            color: var(--text);
            text-align: right;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .badge.draft { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .badge.sent { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .badge.signed { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .badge.expired { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        
        .agreement-actions {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 40px;
            backdrop-filter: blur(10px);
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .action-card {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .action-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 24px;
        }
        
        .timeline {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 30px;
            backdrop-filter: blur(10px);
        }
        
        .timeline-item {
            display: flex;
            gap: 20px;
            padding: 20px 0;
            border-bottom: 1px solid rgba(34, 197, 94, 0.1);
        }
        
        .timeline-item:last-child {
            border-bottom: none;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            background: rgba(34, 197, 94, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--accent);
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-date {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .timeline-action {
            font-weight: 700;
            color: var(--text);
            margin-bottom: 5px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-content {
            background: rgba(10, 47, 29, 0.95);
            border: 1px solid var(--accent);
            border-radius: 15px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--neon);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
        }
        
        .form-group select {
            width: 100%;
            padding: 14px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            font-size: 16px;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .modal-actions .btn {
            flex: 1;
            padding: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Agreement Details</h1>
                <p>Manage and track investment agreement</p>
            </div>
            <div class="header-actions">
                <a href="agreements.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="generate_pdf.php?agreement_id=<?php echo $agreementId; ?>" class="btn btn-primary" target="_blank">
                    <i class="fas fa-download"></i> Download PDF
                </a>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>
        
        <!-- Agreement Info Grid -->
        <div class="info-grid">
            <!-- Agreement Details -->
            <div class="info-card">
                <div class="card-title">
                    <i class="fas fa-file-contract"></i> Agreement Details
                </div>
                <div class="info-row">
                    <span class="info-label">Agreement Number</span>
                    <span class="info-value"><?php echo htmlspecialchars($agreement['agreement_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="badge <?php echo getStatusBadge($agreement['status']); ?>">
                            <?php echo ucfirst($agreement['status']); ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Generated By</span>
                    <span class="info-value"><?php echo htmlspecialchars($agreement['generated_by']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Generated On</span>
                    <span class="info-value"><?php echo $agreement['formatted_generated']; ?></span>
                </div>
                <?php if ($agreement['sent_at']): ?>
                <div class="info-row">
                    <span class="info-label">Sent On</span>
                    <span class="info-value"><?php echo $agreement['formatted_sent']; ?></span>
                </div>
                <?php endif; ?>
                <?php if ($agreement['signed_at']): ?>
                <div class="info-row">
                    <span class="info-label">Signed On</span>
                    <span class="info-value"><?php echo $agreement['formatted_signed']; ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Expiration</span>
                    <span class="info-value">
                        <?php echo $agreement['formatted_expiration']; ?>
                        <?php if ($agreement['days_remaining'] > 0): ?>
                            <span style="color: var(--success); font-size: 12px;">
                                (<?php echo $agreement['days_remaining']; ?> days remaining)
                            </span>
                        <?php elseif ($agreement['days_remaining'] < 0): ?>
                            <span style="color: var(--error); font-size: 12px;">
                                (Expired <?php echo abs($agreement['days_remaining']); ?> days ago)
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            
            <!-- Investor Details -->
            <div class="info-card">
                <div class="card-title">
                    <i class="fas fa-user"></i> Investor Details
                </div>
                <div class="info-row">
                    <span class="info-label">Full Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($agreement['full_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">NIC Number</span>
                    <span class="info-value"><?php echo htmlspecialchars($agreement['nic_no']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($agreement['email']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars($agreement['tel_no']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Investment Type</span>
                    <span class="info-value"><?php echo getInvestmentTypeName($agreement['investment_type']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Investment Years</span>
                    <span class="info-value"><?php echo $agreement['years']; ?> years</span>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="info-card">
                <div class="card-title">
                    <i class="fas fa-chart-bar"></i> Statistics
                </div>
                <div class="info-row">
                    <span class="info-label">Times Downloaded</span>
                    <span class="info-value"><?php echo $agreement['download_count']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Times Viewed</span>
                    <span class="info-value"><?php echo $agreement['view_count']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Updated</span>
                    <span class="info-value">
                        <?php 
                        $updated = new DateTime($agreement['updated_at']);
                        echo $updated->format('M d, Y H:i');
                        ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Agreement ID</span>
                    <span class="info-value"><?php echo $agreement['id']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Investor ID</span>
                    <span class="info-value"><?php echo $agreement['investor_id']; ?></span>
                </div>
            </div>
        </div>
        
        <!-- Action Cards -->
        <div class="agreement-actions">
            <div class="card-title">
                <i class="fas fa-cogs"></i> Agreement Actions
            </div>
            <div class="action-grid">
                <form method="POST" action="" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <h3>Change Status</h3>
                    <p>Update agreement status</p>
                    <button type="button" class="btn btn-primary" onclick="showStatusModal()" style="margin-top: 15px;">
                        <i class="fas fa-edit"></i> Change Status
                    </button>
                </form>
                
                <form method="POST" action="" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <h3>Send Email</h3>
                    <p>Send agreement to investor</p>
                    <button type="submit" name="send_email" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-paper-plane"></i> Send Email
                    </button>
                </form>
                
                <a href="generate_pdf.php?agreement_id=<?php echo $agreementId; ?>" class="action-card" target="_blank">
                    <div class="action-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <h3>Download PDF</h3>
                    <p>Generate and download PDF</p>
                    <button type="button" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-download"></i> Download
                    </button>
                </a>
                
                <a href="preview_agreement.php?id=<?php echo $agreementId; ?>" class="action-card" target="_blank">
                    <div class="action-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <h3>Preview</h3>
                    <p>View agreement preview</p>
                    <button type="button" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                </a>
            </div>
        </div>
        
        <!-- Activity Timeline -->
        <div class="timeline">
            <div class="card-title">
                <i class="fas fa-history"></i> Activity Log
            </div>
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-stream" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                    <p>No activity recorded yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <div class="timeline-item">
                    <div class="timeline-icon">
                        <i class="fas fa-<?php echo $log['action'] === 'agreement_created' ? 'plus-circle' : ($log['action'] === 'status_updated' ? 'sync-alt' : 'history'); ?>"></i>
                    </div>
                    <div class="timeline-content">
                        <div class="timeline-date">
                            <?php echo $log['formatted_created']; ?>
                            <?php if ($log['performed_by']): ?>
                                • by <?php echo htmlspecialchars($log['performed_by']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="timeline-action">
                            <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                        </div>
                        <?php if ($log['description']): ?>
                            <div style="color: var(--text-muted); font-size: 14px;">
                                <?php echo htmlspecialchars($log['description']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-edit"></i> Change Agreement Status
            </div>
            <form method="POST" action="">
                <input type="hidden" name="agreement_id" value="<?php echo $agreementId; ?>">
                <div class="form-group">
                    <label for="status">Select New Status</label>
                    <select id="status" name="status" required>
                        <option value="draft" <?php echo $agreement['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="sent" <?php echo $agreement['status'] === 'sent' ? 'selected' : ''; ?>>Sent to Investor</option>
                        <option value="signed" <?php echo $agreement['status'] === 'signed' ? 'selected' : ''; ?>>Signed</option>
                        <?php if ($agreement['status'] !== 'signed'): ?>
                        <option value="cancelled">Cancelled</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideStatusModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="update_status" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Modal functions
        function showStatusModal() {
            document.getElementById('statusModal').style.display = 'flex';
        }
        
        function hideStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        document.getElementById('statusModal').addEventListener('click', function(event) {
            if (event.target === this) {
                hideStatusModal();
            }
        });
        
        // Auto-hide success messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert.success');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Confirmation for email send
        document.querySelector('button[name="send_email"]')?.addEventListener('click', function(e) {
            if (!confirm('Send this agreement to the investor\'s email?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>