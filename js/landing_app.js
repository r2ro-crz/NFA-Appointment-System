// Enhanced JavaScript with additional functionality
document.addEventListener('DOMContentLoaded', () => {
    const landingContainer = document.querySelector('.landing-container');
    const actionButtons = document.querySelectorAll('.role-button');
    
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
    
    // Language selector removed
    
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
    
    // Language settings removed

});