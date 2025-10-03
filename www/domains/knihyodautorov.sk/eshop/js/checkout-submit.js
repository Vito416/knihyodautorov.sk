// /eshop/js/checkout-submit.js
(function () {
  'use strict';

  const submitBtn = document.getElementById('checkout-submit');
  if (!submitBtn) return;

  // CONFIG
  const TIMEOUT_MS = 10000;   // 10s fetch timeout (AbortController)
  const DEBOUNCE_MS = 500;    // ignore clicks within 500ms

  // internal debounce
  let lastClickAt = 0;

  function lock(btn) {
    btn.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    btn.dataset._origText = btn.innerHTML;
    btn.innerHTML = '...';
  }
  function unlock(btn) {
    btn.removeAttribute('aria-busy');
    btn.disabled = false;
    if (btn.dataset._origText) btn.innerHTML = btn.dataset._origText;
  }

  /**
   * Helper: provede fetch a při csrf_invalid + csrf_token provede jednou retry s novým tokenem.
   * Vrací objekt { resp, txt, data, fetchError }.
   * Používá AbortController pro timeout.
   */
  async function sendWithCsrfRetry(url, payload, headers) {
    // interní helper pro provedení fetchu a parsování odpovědi
    async function doFetch(h) {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS);

      try {
        const resp = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: h,
          body: JSON.stringify(payload),
          signal: controller.signal
        });

        clearTimeout(timeout);

        const txt = await resp.text();
        let data = null;
        try { data = txt ? JSON.parse(txt) : null; } catch (_) { data = null; }
        return { resp, txt, data, fetchError: null };
      } catch (err) {
        clearTimeout(timeout);
        return { resp: { ok: false, status: 0 }, txt: '', data: null, fetchError: err };
      }
    }

    // clone headers to avoid accidental external mutation
    const h = Object.assign({}, headers);

    // první pokus
    let attempt = await doFetch(h);

    // helper: získej CSRF token pouze z těla odpovědi (cart_add posílá token v JSON)
    function extractCsrfFromResponse(respObj) {
      if (!respObj) return null;
      if (respObj.fetchError) return null; // při síťové chybě token nečekáme
      if (respObj && respObj.data) {
        return respObj.data.csrf_token || respObj.data.csrf || respObj.data.token || null;
      }
      return null;
    }

    // pokud server vrátí csrf_invalid + csrf_token (nebo token v těle), aktualizujeme token a zkusíme jednou znovu
    const gotToken = extractCsrfFromResponse(attempt);
    const attemptError = attempt.data && attempt.data.error;
    if ((attemptError === 'csrf_invalid' || (attempt.resp && attempt.resp.status === 403)) && gotToken && !window.__csrfRetryDone) {
      window.__csrfRetryDone = true; // retry jen jednou pro tuto stránku/klik
      try {
        const newToken = gotToken;
        // nastav globální token
        window.__csrfToken = newToken;

        // aktualizuj payload, aby druhý pokus poslal nový token také v těle
        try { if (payload && typeof payload === 'object') payload.csrf = newToken; } catch (_) {}

        // aktualizuj hidden inputs pokud existují
        document.querySelectorAll('input[name="csrf"], input[name="_csrf"], input[name="csrf_token"]').forEach(i => {
          try { i.value = newToken; } catch (_) {}
        });

        // aktualizuj meta tag pokud existuje
        const meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf"]');
        if (meta) try { meta.setAttribute('content', newToken); } catch (_) {}

      } catch (e) { console.warn('CSRF retry prepare failed', e); }

      // druhý pokus (doFetch znovu zavolá JSON.stringify(payload) -> nový payload bude zahrnovat csrf)
      attempt = await doFetch(h);
    }

    return { resp: attempt.resp, txt: attempt.txt, data: attempt.data, fetchError: attempt.fetchError };
  }

  // ignore extra clicks if button already busy or within debounce window
  async function onClick(e) {
    e.preventDefault();

    const now = Date.now();
    if (now - lastClickAt < DEBOUNCE_MS) return;
    lastClickAt = now;

    // pokud už je tlačítko zaneprázdněné, ignorujeme další kliky
    if (submitBtn.disabled || submitBtn.getAttribute('aria-busy') === 'true') return;

    const btn = submitBtn;
    lock(btn);

    // povolíme retry pro tento klik
    window.__csrfRetryDone = false;

    // pokud nastavíme skipUnlock = true, v finally tlačítko neodemkneme
    let skipUnlock = false;

    try {
      const form = document.getElementById('checkout-form');
      if (!form) { console.error('Formulár nenájdený'); unlock(btn); return; }

      const bill_full_name = (form.querySelector('#bill_full_name') || {}).value || '';
      const email = (form.querySelector('#email') || {}).value || '';
      const bill_street = (form.querySelector('#bill_street') || {}).value || '';
      const bill_city = (form.querySelector('#bill_city') || {}).value || '';
      const bill_zip = (form.querySelector('#bill_zip') || {}).value || '';
      const bill_country = (form.querySelector('#bill_country') || {}).value || '';

      // cart snapshot provided server-side in window.__checkoutCart
      const cart = Array.isArray(window.__checkoutCart) ? window.__checkoutCart : [];
// ensure CSRF token is up-to-date from hidden input / meta tag
window.__csrfToken = (
    window.__csrfToken ||
    document.querySelector('input[name="csrf"], input[name="_csrf"], input[name="csrf_token"]')?.value ||
    document.querySelector('meta[name="csrf-token"], meta[name="csrf"]')?.getAttribute('content') ||
    null
);
console.log('--- CSRF TOKEN before submit ---', window.__csrfToken);
const cartPayload = (Array.isArray(window.__checkoutCart) ? window.__checkoutCart : [])
  .map(item => {
    const id = Number(item.book_id ?? item.id ?? item.bookId ?? 0);
    const qty = Number(item.qty ?? item.quantity ?? 1);
    return { book_id: id, qty };
  })
  .filter(item => item.book_id > 0 && item.qty > 0); // jen validní položky
      
      const payload = Object.assign({
        cart: cartPayload,
        bill_full_name: String(bill_full_name),
        email: String(email),
        bill_street: String(bill_street),
        bill_city: String(bill_city),
        bill_zip: String(bill_zip),
        bill_country: String(bill_country),
        idempotency_key: (window.__clientIdempotencyKey = window.__clientIdempotencyKey || (Math.random().toString(36).slice(2) + Date.now().toString(36)))
      }, (window.__csrfToken ? { csrf: window.__csrfToken } : {}));
      console.log('--- PAYLOAD to checkout.php ---', payload);
      const headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      };

      if (!window.__orderSubmitUrl) {
        window.__createFlash ? window.__createFlash('Konfiguracija chýba: order submit URL', { type: 'error' }) : alert('Missing order submit URL');
        unlock(btn);
        return;
      }

      // --- zde použijeme helper s jedním automatickým retry ---
      const result = await sendWithCsrfRetry(window.__orderSubmitUrl, payload, headers);
      const res = result.resp;
      const txt = result.txt;
      const data = result.data;
      const fetchError = result.fetchError;
      console.log('--- RESPONSE from checkout.php ---', data, 'status:', res?.status);
      // pokud byla síťová chyba (fetchError), ukaž uživateli smysluplnou zprávu
      if (fetchError) {
        console.error('Network/fetch error during order submit:', fetchError);
        const msg = (fetchError.name === 'AbortError') ? 'Čas vypršel při komunikaci so serverom. Skúste znova.' : 'Chyba siete – skúste to znova.';
        window.__createFlash ? window.__createFlash(msg, { type: 'error' }) : alert(msg);
        unlock(btn);
        return;
      }

      // pokud server nový token poslal mimo retry helper (defenzivně), aktualizuj klienta
      if (data && data.csrf_token) {
        try {
          document.querySelectorAll('input[name="csrf"], input[name="_csrf"], input[name="csrf_token"]').forEach(i => {
            try { i.value = data.csrf_token; } catch (_) {}
          });
          const meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf"]');
          if (meta) try { meta.setAttribute('content', data.csrf_token); } catch (_) {}
          try { window.__csrfToken = data.csrf_token; } catch (_) {}
        } catch (_) {}
      }
console.log('--- CURRENT CSRF TOKEN ---', window.__csrfToken);
      if (!res || !res.ok) {
        const status = (res && res.status) ? res.status : 0;
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : `Server returned ${status}`;
        window.__createFlash ? window.__createFlash(msg, { type: 'error' }) : alert(msg);
        unlock(btn);
        return;
      }

      if (!data) {
        window.__createFlash ? window.__createFlash('Neočakávaná odpoveď serveru', { type: 'error' }) : alert('No response');
        unlock(btn);
        return;
      }

      if (data.ok) {
        // success path: redirect if redirect_url present
        if (data.redirect_url) {
          // small UX flash optional
          window.__createFlash && window.__createFlash('Presmerovávam na platobnú bránu…', { type: 'success', timeout: 1200 });

          // Prevent unlocking button right before redirect (better UX)
          skipUnlock = true;

          // ensure slight delay so flash is perceptible
          setTimeout(() => {
            window.location.href = data.redirect_url;
          }, 300);
          return;
        } else {
          // no redirect — treat as non-fatal error
          window.__createFlash ? window.__createFlash('Platobná brána nevrátila URL', { type: 'error' }) : alert('no redirect');
        }
      } else {
        // ok:false -> show message
        const msg = data.message || data.error || 'Neznáma chyba pri platbe';
        window.__createFlash ? window.__createFlash(msg, { type: 'error' }) : alert(msg);
      }
    } catch (err) {
      console.error(err);
      window.__createFlash ? window.__createFlash('Chyba siete alebo vnútorná chyba', { type: 'error' }) : alert(err.message || err);
    } finally {
      if (!skipUnlock) unlock(btn);
    }
  }

  submitBtn.addEventListener('click', onClick);
})();