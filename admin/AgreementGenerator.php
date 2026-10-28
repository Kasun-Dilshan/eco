<?php
require_once dirname(__DIR__) . '/includes/agreement_helpers.php';

class AgreementGenerator {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function getInvestorData($investorId) {
        try {
            // Get investor information
            $stmt = $this->db->prepare("
                SELECT i.*, 
                       DATE_FORMAT(i.dob, '%M %d, %Y') as formatted_dob,
                       DATE_FORMAT(i.signing_date, '%M %d, %Y') as formatted_signing,
                       DATE_FORMAT(i.declaration_date, '%M %d, %Y') as formatted_declaration,
                       DATE_FORMAT(i.created_at, '%M %d, %Y') as formatted_created
                FROM investors i 
                WHERE i.id = ?
            ");
            $stmt->execute([$investorId]);
            $investor = $stmt->fetch();
            
            if (!$investor) {
                return false;
            }
            
            // Get beneficiaries
            $benStmt = $this->db->prepare("
                SELECT * FROM beneficiaries 
                WHERE investor_id = ? 
                ORDER BY percentage DESC
            ");
            $benStmt->execute([$investorId]);
            $beneficiaries = $benStmt->fetchAll();
            
            return [
                'investor' => $investor,
                'beneficiaries' => $beneficiaries
            ];
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function getInvestmentTypeName($type) {
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
    
    public function getTemplates() {
        return [
            ['id' => 1, 'name' => 'HPP Agreement', 'description' => 'High Profit Plan agreement'],
            ['id' => 2, 'name' => 'GSP Agreement', 'description' => 'Green Saving Plan agreement'],
            ['id' => 3, 'name' => 'GSI Agreement', 'description' => 'Green Silver Plan agreement'],
            ['id' => 4, 'name' => 'GOLD Agreement', 'description' => 'Gold Plan agreement'],
            ['id' => 5, 'name' => 'SFPS Agreement', 'description' => 'Seraa Farm Profit Share Plan agreement'],
            ['id' => 6, 'name' => 'SFHPS Agreement', 'description' => 'Seraa Farm High Profit Share Plan agreement'],
        ];
    }
    
    public function generateAgreement($investorId, $templateId, $generatedBy) {
        try {
            // Get investor data
            $investorData = $this->getInvestorData($investorId);
            if (!$investorData) {
                return ['success' => false, 'message' => 'Investor not found'];
            }
            
            $investor = $investorData['investor'];

            // Force template from investment plan (6 types = 6 templates)
            if (function_exists('agreementTemplateIdForInvestmentType')) {
                $templateId = agreementTemplateIdForInvestmentType((string)($investor['investment_type'] ?? 'HPP'));
            }
            
            // Create agreements table if it doesn't exist
            $this->createAgreementsTable();

            $result = createAgreementForInvestor($this->db, $investor, (string)$generatedBy);
            if (empty($result['success'])) {
                return ['success' => false, 'message' => $result['message'] ?? 'Failed to create agreement'];
            }

            $agreementId = (int)$result['agreement_id'];
            $agreementNumber = (string)($result['agreement_number'] ?? '');
            
            // Log the generation
            $logStmt = $this->db->prepare("
                INSERT INTO application_logs (investor_id, action, description, performed_by)
                VALUES (?, ?, ?, ?)
            ");
            
            $logStmt->execute([
                $investorId,
                'agreement_generated',
                'Generated agreement ' . $agreementNumber . ' using template ' . $templateId,
                $generatedBy
            ]);
            
            return [
                'success' => true,
                'message' => 'Agreement generated successfully!',
                'agreement_id' => $agreementId,
                'agreement_number' => $agreementNumber
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function getInvestorAgreements($investorId) {
        try {
            // Create agreements table if it doesn't exist
            $this->createAgreementsTable();
            
            $stmt = $this->db->prepare("
                SELECT a.*, 
                       DATE_FORMAT(a.generated_at, '%M %d, %Y %H:%i') as formatted_generated,
                       COALESCE(a.template, 'HPP') as template_name
                FROM agreements a 
                WHERE a.investor_id = ? 
                ORDER BY a.generated_at DESC
            ");
            
            $stmt->execute([$investorId]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    private function createAgreementsTable() {
        try {
            $this->db->exec("
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
                    FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE CASCADE,
                    INDEX idx_investor_id (investor_id),
                    INDEX idx_agreement_number (agreement_number),
                    INDEX idx_status (status)
                )
            ");
        } catch (PDOException $e) {
            // Table already exists or cannot be created
        }
    }
}
?>