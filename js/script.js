let currentStep = 1;
const totalSteps = 6;

function setApplicantType(type) {
    const isCompany = type === 'company';
    const applicantTypeInput = document.getElementById('applicantType');
    const personBtn = document.getElementById('applicantTypePerson');
    const companyBtn = document.getElementById('applicantTypeCompany');
    const fullNameLabel = document.getElementById('fullNameLabel');
    const nameWithInitialsLabel = document.getElementById('nameWithInitialsLabel');
    const nicNoLabel = document.getElementById('nicNoLabel');
    const dobLabel = document.getElementById('dobLabel');
    const fullNameInput = document.getElementById('fullName');
    const nameWithInitialsInput = document.getElementById('nameWithInitials');
    const sectionIcon = document.querySelector('#personalSectionIcon i');

    if (applicantTypeInput) {
        applicantTypeInput.value = isCompany ? 'company' : 'person';
    }

    if (personBtn) personBtn.classList.toggle('active', !isCompany);
    if (companyBtn) companyBtn.classList.toggle('active', isCompany);

    if (fullNameLabel) {
        fullNameLabel.textContent = isCompany ? 'Company Name' : 'Full Name (Block Letters)';
        fullNameLabel.classList.add('required');
    }

    if (fullNameInput) {
        if (isCompany) {
            fullNameInput.removeAttribute('pattern');
            fullNameInput.removeAttribute('onkeypress');
            fullNameInput.title = 'Enter the company name';
        } else {
            fullNameInput.setAttribute('pattern', '[A-Z\\s]+');
            fullNameInput.setAttribute('onkeypress', 'return /[A-Z\\s]/i.test(event.key)');
            fullNameInput.title = 'Please use only uppercase letters and spaces';
        }
    }

    if (nicNoLabel) {
        nicNoLabel.textContent = isCompany ? 'BR Number' : 'NIC No';
        nicNoLabel.classList.add('required');
    }

    if (dobLabel) {
        dobLabel.textContent = isCompany ? 'Company Started Date' : 'Date of Birth';
        dobLabel.classList.add('required');
    }

    if (nameWithInitialsLabel && nameWithInitialsInput) {
        if (isCompany) {
            nameWithInitialsLabel.textContent = 'Name with Initials (Optional)';
            nameWithInitialsLabel.classList.remove('required');
            nameWithInitialsInput.removeAttribute('required');
        } else {
            nameWithInitialsLabel.textContent = 'Name with Initials';
            nameWithInitialsLabel.classList.add('required');
            nameWithInitialsInput.setAttribute('required', 'required');
        }
    }

    if (sectionIcon) {
        sectionIcon.className = isCompany ? 'fas fa-building' : 'fas fa-user-astronaut';
    }
}

// Update progress bar and step indicators
function updateProgress() {
    const progress = (currentStep / totalSteps) * 100;
    const progressBar = document.getElementById('form-progress');
    if (progressBar) {
        progressBar.style.width = `${progress}%`;
    }
    
    // Update step indicators
    for (let i = 1; i <= totalSteps; i++) {
        const stepIndicator = document.getElementById(`step-indicator-${i}`);
        if (stepIndicator) {
            if (i <= currentStep) {
                stepIndicator.classList.add('active');
            } else {
                stepIndicator.classList.remove('active');
            }
        }
    }
}

