// Enhanced Registration JavaScript
document.addEventListener('DOMContentLoaded', () => {
    // Initialize registration form
    initRegistrationForm();
    initFormValidation();
    initPasswordValidation();
    initStepNavigation();
    initUserTypeToggle();
    initFormSubmission();

    // Auto-dismiss server banners (success/error) after a while
    autoDismissAlerts({ selector: '.alert', excludeSelector: '.lockout', delayMs: 7000 });
});

function autoDismissAlerts({ selector, excludeSelector, delayMs }) {
    const alerts = Array.from(document.querySelectorAll(selector));
    if (!alerts.length) return;

    alerts.forEach((el) => {
        if (excludeSelector && el.matches(excludeSelector)) return;
        if (el.dataset?.persist === 'true') return;

        setTimeout(() => {
            if (!el.isConnected) return;

            const height = el.offsetHeight;
            el.style.overflow = 'hidden';
            el.style.maxHeight = `${height}px`;
            el.style.transition = 'opacity 350ms ease, transform 350ms ease, max-height 350ms ease, margin 350ms ease, padding 350ms ease';

            requestAnimationFrame(() => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(-6px)';
                el.style.maxHeight = '0px';
                el.style.marginTop = '0px';
                el.style.marginBottom = '0px';
                el.style.paddingTop = '0px';
                el.style.paddingBottom = '0px';
            });

            setTimeout(() => {
                if (el.parentNode) el.remove();
            }, 380);
        }, delayMs);
    });
}

// Registration State
const registrationState = {
    currentStep: 1,
    totalSteps: 3,
    formData: {},
    validationErrors: {}
};

// Initialize Registration Form
function initRegistrationForm() {
    // Clear any previously persisted data so refresh starts clean
    clearSavedData();

    // Auto-save form data on input
    document.querySelectorAll('#registerForm input, #registerForm select').forEach(element => {
        element.addEventListener('input', (e) => {
            saveFormData();
            updateReviewSection();
        });
        
        element.addEventListener('change', (e) => {
            saveFormData();
            updateReviewSection();
        });
    });
    
    // Initialize password toggle
    initPasswordToggle();

    // Auto-focus first field
    const firstField = document.querySelector('#registerForm input, #registerForm select');
    if (firstField) {
        firstField.focus();
    }

    // Add subtle entrance animation to form elements
    document.querySelectorAll('.input-group').forEach((group, index) => {
        group.style.animationDelay = `${index * 0.05}s`;
        group.style.animation = 'fadeIn 0.5s ease forwards';
    });
}

// Form Validation
function initFormValidation() {
    const emailInput = document.getElementById('email_address');
    const emailConfirmInput = document.getElementById('email_address_confirm');
    const contactInput = document.getElementById('contact_number');
    const employeeIdInput = document.getElementById('employee_id');
    const usernameInput = document.getElementById('username');
    
    // Email validation
    if (emailInput) {
        emailInput.addEventListener('blur', validateEmail);
        emailInput.addEventListener('input', clearFieldError);
        emailInput.addEventListener('input', checkEmailAvailability);
    }
    
    // Email confirmation
    if (emailConfirmInput) {
        emailConfirmInput.addEventListener('blur', validateEmailConfirmation);
        emailConfirmInput.addEventListener('input', clearFieldError);
    }
    
    // Contact number validation
    if (contactInput) {
        contactInput.addEventListener('blur', validateContactNumber);
        contactInput.addEventListener('input', clearFieldError);
        contactInput.addEventListener('input', formatContactNumber);
    }
    
    // Employee ID validation
    if (employeeIdInput) {
        employeeIdInput.addEventListener('blur', validateEmployeeId);
        employeeIdInput.addEventListener('input', clearFieldError);
    }
    
    // Username validation
    if (usernameInput) {
        usernameInput.addEventListener('blur', validateUsername);
        usernameInput.addEventListener('input', clearFieldError);
        usernameInput.addEventListener('input', checkUsernameAvailability);
    }
    
    // Name validation
    document.getElementById('first_name')?.addEventListener('blur', validateName);
    document.getElementById('last_name')?.addEventListener('blur', validateName);
    
    // Real-time validation for required fields
    document.querySelectorAll('#registerForm input[required], #registerForm select[required]').forEach(input => {
        input.addEventListener('blur', () => {
            const value = (input.value || '').toString().trim();
            if (!value) {
                setFieldError(input, 'This field is required');
            }
        });

        const liveHandler = () => {
            const value = (input.value || '').toString().trim();
            if (value) {
                clearFieldError(input);
                setFieldSuccess(input);
            }
        };

        input.addEventListener('input', liveHandler);
        input.addEventListener('change', liveHandler);
    });
}

