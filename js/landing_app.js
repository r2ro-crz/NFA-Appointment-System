// Enhanced JavaScript with additional functionality
document.addEventListener('DOMContentLoaded', () => {
    const landingContainer = document.querySelector('.landing-container');
    const actionButtons = document.querySelectorAll('.role-button');
    const languageSelect = document.getElementById('languageSelect');
    const languageToast = document.getElementById('languageToast');
    const toastClose = document.querySelector('.toast-close');
    
    // Subtle fade-in animation for the container
    if (landingContainer) {
        landingContainer.style.opacity = 0;
        landingContainer.style.transform = 'translateY(20px) scale(0.98)';
        
        setTimeout(() => {
            landingContainer.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            landingContainer.style.opacity = 1;
            landingContainer.style.transform = 'translateY(0) scale(1)';
        }, 100);
    }
    
    // Add visual feedback on button interactions
    actionButtons.forEach(button => {
        button.addEventListener('mouseenter', (e) => {
            e.target.style.boxShadow = '0 8px 20px rgba(0, 0, 0, 0.15)';
            e.target.style.transform = 'translateY(-3px)';
        });
        
        button.addEventListener('mouseleave', (e) => {
            e.target.style.boxShadow = 'none';
            e.target.style.transform = 'translateY(0)';
        });
        
        // Add click effect
        button.addEventListener('mousedown', (e) => {
            e.target.style.transform = 'translateY(1px)';
        });
        
        button.addEventListener('mouseup', (e) => {
            e.target.style.transform = 'translateY(-3px)';
        });
    });
    
    // Language selector functionality
    if (languageSelect) {
        languageSelect.addEventListener('change', function() {
            const selectedLanguage = this.value;
            const languageName = this.options[this.selectedIndex].text;
            
            // Show toast notification
            showToast(`Language changed to ${languageName}`);
            
            // In a real application, you would change the page language here
            // For this example, we'll just simulate it
            console.log(`Language changed to: ${selectedLanguage}`);
            
            // You could also store the preference in localStorage
            localStorage.setItem('preferredLanguage', selectedLanguage);
        });
    }
    
    // Toast notification functions
    function showToast(message) {
        if (!languageToast) return;
        
        // Update toast message
        languageToast.querySelector('span').textContent = message;
        
        // Show toast
        languageToast.classList.add('show');
        
        // Auto-hide after 4 seconds
        setTimeout(() => {
            hideToast();
        }, 4000);
    }
    
    function hideToast() {
        if (!languageToast) return;
        languageToast.classList.remove('show');
    }
    
    // Close toast on button click
    if (toastClose) {
        toastClose.addEventListener('click', hideToast);
    }
    
    // Role cards animation on scroll
    const roleCards = document.querySelectorAll('.role-card');
    
    // Simple intersection observer for cards
    if ('IntersectionObserver' in window) {
        const cardObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, { threshold: 0.1 });
        
        roleCards.forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            cardObserver.observe(card);
        });
    }
    
    // Preload check for video
    const video = document.querySelector('.bg-video');
    if (video) {
        video.addEventListener('loadeddata', () => {
            console.log('Background video loaded successfully');
        });
        
        video.addEventListener('error', () => {
            console.log('Video failed to load, using fallback');
            const videoFallback = document.querySelector('.video-fallback');
            if (videoFallback) {
                videoFallback.style.display = 'block';
            }
        });
    }
    
    // Set initial language from localStorage if available
    const savedLanguage = localStorage.getItem('preferredLanguage');
    if (savedLanguage && languageSelect) {
        languageSelect.value = savedLanguage;
    }
});