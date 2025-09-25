/* header.js — robustní integrace pro header.php (patch) */
(function () {
  'use strict';

  /* helpers */
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

  /* THEME */
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

  /* MOBILE NAV */
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
  if (navToggle) navToggle.addEventListener('click', ()=> { const open = navToggle.getAttribute('aria-expanded') === 'true'; (open ? closeMobileNav() : openMobileNav)(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMobileNav(); });

  /* DROPDOWNS (categories) — robust */
  $$('[data-header-toggle="categories"]').forEach((btn) => {
    const panel = document.getElementById(btn.getAttribute('aria-controls'));
    if (!panel) return;
    const setOpen = (v) => { btn.setAttribute('aria-expanded', v ? 'true' : 'false'); panel.setAttribute('aria-hidden', v ? 'false' : 'true'); };
    setOpen(false);
    btn.addEventListener('click', (e) => { e.stopPropagation(); const isOpen = btn.getAttribute('aria-expanded') === 'true'; setOpen(!isOpen); if (!isOpen) { const first = panel.querySelector('[role="menuitem"]'); if (first) first.focus(); } });
    btn.addEventListener('keydown', (e) => { if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setOpen(true); const first = panel.querySelector('[role="menuitem"]'); if (first) first.focus(); } });
    document.addEventListener('click', (ev) => { if (!panel.contains(ev.target) && ev.target !== btn) setOpen(false); });
  });

  /* MENUBAR keyboard nav */
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

  /* SEARCH SUGGESTIONS */
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
    if (!Array.isArray(items) || items.length === 0) {
      suggestionsHost.innerHTML = '<div class="muted">Žiadne výsledky</div>'; suggestionsHost.setAttribute('aria-hidden','false'); return;
    }
    const ul = document.createElement('ul'); ul.setAttribute('role','listbox'); ul.style.listStyle='none'; ul.style.margin='0'; ul.style.padding='6px';
    items.forEach((it, idx) => {
      const li = document.createElement('li');
      li.setAttribute('role','option');
      li.id = `hdr-sug-${idx}`;
      li.tabIndex = -1;
      li.className = 'header_suggestion';
      li.dataset.value = it.value || it.label || '';
      const label = escapeHtml(it.label || it.value || '');
      const meta = escapeHtml(it.meta || '');
      li.innerHTML = `<div style="font-weight:600">${label}</div><div style="font-size:0.82rem;color:var(--epic-muted)">${meta}</div>`;
      li.addEventListener('click', () => {
        if (it.url) window.location.href = it.url;
        else window.location.href = '/eshop/catalog.php?q=' + encodeURIComponent(it.value || it.label);
      });
      li.addEventListener('keydown', (e) => { if (e.key === 'Enter') li.click(); });
      ul.appendChild(li); suggestionNodes.push(li);
    });
    suggestionsHost.innerHTML = ''; suggestionsHost.appendChild(ul); suggestionsHost.setAttribute('aria-hidden','false');
  };

  const doSuggest = debounce((term) => {
    if (!term || term.length < 2) { clearSuggestions(); return; }
    if (controller) controller.abort();
    controller = new AbortController();
    const url = SUGGEST_URL + encodeURIComponent(term);
    fetch(url, { signal: controller.signal, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject('Network error'))
      .then(data => renderSuggestions(data || []))
      .catch(err => { if (err.name !== 'AbortError') { console.warn('Suggest error', err); clearSuggestions(); } });
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

  /* MINI CART — robust selection & auto-create structure if missing */
  const miniCartEl = document.querySelector('[data-header-minicart]') || document.querySelector('.header_cart-dropdown') || document.querySelector('.header_minicart');
  const cartLink = document.querySelector('[data-header-link="cart"]');
  const cartBadge = document.querySelector('[data-header-badge]');

  const createMinicartStructure = (el) => {
    if (!el) return;
    if (!el.querySelector('.header_minicart-list')) {
      const list = document.createElement('ul'); list.className = 'header_minicart-list';
      const empty = document.createElement('div'); empty.className = 'header_minicart-empty'; empty.textContent = 'V košíku nič nie je';
      el.appendChild(empty); el.appendChild(list);
    }
  };
  createMinicartStructure(miniCartEl);

  const updateCartBadge = (n) => {
    const count = Number(n) || 0;
    if (cartBadge) {
      cartBadge.textContent = count > 0 ? String(count) : '';
      if (count > 0) { cartBadge.classList.remove('header_cart-badge--empty'); cartBadge.removeAttribute('aria-hidden'); cartBadge.setAttribute('role','status'); cartBadge.setAttribute('aria-live','polite'); rafPulse(cartBadge); }
      else { cartBadge.classList.add('header_cart-badge--empty'); cartBadge.setAttribute('aria-hidden','true'); }
    }
    if (cartLink) { cartLink.setAttribute('data-header-cart-count', String(count)); cartLink.setAttribute('aria-label', count > 0 ? `Košík, ${count} položiek` : 'Košík'); }
  };

  const fetchMiniCart = () => {
    if (!miniCartEl) return Promise.resolve();
    createMinicartStructure(miniCartEl);
    return fetch('/eshop/api/minicart.php', { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject('Failed to load minicart'))
      .then(json => {
        const listEl = miniCartEl.querySelector('.header_minicart-list');
        const emptyEl = miniCartEl.querySelector('.header_minicart-empty');
        if (Array.isArray(json.items) && json.items.length) {
          emptyEl.style.display = 'none'; listEl.style.display = ''; listEl.innerHTML = '';
          json.items.slice(0, 8).forEach(it => {
            const li = document.createElement('li');
            const thumb = it.thumb ? `<img src="${escapeHtml(it.thumb)}" alt="" style="width:46px;height:46px;object-fit:cover;border-radius:6px">` : `<div style="width:46px;height:46px;border-radius:6px;background:rgba(255,255,255,0.02)"></div>`;
            li.innerHTML = `<div style="flex:0 0 46px">${thumb}</div><div style="flex:1"><div style="font-weight:600">${escapeHtml(it.title)}</div><div style="font-size:0.85rem;color:var(--epic-muted)">×${Number(it.qty)||1} • ${escapeHtml(it.price||'')}</div></div>`;
            listEl.appendChild(li);
          });
          updateCartBadge(json.total_count || json.items.length || 0);
        } else {
          emptyEl.style.display = ''; listEl.style.display = 'none'; listEl.innerHTML = '';
          updateCartBadge(0);
        }
      }).catch(err => { console.warn('Minicart error', err); });
  };

  if (cartLink && miniCartEl) {
    let timeout = null;
    const open = () => miniCartEl.setAttribute('aria-hidden','false');
    const close = () => miniCartEl.setAttribute('aria-hidden','true');

    cartLink.addEventListener('mouseenter', ()=> { timeout = setTimeout(()=>{ fetchMiniCart().then(open); }, 220); });
    cartLink.addEventListener('mouseleave', ()=> { clearTimeout(timeout); close(); });
    miniCartEl.addEventListener('mouseenter', ()=> clearTimeout(timeout));
    miniCartEl.addEventListener('mouseleave', ()=> close());

    cartLink.addEventListener('click', (e) => {
      e.preventDefault();
      const isOpen = miniCartEl.getAttribute('aria-hidden') === 'false';
      if (isOpen) close(); else fetchMiniCart().then(open);
    });
  }

  try {
    const initialCount = Number(document.querySelector('[data-header-cart-count]')?.getAttribute('data-header-cart-count')) || 0;
    if (initialCount > 0) fetchMiniCart();
  } catch (_) {}

  /* SSE placeholder — unchanged but safe */
  (function setupSSE() {
    if (!('EventSource' in window)) return;
    try {
      const es = new EventSource('/eshop/api/events.php');
      es.addEventListener('message', (e) => {
        try {
          const d = JSON.parse(e.data);
          if (d && d.type === 'cart') updateCartBadge(d.count);
        } catch (_) {}
      });
      es.addEventListener('error', () => { es.close(); });
    } catch (_) {}
  })();

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
  window.EPIC_HEADER = { fetchMiniCart, updateCartBadge, openMobileNav, closeMobileNav, setTheme };

})();