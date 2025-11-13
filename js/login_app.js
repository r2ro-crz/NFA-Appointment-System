document.addEventListener('DOMContentLoaded', () => {
    // --- Login attempt throttling settings ---
    const ATTEMPT_KEY = 'nfa_login_attempts';
    const LOCK_KEY = 'nfa_login_lock_until';
    const LOCK_THRESHOLD = 5; // attempts
    const LOCK_DURATION_MS = 30 * 1000; // 30 seconds
    let lockInterval = null;

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

    // Create lockout message element (hidden by default) using CSS classes
    const loginContainer = document.querySelector('.login-container');
    let lockoutMessage = document.getElementById('lockoutMessage');
    if (!lockoutMessage && loginContainer) {
        lockoutMessage = document.createElement('div');
        lockoutMessage.id = 'lockoutMessage';
        lockoutMessage.className = 'alert lockout';
        lockoutMessage.style.display = 'none';
        // include an icon span and inner text container for consistent layout
        const icon = document.createElement('span');
        icon.className = 'alert-icon';
        icon.textContent = '⏳';
        const inner = document.createElement('div');
        inner.className = 'lockout-text';
        lockoutMessage.appendChild(icon);
        lockoutMessage.appendChild(inner);
        loginContainer.appendChild(lockoutMessage);
    }

    function applyLockoutState() {
        const usernameEl = document.getElementById('username');
        const passwordEl = document.getElementById('password');
        const submitBtn = document.querySelector('.login-button');

        if (isLocked()) {
            const until = getLockUntil();
            const remainingMs = Math.max(0, until - Date.now());
            // disable fields
            if (usernameEl) usernameEl.disabled = true;
            if (passwordEl) passwordEl.disabled = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Locked';
            }
            // show message with countdown
            const seconds = Math.ceil(remainingMs / 1000);
            if (lockoutMessage && lockoutMessage.querySelector('.lockout-text')) {
                lockoutMessage.querySelector('.lockout-text').textContent = `Too many failed attempts. Please wait ${seconds} second${seconds !== 1 ? 's' : ''} before trying again.`;
            }
            if (lockoutMessage) lockoutMessage.style.display = 'flex';

            // start or refresh interval
            if (lockInterval) clearInterval(lockInterval);
            lockInterval = setInterval(() => {
                const rem = Math.max(0, getLockUntil() - Date.now());
                if (rem <= 0) {
                    clearInterval(lockInterval);
                    lockInterval = null;
                    clearLockout();
                } else {
                    const s = Math.ceil(rem / 1000);
                    if (lockoutMessage && lockoutMessage.querySelector('.lockout-text')) {
                        lockoutMessage.querySelector('.lockout-text').textContent = `Too many failed attempts. Please wait ${s} second${s !== 1 ? 's' : ''} before trying again.`;
                    }
                }
            }, 250);
        } else {
            // enable fields
            if (usernameEl) usernameEl.disabled = false;
            if (passwordEl) passwordEl.disabled = false;
            const submitBtn = document.querySelector('.login-button');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Log In';
            }
            if (lockoutMessage) lockoutMessage.style.display = 'none';
            if (lockInterval) {
                clearInterval(lockInterval);
                lockInterval = null;
            }
        }
    }

    // If page was loaded with an error query param (server rejected login), increment attempts
    try {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('error') === '1') {
            const attempts = incAttempts();
            // show small client-side note (we keep server message too)
            console.debug('Login failed. Attempts:', attempts);
        }
    } catch (e) { /* ignore URL parsing errors */ }

    // Apply lockout state on load
    applyLockoutState();
    const loginForm = document.querySelector('.login-container form');
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    // server error element uses .alert.error now
    const errorMessage = document.querySelector('.login-container .alert.error');

    // 1. Password Visibility Toggle
    if (togglePassword) {
        togglePassword.addEventListener('click', function (e) {
            // Toggle the type attribute
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle the eye icon for better feedback
            this.textContent = type === 'password' ? '👁️' : '🔒';
        });
    }

    // 2. Client-side Form Validation and Visual Feedback
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            // Prevent submission while locked
            if (typeof isLocked === 'function' && isLocked()) {
                e.preventDefault();
                applyLockoutState();
                return;
            }

            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            // Simple client-side check
            if (username === '' || password === '') {
                e.preventDefault(); // Stop form submission
                alert("Please enter both a username and a password.");
                // Add temporary styling to highlight empty fields
                if (username === '') document.getElementById('username').style.borderColor = 'red';
                if (password === '') document.getElementById('password').style.borderColor = 'red';
                return;
            }
            
            // Reset border colors on valid submission attempt
            document.getElementById('username').style.borderColor = '#ddd';
            document.getElementById('password').style.borderColor = '#ddd';

            // Optional: Provide a "loading" state on the button
            const submitBtn = document.querySelector('.login-button');
            submitBtn.textContent = 'Verifying...';
            submitBtn.disabled = true;
        });
    }

    // 3. Auto-hide PHP error message after a few seconds
    if (errorMessage) {
        setTimeout(() => {
            errorMessage.style.transition = 'opacity 1s ease-out';
            errorMessage.style.opacity = 0;
            // Remove the element from the DOM after the transition finishes
            setTimeout(() => {
                errorMessage.remove();
            }, 1000); 
        }, 5000); // Wait 5 seconds before starting to fade
    }
});