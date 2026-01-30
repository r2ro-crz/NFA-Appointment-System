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

    const setOverlayMessage = (message) => {
        try {
            const p = loadingOverlay && loadingOverlay.querySelector('.loading-content p');
            if (p) p.textContent = message || p.textContent || 'Processing…';
        } catch (e) {
            // ignore
        }
    };

    const showGlobalLoading = (message) => {
        try {
            if (window.NFALoading && typeof window.NFALoading.show === 'function') {
                window.NFALoading.show(message || 'Processing…');
                return;
            }
        } catch (e) {
            // ignore
        }

        if (loadingOverlay) {
            setOverlayMessage(message || 'Processing…');
            loadingOverlay.classList.add('active');
        }
    };

    const hideGlobalLoading = () => {
        try {
            if (window.NFALoading && typeof window.NFALoading.hide === 'function') {
                window.NFALoading.hide();
                return;
            }
        } catch (e) {
            // ignore
        }

        if (loadingOverlay) {
            loadingOverlay.classList.remove('active');
        }
    };
    
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
            showGlobalLoading('Authenticating…');
            
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
    
    // --- Apply lockout state on load ---
    applyLockoutState();

    // --- Auto-hide transient server banners (errors + registration submitted) ---
    const transientAlerts = Array.from(document.querySelectorAll('.alert'))
        .filter(el => el.id !== 'lockoutMessage');

    transientAlerts.forEach(el => {
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
        }, 7000);
    });

    // Prevent transient banners from reappearing on refresh
    try {
        const url = new URL(window.location.href);
        const transientKeys = ['registered', 'error', 'pending', 'locked', 'rejected', 'deactivated'];
        const hadTransient = transientKeys.some(k => url.searchParams.has(k));
        if (hadTransient) {
            transientKeys.forEach(k => url.searchParams.delete(k));
            const qs = url.searchParams.toString();
            window.history.replaceState({}, document.title, url.pathname + (qs ? `?${qs}` : '') + url.hash);
        }
    } catch (e) {
        // ignore
    }

    // --- OTP 2FA modal handling ---
    const otpBackdrop = document.getElementById('otpModalBackdrop');
    const otpCloseBtn = document.getElementById('otpModalClose');
    const otpForm = document.getElementById('otpForm');
    const otpInputsWrap = document.getElementById('otpInputs');
    const otpError = document.getElementById('otpError');
    const otpSubmitBtn = document.getElementById('otpSubmitBtn');
    const otpResendBtn = document.getElementById('otpResendBtn');
    const otpCancelBtn = document.getElementById('otpCancelBtn');

    const otpBoxes = otpInputsWrap ? Array.from(otpInputsWrap.querySelectorAll('.otp-box')) : [];
    let lastFocusedBeforeOtp = null;

    const showOtpError = (msg) => {
        if (!otpError) return;
        otpError.textContent = msg;
        otpError.style.display = 'block';
    };

    const clearOtpError = () => {
        if (!otpError) return;
        otpError.textContent = '';
        otpError.style.display = 'none';
    };

    const openOtpModal = () => {
        if (!otpBackdrop) return;
        lastFocusedBeforeOtp = document.activeElement;
        otpBackdrop.hidden = false;
        otpBackdrop.style.display = 'flex';
        document.body.classList.add('otp-modal-open');
        clearOtpError();
        otpBoxes.forEach(b => (b.value = ''));
        if (otpBoxes[0]) otpBoxes[0].focus();
    };

    const closeOtpModal = () => {
        if (!otpBackdrop) return;
        otpBackdrop.hidden = true;
        otpBackdrop.style.display = 'none';
        document.body.classList.remove('otp-modal-open');
        clearOtpError();
        if (lastFocusedBeforeOtp && typeof lastFocusedBeforeOtp.focus === 'function') {
            lastFocusedBeforeOtp.focus();
        }
        lastFocusedBeforeOtp = null;
    };

    const getOtpValue = () => otpBoxes.map(b => (b.value || '').replace(/\D/g, '')).join('');

    // Auto-advance inputs and handle paste/backspace
    otpBoxes.forEach((box, idx) => {
        box.addEventListener('input', () => {
            clearOtpError();
            box.value = (box.value || '').replace(/\D/g, '').slice(0, 1);
            if (box.value && otpBoxes[idx + 1]) {
                otpBoxes[idx + 1].focus();
            }
        });

        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !box.value && otpBoxes[idx - 1]) {
                otpBoxes[idx - 1].focus();
                otpBoxes[idx - 1].value = '';
                e.preventDefault();
            }
            if (e.key === 'ArrowLeft' && otpBoxes[idx - 1]) {
                otpBoxes[idx - 1].focus();
                e.preventDefault();
            }
            if (e.key === 'ArrowRight' && otpBoxes[idx + 1]) {
                otpBoxes[idx + 1].focus();
                e.preventDefault();
            }
        });

        box.addEventListener('paste', (e) => {
            const text = (e.clipboardData || window.clipboardData).getData('text');
            const digits = (text || '').replace(/\D/g, '').slice(0, 6).split('');
            if (digits.length) {
                e.preventDefault();
                digits.forEach((d, i) => {
                    if (otpBoxes[i]) otpBoxes[i].value = d;
                });
                const next = otpBoxes[Math.min(digits.length, 5)];
                if (next) next.focus();
            }
        });
    });

    if (otpCloseBtn) {
        otpCloseBtn.addEventListener('click', () => {
            // Treat close as cancel
            if (otpCancelBtn) otpCancelBtn.click();
        });
    }

    if (otpBackdrop) {
        otpBackdrop.addEventListener('click', (e) => {
            if (e.target === otpBackdrop) {
                if (otpCancelBtn) otpCancelBtn.click();
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && otpBackdrop && !otpBackdrop.hidden) {
            if (otpCancelBtn) otpCancelBtn.click();
        }
    });

    const postOtpAction = async (action, bodyObj) => {
        const body = new URLSearchParams({ action, ...(bodyObj || {}) });
        const resp = await fetch('php_helper/authenticate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body
        });
        const data = await resp.json().catch(() => ({}));
        return { ok: resp.ok, status: resp.status, data };
    };

    if (otpForm) {
        otpForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearOtpError();

            const otp = getOtpValue();
            if (otp.length !== 6) {
                showOtpError('Please enter the 6-digit code.');
                return;
            }

            otpSubmitBtn && (otpSubmitBtn.disabled = true);
            otpSubmitBtn && (otpSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...');
            showGlobalLoading('Verifying code…');

            try {
                const res = await postOtpAction('verifyOtp', { otp });
                if (res.data && res.data.success && res.data.redirect) {
                    clearAttempts();
                    window.location.href = res.data.redirect;
                    return;
                }
                showOtpError((res.data && res.data.message) ? res.data.message : 'Verification failed. Please try again.');
            } catch (err) {
                showOtpError('Network error. Please try again.');
            } finally {
                hideGlobalLoading();
                otpSubmitBtn && (otpSubmitBtn.disabled = false);
                otpSubmitBtn && (otpSubmitBtn.innerHTML = '<i class="fas fa-check"></i> Verify');
            }
        });
    }

    if (otpResendBtn) {
        otpResendBtn.addEventListener('click', async () => {
            clearOtpError();
            otpResendBtn.disabled = true;
            const oldText = otpResendBtn.textContent;
            otpResendBtn.textContent = 'Sending...';
            showGlobalLoading('Sending a new code…');

            try {
                const res = await postOtpAction('resendOtp');
                if (res.data && res.data.success) {
                    showOtpError(res.data.message || 'A new code was sent to your email.');
                    // style it like info by reusing error box (simple)
                    if (otpError) {
                        otpError.style.background = 'rgba(0, 122, 51, 0.10)';
                        otpError.style.borderColor = 'rgba(0, 122, 51, 0.25)';
                        otpError.style.color = '#0f5132';
                    }
                    otpBoxes.forEach(b => (b.value = ''));
                    if (otpBoxes[0]) otpBoxes[0].focus();
                } else {
                    showOtpError((res.data && res.data.message) ? res.data.message : 'Could not resend code.');
                }
            } catch (err) {
                showOtpError('Network error. Please try again.');
            } finally {
                hideGlobalLoading();
                otpResendBtn.disabled = false;
                otpResendBtn.textContent = oldText;
            }
        });
    }

    if (otpCancelBtn) {
        otpCancelBtn.addEventListener('click', async () => {
            try {
                await postOtpAction('cancelOtp');
            } catch (e) {
                // ignore
            }
            closeOtpModal();
            // Remove the query string so refresh doesn't reopen modal
            try {
                const url = new URL(window.location.href);
                url.searchParams.delete('twofa');
                window.history.replaceState({}, document.title, url.toString());
            } catch (e) {
                // ignore
            }
        });
    }

    // Auto-open OTP modal after successful credential check
    try {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('twofa') === '1') {
            openOtpModal();
        }
    } catch (e) {
        // ignore
    }
    
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

    // --- Forgot Password flow ---
    const forgotLink = document.getElementById('forgotPasswordLink');
    const fpBackdrop = document.getElementById('fpModalBackdrop');
    const fpClose = document.getElementById('fpModalClose');
    const fpAlert = document.getElementById('fpAlert');

    const fpStepEmail = document.getElementById('fpStepEmail');
    const fpStepOtp = document.getElementById('fpStepOtp');
    const fpStepPassword = document.getElementById('fpStepPassword');

    const fpEmailForm = document.getElementById('fpEmailForm');
    const fpEmailInput = document.getElementById('fpEmail');
    const fpEmailSubmit = document.getElementById('fpEmailSubmit');

    const fpOtpForm = document.getElementById('fpOtpForm');
    const fpOtpInputsWrap = document.getElementById('fpOtpInputs');
    const fpOtpSubmit = document.getElementById('fpOtpSubmit');
    const fpResendBtn = document.getElementById('fpResendBtn');
    const fpCancelBtn = document.getElementById('fpCancelBtn');
    const fpOtpBoxes = fpOtpInputsWrap ? Array.from(fpOtpInputsWrap.querySelectorAll('.otp-box')) : [];

    const fpPasswordForm = document.getElementById('fpPasswordForm');
    const fpNewPassword = document.getElementById('fpNewPassword');
    const fpConfirmPassword = document.getElementById('fpConfirmPassword');
    const fpPasswordSubmit = document.getElementById('fpPasswordSubmit');
    const fpToggleNew = document.getElementById('fpToggleNew');
    const fpToggleConfirm = document.getElementById('fpToggleConfirm');

    let lastFocusedBeforeFp = null;

    const fpShowAlert = (msg, type) => {
        if (!fpAlert) return;
        fpAlert.textContent = msg;
        fpAlert.style.display = 'block';

        if (type === 'success') {
            fpAlert.style.background = 'rgba(0, 122, 51, 0.10)';
            fpAlert.style.borderColor = 'rgba(0, 122, 51, 0.25)';
            fpAlert.style.color = '#0f5132';
        } else {
            fpAlert.style.background = 'rgba(220, 53, 69, 0.10)';
            fpAlert.style.borderColor = 'rgba(220, 53, 69, 0.25)';
            fpAlert.style.color = '#b02a37';
        }
    };

    const fpClearAlert = () => {
        if (!fpAlert) return;
        fpAlert.textContent = '';
        fpAlert.style.display = 'none';
    };

    const fpShowStep = (step) => {
        if (fpStepEmail) fpStepEmail.hidden = step !== 'email';
        if (fpStepOtp) fpStepOtp.hidden = step !== 'otp';
        if (fpStepPassword) fpStepPassword.hidden = step !== 'password';
    };

    const fpOpen = () => {
        if (!fpBackdrop) return;
        lastFocusedBeforeFp = document.activeElement;
        fpBackdrop.hidden = false;
        fpBackdrop.style.display = 'flex';
        document.body.classList.add('fp-modal-open');
        fpClearAlert();
        fpShowStep('email');
        if (fpEmailInput) fpEmailInput.focus();
    };

    const fpCloseModal = () => {
        if (!fpBackdrop) return;
        fpBackdrop.hidden = true;
        fpBackdrop.style.display = 'none';
        document.body.classList.remove('fp-modal-open');
        fpClearAlert();
        if (lastFocusedBeforeFp && typeof lastFocusedBeforeFp.focus === 'function') {
            lastFocusedBeforeFp.focus();
        }
        lastFocusedBeforeFp = null;
    };

    const fpGetOtp = () => fpOtpBoxes.map(b => (b.value || '').replace(/\D/g, '')).join('');

    const fpPost = async (action, bodyObj) => {
        const body = new URLSearchParams({ action, ...(bodyObj || {}) });
        const resp = await fetch('php_helper/authenticate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body
        });
        const data = await resp.json().catch(() => ({}));
        return { ok: resp.ok, status: resp.status, data };
    };

    const fpCancel = async () => {
        try { await fpPost('cancelPasswordReset'); } catch (e) { /* ignore */ }
        fpCloseModal();
    };

    if (forgotLink) {
        forgotLink.addEventListener('click', (e) => {
            e.preventDefault();
            fpOpen();
        });
    }

    if (fpClose) {
        fpClose.addEventListener('click', fpCancel);
    }

    if (fpBackdrop) {
        fpBackdrop.addEventListener('click', (e) => {
            if (e.target === fpBackdrop) fpCancel();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && fpBackdrop && !fpBackdrop.hidden) {
            fpCancel();
        }
    });

    // Step 1: send reset code
    if (fpEmailForm) {
        fpEmailForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            fpClearAlert();

            const email = (fpEmailInput ? fpEmailInput.value.trim() : '');
            if (!email) {
                fpShowAlert('Please enter your email address.');
                return;
            }

            fpEmailSubmit && (fpEmailSubmit.disabled = true);
            fpEmailSubmit && (fpEmailSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...');
            showGlobalLoading('Sending reset code…');

            try {
                const res = await fpPost('startPasswordReset', { email });
                if (res.data && res.data.success) {
                    fpShowAlert('Code sent. Please check your email.', 'success');
                    fpShowStep('otp');
                    fpOtpBoxes.forEach(b => (b.value = ''));
                    if (fpOtpBoxes[0]) fpOtpBoxes[0].focus();
                } else {
                    fpShowAlert((res.data && res.data.message) ? res.data.message : 'Unable to send code.');
                }
            } catch (err) {
                fpShowAlert('Network error. Please try again.');
            } finally {
                hideGlobalLoading();
                fpEmailSubmit && (fpEmailSubmit.disabled = false);
                fpEmailSubmit && (fpEmailSubmit.innerHTML = '<i class="fas fa-paper-plane"></i> Send Code');
            }
        });
    }

    // OTP input UX (auto-advance/paste/backspace)
    fpOtpBoxes.forEach((box, idx) => {
        box.addEventListener('input', () => {
            fpClearAlert();
            box.value = (box.value || '').replace(/\D/g, '').slice(0, 1);
            if (box.value && fpOtpBoxes[idx + 1]) fpOtpBoxes[idx + 1].focus();
        });

        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !box.value && fpOtpBoxes[idx - 1]) {
                fpOtpBoxes[idx - 1].focus();
                fpOtpBoxes[idx - 1].value = '';
                e.preventDefault();
            }
        });

        box.addEventListener('paste', (e) => {
            const text = (e.clipboardData || window.clipboardData).getData('text');
            const digits = (text || '').replace(/\D/g, '').slice(0, 6).split('');
            if (digits.length) {
                e.preventDefault();
                digits.forEach((d, i) => { if (fpOtpBoxes[i]) fpOtpBoxes[i].value = d; });
                const next = fpOtpBoxes[Math.min(digits.length, 5)];
                if (next) next.focus();
            }
        });
    });

    // Step 2: verify OTP
    if (fpOtpForm) {
        fpOtpForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            fpClearAlert();

            const otp = fpGetOtp();
            if (otp.length !== 6) {
                fpShowAlert('Please enter the 6-digit code.');
                return;
            }

            fpOtpSubmit && (fpOtpSubmit.disabled = true);
            fpOtpSubmit && (fpOtpSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...');
            showGlobalLoading('Verifying reset code…');

            try {
                const res = await fpPost('verifyPasswordResetOtp', { otp });
                if (res.data && res.data.success) {
                    fpShowAlert('Code verified. Please set a new password.', 'success');
                    fpShowStep('password');
                    fpNewPassword && (fpNewPassword.value = '');
                    fpConfirmPassword && (fpConfirmPassword.value = '');
                    fpNewPassword && fpNewPassword.focus();
                } else {
                    fpShowAlert((res.data && res.data.message) ? res.data.message : 'Invalid code.');
                }
            } catch (err) {
                fpShowAlert('Network error. Please try again.');
            } finally {
                hideGlobalLoading();
                fpOtpSubmit && (fpOtpSubmit.disabled = false);
                fpOtpSubmit && (fpOtpSubmit.innerHTML = '<i class="fas fa-check"></i> Verify Code');
            }
        });
    }

    if (fpResendBtn) {
        fpResendBtn.addEventListener('click', async () => {
            fpClearAlert();
            fpResendBtn.disabled = true;
            const oldText = fpResendBtn.textContent;
            fpResendBtn.textContent = 'Sending...';
            showGlobalLoading('Sending a new reset code…');

            try {
                const res = await fpPost('resendPasswordResetOtp');
                if (res.data && res.data.success) {
                    fpShowAlert(res.data.message || 'A new code was sent to your email.', 'success');
                    fpOtpBoxes.forEach(b => (b.value = ''));
                    if (fpOtpBoxes[0]) fpOtpBoxes[0].focus();
                } else {
                    fpShowAlert((res.data && res.data.message) ? res.data.message : 'Could not resend code.');
                }
            } catch (err) {
                fpShowAlert('Network error. Please try again.');
            } finally {
                hideGlobalLoading();
                fpResendBtn.disabled = false;
                fpResendBtn.textContent = oldText;
            }
        });
    }

    if (fpCancelBtn) {
        fpCancelBtn.addEventListener('click', fpCancel);
    }

    const validateFpPassword = (pw) => {
        const errors = [];
        if (!pw || pw.length < 8) errors.push('Password must be at least 8 characters.');
        if (!/[A-Z]/.test(pw)) errors.push('Include at least 1 uppercase letter.');
        if (!/[a-z]/.test(pw)) errors.push('Include at least 1 lowercase letter.');
        if (!/\d/.test(pw)) errors.push('Include at least 1 number.');
        return errors;
    };

    const toggleInputType = (inputEl, btnEl) => {
        if (!inputEl || !btnEl) return;
        const type = inputEl.getAttribute('type') === 'password' ? 'text' : 'password';
        inputEl.setAttribute('type', type);
        const icon = btnEl.querySelector('i');
        if (icon) icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
        btnEl.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
    };

    if (fpToggleNew) {
        fpToggleNew.addEventListener('click', () => toggleInputType(fpNewPassword, fpToggleNew));
    }
    if (fpToggleConfirm) {
        fpToggleConfirm.addEventListener('click', () => toggleInputType(fpConfirmPassword, fpToggleConfirm));
    }

    // Step 3: set new password
    if (fpPasswordForm) {
        fpPasswordForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            fpClearAlert();

            const pw = fpNewPassword ? fpNewPassword.value : '';
            const confirm = fpConfirmPassword ? fpConfirmPassword.value : '';

            if (pw !== confirm) {
                fpShowAlert('Passwords do not match.');
                return;
            }
            const pwErrors = validateFpPassword(pw);
            if (pwErrors.length) {
                fpShowAlert(pwErrors.join(' '));
                return;
            }

            fpPasswordSubmit && (fpPasswordSubmit.disabled = true);
            fpPasswordSubmit && (fpPasswordSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...');
            showGlobalLoading('Updating password…');

            try {
                const res = await fpPost('setNewPassword', { password: pw, confirm });
                if (res.data && res.data.success) {
                    fpShowAlert('Password updated successfully. You can now log in.', 'success');
                    // Close after short delay and reset flow
                    setTimeout(() => {
                        fpCloseModal();
                    }, 900);
                } else {
                    fpShowAlert((res.data && res.data.message) ? res.data.message : 'Failed to update password.');
                }
            } catch (err) {
                fpShowAlert('Network error. Please try again.');
            } finally {
                hideGlobalLoading();
                fpPasswordSubmit && (fpPasswordSubmit.disabled = false);
                fpPasswordSubmit && (fpPasswordSubmit.innerHTML = '<i class="fas fa-save"></i> Update Password');
            }
        });
    }
});