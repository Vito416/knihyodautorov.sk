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

// --- Unified submit handler: CSRF + reCAPTCHA + single-send ---
(function () {
  'use strict';
  const FORM_SELECTORS = '.contact-form, .subscribe-form';
  const CSRF_ENDPOINT = '/eshop/csrf_token';

  let csrfToken = null;
  let csrfPromise = null;

  // fetch CSRF token; clears csrfPromise on fetch failure so next attempt will retry
  function fetchCsrf() {
    if (!csrfPromise) {
      csrfPromise = fetch(CSRF_ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(r => {
          if (!r.ok) throw new Error('CSRF fetch failed ' + r.status);
          return r.json().catch(() => { throw new Error('CSRF: invalid JSON'); });
        })
        .then(j => {
          const tok = j && (j.token || j.csrf || j.csrf_token) ? (j.token || j.csrf || j.csrf_token) : null;
          if (!tok) throw new Error('CSRF token missing in response');
          csrfToken = tok;
          return tok;
        })
        .catch(err => {
          // allow next caller to try again
          csrfPromise = null;
          throw err;
        });
    }
    return csrfPromise;
  }

  // ensureCsrfToken returns resolved token if present or starts fetch
  function ensureCsrfToken() {
    if (csrfToken) return Promise.resolve(csrfToken);
    return fetchCsrf();
  }

  // idempotent inject
  function injectCsrf(form, token) {
    if (!form) return;
    let el = form.querySelector('input[name="csrf"]');
    if (!el) {
      el = document.createElement('input');
      el.type = 'hidden';
      el.name = 'csrf';
      form.appendChild(el);
    }
    el.value = token || '';
  }

  // mark token as consumed (force re-fetch next time)
  function consumeCsrf() {
    csrfToken = null;
    csrfPromise = null;
  }

  // attempt to read a new token from server response (JSON field or header)
  function extractTokenFromResponse(resp, parsedJson) {
    if (parsedJson && (parsedJson.csrf || parsedJson.token || parsedJson.csrf_token)) {
      return parsedJson.csrf || parsedJson.token || parsedJson.csrf_token;
    }
    const hdr = resp.headers && (resp.headers.get && resp.headers.get('x-csrf-token'));
    if (hdr) return hdr;
    return null;
  }

  document.querySelectorAll(FORM_SELECTORS).forEach(form => {
    if (!form) return;
    if (form.dataset.unifiedAttached === '1') return;
    form.dataset.unifiedAttached = '1';

    let submitting = false;
    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      if (submitting) return;
      submitting = true;

      const submitBtn = form.querySelector('button[type="submit"]');
      const btnText = submitBtn ? submitBtn.querySelector('.btn-text') : null;
      const spinner = submitBtn ? submitBtn.querySelector('.btn-spinner') : null;
      const feedbackId = form.dataset.feedback || (form.classList.contains('subscribe-form') ? 'subscribe-feedback' : 'contact-feedback');
      const feedbackEl = document.getElementById(feedbackId);

      if (btnText) btnText.style.display = 'none';
      if (spinner) spinner.style.display = 'inline-block';
      if (submitBtn) submitBtn.disabled = true;
      if (feedbackEl) feedbackEl.textContent = '';

      // 1) ensure csrf
      try {
        const tok = await ensureCsrfToken();
        injectCsrf(form, tok);
      } catch (err) {
        console.error('CSRF fetch error', err);
        if (feedbackEl) feedbackEl.textContent = 'Nepodařilo se připravit formulář. Obnovte stránku.';
        submitting = false;
        if (btnText) btnText.style.display = 'inline';
        if (spinner) spinner.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
        return;
      }

      // 2) reCAPTCHA (if configured)
      try {
        const sitekey = form.dataset.sitekey;
        if (window.grecaptcha && sitekey) {
          await new Promise((resolve, reject) => {
            window.grecaptcha.ready(() => {
              try {
                window.grecaptcha.execute(sitekey, { action: form.dataset.action || 'submit' }).then(token => {
                  let inpf = form.querySelector('input[name="g-recaptcha-response"]');
                  if (!inpf) {
                    inpf = document.createElement('input');
                    inpf.type = 'hidden';
                    inpf.name = 'g-recaptcha-response';
                    form.appendChild(inpf);
                  }
                  inpf.value = token || '';
                  resolve();
                }).catch(reject);
              } catch (e) { reject(e); }
            });
          });
        }
      } catch (err) {
        console.error('reCAPTCHA error', err);
        if (feedbackEl) feedbackEl.textContent = 'Chyba při ověření reCAPTCHA.';
        submitting = false;
        if (btnText) btnText.style.display = 'inline';
        if (spinner) spinner.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
        return;
      }

      // 3) send with CSRF-retry-once logic
      try {
        async function doPost() {
          const formData = new FormData(form);
          injectCsrf(form, csrfToken);
          const resp = await fetch(form.action, { method: 'POST', body: formData, credentials: 'same-origin' });
          const txt = await resp.text();
          let parsed;
          try { parsed = JSON.parse(txt); } catch (_) { parsed = { success: resp.ok, message: txt || ('HTTP ' + resp.status) }; }
          return { resp, parsed, rawText: txt };
        }

        const attempt1 = await doPost();

        // detect CSRF error: status 400/401/403 OR message containing 'csrf'/'token'
        const attempt1Status = attempt1.resp && attempt1.resp.status;
        const attempt1Msg = (attempt1.parsed && attempt1.parsed.message) ? String(attempt1.parsed.message) : '';
        const isCsrfError = (
          (attempt1Status === 400 || attempt1Status === 401 || attempt1Status === 403) &&
          (/csrf|token/i.test(attempt1Msg) || !attempt1.parsed.success)
        ) || (/csrf|token/i.test(attempt1Msg));

        if (isCsrfError) {
          console.warn('CSRF rejected — fetching fresh token and retrying once');
          // if server returned a fresh token in response, use it
          const maybeNew = extractTokenFromResponse(attempt1.resp, attempt1.parsed);
          if (maybeNew) {
            csrfToken = maybeNew;
          } else {
            // otherwise force re-fetch
            consumeCsrf();
            try { await fetchCsrf(); } catch (e) { /* handled below */ }
          }

          try {
            const attempt2 = await doPost();
            // allow server to supply a token in second response
            const newTok = extractTokenFromResponse(attempt2.resp, attempt2.parsed);
            if (newTok) csrfToken = newTok;

            if (feedbackEl) feedbackEl.textContent = attempt2.parsed.message || (attempt2.parsed.success ? 'Hotovo' : 'Chyba při odesílání.');
            if (attempt2.parsed.success) form.reset();

            // consume token to force fresh on next submit (safety)
            consumeCsrf();
          } catch (err2) {
            console.error('Retry after CSRF fetch failed', err2);
            if (feedbackEl) feedbackEl.textContent = 'Chyba při odesílání (CSRF). Zkuste obnovit stránku.';
            consumeCsrf();
          }
        } else {
          // success / normal flow
          // if server returned new token in response, keep it for next submits
          const newTok = extractTokenFromResponse(attempt1.resp, attempt1.parsed);
          if (newTok) csrfToken = newTok;

          if (feedbackEl) feedbackEl.textContent = attempt1.parsed.message || (attempt1.parsed.success ? 'Hotovo' : 'Chyba při odesílání.');
          if (attempt1.parsed.success) form.reset();

          // consume token because server likely marks it used
          consumeCsrf();
        }
      } catch (err) {
        console.error('Send failed', err);
        if (feedbackEl) feedbackEl.textContent = 'Chyba při odesílání, zkontrolujte konzoli.';
      } finally {
        submitting = false;
        if (btnText) btnText.style.display = 'inline';
        if (spinner) spinner.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
      }
    }, { passive: false });
  });
})();