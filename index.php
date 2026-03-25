<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Serendib Green Plantation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="floating-elements">
        <div class="floating-element" style="top: 10%; left: 5%; animation-delay: 0s;"></div>
        <div class="floating-element" style="top: 20%; left: 90%; animation-delay: 1s;"></div>
        <div class="floating-element" style="top: 50%; left: 15%; animation-delay: 2s;"></div>
        <div class="floating-element" style="top: 80%; left: 80%; animation-delay: 3s;"></div>
        <div class="floating-element" style="top: 30%; left: 70%; animation-delay: 4s;"></div>
        
        <div class="leaf" style="top: 15%; left: 20%; animation-delay: 0.5s;"></div>
        <div class="leaf" style="top: 60%; left: 85%; animation-delay: 2.5s;"></div>
        <div class="leaf" style="top: 85%; left: 40%; animation-delay: 4.5s;"></div>
    </div>
    
    <div class="container">
        <header>
            <div class="logo">Serendib Green Plantaion</div>
            <h1>Green Investor Portal</h1>
            <p class="subtitle">Sustainable investing for a brighter future</p>
            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
        </header>
        
        <div class="progress-container">
            <div class="progress-bar">
                <div class="progress" id="form-progress"></div>
            </div>
            <div class="step-indicators">
                <div class="step active" id="step-indicator-1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Personal</div>
                </div>
                <div class="step" id="step-indicator-2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Profession</div>
                </div>
                <div class="step" id="step-indicator-3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Beneficiaries</div>
                </div>
                <div class="step" id="step-indicator-4">
                    <div class="step-circle">4</div>
                    <div class="step-label">Payment</div>
                </div>
                <div class="step" id="step-indicator-5">
                    <div class="step-circle">5</div>
                    <div class="step-label">Bank Details</div>
                </div>
                <div class="step" id="step-indicator-6">
                    <div class="step-circle">6</div>
                    <div class="step-label">Declaration</div>
                </div>
            </div>
        </div>
        
        <div class="form-card">
            <div class="form-container">
                <form id="investor-form" action="process_form.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" id="declarationDate" name="declarationDate" value="">
                    <!-- Step 1: Personal Details -->
                    <div class="form-step active" id="step1">
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="fas fa-user-astronaut"></i>
                                </div>
                                <div class="section-title">
                                    <span class="section-number">1</span>
                                    Personal Details
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="fullName" class="required">Full Name (Block Letters)</label>
                                    <div class="input-container">
                                         <input type="text" id="fullName" name="fullName" required 
               value="<?php echo isset($_SESSION['form_data']['fullName']) ? htmlspecialchars($_SESSION['form_data']['fullName']) : ''; ?>"
               oninput="this.value = this.value.toUpperCase()"
               onkeypress="return /[A-Z\s]/i.test(event.key)"
               pattern="[A-Z\s]+"
               title="Please use only uppercase letters and spaces">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="nameWithInitials" class="required">Name with Initials</label>
                                    <div class="input-container">
                                       <input type="text" id="nameWithInitials" name="nameWithInitials" required 
               value="<?php echo isset($_SESSION['form_data']['nameWithInitials']) ? htmlspecialchars($_SESSION['form_data']['nameWithInitials']) : ''; ?>"
               oninput="this.value = this.value.toUpperCase()"
               onkeypress="return /[A-Z\s]/i.test(event.key)"
               pattern="[A-Z\s]+"
               title="Please use only uppercase letters and spaces">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="nicNo" class="required">NIC No</label>
                                    <div class="input-container">
                                        <input type="text" id="nicNo" name="nicNo" required
                                               value="<?php echo isset($_SESSION['form_data']['nicNo']) ? htmlspecialchars($_SESSION['form_data']['nicNo']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="telNo" class="required">Tel No</label>
                                    <div class="input-container">
                                        <input type="tel" id="telNo" name="telNo" required
                                               value="<?php echo isset($_SESSION['form_data']['telNo']) ? htmlspecialchars($_SESSION['form_data']['telNo']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="dob" class="required">Date of Birth</label>
                                    <div class="input-container">
                                        <input type="date" id="dob" name="dob" required
                                               value="<?php echo isset($_SESSION['form_data']['dob']) ? htmlspecialchars($_SESSION['form_data']['dob']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="email" class="required">E-mail</label>
                                    <div class="input-container">
                                        <input type="email" id="email" name="email" required
                                               value="<?php echo isset($_SESSION['form_data']['email']) ? htmlspecialchars($_SESSION['form_data']['email']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label for="address" class="required">Address</label>
                                    <div class="input-container">
                                        <textarea id="address" name="address" rows="3" required><?php echo isset($_SESSION['form_data']['address']) ? htmlspecialchars($_SESSION['form_data']['address']) : ''; ?></textarea>
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-nav">
                            <div></div>
                            <button type="button" class="nav-btn next-btn" onclick="nextStep(1)">
                                Next <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Profession of Investor -->
                    <div class="form-step" id="step2">
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="fas fa-briefcase"></i>
                                </div>
                                <div class="section-title">
                                    <span class="section-number">2</span>
                                    Profession of Investor
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="occupation" class="required">Occupation</label>
                                    <div class="input-container">
                                        <input type="text" id="occupation" name="occupation" required
                                               value="<?php echo isset($_SESSION['form_data']['occupation']) ? htmlspecialchars($_SESSION['form_data']['occupation']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="employerName" class="required">Name of Employer</label>
                                    <div class="input-container">
                                        <input type="text" id="employerName" name="employerName" required
                                               value="<?php echo isset($_SESSION['form_data']['employerName']) ? htmlspecialchars($_SESSION['form_data']['employerName']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-nav">
                            <button type="button" class="nav-btn" onclick="prevStep(2)">
                                <i class="fas fa-arrow-left"></i> Previous
                            </button>
                            <button type="button" class="nav-btn next-btn" onclick="nextStep(2)">
                                Next <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Beneficiaries -->
                    <div class="form-step" id="step3">
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="section-title">
                                    <span class="section-number">3</span>
                                    Details of the Intended Beneficiaries
                                </div>
                            </div>
                            <div id="beneficiaries-container">
                                <?php
                                $beneficiaryCount = isset($_SESSION['form_data']['beneficiaryName']) ? count($_SESSION['form_data']['beneficiaryName']) : 1;
                                for($i = 0; $i < $beneficiaryCount; $i++):
                                ?>
                                <div class="beneficiary-item">
                                    <?php if($i > 0): ?>
                                    <button type="button" class="remove-beneficiary" onclick="removeBeneficiary(this)">
                                        <i class="fas fa-times"></i> Remove
                                    </button>
                                    <?php endif; ?>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="required">Beneficiary Name</label>
                                            <div class="input-container">
                                                <input type="text" name="beneficiaryName[]" required
                                                       value="<?php echo isset($_SESSION['form_data']['beneficiaryName'][$i]) ? htmlspecialchars($_SESSION['form_data']['beneficiaryName'][$i]) : ''; ?>">
                                                <div class="input-highlight"></div>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="required">NIC No</label>
                                            <div class="input-container">
                                                <input type="text" name="beneficiaryNIC[]" required
                                                       value="<?php echo isset($_SESSION['form_data']['beneficiaryNIC'][$i]) ? htmlspecialchars($_SESSION['form_data']['beneficiaryNIC'][$i]) : ''; ?>">
                                                <div class="input-highlight"></div>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="required">Percentage</label>
                                            <div class="input-container">
                                                <input type="number" name="beneficiaryPercentage[]" min="1" max="100" required
                                                       value="<?php echo isset($_SESSION['form_data']['beneficiaryPercentage'][$i]) ? htmlspecialchars($_SESSION['form_data']['beneficiaryPercentage'][$i]) : ''; ?>">
                                                <div class="input-highlight"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <button type="button" class="add-beneficiary" onclick="addBeneficiary()">
                                <i class="fas fa-plus"></i> Add Beneficiary
                            </button>
                        </div>
                        
                        <div class="form-nav">
                            <button type="button" class="nav-btn" onclick="prevStep(3)">
                                <i class="fas fa-arrow-left"></i> Previous
                            </button>
                            <button type="button" class="nav-btn next-btn" onclick="nextStep(3)">
                                Next <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 4: Initial Payment -->
                    <div class="form-step" id="step4">
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="section-title">
                                    <span class="section-number">4</span>
                                    Initial Payment
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="years" class="required">Years</label>
                                    <div class="input-container">
                                        <input type="number" id="years" name="years" min="1" required
                                               value="<?php echo isset($_SESSION['form_data']['years']) ? htmlspecialchars($_SESSION['form_data']['years']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>


