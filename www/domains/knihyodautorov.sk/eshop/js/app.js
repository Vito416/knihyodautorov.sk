/* app.js — EPIC UI additions
 * - nav, dropdowns, flashes (existing)
 * - theme toggle (sepia / epic / default)
 * - book preview modal
 * - ambient audio toggle (best-effort, file /assets/ambient.mp3)
 * - cart badge updater
 */
(function () {
  'use strict';
  /* ---------- tiny utils ---------- */
  const qs  = (sel, ctx = document) => (ctx || document).querySelector(sel);
  const qsa = (sel, ctx = document) => Array.from((ctx || document).querySelectorAll(sel));
  const on  = (el, ev, cb, opts) => { if (el) el.addEventListener(ev, cb, opts); };

  function debounce(fn, wait = 250) {
    let timer = null;
    return function (...args) {
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  function getCsrfToken() {
    const m = qs('meta[name="csrf-token"]');
    return m ? String(m.getAttribute('content') || '') : null;
  }

  async function safeFetch(input, init = {}) {
    init = Object.assign({}, init);
    init.headers = init.headers || {};
    const csrf = getCsrfToken();
    if (csrf) {
      try {
        const url = new URL(input, window.location.href);
        if (url.origin === window.location.origin) init.headers['X-CSRF-Token'] = csrf;
      } catch (err) {
        init.headers['X-CSRF-Token'] = csrf;
      }
    }
    if (!init.method) init.method = 'GET';
    return fetch(input, init);
  }

  /* ---------- existing UI inits (nav, dropdowns, flash) ---------- */
  function initNavToggle() {
    const btn = qs('#nav-toggle');
    const nav = qs('#main-nav');
    if (!btn || !nav) return;
    if (nav.dataset.init === '1') return;
    nav.dataset.init = '1';
    // mobile clone logic unchanged (kept)
    const MOBILE_BREAK = 900;
    function isMobile(){ return window.innerWidth <= MOBILE_BREAK; }
    function syncHeaderActionsToNav(open) {
      const headerActions = qs('.header-actions');
      if (!headerActions) return;
      const existing = nav.querySelector('.nav-actions-mobile');
      if (open && isMobile()) {
        if (!existing) {
          const clone = headerActions.cloneNode(true);
          clone.classList.add('nav-actions-mobile');
          clone.removeAttribute('aria-live');
          qsa('[id]', clone).forEach(el => el.removeAttribute('id'));
          nav.appendChild(clone);
        }
      } else {
        if (existing) existing.remove();
      }
    }
    function toggleNav(forceOpen = null) {
      const isOpen = nav.classList.contains('open');
      const toOpen = forceOpen === null ? !isOpen : !!forceOpen;
      if (toOpen) {
        nav.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        const first = nav.querySelector('a,button,input,select');
        if (first) first.focus();
        syncHeaderActionsToNav(true);
      } else {
        nav.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
        btn.focus({preventScroll:true});
        syncHeaderActionsToNav(false);
      }
    }
    on(btn, 'click', function (e) { e.preventDefault(); toggleNav(); });
    on(document, 'keydown', function (ev) { if (ev.key === 'Escape' && nav.classList.contains('open')) toggleNav(false); });
    on(document, 'click', function (ev) { if (!nav.classList.contains('open')) return; if (!nav.contains(ev.target) && !btn.contains(ev.target)) toggleNav(false); });
    on(window, 'resize', debounce(function () { if (!isMobile() && nav.classList.contains('open')) toggleNav(false); if (!isMobile()) { const existing = nav.querySelector('.nav-actions-mobile'); if (existing) existing.remove(); } }, 120));
  }

  function initDropdowns() {
    const toggles = qsa('.nav-dropdown > .dropdown-toggle');
    if (!toggles.length) return;
    if (document.body.dataset.dropdownInit === '1') return;
    document.body.dataset.dropdownInit = '1';
    function closeDropdown(parent) { parent.classList.remove('open'); const btn = parent.querySelector('.dropdown-toggle'); if (btn) btn.setAttribute('aria-expanded', 'false'); }
    function openDropdown(parent) { parent.classList.add('open'); const btn = parent.querySelector('.dropdown-toggle'); if (btn) btn.setAttribute('aria-expanded', 'true'); }
    toggles.forEach(btn => {
      const parent = btn.parentElement;
      const menu = parent && parent.querySelector('.dropdown');
      if (!parent || !menu) return;
      btn.setAttribute('aria-haspopup', 'true');
      if (!btn.hasAttribute('aria-expanded')) btn.setAttribute('aria-expanded', 'false');
      on(btn, 'click', function (ev) {
        ev.preventDefault();
        const opened = parent.classList.toggle('open');
        btn.setAttribute('aria-expanded', opened ? 'true' : 'false');
        if (opened) { const first = menu.querySelector('a,button'); if (first) first.focus(); }
      });
      on(btn, 'keydown', function (ev) {
        if (ev.key === 'ArrowDown' || ev.key === 'Down') { ev.preventDefault(); openDropdown(parent); const first = menu.querySelector('a,button'); if (first) first.focus(); }
        else if (ev.key === 'ArrowUp' || ev.key === 'Up') { ev.preventDefault(); openDropdown(parent); const items = Array.from(menu.querySelectorAll('a,button')); if (items.length) items[items.length - 1].focus(); }
      });
      on(menu, 'keydown', function (ev) {
        const items = Array.from(menu.querySelectorAll('a,button')).filter(Boolean);
        if (!items.length) return;
        const idx = items.indexOf(document.activeElement);
        if (ev.key === 'ArrowDown' || ev.key === 'Down') { ev.preventDefault(); const next = items[(idx + 1) % items.length]; next && next.focus(); }
        else if (ev.key === 'ArrowUp' || ev.key === 'Up') { ev.preventDefault(); const prev = items[(idx - 1 + items.length) % items.length]; prev && prev.focus(); }
        else if (ev.key === 'Home') { ev.preventDefault(); items[0].focus(); }
        else if (ev.key === 'End') { ev.preventDefault(); items[items.length - 1].focus(); }
        else if (ev.key === 'Escape') { ev.preventDefault(); closeDropdown(parent); btn.focus(); }
      });
      on(document, 'click', function (ev) { if (!parent.classList.contains('open')) return; if (!parent.contains(ev.target)) closeDropdown(parent); });
      on(menu, 'focusout', function (ev) { window.setTimeout(() => { if (!parent.contains(document.activeElement)) closeDropdown(parent); }, 10); });
    });
  }

  function initFlashMessages() {
    const container = qs('.flash-messages');
    if (!container) return;
    const flashes = qsa('.flash-messages .flash-info, .flash-messages .flash-success, .flash-messages .flash-warning, .flash-messages .flash-error');
    flashes.forEach(node => {
      if (!qs('.flash-dismiss', node)) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'flash-dismiss';
        btn.innerHTML = '✕';
        btn.setAttribute('aria-label', 'Zavrieť správu');
        on(btn, 'click', function () { node.classList.add('flash-hide'); setTimeout(() => { node && node.parentNode && node.parentNode.removeChild(node); }, 320); });
        node.appendChild(btn);
      }
      if (!node.classList.contains('flash-error')) {
        setTimeout(() => { node.classList.add('flash-hide'); setTimeout(() => { node && node.parentNode && node.parentNode.removeChild(node); }, 320); }, 6000);
      }
    });
  }

  /* ---------- theme toggle (epic / sepia / default) ---------- */
  function initThemeToggle() {
    const btn = qs('#theme-toggle');
    if (!btn) return;
    const body = document.body;
    function setTheme(t) {
      body.classList.remove('epic-theme','sepia-theme','dark-theme');
      if (t === 'epic') body.classList.add('epic-theme');
      if (t === 'sepia') body.classList.add('sepia-theme');
      if (t === 'dark') body.classList.add('dark-theme');
      localStorage.setItem('site-theme', t);
      btn.setAttribute('aria-pressed', String(t !== 'default'));
    }
    // restore
    const saved = localStorage.getItem('site-theme') || 'epic';
    setTheme(saved);
    on(btn, 'click', function (e) {
      e.preventDefault();
      const order = ['epic','sepia','dark','default'];
      const cur = localStorage.getItem('site-theme') || 'epic';
      const idx = order.indexOf(cur);
      const next = order[(idx + 1) % order.length];
      setTheme(next);
    });
  }

  /* ---------- book preview modal ---------- */
  function initPreviewModal() {
    const modal = qs('#book-preview-modal');
    if (!modal) return;
    const backdrop = modal.querySelector('.modal-backdrop');
    const panel = modal.querySelector('.modal-panel');
    const closeButtons = qsa('[data-action="close"]', modal);
    const titleEl = modal.querySelector('.modal-title');
    const authorEl = modal.querySelector('.modal-author');
    const excerptEl = modal.querySelector('.modal-excerpt');
    const coverEl = modal.querySelector('.modal-cover img');
    const openDetail = modal.querySelector('.modal-open-book');
    const buyBtn = modal.querySelector('.modal-buy');

    function open(data) {
      modal.setAttribute('aria-hidden','false');
      modal.classList.add('open');
      titleEl.textContent = data.title || '';
      authorEl.textContent = data.author || '';
      excerptEl.textContent = data.excerpt || '';
      if (data.cover) coverEl.src = data.cover;
      openDetail.href = '/eshop/book.php?slug=' + encodeURIComponent(data.slug || data.id || '');
      buyBtn.onclick = () => {
        // quick buy redirect to detail (safer than auto-add)
        window.location.href = '/eshop/book.php?slug=' + encodeURIComponent(data.slug || data.id || '');
      };
      // focus management
      setTimeout(() => {
        modal.querySelector('.modal-close').focus();
      }, 30);
    }
    function close() {
      modal.setAttribute('aria-hidden','true');
      modal.classList.remove('open');
      // reset
      titleEl.textContent = '';
      authorEl.textContent = '';
      excerptEl.textContent = '';
      coverEl.src = '/assets/book-placeholder-epic.png';
    }

    qsa('.preview-btn').forEach(btn => {
      on(btn, 'click', function (ev) {
        ev.preventDefault();
        const payload = btn.getAttribute('data-book');
        if (!payload) return;
        try {
          const data = JSON.parse(payload);
          open(data);
        } catch (e) {}
      });
    });

    backdrop && on(backdrop, 'click', close);
    closeButtons.forEach(cb => on(cb, 'click', close));
    on(document, 'keydown', function (ev) { if (ev.key === 'Escape' && modal.classList.contains('open')) close(); });
  }

  /* ---------- ambient audio ---------- */
  function initAmbient() {
    const btn = qs('#ambient-toggle');
    if (!btn) return;
    const audio = new Audio('/assets/ambient.mp3');
    audio.loop = true;
    audio.preload = 'auto';
    let playing = false;
    function setState(p) {
      playing = !!p;
      btn.setAttribute('aria-pressed', String(playing));
      btn.textContent = playing ? '🔊 Ambient' : '🎵 Ambient';
    }
    on(btn, 'click', function () {
      if (!playing) {
        audio.play().then(()=> setState(true)).catch(()=> setState(false));
      } else {
        audio.pause();
        setState(false);
      }
    });
    // stop on page hide
    document.addEventListener('visibilitychange', function () {
      if (document.hidden && playing) { audio.pause(); setState(false); }
    });
  }

  /* ---------- cart badge updater (kept) ---------- */
  function initCartBadge(options = {}) {
    const badge = qs('.cart-badge');
    if (!badge) return () => {};
    const url = options.endpoint || '/eshop/api/cart_count.php';
    const refreshInterval = options.interval || 30 * 1000;
    async function updateOnce() {
      try {
        const res = await safeFetch(url, { method: 'GET', credentials: 'same-origin' });
        if (!res || !res.ok) return;
        const json = await res.json();
        const count = parseInt((json && json.count) || 0, 10) || 0;
        badge.textContent = count > 0 ? String(count) : '';
        badge.setAttribute('aria-label', count + ' položiek v košíku');
      } catch (e) {}
    }
    updateOnce();
    const timer = setInterval(updateOnce, refreshInterval);
    return () => clearInterval(timer);
  }

  /* ---------- init all ---------- */
  function initAll() {
    try { initNavToggle(); } catch (e) { console.warn('nav',e); }
    try { initDropdowns(); } catch (e) { console.warn('dd',e); }
    try { initFlashMessages(); } catch (e) { console.warn('flash',e); }
    try { initThemeToggle(); } catch (e) { console.warn('theme',e); }
    try { initPreviewModal(); } catch (e) { console.warn('preview',e); }
    try { initAmbient(); } catch (e) { /* ignore */ }
    let stopCart = () => {};
    try { stopCart = initCartBadge(); } catch (e) { console.warn('cart',e); }
    window.App = window.App || {};
    window.App.safeFetch = safeFetch;
    window.App.updateCartBadge = function () {
      const badge = qs('.cart-badge');
      if (!badge) return;
      safeFetch('/eshop/api/cart_count.php', { method: 'GET', credentials: 'same-origin' })
        .then(r => (r && r.ok) ? r.json() : null)
        .then(json => {
          const count = parseInt((json && json.count) || 0, 10) || 0;
          badge.textContent = count > 0 ? String(count) : '';
          badge.setAttribute('aria-label', count + ' položiek v košíku');
        })
        .catch(()=>{});
    };
    window.App.stopCartBadge = stopCart;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

})();