function checkEmailAvailability() {
    const emailField = document.getElementById('email_address');
    if (!emailField) return;

    const email = emailField.value.trim();
    if (!email || !validateEmailFormat(email)) return;

    clearTimeout(emailField._timeout);
    emailField._timeout = setTimeout(async () => {
        try {
            const response = await fetch(`php_helper/api.php?action=checkEmail&email=${encodeURIComponent(email)}`);
            const data = await response.json();

            if (data.exists) {
                setFieldError(emailField, 'This email is already registered');
            } else {
                clearFieldError(emailField);
                setFieldSuccess(emailField);
            }
        } catch (error) {
            console.warn('Could not check email availability:', error);
        }
    }, 500);
}

// Password Validation
function initPasswordValidation() {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    
    if (passwordInput) {
        passwordInput.addEventListener('input', () => {
            clearFieldError(passwordInput);
            validatePasswordStrength();
            validatePasswordMatch();
            updatePasswordRequirements();
        });
    }
    
    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', () => {
            clearFieldError(confirmPasswordInput);
            validatePasswordMatch();
            updatePasswordRequirements();
        });
    }
}

// Step Navigation
function initStepNavigation() {
    // Update progress indicator
    updateProgressIndicator();
    
    // Add keyboard navigation
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.target.matches('button, textarea')) {
            e.preventDefault();
            if (registrationState.currentStep < registrationState.totalSteps) {
                nextStep();
            }
        }
        
        if (e.key === 'Escape' && registrationState.currentStep > 1) {
            prevStep();
        }
    });
}

function updateProgressIndicator() {
    const progressSteps = document.querySelectorAll('.progress-step');
    
    progressSteps.forEach((step, index) => {
        const stepNumber = index + 1;
        
        if (stepNumber === registrationState.currentStep) {
            step.classList.add('active');
        } else if (stepNumber < registrationState.currentStep) {
            step.classList.add('completed');
            step.classList.remove('active');
        } else {
            step.classList.remove('active', 'completed');
        }
    });
}

function nextStep() {
    // Validate current step before proceeding
    if (!validateCurrentStep()) {
        return;
    }
    
    if (registrationState.currentStep < registrationState.totalSteps) {
        // Hide current step
        document.getElementById(`step${registrationState.currentStep}`).classList.remove('active');
        
        // Show next step
        registrationState.currentStep++;
        document.getElementById(`step${registrationState.currentStep}`).classList.add('active');
        
        // Update progress indicator
        updateProgressIndicator();
        
        // Scroll to top of step
        document.getElementById(`step${registrationState.currentStep}`).scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
        });
        
        // If moving to review step, update review content
        if (registrationState.currentStep === 3) {
            updateReviewSection();
        }
    }
}

function prevStep() {
    if (registrationState.currentStep > 1) {
        // Hide current step
        document.getElementById(`step${registrationState.currentStep}`).classList.remove('active');
        
        // Show previous step
        registrationState.currentStep--;
        document.getElementById(`step${registrationState.currentStep}`).classList.add('active');
        
        // Update progress indicator
        updateProgressIndicator();
        
        // Scroll to top of step
        document.getElementById(`step${registrationState.currentStep}`).scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
        });
    }
}

// User Type Toggle
function initUserTypeToggle() {
    const userTypeSelect = document.getElementById('user_type');
    const regionSelect = document.getElementById('region_id');
    const processorDesc = document.getElementById('processorDesc');
    const adminDesc = document.getElementById('adminDesc');
    
    if (userTypeSelect) {
        userTypeSelect.addEventListener('change', function() {
            toggleBranchSelection();
            updateRoleDescriptions();
        });
        
        // Initialize on load
        toggleBranchSelection();
        updateRoleDescriptions();
    }

    if (regionSelect) {
        regionSelect.addEventListener('change', () => {
            const regionId = regionSelect.value;
            if (regionId) {
                clearFieldError(regionSelect);
                setFieldSuccess(regionSelect);
            }
            loadBranchesForRegion(regionId, { preserveSelection: false });
        });
    }
    
    // Add click handlers to role cards
    if (processorDesc && adminDesc) {
        processorDesc.addEventListener('click', () => {
            userTypeSelect.value = 'Processor';
            toggleBranchSelection();
            updateRoleDescriptions();
            userTypeSelect.dispatchEvent(new Event('change'));
        });
        
        adminDesc.addEventListener('click', () => {
            userTypeSelect.value = 'Admin';
            toggleBranchSelection();
            updateRoleDescriptions();
            userTypeSelect.dispatchEvent(new Event('change'));
        });
    }
}