<div class="form-group">
                <label for="investmentAmount" class="required">Investment Amount (Rs.)</label>
                <div class="input-container">
                    <input type="number" id="investmentAmount" name="investmentAmount" 
            
                           value="<?php echo isset($_SESSION['form_data']['investmentAmount']) ? htmlspecialchars($_SESSION['form_data']['investmentAmount']) : ''; ?>"
                           placeholder="e.g., 10000">
                    <div class="input-highlight"></div>
                </div>
            </div>



  <div class="form-group">
                <label for="investmentType" class="required">Investment Plan</label>
                <div class="input-container">
                    <select id="investmentType" name="investmentType" required>
                        <option value="">Select Investment Plan</option>
                        <option value="HPP" <?php echo isset($_SESSION['form_data']['investmentType']) && $_SESSION['form_data']['investmentType'] == 'HPP' ? 'selected' : ''; ?>>High profit plan</option>
                        <option value="GSP" <?php echo isset($_SESSION['form_data']['investmentType']) && $_SESSION['form_data']['investmentType'] == 'GSP' ? 'selected' : ''; ?>>Green saving plan</option>
                        <option value="GSI" <?php echo isset($_SESSION['form_data']['investmentType']) && $_SESSION['form_data']['investmentType'] == 'GSI' ? 'selected' : ''; ?>>Green silver plan</option>
                        <option value="GOLD" <?php echo isset($_SESSION['form_data']['investmentType']) && $_SESSION['form_data']['investmentType'] == 'GOLD' ? 'selected' : ''; ?>>Gold plan</option>
                        <option value="SFPS" <?php echo isset($_SESSION['form_data']['investmentType']) && $_SESSION['form_data']['investmentType'] == 'SFPS' ? 'selected' : ''; ?>>Seraa farm profit share plan</option>
                        <option value="SFHPS" <?php echo isset($_SESSION['form_data']['investmentType']) && $_SESSION['form_data']['investmentType'] == 'SFHPS' ? 'selected' : ''; ?>>Seraa farm high profit share plan</option>
                    </select>
                    <div class="input-highlight"></div>
                </div>
            </div>


            












                                <div class="form-group">
                                    <label for="signingDate" class="required">Signing Date</label>
                                    <div class="input-container">
                                        <input type="date" id="signingDate" name="signingDate" required
                                               value="<?php echo isset($_SESSION['form_data']['signingDate']) ? htmlspecialchars($_SESSION['form_data']['signingDate']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                               
                            </div>
                        </div>
                        
                        <div class="form-nav">
                            <button type="button" class="nav-btn" onclick="prevStep(4)">
                                <i class="fas fa-arrow-left"></i> Previous
                            </button>
                            <button type="button" class="nav-btn next-btn" onclick="nextStep(4)">
                                Next <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 5: Bank Details -->
                    <div class="form-step" id="step5">
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="section-title">
                                    <span class="section-number">5</span>
                                    Details of the Bank Account
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="accountNo" class="required">Account No</label>
                                    <div class="input-container">
                                        <input type="text" id="accountNo" name="accountNo" required
                                               value="<?php echo isset($_SESSION['form_data']['accountNo']) ? htmlspecialchars($_SESSION['form_data']['accountNo']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank" class="required">Bank</label>
                                    <div class="input-container">
                                        <input type="text" id="bank" name="bank" required
                                               value="<?php echo isset($_SESSION['form_data']['bank']) ? htmlspecialchars($_SESSION['form_data']['bank']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="branch" class="required">Branch</label>
                                    <div class="input-container">
                                        <input type="text" id="branch" name="branch" required
                                               value="<?php echo isset($_SESSION['form_data']['branch']) ? htmlspecialchars($_SESSION['form_data']['branch']) : ''; ?>">
                                        <div class="input-highlight"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="fas fa-file-upload"></i>
                                </div>
                                <div class="section-title">
                                    Upload Required Documents
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="required">Investor ID (Both Sides)</label>
                                    <div class="file-upload" onclick="document.getElementById('investorId').click()">
                                        <i class="fas fa-id-card"></i>
                                        <p>Upload Investor ID</p>
                                        <p class="file-info" id="investorIdInfo">
                                            <?php if(isset($_SESSION['form_data']['investorId_name'])): ?>
                                                Selected: <?php echo htmlspecialchars($_SESSION['form_data']['investorId_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <input type="file" id="investorId" class="file-input" name="investorId" accept="image/*,.pdf" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="required">Beneficiary ID (Both Sides)</label>
                                    <div class="file-upload" onclick="document.getElementById('beneficiaryId').click()">
                                        <i class="fas fa-id-card"></i>
                                        <p>Upload Beneficiary ID</p>
                                        <p class="file-info" id="beneficiaryIdInfo">
                                            <?php if(isset($_SESSION['form_data']['beneficiaryId_name'])): ?>
                                                Selected: <?php echo htmlspecialchars($_SESSION['form_data']['beneficiaryId_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <input type="file" id="beneficiaryId" class="file-input" name="beneficiaryId" accept="image/*,.pdf" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="required">Passbook</label>
                                    <div class="file-upload" onclick="document.getElementById('passbook').click()">
                                        <i class="fas fa-book"></i>
                                        <p>Upload Passbook</p>
                                        <p class="file-info" id="passbookInfo">
                                            <?php if(isset($_SESSION['form_data']['passbook_name'])): ?>
                                                Selected: <?php echo htmlspecialchars($_SESSION['form_data']['passbook_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <input type="file" id="passbook" class="file-input" name="passbook" accept="image/*,.pdf" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="required">Payment Slip</label>
                                    <div class="file-upload" onclick="document.getElementById('paymentSlip').click()">
                                        <i class="fas fa-receipt"></i>
                                        <p>Upload Payment Slip</p>
                                        <p class="file-info" id="paymentSlipInfo">
                                            <?php if(isset($_SESSION['form_data']['paymentSlip_name'])): ?>
                                                Selected: <?php echo htmlspecialchars($_SESSION['form_data']['paymentSlip_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <input type="file" id="paymentSlip" class="file-input" name="paymentSlip" accept="image/*,.pdf" required>
                                    </div>
                                </div>
                                 <div class="form-group">
    <label class="required">Proof Documents</label>
    <div class="file-upload" onclick="document.getElementById('proofDocuments').click()">
        <i class="fas fa-receipt"></i>
        <p>Upload Proof Documents</p>
        <p class="file-info" id="proofDocumentsInfo">
            <?php if(isset($_SESSION['form_data']['proofDocuments_name'])): ?>
                Selected: <?php echo htmlspecialchars($_SESSION['form_data']['proofDocuments_name']); ?>
            <?php endif; ?>
        </p>
        <input type="file" id="proofDocuments" class="file-input" name="proofDocuments" accept="image/*,.pdf" required>
    </div>
</div>
                                <div class="form-group">
    <label class="required">Other Documents</label>
    <div class="file-upload" onclick="document.getElementById('otherDocuments').click()">
        <i class="fas fa-receipt"></i>
        <p>Upload Other Documents</p>
        <p class="file-info" id="otherDocumentsInfo">
            <?php if(isset($_SESSION['form_data']['otherDocuments_name'])): ?>
                Selected: <?php echo htmlspecialchars($_SESSION['form_data']['otherDocuments_name']); ?>
            <?php endif; ?>
        </p>
        <input type="file" id="otherDocuments" class="file-input" name="otherDocuments" accept="image/*,.pdf" required>
    </div>
</div>
                            </div>
                        </div>
                        
                        <div class="form-nav">
                            <button type="button" class="nav-btn" onclick="prevStep(5)">
                                <i class="fas fa-arrow-left"></i> Previous
                            </button>
                            <button type="button" class="nav-btn next-btn" onclick="nextStep(5)">
                                Next <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 6: Declaration -->
                   <div class="form-step" id="step6">
    <div class="form-section">
        <div class="section-header">
            <div class="section-icon">
                <i class="fas fa-file-signature"></i>
            </div>
            <div class="section-title">
                <span class="section-number">6</span>
                Declaration
            </div>
        </div>
        <div class="form-grid">
            <div class="form-group" style="grid-column: 1 / -1;">
                <div class="checkbox-group">
                    <input type="checkbox" id="declaration" name="declaration" value="accepted" required>
                    <label for="declaration" class="required">
                        I hereby affirm that the aforementioned information is accurate and complete to the best of my knowledge
                    </label>
                </div>
            </div>
            
         
        </div>
    </div>
    
    <div class="form-nav">
        <button type="button" class="nav-btn" onclick="prevStep(6)">
            <i class="fas fa-arrow-left"></i> Previous
        </button>
        <button type="submit" class="submit-btn">
            <i class="fas fa-paper-plane"></i> Submit Application
        </button>
    </div>
</div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/script.js?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/js/script.js')); ?>"></script>
</body>
</html>
<?php
// Clear form data from session after displaying
if(isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>