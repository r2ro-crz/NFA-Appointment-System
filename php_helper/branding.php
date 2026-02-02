<?php

// System branding (shared across pages)
// Note: asset paths are relative to the project root (same style as existing code).

define('NFA_COMPANY_NAME', 'National Food Authority');
define('NFA_SYSTEM_NAME', 'PalayPortal');
define('NFA_SYSTEM_TAGLINE', 'Your Direct Line to NFA.');

// Primary brand label used across pages
define('NFA_BRAND_NAME', 'NFA ' . NFA_SYSTEM_NAME);

// Legacy constant retained for compatibility; now points to the PalayPortal logo
// so only one logo is shown throughout the system.
define('NFA_COMPANY_LOGO', 'img/PalayPortal_logo.png');
// Provided logo file in /img
// (File present in repo: img/PalayPortal_logo.png)
define('NFA_SYSTEM_LOGO', 'img/PalayPortal_logo.png');
// Tab icon (favicon)
define('NFA_FAVICON', NFA_SYSTEM_LOGO);

function nfa_page_title(string $pageTitle): string {
    $pageTitle = trim($pageTitle);
    if ($pageTitle === '') return NFA_BRAND_NAME;
    return NFA_BRAND_NAME . ' | ' . $pageTitle;
}

