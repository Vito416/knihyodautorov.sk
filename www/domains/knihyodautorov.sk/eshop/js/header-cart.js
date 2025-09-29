// /eshop/js/header-cart.js
document.addEventListener('DOMContentLoaded', () => {
  const cartLink = document.querySelector('[data-header-link="cart"]');
  let badge = cartLink?.querySelector('[data-header-badge]');

  function ensureBadge() {
    if (badge) return badge;
    if (!cartLink) return null;
    badge = cartLink.querySelector('[data-header-badge]');
    if (badge) return badge;

    // vytvoříme základní badge pokud neexistuje
    const el = document.createElement('span');
    el.className = 'header_cart-badge header_cart-badge--empty visually-hidden';
    el.setAttribute('data-header-badge', '0');
    el.setAttribute('aria-hidden', 'true');
    el.textContent = '0';
    cartLink.appendChild(el);
    badge = el;
    return badge;
  }

  // Helper na update — druhý parametr doPulse (default false)
  function updateCartCount(count, doPulse = false) {
    if (!cartLink) return;
    const b = ensureBadge();
    if (!b) return;

    count = parseInt(count, 10) || 0;
    b.textContent = String(count);
    b.dataset.headerBadge = String(count);

    if (count > 0) {
      b.classList.remove('header_cart-badge--empty', 'visually-hidden');
      b.removeAttribute('aria-hidden');
      b.setAttribute('role', 'status');
      b.setAttribute('aria-live', 'polite');
      b.setAttribute('aria-atomic', 'true');
      cartLink.setAttribute('title', `Košík, ${count} položiek`);
      cartLink.setAttribute('aria-label', `Košík, ${count} položiek`);
    } else {
      b.textContent = '0';
      b.classList.add('header_cart-badge--empty', 'visually-hidden');
      b.setAttribute('aria-hidden', 'true');
      cartLink.setAttribute('title', 'Košík');
      cartLink.setAttribute('aria-label', 'Košík');
    }

    // pulse animation (jen pokud explicitně požadováno)
    if (doPulse) {
      try {
        b.classList.remove('pulse');
        // reflow pro retrigger
        void b.offsetWidth;
        b.classList.add('pulse');
        setTimeout(() => b.classList.remove('pulse'), 900);
      } catch (err) {
        if (window.console && typeof window.console.warn === 'function') {
          console.warn('Cart badge pulse failed', err);
        }
      }
    }
  }

  // Exponujeme do window pro volání odkudkoliv (např. po přidání položky do košíku AJAXem)
  window.CartBadge = {
    update: (count, doPulse = false) => updateCartCount(count, doPulse)
  };

  // Nasloucháme události cart:updated — zde chceme pulzovat
  document.addEventListener('cart:updated', (e) => {
    const cart = e?.detail?.cart ?? null;
    const newCount = cart ? Number(cart.items_total_qty ?? cart.items_count ?? 0) : 0;
    if (window.CartBadge && typeof window.CartBadge.update === 'function') {
      window.CartBadge.update(newCount, true);
    } else {
      // fallback přímo
      updateCartCount(newCount, true);
    }
  });

  // Pokud server poslal počáteční stav — bez pulse
  const initialCount = cartLink?.dataset.headerCartCount;
  if (initialCount !== undefined) {
    updateCartCount(initialCount, false);
  }

// ihned po načtení načti aktuální košík
fetch('/eshop/cart_mini', { credentials: 'same-origin' })
  .then(r => r.ok ? r.json() : Promise.reject('cart_get error'))
  .then(json => {
    const count = Number(json?.total_count ?? 0);
    updateCartCount(count, false);
  })
  .catch(err => console.warn('Init cart count error', err));
});