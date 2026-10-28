<?php
session_start();
require_once 'config.php';
require_once 'db.php';
require_once 'includes/application_helpers.php';
require_once 'includes/approval_email.php';
require_once 'includes/investor_auth.php';



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
        
        if (in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ((int)$file['error'] === UPLOAD_ERR_OK && ($file['size'] ?? 0) > MAX_FILE_SIZE)
        ) {
            $errors[] = 'Invalid file attachment';
            return [false, null, $errors];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading {$fieldName}. Error code: " . $file['error'];
            return [false, null, $errors];
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
            
            // Normalize text fields to block letters
            $uppercaseFields = [
                'fullName', 'nameWithInitials', 'nicNo', 'address',
                'occupation', 'employerName', 'bank', 'branch'
            ];
            foreach ($uppercaseFields as $field) {
                if (isset($data[$field])) {
                    $data[$field] = strtoupper(trim($data[$field]));
                }
            }
            if (isset($data['beneficiaryName']) && is_array($data['beneficiaryName'])) {
                foreach ($data['beneficiaryName'] as $i => $name) {
                    $data['beneficiaryName'][$i] = strtoupper(trim($name));
                }
            }
            if (isset($data['beneficiaryNIC']) && is_array($data['beneficiaryNIC'])) {
                foreach ($data['beneficiaryNIC'] as $i => $nic) {
                    $data['beneficiaryNIC'][$i] = strtoupper(trim($nic));
                }
            }

            $data['email'] = trim($data['email'] ?? '');
            if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address.");
            }

            $data['employerName'] = trim($data['employerName'] ?? '');
            $data['nameWithInitials'] = trim($data['nameWithInitials'] ?? '');
            $applicantType = (isset($data['applicantType']) && $data['applicantType'] === 'company')
                ? 'company'
                : 'person';
            $data['applicantType'] = $applicantType;

            // Validate required fields
            $requiredFields = [
                'fullName', 'nicNo', 'telNo', 'dob',
                'address', 'occupation', 'years', 'investmentType', 'signingDate',
                'accountNo', 'bank', 'branch', 'declarationDate', 'salesPersonCode'
            ];
            if ($applicantType === 'person') {
                array_splice($requiredFields, 1, 0, 'nameWithInitials');
            }

            // Add validation for investment plan type codes
            $validInvestmentTypes = [
                'HPP', 'GSP', 'GSI', 'GOLD', 'SFPS', 'SFHPS'
            ];

            if (!in_array($data['investmentType'], $validInvestmentTypes)) {
                throw new Exception("Please select a valid investment plan.");
            }

            $data['gspPlan'] = trim($data['gspPlan'] ?? '');
            $data['gspPaymentOption'] = trim($data['gspPaymentOption'] ?? '');
            $data['maturityAmount'] = null;

            if ($data['investmentType'] === 'GSP') {
                $validGspPlans = ['plan1', 'plan2', 'plan3'];
                if (!in_array($data['gspPlan'], $validGspPlans, true)) {
                    throw new Exception("Please select a GSP plan (Plan 1, Plan 2, or Plan 3).");
                }
                if ($data['gspPlan'] === 'plan1') {
                    $validGspPayments = ['one_time', '5250', '6120', '4600', '3700'];
                    if (!in_array($data['gspPaymentOption'], $validGspPayments, true)) {
                        throw new Exception("Please select a valid GSP payment option.");
                    }
                    // Plan 01 one-time payment: investment 200,000 → maturity 1,000,000
                    if ($data['gspPaymentOption'] === 'one_time') {
                        $data['investmentAmount'] = '200000';
                        $data['maturityAmount'] = '1000000';
                    }
                } else {
                    $data['gspPaymentOption'] = '';
                }
            } else {
                $data['gspPlan'] = '';
                $data['gspPaymentOption'] = '';
            }
            
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Please fill in all required fields. Missing: " . $field);
                }
            }
            
            // Validate sales person code
            $salesPersonCode = strtoupper(trim($data['salesPersonCode']));
            $salesPersonStmt = $this->db->prepare("
                SELECT id FROM sales_persons
                WHERE sales_code = ? AND status = 'active'
                LIMIT 1
            ");
            $salesPersonStmt->execute([$salesPersonCode]);
            if (!$salesPersonStmt->fetch()) {
                throw new Exception("Invalid sales person code. Please check the code and try again.");
            }
            $data['salesPersonCode'] = $salesPersonCode;

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
            $requiredFileFields = [
                'investorId', 'beneficiaryId',
                'passbook', 'paymentSlip',
                'otherDocuments'
            ];
            $optionalFileFields = ['proofDocuments'];

            foreach ($requiredFileFields as $field) {
                $fileError = isset($files[$field]) ? (int)$files[$field]['error'] : UPLOAD_ERR_NO_FILE;
                if (in_array($fileError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    || ($fileError === UPLOAD_ERR_OK && ($files[$field]['size'] ?? 0) > MAX_FILE_SIZE)
                ) {
                    throw new Exception('Invalid file attachment');
                }

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

            foreach ($optionalFileFields as $field) {
                if (!isset($files[$field]) || (int)$files[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                    $uploadedFiles[$field] = null;
                    continue;
                }

                $fileError = (int)$files[$field]['error'];
                if (in_array($fileError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    || ($fileError === UPLOAD_ERR_OK && ($files[$field]['size'] ?? 0) > MAX_FILE_SIZE)
                ) {
                    throw new Exception('Invalid file attachment');
                }

                if ($fileError === UPLOAD_ERR_OK) {
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
                    $uploadedFiles[$field] = null;
                }
            }
            
            // Insert investor data
            $stmt = $this->db->prepare("
                INSERT INTO investors (
                    full_name, name_with_initials, nic_no, tel_no, dob, email,
                    address, occupation, employer_name, years, investment_type,
                    investment_amount, currency, signing_date,
                    account_no, bank_name, branch_name, declaration_date,
                    sales_person_code, applicant_type,
                    gsp_plan, gsp_payment_option, maturity_amount,
                    investor_id_doc, beneficiary_id_doc,
                    passbook_doc, payment_slip_doc,
                    proof_documents, other_documents
                ) VALUES (
                    :full_name, :name_with_initials, :nic_no, :tel_no, :dob, :email,
                    :address, :occupation, :employer_name, :years, :investment_type,
                    :investment_amount, :currency, :signing_date,
                    :account_no, :bank_name, :branch_name, :declaration_date,
                    :sales_person_code, :applicant_type,
                    :gsp_plan, :gsp_payment_option, :maturity_amount,
                    :investor_id_doc, :beneficiary_id_doc,
                    :passbook_doc, :payment_slip_doc,
                    :proof_documents, :other_documents
                )
            ");
            
            $stmt->execute([
                ':full_name' => $data['fullName'],
                ':name_with_initials' => $data['nameWithInitials'] !== '' ? $data['nameWithInitials'] : '',
                ':nic_no' => $data['nicNo'],
                ':tel_no' => $data['telNo'],
                ':dob' => $data['dob'],
                ':email' => $data['email'] !== '' ? $data['email'] : '',
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
                ':sales_person_code' => $data['salesPersonCode'],
                ':applicant_type' => $data['applicantType'],
                ':gsp_plan' => $data['gspPlan'] !== '' ? $data['gspPlan'] : null,
                ':gsp_payment_option' => $data['gspPaymentOption'] !== '' ? $data['gspPaymentOption'] : null,
                ':maturity_amount' => $data['maturityAmount'] !== null && $data['maturityAmount'] !== '' ? $data['maturityAmount'] : null,
                ':investor_id_doc' => $uploadedFiles['investorId'],
                ':beneficiary_id_doc' => $uploadedFiles['beneficiaryId'],
                ':passbook_doc' => $uploadedFiles['passbook'],
                ':payment_slip_doc' => $uploadedFiles['paymentSlip'],
                ':proof_documents' => $uploadedFiles['proofDocuments'],
                ':other_documents' => $uploadedFiles['otherDocuments']
            ]);
            
            $investorId = $this->db->lastInsertId();
            
            debug_log("Investor inserted successfully", ['id' => $investorId]);

            // If this NIC already has a portal password, reuse it on the new proposal
            syncPortalPasswordToInvestor($this->db, (int) $investorId, (string) $data['nicNo']);
            
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
            
            // Send email notification when an address was provided
            if ($data['email'] !== '') {
                $emailSent = $this->sendEmail($data['email'], $investorId);
                debug_log("Email sending attempt", ['sent' => $emailSent, 'to' => $data['email']]);
            }

            // Notify first approver (must not block successful submission)
            try {
                $approverNotified = sendApproverLevelEmail($this->db, (int) $investorId, 1, 'new_application');
                debug_log('First approver notification', array(
                    'sent_count' => $approverNotified,
                    'application_id' => $investorId,
                ));
            } catch (Exception $e) {
                error_log('First approver notification failed: ' . $e->getMessage());
                debug_log('First approver notification failed', $e->getMessage());
            }
            
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
        if (stripos($result['message'], 'sales person code') !== false) {
            $_SESSION['field_errors']['salesPersonCode'] = $result['message'];
        }
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