// Navigate to next step
function nextStep(step) {
    if (validateStep(step)) {
        const currentStepEl = document.getElementById(`step${step}`);
        const nextStepEl = document.getElementById(`step${step + 1}`);
        
        if (currentStepEl && nextStepEl) {
            currentStepEl.classList.remove('active');
            currentStep = step + 1;
            nextStepEl.classList.add('active');
            updateProgress();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }
}

// Navigate to previous step
function prevStep(step) {
    const currentStepEl = document.getElementById(`step${step}`);
    const prevStepEl = document.getElementById(`step${step - 1}`);
    
    if (currentStepEl && prevStepEl) {
        currentStepEl.classList.remove('active');
        currentStep = step - 1;
        prevStepEl.classList.add('active');
        updateProgress();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Validate current step
function validateStep(step) {
    const currentStepElement = document.getElementById(`step${step}`);
    if (!currentStepElement) return false;
    
    const inputs = currentStepElement.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    
    for (let input of inputs) {
        if (input.type === 'checkbox') {
            if (!input.checked) {
                showValidationError('You must check the <strong>declaration checkbox</strong> to confirm that the information you provided is accurate and complete before submitting.');
                input.focus();
                return false;
            }
        } else if (input.type === 'file') {
            // File validation will be handled on submission
            continue;
        } else {
            if (!input.value.trim()) {
                // Find a human-readable label for the field
                let fieldLabel = '';
                const labelEl = document.querySelector(`label[for="${input.id}"]`);
                if (labelEl) {
                    fieldLabel = labelEl.textContent.replace(/\s*\*\s*$/, '').trim();
                } else if (input.placeholder) {
                    fieldLabel = input.placeholder;
                } else if (input.name) {
                    fieldLabel = input.name.replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').trim();
                }
                const stepNames = {1:'Personal',2:'Profession',3:'Beneficiaries',4:'Payment',5:'Bank Details',6:'Declaration'};
                const stepLabel = stepNames[step] ? ` (Step ${step}: ${stepNames[step]})` : '';
                const msg = fieldLabel
                    ? `<strong>"${fieldLabel}"</strong> is required${stepLabel}. Please fill in this field.`
                    : `Please fill in all required fields${stepLabel}.`;
                showValidationError(msg);
                input.focus();
                return false;
            }
            
            // Date validation for step 1 — allow today, reject only future dates
            if (step === 1 && input.type === 'date' && input.id === 'dob') {
                const dob = new Date(input.value + 'T00:00:00');
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                if (dob > today) {
                    const isCompany = isCompanyApplicant();
                    showValidationError(isCompany
                        ? 'Company started date cannot be in the future.'
                        : 'Date of birth cannot be in the future.');
                    input.focus();
                    return false;
                }
            }
        }
    }
    
    // Additional validation for specific steps
    if (step === 3) {
        const percentages = document.querySelectorAll('input[name="beneficiaryPercentage[]"]');
        let totalPercentage = 0;
        
        for (let percentage of percentages) {
            const value = parseInt(percentage.value) || 0;
            if (value < 1 || value > 100) {
                showValidationError('Each beneficiary percentage must be between 1% and 100%.');
                percentage.focus();
                return false;
            }
            totalPercentage += value;
        }
        
        if (totalPercentage !== 100) {
            showValidationError(`Total beneficiary percentage must equal 100%. Current total: ${totalPercentage}%`);
            return false;
        }
    }

    if (step === 5 && hasOversizedAttachment(currentStepElement)) {
        showValidationError('Invalid file attachment');
        return false;
    }
    
    return true;
}

function hasOversizedAttachment(scope) {
    const root = scope || document;
    const fileInputs = root.querySelectorAll('.file-input');
    for (const input of fileInputs) {
        if (!input.files || input.files.length === 0) {
            continue;
        }
        const maxFileSize = parseInt(input.dataset.maxSize, 10) || (3 * 1024 * 1024);
        if (input.files[0].size > maxFileSize) {
            return true;
        }
    }
    return false;
}

// Add beneficiary
function addBeneficiary() {
    const container = document.getElementById('beneficiaries-container');
    if (!container) return;
    
    const newBeneficiary = document.createElement('div');
    newBeneficiary.className = 'beneficiary-item';
    newBeneficiary.innerHTML = `
        <button type="button" class="remove-beneficiary" onclick="removeBeneficiary(this)">
            <i class="fas fa-times"></i> Remove
        </button>
        <div class="form-grid">
            <div class="form-group">
                <label class="required">Beneficiary Name</label>
                <div class="input-container">
                    <input type="text" name="beneficiaryName[]" required oninput="this.value = this.value.toUpperCase()">
                    <div class="input-highlight"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="required">NIC No</label>
                <div class="input-container">
                    <input type="text" name="beneficiaryNIC[]" required oninput="this.value = this.value.toUpperCase()">
                    <div class="input-highlight"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="required">Percentage</label>
                <div class="input-container">
                    <input type="number" name="beneficiaryPercentage[]" min="1" max="100" required>
                    <div class="input-highlight"></div>
                </div>
            </div>
        </div>
    `;
    container.appendChild(newBeneficiary);
}

// Remove beneficiary
function removeBeneficiary(button) {
    const beneficiaries = document.querySelectorAll('.beneficiary-item');
    if (beneficiaries.length > 1) {
        button.parentElement.remove();
    } else {
        showValidationError('At least one beneficiary is required.');
    }
}

// Show validation error
function showValidationError(message) {
    // Remove existing error messages
    const existingErrors = document.querySelectorAll('.validation-error');
    existingErrors.forEach(error => error.remove());

    // Create error message
    const errorDiv = document.createElement('div');
    errorDiv.className = 'alert error validation-error';
    errorDiv.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;min-width:320px;max-width:90vw;padding:14px 20px 14px 16px;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.35);display:flex;align-items:flex-start;gap:10px;animation:fadeInDown .3s ease;';
    errorDiv.innerHTML = `
        <i class="fas fa-exclamation-triangle" style="color:#ff4d4d;font-size:1.2em;margin-top:2px;flex-shrink:0;"></i>
        <span style="flex:1;line-height:1.5;">${message}</span>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:1.1em;color:inherit;margin-left:8px;opacity:0.7;padding:0;" title="Dismiss">&times;</button>
    `;

    document.body.appendChild(errorDiv);

    // Scroll error into view
    errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    // Auto-remove after 8 seconds
    const timer = setTimeout(() => {
        if (errorDiv && errorDiv.parentNode) {
            errorDiv.style.animation = 'fadeOutUp .3s ease forwards';
            setTimeout(() => errorDiv.remove(), 300);
        }
    }, 8000);

    // Cancel timer if manually dismissed
    errorDiv.querySelector('button').addEventListener('click', () => clearTimeout(timer));
}

function showFieldError(fieldId, message) {
    const errorEl = document.getElementById(`${fieldId}Error`);
    const input = document.getElementById(fieldId);

    if (errorEl) {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }

    if (input) {
        input.classList.add('input-error');
    }
}

function clearFieldError(fieldId) {
    const errorEl = document.getElementById(`${fieldId}Error`);
    const input = document.getElementById(fieldId);

    if (errorEl) {
        errorEl.textContent = '';
        errorEl.style.display = 'none';
    }

    if (input) {
        input.classList.remove('input-error');
    }
}

async function validateSalesPersonCode(code) {
    const trimmedCode = code.trim().toUpperCase();

    if (!trimmedCode) {
        showFieldError('salesPersonCode', 'Sales person code is required.');
        return false;
    }

    try {
        const response = await fetch(`validate_sales_code.php?code=${encodeURIComponent(trimmedCode)}`);
        const data = await response.json();

        if (data.valid) {
            clearFieldError('salesPersonCode');
            return true;
        }

        showFieldError('salesPersonCode', data.message || 'Invalid sales person code. Please check the code and try again.');
        return false;
    } catch (error) {
        showFieldError('salesPersonCode', 'Unable to validate sales code. Please try again.');
        return false;
    }
}

function goToStep(step) {
    document.querySelectorAll('.form-step').forEach(stepEl => {
        stepEl.classList.remove('active');
    });

    const targetStep = document.getElementById(`step${step}`);
    if (targetStep) {
        targetStep.classList.add('active');
    }

    currentStep = step;
    updateProgress();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// File upload handlers
document.addEventListener('DOMContentLoaded', function() {
    // Handle file input changes
    const fileInputs = document.querySelectorAll('.file-input');
    fileInputs.forEach(input => {
        input.addEventListener('change', function() {
            const fileInfoId = this.id + 'Info';
            const fileInfoElement = document.getElementById(fileInfoId);
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                const fileSize = this.files[0].size;
                const maxFileSize = parseInt(this.dataset.maxSize, 10) || (3 * 1024 * 1024);
                const fileSizeMb = (fileSize / 1024 / 1024).toFixed(2);
                
                // Validate file size (reject anything over the allowed MB limit)
                if (fileSize > maxFileSize) {
                    showValidationError('Invalid file attachment');
                    this.value = '';
                    if (fileInfoElement) {
                        fileInfoElement.textContent = 'Invalid file attachment';
                        fileInfoElement.style.color = 'var(--error)';
                    }
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['jpg', 'jpeg', 'png', 'pdf'];
                const fileExt = fileName.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(fileExt)) {
                    showValidationError('File must be JPG, JPEG, PNG, or PDF.');
                    this.value = '';
                    if (fileInfoElement) {
                        fileInfoElement.textContent = 'Invalid file type.';
                        fileInfoElement.style.color = 'var(--error)';
                    }
                    return;
                }
                
                if (fileInfoElement) {
                    fileInfoElement.textContent = `Selected: ${fileName} (${fileSizeMb} MB)`;
                    fileInfoElement.style.color = 'var(--success)';
                }
            } else {
                if (fileInfoElement) {
                    fileInfoElement.textContent = 'No file selected';
                    fileInfoElement.style.color = 'var(--text-muted)';
                }
            }
        });
    });
    
    // (finalSignature upload removed from form)
});

// Form submission - SIMPLIFIED VERSION
document.getElementById('investor-form').addEventListener('submit', async function(e) {
    if (this.dataset.salesCodeValidated === '1') {
        delete this.dataset.salesCodeValidated;
        return true;
    }

    e.preventDefault();

    const submitBtn = document.querySelector('.submit-btn');
    const defaultSubmitHtml = '<i class="fas fa-paper-plane"></i> Submit Application';

    // Validate all steps before submission
    const stepNames = {1:'Personal',2:'Profession',3:'Beneficiaries',4:'Payment',5:'Bank Details',6:'Declaration'};
    for (let i = 1; i <= totalSteps; i++) {
        if (!validateStep(i)) {
            goToStep(i);
            // validateStep already shows the specific field error; add step context if not already shown
            return;
        }
    }
    
    // Validate optional email format when provided
    const emailInput = document.getElementById('email');
    if (emailInput && emailInput.value.trim()) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailInput.value.trim())) {
            showValidationError('Please enter a valid email address.');
            goToStep(1);
            emailInput.focus();
            return;
        }
    }

    // Validate file uploads (proofDocuments is optional)
    const requiredFiles = ['investorId', 'beneficiaryId', 'passbook', 'paymentSlip', 'otherDocuments'];
    const fileLabels = {
        investorId: 'investor ID',
        beneficiaryId: 'beneficiary ID',
        passbook: 'passbook',
        paymentSlip: 'payment slip',
        otherDocuments: 'proposal form'
    };
    let missingFiles = [];
    
    requiredFiles.forEach(fileId => {
        const fileInput = document.getElementById(fileId);
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            missingFiles.push(fileLabels[fileId] || fileId);
        }
    });
    
    if (missingFiles.length > 0) {
        const fileList = missingFiles.map(f => `<strong>${f}</strong>`).join(', ');
        showValidationError(`The following required documents are missing (Step 5: Bank Details): ${fileList}. Please upload all required files before submitting.`);
        goToStep(5);
        return;
    }

    if (hasOversizedAttachment(document)) {
        showValidationError('One or more uploaded files are too large (Step 5: Bank Details). Please ensure each file is within the allowed size limit and try again.');
        goToStep(5);
        return;
    }
    
    // Validate declaration checkbox
    const declarationCheckbox = document.getElementById('declaration');
    if (!declarationCheckbox || !declarationCheckbox.checked) {
        showValidationError('You must check the <strong>declaration checkbox</strong> (Step 6: Declaration) to confirm that the information you provided is accurate and complete.');
        goToStep(6);
        if (declarationCheckbox) declarationCheckbox.focus();
        return;
    }

    const salesPersonCodeInput = document.getElementById('salesPersonCode');
    if (submitBtn) {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating...';
        submitBtn.disabled = true;
    }

    const salesCodeValid = await validateSalesPersonCode(salesPersonCodeInput ? salesPersonCodeInput.value : '');
    if (!salesCodeValid) {
        if (submitBtn) {
            submitBtn.innerHTML = defaultSubmitHtml;
            submitBtn.disabled = false;
        }
        goToStep(6);
        if (salesPersonCodeInput) {
            salesPersonCodeInput.focus();
        }
        return;
    }
    
    // Show loading state
    if (submitBtn) {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        submitBtn.disabled = true;
    }

    this.dataset.salesCodeValidated = '1';
    this.submit();
});

