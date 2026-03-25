<?php
require_once '../config.php';
require_once '../db.php';

session_start();



// Initialize variables
$message = '';
$messageType = '';
$search = $_GET['search'] ?? '';
$applications = [];

// Search for applications
if (!empty($search)) {
    try {
        $stmt = $db->prepare("
            SELECT i.*, 
                   DATE_FORMAT(i.created_at, '%M %d, %Y') as formatted_created,
                   (SELECT COUNT(*) FROM agreements WHERE investor_id = i.id) as agreement_count
            FROM investors i 
            WHERE i.nic_no LIKE ? OR i.full_name LIKE ? OR i.email LIKE ?
            AND i.status = 'approved'
            ORDER BY i.created_at DESC 
            LIMIT 20
        ");
        $searchParam = "%$search%";
        $stmt->execute([$searchParam, $searchParam, $searchParam]);
        $applications = $stmt->fetchAll();
        
        if (empty($applications)) {
            $message = "No approved applications found matching your search.";
            $messageType = 'info';
        }
    } catch (PDOException $e) {
        $message = "Database error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Handle agreement generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_agreement'])) {
    $investorId = $_POST['investor_id'] ?? 0;
    
    if ($investorId) {
        try {
            // Get investor details
            $stmt = $db->prepare("SELECT * FROM investors WHERE id = ? AND status = 'approved'");
            $stmt->execute([$investorId]);
            $investor = $stmt->fetch();
            
            if ($investor) {
                // Generate agreement number
                $agreementNumber = 'EWF-' . date('Y') . '-' . str_pad($investorId, 6, '0', STR_PAD_LEFT) . '-' . rand(1000, 9999);
                
                // Insert new agreement
                $stmt = $db->prepare("
                    INSERT INTO agreements (
                        investor_id, agreement_number, agreement_type, status,
                        generated_by, generated_at, expiration_date
                    ) VALUES (?, ?, 'investment', 'draft', ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
                ");
                
                $stmt->execute([
                    $investorId,
                    $agreementNumber,
                    $_SESSION['admin_name']
                ]);
                
                $agreementId = $db->lastInsertId();
                
                // Log the creation
                $logStmt = $db->prepare("
                    INSERT INTO agreement_logs (agreement_id, action, description, performed_by)
                    VALUES (?, ?, ?, ?)
                ");
                
                $logStmt->execute([
                    $agreementId,
                    'agreement_created',
                    'Created new agreement ' . $agreementNumber . ' for ' . $investor['full_name'],
                    $_SESSION['admin_name']
                ]);
                
                // Redirect to view agreement
                header("Location: view_agreement.php?id=" . $agreementId);
                exit();
            } else {
                $message = "Investor not found or not approved!";
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = "Database error: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Application | EcoWealth Admin</title>
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
            position: relative;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px 0;
        }
        
        .logo {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--neon), var(--accent));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: var(--text-muted);
            font-size: 18px;
        }
        
        .search-box {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 40px;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .search-input {
            flex: 1;
            padding: 16px 20px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            color: var(--text);
            font-size: 16px;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        
        .search-btn {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 16px 30px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
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
        
        .alert.info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.1));
            border-color: var(--info);
            border-left: 5px solid var(--info);
        }
        
        .applications-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .application-card {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .application-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            border-color: var(--accent);
        }
        
        .app-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .app-id {
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .app-badge {
            background: var(--accent);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .app-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text);
        }
        
        .app-details {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .app-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed rgba(34, 197, 94, 0.2);
        }
        
        .meta-item {
            text-align: center;
        }
        
        .meta-value {
            font-weight: 700;
            color: var(--text);
        }
        
        .meta-label {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .action-btn {
            background: linear-gradient(135deg, var(--accent), var(--neon));
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(34, 197, 94, 0.3);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        
        .empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .back-btn {
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 10px;
            padding: 12px 24px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--accent);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">ECO WEALTH FINANCE</div>
            <div class="subtitle">Generate New Investment Agreement</div>
        </div>
        
        <!-- Display Messages -->
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'exclamation-circle' : 'info-circle'); ?>" style="font-size: 20px;"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>
        
        <div class="search-box">
            <h2 style="color: var(--neon); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-search"></i> Find Approved Application
            </h2>
            <p style="color: var(--text-muted); margin-bottom: 25px;">
                Search for approved investment applications by NIC number, name, or email to generate a new agreement.
            </p>
            
            <form method="GET" action="" class="search-form">
                <input type="text" 
                       name="search" 
                       class="search-input" 
                       placeholder="Enter NIC, Name, or Email..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       required>
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
            
            <?php if (!empty($applications)): ?>
                <h3 style="color: var(--text); margin: 30px 0 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-users"></i> Found <?php echo count($applications); ?> Approved Applications
                </h3>
                
                <div class="applications-grid">
                    <?php foreach ($applications as $app): ?>
                    <div class="application-card">
                        <div class="app-header">
                            <div class="app-id">ID: <?php echo $app['id']; ?></div>
                            <div class="app-badge">Approved</div>
                        </div>
                        
                        <div class="app-name"><?php echo htmlspecialchars($app['full_name']); ?></div>
                        
                        <div class="app-details">
                            <div><i class="fas fa-id-card"></i> NIC: <?php echo htmlspecialchars($app['nic_no']); ?></div>
                            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($app['email']); ?></div>
                            <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($app['tel_no']); ?></div>
                            <div><i class="fas fa-chart-line"></i> Investment Type: <?php 
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
                                echo $types[$app['investment_type']] ?? 'Unknown';
                            ?></div>
                        </div>
                        
                        <div class="app-meta">
                            <div class="meta-item">
                                <div class="meta-value"><?php echo $app['years']; ?></div>
                                <div class="meta-label">Years</div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-value"><?php echo $app['formatted_created']; ?></div>
                                <div class="meta-label">Applied</div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-value"><?php echo $app['agreement_count']; ?></div>
                                <div class="meta-label">Agreements</div>
                            </div>
                        </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="investor_id" value="<?php echo $app['id']; ?>">
                            <button type="submit" name="generate_agreement" class="action-btn">
                                <i class="fas fa-file-contract"></i> Generate Agreement
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!empty($search)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3>No Applications Found</h3>
                    <p>No approved applications match your search criteria.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="agreements.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Agreements
            </a>
        </div>
    </div>
    
    <script>
        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>