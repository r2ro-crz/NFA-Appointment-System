(function () {
  const CONTENT = {
    privacy: {
      title: 'Privacy Policy',
      html: `
        <div class="legal-content">
          <p><strong>Last updated:</strong> January 11, 2026</p>
          <p>This Privacy Policy explains how the National Food Authority (NFA) Farmer’s Appointment Portal collects, uses, stores, and protects personal information. This page is provided for transparency for users of this system and is intended to align with applicable Philippine laws and government policies on privacy and access to information.</p>

          <h3>1) Key privacy commitments</h3>
          <ul>
            <li><strong>Protection of personal information:</strong> Personal information (including sensitive personal information) under NFA control is disclosed only as permitted by existing laws. Reasonable security arrangements are implemented to guard against unauthorized access, leaks, loss, misuse, alteration, or premature disclosure.</li>
            <li><strong>Freedom of Information (FOI) with privacy safeguards:</strong> While NFA supports public disclosure for matters of public interest, the right to privacy is a recognized exception. Information deemed confidential for the protection of individuals’ privacy should not be released in response to an FOI request.</li>
            <li><strong>Lawful processing principles:</strong> When processing personal data (e.g., for program beneficiaries), NFA applies the principles of transparency, legitimate purpose, and proportionality. Disclosure of personal information (such as names and amounts received) may be allowed, but only to the minimum extent necessary for a stated purpose (e.g., transparency and accountability in government programs).</li>
            <li><strong>Employee accountability:</strong> NFA employees and officials with authorized access to personal information are prohibited from disclosing it except as legally authorized.</li>
          </ul>

          <h3>2) Information we may collect</h3>
          <ul>
            <li><strong>Account and identity information:</strong> name, user role, login identifiers, and related account details.</li>
            <li><strong>Appointment details:</strong> selected branch/region, appointment date, time slot (AM/PM), and other appointment-related information you submit.</li>
            <li><strong>Operational records:</strong> status updates (e.g., pending/confirmed/completed/cancelled) and delivery details (e.g., delivered volume) where applicable.</li>
            <li><strong>Technical data:</strong> basic device/browser data and logs necessary for security, troubleshooting, and audit purposes.</li>
          </ul>

          <h3>3) How we use your information</h3>
          <ul>
            <li>To schedule, manage, and process farmer appointments.</li>
            <li>To communicate appointment-related updates/notifications to authorized users.</li>
            <li>To enforce system security, prevent abuse, and maintain service availability.</li>
            <li>To comply with legal obligations, audit requirements, and lawful government reporting.</li>
          </ul>

          <h3>4) Sharing and disclosure</h3>
          <p>We may share or disclose information only when lawful and necessary, such as:</p>
          <ul>
            <li>Within NFA offices/branches and authorized personnel for appointment processing and service delivery.</li>
            <li>With service providers strictly for system operations (e.g., email delivery), subject to confidentiality and security requirements.</li>
            <li>When required by law, legal process, or valid government directives.</li>
          </ul>

          <h3>5) Data retention</h3>
          <p>We retain personal information only for as long as necessary to fulfill the stated purposes, comply with legal requirements, and support auditing and record-keeping. Retention periods may vary depending on record type and regulatory obligations.</p>

          <h3>6) Security measures</h3>
          <p>We implement reasonable organizational, physical, and technical security measures. Access is restricted to authorized users, and administrative controls are applied to reduce the risk of unauthorized access or disclosure. No system can be guaranteed 100% secure, but we continuously improve controls where feasible.</p>

          <h3>7) Your rights and inquiries</h3>
          <p>If you have questions or requests related to personal information processed through this portal (e.g., correction of inaccurate details), please contact your NFA office/branch handling your appointment.</p>
        </div>
      `
    },
    terms: {
      title: 'Terms of Use',
      html: `
        <div class="legal-content">
          <p><strong>Last updated:</strong> January 11, 2026</p>
          <p>These Terms of Use govern your access to and use of the NFA Farmer’s Appointment Portal. By using this system, you agree to these terms.</p>

          <h3>1) Eligibility and access</h3>
          <ul>
            <li>You must use the portal only for lawful purposes and in connection with NFA appointment services.</li>
            <li>Access may be limited to authorized users (e.g., farmers, processors, administrators) depending on assigned roles.</li>
          </ul>

          <h3>2) User responsibilities</h3>
          <ul>
            <li><strong>Accurate information:</strong> You are responsible for providing accurate and complete information when creating or managing appointments.</li>
            <li><strong>Account security:</strong> Keep your credentials confidential. Notify NFA personnel if you suspect unauthorized access.</li>
            <li><strong>Appropriate use:</strong> Do not attempt to disrupt the system, bypass security controls, scrape data, or access accounts/data not intended for you.</li>
          </ul>

          <h3>3) Appointments and availability</h3>
          <ul>
            <li>Appointment slots are subject to branch capacity and availability.</li>
            <li>NFA may confirm, reschedule, or cancel appointments based on operational requirements, capacity constraints, or incomplete/invalid information.</li>
          </ul>

          <h3>4) Privacy</h3>
          <p>Your use of the portal is also governed by the Privacy Policy. By using the portal, you acknowledge that your information will be processed according to that policy and applicable laws.</p>

          <h3>5) System availability and changes</h3>
          <ul>
            <li>The portal may be temporarily unavailable due to maintenance, updates, or technical issues.</li>
            <li>NFA may modify features, workflows, or these Terms of Use at any time, with updates reflected on this page.</li>
          </ul>

          <h3>6) Disclaimer</h3>
          <p>This portal is provided to streamline appointment processing. While reasonable efforts are made to keep information accurate and the service available, NFA does not guarantee uninterrupted operation and is not liable for delays or interruptions beyond its control.</p>

          <h3>7) Governing law</h3>
          <p>These Terms are governed by the laws of the Republic of the Philippines and applicable government rules and regulations.</p>
        </div>
      `
    },
    help: {
      title: 'Help Center',
      html: `
        <div class="legal-content">
          <p><strong>Support</strong></p>
          <ul>
            <li><strong>Office hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM</li>
            <li><strong>Contact number:</strong> 09171139347</li>
            <li><strong>Tip:</strong> Choose an available date/time slot and verify your branch details before submitting.</li>
          </ul>
          <h3>Frequently asked questions</h3>
          <ul>
            <li><strong>I can’t find an available date.</strong> Some dates may be full. Try another day or a different time slot (AM/PM) if available.</li>
            <li><strong>I submitted an appointment. What’s next?</strong> Monitor your status (pending/confirmed). If rescheduled by staff, check the updated date/time.</li>
            <li><strong>I entered wrong details.</strong> Contact your NFA branch for correction or rescheduling assistance.</li>
          </ul>
          <p>If you’re a staff user and experience issues (e.g., missing appointments or notification problems), please coordinate with your local system administrator.</p>
        </div>
      `
    },
    faq: {
      title: 'Frequently Asked Questions',
      html: `
        <div class="legal-content">
          <p><strong>Last updated:</strong> January 12, 2026</p>

          <h3>Scheduling</h3>
          <ul>
            <li><strong>Why can’t I select some dates?</strong> Those dates may be fully booked or not available for the selected branch.</li>
            <li><strong>Can I change my appointment after submitting?</strong> If you need to change details, contact your NFA branch/support so they can assist with updates or rescheduling.</li>
            <li><strong>What if I submitted wrong information?</strong> Contact support and provide your appointment details so corrections can be handled properly.</li>
          </ul>

          <h3>Time slots</h3>
          <ul>
            <li><strong>AM/PM availability</strong> depends on branch capacity. If one slot is full, try the other slot or a different date.</li>
          </ul>

          <h3>Troubleshooting</h3>
          <ul>
            <li><strong>The page is not loading correctly.</strong> Refresh the page. If the issue persists, try a different browser or clear cache.</li>
            <li><strong>I didn’t receive confirmation/OTP emails.</strong> Check spam/junk folders and verify your email address is correct. If still missing, contact support.</li>
          </ul>
        </div>
      `
    },
    contact: {
      title: 'Contact Support',
      html: `
        <div class="legal-content">
          <p><strong>Last updated:</strong> January 12, 2026</p>
          <p>If you need assistance with appointments (schedule, reschedule, corrections, or technical issues), reach out using the options below.</p>

          <h3>Contact options</h3>
          <ul>
            <li><strong>Phone:</strong> (02) 8929-6701</li>
            <li><strong>Email:</strong> support@nfa.gov.ph</li>
            <li><strong>Office hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM</li>
          </ul>

          <h3>Live chat</h3>
          <p>Live chat is not currently available in this portal. Please call or email support for faster assistance.</p>
        </div>
      `
    }
  };

  const ensureModal = () => {
    let backdrop = document.getElementById('legalModalBackdrop');
    if (backdrop) return backdrop;

    backdrop = document.createElement('div');
    backdrop.className = 'legal-modal-backdrop';
    backdrop.id = 'legalModalBackdrop';
    backdrop.hidden = true;

    backdrop.innerHTML = `
      <div class="legal-modal" role="dialog" aria-modal="true" aria-labelledby="legalModalTitle">
        <div class="legal-modal-header">
          <h2 class="legal-modal-title" id="legalModalTitle">Details</h2>
          <button type="button" class="legal-modal-close" id="legalModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="legal-modal-body" id="legalModalBody"></div>
        <div class="legal-modal-footer">
          <button type="button" class="legal-modal-secondary" id="legalModalCloseFooter">Close</button>
        </div>
      </div>
    `;

    document.body.appendChild(backdrop);
    return backdrop;
  };

  const getTemplateEl = (type) => {
    const idMap = {
      privacy: 'privacyPolicyTemplate',
      terms: 'termsOfUseTemplate',
      help: 'helpCenterTemplate'
    };
    return document.getElementById(idMap[type]);
  };

  const state = {
    lastFocusedEl: null
  };

  const closeModal = () => {
    const backdrop = document.getElementById('legalModalBackdrop');
    if (!backdrop) return;
    backdrop.hidden = true;
    backdrop.style.display = 'none';
    document.body.classList.remove('legal-modal-open');

    const body = document.getElementById('legalModalBody');
    if (body) body.innerHTML = '';

    if (state.lastFocusedEl && typeof state.lastFocusedEl.focus === 'function') {
      state.lastFocusedEl.focus();
    }
    state.lastFocusedEl = null;
  };

  const openModal = (type) => {
    const content = CONTENT[type];
    if (!content) return;

    const backdrop = ensureModal();
    const titleEl = document.getElementById('legalModalTitle');
    const bodyEl = document.getElementById('legalModalBody');

    state.lastFocusedEl = document.activeElement;

    if (titleEl) titleEl.textContent = content.title;

    if (bodyEl) {
      bodyEl.innerHTML = '';

      const template = getTemplateEl(type);
      if (template && template.content) {
        bodyEl.appendChild(template.content.cloneNode(true));
      } else {
        bodyEl.innerHTML = content.html;
      }
    }

    backdrop.hidden = false;
    backdrop.style.display = 'flex';
    document.body.classList.add('legal-modal-open');

    const closeBtn = document.getElementById('legalModalClose');
    if (closeBtn) closeBtn.focus();
  };

  const wireOnce = () => {
    const backdrop = ensureModal();

    if (!backdrop.dataset.wired) {
      backdrop.dataset.wired = '1';

      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeModal();
      });

      const closeBtn = document.getElementById('legalModalClose');
      const closeFooterBtn = document.getElementById('legalModalCloseFooter');
      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      if (closeFooterBtn) closeFooterBtn.addEventListener('click', closeModal);

      document.addEventListener('keydown', (e) => {
        const b = document.getElementById('legalModalBackdrop');
        if (e.key === 'Escape' && b && !b.hidden) closeModal();
      });
    }

    const triggers = document.querySelectorAll('[data-legal-modal]');
    triggers.forEach((el) => {
      if (el.dataset.legalWired === '1') return;
      el.dataset.legalWired = '1';

      el.addEventListener('click', (e) => {
        e.preventDefault();
        const t = (el.getAttribute('data-legal-modal') || '').toLowerCase();
        if (t === 'privacy') return openModal('privacy');
        if (t === 'terms' || t === 'terms-of-service' || t === 'service') return openModal('terms');
        if (t === 'help') return openModal('help');
        if (t === 'faq' || t === 'faqs') return openModal('faq');
        if (t === 'contact' || t === 'contact-us' || t === 'support') return openModal('contact');
      });
    });
  };

  // Expose for debugging/manual usage if needed
  window.NFALegalModal = {
    open: openModal,
    close: closeModal,
    wire: wireOnce
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireOnce);
  } else {
    wireOnce();
  }
})();