// Initialize progress bar
updateProgress();

// Add floating elements
function createFloatingElements() {
    const container = document.querySelector('.floating-elements');
    if (!container) return;
    
    for (let i = 0; i < 15; i++) {
        const element = document.createElement('div');
        element.className = 'floating-element';
        element.style.top = `${Math.random() * 100}%`;
        element.style.left = `${Math.random() * 100}%`;
        element.style.animationDelay = `${Math.random() * 10}s`;
        element.style.animationDuration = `${15 + Math.random() * 15}s`;
        container.appendChild(element);
    }
    
    // Add more leaves
    for (let i = 0; i < 8; i++) {
        const leaf = document.createElement('div');
        leaf.className = 'leaf';
        leaf.style.top = `${Math.random() * 100}%`;
        leaf.style.left = `${Math.random() * 100}%`;
        leaf.style.animationDelay = `${Math.random() * 10}s`;
        leaf.style.animationDuration = `${20 + Math.random() * 20}s`;
        container.appendChild(leaf);
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    createFloatingElements();
    
    // Set default date values
    const today = new Date().toISOString().split('T')[0];
    
    // Set signing date to today
    const signingDateInput = document.getElementById('signingDate');
    if (signingDateInput && !signingDateInput.value) {
        signingDateInput.value = today;
    }
    
    // Set declaration date to today
    const declarationDateInput = document.getElementById('declarationDate');
    if (declarationDateInput && !declarationDateInput.value) {
        declarationDateInput.value = today;
    }
    
    // Allow any date of birth up to and including today
    const dobInput = document.getElementById('dob');
    if (dobInput) {
        dobInput.max = today;
    }

    // Apply Person / Company labels from saved or default type
    const applicantTypeInput = document.getElementById('applicantType');
    setApplicantType(applicantTypeInput && applicantTypeInput.value === 'company' ? 'company' : 'person');

    const salesPersonCodeInput = document.getElementById('salesPersonCode');
    if (salesPersonCodeInput) {
        salesPersonCodeInput.addEventListener('blur', async function() {
            if (this.value.trim()) {
                await validateSalesPersonCode(this.value);
            }
        });
    }

    const salesPersonCodeError = document.getElementById('salesPersonCodeError');
    if (salesPersonCodeError && salesPersonCodeError.textContent.trim()) {
        const salesInput = document.getElementById('salesPersonCode');
        if (salesInput) {
            salesInput.classList.add('input-error');
        }
        goToStep(6);
    }
    
    console.log('Form initialized successfully');

});

