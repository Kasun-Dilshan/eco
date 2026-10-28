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
            error_log('EcoWealth database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Database connection error. Please contact the administrator.');
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
            nic_no VARCHAR(20) NOT NULL,
            INDEX idx_investors_nic_no (nic_no),
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
                "other_documents VARCHAR(255) NULL",
                "agreement_no VARCHAR(50) NULL",
                "sales_person_code VARCHAR(50) NULL",
                "applicant_type VARCHAR(20) NOT NULL DEFAULT 'person'",
                "gsp_plan VARCHAR(20) NULL",
                "gsp_payment_option VARCHAR(50) NULL",
                "maturity_amount DECIMAL(12,2) NULL"
            ]);

            $this->ensureSalesPersonsTable();
            $this->ensureInvestorFeedbackTable();
            
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
                    'subject' => 'Your SERENDIB GREEN PLANTATION PVT LTD Investment Application Has Been Received',
                    'body' => '<h2>Application Received</h2><p>Dear {full_name},</p><p>Thank you for submitting your investment application to SERENDIB GREEN PLANTATION PVT LTD. We have received your application and it is currently under review.</p><p><strong>Application ID:</strong> {application_id}</p><p>We will review your application and contact you within 3-5 business days.</p><p>Best regards,<br>SERENDIB GREEN PLANTATION PVT LTD</p>',
                    'variables' => 'full_name,application_id'
                ],
                [
                    'template_name' => 'Application Approved',
                    'subject' => 'Congratulations! Your SERENDIB GREEN PLANTATION PVT LTD Application Has Been Approved',
                    'body' => '<h2>Application Approved</h2><p>Dear {full_name},</p><p>We are pleased to inform you that your investment application has been approved!</p><p><strong>Application ID:</strong> {application_id}</p><p>Your investment account has been activated. You will receive your login credentials in a separate email.</p><p>If you have any questions, please don\'t hesitate to contact us.</p><p>Welcome to the EcoWealth family!</p><p>Best regards,<br>SERENDIB GREEN PLANTATION PVT LTD</p>',
                    'variables' => 'full_name,application_id'
                ],
                [
                    'template_name' => 'Additional Information Required',
                    'subject' => 'Additional Information Required for Your SERENDIB GREEN PLANTATION PVT LTD Application',
                    'body' => '<h2>Additional Information Required</h2><p>Dear {full_name},</p><p>We are reviewing your investment application and require some additional information:</p><p>{additional_notes}</p><p>Please provide this information at your earliest convenience so we can continue processing your application.</p><p><strong>Application ID:</strong> {application_id}</p><p>Thank you for your cooperation.</p><p>Best regards,<br>SERENDIB GREEN PLANTATION PVT LTD</p>',
                    'variables' => 'full_name,application_id,additional_notes'
                ],
                [
                    'template_name' => 'Application Rejected',
                    'subject' => 'Update on Your SERENDIB GREEN PLANTATION PVT LTD Investment Application',
                    'body' => '<h2>Application Update</h2><p>Dear {full_name},</p><p>Thank you for your interest in SERENDIB GREEN PLANTATION PVT LTD. After careful review, we regret to inform you that your application has not been approved at this time.</p><p><strong>Reason:</strong> {rejection_reason}</p><p><strong>Application ID:</strong> {application_id}</p><p>We encourage you to review our eligibility criteria and consider applying again in the future.</p><p>If you have any questions about this decision, please contact our support team.</p><p>Best regards,<br>SERENDIB GREEN PLANTATION PVT LTD</p>',
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
            error_log('EcoWealth: Error creating tables: ' . $e->getMessage());
        }
    }

    private function ensureSalesPersonsTable() {
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS sales_persons (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    branch VARCHAR(100) NOT NULL,
                    epf_no VARCHAR(20) NOT NULL,
                    sales_code VARCHAR(50) NOT NULL,
                    phone_number VARCHAR(20) NULL,
                    email VARCHAR(255) NULL,
                    sales_rank VARCHAR(100) NULL,
                    reported_sales_person_id INT NULL,
                    status ENUM('active', 'inactive') DEFAULT 'active',
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_epf_no (epf_no),
                    UNIQUE KEY unique_sales_code (sales_code),
                    INDEX idx_branch (branch),
                    INDEX idx_status (status),
                    INDEX idx_reported_sales_person (reported_sales_person_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->ensureSalesPersonColumns([
                "phone_number VARCHAR(20) NULL",
                "email VARCHAR(255) NULL",
                "sales_rank VARCHAR(100) NULL",
                "reported_sales_person_id INT NULL",
            ]);
        } catch (PDOException $e) {
            debug_log("Sales persons table migration failed", $e->getMessage());
        }
    }

    private function ensureSalesPersonColumns(array $columnDefinitions) {
        try {
            $stmt = $this->connection->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'sales_persons'
            ");
            $stmt->execute();
            $existing = array_map('strtolower', array_column($stmt->fetchAll(), 'COLUMN_NAME'));

            foreach ($columnDefinitions as $def) {
                $name = strtolower(trim(strtok($def, ' ')));
                if (!in_array($name, $existing, true)) {
                    $this->connection->exec("ALTER TABLE sales_persons ADD COLUMN {$def}");
                }
            }
        } catch (PDOException $e) {
            debug_log("Sales person column migration failed", $e->getMessage());
        }
    }

    public function ensureSchema() {
        try {
            $tableCheck = $this->connection->query("SHOW TABLES LIKE 'investors'");
            if (!$tableCheck || $tableCheck->rowCount() === 0) {
                return;
            }
        } catch (PDOException $e) {
            return;
        }

        $this->ensureInvestorColumns([
            "investment_type VARCHAR(50) NULL",
            "investment_amount DECIMAL(12,2) NULL",
            "currency VARCHAR(10) NULL",
            "proof_documents VARCHAR(255) NULL",
            "other_documents VARCHAR(255) NULL",
            "agreement_no VARCHAR(50) NULL",
            "sales_person_code VARCHAR(50) NULL",
            "approver_level INT NOT NULL DEFAULT 1",
            "current_approver VARCHAR(255) NULL",
            "portal_password_hash VARCHAR(255) NULL",
            "portal_last_login TIMESTAMP NULL",
            "portal_failed_attempts INT NOT NULL DEFAULT 0",
            "profile_photo VARCHAR(255) NULL",
            "applicant_type VARCHAR(20) NOT NULL DEFAULT 'person'",
            "gsp_plan VARCHAR(20) NULL",
            "gsp_payment_option VARCHAR(50) NULL",
            "maturity_amount DECIMAL(12,2) NULL",
        ]);

        $this->ensureSalesPersonsTable();
        $this->ensureInvestorFeedbackTable();

        // Same customer (NIC) may submit multiple proposals
        $this->allowMultipleProposalsPerNic();
        $this->replaceSecondApproverWithIsurindi();
    }

    /**
     * Replace Hesanthi (or the placeholder second approver) with Isurindi.
     * Copies Isurindi's staff password so she can log in as second approver.
     */
    private function replaceSecondApproverWithIsurindi() {
        try {
            $tableCheck = $this->connection->query("SHOW TABLES LIKE 'approvers'");
            if (!$tableCheck || $tableCheck->rowCount() === 0) {
                return;
            }

            $targetName = 'Isurindi';
            $targetEmail = 'isurindi@serendibgroups.com';
            $targetPassword = null;

            $usersCheck = $this->connection->query("SHOW TABLES LIKE 'users'");
            if ($usersCheck && $usersCheck->rowCount() > 0) {
                $stmt = $this->connection->query("
                    SELECT full_name, username, email, password_hash
                    FROM users
                    WHERE LOWER(username) = 'isurindi'
                       OR LOWER(email) = 'isurindi@serendibgroups.com'
                       OR LOWER(full_name) LIKE '%isurindi%'
                    ORDER BY id ASC
                    LIMIT 1
                ");
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $fromName = trim((string) ($user['full_name'] ?: $user['username']));
                    if ($fromName !== '') {
                        $targetName = $fromName;
                    }
                    $fromEmail = trim((string) ($user['email'] ?? ''));
                    if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                        $targetEmail = $fromEmail;
                    }
                    if (!empty($user['password_hash'])) {
                        $targetPassword = $user['password_hash'];
                    }
                }
            }

            $stmt = $this->connection->query("
                SELECT id, name, email, role, level
                FROM approvers
                WHERE level = 2 OR role = 'second_approver'
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                return;
            }

            $targetEmailLower = strtolower($targetEmail);
            $onlyOneSecondApprover = count($rows) === 1;

            foreach ($rows as $row) {
                $name = strtolower(trim((string) ($row['name'] ?? '')));
                $email = strtolower(trim((string) ($row['email'] ?? '')));

                if (strpos($name, 'isurindi') !== false || $email === $targetEmailLower) {
                    continue;
                }

                $isHesanthi = (strpos($name, 'hesanthi') !== false)
                    || (strpos($email, 'hesanthi') !== false)
                    || strpos($email, 'hesa@') === 0;
                $isPlaceholder = ($name === 'second approver')
                    || (strpos($email, 'second.approver') !== false);

                if (!$isHesanthi && !$isPlaceholder && !$onlyOneSecondApprover) {
                    continue;
                }

                if ($targetPassword) {
                    $upd = $this->connection->prepare("
                        UPDATE approvers
                        SET name = ?, email = ?, password = ?, role = 'second_approver', level = 2, status = 'active'
                        WHERE id = ?
                    ");
                    $upd->execute([$targetName, $targetEmail, $targetPassword, $row['id']]);
                } else {
                    $upd = $this->connection->prepare("
                        UPDATE approvers
                        SET name = ?, email = ?, role = 'second_approver', level = 2, status = 'active'
                        WHERE id = ?
                    ");
                    $upd->execute([$targetName, $targetEmail, $row['id']]);
                }
            }
        } catch (PDOException $e) {
            debug_log('replaceSecondApproverWithIsurindi failed', $e->getMessage());
        }
    }

    private function ensureInvestorFeedbackTable() {
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS investor_feedback (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    investor_id INT NOT NULL,
                    investor_name VARCHAR(255) NOT NULL,
                    nic_no VARCHAR(20) NULL,
                    feedback_text TEXT NOT NULL,
                    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                    reviewed_by INT NULL,
                    reviewed_by_name VARCHAR(255) NULL,
                    reviewed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_feedback_status (status),
                    INDEX idx_feedback_investor (investor_id),
                    INDEX idx_feedback_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            debug_log("Investor feedback table migration failed", $e->getMessage());
        }
    }

    /**
     * Drop UNIQUE constraint on investors.nic_no so one customer can have many applications.
     * Keeps a non-unique index for lookups.
     */
    private function allowMultipleProposalsPerNic() {
        try {
            $stmt = $this->connection->query("
                SHOW INDEX FROM investors
                WHERE Column_name = 'nic_no' AND Non_unique = 0
            ");
            $uniqueIndexes = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            foreach ($uniqueIndexes as $idx) {
                $keyName = $idx['Key_name'] ?? '';
                if ($keyName === '' || strcasecmp($keyName, 'PRIMARY') === 0) {
                    continue;
                }
                $this->connection->exec('ALTER TABLE investors DROP INDEX `' . str_replace('`', '``', $keyName) . '`');
            }

            $nonUnique = $this->connection->query("
                SHOW INDEX FROM investors
                WHERE Column_name = 'nic_no' AND Non_unique = 1
            ");
            if ($nonUnique && $nonUnique->rowCount() === 0) {
                $this->connection->exec('ALTER TABLE investors ADD INDEX idx_investors_nic_no (nic_no)');
            }
        } catch (PDOException $e) {
            debug_log('NIC uniqueness migration failed', $e->getMessage());
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

try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'investors'");
    if ($tableCheck && $tableCheck->rowCount() === 0) {
        $database->createTables();
    } else {
        $database->ensureSchema();
    }
} catch (PDOException $e) {
    error_log('EcoWealth: table initialization check failed: ' . $e->getMessage());
}
