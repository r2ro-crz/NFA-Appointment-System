document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.querySelector('.login-container form');
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    const errorMessage = document.querySelector('.login-container .error');

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