// Full name validation for block letters only (person applicants)
function validateFullName(name) {
    // Remove extra spaces and check if it's valid
    const trimmedName = name.trim();
    const blockLettersRegex = /^[A-Z\s]+$/;
    
    return blockLettersRegex.test(trimmedName) && trimmedName.length >= 2;
}

function isCompanyApplicant() {
    const typeInput = document.getElementById('applicantType');
    return typeInput && typeInput.value === 'company';
}

// Add event listener for full name validation
document.addEventListener('DOMContentLoaded', function() {
    const fullNameInput = document.getElementById('fullName');
    
    if (fullNameInput) {
        // Convert to uppercase on blur (when user leaves the field)
        fullNameInput.addEventListener('blur', function() {
            this.value = this.value.toUpperCase();
            
            // Validate format for person applicants only
            if (!isCompanyApplicant() && this.value.trim() && !validateFullName(this.value)) {
                showValidationError('Full name should contain only uppercase letters (A-Z) and spaces');
                this.focus();
            }
        });
        
        // Prevent invalid key presses for person applicants only
        fullNameInput.addEventListener('keypress', function(e) {
            if (isCompanyApplicant()) return;
            const char = String.fromCharCode(e.which);
            // Allow only letters and space
            if (!/[A-Z\s]/i.test(char)) {
                e.preventDefault();
                return false;
            }
        });
        
        // Format the name as user types
        fullNameInput.addEventListener('input', function() {
            if (isCompanyApplicant()) {
                this.value = this.value.toUpperCase();
                return;
            }
            // Remove any non-letter characters except spaces
            this.value = this.value.replace(/[^A-Z\s]/gi, '');
            // Convert to uppercase
            this.value = this.value.toUpperCase();
        });
    }

    const personBtn = document.getElementById('applicantTypePerson');
    const companyBtn = document.getElementById('applicantTypeCompany');
    if (personBtn) {
        personBtn.addEventListener('click', function(e) {
            e.preventDefault();
            setApplicantType('person');
        });
    }
    if (companyBtn) {
        companyBtn.addEventListener('click', function(e) {
            e.preventDefault();
            setApplicantType('company');
        });
    }

    const investmentTypeSelect = document.getElementById('investmentType');
    const gspPlanSelect = document.getElementById('gspPlan');
    const gspPaymentSelect = document.getElementById('gspPaymentOption');
    if (investmentTypeSelect) {
        investmentTypeSelect.addEventListener('change', updateGspPlanFields);
    }
    if (gspPlanSelect) {
        gspPlanSelect.addEventListener('change', updateGspPlanFields);
    }
    if (gspPaymentSelect) {
        gspPaymentSelect.addEventListener('change', updateGspPlanFields);
    }
    updateGspPlanFields();
});

