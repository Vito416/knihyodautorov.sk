/* header.js — robustní integrace pro header.php (patch)
   Přidá hlavičku mini-košíku, remove/clear akce a opraví "empty" zobrazení. */
(function () {
  'use strict';

  /* -------------------- helpers -------------------- */
  const $ = (sel, ctx = document) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from((ctx || document).querySelectorAll(sel));
  const on = (el, evt, selOrHandler, handler) => {
    if (!el) return () => {};
    if (typeof selOrHandler === 'function') {
      el.addEventListener(evt, selOrHandler);
      return () => el.removeEventListener(evt, selOrHandler);
    }
    const selector = selOrHandler; const fn = handler;
    const deleg = (e) => {
      let t = e.target;
      while (t && t !== el) {
        if (t.matches && t.matches(selector)) { fn.call(t, e); break; }
        t = t.parentElement;
      }
    };
    el.addEventListener(evt, deleg);
    return () => el.removeEventListener(evt, deleg);
  };
  const debounce = (fn, wait = 220) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn.apply(null, args), wait); }; };
  const rafPulse = (el) => { if (!el) return; el.classList.remove('pulse'); void el.offsetWidth; el.classList.add('pulse'); setTimeout(()=>el.classList.remove('pulse'), 900); };
  const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  // small POST helper (JSON) with CSRF auto-injection and form fallback
  const postJson = (url, payload = {}) => {
    const csrf = (document.querySelector('input[name="csrf"], input[name="_csrf"], input[name="csrfToken"]') || {}).value
              || document.querySelector('meta[name="csrf-token"], meta[name="csrf"]')?.getAttribute('content')
              || window.__csrfToken || null;

    // try JSON first (include CSRF header for server)
    const headers = { 'Content-Type': 'application/json' };
    if (csrf) headers['X-CSRF-Token'] = csrf;

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify(payload)
    }).then(r => {
      if (!r.ok) return r.text().then(t => Promise.reject(new Error(t || r.statusText)));
      return r.json().catch(()=>({}));
    }).then(json => {
      // update token if server returned new one
      if (json && json.csrfToken) {
        document.querySelectorAll('input[name="csrf"], input[name="_csrf"], input[name="csrfToken"]').forEach(i => {
          try { i.value = json.csrfToken; } catch(_) {}
        });
        const meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf"]');
        if (meta) try { meta.setAttribute('content', json.csrfToken); } catch(_) {}
        try { window.__csrfToken = json.csrfToken; } catch(_) {}
      }
      return json;
    }).catch(err => {
      // fallback: form-encoded (send csrf in form)
      try {
        const fd = new FormData();
        Object.keys(payload || {}).forEach(k => fd.append(k, payload[k]));
        if (csrf) fd.set('csrf', csrf);
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(r => {
            if (!r.ok) return r.text().then(t => Promise.reject(new Error(t || r.statusText)));
            return r.json().catch(()=>({}));
          }).then(json => {
            if (json && json.csrfToken) {
              document.querySelectorAll('input[name="csrf"], input[name="_csrf"], input[name="csrfToken"]').forEach(i => {
                try { i.value = json.csrfToken; } catch(_) {}
              });
              const meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf"]');
              if (meta) try { meta.setAttribute('content', json.csrfToken); } catch(_) {}
              try { window.__csrfToken = json.csrfToken; } catch(_) {}
            }
            return json;
          });
      } catch (e) {
        return Promise.reject(err);
      }
    });
  };

  // Robustní extraktor počtu — umísti to do vyššího scope (před CartBadge IIFE)
  // (zajistí, že ho uvidí i fetchMiniCart()/clearCart() atd.)
  function extractCount(payload) {
    if (payload == null) return 0;

    if (typeof payload === 'number' && Number.isFinite(payload)) return Math.max(0, Math.floor(payload));
    if (typeof payload === 'string') {
      const n = Number(payload);
      if (!Number.isNaN(n) && Number.isFinite(n)) return Math.max(0, Math.floor(n));
    }

    const obj = payload;

    const tryNum = (v) => (v === undefined || v === null) ? undefined : (Number(v) || 0);

    const candidates = [
      tryNum(obj.total_count),
      tryNum(obj.items_total_qty),
      tryNum(obj.items_count),
      tryNum(obj.count),
      tryNum(obj.total)
    ];
    for (const c of candidates) {
      if (c !== undefined && !Number.isNaN(c)) return Math.max(0, Math.floor(c));
    }

    if (obj.cart && typeof obj.cart === 'object') {
      const fromCart = extractCount(obj.cart);
      if (fromCart > 0) return fromCart;
    }

    if (Array.isArray(obj.items) && obj.items.length) {
      const sum = obj.items.reduce((s, it) => {
        if (!it) return s;
        const q = Number(it.qty ?? it.quantity ?? it.amount ?? it.qty_sum ?? 0);
        return s + (Number.isFinite(q) ? Math.max(0, q) : 0);
      }, 0);
      if (sum > 0) return sum;
      return obj.items.length;
    }

    if (Array.isArray(payload)) {
      const sum = payload.reduce((s, it) => {
        if (!it) return s;
        const q = Number(it.qty ?? it.quantity ?? 0);
        return s + (Number.isFinite(q) ? Math.max(0, q) : 0);
      }, 0);
      if (sum > 0) return sum;
      return payload.length;
    }

    return 0;
  }

  /* ### Unified CartBadge manager ### */
  (function () {
    const getBadgeEl = () => document.querySelector('[data-header-badge]') || document.querySelector('[data-header-badge]');

    // bezpečný update DOMu jedno místo
    function applyBadge(count, { pulse = false } = {}) {
      const badge = getBadgeEl();
      const cartLink = document.querySelector('[data-header-link="cart"]');

      // pokud neexistuje badge -> vytvoř ji
      let b = badge;
      if (!b && cartLink) {
        const el = document.createElement('span');
        el.className = 'header_cart-badge header_cart-badge--empty visually-hidden';
        el.setAttribute('data-header-badge', '0');
        el.setAttribute('aria-hidden', 'true');
        el.textContent = '0';
        cartLink.appendChild(el);
        b = el;
      }
      if (!b) return;

      const n = Number(count) || 0;
      b.textContent = String(n > 0 ? n : '0');
      b.setAttribute('data-header-badge', String(n));
      b.dataset.headerBadge = String(n);

      if (n > 0) {
        b.classList.remove('header_cart-badge--empty', 'visually-hidden');
        b.removeAttribute('aria-hidden');
        b.setAttribute('role','status');
        b.setAttribute('aria-live','polite');
        b.setAttribute('aria-atomic','true');
        if (cartLink) {
          cartLink.setAttribute('data-header-cart-count', String(n));
          cartLink.setAttribute('aria-label', `Košík, ${n} položiek`);
          cartLink.setAttribute('title', `Košík, ${n} položiek`);
        }
      } else {
        b.classList.add('header_cart-badge--empty', 'visually-hidden');
        b.setAttribute('aria-hidden','true');
        if (cartLink) {
          cartLink.removeAttribute('data-header-cart-count');
          cartLink.setAttribute('aria-label','Košík');
          cartLink.setAttribute('title','Košík');
        }
      }

      if (pulse) {
        try { b.classList.remove('pulse'); void b.offsetWidth; b.classList.add('pulse'); setTimeout(()=> b.classList.remove('pulse'), 900); } catch(_) {}
      }
    }

    // in-flight protection: pouze poslední odpověď se uplatní
    let lastReqId = 0;
    async function fetchAndApply({ pulse = false } = {}) {
      const reqId = ++lastReqId;
      try {
        const res = await fetch('/eshop/cart_mini', { credentials: 'same-origin' });
        if (reqId !== lastReqId) return; // stale
        if (!res.ok) {
          console.warn('Cart fetch failed', res.status);
          return;
        }
        const json = await res.json().catch(()=>null);
        if (reqId !== lastReqId) return;
        const cnt = extractCount(json);
        applyBadge(cnt, { pulse });
        // emit event for backward compatibility
        try { document.dispatchEvent(new CustomEvent('cart:updated', { detail: { cart: json || null } })); } catch(_) {}
      } catch (err) {
        if (reqId !== lastReqId) return;
        console.warn('Cart fetch error', err);
      }
    }

    // veřejné API (jediné místo které používej v dalších skriptech)
    window.CartBadge = window.CartBadge || {
      update: (count, doPulse = false) => applyBadge(count, { pulse: !!doPulse }),
      fetchAndUpdate: (opts = {}) => fetchAndApply(opts),
      // helper pro start polling — vrátí stop funkci
      startPolling: (interval = 30_000) => {
        let stopped = false;
        const tick = async () => { if (stopped) return; await fetchAndApply({ pulse: false }); if (!stopped) timer = setTimeout(tick, interval); };
        let timer = setTimeout(tick, 0);
        return () => { stopped = true; clearTimeout(timer); };
      }
    };

    // INITIAL: použij server-rendered data-header-cart-count pokud je, jinak udělej fetch
    try {
      const cartLink = document.querySelector('[data-header-link="cart"]');
      const initCountAttr = cartLink?.getAttribute('data-header-cart-count');
      if (typeof initCountAttr !== 'undefined' && initCountAttr !== null) {
        window.CartBadge.update(Number(initCountAttr) || 0, false);
        // pokud je >0, synchronizuj kompletní stav (nepovinné)
        if ((Number(initCountAttr) || 0) > 0) window.CartBadge.fetchAndUpdate();
      } else {
        // žádný server-side count => načti
        window.CartBadge.fetchAndUpdate();
      }
    } catch (_) {}
  })();

  /* -------------------- THEME -------------------- */
  const THEME_KEY = 'epic:theme';
  const themeToggle = $('[data-header-action="theme-toggle"]');
  const setTheme = (t) => {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem(THEME_KEY, t); } catch (_) {}
    if (themeToggle) themeToggle.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
  };
  const initTheme = () => {
    try {
      const stored = localStorage.getItem(THEME_KEY);
      if (stored) { setTheme(stored); return; }
    } catch (_) {}
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    setTheme(prefersDark ? 'dark' : 'light');
  };
  if (themeToggle) themeToggle.addEventListener('click', ()=> {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    setTheme(current === 'dark' ? 'light' : 'dark');
  });
  initTheme();

  /* -------------------- MOBILE NAV -------------------- */
  const mobileNav = document.getElementById('header_nav_mobile');
  const navToggle = document.querySelector('[data-header-toggle="nav"]');
  let lastFocused = null;
  const openMobileNav = () => {
    if (!mobileNav || !navToggle) return;
    mobileNav.setAttribute('aria-hidden','false'); navToggle.setAttribute('aria-expanded','true');
    lastFocused = document.activeElement;
    const first = mobileNav.querySelector('a,button,input,textarea,select'); if (first) first.focus();
    document.body.style.overflow = 'hidden';
  };
  const closeMobileNav = () => {
    if (!mobileNav || !navToggle) return;
    mobileNav.setAttribute('aria-hidden','true'); navToggle.setAttribute('aria-expanded','false');
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
    document.body.style.overflow = '';
  };
  if (navToggle) navToggle.addEventListener('click', ()=> {
    const open = navToggle.getAttribute('aria-expanded') === 'true';
    (open ? closeMobileNav : openMobileNav)();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMobileNav(); });

  /* -------------------- DROPDOWNS (categories) — robust -------------------- */
  const categoryButtons = $$('[data-header-toggle="categories"]');
  const categoryMap = new Map();

  categoryButtons.forEach((btn) => {
    const panel = document.getElementById(btn.getAttribute('aria-controls'));
    if (!panel) return;
    categoryMap.set(btn, panel);

    const setOpen = (v) => { btn.setAttribute('aria-expanded', v ? 'true' : 'false'); panel.setAttribute('aria-hidden', v ? 'false' : 'true'); };
    setOpen(false);

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = btn.getAttribute('aria-expanded') === 'true';
      categoryMap.forEach((p, b) => { b.setAttribute('aria-expanded','false'); p.setAttribute('aria-hidden','true'); });
      if (!isOpen) {
        setOpen(true);
        const first = panel.querySelector('[role="menuitem"]');
        if (first) first.focus();
      } else {
        setOpen(false);
      }
    });

    btn.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        categoryMap.forEach((p, b) => { b.setAttribute('aria-expanded','false'); p.setAttribute('aria-hidden','true'); });
        setOpen(true);
        const first = panel.querySelector('[role="menuitem"]');
        if (first) first.focus();
      }
    });
  });

  document.addEventListener('click', (ev) => {
    categoryMap.forEach((panel, btn) => {
      if (!panel.contains(ev.target) && ev.target !== btn) {
        btn.setAttribute('aria-expanded','false');
        panel.setAttribute('aria-hidden','true');
      }
    });
  });

  /* -------------------- MENUBAR keyboard nav -------------------- */
  const navList = document.querySelector('.header_nav-list');
  if (navList) navList.addEventListener('keydown', (e) => {
    const menuitems = Array.from(navList.querySelectorAll('[role="menuitem"]')); if (!menuitems.length) return;
    const idx = menuitems.indexOf(document.activeElement);
    switch (e.key) {
      case 'ArrowRight': { const n = (idx + 1) % menuitems.length; menuitems[n].focus(); e.preventDefault(); break; }
      case 'ArrowLeft': { const n = (idx - 1 + menuitems.length) % menuitems.length; menuitems[n].focus(); e.preventDefault(); break; }
      case 'Home': menuitems[0].focus(); e.preventDefault(); break;
      case 'End': menuitems[menuitems.length - 1].focus(); e.preventDefault(); break;
      default: break;
    }
  });

  /* -------------------- SEARCH SUGGESTIONS (unchanged) -------------------- */
  const searchInput = document.querySelector('[data-header-input="search-q"]');
  const suggestionsHost = document.querySelector('[data-header-suggestions]') || document.getElementById('header_search_suggestions');
  const SUGGEST_URL = document.querySelector('meta[name="suggest-url"]')?.content || '/eshop/api/suggest.php?q=';
  let controller = null, suggestionNodes = [], selectionIndex = -1;

  const clearSuggestions = () => {
    if (!suggestionsHost) return;
    suggestionsHost.innerHTML = ''; suggestionsHost.setAttribute('aria-hidden','true');
    suggestionNodes = []; selectionIndex = -1;
    if (searchInput) searchInput.removeAttribute('aria-activedescendant');
  };

  const renderSuggestions = (items) => {
    if (!suggestionsHost) return;
    suggestionNodes = []; selectionIndex = -1;
    suggestionsHost.innerHTML = '';

    if (!Array.isArray(items) || items.length === 0) {
      const none = document.createElement('div');
      none.className = 'muted';
      none.textContent = 'Žiadne výsledky';
      suggestionsHost.appendChild(none);
      suggestionsHost.setAttribute('aria-hidden','false');
      return;
    }

    const ul = document.createElement('ul');
    ul.className = 'header_suggestions-list';
    ul.setAttribute('role','listbox');

    items.forEach((it, idx) => {
      const li = document.createElement('li');
      li.setAttribute('role','option');
      li.id = `hdr-sug-${idx}`;
      li.tabIndex = -1;
      li.className = 'header_suggestion';
      li.dataset.value = it.value || it.label || '';

      const labelDiv = document.createElement('div');
      labelDiv.className = 'hdr-label';
      labelDiv.innerHTML = escapeHtml(it.label || it.value || '');

      const metaDiv = document.createElement('div');
      metaDiv.className = 'hdr-meta';
      metaDiv.innerHTML = escapeHtml(it.meta || '');

      li.appendChild(labelDiv);
      li.appendChild(metaDiv);

      li.addEventListener('click', () => {
        if (it.url) window.location.href = it.url;
        else window.location.href = '/eshop/catalog.php?q=' + encodeURIComponent(it.value || it.label || '');
      });
      li.addEventListener('keydown', (e) => { if (e.key === 'Enter') li.click(); });

      ul.appendChild(li);
      suggestionNodes.push(li);
    });

    suggestionsHost.appendChild(ul);
    suggestionsHost.setAttribute('aria-hidden','false');
  };

  const doSuggest = debounce((term) => {
    if (!term || term.length < 2) { clearSuggestions(); return; }
    if (controller) controller.abort();
    controller = new AbortController();
    const url = SUGGEST_URL + encodeURIComponent(term);
    fetch(url, { signal: controller.signal, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject('Network error'))
      .then(data => renderSuggestions(data || []))
      .catch(err => { if (!err || err.name !== 'AbortError') { console.warn('Suggest error', err); clearSuggestions(); } });
  }, 200);

  if (searchInput) {
    searchInput.addEventListener('input', (e) => { doSuggest(e.target.value.trim()); });
    searchInput.addEventListener('keydown', (e) => {
      if (!suggestionNodes.length) { if (e.key === 'Escape') clearSuggestions(); return; }
      switch (e.key) {
        case 'ArrowDown': e.preventDefault(); selectionIndex = Math.min(selectionIndex + 1, suggestionNodes.length - 1); updateSelection(); break;
        case 'ArrowUp': e.preventDefault(); selectionIndex = Math.max(selectionIndex - 1, 0); updateSelection(); break;
        case 'Enter': if (selectionIndex >= 0 && suggestionNodes[selectionIndex]) suggestionNodes[selectionIndex].click(); break;
        case 'Escape': clearSuggestions(); break;
        default: break;
      }
    });
  }

  function updateSelection() {
    suggestionNodes.forEach((n,i)=> n.setAttribute('aria-selected', i === selectionIndex ? 'true' : 'false'));
    const active = suggestionNodes[selectionIndex];
    if (active) {
      active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      if (searchInput) { searchInput.setAttribute('aria-activedescendant', active.id); active.focus(); }
    } else { if (searchInput) searchInput.removeAttribute('aria-activedescendant'); }
  }

  document.addEventListener('click', (e) => {
    if (suggestionsHost && !suggestionsHost.contains(e.target) && e.target !== searchInput) clearSuggestions();
  });

  /* -------------------- MINI CART — robust selection & auto-create structure if missing -------------------- */
  const miniCartEl = document.querySelector('[data-header-minicart]') || document.querySelector('.header_cart-dropdown') || document.querySelector('.header_minicart');
  const cartLink = document.querySelector('[data-header-link="cart"]');
  const cartBadge = document.querySelector('[data-header-badge]');

  const createMinicartStructure = (el) => {
    if (!el) return;
    // If already created but missing parts, ensure they exist
    if (!el.querySelector('.header_minicart-header')) {
      const header = document.createElement('div');
      header.className = 'header_minicart-header';
      header.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
          <div style="display:flex;align-items:center;gap:10px;">
            <strong class="header_minicart-title-heading">Košík</strong>
            <div class="header_minicart-total muted" aria-hidden="true" style="font-size:.92rem;color:var(--muted)"></div>
          </div>
          <div class="header_minicart-controls" style="display:flex;gap:8px;align-items:center;">
            <a href="/eshop/cart_clear" class="header_btn header_btn--ghost minicart-action-clear" title="Vymazať košík">Vymazať</a>
            <a href="/eshop/cart" class="header_btn header_btn--ghost minicart-action-view" title="Zobraziť košík">Zobraziť</a>
            <a href="/eshop/checkout" class="header_btn header_btn--primary minicart-action-checkout" title="Dokončiť nákup">Dokončiť</a>
          </div>
        </div>
      `;
      el.appendChild(header);
    }

    if (!el.querySelector('.header_minicart-list')) {
      const empty = document.createElement('div');
      empty.className = 'header_minicart-empty';
      empty.textContent = 'V košíku nič nie je';
      empty.setAttribute('aria-hidden','true');

      const list = document.createElement('ul');
      list.className = 'header_minicart-list';
      list.setAttribute('role','list');
      list.setAttribute('aria-hidden','true');

      el.appendChild(empty);
      el.appendChild(list);
    }

    // footer / actions area (optional)
    if (!el.querySelector('.header_minicart-actions')) {
      const actions = document.createElement('div');
      actions.className = 'header_minicart-actions';
      actions.style.display = 'none'; // visibility managed by aria attributes
      el.appendChild(actions);
    }
  };
  createMinicartStructure(miniCartEl);

// --- REPLACEMENT: delegator to unified CartBadge (hotfix) ---
const updateCartBadge = (n, doPulse = true) => {
  const count = Number(n) || 0;
  // preferované jediné místo: window.CartBadge.update
  if (window.CartBadge && typeof window.CartBadge.update === 'function') {
    try {
      window.CartBadge.update(count, !!doPulse);
      return;
    } catch (_) { /* fallback dál */ }
  }

  // fallback (stará logika, bez pulse)
  const b = document.querySelector('[data-header-badge]');
  if (b) {
    b.textContent = count > 0 ? String(count) : '';
    b.setAttribute('data-header-badge', String(count));
    if (count > 0) {
      b.classList.remove('header_cart-badge--empty', 'visually-hidden');
      b.removeAttribute('aria-hidden');
      b.setAttribute('role','status');
      b.setAttribute('aria-live','polite');
      b.setAttribute('aria-atomic','true');
    } else {
      b.classList.add('header_cart-badge--empty', 'visually-hidden');
      b.setAttribute('aria-hidden','true');
    }
  }
  if (cartLink) {
    if (count > 0) {
      cartLink.setAttribute('data-header-cart-count', String(count));
      cartLink.setAttribute('aria-label', `Košík, ${count} položiek`);
      cartLink.setAttribute('title', `Košík, ${count} položiek`);
    } else {
      cartLink.removeAttribute('data-header-cart-count');
      cartLink.setAttribute('aria-label', 'Košík');
      cartLink.setAttribute('title', 'Košík');
    }
  }
};

  // remove single item by id (rowid or id)
  const removeCartItem = (id) => {
    if (!id) return Promise.reject(new Error('no id'));
    // disable interactions briefly (optimistic)
    return postJson('/eshop/cart_remove.php', { id })
      .then(res => {
        // server should respond success = true or updated cart
        return fetchMiniCart(); // refresh view
      }).catch(err => {
        console.warn('Remove item failed', err);
        return Promise.reject(err);
      });
  };

// --- clear cart (with CSRF) + UI update + event dispatch ---
const getCsrfFromDOM = () => {
  return (document.querySelector('input[name="csrf"], input[name="_csrf"], input[name="csrfToken"]') || {}).value
    || document.querySelector('meta[name="csrf-token"], meta[name="csrf"]')?.getAttribute('content')
    || window.__csrfToken || null;
};

const applyCsrfToDOM = (token) => {
  if (!token) return;
  document.querySelectorAll('input[name="csrf"], input[name="_csrf"], input[name="csrfToken"]').forEach(i => {
    try { i.value = token; } catch (_) {}
  });
  const meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf"]');
  if (meta) try { meta.setAttribute('content', token); } catch (_) {}
  try { window.__csrfToken = token; } catch (_) {}
};

  const clearCart = () => {
    const url = '/eshop/cart_clear'; // uprav na '/eshop/cart_clear' pokud máš vlastní routing
    const csrf = getCsrfFromDOM();
    const fd = new FormData();
    if (csrf) fd.set('csrf', csrf);

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, csrf ? { 'X-CSRF-Token': csrf } : {}),
      body: fd
    })
    .then(r => r.text().then(txt => ({ r, txt })))
    .then(({ r, txt }) => {
      if (!r.ok) {
        try { const j = JSON.parse(txt); return Promise.reject(j); } catch(_) { return Promise.reject(txt || r.statusText); }
      }
      let data = {};
      try { data = txt ? JSON.parse(txt) : {}; } catch(_) { data = {}; }

      // update CSRF
      if (data && data.csrfToken) applyCsrfToDOM(data.csrfToken);

      // update badge immediately using returned cart summary (robust)
      if (data && data.cart) {
        updateCartBadge(extractCount(data.cart ?? data));
      } else {
        updateCartBadge(0);
      }

      // dispatch events so other modules react
      try {
        document.dispatchEvent(new CustomEvent('cart:cleared', { detail: { cart: data.cart || null } }));
        document.dispatchEvent(new CustomEvent('cart:updated', { detail: { cart: data.cart || null } }));
      } catch (_) {}

      // refresh minicart markup for consistency
      return fetchMiniCart().then(() => data);
    });
  };

  const fetchMiniCart = () => {
    if (!miniCartEl) return Promise.resolve();
    createMinicartStructure(miniCartEl);
    // show loading state (optional): set aria-busy
    miniCartEl.setAttribute('aria-busy','true');
    return fetch('/eshop/cart_mini', { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject('Failed to load minicart'))
      .then(json => {
        const listEl = miniCartEl.querySelector('.header_minicart-list');
        const emptyEl = miniCartEl.querySelector('.header_minicart-empty');
        const headerTotal = miniCartEl.querySelector('.header_minicart-total');
        const actions = miniCartEl.querySelector('.header_minicart-actions');

        // update header total if available
        if (headerTotal) {
          if (json && (json.total_price !== undefined && json.total_price !== null)) {
            headerTotal.textContent = `Spolu: ${String(json.total_price)}`;
            headerTotal.setAttribute('aria-hidden','false');
          } else {
            headerTotal.textContent = '';
            headerTotal.setAttribute('aria-hidden','true');
          }
        }

        if (Array.isArray(json.items) && json.items.length) {
          emptyEl.setAttribute('aria-hidden','true');
          listEl.setAttribute('aria-hidden','false');
          listEl.innerHTML = '';
          json.items.slice(0, 20).forEach(it => {
            const li = document.createElement('li');
            li.className = 'header_minicart-item';
            li.setAttribute('role','listitem');
            // store identifier for removal
            const itemId = it.id || it.rowid || it.sku || it.key || '';

            const thumbWrap = document.createElement('div');
            thumbWrap.className = 'header_minicart-thumb-wrap';

            if (it.thumb) {
              const img = document.createElement('img');
              img.className = 'header_minicart-thumb';
              img.src = it.thumb;
              img.alt = it.title || '';
              thumbWrap.appendChild(img);
            } else {
              const box = document.createElement('div');
              box.className = 'header_minicart-thumb';
              thumbWrap.appendChild(box);
            }

            const meta = document.createElement('div');
            meta.className = 'header_minicart-meta';

            const title = document.createElement('div');
            title.className = 'header_minicart-title';
            // clickable title link if url provided
            if (it.url) {
              const a = document.createElement('a');
              a.href = it.url;
              a.textContent = it.title || '';
              a.className = 'header_minicart-title-link';
              title.appendChild(a);
            } else {
              title.textContent = it.title || '';
            }

            const sub = document.createElement('div');
            sub.className = 'header_minicart-sub';
            sub.textContent = `×${Number(it.qty)||1} • ${it.price||''}`;

            // remove button
            const remBtn = document.createElement('button');
            remBtn.type = 'button';
            remBtn.className = 'minicart-remove header_btn header_btn--ghost';
            remBtn.setAttribute('title', 'Odstrániť položku');
            remBtn.dataset.id = itemId;
            remBtn.textContent = 'Odstrániť';

            meta.appendChild(title);
            meta.appendChild(sub);

            // layout: thumb | meta | remove btn
            const rightWrap = document.createElement('div');
            rightWrap.style.display = 'flex';
            rightWrap.style.alignItems = 'center';
            rightWrap.style.gap = '8px';
            rightWrap.appendChild(remBtn);

            li.appendChild(thumbWrap);
            li.appendChild(meta);
            li.appendChild(rightWrap);

            listEl.appendChild(li);
          });

          // show actions (clear/view/checkout)
          if (actions) actions.style.display = 'flex';
          updateCartBadge(extractCount(json));
        } else {
          emptyEl.setAttribute('aria-hidden','false');
          listEl.setAttribute('aria-hidden','true');
          listEl.innerHTML = '';
          if (actions) actions.style.display = 'none';
          updateCartBadge(0);
        }

        miniCartEl.removeAttribute('aria-busy');
      }).catch(err => {
        miniCartEl.removeAttribute('aria-busy');
        console.warn('Minicart error', err);
      });
  };

  /* delegation: remove item click, clear cart click */
  if (miniCartEl) {
    // delegate remove
    on(miniCartEl, 'click', '.minicart-remove', (e) => {
      const id = e.target.dataset.id;
      if (!id) return;
      e.target.disabled = true;
      removeCartItem(id).finally(() => { e.target.disabled = false; });
    });

    // clear cart
    on(miniCartEl, 'click', '.minicart-action-clear', (e) => {
      e.preventDefault();
      const btn = e.target;
      if (!confirm('Naozaj vymazať celý košík?')) return;
      btn.disabled = true;
      clearCart().finally(()=> { btn.disabled = false; });
    });

    // view and checkout are normal links — no JS needed (kept for consistency)
  }

  if (cartLink && miniCartEl) {
    // delays (nastav si podle potřeby)
    const HOVER_OPEN_DELAY = 260;  // ms před otevřením po najetí
    const HOVER_CLOSE_DELAY = 360; // ms před zavřením po opuštění
    let openTimer = null;
    let closeTimer = null;

    // helpery - open/close kontrolují a mažou timery
    const doOpen = () => {
      clearTimeout(closeTimer);
      clearTimeout(openTimer);
      // aktualizace aria
      miniCartEl.setAttribute('aria-hidden', 'false');
      if (cartLink) cartLink.setAttribute('aria-expanded','true');
    };
    const doClose = () => {
      clearTimeout(openTimer);
      clearTimeout(closeTimer);
      miniCartEl.setAttribute('aria-hidden', 'true');
      if (cartLink) cartLink.setAttribute('aria-expanded','false');
    };

    // bezpečné otevření s fetch a s ochranou proti duplikaci fetchů
    const openWithFetch = () => {
      clearTimeout(closeTimer);
      // pokud už je otevřeno, nic nedělej kromě zrušení timerů
      if (miniCartEl.getAttribute('aria-hidden') === 'false') { return; }
      // fetch nejdřív (pokud chceš okamžité zobrazení, můžeš doOpen() zavolat před fetch)
      fetchMiniCart().then(() => doOpen()).catch(()=> doOpen());
    };

    // event type - preferujeme pointer events pokud jsou dostupné
    const enterEvt = ('onpointerenter' in window) ? 'pointerenter' : 'mouseenter';
    const leaveEvt = ('onpointerleave' in window) ? 'pointerleave' : 'mouseleave';

    // když najedeš na ikonu: naplánuj otevření
    cartLink.addEventListener(enterEvt, () => {
      clearTimeout(closeTimer);
      clearTimeout(openTimer);
      openTimer = setTimeout(()=> openWithFetch(), HOVER_OPEN_DELAY);
    });

    // když odjedeš z ikony: naplánuj zavření
    cartLink.addEventListener(leaveEvt, () => {
      clearTimeout(openTimer);
      clearTimeout(closeTimer);
      closeTimer = setTimeout(()=> doClose(), HOVER_CLOSE_DELAY);
    });

    // když kurzor vejde do minicartu, zrušíme zavření
    miniCartEl.addEventListener(enterEvt, () => {
      clearTimeout(closeTimer);
      clearTimeout(openTimer);
      // pokud byl mini otevřený (fetch proběhl), ujistit se, že zůstane otevřený
      if (miniCartEl.getAttribute('aria-hidden') === 'true') {
        // možná bylo otevření naplánované; ujistíme se, že se otevře
        openWithFetch();
      }
    });

    // když kurzor opustí minicart, naplánuj zavření
    miniCartEl.addEventListener(leaveEvt, () => {
      clearTimeout(openTimer);
      clearTimeout(closeTimer);
      closeTimer = setTimeout(()=> doClose(), HOVER_CLOSE_DELAY);
    });

    // click na ikonu: toggle (pro dotyková zařízení)
    cartLink.addEventListener('click', (e) => {
      // Pokud kliknutí začalo v elementu uvnitř mini košíku (např. <a> "Dokončiť"),
      // necháme default navigaci a nevoláme preventDefault().
      try {
        if (miniCartEl && miniCartEl.contains(e.target)) {
          return; // necháme odkazy v minicartu fungovat normálně
        }
      } catch (_) {}

      // Jinak: řešíme toggle otevření/uzavření mini košíku
      e.preventDefault();
      const isOpen = miniCartEl && miniCartEl.getAttribute('aria-hidden') === 'false';
      if (isOpen) {
        doClose();
      } else {
        openWithFetch();
      }
    });

    // přístupnost: klávesou Enter/Space otevřít a Esc zavřít
    cartLink.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openWithFetch();
      } else if (e.key === 'Escape') {
        doClose();
      }
    });

    // focus/blur (pro keyboard users): focus otevře, blur naplánuje zavření
    cartLink.addEventListener('focus', () => { clearTimeout(closeTimer); openWithFetch(); }, true);
    cartLink.addEventListener('blur', () => { clearTimeout(openTimer); closeTimer = setTimeout(()=> doClose(), HOVER_CLOSE_DELAY); }, true);
    miniCartEl.addEventListener('focusin', () => { clearTimeout(closeTimer); }, true);
    miniCartEl.addEventListener('focusout', () => { clearTimeout(openTimer); closeTimer = setTimeout(()=> doClose(), HOVER_CLOSE_DELAY); }, true);
  }

  /* header shrink on scroll */
  const headerRoot = document.querySelector('.header_root');
  const onScroll = debounce(()=> {
    if (!headerRoot) return;
    if (window.scrollY > 20) headerRoot.classList.add('header_scrolled'); else headerRoot.classList.remove('header_scrolled');
  }, 40);
  document.addEventListener('scroll', onScroll);
  onScroll(); // init

  /* ESC global close (dropdowns + suggestions + mobile nav) */
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      $$('[data-header-toggle="categories"]').forEach(t => t.setAttribute('aria-expanded','false'));
      $$('[data-header-dropdown]').forEach(p => p.setAttribute('aria-hidden','true'));
      clearSuggestions();
      closeMobileNav();
      if (miniCartEl) miniCartEl.setAttribute('aria-hidden','true');
    }
  });

  /* expose small API */
  window.EPIC_HEADER = { fetchMiniCart, updateCartBadge, openMobileNav, closeMobileNav, setTheme, removeCartItem, clearCart };

})();