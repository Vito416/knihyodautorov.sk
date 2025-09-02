(function () {
  'use strict';

  // Config (upravené selektory z gdpr → privacy)
  const MODAL_SELECTOR = '#privacyModal';
  const CLOSE_BTN_SELECTOR = '#closePrivacyBtn';
  const OPEN_TRIGGER_SELECTOR = '.footer-privacy-modalbtn';
  const FOCUSABLE_SELECTORS = [
    'a[href]:not([tabindex="-1"]):not([disabled])',
    'button:not([disabled])',
    'input:not([type="hidden"]):not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  let modal = null;
  let closeBtn = null;
  let isOpen = false;
  let prevFocusedEl = null;

  function $(sel) { return document.querySelector(sel); }
  function q$(root, sel) { return Array.from((root || document).querySelectorAll(sel)); }

  function isVisible(el) {
    if (!el) return false;
    const style = getComputedStyle(el);
    return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
  }

  function getFocusableIn(mod) {
    return q$(mod, FOCUSABLE_SELECTORS).filter(isVisible);
  }

  function initRefs() {
    modal = $(MODAL_SELECTOR);
    closeBtn = modal ? modal.querySelector(CLOSE_BTN_SELECTOR) : null;
    return !!modal;
  }

  function ensureInBody() {
    if (!modal) return;
    if (modal.parentNode !== document.body) {
      document.body.appendChild(modal);
    }
  }

  function ensureTabIndex() {
    if (!modal) return;
    if (!modal.hasAttribute('tabindex')) modal.setAttribute('tabindex', '-1');
  }

  function openPrivacyModal(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();

    if (!modal) {
      if (!initRefs()) return;
    }

    if (isOpen) return;

    ensureInBody();
    ensureTabIndex();

    prevFocusedEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('active');

    // lock scroll
    document.body.style.overflow = 'hidden';

    setTimeout(() => {
      const focusables = getFocusableIn(modal);
      if (focusables.length) {
        focusables[0].focus();
      } else {
        try { modal.focus(); } catch (err) {}
      }
      isOpen = true;
      document.addEventListener('keydown', onKeyDown, true);
    }, 40);
  }

  function closePrivacyModal() {
    if (!modal || !isOpen) return;

    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('active');

    document.body.style.overflow = '';

    try {
      if (prevFocusedEl && typeof prevFocusedEl.focus === 'function') prevFocusedEl.focus();
    } catch (err) {}

    isOpen = false;
    document.removeEventListener('keydown', onKeyDown, true);
  }

  function onOverlayClick(e) {
    if (!modal) return;
    if (e.target === modal) closePrivacyModal();
  }

  function onKeyDown(e) {
    if (!isOpen || !modal) return;

    if (e.key === 'Escape' || e.key === 'Esc') {
      e.preventDefault();
      closePrivacyModal();
      return;
    }

    if (e.key === 'Tab') {
      const focusables = getFocusableIn(modal);
      if (focusables.length === 0) {
        e.preventDefault();
        try { modal.focus(); } catch (err) {}
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first || document.activeElement === modal) {
          e.preventDefault();
          last.focus();
        }
      } else {
        if (document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    }
  }

  function delegatedClickHandler(e) {
    const trigger = e.target.closest(OPEN_TRIGGER_SELECTOR);
    if (!trigger) return;
    if (e && typeof e.preventDefault === 'function') e.preventDefault();
    openPrivacyModal(e);
  }

  function init() {
    initRefs(); // try init refs (ok pokud modal není v DOM)
    if (modal) {
      if (!modal.hasAttribute('aria-hidden')) modal.setAttribute('aria-hidden', 'true');
      ensureTabIndex();
      modal.addEventListener('click', onOverlayClick);
      if (closeBtn) closeBtn.addEventListener('click', closePrivacyModal);
    }

    document.addEventListener('click', delegatedClickHandler, { passive: false });

    // expose global methods for other scripts if needed
    window.openPrivacyModal = openPrivacyModal;
    window.closePrivacyModal = closePrivacyModal;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();

(function () {
  'use strict';

  const forms = document.querySelectorAll('.contact-form');

  forms.forEach(form => {
    const RECAPTCHA_PUBLIC_KEY = form.dataset.sitekey;
    if (!RECAPTCHA_PUBLIC_KEY) {
      console.error('reCAPTCHA public key není nastavený!');
      return;
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = form.querySelector('button[type="submit"]');
      const feedbackEl = document.getElementById('contact-feedback');
      if (!submitBtn || !feedbackEl) return;

      const btnText = submitBtn.querySelector('.btn-text');
      const spinner = submitBtn.querySelector('.btn-spinner');

      // aktivace spinneru
      if (btnText) btnText.style.display = 'none';
      if (spinner) spinner.style.display = 'inline-block';
      submitBtn.disabled = true;
      feedbackEl.textContent = '';

      grecaptcha.ready(() => {
        grecaptcha.execute(RECAPTCHA_PUBLIC_KEY, { action: 'contact' }).then(token => {
          // doplnění tokenu
          form.querySelector('input[name="g-recaptcha-response"]').value = token;

          const formData = new FormData(form);
          fetch(form.action, { method: 'POST', body: formData })
            .then(resp => {
              if (!resp.ok) throw new Error('Network response not OK');
              return resp.json(); // parse JSON
            })
            .then(data => {
              if (data.success) {
                feedbackEl.textContent = data.message;
                form.reset();
              } else {
                feedbackEl.textContent = data.error || 'Chyba při odesílání.';
              }
            })
            .catch(err => {
              console.error(err);
              feedbackEl.textContent = 'Chyba při odesílání, zkuste znovu.';
            })
            .finally(() => {
              if (btnText) btnText.style.display = 'inline';
              if (spinner) spinner.style.display = 'none';
              submitBtn.disabled = false;
            });
        });
      });
    });
  });
})();