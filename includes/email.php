<?php
// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php'; // If using Composer
// OR if manual installation:
// require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
// require_once __DIR__ . '/../phpmailer/src/SMTP.php';
// require_once __DIR__ . '/../phpmailer/src/Exception.php';

class EmailSender {
    private $mail;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host       = SMTP_HOST;
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = SMTP_USERNAME;
            $this->mail->Password   = SMTP_PASSWORD;
            $this->mail->SMTPSecure = SMTP_SECURE;
            $this->mail->Port       = SMTP_PORT;
            
            // Sender
            $this->mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $this->mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            // Content
            $this->mail->isHTML(true);
            $this->mail->CharSet = 'UTF-8';
            
        } catch (Exception $e) {
            error_log("Email setup failed: " . $e->getMessage());
            throw new Exception("Email configuration error: " . $e->getMessage());
        }
    }
    
    public function sendEmail($toEmail, $toName, $subject, $body, $attachments = []) {
        try {
            // Clear previous recipients
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            // Add recipient
            $this->mail->addAddress($toEmail, $toName);
            
            // Subject and body
            $this->mail->Subject = $subject;
            $this->mail->Body    = $this->formatEmailBody($body);
            $this->mail->AltBody = strip_tags($body);
            
            // Add attachments if any
            foreach ($attachments as $attachment) {
                if (file_exists($attachment['path'])) {
                    $this->mail->addAttachment(
                        $attachment['path'],
                        $attachment['name'] ?? basename($attachment['path'])
                    );
                }
            }
            
            // Send email
            $result = $this->mail->send();
            
            // Log the email
            $this->logEmail($toEmail, $toName, $subject, $body, $result);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Email sending failed to $toEmail: " . $e->getMessage());
            return false;
        }
    }
    
    private function formatEmailBody($body) {
        // Add HTML structure and footer
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0a2f1d; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                .button { display: inline-block; padding: 10px 20px; background: #22c55e; color: white; text-decoration: none; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>EcoWealth Finance</h2>
                </div>
                <div class="content">
                    ' . $body . '
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' EcoWealth Finance. All rights reserved.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>For inquiries, contact: umesh@serendibgroups.com</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    private function logEmail($toEmail, $toName, $subject, $body, $success) {
        // Create logs directory if it doesn't exist
        $logDir = __DIR__ . '/../logs/';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        // Log to file
        $logMessage = date('Y-m-d H:i:s') . " - " . 
                     ($success ? "SENT" : "FAILED") . " - " .
                     "To: $toEmail ($toName) - " .
                     "Subject: $subject\n";
        
        file_put_contents($logDir . 'email.log', $logMessage, FILE_APPEND);
    }
}

// Simple email function for quick use
function sendSimpleEmail($to, $subject, $message, $fromName = null) {
    try {
        $emailSender = new EmailSender();
        $fromName = $fromName ?: SMTP_FROM_NAME;
        
        return $emailSender->sendEmail($to, '', $subject, $message);
        
    } catch (Exception $e) {
        error_log("Simple email failed: " . $e->getMessage());
        return false;
    }
}
?>