function toggleBranchSelection() {
    const userTypeSelect = document.getElementById('user_type');
    const regionSection = document.getElementById('regionSection');
    const regionSelect = document.getElementById('region_id');
    const branchSection = document.getElementById('branchSection');
    const branchSelect = document.getElementById('branch_id');
    
    if (!userTypeSelect) return;
    
    const isProcessor = userTypeSelect.value === 'Processor';
    
    if (regionSection) regionSection.style.display = isProcessor ? 'block' : 'none';
    if (branchSection) branchSection.style.display = isProcessor ? 'block' : 'none';

    if (regionSelect) {
        regionSelect.required = isProcessor;
        if (!isProcessor) {
            regionSelect.value = '';
            clearFieldError(regionSelect);
            regionSelect.classList.remove('success');
        }
    }

    if (branchSelect) {
        branchSelect.required = isProcessor;
        if (!isProcessor) {
            branchSelect.value = '';
            branchSelect.disabled = true;
            clearFieldError(branchSelect);
            branchSelect.classList.remove('success');
        } else {
            const regionId = regionSelect?.value || '';
            branchSelect.disabled = !regionId;
            if (regionId) {
                loadBranchesForRegion(regionId, { preserveSelection: true });
            } else {
                // Clear options to avoid mismatched branch/region
                branchSelect.value = '';
            }
        }
    }
    
    // Animate the change
    if (isProcessor) {
        if (regionSection) regionSection.style.animation = 'fadeIn 0.5s ease';
        if (branchSection) branchSection.style.animation = 'fadeIn 0.5s ease';
    }

    updateReviewSection();
}

const branchCacheByRegion = new Map();

async function loadBranchesForRegion(regionId, { preserveSelection } = { preserveSelection: true }) {
    const userType = document.getElementById('user_type')?.value;
    const branchSelect = document.getElementById('branch_id');

    if (userType !== 'Processor' || !branchSelect) return;

    const regionIdStr = (regionId || '').toString().trim();
    const previousBranch = preserveSelection ? (branchSelect.value || '') : '';

    if (!regionIdStr) {
        branchSelect.disabled = true;
        branchSelect.innerHTML = '<option value="">Select Region first</option>';
        clearFieldError(branchSelect);
        updateReviewSection();
        return;
    }

    branchSelect.disabled = true;
    branchSelect.innerHTML = '<option value="">Loading branches...</option>';

    try {
        let branches = branchCacheByRegion.get(regionIdStr);
        if (!branches) {
            const response = await fetch(`php_helper/api.php?action=getBranches&region_id=${encodeURIComponent(regionIdStr)}`);
            const data = await response.json();
            branches = Array.isArray(data?.data) ? data.data : [];
            branchCacheByRegion.set(regionIdStr, branches);
        }

        // Rebuild options safely
        branchSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select Branch';
        branchSelect.appendChild(placeholder);

        branches.forEach(b => {
            const opt = document.createElement('option');
            opt.value = String(b.branch_id);
            opt.textContent = String(b.branch_name ?? '');
            branchSelect.appendChild(opt);
        });

        branchSelect.disabled = false;

        if (previousBranch) {
            branchSelect.value = previousBranch;
        }

        // If selection isn't valid for this region, reset
        if (previousBranch && branchSelect.value !== previousBranch) {
            branchSelect.value = '';
        }

        if (branchSelect.value) {
            clearFieldError(branchSelect);
            setFieldSuccess(branchSelect);
        }
    } catch (error) {
        console.warn('Could not load branches:', error);
        branchSelect.disabled = true;
        branchSelect.innerHTML = '<option value="">Failed to load branches</option>';
        setFieldError(branchSelect, 'Unable to load branches. Please refresh the page.');
    }

    updateReviewSection();
}

