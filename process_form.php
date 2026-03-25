<?php
session_start();
require_once 'config.php';
require_once 'db.php';



// Debug: Log POST data
debug_log("Form submission started", $_POST);
debug_log("Files received", $_FILES);

class FormProcessor {
    private $db;
    private $uploadDir;
    
    public function __construct() {
        $this->db = $GLOBALS['db'];
        $this->uploadDir = UPLOAD_DIR;
    }
    
    private function validateFile($file, $fieldName) {
        $errors = [];
        
        // Check if file was uploaded
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = "{$fieldName} is required.";
            return [false, null, $errors];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading {$fieldName}. Error code: " . $file['error'];
            return [false, null, $errors];
        }
        
        // Check file size
        if ($file['size'] > MAX_FILE_SIZE) {
            $errors[] = "{$fieldName} is too large. Maximum size is 5MB.";
        }
        
        // Check file type
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, ALLOWED_FILE_TYPES)) {
            $errors[] = "{$fieldName} must be JPG, JPEG, PNG, or PDF.";
        }
        
        if (!empty($errors)) {
            return [false, null, $errors];
        }
        
        // Generate unique filename
        $fileName = uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file['name']);
        
        return [true, $fileName, $errors];
    }
    
    private function uploadFile($file, $fileName) {
        $destination = $this->uploadDir . $fileName;
        
        debug_log("Attempting to upload file", [
            'source' => $file['tmp_name'],
            'destination' => $destination,
            'file_size' => $file['size']
        ]);
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            debug_log("File uploaded successfully", $fileName);
            return $fileName;
        } else {
            debug_log("File upload failed", [
                'error' => error_get_last(),
                'is_uploaded' => is_uploaded_file($file['tmp_name']),
                'temp_exists' => file_exists($file['tmp_name'])
            ]);
            return false;
        }
    }
    
    private function sendEmail($investorEmail, $applicationId) {
        $subject = "Serendib Green Plantation Application Submitted";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .header { background: #0a2f1d; color: white; padding: 20px; }
                .content { padding: 20px; }
                .footer { background: #f4f4f4; padding: 10px; text-align: center; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>Serendib Green Plantation</h2>
            </div>
            <div class='content'>
                <h3>Thank you for your investment application!</h3>
                <p>Your application has been successfully submitted.</p>
                <p><strong>Application ID:</strong> SGP-" . str_pad($applicationId, 6, '0', STR_PAD_LEFT) . "</p>
                <p>We will review your application and contact you within 3-5 business days.</p>
                <p>You can also check your application status by visiting our portal.</p>
            </div>
            <div class='footer'>
                <p>© " . date('Y') . " Serendib Green Plantaion. All rights reserved.</p>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . ADMIN_EMAIL . "\r\n";
        $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        return mail($investorEmail, $subject, $message, $headers);
    }
    
    public function processForm($data, $files) {
        debug_log("Processing form data", $data);
        
        try {
            $this->db->beginTransaction();

            // Ensure declarationDate exists even if the form omits it
            if (empty($data['declarationDate'])) {
                $data['declarationDate'] = date('Y-m-d');
            }
            
            // Validate required fields
            $requiredFields = [
                'fullName', 'nameWithInitials', 'nicNo', 'telNo', 'dob', 'email',
                'address', 'occupation', 'employerName', 'years', 'investmentType', 'signingDate',
                'accountNo', 'bank', 'branch', 'declarationDate'
            ];

            // Add validation for investment type
            $validInvestmentTypes = [
                'green_bonds', 'sustainable_etf', 'renewable_energy', 
                'esg_funds', 'carbon_credits', 'green_real_estate',
                'sustainable_agriculture', 'water_management', 'other'
            ];

            if (!in_array($data['investmentType'], $validInvestmentTypes)) {
                throw new Exception("Please select a valid investment type.");
            }
            
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Please fill in all required fields. Missing: " . $field);
                }
            }
            
            // Validate declaration
            if (!isset($data['declaration']) || $data['declaration'] !== 'accepted') {
                throw new Exception("You must accept the declaration.");
            }
            
            // Validate beneficiaries
            if (!isset($data['beneficiaryName']) || !is_array($data['beneficiaryName'])) {
                throw new Exception("At least one beneficiary is required.");
            }
            
            // Calculate total percentage
            $totalPercentage = 0;
            if (isset($data['beneficiaryPercentage'])) {
                foreach ($data['beneficiaryPercentage'] as $percentage) {
                    $totalPercentage += intval($percentage);
                }
            }
            
            if ($totalPercentage !== 100) {
                throw new Exception("Total beneficiary percentage must equal 100%. Current total: {$totalPercentage}%");
            }
            
            // Handle file uploads
            $uploadedFiles = [];
            $fileFields = [
                'investorId', 'beneficiaryId', 
                'passbook', 'paymentSlip',
                'proofDocuments', 'otherDocuments'
            ];
            
            foreach ($fileFields as $field) {
                if (isset($files[$field]) && $files[$field]['error'] === UPLOAD_ERR_OK) {
                    list($valid, $fileName, $errors) = $this->validateFile($files[$field], $field);
                    
                    if (!$valid) {
                        throw new Exception(implode(" ", $errors));
                    }
                    
                    $uploadedFileName = $this->uploadFile($files[$field], $fileName);
                    if (!$uploadedFileName) {
                        throw new Exception("Failed to upload {$field}. Please try again.");
                    }
                    
                    $uploadedFiles[$field] = $uploadedFileName;
                } else {
                    throw new Exception("{$field} is required. Please upload the file.");
                }
            }
            
            // Insert investor data
            $stmt = $this->db->prepare("
                INSERT INTO investors (
                    full_name, name_with_initials, nic_no, tel_no, dob, email,
                    address, occupation, employer_name, years, investment_type,
                    investment_amount, currency, signing_date,
                    account_no, bank_name, branch_name, declaration_date,
                    investor_id_doc, beneficiary_id_doc,
                    passbook_doc, payment_slip_doc,
                    proof_documents, other_documents
                ) VALUES (
                    :full_name, :name_with_initials, :nic_no, :tel_no, :dob, :email,
                    :address, :occupation, :employer_name, :years, :investment_type,
                    :investment_amount, :currency, :signing_date,
                    :account_no, :bank_name, :branch_name, :declaration_date,
                    :investor_id_doc, :beneficiary_id_doc,
                    :passbook_doc, :payment_slip_doc,
                    :proof_documents, :other_documents
                )
            ");
            
            $stmt->execute([
                ':full_name' => $data['fullName'],
                ':name_with_initials' => $data['nameWithInitials'],
                ':nic_no' => $data['nicNo'],
                ':tel_no' => $data['telNo'],
                ':dob' => $data['dob'],
                ':email' => $data['email'],
                ':address' => $data['address'],
                ':occupation' => $data['occupation'],
                ':employer_name' => $data['employerName'],
                ':years' => $data['years'],
                ':investment_type' => $data['investmentType'],
                ':investment_amount' => isset($data['investmentAmount']) && $data['investmentAmount'] !== '' ? $data['investmentAmount'] : null,
                ':currency' => 'LKR',
                ':signing_date' => $data['signingDate'],
                ':account_no' => $data['accountNo'],
                ':bank_name' => $data['bank'],
                ':branch_name' => $data['branch'],
                ':declaration_date' => $data['declarationDate'],
                ':investor_id_doc' => $uploadedFiles['investorId'],
                ':beneficiary_id_doc' => $uploadedFiles['beneficiaryId'],
                ':passbook_doc' => $uploadedFiles['passbook'],
                ':payment_slip_doc' => $uploadedFiles['paymentSlip'],
                ':proof_documents' => $uploadedFiles['proofDocuments'],
                ':other_documents' => $uploadedFiles['otherDocuments']
            ]);
            
            $investorId = $this->db->lastInsertId();
            
            debug_log("Investor inserted successfully", ['id' => $investorId]);
            
            // Insert beneficiaries
            $beneficiaryStmt = $this->db->prepare("
                INSERT INTO beneficiaries (investor_id, beneficiary_name, beneficiary_nic, percentage)
                VALUES (:investor_id, :beneficiary_name, :beneficiary_nic, :percentage)
            ");
            
            $beneficiaryCount = count($data['beneficiaryName']);
            for ($i = 0; $i < $beneficiaryCount; $i++) {
                $beneficiaryStmt->execute([
                    ':investor_id' => $investorId,
                    ':beneficiary_name' => $data['beneficiaryName'][$i],
                    ':beneficiary_nic' => $data['beneficiaryNIC'][$i],
                    ':percentage' => $data['beneficiaryPercentage'][$i]
                ]);
                
                debug_log("Beneficiary inserted", [
                    'investor_id' => $investorId,
                    'name' => $data['beneficiaryName'][$i]
                ]);
            }
            
            // Log the application
            $logStmt = $this->db->prepare("
                INSERT INTO application_logs (investor_id, action, description)
                VALUES (:investor_id, :action, :description)
            ");
            
            $logStmt->execute([
                ':investor_id' => $investorId,
                ':action' => 'submitted',
                ':description' => 'Application submitted successfully'
            ]);
            
            $this->db->commit();
            
            debug_log("Transaction committed successfully", ['application_id' => $investorId]);
            
            // Send email notification
            $emailSent = $this->sendEmail($data['email'], $investorId);
            debug_log("Email sending attempt", ['sent' => $emailSent, 'to' => $data['email']]);
            
            return [
                'success' => true,
                'message' => 'Application submitted successfully!',
                'application_id' => $investorId
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            
            // Clean up uploaded files on error
            foreach ($uploadedFiles as $file) {
                $filePath = $this->uploadDir . $file;
                if (file_exists($filePath)) {
                    unlink($filePath);
                    debug_log("Cleaned up file on error", $filePath);
                }
            }
            
            debug_log("Form processing error", $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

// Process the form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("POST request received");
    
    $processor = new FormProcessor();
    $result = $processor->processForm($_POST, $_FILES);
    
    if ($result['success']) {
        // Store application ID and email in session
        $_SESSION['application_id'] = $result['application_id'];
        $_SESSION['user_email'] = $_POST['email'];
        
        // Clear any previous form data
        unset($_SESSION['form_data']);
        unset($_SESSION['error']);
        
        debug_log("Redirecting to submit.php", ['application_id' => $result['application_id']]);
        
        header('Location: submit.php');
        exit();
    } else {
        $_SESSION['error'] = $result['message'];
        // Store form data in session for repopulation
        $_SESSION['form_data'] = $_POST;
        
        // Store file names for display
        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $_SESSION['form_data'][$key . '_name'] = $file['name'];
            }
        }
        
        debug_log("Redirecting back to index with error", ['error' => $result['message']]);
        
        header('Location: index.php');
        exit();
    }
} else {
    debug_log("Invalid request method", $_SERVER['REQUEST_METHOD']);
    $_SESSION['error'] = "Invalid request method.";
    header('Location: index.php');
    exit();
}

?>