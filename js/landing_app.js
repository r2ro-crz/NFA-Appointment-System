document.addEventListener('DOMContentLoaded', () => {
    const landingContainer = document.querySelector('.landing-container');
    const actionButtons = document.querySelectorAll('.action-button');

    // Subtle fade-in animation for the container
    if (landingContainer) {
        landingContainer.style.opacity = 0;
        landingContainer.style.transform = 'translateY(20px)';
        setTimeout(() => {
            landingContainer.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            landingContainer.style.opacity = 1;
            landingContainer.style.transform = 'translateY(0)';
        }, 100);
    }

    // Add visual feedback on button interactions
    actionButtons.forEach(button => {
        button.addEventListener('mouseenter', (e) => {
            e.target.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.1)';
            e.target.style.transform = 'translateY(-2px)';
        });

        button.addEventListener('mouseleave', (e) => {
            e.target.style.boxShadow = 'none';
            e.target.style.transform = 'translateY(0)';
        });
    });
});