function updateRoleDescriptions() {
    const userTypeSelect = document.getElementById('user_type');
    const processorDesc = document.getElementById('processorDesc');
    const adminDesc = document.getElementById('adminDesc');
    
    if (!userTypeSelect || !processorDesc || !adminDesc) return;
    
    const selectedType = userTypeSelect.value;
    
    // Reset all cards
    processorDesc.classList.remove('selected');
    adminDesc.classList.remove('selected');
    
    // Highlight selected card
    if (selectedType === 'Processor') {
        processorDesc.classList.add('selected');
    } else if (selectedType === 'Admin') {
        adminDesc.classList.add('selected');
    }
}

// Form Submission
function initFormSubmission() {
    const form = document.getElementById('registerForm');
    const submitBtn = document.getElementById('submitBtn');
    const termsCheckbox = document.getElementById('agreeTerms');
    
    if (!form || !submitBtn || !termsCheckbox) return;
    
    form.addEventListener('submit', function(e) {
        // Prevent double submit
        if (form.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }

        // Validate all steps
        if (!validateAllSteps()) {
            e.preventDefault();
            const firstErrorStep = findFirstStepWithErrors();
            if (firstErrorStep) {
                goToStep(firstErrorStep);
            }
            return;
        }

        // Check terms agreement
        if (!termsCheckbox.checked) {
            e.preventDefault();
            setFieldError(termsCheckbox, 'You must agree to the terms and conditions');
            termsCheckbox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // Allow native form POST so PHP redirects/messages work
        form.dataset.submitting = '1';
        showLoadingOverlay(true);
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    });
}

// Validation Functions
function validateCurrentStep() {
    const currentStep = registrationState.currentStep;
    let isValid = true;
    
    switch (currentStep) {
        case 1:
            isValid = validateStep1();
            break;
        case 2:
            isValid = validateStep2();
            break;
        case 3:
            isValid = validateStep3();
            break;
    }
    
    if (!isValid) {
        // Scroll to first error
        const firstError = document.querySelector('.input-wrapper.error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    
    return isValid;
}

function validateStep1() {
    let isValid = true;
    
    // Required fields
    const requiredFields = ['first_name', 'last_name', 'employee_id', 'email_address', 'contact_number', 'gender'];
    
    requiredFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field && !field.value.trim()) {
            setFieldError(field, 'This field is required');
            isValid = false;
        }
    });
    
    // Email validation
    const emailField = document.getElementById('email_address');
    if (emailField && emailField.value && !validateEmailFormat(emailField.value)) {
        setFieldError(emailField, 'Please enter a valid email address');
        isValid = false;
    }
    
    // Contact number validation
    const contactField = document.getElementById('contact_number');
    if (contactField && contactField.value && !validateContactFormat(contactField.value)) {
        setFieldError(contactField, 'Please enter a valid Philippine contact number');
        isValid = false;
    }
    
    return isValid;
}

function validateStep2() {
    let isValid = true;
    
    // Required fields
    const requiredFields = ['user_type', 'username', 'password', 'confirm_password'];
    
    requiredFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field && !field.value.trim()) {
            setFieldError(field, 'This field is required');
            isValid = false;
        }
    });
    
    // Region/Branch validation for processors
    const userType = document.getElementById('user_type')?.value;
    const regionField = document.getElementById('region_id');
    const branchField = document.getElementById('branch_id');

    if (userType === 'Processor') {
        if (regionField && !regionField.value) {
            setFieldError(regionField, 'Region selection is required for Processor accounts');
            isValid = false;
        } else if (regionField?.value) {
            setFieldSuccess(regionField);
        }

        if (branchField && !branchField.value) {
            setFieldError(branchField, 'Branch selection is required for Processor accounts');
            isValid = false;
        } else if (branchField?.value) {
            setFieldSuccess(branchField);
        }
    }
    
    // Password strength validation
    const passwordField = document.getElementById('password');
    if (passwordField && !validatePasswordStrength(passwordField.value)) {
        setFieldError(passwordField, 'Password does not meet requirements');
        isValid = false;
    } else if (passwordField?.value) {
        setFieldSuccess(passwordField);
    }
    
    // Password match validation
    const password = document.getElementById('password')?.value;
    const confirmPassword = document.getElementById('confirm_password')?.value;
    
    if (password && confirmPassword && password !== confirmPassword) {
        setFieldError(document.getElementById('confirm_password'), 'Passwords do not match');
        isValid = false;
    } else if (confirmPassword) {
        setFieldSuccess(document.getElementById('confirm_password'));
    }
    
    // Email confirmation
    const email = document.getElementById('email_address')?.value;
    const emailConfirm = document.getElementById('email_address_confirm')?.value;
    
    if (email && emailConfirm && email !== emailConfirm) {
        setFieldError(document.getElementById('email_address_confirm'), 'Email addresses do not match');
        isValid = false;
    } else if (emailConfirm) {
        setFieldSuccess(document.getElementById('email_address_confirm'));
    }
    
    return isValid;
}

