<?php
session_start(); // THIS LINE IS CRITICAL
require_once 'config.php';
require_once 'db.php';

// Check if user came from a successful form submission
$applicationId = isset($_SESSION['application_id']) ? $_SESSION['application_id'] : null;
$email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : null;

// Debug: Check if session variables exist
error_log("Application ID in session: " . ($applicationId ?? 'NULL'));
error_log("Email in session: " . ($email ?? 'NULL'));

// If no application ID, check for GET parameter or redirect
if (!$applicationId) {
    if (isset($_GET['ref'])) {
        $applicationId = $_GET['ref'];
    } else {
        // If no application ID and no ref parameter, redirect to index
        header('Location: index.php');
        exit();
    }
}

// Clear session data
if ($applicationId && isset($_SESSION['application_id'])) {
    unset($_SESSION['application_id']);
}
if ($email && isset($_SESSION['user_email'])) {
    unset($_SESSION['user_email']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Submitted | Serendib Green Investment</title>
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
            --text: #f0fdf4;
            --text-muted: #a7f3d0;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a2f1d 0%, #064e3b 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(34, 197, 94, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 255, 136, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(16, 185, 129, 0.1) 0%, transparent 50%);
            z-index: -1;
        }
        
        .success-container {
            max-width: 800px;
            width: 100%;
            margin: 0 auto;
            text-align: center;
        }
        
        .success-card {
            background: rgba(10, 47, 29, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 20px;
            padding: 60px 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .success-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--neon), transparent);
        }
        
        .success-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--accent), var(--neon));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: float 3s ease-in-out infinite;
            box-shadow: 0 0 40px var(--accent-glow);
        }
        
        .success-icon i {
            font-size: 60px;
            color: white;
        }
        
        h1 {
            font-size: 48px;
            font-weight: 800;
            background: linear-gradient(90deg, var(--text), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 20px;
        }
        
        .subtitle {
            font-size: 20px;
            color: var(--text-muted);
            margin-bottom: 40px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .application-info {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 30px;
            margin: 30px 0;
            text-align: left;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
        }
        
        .application-id {
            background: linear-gradient(90deg, var(--accent), var(--neon));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 2px;
            padding: 10px 20px;
            border: 2px solid rgba(34, 197, 94, 0.3);
            border-radius: 10px;
            display: inline-block;
            margin: 10px 0;
        }
        
        .next-steps {
            background: rgba(26, 77, 51, 0.3);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 15px;
            padding: 30px;
            margin: 30px 0;
        }
        
        .next-steps h3 {
            color: var(--neon);
            margin-bottom: 20px;
            font-size: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .steps-list {
            list-style: none;
            text-align: left;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .steps-list li {
            margin-bottom: 20px;
            padding-left: 40px;
            position: relative;
        }
        
        .steps-list li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 30px;
            height: 30px;
            background: rgba(34, 197, 94, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--accent);
        }
        
        .steps-list li:nth-child(1)::before { content: '1'; }
        .steps-list li:nth-child(2)::before { content: '2'; }
        .steps-list li:nth-child(3)::before { content: '3'; }
        .steps-list li:nth-child(4)::before { content: '4'; }
        
        .step-title {
            font-weight: 700;
            color: var(--text);
            margin-bottom: 5px;
        }
        
        .step-desc {
            color: var(--text-muted);
            font-size: 14px;
        }
        
        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 16px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            min-width: 200px;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, var(--accent), var(--neon));
            color: white;
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(34, 197, 94, 0.5);
        }
        
        .btn-secondary {
            background: rgba(26, 77, 51, 0.7);
            color: var(--text);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: var(--accent);
            transform: translateY(-3px);
        }
        
        .print-btn {
            background: rgba(59, 130, 246, 0.8);
            color: white;
            border: none;
        }
        
        .print-btn:hover {
            background: rgb(37, 99, 235);
            transform: translateY(-3px);
        }
        
        .floating-elements {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .floating-element {
            position: absolute;
            width: 10px;
            height: 10px;
            background: var(--neon);
            border-radius: 50%;
            opacity: 0.5;
            filter: blur(1px);
            animation: float 15s infinite linear;
        }
        
        .leaf {
            position: absolute;
            width: 20px;
            height: 20px;
            background: var(--neon);
            clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
            opacity: 0.3;
            animation: floatLeaf 20s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translateY(0) translateX(0) scale(1); }
            25% { transform: translateY(-20px) translateX(10px) scale(1.1); }
            50% { transform: translateY(0) translateX(20px) scale(1); }
            75% { transform: translateY(20px) translateX(10px) scale(0.9); }
            100% { transform: translateY(0) translateX(0) scale(1); }
        }
        
        @keyframes floatLeaf {
            0% { transform: translateY(0) translateX(0) rotate(0deg); }
            25% { transform: translateY(-30px) translateX(15px) rotate(90deg); }
            50% { transform: translateY(0) translateX(30px) rotate(180deg); }
            75% { transform: translateY(30px) translateX(15px) rotate(270deg); }
            100% { transform: translateY(0) translateX(0) rotate(360deg); }
        }
        
        .confetti {
            position: absolute;
            width: 15px;
            height: 15px;
            opacity: 0;
        }
        
        .email-note {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .email-note i {
            color: var(--accent);
            margin-right: 10px;
        }
        
        .countdown {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 10px;
        }
        
        .countdown span {
            color: var(--neon);
            font-weight: 700;
            font-size: 16px;
        }
        
        @media (max-width: 768px) {
            .success-card {
                padding: 40px 20px;
            }
            
            h1 {
                font-size: 36px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .application-id {
                font-size: 22px;
                word-break: break-all;
            }
        }
        
        @keyframes successIcon {
            0% { transform: scale(0); opacity: 0; }
            70% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .success-icon {
            animation: successIcon 0.5s ease-out;
        }
    </style>
</head>
<body>
    <div class="floating-elements" id="floatingElements"></div>
    
    <div class="success-container">
        <div class="success-card">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            
            <h1>Application Submitted Successfully!</h1>
            <p class="subtitle">Thank you for choosing Serendib Green Investment. Your investment application has been received and is being processed.</p>
            
            <div class="application-info">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Application Reference</div>
                        <div class="application-id"><?php echo htmlspecialchars($applicationId ? "EWI-" . str_pad($applicationId, 6, '0', STR_PAD_LEFT) : "EWI-" . ($_GET['ref'] ?? 'N/A')); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Submission Date</div>
                        <div class="info-value"><?php echo date('F d, Y'); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Submission Time</div>
                        <div class="info-value"><?php echo date('h:i A'); ?></div>
                    </div>
                </div>
                
                <?php if($email): ?>
                <div class="email-note">
                    <p><i class="fas fa-envelope"></i> A confirmation email has been sent to <strong><?php echo htmlspecialchars($email); ?></strong></p>
                    
                </div>
                <?php endif; ?>
            </div>
            
            <div class="next-steps">
                <h3><i class="fas fa-list-check"></i> What Happens Next?</h3>
                <ul class="steps-list">
                    <li>
                        <div class="step-title">Application Review</div>
                        <div class="step-desc">Our team will review your application within 24-48 hours.</div>
                    </li>
                    <li>
                        <div class="step-title">Document Verification</div>
                        <div class="step-desc">We'll verify all submitted documents and information.</div>
                    </li>
                    <li>
                        <div class="step-title">Approval Notification</div>
                        <div class="step-desc">You'll receive an email notification once your application is approved.</div>
                    </li>
                    <li>
                        <div class="step-title">Account Activation</div>
                        <div class="step-desc">Your investment account will be activated and you'll receive login credentials.</div>
                    </li>
                </ul>
            </div>
            
            <div class="action-buttons">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Back to Home
                </a>
                <a href="javascript:void(0)" onclick="window.print()" class="btn print-btn">
                    <i class="fas fa-print"></i> Print Confirmation
                </a>
                <a href="mailto:support@serendib.com" class="btn btn-secondary">
                    <i class="fas fa-headset"></i> Contact Support
                </a>
            </div>
            
            <div style="margin-top: 30px; font-size: 14px; color: var(--text-muted);">
                <p>For any queries, please email us at <a href="mailto:support@serendib.com" style="color: var(--neon);">support@serendib.com</a> or call <strong style="color: var(--text);">+94 11 234 5678</strong></p>
            </div>
        </div>
    </div>

    <script>
        // Create floating elements
        function createFloatingElements() {
            const container = document.getElementById('floatingElements');
            if (!container) return;
            
            for (let i = 0; i < 20; i++) {
                const element = document.createElement('div');
                element.className = 'floating-element';
                element.style.top = `${Math.random() * 100}%`;
                element.style.left = `${Math.random() * 100}%`;
                element.style.animationDelay = `${Math.random() * 10}s`;
                element.style.animationDuration = `${15 + Math.random() * 15}s`;
                container.appendChild(element);
            }
            
            for (let i = 0; i < 10; i++) {
                const leaf = document.createElement('div');
                leaf.className = 'leaf';
                leaf.style.top = `${Math.random() * 100}%`;
                leaf.style.left = `${Math.random() * 100}%`;
                leaf.style.animationDelay = `${Math.random() * 10}s`;
                leaf.style.animationDuration = `${20 + Math.random() * 20}s`;
                container.appendChild(leaf);
            }
        }
        
        // Countdown timer for redirect
       
        
        // Create confetti effect
        function createConfetti() {
            const colors = ['#22c55e', '#00ff88', '#10b981', '#0ea5e9', '#8b5cf6'];
            const container = document.body;
            
            for (let i = 0; i < 100; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.position = 'absolute';
                confetti.style.width = `${Math.random() * 10 + 5}px`;
                confetti.style.height = `${Math.random() * 10 + 5}px`;
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.left = `${Math.random() * 100}vw`;
                confetti.style.top = '-20px';
                confetti.style.borderRadius = Math.random() > 0.5 ? '50%' : '0';
                confetti.style.opacity = '0';
                
                container.appendChild(confetti);
                
                // Animate confetti
                const animation = confetti.animate([
                    { 
                        opacity: 0, 
                        transform: 'translateY(0) rotate(0deg)',
                        top: '-20px'
                    },
                    { 
                        opacity: 1, 
                        transform: `translateY(${Math.random() * 20 + 10}vh) rotate(${Math.random() * 360}deg)`,
                        top: '20px'
                    },
                    { 
                        opacity: 0, 
                        transform: `translateY(${window.innerHeight + 100}px) rotate(${Math.random() * 720}deg)`,
                        top: `${window.innerHeight}px`
                    }
                ], {
                    duration: Math.random() * 3000 + 2000,
                    delay: Math.random() * 1000,
                    easing: 'cubic-bezier(0.215, 0.61, 0.355, 1)'
                });
                
                animation.onfinish = () => confetti.remove();
            }
        }
        
        // Print confirmation function
        function printConfirmation() {
            const printContent = `
                <html>
                <head>
                    <title>Serendib Green Investment Application Confirmation</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .header { text-align: center; margin-bottom: 30px; }
                        .logo { color: #0a2f1d; font-weight: bold; font-size: 24px; }
                        .confirmation-id { background: #f0fdf4; padding: 10px; border-radius: 5px; font-size: 20px; margin: 20px 0; }
                        .details { margin: 20px 0; }
                        .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <div class="logo">SERENDIB GREEN INVESTMENT</div>
                        <h1>Application Confirmation</h1>
                        <p>Your investment application has been successfully submitted</p>
                    </div>
                    
                    <div style="text-align: center;">
                        <div class="confirmation-id">
                            Application Reference: <?php echo $applicationId ? "EWI-" . str_pad($applicationId, 6, '0', STR_PAD_LEFT) : "EWI-" . ($_GET['ref'] ?? 'N/A'); ?>
                        </div>
                        <p>Date: <?php echo date('F d, Y'); ?></p>
                        <p>Time: <?php echo date('h:i A'); ?></p>
                    </div>
                    
                    <div class="details">
                        <p>Thank you for choosing Serendib Green Investment. Your application is now being processed.</p>
                        <p><strong>Next Steps:</strong></p>
                        <ol>
                            <li>Application review within 24-48 hours</li>
                            <li>Document verification</li>
                            <li>Approval notification via email</li>
                            <li>Account activation and credentials sent</li>
                        </ol>
                    </div>
                    
                    <div class="footer">
                        <p>For any queries, please contact:</p>
                        <p>Email: support@serendib.com | Phone: +94 11 234 5678</p>
                        <p>© <?php echo date('Y'); ?> Serendib Green Investment. All rights reserved.</p>
                    </div>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            createFloatingElements();
            createConfetti();
            
            // Override print button
            const printBtn = document.querySelector('.print-btn');
            if (printBtn) {
                printBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    printConfirmation();
                });
            }
        });
    </script>
</body>
</html>