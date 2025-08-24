// /js/books.js — CLEANED & SIMPLIFIED (FIXED)
// Kompletní náhrada: načítání knih (AJAX), rotace karet, vyhledávání, lazy-load,
// SVG maska (pokud je dostupná), bezpečné fallbacky. Oprava: čtení cover URL před smazáním <img> + vždy nastavujeme data-cover.

document.addEventListener('DOMContentLoaded', () => {
  // ---------- CONFIG & DOM refs ----------
  const booksGrid = document.getElementById('booksGrid');
  const unifiedInput = document.getElementById('unifiedSearch');
  const clearBtn = document.getElementById('clearSearch');
  const modal = document.getElementById('bookModal');
  const modalClose = modal?.querySelector('.modal-close');
  const modalCover = document.getElementById('modalCover');
  const modalTitle = document.getElementById('modalTitle');
  const modalAuthor = document.getElementById('modalAuthor');
  const modalDesc = document.getElementById('modalDesc');
  const modalDownload = document.getElementById('modalDownload');

  const IMG_FALLBACK = '/assets/cover-fallback.png';
  const MASK_URL = '/assets/book-cover-transparent-mask.svg';
  const MASK_ID = 'bookHoleMask';

  let poolItems = [];
  let perm = [];
  let permPos = 0;
  let rotationInterval = null;
  let rotationPaused = false;
  let lastLimit = detectLimit();
  let searchCounter = 0;

  // mask data
  let maskLoaded = false;
  let maskSerializedNodes = [];
  let maskHoleRect = null; // in user units (viewBox)
  let maskViewBox = '0 0 581 1238';

  // ---------- small helpers ----------
  const qsa = (sel, root = document) => Array.from((root || document).querySelectorAll(sel));
  const qs = (sel, root = document) => (root || document).querySelector(sel);

  function detectLimit() {
    const w = window.innerWidth;
    if (w >= 1200) return 4;
    if (w >= 900) return 3;
    if (w >= 700) return 2;
    return 1;
  }

  function shuffleArray(arr) {
    const a = arr.slice();
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
  }

  async function fetchBooks(limit = 4, q = '') {
    const path = window.location.pathname.replace(/\/$/, '') + '/partials/books.php';
    const params = new URLSearchParams({ ajax: '1', limit: String(limit) });
    if (q) params.set('q', q);
    const url = path + '?' + params.toString();
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json();
      if (data && Array.isArray(data.items)) return data.items;
      return [];
    } catch (e) {
      return [];
    }
  }

  // ---------- svg sizing utility ----------
  function updateSvgPixelSize(svgEl, container) {
    if (!svgEl || !container) return;
    const r = container.getBoundingClientRect();
    if (!isFinite(r.width) || r.width <= 0) return;
    svgEl.style.width = Math.round(r.width) + 'px';
    svgEl.style.height = Math.round(r.height) + 'px';
    svgEl.setAttribute('width', String(Math.round(r.width)));
    svgEl.setAttribute('height', String(Math.round(r.height)));
  }

  // debounce resize of generated svgs
  let __svgResizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(__svgResizeTimer);
    __svgResizeTimer = setTimeout(() => {
      qsa('svg.cover-svg-generated').forEach(sv => updateSvgPixelSize(sv, sv.parentElement));
    }, 120);
  });

  // ---------- lazy load images ----------
  function lazyLoadImages(root = document) {
    const imgs = qsa('img.book-cover[data-src]', root);
    if (!imgs.length) return;
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries, obs) => {
        entries.forEach(en => {
          if (!en.isIntersecting) return;
          const img = en.target;
          const src = img.dataset.src;
          img.src = src || IMG_FALLBACK;
          img.removeAttribute('data-src');
          img.onerror = () => { img.onerror = null; img.src = IMG_FALLBACK; };
          obs.unobserve(img);
        });
      }, { root: null, rootMargin: '200px' });
      imgs.forEach(i => {
        i.onerror = () => { i.onerror = null; i.src = IMG_FALLBACK; };
        io.observe(i);
      });
    } else {
      imgs.forEach(i => { i.src = i.dataset.src || IMG_FALLBACK; i.onerror = () => { i.onerror = null; i.src = IMG_FALLBACK; }; i.removeAttribute('data-src'); });
    }
  }

  function revealStagger(root = document) {
    const cards = qsa('.book-card', root);
    cards.forEach((c, idx) => {
      c.style.opacity = '0';
      c.style.transform = 'translateY(20px) rotateX(6deg)';
      setTimeout(() => {
        c.classList.add('book-card-revealed');
        c.style.opacity = '';
        c.style.transform = '';
      }, 120 + idx * 80);
    });
  }

  // ---------- mask parsing helper ----------
  function computeMaskHoleRectFromNodes(nodes, vb) {
    if (!nodes || !nodes.length) return null;
    const svgNS = 'http://www.w3.org/2000/svg';
    const temp = document.createElementNS(svgNS, 'svg');
    if (vb) temp.setAttribute('viewBox', vb);
    temp.style.position = 'absolute';
    temp.style.width = '0';
    temp.style.height = '0';
    temp.style.overflow = 'hidden';
    temp.setAttribute('aria-hidden', 'true');
    const g = document.createElementNS(svgNS, 'g');
    nodes.forEach(n => {
      try {
        const tmp = new DOMParser().parseFromString(`<svg xmlns="http://www.w3.org/2000/svg">${n}</svg>`, 'image/svg+xml');
        const first = tmp.documentElement.firstChild;
        if (first) g.appendChild(first.cloneNode(true));
      } catch (e) {}
    });
    temp.appendChild(g);
    document.body.appendChild(temp);
    let bb = null;
    try { bb = g.getBBox(); } catch (e) { bb = null; }
    document.body.removeChild(temp);
    if (!bb || !isFinite(bb.width) || bb.width <= 0 || !isFinite(bb.height) || bb.height <= 0) return null;
    return { x: bb.x, y: bb.y, width: bb.width, height: bb.height };
  }

  // ---------- load mask (once) ----------
  async function loadMaskOnce() {
    if (maskLoaded) return;
    try {
      const res = await fetch(MASK_URL, { cache: 'no-store' });
      if (!res.ok) throw new Error('mask not available');
      const svgText = await res.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(svgText, 'image/svg+xml');
      const rootSvg = doc.querySelector('svg');
      if (rootSvg && rootSvg.getAttribute('viewBox')) maskViewBox = rootSvg.getAttribute('viewBox');

      const clipCandidate = doc.querySelector('clipPath, path, g');
      if (!clipCandidate) {
        maskSerializedNodes = ['<rect x="0" y="0" width="581" height="1238"/>'
        ];
      } else {
        if (clipCandidate.tagName.toLowerCase() === 'clippath') {
          const inner = clipCandidate.cloneNode(true);
          const children = Array.from(inner.childNodes)
            .map(n => n.outerHTML || new XMLSerializer().serializeToString(n))
            .filter(Boolean);
          maskSerializedNodes = children.length ? children : [inner.outerHTML];
        } else {
          maskSerializedNodes = [clipCandidate.outerHTML];
        }
      }

      try { maskHoleRect = computeMaskHoleRectFromNodes(maskSerializedNodes, maskViewBox); } catch (e) { maskHoleRect = null; }
      maskLoaded = true;

      (function ensureHiddenDefs() {
        const svgNS = 'http://www.w3.org/2000/svg';
        let hiddenSvg = document.querySelector('svg[aria-hidden][data-book-mask]');
        if (!hiddenSvg) {
          hiddenSvg = document.createElementNS(svgNS, 'svg');
          hiddenSvg.setAttribute('aria-hidden', 'true');
          hiddenSvg.setAttribute('data-book-mask', '1');
          hiddenSvg.style.width = '0';
          hiddenSvg.style.height = '0';
          hiddenSvg.style.position = 'absolute';
          hiddenSvg.style.overflow = 'hidden';
          if (maskViewBox) hiddenSvg.setAttribute('viewBox', maskViewBox);
          const defs = document.createElementNS(svgNS, 'defs');
          hiddenSvg.appendChild(defs);
          document.body.appendChild(hiddenSvg);
        }
        const defs = hiddenSvg.querySelector('defs');
        const prev = defs.querySelector(`#${MASK_ID}`);
        if (prev) prev.remove();
        const cp = document.createElementNS(svgNS, 'clipPath');
        cp.id = MASK_ID;
        cp.setAttribute('clipPathUnits', 'userSpaceOnUse');
        maskSerializedNodes.forEach(str => {
          try {
            const tmp = new DOMParser().parseFromString(`<svg xmlns="http://www.w3.org/2000/svg">${str}</svg>`, 'image/svg+xml');
            Array.from(tmp.documentElement.childNodes).forEach(n => cp.appendChild(n.cloneNode(true)));
          } catch (e) {}
        });
        defs.appendChild(cp);
      })();

    } catch (e) {
      maskLoaded = false;
      maskSerializedNodes = [];
      maskHoleRect = null;
    }
  }

  // ---------- image preloader ----------
  async function tryImagePathsSimple(original, timeout = 8000) {
    if (!original || !String(original).trim()) return IMG_FALLBACK;
    const orig = String(original).trim();
    const candidates = [];
    try {
      const u = new URL(orig, window.location.href);
      if (!u.pathname.endsWith('/')) candidates.push(u.href);
    } catch (e) {
      const cleaned = orig.replace(/^\/+/, '');
      const looksLikeFile = /\.[a-z0-9]{2,6}$/i.test(cleaned);
      if (looksLikeFile) {
        candidates.push(window.location.origin + (orig.startsWith('/') ? orig : '/' + cleaned));
        candidates.push(window.location.origin + '/books-img/' + cleaned);
      } else {
        candidates.push(window.location.origin + (orig.startsWith('/') ? orig : '/' + cleaned));
      }
    }
    const uniq = Array.from(new Set(candidates)).filter(Boolean);
    if (!uniq.length) return IMG_FALLBACK;

    const preload = url => new Promise((res, rej) => {
      const img = new Image();
      let timer = setTimeout(() => { img.onload = img.onerror = null; rej(new Error('timeout')); }, timeout);
      img.onload = () => { clearTimeout(timer); res(url); };
      img.onerror = () => { clearTimeout(timer); rej(new Error('error')); };
      img.src = url;
    });

    for (const u of uniq) {
      try { await preload(u); return u; } catch (e) {}
    }
    return IMG_FALLBACK;
  }

    async function applyFrameToCards(root = document) {
      if (!maskLoaded) await loadMaskOnce();
      const cards =
        root instanceof Element && root.matches('.book-card')
          ? [root]
          : (root instanceof Element
              ? Array.from(root.querySelectorAll('.book-card'))
              : Array.from(document.querySelectorAll('.book-card')));
      const svgNS = 'http://www.w3.org/2000/svg';

      for (const card of cards) {
        const coverWrap = card.querySelector('.cover-wrap');
        if (!coverWrap) continue;

        // ZÍSKAT COVER URL DŘÍV, NEŽ SMAŽEME <img>
        const imgEl = coverWrap.querySelector('img.book-cover') || coverWrap.querySelector('img');
        const initialCoverUrl = (imgEl?.dataset?.src || imgEl?.getAttribute?.('src') || card.dataset?.cover || '').trim();
        if (initialCoverUrl) card.dataset.cover = initialCoverUrl; // uložit pro příště

        // ensure .book-frame exists
        if (!coverWrap.querySelector('.book-frame')) {
          const f = document.createElement('div'); f.className = 'book-frame'; coverWrap.appendChild(f);
        }

        // až teď můžeme <img> odstranit
        qsa(':scope > img', coverWrap).forEach(i => i.remove());

        // create svg
        let svgEl = coverWrap.querySelector('svg.cover-svg-generated');
        if (svgEl) svgEl.remove();
        svgEl = document.createElementNS(svgNS, 'svg');
        svgEl.setAttribute('class', 'cover-svg cover-svg-generated');
        svgEl.setAttribute('viewBox', maskViewBox || '0 0 581 1238');
        svgEl.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svgEl.style.width = '100%';
        svgEl.style.height = '100%';
        svgEl.style.display = 'block';
        svgEl.style.pointerEvents = 'none';
        coverWrap.insertBefore(svgEl, coverWrap.firstChild);

        updateSvgPixelSize(svgEl, coverWrap);

        // defs + local clip
        const defs = document.createElementNS(svgNS, 'defs');
        svgEl.appendChild(defs);
        if (maskSerializedNodes && maskSerializedNodes.length) {
          const localClipId = `mask-${Math.random().toString(36).slice(2,8)}`;
          const localCp = document.createElementNS(svgNS, 'clipPath');
          localCp.setAttribute('id', localClipId);
          localCp.setAttribute('clipPathUnits', 'userSpaceOnUse');
          maskSerializedNodes.forEach(str => {
            try {
              const tmp = new DOMParser().parseFromString(`<svg xmlns="http://www.w3.org/2000/svg">${str}</svg>`, 'image/svg+xml');
              Array.from(tmp.documentElement.childNodes).forEach(n => localCp.appendChild(n.cloneNode(true)));
            } catch (e) {}
          });
          defs.appendChild(localCp);
          svgEl.dataset.localClip = localClipId;
        }

        // image element
        const imgNode = document.createElementNS(svgNS, 'image');
        imgNode.setAttribute('class', 'cover-image');
        imgNode.setAttribute('preserveAspectRatio', 'none');
        if (svgEl.dataset.localClip) imgNode.setAttribute('clip-path', `url(#${svgEl.dataset.localClip})`);
        else if (maskLoaded) imgNode.setAttribute('clip-path', `url(#${MASK_ID})`);
        svgEl.appendChild(imgNode);

        // použít zjištěný/uložený cover
        const coverUrl = card.dataset.cover || '';
        let urlToUse = IMG_FALLBACK;
        try { urlToUse = await tryImagePathsSimple(coverUrl, 9000); } catch (e) { urlToUse = IMG_FALLBACK; }

        const vbParts = (svgEl.getAttribute('viewBox') || '0 0 581 1238').trim().split(/\s+/).map(Number);
        const vbW = vbParts[2] || 581; const vbH = vbParts[3] || 1238;
        const rect = maskHoleRect
          ? { x: maskHoleRect.x, y: maskHoleRect.y, width: maskHoleRect.width, height: maskHoleRect.height }
          : { x: 0, y: 0, width: vbW, height: vbH };

        imgNode.setAttribute('x', String(rect.x));
        imgNode.setAttribute('y', String(rect.y));
        imgNode.setAttribute('width', String(rect.width));
        imgNode.setAttribute('height', String(rect.height));

        try { imgNode.setAttribute('href', urlToUse); } catch (e) {}
        try { imgNode.setAttributeNS('http://www.w3.org/1999/xlink', 'href', urlToUse); } catch (e) {}

        updateSvgPixelSize(svgEl, coverWrap);
      } // konec for-of
    } // konec funkce

  // run mask loader & initial apply
  (async function initMask() { await loadMaskOnce(); if (booksGrid) applyFrameToCards(booksGrid); })();

  // ---------- render logic ----------
  function takeNextWindow(limit) {
    if (!perm.length || poolItems.length === 0) return [];
    const effectiveLimit = Math.min(limit, poolItems.length);
    const windowItems = [];
    const seen = new Set();
    while (windowItems.length < effectiveLimit) {
      if (permPos >= perm.length) {
        perm = shuffleArray(Array.from(Array(poolItems.length).keys())); permPos = 0;
      }
      const idx = perm[permPos++];
      const item = poolItems[idx];
      if (!item) continue;
      const id = item.id ?? (item.nazov + '::' + idx);
      if (seen.has(id)) continue;
      seen.add(id);
      windowItems.push(item);
    }
    return shuffleArray(windowItems);
  }

  function renderBooks(items) {
    if (!booksGrid) return;
    booksGrid.classList.add('fade-out');
    setTimeout(() => {
      booksGrid.innerHTML = '';
      const frag = document.createDocumentFragment();
      items.forEach(it => {
        const art = document.createElement('article');
        art.className = 'book-card';
        art.setAttribute('tabindex', '0');
        art.dataset.category = it.category_slug || 'uncategorized';
        art.dataset.author = it.author_id ? ('author-' + it.author_id) : '';
        art.dataset.title = (it.nazov || '').toLowerCase();

        const esc = s => { if (!s) return ''; return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[m])); };
        const imgSrc = (it.obrazok && it.obrazok.trim()) ? it.obrazok : IMG_FALLBACK;

        // ✅ vždy ulož cover i na kartu
        art.dataset.cover = imgSrc;

        art.innerHTML = `
          <div class="card-inner">
            ${it.category_nazov ? `<div class="card-meta"><span class="badge">${esc(it.category_nazov)}</span></div>` : ''}
            <div class="cover-wrap" style="transform-style:preserve-3d;">
              <img class="book-cover" data-src="${esc(imgSrc)}" alt="${esc(it.nazov)}" onerror="this.onerror=null;this.src='${IMG_FALLBACK}'">
              <div class="book-frame" aria-hidden="true"></div>
            </div>
            <div class="card-info">
              <h3 class="book-title">${esc(it.nazov)}</h3>
              <p class="book-author">${esc(it.autor || '')}</p>
              <p class="book-desc">${esc((it.popis||'').substring(0,160))}</p>
            </div>
            <div class="card-actions">
              <button class="btn btn-outline open-detail" type="button"
              data-title="${esc(it.nazov)}" data-author="${esc(it.autor||'')}"
              data-desc="${esc(it.popis||'')}" data-cover="${esc(imgSrc)}" data-pdf="${esc(it.pdf||'')}">
              Zobraziť
              </button>
              ${it.pdf ? `
              <button class="btn btn-download" type="button"
              data-href="${esc(it.pdf)}" 
              data-filename="${encodeURIComponent(it.nazov)}.pdf">
              Stiahnuť
              </button>` : ''}
            </div>
          </div>
        `;
        frag.appendChild(art);
      });
      booksGrid.appendChild(frag);

      applyFrameToCards(booksGrid);
      lazyLoadImages(booksGrid);
      revealStagger(booksGrid);
      bindDetailButtons(booksGrid);

      booksGrid.classList.remove('fade-out');
      booksGrid.classList.add('fade-in');
      setTimeout(() => booksGrid.classList.remove('fade-in'), 600);
    }, 180);
  }

  // ---------- detail modal ----------
  function bindDetailButtons(root = document) {
    qsa('.open-detail', root).forEach(btn => { btn.replaceWith(btn.cloneNode(true)); });
    qsa('.open-detail', root).forEach(btn => btn.addEventListener('click', () => openModal({
      title: btn.dataset.title,
      author: btn.dataset.author,
      desc: btn.dataset.desc,
      cover: btn.dataset.cover,
      pdf: btn.dataset.pdf
    })));
  }
  function openModal(d) {
    if (!modal) return;
    modalCover.src = d.cover || IMG_FALLBACK;
    modalTitle.textContent = d.title || '';
    modalAuthor.textContent = d.author || '';
    modalDesc.textContent = d.desc || '';
    modalDownload.href = d.pdf || '#';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() { if (!modal) return; modal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
  modalClose?.addEventListener('click', closeModal);
  modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal?.getAttribute('aria-hidden') === 'false') closeModal(); });

  // ---------- rotation ----------
  const ROTATE_MS = 8000;
  function startRotation() {
    stopRotation();
    rotationInterval = setInterval(() => {
      if (rotationPaused) return;
      const limit = detectLimit();
      const items = takeNextWindow(limit);
      if (items && items.length) renderBooks(items);
    }, ROTATE_MS);
  }
  function stopRotation() { if (rotationInterval) { clearInterval(rotationInterval); rotationInterval = null; } }

  // ---------- initial pool & startup ----------
  (async function initPool() {
    poolItems = await fetchBooks(50, '');
    if (!poolItems || !poolItems.length) poolItems = await fetchBooks(4, '');
    if (!poolItems || poolItems.length === 0) {
      if (booksGrid) booksGrid.innerHTML = '<div class="no-books">Zatiaľ neboli pridané žiadne knihy.</div>';
      return;
    }
    perm = shuffleArray(Array.from(Array(poolItems.length).keys())); permPos = 0;
    const initial = takeNextWindow(detectLimit());
    if (initial.length) renderBooks(initial);
    startRotation();
  })();

  // ---------- resize listener ----------
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const newLimit = detectLimit();
      if (newLimit !== lastLimit) {
        lastLimit = newLimit;
        const items = takeNextWindow(newLimit);
        if (items.length) renderBooks(items);
      }
    }, 180);
  });

  // ---------- search & clear ----------
  if (clearBtn) clearBtn.style.display = 'none';
  let debounceTimer = null;
  let lastQuery = '';

  unifiedInput?.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(async () => {
      const q = (unifiedInput.value || '').trim();
      if (q === lastQuery) return;
      lastQuery = q;
      if (!q) {
        if (clearBtn) clearBtn.style.display = 'none';
        rotationPaused = false;
        const items = takeNextWindow(detectLimit());
        if (items.length) renderBooks(items);
        startRotation();
        return;
      }
      rotationPaused = true; stopRotation(); if (clearBtn) clearBtn.style.display = 'inline-block';
      const thisSearch = ++searchCounter;
      const items = await fetchBooks(50, q);
      if (thisSearch !== searchCounter) return;
      if (items.length) renderBooks(shuffleArray(items).slice(0, Math.max(1, detectLimit())));
      else if (booksGrid) booksGrid.innerHTML = '<div class="no-books">Nenašli sa žiadne knihy.</div>';
    }, 420);
  });

  clearBtn?.addEventListener('click', () => {
    if (!unifiedInput) return;
    unifiedInput.value = '';
    lastQuery = '';
    if (clearBtn) clearBtn.style.display = 'none';
    rotationPaused = false;
    const items = takeNextWindow(detectLimit());
    if (items.length) renderBooks(items);
    startRotation();
  });

  // ---------- download button handler (delegation) ----------
  document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-download');
    if (!btn) return;
    const url = btn.dataset.href;
    const filename = btn.dataset.filename;
    if (!url) return;
    const a = document.createElement('a');
    a.href = url;
    if (filename) a.download = filename;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    document.body.appendChild(a);
    a.click();
    a.remove();
  });

});