function validateStep3() {
    // Check terms agreement
    const termsCheckbox = document.getElementById('agreeTerms');
    if (!termsCheckbox.checked) {
        setFieldError(termsCheckbox, 'You must agree to the terms and conditions');
        return false;
    }
    
    return true;
}

function validateAllSteps() {
    return validateStep1() && validateStep2() && validateStep3();
}

function findFirstStepWithErrors() {
    for (let step = 1; step <= 3; step++) {
        // We need to check each step's validation
        // For simplicity, we'll check if any error elements exist in each step
        const stepElement = document.getElementById(`step${step}`);
        if (stepElement && stepElement.querySelector('.input-wrapper.error')) {
            return step;
        }
    }
    return null;
}

// Field Validation Functions
function validateEmail() {
    const field = document.getElementById('email_address');
    if (!field) return true;
    
    const email = field.value.trim();
    if (!email) {
        setFieldError(field, 'Email is required');
        return false;
    }
    
    if (!validateEmailFormat(email)) {
        setFieldError(field, 'Please enter a valid email address');
        return false;
    }
    
    clearFieldError(field);
    setFieldSuccess(field);
    return true;
}

function validateEmailConfirmation() {
    const emailField = document.getElementById('email_address');
    const confirmField = document.getElementById('email_address_confirm');
    
    if (!emailField || !confirmField) return true;
    
    const email = emailField.value.trim();
    const confirm = confirmField.value.trim();
    
    if (!confirm) {
        setFieldError(confirmField, 'Please confirm your email address');
        return false;
    }
    
    if (email !== confirm) {
        setFieldError(confirmField, 'Email addresses do not match');
        return false;
    }
    
    clearFieldError(confirmField);
    setFieldSuccess(confirmField);
    return true;
}

function validateContactNumber() {
    const field = document.getElementById('contact_number');
    if (!field) return true;
    
    const contact = field.value.trim().replace(/\D/g, '');
    if (!contact) {
        setFieldError(field, 'Contact number is required');
        return false;
    }
    
    if (!validateContactFormat(contact)) {
        setFieldError(field, 'Please enter a valid Philippine contact number (09XXXXXXXXX)');
        return false;
    }
    
    clearFieldError(field);
    setFieldSuccess(field);
    return true;
}

function validateEmployeeId() {
    const field = document.getElementById('employee_id');
    if (!field) return true;
    
    const employeeId = field.value.trim();
    if (!employeeId) {
        setFieldError(field, 'Employee ID is required');
        return false;
    }
    
    if (employeeId.length < 3) {
        setFieldError(field, 'Employee ID must be at least 3 characters');
        return false;
    }
    
    clearFieldError(field);
    setFieldSuccess(field);
    return true;
}

function validateUsername() {
    const field = document.getElementById('username');
    if (!field) return true;
    
    const username = field.value.trim();
    if (!username) {
        setFieldError(field, 'Username is required');
        return false;
    }
    
    if (username.length < 3) {
        setFieldError(field, 'Username must be at least 3 characters');
        return false;
    }
    
    if (!/^[a-zA-Z0-9._-]+$/.test(username)) {
        setFieldError(field, 'Username can only contain letters, numbers, dots, dashes, and underscores');
        return false;
    }
    
    clearFieldError(field);
    setFieldSuccess(field);
    return true;
}

