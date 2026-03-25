let currentStep = 1;
const totalSteps = 6;

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
                showValidationError('Please accept the declaration.');
                input.focus();
                return false;
            }
        } else if (input.type === 'file') {
            // File validation will be handled on submission
            continue;
        } else {
            if (!input.value.trim()) {
                showValidationError(`Please fill in all required fields.`);
                input.focus();
                return false;
            }
            
            // Email validation for step 1
            if (step === 1 && input.type === 'email') {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(input.value)) {
                    showValidationError('Please enter a valid email address.');
                    input.focus();
                    return false;
                }
            }
            
            // Date validation for step 1
            if (step === 1 && input.type === 'date' && input.id === 'dob') {
                const dob = new Date(input.value);
                const today = new Date();
                if (dob >= today) {
                    showValidationError('Date of birth must be in the past.');
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
    
    return true;
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
                    <input type="text" name="beneficiaryName[]" required>
                    <div class="input-highlight"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="required">NIC No</label>
                <div class="input-container">
                    <input type="text" name="beneficiaryNIC[]" required>
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
    errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    
    // Insert after header
    const header = document.querySelector('header');
    if (header) {
        header.appendChild(errorDiv);
    }
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (errorDiv && errorDiv.parentNode) {
            errorDiv.remove();
        }
    }, 5000);
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
                
                // Validate file size (5MB)
                if (fileSize > 5 * 1024 * 1024) {
                    showValidationError('File size must be less than 5MB');
                    this.value = '';
                    if (fileInfoElement) {
                        fileInfoElement.textContent = 'File too large. Max 5MB.';
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
                    fileInfoElement.textContent = `Selected: ${fileName} (${(fileSize / 1024).toFixed(2)} KB)`;
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
document.getElementById('investor-form').addEventListener('submit', function(e) {
    // Validate all steps before submission
    for (let i = 1; i <= totalSteps; i++) {
        if (!validateStep(i)) {
            e.preventDefault();
            // Go to the step with error
            document.querySelectorAll('.form-step').forEach(step => {
                step.classList.remove('active');
            });
            const errorStep = document.getElementById(`step${i}`);
            if (errorStep) {
                errorStep.classList.add('active');
            }
            currentStep = i;
            updateProgress();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
    }
    
    // Validate file uploads
    const requiredFiles = ['investorId', 'beneficiaryId', 'passbook', 'paymentSlip'];
    let missingFiles = [];
    
    requiredFiles.forEach(fileId => {
        const fileInput = document.getElementById(fileId);
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            missingFiles.push(fileId.replace(/([A-Z])/g, ' $1').toLowerCase());
        }
    });
    
    if (missingFiles.length > 0) {
        e.preventDefault();
        showValidationError(`Please upload: ${missingFiles.join(', ')}`);
        // Go to step 5 (file uploads)
        document.querySelectorAll('.form-step').forEach(step => {
            step.classList.remove('active');
        });
        const step5 = document.getElementById('step5');
        if (step5) {
            step5.classList.add('active');
        }
        currentStep = 5;
        updateProgress();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
    }
    
    // Validate declaration checkbox
    const declarationCheckbox = document.getElementById('declaration');
    if (!declarationCheckbox || !declarationCheckbox.checked) {
        e.preventDefault();
        showValidationError('You must accept the declaration.');
        declarationCheckbox.focus();
        return;
    }
    
    // Show loading state
    const submitBtn = document.querySelector('.submit-btn');
    if (submitBtn) {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        submitBtn.disabled = true;
    }
    
    // Allow form to submit normally
    return true;
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
    
    // Set maximum date for dob to 18 years ago
    const dobInput = document.getElementById('dob');
    if (dobInput) {
        const maxDate = new Date();
        maxDate.setFullYear(maxDate.getFullYear() - 18);
        dobInput.max = maxDate.toISOString().split('T')[0];
    }
    
    console.log('Form initialized successfully');










// Full name validation for block letters only
function validateFullName(name) {
    // Remove extra spaces and check if it's valid
    const trimmedName = name.trim();
    const blockLettersRegex = /^[A-Z\s]+$/;
    
    return blockLettersRegex.test(trimmedName) && trimmedName.length >= 2;
}

// Add event listener for full name validation
document.addEventListener('DOMContentLoaded', function() {
    const fullNameInput = document.getElementById('fullName');
    
    if (fullNameInput) {
        // Convert to uppercase on blur (when user leaves the field)
        fullNameInput.addEventListener('blur', function() {
            this.value = this.value.toUpperCase();
            
            // Validate format
            if (this.value.trim() && !validateFullName(this.value)) {
                showValidationError('Full name should contain only uppercase letters (A-Z) and spaces');
                this.focus();
            }
        });
        
        // Prevent invalid key presses
        fullNameInput.addEventListener('keypress', function(e) {
            const char = String.fromCharCode(e.which);
            // Allow only letters and space
            if (!/[A-Z\s]/i.test(char)) {
                e.preventDefault();
                return false;
            }
        });
        
        // Format the name as user types
        fullNameInput.addEventListener('input', function() {
            // Remove any non-letter characters except spaces
            this.value = this.value.replace(/[^A-Z\s]/gi, '');
            // Convert to uppercase
            this.value = this.value.toUpperCase();
        });
    }
});




    
});
