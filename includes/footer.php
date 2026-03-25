<?php
// Close any open connections or sessions
function cleanup() {
    // Clear sensitive session data if needed
    if (isset($_SESSION['form_data'])) {
        unset($_SESSION['form_data']);
    }
}

// Function to send confirmation email
function sendConfirmationEmail($email, $name, $reference_no) {
    $subject = "EcoWealth Investment Application Confirmation";
    
    $message = "
    <html>
    <head>
        <title>Application Confirmation</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0a2f1d; color: white; padding: 20px; text-align: center; }
            .content { padding: 30px; background: #f9f9f9; }
            .reference { background: #e8f5e9; padding: 15px; border-left: 4px solid #22c55e; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>EcoWealth Finance</h2>
                <h3>Green Investor Portal</h3>
            </div>
            <div class='content'>
                <h4>Dear $name,</h4>
                <p>Thank you for submitting your investment application to EcoWealth Finance.</p>
                
                <div class='reference'>
                    <strong>Application Reference Number:</strong><br>
                    <h3>$reference_no</h3>
                    <p>Please keep this reference number for all future communications.</p>
                </div>
                
                <p><strong>Application Status:</strong> Pending Review</p>
                <p>Our team will review your application within 3-5 working days. You will receive an update via email once the review is complete.</p>
                
                <p><strong>Next Steps:</strong></p>
                <ul>
                    <li>Verification of submitted documents</li>
                    <li>Background check (if required)</li>
                    <li>Final approval and account activation</li>
                </ul>
                
                <p>If you have any questions, please contact our support team at investors@ecowealth.com or call +94 11 234 5678.</p>
            </div>
            <div class='footer'>
                <p>This is an automated email. Please do not reply to this message.</p>
                <p>© " . date('Y') . " EcoWealth Finance. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // For production, use a proper mailer like PHPMailer
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: EcoWealth Finance <noreply@ecowealth.com>" . "\r\n";
    
    return mail($email, $subject, $message, $headers);
}

// Cleanup on script end
register_shutdown_function('cleanup');
?>