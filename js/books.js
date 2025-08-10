// /js/books.js
// Rotace promo knih: pool + permutace + okno podle breakpointu
// + odstraněné duplicity v okně a fallback obrázek

document.addEventListener('DOMContentLoaded', () => {
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

  // helpery
  const qsa = (sel, root = document) => Array.from((root || document).querySelectorAll(sel));
  const qs = sel => document.querySelector(sel);

  // ------- konfigurace -------
  const ROTATE_MS = 8000;
  let rotationInterval = null;
  let rotationPaused = false;

  // pool a permutace
  let poolItems = [];       // všechny položky (objekty)
  let perm = [];            // pole indexů poolItems v náhodném pořadí
  let permPos = 0;          // aktuální index v perm
  let lastLimit = detectLimit();

  // fallback obrázok (uprav, ak chceš iné meno)
  const IMG_FALLBACK = '/books-img/books-imgFB.png';

  // ---------- pomocné funkce ----------
  function detectLimit() {
    const w = window.innerWidth;
    if (w >= 1200) return 4;
    if (w >= 900) return 3;
    if (w >= 700) return 2;
    return 1;
  }

  function shuffleArray(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
  }

  // fetch (pool or search)
  async function fetchBooks(limit = 4, q = '') {
    const path = window.location.pathname.replace(/\/$/, '') + '/partials/books.php';
    const params = new URLSearchParams();
    params.set('ajax', '1');
    params.set('limit', String(limit));
    if (q) params.set('q', q);
    const url = path + '?' + params.toString();
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json();
      if (data.error) {
        console.error('Books AJAX error:', data.error);
        return [];
      }
      return data.items || [];
    } catch (err) {
      console.error('Fetch error', err);
      return [];
    }
  }

  // lazy-load a reveal pro nové karty
  function lazyLoadImages(root = document) {
    const imgs = qsa('img.book-cover[data-src]', root);
    if (!imgs.length) return;
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries, obs) => {
        entries.forEach(en => {
          if (en.isIntersecting) {
            const img = en.target;
            const src = img.dataset.src;
            if (src) { img.src = src; img.removeAttribute('data-src'); }
            // fallback handler (pro jistotu)
            img.onerror = function () { this.onerror = null; this.src = IMG_FALLBACK; };
            obs.unobserve(img);
          }
        });
      }, { root: null, rootMargin: '200px' });
      imgs.forEach(i => {
        // fallback set preloader
        i.onerror = function () { this.onerror = null; this.src = IMG_FALLBACK; };
        io.observe(i);
      });
    } else {
      imgs.forEach(i => {
        i.src = i.dataset.src || IMG_FALLBACK;
        i.onerror = function () { this.onerror = null; this.src = IMG_FALLBACK; };
        i.removeAttribute('data-src');
      });
    }
  }

  function revealStagger(root = document) {
    const cards = qsa('.book-card', root);
    cards.forEach((c, idx) => {
      c.style.opacity = '0';
      c.style.transform = 'translateY(20px) rotateX(6deg)';
      setTimeout(() => {
        c.classList.add('revealed');
        c.style.opacity = '';
        c.style.transform = '';
      }, 120 + idx * 80);
    });
  }

  // create next window from permutace without duplicates
  function takeNextWindow(limit) {
    if (!perm.length || poolItems.length === 0) return [];
    // avoid duplicates: effective limit cannot exceed pool size
    const effectiveLimit = Math.min(limit, poolItems.length);
    const windowItems = [];
    const seenIds = new Set();

    // loop, take items skipping those already in windowItems
    while (windowItems.length < effectiveLimit) {
      if (permPos >= perm.length) {
        // reshuffle perm if exhausted
        perm = shuffleArray(Array.from(Array(poolItems.length).keys()));
        permPos = 0;
      }
      const idx = perm[permPos++];
      const item = poolItems[idx];
      if (!item) continue;
      const id = item.id ?? (item.nazov + '::' + idx);
      if (seenIds.has(id)) {
        // skip duplicates within this window
        // but continue to advance permPos so we don't loop forever
        // if we detect that we've cycled through full perm without adding, break
        // (but since effectiveLimit <= poolItems.length, this should not happen)
        continue;
      }
      seenIds.add(id);
      windowItems.push(item);
    }

    // additionally shuffle window order so positions are random
    return shuffleArray(windowItems);
  }

  // render
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

        const esc = s => {
          if (!s) return '';
          return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        };

        // ensure fallback if obrazok empty
        const imgSrc = (it.obrazok && it.obrazok.trim()) ? it.obrazok : IMG_FALLBACK;

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
              ${it.pdf ? `<a class="btn btn-primary" href="${esc(it.pdf)}" target="_blank" rel="noopener">Stiahnuť</a>` : ''}
            </div>
          </div>
        `;
        frag.appendChild(art);
      });
      booksGrid.appendChild(frag);

      // init lazy/reveal/modal
      lazyLoadImages(booksGrid);
      revealStagger(booksGrid);
      bindDetailButtons(booksGrid);

      booksGrid.classList.remove('fade-out');
      booksGrid.classList.add('fade-in');
      setTimeout(() => booksGrid.classList.remove('fade-in'), 600);
    }, 200);
  }

  // detail binding
  function bindDetailButtons(root = document) {
    // safely remove old listeners by replacing nodes
    qsa('.open-detail', root).forEach(btn => {
      btn.replaceWith(btn.cloneNode(true));
    });
    qsa('.open-detail', root).forEach(btn => {
      btn.addEventListener('click', () => {
        openModal({
          title: btn.dataset.title,
          author: btn.dataset.author,
          desc: btn.dataset.desc,
          cover: btn.dataset.cover,
          pdf: btn.dataset.pdf
        });
      });
    });
  }

  // modal
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
  function closeModal() {
    if (!modal) return;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }
  modalClose?.addEventListener('click', closeModal);
  modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal?.getAttribute('aria-hidden') === 'false') closeModal(); });

  // ---------- ROTATION management ----------
  function startRotation() {
    stopRotation();
    rotationInterval = setInterval(() => {
      if (rotationPaused) return;
      const limit = detectLimit();
      const items = takeNextWindow(limit);
      if (items && items.length) renderBooks(items);
    }, ROTATE_MS);
  }
  function stopRotation() {
    if (rotationInterval) { clearInterval(rotationInterval); rotationInterval = null; }
  }

  // ---------- initial: load pool (all items) ----------
  (async function initPool() {
    // fetch a large limit to get entire pool (50 by default should be enough)
    poolItems = await fetchBooks(50, '');
    if (!poolItems || !poolItems.length) {
      poolItems = await fetchBooks(4, '');
    }

    // if still empty, bail
    if (!poolItems || poolItems.length === 0) {
      booksGrid && (booksGrid.innerHTML = '<div class="no-books">Zatiaľ neboli pridané žiadne knihy.</div>');
      return;
    }

    // build perm (shuffle indices)
    perm = shuffleArray(Array.from(Array(poolItems.length).keys()));
    permPos = 0;

    // render initial window of size detectLimit()
    const initial = takeNextWindow(detectLimit());
    if (initial.length) renderBooks(initial);

    // start rotation
    startRotation();
  })();

  // resize handling
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(async () => {
      const newLimit = detectLimit();
      if (newLimit !== lastLimit) {
        lastLimit = newLimit;
        const items = takeNextWindow(newLimit);
        if (items.length) renderBooks(items);
      }
    }, 180);
  });

  // unified search: on Enter do server search (pause rotation)
  unifiedInput?.addEventListener('keydown', async (e) => {
    if (e.key === 'Enter') {
      const q = (unifiedInput.value || '').trim();
      if (!q) return;
      rotationPaused = true;
      stopRotation();
      const items = await fetchBooks(50, q); // search up to 50 results
      if (items.length) {
        renderBooks(shuffleArray(items).slice(0, Math.max(1, detectLimit())));
        clearBtn.style.display = 'inline-block';
      } else {
        booksGrid.innerHTML = '<div class="no-books">Nenašli sa žiadne knihy.</div>';
        clearBtn.style.display = 'inline-block';
      }
    }
  });

  // clear search -> resume rotation
  clearBtn?.addEventListener('click', async () => {
    unifiedInput.value = '';
    clearBtn.style.display = 'none';
    rotationPaused = false;
    const items = takeNextWindow(detectLimit());
    if (items.length) renderBooks(items);
    startRotation();
  });

});
