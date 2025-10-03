// /eshop/js/cart-ajax-flash.js
// AJAX add-to-cart handler + epic flash widget
// - intercepts forms with class .modal-add-to-cart-form or data-ajax="cart"
// - sends FormData via fetch, shows Slovak flash, updates UI via event 'cart:updated'
// - requires /eshop/css/flash.css from previous instructions for full styling
(function () {
  'use strict';

  const AUTO_DISMISS_MS = 5000;
  const CONTAINER_ID = 'flash-container-js';
  const STYLE_ID = 'flash-styles-js';

  // Ensure there's a (possibly empty) style marker so we don't inject duplicates.
  function ensureStyles() {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    // keep empty: preferred styling comes from /eshop/css/flash.css
    style.appendChild(document.createTextNode(''));
    document.head.appendChild(style);
  }

  function ensureContainer() {
    let c = document.getElementById(CONTAINER_ID);
    if (c) return c;
    c = document.createElement('div');
    c.id = CONTAINER_ID;
    c.className = 'flash-widget-container';
    document.body.appendChild(c);
    return c;
  }

  // create epic flash, returns {el, dismiss}
  function createFlash(message, { type = 'info', timeout = AUTO_DISMISS_MS } = {}) {
    ensureStyles();
    const container = ensureContainer();

    const el = document.createElement('div');
    el.className = 'flash-widget flash-' + (type === 'success' ? 'success' : type === 'error' ? 'error' : 'info');
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');

    // icon
    const icon = document.createElement('div');
    icon.className = 'flash-icon';
    icon.innerHTML = (type === 'success') ? '✓' : (type === 'error') ? '✕' : 'ℹ';
    el.appendChild(icon);

    // body
    const body = document.createElement('div');
    body.className = 'flash-body';
    body.innerHTML = String(message);
    el.appendChild(body);

    // meta line (subtotal or details)
    const meta = document.createElement('div');
    meta.className = 'flash-meta';
    el.appendChild(meta);

    // dismiss
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'flash-dismiss';
    btn.setAttribute('aria-label', 'Zavrieť správu');
    btn.innerHTML = '✕';
    el.appendChild(btn);

    // progress
    const progress = document.createElement('div');
    progress.className = 'flash-progress';
    const progressInner = document.createElement('i');
    progress.appendChild(progressInner);
    el.appendChild(progress);

    container.appendChild(el);

    // show animation
    requestAnimationFrame(() => el.classList.add('show'));

    let dismissed = false;
    let dismissTimer = null;
    let startTime = Date.now();
    let remaining = timeout;

    // start progress via CSS transition (duration controlled here)
    progressInner.style.transform = 'scaleX(1)';
    progressInner.style.transitionProperty = 'transform';
    progressInner.style.transitionTimingFunction = 'linear';
    progressInner.style.transitionDuration = timeout + 'ms';

    // trigger the animation to scaleX(0)
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        progressInner.style.transform = 'scaleX(0)';
      });
    });

    dismissTimer = setTimeout(dismiss, timeout + 60);

    function dismiss() {
      if (dismissed) return;
      dismissed = true;
      clearTimeout(dismissTimer);
      el.classList.remove('show');
      setTimeout(() => { try { container.removeChild(el); } catch (_) {} }, 260);
    }

    // pause / resume to preserve remaining time on hover
    el.addEventListener('mouseenter', () => {
      if (dismissed) return;
      const elapsed = Date.now() - startTime;
      remaining = Math.max(0, timeout - elapsed);
      // freeze progress
      const frac = Math.max(0, Math.min(1, elapsed / timeout));
      const currentScale = 1 - frac;
      progressInner.style.transitionProperty = 'none';
      progressInner.style.transform = 'scaleX(' + currentScale + ')';
      clearTimeout(dismissTimer);
    });

    el.addEventListener('mouseleave', () => {
      if (dismissed) return;
      if (remaining <= 0) { dismiss(); return; }
      progressInner.style.transitionProperty = 'transform';
      progressInner.style.transitionTimingFunction = 'linear';
      progressInner.style.transitionDuration = remaining + 'ms';
      requestAnimationFrame(() => {
        progressInner.style.transform = 'scaleX(0)';
        startTime = Date.now();
        dismissTimer = setTimeout(dismiss, remaining + 60);
      });
    });

    btn.addEventListener('click', dismiss);

    return { el, dismiss };
  }

  function setSubmitting(btn, isSubmitting) {
    if (!btn) return;
    if (isSubmitting) {
      btn.setAttribute('aria-busy', 'true');
      btn.disabled = true;
      btn.dataset._origText = btn.innerHTML;
      btn.innerHTML = '...';
    } else {
      btn.removeAttribute('aria-busy');
      btn.disabled = false;
      if (btn.dataset._origText) btn.innerHTML = btn.dataset._origText;
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>\"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#39;' })[c];
    });
  }

  // main delegated submit handler (replace this whole function if needed)
  async function onSubmit(e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    // Only intercept our cart forms
    if (!form.classList.contains('modal-add-to-cart-form') && form.dataset.ajax !== 'cart') {
      return;
    }

    e.preventDefault();

    let submitBtn = (e.submitter && e.submitter instanceof HTMLElement) ? e.submitter : form.querySelector('button[type="submit"], input[type="submit"]');

    setSubmitting(submitBtn, true);

    try {
      const action = form.getAttribute('action') || window.location.pathname;
      const method = (form.getAttribute('method') || 'POST').toUpperCase();

      const fd = new FormData(form);

      const headers = { 'X-Requested-With': 'XMLHttpRequest' };

      const fetchOpts = {
        method: method,
        credentials: 'same-origin',
        headers: headers,
        body: fd
      };

      const res = await fetch(action, fetchOpts);

      const text = await res.text();
      let data = null;
      try { data = text ? JSON.parse(text) : null; } catch (err) { data = null; }

      if (!res.ok) {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : `Server returned ${res.status}`;
        createFlash(`<strong>Chyba:</strong> ${escapeHtml(msg)}`, { type: 'error' });
        setSubmitting(submitBtn, false);
        return;
      }

      if (!data) {
        createFlash('Neočakovaná odpoveď serveru.', { type: 'error' });
        setSubmitting(submitBtn, false);
        return;
      }

      // Slovak plural helper
      function pluralPolozka(n) {
        n = Math.abs(Number(n) || 0);
        if (n === 1) return 'položka';
        if (n >= 2 && n <= 4) return 'položky';
        return 'položiek';
      }

      if (data.ok) {
        const cart = data.cart || null;
        const qty = cart ? (Number(cart.items_total_qty || cart.items_count || 1)) : 1;
        const word = pluralPolozka(qty);
        const currency = cart ? (cart.currency || '') : '';
        const subtotal = cart && cart.subtotal ? ` — ${escapeHtml(String(cart.subtotal))} ${escapeHtml(currency)}` : '';

        const message = `Pridané do košíka (${qty} ${word})${subtotal}`;

        const f = createFlash(message, { type: 'success' });
        if (f && f.el) {
          const metaNode = f.el.querySelector('.flash-meta');
          if (metaNode && cart) {
            metaNode.textContent = `${qty} ${word} — ${cart.subtotal || ''} ${cart.currency || ''}`.trim();
          }
        }
        // --- CSRF token update (drop-in) ---
        if (data && data.csrf_token) {
          try {
            // 1) update any hidden inputs inside this form (and other forms on page)
            document.querySelectorAll('input[name="csrf"], input[name="_csrf"], input[name="csrf_token"]').forEach(i => {
              try { i.value = data.csrf_token; } catch (_) {}
            });

            // 2) update meta tag if present (<meta name="csrf-token" content="...">)
            const meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf"]');
            if (meta) try { meta.setAttribute('content', data.csrf_token); } catch (_) {}

            // 3) optionally expose globally for quick JS access
            try { window.__csrfToken = data.csrf_token; } catch (_) {}
          } catch (_) {}
        }
        document.dispatchEvent(new CustomEvent('cart:updated', { detail: { cart } }));
        // pokud formulář obsahoval buy_now, přesměruj uživatele na checkout
        try {
        if (form.querySelector('input[name="buy_now"]') && res.ok) {
            // malá prodleva aby se flash zobrazil (volitelně)
            setTimeout(() => {
            window.location.href = '/eshop/checkout';
            }, 250);
            return; // už nepotřebujeme dál nic dělat (submit finished)
        }
        } catch (err) {
        // ignore
        }
        // --- NOVÉ: aktualizovat header badge cez globálny CartBadge ---
        try {
        // preferujeme celkový počet kusov v košíku, fallback na počet distinct položiek
        const newCount = cart ? (Number(cart.items_total_qty ?? cart.items_count ?? 0)) : 0;
        if (window.CartBadge && typeof window.CartBadge.update === 'function') {
            window.CartBadge.update(newCount);
        } else {
            // pre istotu nastavíme data-atribút (server-side render môže čítať z toho)
            const cartLink = document.querySelector('[data-header-link="cart"]');
            if (cartLink) cartLink.dataset.headerCartCount = String(newCount);
        }
        } catch (err) {
        if (window.console && typeof window.console.warn === 'function') {
            console.warn('Failed to update CartBadge:', err);
        }
        }
        // close modal robustly:
        const modal = form.closest('.modal');
        if (modal) {
          const closeBtn = modal.querySelector('.modal-close');
          if (closeBtn) {
            try { closeBtn.click(); } catch (_) {}
          }
          try { modal.dispatchEvent(new CustomEvent('modal:close', { bubbles: true })); } catch (_) {}
          ['open','is-open','show','active'].forEach(c => modal.classList.remove(c));
          try { modal.setAttribute('aria-hidden', 'true'); } catch (_) {}
          const overlay = modal.querySelector('.modal-overlay');
          if (overlay) overlay.setAttribute('aria-hidden', 'true');
        }

      } else {
        const msg = data.message || data.error || 'Neznáma chyba';
        createFlash(escapeHtml(msg), { type: 'error' });
      }

    } catch (err) {
      console.error(err);
      createFlash('Chyba siete alebo vnútorná chyba.', { type: 'error' });
    } finally {
      setSubmitting(submitBtn, false);
    }
  }

  // Delegovaný handler pro Buy Now tlačítko uvnitř formuláře
    document.addEventListener('click', function (e) {
    const btn = e.target.closest && e.target.closest('[data-action="buy-now"]');
    if (!btn) return;

    // najdeme nejbližší formulář
    const form = btn.closest('form');
    if (!form) return;

    // pokud už tam je input buy_now, nezakládáme duplicitní
    let buyInput = form.querySelector('input[name="buy_now"]');
    if (!buyInput) {
        buyInput = document.createElement('input');
        buyInput.type = 'hidden';
        buyInput.name = 'buy_now';
        buyInput.value = '1';
        form.appendChild(buyInput);
    } else {
        buyInput.value = '1';
    }

    // simulujeme kliknutí na submit (to spustí onSubmit, který už posílá AJAX)
    const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (submitBtn) {
        // použijeme form.submit() pokud nechceme UI submitter behavior
        try {
        submitBtn.click();
        } catch (err) {
        // fallback
        form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    } else {
        // žádné submit tlačítko => použijeme requestSubmit / submit
        form.requestSubmit ? form.requestSubmit() : form.submit();
    }
    });

  // attach delegated submit listener (capture so we see submits early)
  document.addEventListener('submit', onSubmit, true);

  // expose helper
  window.__createFlash = function (message, opts) {
    return createFlash(message, opts || {});
  };

})();