function validateName(e) {
    const field = e.target;
    const name = field.value.trim();
    
    if (!name) {
        setFieldError(field, 'This field is required');
        return false;
    }
    
    if (!/^[a-zA-Z\s'-]+$/.test(name)) {
        setFieldError(field, 'Name can only contain letters, spaces, hyphens, and apostrophes');
        return false;
    }
    
    clearFieldError(field);
    setFieldSuccess(field);
    return true;
}

// Password Validation Functions
function validatePasswordStrength() {
    const password = document.getElementById('password')?.value;
    if (!password) return false;
    
    const strength = calculatePasswordStrength(password);
    const strengthMeter = document.querySelector('.strength-meter');
    const strengthText = document.getElementById('strengthText');
    
    if (strengthMeter) {
        strengthMeter.className = 'strength-meter';
        strengthMeter.classList.add(strength.level);
    }
    
    if (strengthText) {
        strengthText.textContent = strength.label;
    }

    // Must satisfy all required rules (including special character)
    return !!(strength.requirements?.length && strength.requirements?.uppercase && strength.requirements?.lowercase && strength.requirements?.number && strength.requirements?.special);
}

function calculatePasswordStrength(password) {
    let score = 0;
    const requirements = {
        length: password.length >= 8,
        uppercase: /[A-Z]/.test(password),
        lowercase: /[a-z]/.test(password),
        number: /\d/.test(password),
        special: /[^A-Za-z0-9]/.test(password)
    };
    
    Object.values(requirements).forEach(met => {
        if (met) score++;
    });
    
    let level = 'weak';
    let label = 'Weak';
    
    if (score >= 5) {
        level = 'very-strong';
        label = 'Very Strong';
    } else if (score >= 4) {
        level = 'strong';
        label = 'Strong';
    } else if (score >= 3) {
        level = 'medium';
        label = 'Medium';
    }
    
    return { score, level, label, requirements };
}

function validatePasswordMatch() {
    const password = document.getElementById('password')?.value;
    const confirmPassword = document.getElementById('confirm_password')?.value;
    
    if (!password || !confirmPassword) return true;
    
    if (password !== confirmPassword) {
        setFieldError(document.getElementById('confirm_password'), 'Passwords do not match');
        return false;
    }
    
    clearFieldError(document.getElementById('confirm_password'));
    setFieldSuccess(document.getElementById('confirm_password'));
    return true;
}

function updatePasswordRequirements() {
    const password = document.getElementById('password')?.value || '';
    const confirmPassword = document.getElementById('confirm_password')?.value || '';
    
    const requirements = {
        length: password.length >= 8,
        uppercase: /[A-Z]/.test(password),
        lowercase: /[a-z]/.test(password),
        number: /\d/.test(password),
        special: /[^A-Za-z0-9]/.test(password),
        match: password && confirmPassword && password === confirmPassword
    };
    
    Object.entries(requirements).forEach(([rule, met]) => {
        const requirementElement = document.querySelector(`[data-rule="${rule}"]`);
        if (requirementElement) {
            requirementElement.classList.toggle('met', met);
            const icon = requirementElement.querySelector('i');
            if (icon) {
                icon.className = met ? 'fas fa-check-circle' : 'fas fa-circle';
                icon.style.color = met ? 'var(--success-green)' : '#ccc';
            }
        }
    });
}

// Utility Functions
function validateEmailFormat(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function validateContactFormat(contact) {
    const digits = contact.replace(/\D/g, '');
    return /^09\d{9}$/.test(digits) || /^\+639\d{9}$/.test(digits);
}

function formatContactNumber(e) {
    const field = e.target;
    let value = field.value.replace(/\D/g, '');
    
    if (value.length > 11) {
        value = value.substring(0, 11);
    }
    
    if (value.length >= 4 && value.length <= 7) {
        value = value.replace(/(\d{4})(\d+)/, '$1-$2');
    } else if (value.length >= 8) {
        value = value.replace(/(\d{4})(\d{3})(\d+)/, '$1-$2-$3');
    }
    
    field.value = value;
}

function setFieldError(field, message) {
    if (!field) return;

    field.classList.add('error');
    field.classList.remove('success');

    const wrapper = field.closest('.input-wrapper');
    if (wrapper) {
        wrapper.classList.add('error');
        wrapper.classList.remove('success');
    }

    const group = field.closest?.('.input-group');
    let feedback = group?.querySelector('.input-feedback') || wrapper?.querySelector('.input-feedback');
    if (!feedback) {
        const termsContainer = field.closest('.terms-checkbox');
        if (termsContainer) {
            feedback = termsContainer.querySelector('.input-feedback');
            if (!feedback) {
                feedback = document.createElement('div');
                feedback.className = 'input-feedback';
                termsContainer.appendChild(feedback);
            }
        }
    }

    if (feedback) feedback.textContent = message;
    
    // Store error in state
    const fieldId = field.id;
    if (fieldId) {
        registrationState.validationErrors[fieldId] = message;
    }
}

function setFieldSuccess(field) {
    if (!field) return;

    field.classList.remove('error');
    field.classList.add('success');

    const wrapper = field.closest?.('.input-wrapper');
    const group = field.closest?.('.input-group');
    if (wrapper) {
        wrapper.classList.remove('error');
        wrapper.classList.add('success');
    }

    const feedback = group?.querySelector('.input-feedback') || wrapper?.querySelector('.input-feedback');
    if (feedback) feedback.textContent = '';

    const fieldId = field.id;
    if (fieldId && registrationState.validationErrors[fieldId]) {
        delete registrationState.validationErrors[fieldId];
    }
}

function clearFieldError(e) {
    const field = e.target || e;
    const wrapper = field.closest('.input-wrapper');
    const group = field.closest?.('.input-group');
    
    if (wrapper) {
        wrapper.classList.remove('error');
    }

    if (field?.classList) {
        field.classList.remove('error');
    }
    
    const feedback = group?.querySelector('.input-feedback') || wrapper?.querySelector('.input-feedback');
    if (feedback) {
        feedback.textContent = '';
    }

    const termsContainer = field.closest?.('.terms-checkbox');
    const termsFeedback = termsContainer?.querySelector('.input-feedback');
    if (termsFeedback) {
        termsFeedback.textContent = '';
    }
    
    // Clear error from state
    const fieldId = field.id;
    if (fieldId && registrationState.validationErrors[fieldId]) {
        delete registrationState.validationErrors[fieldId];
    }
}

function initPasswordToggle() {
    const togglePasswordBtn = document.getElementById('togglePassword');
    const toggleConfirmPasswordBtn = document.getElementById('toggleConfirmPassword');
    const passwordField = document.getElementById('password');
    const confirmPasswordField = document.getElementById('confirm_password');
    
    if (togglePasswordBtn && passwordField) {
        togglePasswordBtn.addEventListener('click', () => {
            togglePasswordVisibility(passwordField, togglePasswordBtn);
        });
    }
    
    if (toggleConfirmPasswordBtn && confirmPasswordField) {
        toggleConfirmPasswordBtn.addEventListener('click', () => {
            togglePasswordVisibility(confirmPasswordField, toggleConfirmPasswordBtn);
        });
    }
}

function togglePasswordVisibility(passwordField, toggleButton) {
    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
    passwordField.setAttribute('type', type);
    
    const icon = toggleButton.querySelector('i');
    if (icon) {
        icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    }
    
    toggleButton.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
}

// Data Management
function saveFormData() {
    const formElements = document.querySelectorAll('#registerForm input, #registerForm select');
    const formData = {};
    
    formElements.forEach(element => {
        if (element.type === 'password' || element.type === 'checkbox') {
            return;
        }
        const id = element.id;
        const value = (element.value || '').toString().trim();
        
        if (id) {
            formData[id] = value;
        }
    });
    
    registrationState.formData = formData;
}

function loadSavedFormData() {
    try {
        const savedData = localStorage.getItem('nfa_registration_data');
        const savedStep = localStorage.getItem('nfa_registration_step');
        
        if (savedData) {
            const formData = JSON.parse(savedData);

            const applyValue = (id, value) => {
                const element = document.getElementById(id);
                if (!element || !value || element.type === 'password' || element.type === 'checkbox') return;
                element.value = value;
                if (element.tagName === 'SELECT') {
                    element.dispatchEvent(new Event('change'));
                }
            };

            // Apply in a safe order so region loads before branch
            applyValue('user_type', formData.user_type);
            applyValue('region_id', formData.region_id);

            Object.entries(formData).forEach(([id, value]) => {
                if (id === 'user_type' || id === 'region_id' || id === 'branch_id') return;
                applyValue(id, value);
            });

            // Branch last (after region triggers branch loading)
            applyValue('branch_id', formData.branch_id);
            
            registrationState.formData = formData;
            
            // Update review section if needed
            updateReviewSection();
        }
        
        if (savedStep && parseInt(savedStep) > 1) {
            // Don't auto-navigate, but we could show a resume option
        }
    } catch (e) {
        console.warn('Could not load saved form data');
    }
}

function clearSavedData() {
    try {
        localStorage.removeItem('nfa_registration_data');
        localStorage.removeItem('nfa_registration_step');
    } catch (e) {
        console.warn('Could not clear saved data');
    }
}

// Review Section
function updateReviewSection() {
    // Personal Information
    const firstName = document.getElementById('first_name')?.value || '--';
    const middleName = document.getElementById('middle_name')?.value || '';
    const lastName = document.getElementById('last_name')?.value || '--';
    const suffix = document.getElementById('suffix')?.value || '';
    
    let fullName = `${firstName}`;
    if (middleName) fullName += ` ${middleName}`;
    fullName += ` ${lastName}`;
    if (suffix) fullName += ` ${suffix}`;
    
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    setText('reviewName', fullName);
    setText('reviewEmployeeId', document.getElementById('employee_id')?.value || '--');
    setText('reviewEmail', document.getElementById('email_address')?.value || '--');
    setText('reviewContact', document.getElementById('contact_number')?.value || '--');
    setText('reviewGender', document.getElementById('gender')?.value || '--');
    
    // Account Details
    setText('reviewUserType', document.getElementById('user_type')?.value || '--');
    setText('reviewUsername', document.getElementById('username')?.value || '--');

    const regionItem = document.getElementById('reviewRegionItem');
    const regionDisplay = document.getElementById('reviewRegion');
    const regionSelect = document.getElementById('region_id');
    
    // Branch (if processor)
    const userType = document.getElementById('user_type')?.value;
    const branchId = document.getElementById('branch_id')?.value;
    const branchSection = document.getElementById('reviewBranchItem');
    const branchDisplay = document.getElementById('reviewBranch');
    
    if (userType === 'Processor') {
        // Region
        if (regionItem) regionItem.style.display = 'block';
        if (regionDisplay) {
            const selectedRegion = regionSelect?.options?.[regionSelect.selectedIndex]?.textContent || '--';
            regionDisplay.textContent = regionSelect?.value ? selectedRegion : '--';
        }

        // Branch
        if (branchId) {
            const branchSelect = document.getElementById('branch_id');
            const selectedOption = branchSelect?.options[branchSelect.selectedIndex];
            const branchName = selectedOption?.textContent || '--';
            if (branchDisplay) branchDisplay.textContent = branchName;
            if (branchSection) branchSection.style.display = 'block';
        } else {
            if (branchSection) branchSection.style.display = 'block';
            if (branchDisplay) branchDisplay.textContent = '--';
        }
    } else {
        if (regionItem) regionItem.style.display = 'none';
        if (branchSection) branchSection.style.display = 'none';
    }
}

// Navigation
function goToStep(stepNumber) {
    if (stepNumber >= 1 && stepNumber <= registrationState.totalSteps) {
        // Hide current step
        document.getElementById(`step${registrationState.currentStep}`)?.classList.remove('active');
        
        // Show target step
        registrationState.currentStep = stepNumber;
        document.getElementById(`step${registrationState.currentStep}`)?.classList.add('active');
        
        // Update progress indicator
        updateProgressIndicator();
        
        // Scroll to top of step
        document.getElementById(`step${registrationState.currentStep}`)?.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
        });
    }
}

