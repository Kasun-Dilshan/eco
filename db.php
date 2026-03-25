<?php
require_once 'config.php';

class Database {
    private $connection;
    
    public function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Create tables if they don't exist
    public function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS investors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL,
            name_with_initials VARCHAR(255) NOT NULL,
            nic_no VARCHAR(20) NOT NULL UNIQUE,
            tel_no VARCHAR(20) NOT NULL,
            dob DATE NOT NULL,
            email VARCHAR(255) NOT NULL,
            address TEXT NOT NULL,
            occupation VARCHAR(100) NOT NULL,
            employer_name VARCHAR(255) NOT NULL,
            years INT NOT NULL,
            investment_type VARCHAR(50) NULL,
            investment_amount DECIMAL(12,2) NULL,
            currency VARCHAR(10) NULL,
            signing_date DATE NOT NULL,
            account_no VARCHAR(50) NOT NULL,
            bank_name VARCHAR(100) NOT NULL,
            branch_name VARCHAR(100) NOT NULL,
            declaration_date DATE NOT NULL,
            signature_upload VARCHAR(255),
            investor_id_doc VARCHAR(255),
            beneficiary_id_doc VARCHAR(255),
            passbook_doc VARCHAR(255),
            payment_slip_doc VARCHAR(255),
            final_signature VARCHAR(255),
            proof_documents VARCHAR(255),
            other_documents VARCHAR(255),
            status ENUM('pending', 'reviewed', 'approved', 'rejected') DEFAULT 'pending',
            admin_notes TEXT,
            reviewed_by INT,
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS beneficiaries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            investor_id INT NOT NULL,
            beneficiary_name VARCHAR(255) NOT NULL,
            beneficiary_nic VARCHAR(20) NOT NULL,
            percentage INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS application_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            investor_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            description TEXT,
            performed_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(100) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role ENUM('super_admin', 'admin', 'staff') DEFAULT 'staff',
            last_login TIMESTAMP NULL,
            login_attempts INT DEFAULT 0,
            is_locked TINYINT(1) DEFAULT 0,
            lockout_until TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS email_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_name VARCHAR(100) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            variables TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS sent_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            investor_id INT NOT NULL,
            admin_id INT,
            template_id INT,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            status ENUM('sent', 'failed') DEFAULT 'sent',
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
            FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        try {
            $this->connection->exec($sql);

            // Ensure columns exist for older databases (safe migrations)
            $this->ensureInvestorColumns([
                "investment_type VARCHAR(50) NULL",
                "investment_amount DECIMAL(12,2) NULL",
                "currency VARCHAR(10) NULL",
                "proof_documents VARCHAR(255) NULL",
                "other_documents VARCHAR(255) NULL"
            ]);
            
            // Insert default admin user if not exists
            $stmt = $this->connection->prepare("SELECT COUNT(*) FROM admin_users WHERE username = 'admin'");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count == 0) {
                $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $this->connection->prepare("
                    INSERT INTO admin_users (username, password_hash, email, full_name, role) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute(['admin', $defaultPassword, 'admin@ecowealth.com', 'System Administrator', 'super_admin']);
            }
            
            // Insert default email templates
            $templates = [
                [
                    'template_name' => 'Application Received',
                    'subject' => 'Your EcoWealth Investment Application Has Been Received',
                    'body' => '<h2>Application Received</h2><p>Dear {full_name},</p><p>Thank you for submitting your investment application to EcoWealth Finance. We have received your application and it is currently under review.</p><p><strong>Application ID:</strong> {application_id}</p><p>We will review your application and contact you within 3-5 business days.</p><p>Best regards,<br>EcoWealth Finance Team</p>',
                    'variables' => 'full_name,application_id'
                ],
                [
                    'template_name' => 'Application Approved',
                    'subject' => 'Congratulations! Your EcoWealth Application Has Been Approved',
                    'body' => '<h2>Application Approved</h2><p>Dear {full_name},</p><p>We are pleased to inform you that your investment application has been approved!</p><p><strong>Application ID:</strong> {application_id}</p><p>Your investment account has been activated. You will receive your login credentials in a separate email.</p><p>If you have any questions, please don\'t hesitate to contact us.</p><p>Welcome to the EcoWealth family!</p><p>Best regards,<br>EcoWealth Finance Team</p>',
                    'variables' => 'full_name,application_id'
                ],
                [
                    'template_name' => 'Additional Information Required',
                    'subject' => 'Additional Information Required for Your EcoWealth Application',
                    'body' => '<h2>Additional Information Required</h2><p>Dear {full_name},</p><p>We are reviewing your investment application and require some additional information:</p><p>{additional_notes}</p><p>Please provide this information at your earliest convenience so we can continue processing your application.</p><p><strong>Application ID:</strong> {application_id}</p><p>Thank you for your cooperation.</p><p>Best regards,<br>EcoWealth Finance Team</p>',
                    'variables' => 'full_name,application_id,additional_notes'
                ],
                [
                    'template_name' => 'Application Rejected',
                    'subject' => 'Update on Your EcoWealth Investment Application',
                    'body' => '<h2>Application Update</h2><p>Dear {full_name},</p><p>Thank you for your interest in EcoWealth Finance. After careful review, we regret to inform you that your application has not been approved at this time.</p><p><strong>Reason:</strong> {rejection_reason}</p><p><strong>Application ID:</strong> {application_id}</p><p>We encourage you to review our eligibility criteria and consider applying again in the future.</p><p>If you have any questions about this decision, please contact our support team.</p><p>Best regards,<br>EcoWealth Finance Team</p>',
                    'variables' => 'full_name,application_id,rejection_reason'
                ]
            ];
            
            foreach ($templates as $template) {
                $stmt = $this->connection->prepare("
                    SELECT COUNT(*) FROM email_templates WHERE template_name = ?
                ");
                $stmt->execute([$template['template_name']]);
                $count = $stmt->fetchColumn();
                
                if ($count == 0) {
                    $stmt = $this->connection->prepare("
                        INSERT INTO email_templates (template_name, subject, body, variables) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $template['template_name'],
                        $template['subject'],
                        $template['body'],
                        $template['variables']
                    ]);
                }
            }
            
            debug_log("Tables created successfully");
        } catch (PDOException $e) {
            debug_log("Error creating tables", $e->getMessage());
            die("Error creating tables: " . $e->getMessage());
        }
    }

    private function ensureInvestorColumns(array $columnDefinitions) {
        try {
            $stmt = $this->connection->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'investors'
            ");
            $stmt->execute();
            $existing = array_map('strtolower', array_column($stmt->fetchAll(), 'COLUMN_NAME'));

            foreach ($columnDefinitions as $def) {
                $name = strtolower(trim(strtok($def, ' ')));
                if (!in_array($name, $existing, true)) {
                    $this->connection->exec("ALTER TABLE investors ADD COLUMN {$def}");
                }
            }
        } catch (PDOException $e) {
            // If migrations fail, log but don't hard-fail table creation.
            debug_log("Investor column migration failed", $e->getMessage());
        }
    }
}

// Create database instance
$database = new Database();
$db = $database->getConnection();
$database->createTables();
?>