/* ---------- EPIC LUXURY MODAL JS (LRU + EPIC ZOOM FIX) ----------
   Comments: Slovak / české komentáře (projekt knihyodautorov.sk)
*/
document.addEventListener('DOMContentLoaded', () => {
  // === CONFIG ===
  const USE_LRU = true;           // zapni/vypni LRU
  const LRU_MAX = 250;            // max položek v cache
  const PREFETCH_DELAY = 180;     // ms delay pro prefetch debounce

  // === LRU implementace (kompatibilní s Map API) ===
  class LRUCache {
    constructor(max = 250) {
      this.max = Math.max(1, max);
      this.map = new Map();
    }
    get size() { return this.map.size; }
    has(k) { return this.map.has(k); }
    get(k) {
      if (!this.map.has(k)) return undefined;
      const v = this.map.get(k);
      // posunout na konec (nejnovější)
      this.map.delete(k);
      this.map.set(k, v);
      return v;
    }
    set(k, v) {
      if (this.map.has(k)) this.map.delete(k);
      this.map.set(k, v);
      // prune oldest
      while (this.map.size > this.max) {
        const firstKey = this.map.keys().next().value;
        this.map.delete(firstKey);
      }
      return this;
    }
    delete(k) { return this.map.delete(k); }
    clear() { this.map.clear(); }
    keys() { return this.map.keys(); }
  }

  // fallback plain Map wrapper pro stejnou API kompatibilitu
  class SimpleCache {
    constructor(max=Infinity){ this.map=new Map(); this.max=max; }
    get size(){ return this.map.size; }
    has(k){ return this.map.has(k); }
    get(k){ return this.map.get(k); }
    set(k,v){ this.map.set(k,v); while(this.map.size>this.max){ this.map.delete(this.map.keys().next().value); } return this; }
    delete(k){ return this.map.delete(k); }
    clear(){ this.map.clear(); }
    keys(){ return this.map.keys(); }
  }

  const fragmentCache = USE_LRU ? new LRUCache(LRU_MAX) : new SimpleCache(LRU_MAX);
  let lastTrigger = null;
  const activeControllers = new Map();

  /* -----------------------------
   * Helpers
   * ----------------------------- */
  function isElementVisible(el) {
    try { return !!(el && el.offsetParent !== null); } catch { return false; }
  }

  /* -----------------------------
   * Focus trap (scoped)
   * ----------------------------- */
  function trapFocus(modal) {
    const selector = 'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const getNodes = () => Array.from(modal.querySelectorAll(selector)).filter(isElementVisible);
    let nodes = getNodes();
    if (!nodes.length) return () => {};
    let first = nodes[0], last = nodes[nodes.length - 1];

    function keyHandler(e) {
      if (e.key !== 'Tab') return;
      nodes = getNodes();
      first = nodes[0]; last = nodes[nodes.length - 1];
      if (!first || !last) return;
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    modal.addEventListener('keydown', keyHandler);
    return () => modal.removeEventListener('keydown', keyHandler);
  }

  /* -----------------------------
   * Scroll lock
   * ----------------------------- */
  function lockScroll() { document.documentElement.classList.add('modal-open'); }
  function unlockScroll() { document.documentElement.classList.remove('modal-open'); }

  /* -----------------------------
   * Skeleton + loader
   * ----------------------------- */
  function showSkeleton(body) {
    body.innerHTML = `
      <div class="modal-skeleton" style="height:320px;margin-bottom:1rem"></div>
      <div class="modal-skeleton" style="height:28px;width:60%;margin-bottom:0.5rem"></div>
      <div class="modal-skeleton" style="height:18px;width:40%;margin-bottom:0.5rem"></div>
      <div class="modal-skeleton" style="height:14px;width:80%;margin-top:0.5rem"></div>
    `;
  }
  function addLoader(modal) {
    if (!modal.querySelector('.modal-loader')) {
      const loader = document.createElement('div');
      loader.className = 'modal-loader';
      loader.setAttribute('aria-hidden','true');
      loader.style.pointerEvents = 'none';
      modal.appendChild(loader);
    }
  }
  function removeLoader(modal) { modal.querySelector('.modal-loader')?.remove(); }

  /* -----------------------------
   * Extract fragment (bez script tagů)
   * ----------------------------- */
  function extractFragment(html) {
    const temp = document.createElement('div');
    temp.innerHTML = html.trim();
    const extracted = temp.querySelector('.modal-inner') || temp.querySelector('.modal-body') || temp;
    extracted.querySelectorAll('.modal-close, .close, [data-modal-close]').forEach(n => n.remove());
    extracted.querySelectorAll('script').forEach(s => s.remove());
    return extracted.innerHTML.trim();
  }

  /* -----------------------------
   * Ensure modal singleton
   * ----------------------------- */
  function ensureModal() {
    let modal = document.getElementById('bookModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'bookModal';
      modal.className = 'modal';
      modal.setAttribute('aria-hidden', 'true');
      modal.innerHTML = `
        <div class="modal-overlay" data-role="overlay" aria-hidden="true"></div>
        <div class="panel" role="dialog" aria-modal="true" aria-label="Detail knihy">
          <button class="modal-close" aria-label="Zavřít">&times;</button>
          <div class="modal-inner"><div class="modal-body" tabindex="0"></div></div>
        </div>`;
      document.body.appendChild(modal);
    }

    const overlay = modal.querySelector('.modal-overlay');
    const closeBtn = modal.querySelector('.modal-close');

    if (!overlay._handlerAttached) { overlay.addEventListener('click', () => closeModal(modal)); overlay._handlerAttached = true; }
    if (!closeBtn._handlerAttached) { closeBtn.addEventListener('click', () => closeModal(modal)); closeBtn._handlerAttached = true; }
    return modal;
  }

  /* -----------------------------
   * Close modal (cleanup)
   * ----------------------------- */
  function closeModal(modal) {
    if (!modal || !modal.classList.contains('open')) return;
    modal.classList.remove('open');
    modal.classList.add('closing');
    modal.setAttribute('aria-hidden', 'true');
    unlockScroll();

    try { modal._cleanup?.(); } catch (e) { console.error(e); }
    modal._cleanup = null;

    const panel = modal.querySelector('.panel') || modal;
    try {
      const cs = getComputedStyle(panel);
      const durations = (cs.transitionDuration || '0s').split(',').map(s => parseFloat(s) || 0);
      const tDur = Math.max(...durations);
      if (tDur > 0) {
        const handler = () => { panel.removeEventListener('transitionend', handler); modal.classList.remove('closing'); };
        panel.addEventListener('transitionend', handler);
        setTimeout(() => modal.classList.contains('closing') && modal.classList.remove('closing'), tDur * 1000 + 200);
      } else modal.classList.remove('closing');
    } catch { modal.classList.remove('closing'); }

    try { lastTrigger?.focus?.(); } catch {}

    // ensure any open epic zoom overlays are removed when modal closes
    document.querySelectorAll('.epic-zoom-overlay').forEach(o => {
      try { o.remove(); } catch(e) {}
    });
  }

  /* -----------------------------
   * Epic cover zoom + parallax (FIX: add .active and remove keydown listener correctly)
   * ----------------------------- */
  function attachEpicCoverZoom(body) {
    const coverImg = body.querySelector('.modal-cover img');
    if (!coverImg) return () => {};
    const container = coverImg.closest('.modal-cover') || coverImg.parentElement;
    coverImg.classList.add('parallax', 'glow');

    const onMouseEnter = () => {
      coverImg.style.transition = 'transform 0.35s ease, box-shadow 0.35s ease';
      coverImg.style.transform = 'scale(1.05) translateY(-3px)';
      coverImg.style.boxShadow = '0 20px 60px rgba(255,200,40,0.35)';
    };
    const onMouseLeave = () => {
      coverImg.style.transform = '';
      coverImg.style.boxShadow = '';
    };
    const onMove = (e) => {
      const clientX = e.clientX ?? (e.touches && e.touches[0] && e.touches[0].clientX);
      const clientY = e.clientY ?? (e.touches && e.touches[0] && e.touches[0].clientY);
      if (clientX == null || clientY == null) return;
      const rect = container.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const dx = (clientX - cx) / rect.width;
      const dy = (clientY - cy) / rect.height;
      coverImg.style.transform = `translate3d(${dx * 6}px,${dy * 4}px,0) scale(1.02)`;
    };

    // uvnitř attachEpicCoverZoom(...)
    const zoomHandler = () => {
      // vybereme nejlogičtější prvek pro návrat fokusu:
      // preferuj aktuální activeElement, pak modal close button, pak lastTrigger, pak coverImg, nakonec body
      const modalRoot = document.getElementById('bookModal');
      let prevFocus = (document.activeElement instanceof HTMLElement) ? document.activeElement : null;
      if (!prevFocus || prevFocus === document.body) {
        prevFocus = modalRoot?.querySelector('.modal-close') || lastTrigger || coverImg || document.body;
      }

      // vytvoříme overlay a uděláme ho focusable programově
      const overlay = document.createElement('div');
      overlay.className = 'epic-zoom-overlay';
      overlay.setAttribute('role','dialog');
      overlay.setAttribute('aria-modal','true');
      overlay.setAttribute('aria-label','Zvětšený obrázek');
      overlay.tabIndex = -1; // umožní programatický fokus
      overlay.style.outline = 'none';

      const img = document.createElement('img');
      img.src = coverImg.src;
      img.alt = coverImg.alt || '';
      overlay.appendChild(img);
      document.body.appendChild(overlay);

      // znepřístupnit pozadí screen readerům (inert by byl lepší s polyfillem)
      if (modalRoot) modalRoot.setAttribute('aria-hidden', 'true');

      // otevřeme vizuálně a přeneseme fokus na overlay
      requestAnimationFrame(() => {
        overlay.classList.add('active');
        try { overlay.focus(); } catch(e) { /* ignore */ }
      });

      // helper: ověříme, jestli lze prvek fokusovat
      const isFocusable = el => {
        if (!el) return false;
        if (!(el instanceof HTMLElement)) return false;
        if (!document.contains(el)) return false;
        if (el.hasAttribute('disabled')) return false;
        // offsetParent null může znamenat display:none nebo visibility:hidden (nezaručeně)
        try { if (el.offsetParent === null && getComputedStyle(el).position !== 'fixed') return false; } catch(e) {}
        return true;
      };

      const escHandler = (e) => { if (e.key === 'Escape') remove(); };

      function remove() {
        try { document.removeEventListener('keydown', escHandler); } catch(e) {}
        // animace zavírání overlayu
        overlay.classList.remove('active');
        img.style.transform = 'scale(0.95)';

        // počkejme na vizuální vypršení animace, pak element odstraníme
        setTimeout(() => {
          try { overlay.remove(); } catch(e) {}
          // nejprve zrušíme aria-hidden na modal tak, aby byl element opět focusovatelný
          if (modalRoot) modalRoot.removeAttribute('aria-hidden');

          // počkejme na další repaint a bezpečně obnovme fokus
          requestAnimationFrame(() => {
            try {
              if (isFocusable(prevFocus)) prevFocus.focus();
              else {
                // fallback: pokusit se nalézt nějaké focusovatelné v modalu
                const fallback = modalRoot?.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                (fallback || document.body).focus?.();
              }
            } catch(e) { /* ignore */ }
          });
        }, 240); // hodnota sladěná s CSS animací
      }

      document.addEventListener('keydown', escHandler);
      overlay.addEventListener('click', remove, { once: true });
    };

    container.addEventListener('pointermove', onMove, { passive: true });
    coverImg.addEventListener('pointerenter', onMouseEnter);
    coverImg.addEventListener('pointerleave', onMouseLeave);
    coverImg.addEventListener('click', zoomHandler);

    return () => {
      container.removeEventListener('pointermove', onMove);
      coverImg.removeEventListener('pointerenter', onMouseEnter);
      coverImg.removeEventListener('pointerleave', onMouseLeave);
      coverImg.removeEventListener('click', zoomHandler);
      coverImg.classList.remove('parallax','glow');
      coverImg.style.transform = '';
      coverImg.style.boxShadow = '';
    };
  }

  /* -----------------------------
   * Swipe to close (robust)
   * ----------------------------- */
  function attachSwipe(panel, modal) {
    if (!panel) return () => {};
    let startY = 0, curY = 0, dragging = false;
    const onStart = (e) => {
      if (e.touches && e.touches.length !== 1) return;
      startY = e.touches ? e.touches[0].clientY : e.clientY;
      dragging = true;
      panel.style.transition = 'none';
    };
    const onMove = (e) => {
      if (!dragging) return;
      const clientY = e.touches ? e.touches[0].clientY : e.clientY;
      curY = clientY;
      const dy = curY - startY;
      if (dy > 0) {
        panel.style.transform = `translateY(${dy}px)`;
        panel.style.opacity = `${Math.max(0.35, 1 - dy / 400)}`;
      }
    };
    const onEnd = () => {
      if (!dragging) return;
      const dy = curY - startY;
      dragging = false;
      panel.style.transition = 'transform .25s ease, opacity .2s ease';
      if (dy > 120) closeModal(modal);
      else { panel.style.transform = ''; panel.style.opacity = ''; }
    };

    panel.addEventListener('touchstart', onStart, { passive: true });
    panel.addEventListener('touchmove', onMove, { passive: true });
    panel.addEventListener('touchend', onEnd);
    panel.addEventListener('mousedown', onStart);
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onEnd);

    return () => {
      panel.removeEventListener('touchstart', onStart);
      panel.removeEventListener('touchmove', onMove);
      panel.removeEventListener('touchend', onEnd);
      panel.removeEventListener('mousedown', onStart);
      window.removeEventListener('mousemove', onMove);
      window.removeEventListener('mouseup', onEnd);
    };
  }

  /* -----------------------------
   * Open modal
   * ----------------------------- */
  async function openModalForCard(card) {
    if (!card) return;
    lastTrigger = document.activeElement;
    const link = card.querySelector('.openDetail') || card;
    const slug = link.dataset.slug, id = link.dataset.id;
    const key = slug ? `slug:${slug}` : id ? `id:${id}` : null;
    const url = '/eshop/detail?fragment=1' + (slug ? `&slug=${encodeURIComponent(slug)}` : `&id=${encodeURIComponent(id)}`);

    const modal = ensureModal();
    const body = modal.querySelector('.modal-body');
    const panel = modal.querySelector('.panel');

    showSkeleton(body); addLoader(modal);
    await new Promise(r => setTimeout(r, 50));

    let injected = '';
    try {
      if (key && fragmentCache.has(key)) injected = fragmentCache.get(key);
      else {
        if (activeControllers.has(key)) { try { activeControllers.get(key).abort(); } catch{} activeControllers.delete(key); }
        const controller = new AbortController();
        if (key) activeControllers.set(key, controller);

        const res = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        injected = extractFragment(html);
        if (key && injected) fragmentCache.set(key, injected);
        if (key) activeControllers.delete(key);
      }
    } catch (e) {
      console.error('Modal fetch error', e);
      removeLoader(modal);
      body.innerHTML = '<p>Detaily se nepodařilo načíst.</p>';
      modal.classList.add('open'); modal.setAttribute('aria-hidden','false');
      lockScroll();
      modal._cleanup = () => { try { unlockScroll(); } catch {} };
      return;
    }

    body.innerHTML = injected || '<p>Detaily se nepodařilo načíst.</p>';
    removeLoader(modal);

    const releaseFocus = trapFocus(modal);
    modal.setAttribute('aria-hidden','false');
    lockScroll();

    const detachCover = attachEpicCoverZoom(body);
    const detachSwipe = attachSwipe(panel, modal);

    const escHandler = (e) => { if (e.key === 'Escape') closeModal(modal); };
    document.addEventListener('keydown', escHandler);

    modal._cleanup = () => {
      try { releaseFocus(); } catch {}
      try { detachSwipe(); } catch {}
      try { detachCover(); } catch {}
      try { document.removeEventListener('keydown', escHandler); } catch {}
      try { unlockScroll(); } catch {}
      try { if (key && activeControllers.has(key)) { activeControllers.get(key).abort(); activeControllers.delete(key); } } catch {}
    };

    modal.classList.add('open');
    const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    (focusable || modal.querySelector('.modal-close') || modal.querySelector('.panel')).focus?.();
  }

  /* -----------------------------
   * Prefetch (debounced)
   * ----------------------------- */
  const prefetchTimers = new WeakMap();
  document.body.addEventListener('pointerenter', (e) => {
    const card = e.target.closest('.book-card, .card');
    if (!card) return;
    const link = card.querySelector('.openDetail') || card;
    const slug = link.dataset.slug, id = link.dataset.id;
    const key = slug ? `slug:${slug}` : id ? `id:${id}` : null;
    if (!key || fragmentCache.has(key)) return;

    const existing = prefetchTimers.get(card);
    if (existing) clearTimeout(existing);

    const t = setTimeout(() => {
      const url = '/eshop/detail?fragment=1' + (slug ? `&slug=${encodeURIComponent(slug)}` : `&id=${encodeURIComponent(id)}`);
      if (activeControllers.has(key)) return;
      const controller = new AbortController();
      activeControllers.set(key, controller);
      fetch(url, { credentials: 'same-origin', signal: controller.signal })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => { try { fragmentCache.set(key, extractFragment(html)); } catch (err) { console.error(err); } })
        .catch(()=>{})
        .finally(()=> activeControllers.delete(key));
    }, PREFETCH_DELAY);
    prefetchTimers.set(card, t);
  }, true);

  // cancel prefetch on pointerleave for the same card
  document.body.addEventListener('pointerleave', (e) => {
    const card = e.target.closest('.book-card, .card');
    if (!card) return;
    const timer = prefetchTimers.get(card);
    if (timer) {
      clearTimeout(timer);
      prefetchTimers.delete(card);
    }
  }, true);

  /* -----------------------------
   * Click delegation
   * ----------------------------- */
  document.body.addEventListener('click', (e) => {
    const card = e.target.closest('.book-card, .card');
    if (!card) return;
    if (e.target.closest('form') || e.target.closest('.modal-close') || e.target.closest('.no-modal') || e.target.closest('a[data-no-modal]')) return;
    const anchor = e.target.closest('a');
    if (anchor && anchor.href && (anchor.target === '_blank' || e.metaKey || e.ctrlKey || anchor.hasAttribute('download'))) return;

    e.preventDefault();
    openModalForCard(card).catch(err => console.error(err));
  });

  window.EpicModal = { openModalForCard, fragmentCache, closeModal };
});