// UI Helpers
function showLoadingOverlay(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        if (show) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }
}

function showErrorMessage(message) {
    // Create error alert
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert error';
    alertDiv.innerHTML = `
        <div class="alert-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="alert-content">
            <strong>Error</strong>
            <p>${message}</p>
        </div>
    `;
    
    // Insert at top of form
    const formWrapper = document.querySelector('.registration-form-wrapper');
    if (formWrapper) {
        formWrapper.insertBefore(alertDiv, formWrapper.firstChild);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
}

function checkUsernameAvailability() {
    const usernameField = document.getElementById('username');
    if (!usernameField) return;
    
    const username = usernameField.value.trim();
    if (username.length < 3) return;
    
    // Debounce the check
    clearTimeout(usernameField._timeout);
    usernameField._timeout = setTimeout(async () => {
        try {
            const response = await fetch(`php_helper/api.php?action=checkUsername&username=${encodeURIComponent(username)}`);
            const data = await response.json();
            
            if (data.exists) {
                setFieldError(usernameField, 'This username is already taken');
            } else {
                // Only set success if it passes local rules
                if (/^[a-zA-Z0-9._-]+$/.test(username)) {
                    clearFieldError(usernameField);
                    setFieldSuccess(usernameField);
                }
            }
        } catch (error) {
            console.warn('Could not check username availability:', error);
        }
    }, 500);
}

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    // Optionally clear saved data when registration is successful
    if (document.querySelector('.alert.success')) {
        clearSavedData();
    }
});