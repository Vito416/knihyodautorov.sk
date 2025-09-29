// /eshop/js/checkout-submit.js
(function () {
  'use strict';

  const submitBtn = document.getElementById('checkout-submit');
  if (!submitBtn) return;

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

  async function onClick(e) {
    e.preventDefault();
    const btn = submitBtn;
    lock(btn);

    try {
      // collect billing data from form fields
      const form = document.getElementById('checkout-form');
      if (!form) {
        window.__createFlash && window.__createFlash('Formulár nenájdený', { type: 'error' });
        unlock(btn);
        return;
      }

      const bill_full_name = (form.querySelector('#bill_full_name') || {}).value || '';
      const email = (form.querySelector('#email') || {}).value || '';
      const bill_street = (form.querySelector('#bill_street') || {}).value || '';
      const bill_city = (form.querySelector('#bill_city') || {}).value || '';
      const bill_zip = (form.querySelector('#bill_zip') || {}).value || '';
      const bill_country = (form.querySelector('#bill_country') || {}).value || '';

      // cart snapshot provided server-side in window.__checkoutCart
      const cart = Array.isArray(window.__checkoutCart) ? window.__checkoutCart : [];

      const payload = {
        cart: cart.map(item => {
          // normalize item shape
          return {
            book_id: Number(item.book_id || item.id || item.bookId || 0),
            qty: Number(item.qty || item.quantity || 1),
          };
        }),
        bill_full_name: String(bill_full_name),
        email: String(email),
        bill_street: String(bill_street),
        bill_city: String(bill_city),
        bill_zip: String(bill_zip),
        bill_country: String(bill_country),
        // optional: client-supply idempotency key (helps retrying)
        idempotency_key: (window.__clientIdempotencyKey = window.__clientIdempotencyKey || (Math.random().toString(36).slice(2) + Date.now().toString(36)))
      };

      const headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      };

      // include CSRF header if available
      if (window.__csrfToken) {
        headers['X-CSRF-Token'] = window.__csrfToken;
      }

      const res = await fetch(window.__orderSubmitUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify(payload)
      });

      let data = null;
      try {
        const txt = await res.text();
        data = txt ? JSON.parse(txt) : null;
      } catch (err) {
        data = null;
      }

      if (!res.ok) {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : `Server returned ${res.status}`;
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
      unlock(btn);
    }
  }

  submitBtn.addEventListener('click', onClick);
})();