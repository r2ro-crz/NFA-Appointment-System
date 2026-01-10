// Enhanced Login JavaScript
document.addEventListener('DOMContentLoaded', () => {
    // --- DOM Elements ---
    const loginForm = document.getElementById('loginForm');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const submitBtn = document.getElementById('submitBtn');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const lockoutMessage = document.getElementById('lockoutMessage');
    const lockoutTimer = document.getElementById('countdown');
    
    // --- Login attempt throttling settings ---
    const ATTEMPT_KEY = 'nfa_login_attempts';
    const LOCK_KEY = 'nfa_login_lock_until';
    const LOCK_THRESHOLD = 5;
    const LOCK_DURATION_MS = 30 * 1000;
    let lockInterval = null;
    
    // --- Utility Functions ---
    function safeLocalStorageGet(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }
    
    function safeLocalStorageSet(key, val) {
        try { localStorage.setItem(key, val); } catch (e) { /* ignore */ }
    }
    
    function safeLocalStorageRemove(key) {
        try { localStorage.removeItem(key); } catch (e) { /* ignore */ }
    }
    
    function getAttempts() {
        return parseInt(safeLocalStorageGet(ATTEMPT_KEY) || '0', 10);
    }
    
    function setAttempts(n) {
        safeLocalStorageSet(ATTEMPT_KEY, String(n));
    }
    
    function incAttempts() {
        const a = getAttempts() + 1;
        setAttempts(a);
        if (a >= LOCK_THRESHOLD) {
            setLockout(Date.now() + LOCK_DURATION_MS);
        }
        return a;
    }
    
    function clearAttempts() {
        setAttempts(0);
    }
    
    function setLockout(untilMs) {
        safeLocalStorageSet(LOCK_KEY, String(untilMs));
        applyLockoutState();
    }
    
    function clearLockout() {
        safeLocalStorageRemove(LOCK_KEY);
        clearAttempts();
        applyLockoutState();
    }
    
    function getLockUntil() {
        return parseInt(safeLocalStorageGet(LOCK_KEY) || '0', 10);
    }
    
    function isLocked() {
        const until = getLockUntil();
        return until && Date.now() < until;
    }
    
    // --- Lockout State Management ---
    function applyLockoutState() {
        const isAccountLocked = isLocked();
        
        // Disable/enable form inputs
        usernameInput.disabled = isAccountLocked;
        passwordInput.disabled = isAccountLocked;
        
        if (isAccountLocked) {
            // Show lockout message
            lockoutMessage.style.display = 'flex';
            
            // Update button
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-lock"></i> Account Locked';
            
            // Start countdown timer
            updateLockoutTimer();
            if (lockInterval) clearInterval(lockInterval);
            lockInterval = setInterval(updateLockoutTimer, 1000);
        } else {
            // Hide lockout message
            lockoutMessage.style.display = 'none';
            
            // Enable button
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<span class="button-text">Log In</span><i class="fas fa-arrow-right button-icon"></i>';
            
            // Clear interval
            if (lockInterval) {
                clearInterval(lockInterval);
                lockInterval = null;
            }
        }
    }
    
    function updateLockoutTimer() {
        const until = getLockUntil();
        const remainingMs = Math.max(0, until - Date.now());
        
        if (remainingMs <= 0) {
            clearLockout();
            return;
        }
        
        const seconds = Math.ceil(remainingMs / 1000);
        lockoutTimer.textContent = seconds;
        
        // Update message text
        const timerText = lockoutMessage.querySelector('.alert-content p');
        timerText.innerHTML = `Please wait <span id="countdown">${seconds}</span> seconds before trying again.`;
    }
    
    // --- Form Validation ---
    function validateForm() {
        let isValid = true;
        const username = usernameInput.value.trim();
        const password = passwordInput.value;
        
        // Clear previous errors
        document.querySelectorAll('.input-feedback').forEach(el => el.textContent = '');
        document.querySelectorAll('.input-wrapper input').forEach(input => {
            input.style.borderColor = '#e1e5e9';
        });
        
        // Username validation (only required for login)
        if (!username) {
            showInputError(usernameInput, 'Username is required');
            isValid = false;
        }
        
        // Password validation
        if (!password) {
            showInputError(passwordInput, 'Password is required');
            isValid = false;
        }
        
        return isValid;
    }
    
    function showInputError(input, message) {
        input.style.borderColor = 'var(--full-red)';
        const feedback = input.parentElement.querySelector('.input-feedback');
        if (feedback) {
            feedback.textContent = message;
            feedback.style.color = 'var(--full-red)';
        }
    }
    
    // --- Password Visibility Toggle ---
    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Update icon
            const icon = this.querySelector('i');
            icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            
            // Accessibility
            this.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
        });
    }
    
    // --- Form Submission ---
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Check if account is locked
            if (isLocked()) {
                applyLockoutState();
                return;
            }
            
            // Validate form
            if (!validateForm()) {
                return;
            }
            
            // Show loading overlay
            loadingOverlay.classList.add('active');
            
            // Update button state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
            
            // Simulate network delay for demo purposes
            setTimeout(() => {
                // In a real application, this would be handled by the PHP backend
                // For now, we'll just submit the form
                loginForm.submit();
                
                // Increment attempts for demonstration (in real app, this would be based on server response)
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('error') === '1') {
                    incAttempts();
                }
            }, 1500);
        });
    }
    
    // --- Auto-hide transient error messages (but keep lockout visible) ---
    const errorMessages = document.querySelectorAll('.alert.error');
    errorMessages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.8s ease-out, transform 0.8s ease-out';
            message.style.opacity = '0';
            message.style.transform = 'translateX(-20px)';
            
            setTimeout(() => {
                if (message.parentNode) {
                    message.remove();
                }
            }, 800);
        }, 8000); // Hide after 8 seconds
    });
    
    // --- Apply lockout state on load ---
    applyLockoutState();
    
    // --- Increment attempts if coming from error page ---
    try {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('error') === '1') {
            const attempts = incAttempts();
            console.log(`Login failed. Total attempts: ${attempts}`);
        }
    } catch (e) {
        console.error('Error parsing URL parameters:', e);
    }
    
    // --- Input field animations ---
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        // Add focus animation
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'scale(1.02)';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'scale(1)';
        });
        
        // Real-time validation
        input.addEventListener('input', function() {
            if (this.value.trim() !== '') {
                this.style.borderColor = 'var(--nfa-green)';
            } else {
                this.style.borderColor = '#e1e5e9';
            }
        });
    });
    
    // --- Remember me functionality ---
    const rememberCheckbox = document.getElementById('remember');
    if (rememberCheckbox) {
        // Load saved username if remember me was checked previously
        const savedUsername = safeLocalStorageGet('remembered_username');
        if (savedUsername) {
            usernameInput.value = savedUsername;
            rememberCheckbox.checked = true;
        }
        
        // Save username when checkbox is checked
        rememberCheckbox.addEventListener('change', function() {
            if (this.checked && usernameInput.value.trim()) {
                safeLocalStorageSet('remembered_username', usernameInput.value.trim());
            } else {
                safeLocalStorageRemove('remembered_username');
            }
        });
    }
    
    // --- Accessibility improvements ---
    document.addEventListener('keydown', function(e) {
        // Submit form with Ctrl+Enter
        if (e.ctrlKey && e.key === 'Enter') {
            if (loginForm && !submitBtn.disabled) {
                loginForm.requestSubmit();
            }
        }
        
        // Toggle password with Alt+P
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            if (togglePasswordBtn) {
                togglePasswordBtn.click();
            }
        }
    });
});