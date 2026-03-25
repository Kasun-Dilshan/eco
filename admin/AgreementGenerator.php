<?php
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
    
    public function getTemplates() {
        return [
            ['id' => 1, 'name' => 'Standard Investment Agreement', 'description' => 'Standard agreement with basic terms'],
            ['id' => 2, 'name' => 'Premium Investment Agreement', 'description' => 'Detailed agreement with comprehensive terms'],
            ['id' => 3, 'name' => 'Simple Investment Agreement', 'description' => 'Basic agreement for simple investments']
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
            
            // Generate agreement number
            $agreementNumber = 'AGR-' . str_pad($investorId, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd') . rand(100, 999);
            
            // Create agreements table if it doesn't exist
            $this->createAgreementsTable();
            
            // Insert agreement record
            $stmt = $this->db->prepare("
                INSERT INTO agreements (
                    investor_id, agreement_number, template_id, 
                    status, generated_by, generated_at
                ) VALUES (?, ?, ?, 'draft', ?, NOW())
            ");
            
            $stmt->execute([
                $investorId,
                $agreementNumber,
                $templateId,
                $generatedBy
            ]);
            
            $agreementId = $this->db->lastInsertId();
            
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
                       CASE a.template_id 
                           WHEN 1 THEN 'Standard Investment Agreement'
                           WHEN 2 THEN 'Premium Investment Agreement'
                           WHEN 3 THEN 'Simple Investment Agreement'
                           ELSE 'Unknown Template'
                       END as template_name
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