const GSP_PLAN1_OPTIONS = {
    one_time: { investment: 200000, maturity: 1000000 }
};

function updateGspPlanFields() {
    const investmentType = document.getElementById('investmentType');
    const gspPlanGroup = document.getElementById('gspPlanGroup');
    const gspPaymentGroup = document.getElementById('gspPaymentGroup');
    const gspMaturityGroup = document.getElementById('gspMaturityGroup');
    const gspPlan = document.getElementById('gspPlan');
    const gspPaymentOption = document.getElementById('gspPaymentOption');
    const investmentAmount = document.getElementById('investmentAmount');
    const maturityAmount = document.getElementById('maturityAmount');

    if (!investmentType || !gspPlanGroup || !gspPaymentGroup || !gspPlan || !gspPaymentOption) {
        return;
    }

    const isGsp = investmentType.value === 'GSP';
    gspPlanGroup.style.display = isGsp ? '' : 'none';

    if (!isGsp) {
        gspPlan.value = '';
        gspPlan.removeAttribute('required');
        gspPaymentOption.value = '';
        gspPaymentOption.removeAttribute('required');
        gspPaymentGroup.style.display = 'none';
        if (gspMaturityGroup) gspMaturityGroup.style.display = 'none';
        if (maturityAmount) maturityAmount.value = '';
        if (investmentAmount) investmentAmount.readOnly = false;
        return;
    }

    gspPlan.setAttribute('required', 'required');

    const isPlan1 = gspPlan.value === 'plan1';
    gspPaymentGroup.style.display = isPlan1 ? '' : 'none';

    if (isPlan1) {
        gspPaymentOption.setAttribute('required', 'required');
    } else {
        gspPaymentOption.value = '';
        gspPaymentOption.removeAttribute('required');
        if (gspMaturityGroup) gspMaturityGroup.style.display = 'none';
        if (maturityAmount) maturityAmount.value = '';
        if (investmentAmount) investmentAmount.readOnly = false;
        return;
    }

    const selectedOption = GSP_PLAN1_OPTIONS[gspPaymentOption.value];
    if (selectedOption) {
        if (investmentAmount) {
            investmentAmount.value = selectedOption.investment;
            investmentAmount.readOnly = true;
        }
        if (maturityAmount) {
            maturityAmount.value = selectedOption.maturity;
        }
        if (gspMaturityGroup) {
            gspMaturityGroup.style.display = '';
        }
    } else {
        if (investmentAmount) investmentAmount.readOnly = false;
        if (maturityAmount) maturityAmount.value = '';
        if (gspMaturityGroup) gspMaturityGroup.style.display = 'none';
    }
}
