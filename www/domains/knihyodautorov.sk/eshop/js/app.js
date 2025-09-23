(function () {
  'use strict';

  // ---------- small utilities ----------
  const qs = (sel, ctx = document) => ctx.querySelector(sel);
  const qsa = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
  const on = (el, ev, cb) => el && el.addEventListener(ev, cb);

  const debounce = (fn, wait = 250) => {
    let t = null;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  };

  const getCsrfToken = () => {
    const m = qs('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : null;
  };

  const safeFetch = async (input, init = {}) => {
    init.headers = init.headers || {};
    const csrf = getCsrfToken();
    if (csrf) {
      try {
        const url = new URL(input, window.location.href);
        if (url.origin === window.location.origin) {
          init.headers['X-CSRF-Token'] = csrf;
        }
      } catch {
        init.headers['X-CSRF-Token'] = csrf;
      }
    }
    if (!init.method) init.method = 'GET';
    return fetch(input, init);
  };

  // ---------- nav toggle (mobile) ----------
  const initNavToggle = () => {
    const btn = qs('#nav-toggle');
    const nav = qs('#main-nav');
    if (!btn || !nav) return;

    btn.addEventListener('click', () => {
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      nav.classList.toggle('open');

      if (nav.classList.contains('open')) {
        const first = nav.querySelector('a, button, input');
        if (first) first.focus();
      } else {
        btn.focus();
      }
    });

    document.addEventListener('keydown', ev => {
      if (ev.key === 'Escape' && nav.classList.contains('open')) {
        nav.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    });

    document.addEventListener('click', ev => {
      if (!nav.classList.contains('open')) return;
      if (!nav.contains(ev.target) && !btn.contains(ev.target)) {
        nav.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  };

  // ---------- dropdowns ----------
  const initDropdowns = () => {
    qsa('.nav-dropdown > .dropdown-toggle').forEach(btn => {
      const parent = btn.parentElement;
      const menu = parent.querySelector('.dropdown');
      if (!menu) return;

      btn.addEventListener('click', () => {
        const open = parent.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) menu.querySelector('a, button')?.focus();
      });

      btn.addEventListener('keydown', ev => {
        if (ev.key === 'ArrowDown') {
          ev.preventDefault();
          parent.classList.add('open');
          btn.setAttribute('aria-expanded', 'true');
          menu.querySelector('a, button')?.focus();
        }
      });

      const closeDropdown = ev => {
        if (!parent.classList.contains('open')) return;
        if (!parent.contains(ev.target)) {
          parent.classList.remove('open');
          btn.setAttribute('aria-expanded', 'false');
        }
      };

      document.addEventListener('click', closeDropdown);

      parent.addEventListener('keydown', ev => {
        if (ev.key === 'Escape') {
          parent.classList.remove('open');
          btn.setAttribute('aria-expanded', 'false');
          btn.focus();
        }
      });
    });
  };

  // ---------- flash messages ----------
  const initFlashMessages = () => {
    qsa('.flash-messages .flash-info, .flash-messages .flash-success, .flash-messages .flash-warning, .flash-messages .flash-error')
      .forEach(node => {

        if (!qs('.flash-dismiss', node)) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'flash-dismiss';
          btn.innerHTML = '✕';
          btn.setAttribute('aria-label', 'Zavrieť správu');
          btn.addEventListener('click', () => hideFlash(node));
          node.appendChild(btn);
        }

        if (!node.classList.contains('flash-error')) {
          setTimeout(() => hideFlash(node), 6000);
        }
      });

    const hideFlash = node => {
      if (!node) return;
      node.classList.add('flash-hide');
      setTimeout(() => {
        node.parentNode?.removeChild(node);
      }, 300);
    };
  };

  // ---------- search debounce ----------
  const initSearchDebounce = () => {
    const form = qs('.search-form');
    const input = qs('input[type="search"]', form);
    if (!form || !input) return;

    // Debounced auto-submit (optional, can be commented out)
    // input.addEventListener('input', debounce(() => {
    //   if (input.value.trim()) form.submit();
    // }, 600));
  };

  // ---------- cart badge ----------
  const initCartBadge = (options = {}) => {
    const badge = qs('.cart-badge');
    if (!badge) return;
    const url = options.endpoint || '/eshop/api/cart_count.php';
    const interval = options.interval || 30000;

    const update = async () => {
      try {
        const res = await safeFetch(url, { method: 'GET', credentials: 'same-origin' });
        if (!res.ok) return;
        const json = await res.json();
        badge.textContent = (parseInt(json.count || 0, 10) || 0) || '';
      } catch {}
    };

    update();
    const timer = setInterval(update, interval);
    return () => clearInterval(timer);
  };

  // ---------- init all ----------
  const initAll = () => {
    initNavToggle();
    initDropdowns();
    initFlashMessages();
    initSearchDebounce();
    const stopCart = initCartBadge();

    window.App = window.App || {};
    window.App.safeFetch = safeFetch;
    window.App.updateCartBadge = () => initCartBadge({ interval: 0 });
    window.App.stopCartBadge = stopCart;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

})();