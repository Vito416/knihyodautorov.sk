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

  // ---------- TIMING CONFIG (safe, centralised) ----------
  const TIMINGS = {
    SVG_RESIZE_DEBOUNCE_MS: 120,
    RENDER_DELAY_MS: 180,
    REVEAL_BASE_MS: 120,
    REVEAL_STEP_MS: 80,
    FADE_IN_REMOVE_MS: 600,
    MAKE_RESIZED_FETCH_TIMEOUT_MS: 7000,
    MAKE_RESIZED_IMG_TIMEOUT_MS: 7000,
    TRYIMAGE_PRELOAD_TIMEOUT_MS: 8000,
    NATURAL_IMG_LOAD_TIMEOUT_MS: 5000,
    OBJECTURL_LIFETIME_MS: 4000,
    ROTATE_MS: 8000,
    WINDOW_RESIZE_DEBOUNCE_MS: 180,
    SEARCH_DEBOUNCE_MS: 420
  };

  let poolItems = [];
  let perm = [];
  let permPos = 0;
  let rotationInterval = null;
  let rotationPaused = false;
  let lastLimit = detectLimit();
  let searchCounter = 0;

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
    const params = new URLSearchParams({ ajax: '1', limit: String(limit) });
    if (q) params.set('q', q);

    const url = '/partials/books.php?' + params.toString();

    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) return [];

      const data = await res.json();
      if (data && Array.isArray(data.items)) return data.items;
      return [];
    } catch {
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
    }, TIMINGS.SVG_RESIZE_DEBOUNCE_MS);
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

    // jen delay podle indexu
    cards.forEach((c, idx) => {
      c.style.transitionDelay =
        (TIMINGS.REVEAL_BASE_MS + idx * TIMINGS.REVEAL_STEP_MS) + 'ms';
    });

    // force reflow, aby browser zaregistroval delay
    void root.offsetHeight;

    // přidat class -> spustí se CSS transition
    cards.forEach(c => c.classList.add('book-card-revealed'));
    // remove inline transitionDelay after the reveal finishes (prevents persistent style writes)
    const maxDelay = TIMINGS.REVEAL_BASE_MS + Math.max(0, cards.length - 1) * TIMINGS.REVEAL_STEP_MS + 500;
    setTimeout(() => {
      cards.forEach(c => { c.style.transitionDelay = ''; });
    }, maxDelay);

  }

  // ---------- image preloader (returns {url, blob}) ----------
  // tries to obtain a Blob via fetch (CORS). If that fails, falls back to image preloading
  async function tryImagePathsSimple(original, timeout = TIMINGS.TRYIMAGE_PRELOAD_TIMEOUT_MS) {
    if (!original || !String(original).trim()) return { url: IMG_FALLBACK, blob: null };
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
    if (!uniq.length) return { url: IMG_FALLBACK, blob: null };

    // Helper: try fetching blob (preferred) with timeout
    const tryFetchBlob = async (url) => {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), timeout);
      try {
        const resp = await fetch(url, { signal: controller.signal, mode: 'cors' });
        clearTimeout(timeoutId);
        if (resp && resp.ok) {
          const blob = await resp.blob();
          return { url, blob };
        }
      } catch (e) {
        // fetch failed (could be CORS or network)
      } finally {
        try { clearTimeout(timeoutId); } catch (e) {}
      }
      return null;
    };

    // 1) Try fetch for each candidate (fast path if server supports CORS)
    for (const u of uniq) {
      try {
        const r = await tryFetchBlob(u);
        if (r) return r;
      } catch (e) { /* ignore */ }
    }

    // 2) Fallback: image preload (no blob available)
    const preload = url => new Promise((res, rej) => {
      const img = new Image();
      let timer = setTimeout(() => { img.onload = img.onerror = null; rej(new Error('timeout')); }, timeout);
      img.onload = () => { clearTimeout(timer); res(url); };
      img.onerror = () => { clearTimeout(timer); rej(new Error('error')); };
      img.src = url;
    });

    for (const u of uniq) {
      try {
        await preload(u);
        return { url: u, blob: null };
      } catch (e) {}
    }
    return { url: IMG_FALLBACK, blob: null };
  }

  // ---------- image resizing/canvas + fetch helper (top-level) ----------
  // now accepts optional srcBlob (if provided, skip fetching)
  async function makeResizedObjectURL(srcUrl, dstPxW, dstPxH, crossOrigin, srcBlob = null) {
    // normalize
    dstPxW = Math.max(1, Math.round(dstPxW || 1));
    dstPxH = Math.max(1, Math.round(dstPxH || 1));

    try {
      let blob = null;

      if (srcBlob instanceof Blob) {
        blob = srcBlob;
      } else {
        // 1) fetch the resource (CORS required on server)
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), TIMINGS.MAKE_RESIZED_FETCH_TIMEOUT_MS);
        let resp;
        try {
          resp = await fetch(srcUrl, { signal: controller.signal, mode: 'cors' });
        } finally {
          clearTimeout(timeoutId);
        }
        if (!resp || !resp.ok) throw new Error('fetch-failed');
        blob = await resp.blob();
      }

      // 2) try createImageBitmap (preferred)
      let bitmap = null;
      try {
        bitmap = await createImageBitmap(blob);
      } catch (e) {
        bitmap = null;
      }

      // 3) prepare canvas and draw (either from bitmap or from Image)
      const canvas = document.createElement('canvas');
      canvas.width = dstPxW;
      canvas.height = dstPxH;
      const ctx = canvas.getContext('2d');

      if (bitmap) {
        ctx.drawImage(bitmap, 0, 0, bitmap.width, bitmap.height, 0, 0, canvas.width, canvas.height);
      } else {
        const tmpUrl = URL.createObjectURL(blob);
        try {
          await new Promise((resolve, reject) => {
            const img = new Image();
            if (crossOrigin) img.crossOrigin = crossOrigin;
            let done = false;
            img.onload = () => { if (done) return; done = true; resolve(img); };
            img.onerror = (err) => { if (done) return; done = true; reject(err); };
            img.src = tmpUrl;
            setTimeout(() => { if (done) return; done = true; reject(new Error('img-load-timeout')); }, TIMINGS.MAKE_RESIZED_IMG_TIMEOUT_MS);
          }).then(img => {
            ctx.drawImage(img, 0, 0, img.naturalWidth, img.naturalHeight, 0, 0, canvas.width, canvas.height);
          });
        } finally {
          try { URL.revokeObjectURL(tmpUrl); } catch (e) {}
        }
      }

      // 4) export canvas -> blob -> objectURL
      return await new Promise((resolve, reject) => {
        canvas.toBlob((outBlob) => {
          if (!outBlob) return resolve(srcUrl);
          resolve(URL.createObjectURL(outBlob));
        }, 'image/png');
      });

    } catch (err) {
      return srcUrl;
    }
  }

  // ---------- simplified, cached mask loader & applyFrameToCards ----------
  const MASK_URL = '/assets/book-cover-transparent-mask.svg';
  const FRAME_PNG_URL = '/assets/book-cover-transparent.png';
  const DEFAULTS = {
    MASK_SVG_URL: MASK_URL,
    FRAME_PNG_URL: FRAME_PNG_URL,
    CROSSORIGIN: undefined // set to 'anonymous' if needed
  };

  let __maskCache = { svgText: null, doc: null, viewBox: null, holeRect: null, pathD: null, pathBBox: null };

  async function loadMaskOnce(url = DEFAULTS.MASK_SVG_URL) {
    if (__maskCache.svgText) return __maskCache;
    const resp = await fetch(url, { cache: 'force-cache' });
    if (!resp.ok) throw new Error('Failed to fetch mask svg: ' + resp.status);
    const text = await resp.text();
    const parser = new DOMParser();
    const doc = parser.parseFromString(text, 'image/svg+xml');
    const svgEl = doc.documentElement;

    let vb = (svgEl.getAttribute && svgEl.getAttribute('viewBox')) || null;
    if (vb) {
      const parts = vb.trim().split(/\s+/).map(Number);
      __maskCache.viewBox = { x: parts[0], y: parts[1], w: parts[2], h: parts[3] };
    } else {
      const w = Number(svgEl.getAttribute('width')) || 581;
      const h = Number(svgEl.getAttribute('height')) || 1238;
      __maskCache.viewBox = { x: 0, y: 0, w, h };
    }

    __maskCache.svgText = text;
    __maskCache.doc = doc;

    // Try to compute hole rect: look for element with id/class 'mask-hole' or compute union bbox of shapes
    let holeEl =
      doc.getElementById('mask-hole') ||
      doc.querySelector('.mask-hole') ||
      doc.querySelector('#hole') ||
      doc.querySelector('.hole');

    function makeTempSvgAndAppend(templateSvgElement) {
      const ns = 'http://www.w3.org/2000/svg';
      const tmp = document.createElementNS(ns, 'svg');
      tmp.setAttribute('aria-hidden', 'true');
      tmp.style.position = 'absolute';
      tmp.style.left = '-9999px';
      tmp.style.top = '-9999px';
      tmp.style.width = '1px';
      tmp.style.height = '1px';
      tmp.style.opacity = '0';
      try {
        const clone = templateSvgElement.cloneNode(true);
        if (clone.getAttribute && !clone.getAttribute('viewBox')) {
          const vb = __maskCache.viewBox;
          if (vb) clone.setAttribute('viewBox', `${vb.x} ${vb.y} ${vb.w} ${vb.h}`);
        }
        tmp.appendChild(clone);
      } catch (e) {
        const r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        r.setAttribute('width', '1');
        r.setAttribute('height', '1');
        tmp.appendChild(r);
      }
      document.body.appendChild(tmp);
      return tmp;
    }

    function getBBoxSafe(node, tmpSvg) {
      let target = null;
      if (node.id) target = tmpSvg.querySelector('#' + CSS.escape(node.id));
      if (!target) {
        const all = Array.from(tmpSvg.querySelectorAll(node.tagName.toLowerCase()));
        target = all[0] || tmpSvg;
      }
      return target.getBBox();
    }

    if (holeEl) {
      const tmpSvg = makeTempSvgAndAppend(doc.documentElement);
      try {
        const bbox = getBBoxSafe(holeEl, tmpSvg);
        __maskCache.holeRect = { x: bbox.x, y: bbox.y, width: bbox.width, height: bbox.height };
      } finally {
        tmpSvg.remove();
      }
    } else {
      const shapes = Array.from(doc.querySelectorAll('path, rect, circle, ellipse, polygon, polyline, g'));
      if (shapes.length) {
        const tmpSvg = makeTempSvgAndAppend(doc.documentElement);
        try {
          let union = null;
          for (const s of shapes) {
            try {
              const b = getBBoxSafe(s, tmpSvg);
              if (!union) union = { x: b.x, y: b.y, x2: b.x + b.width, y2: b.y + b.height };
              else {
                union.x = Math.min(union.x, b.x);
                union.y = Math.min(union.y, b.y);
                union.x2 = Math.max(union.x2, b.x + b.width);
                union.y2 = Math.max(union.y2, b.y + b.height);
              }
            } catch (e) { /* ignore */ }
          }
          if (union) {
            __maskCache.holeRect = { x: union.x, y: union.y, width: union.x2 - union.x, height: union.y2 - union.y };
          }
        } finally {
          tmpSvg.remove();
        }
      }
    }

    if (!__maskCache.holeRect) {
      const vb2 = __maskCache.viewBox;
      __maskCache.holeRect = { x: vb2.x, y: vb2.y, width: vb2.w, height: vb2.h };
    }

    // EXTRACT pathD once for reuse to avoid reparsing for each card
    try {
      const tmpDoc2 = new DOMParser().parseFromString(text, 'image/svg+xml');
      const p = tmpDoc2.querySelector('path.st0') || tmpDoc2.querySelector('path');
      __maskCache.pathD = p ? p.getAttribute('d') : null;
    } catch (e) { /* ignore */ }

    return __maskCache;
  }

  function computeCoverPlacement(natW, natH, hole) {
    if (!(natW > 0 && natH > 0)) {
      return { x: hole.x, y: hole.y, width: hole.width, height: hole.height };
    }
    const scale = Math.max(hole.width / natW, hole.height / natH);
    const dispW = natW * scale;
    const dispH = natH * scale;
    const x = hole.x + (hole.width - dispW) / 2;
    const y = hole.y + (hole.height - dispH) / 2;
    return { x, width: dispW, height: dispH, y };
  }

  async function applyFrameToCards(root = document, options = {}) {
    const opt = {
      MASK_SVG_URL: DEFAULTS.MASK_SVG_URL,
      FRAME_PNG_URL: DEFAULTS.FRAME_PNG_URL,
      CROSSORIGIN: DEFAULTS.CROSSORIGIN,
      deform: true,
      allowUpscale: false,
      ...options
    };

    try { await loadMaskOnce(opt.MASK_SVG_URL); } catch (e) { /* fallback */ }
    const maskInfo = __maskCache || {};
    const svgNS = 'http://www.w3.org/2000/svg';
    const xlinkNS = 'http://www.w3.org/1999/xlink';

    // extrahuj path data (prefer .st0)
    let pathD = maskInfo.pathD || null;

    const vb = maskInfo.viewBox || { x: 0, y: 0, w: 581, h: 1238 };

    // compute path bbox in user units (if pathD available) - reuse existing method
    let pathBBox = null;
    if (pathD) {
      try {
        const tmpSvg = document.createElementNS(svgNS, 'svg');
        tmpSvg.setAttribute('viewBox', `${vb.x} ${vb.y} ${vb.w} ${vb.h}`);
        tmpSvg.style.position = 'absolute';
        tmpSvg.style.left = '-9999px';
        tmpSvg.style.width = '1px';
        tmpSvg.style.height = '1px';
        tmpSvg.setAttribute('aria-hidden', 'true');

        const tmpPath = document.createElementNS(svgNS, 'path');
        tmpPath.setAttribute('d', pathD);
        tmpPath.setAttribute('fill', 'black');
        tmpSvg.appendChild(tmpPath);
        document.body.appendChild(tmpSvg);

        try {
          const bb = tmpPath.getBBox();
          if (bb && isFinite(bb.width) && bb.width > 0 && isFinite(bb.height) && bb.height > 0) {
            pathBBox = { x: bb.x, y: bb.y, width: bb.width, height: bb.height };
          }
        } catch (e) { /* getBBox failed */ }
        tmpSvg.remove();
      } catch (e) { /* ignore */ }
    }
    if (!pathBBox) {
      if (typeof maskHoleRect !== 'undefined' && maskHoleRect && maskHoleRect.width > 0) {
        pathBBox = { x: maskHoleRect.x, y: maskHoleRect.y, width: maskHoleRect.width, height: maskHoleRect.height };
      } else {
        pathBBox = { x: vb.x, y: vb.y, width: vb.w, height: vb.h };
      }
    }

    // collect cards
    const cards =
      root instanceof Element && root.matches && root.matches('.book-card')
        ? [root]
        : (root instanceof Element ? Array.from(root.querySelectorAll('.book-card')) : Array.from(document.querySelectorAll('.book-card')));

    // helper: makeResizedObjectURL (inner copy) - accepts optional srcBlob
    async function makeResizedObjectURLInner(srcUrl, dstPxW, dstPxH, crossOrigin, srcBlob = null) {
      // reuse top-level function (keeps single implementation)
      return await makeResizedObjectURL(srcUrl, dstPxW, dstPxH, crossOrigin, srcBlob);
    }

    for (const card of cards) {
      try {
        const coverWrap = card.querySelector('.cover-wrap');
        if (!coverWrap) continue;

        // read cover url
        const imgEl = coverWrap.querySelector('img.book-cover') || coverWrap.querySelector('img');
        const initialCoverUrl = (imgEl?.dataset?.src || imgEl?.getAttribute?.('src') || card.dataset?.cover || '').trim();
        if (initialCoverUrl) card.dataset.cover = initialCoverUrl;
        const coverUrlRaw = card.dataset.cover || '';
        let coverUrl = IMG_FALLBACK;
        let coverBlob = null;
        try {
          const coverResult = await tryImagePathsSimple(coverUrlRaw, TIMINGS.TRYIMAGE_PRELOAD_TIMEOUT_MS);
          coverUrl = coverResult.url || IMG_FALLBACK;
          coverBlob = coverResult.blob || null;
        } catch (e) { coverUrl = IMG_FALLBACK; coverBlob = null; }

        // remove existing visuals
        Array.from(coverWrap.querySelectorAll(':scope > img')).forEach(n => n.remove());
        Array.from(coverWrap.querySelectorAll(':scope > svg.cover-svg-generated')).forEach(n => n.remove());

        // Preload natural size (keep it - used for contain logic)
        let natW = 0, natH = 0;
        try {
          await new Promise((res, rej) => {
            if (!coverUrl) return res();
            const im = new Image();
            if (opt.CROSSORIGIN) im.crossOrigin = opt.CROSSORIGIN;
            let done = false;
            im.onload = () => { if (done) return; done = true; natW = im.naturalWidth || im.width; natH = im.naturalHeight || im.height; res(); };
            im.onerror = () => { if (done) return; done = true; rej(new Error('cover load failed')); };
            im.src = coverUrl;
            setTimeout(() => { if (done) return; done = true; rej(new Error('timeout')); }, TIMINGS.NATURAL_IMG_LOAD_TIMEOUT_MS);
          });
        } catch (e) { natW = 0; natH = 0; }

        // Create SVG and image elements
        const viewBoxStr = `${vb.x} ${vb.y} ${vb.w} ${vb.h}`;
        const svgEl = document.createElementNS(svgNS, 'svg');
        svgEl.setAttribute('class', 'cover-svg cover-svg-generated');
        svgEl.setAttribute('viewBox', viewBoxStr);
        svgEl.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svgEl.style.width = '100%';
        svgEl.style.height = '100%';
        svgEl.style.display = 'block';
        svgEl.style.pointerEvents = 'none';
        svgEl.setAttribute('aria-hidden', 'true');

        // ALWAYS create local defs + clipPath (more reliable across browsers)
        const localDefs = document.createElementNS(svgNS, 'defs');
        const localCpId = `local-clip-${hashString((pathD || '') + card.dataset.cover + Math.random())}`;
        const cp = document.createElementNS(svgNS, 'clipPath');
        cp.setAttribute('id', localCpId);
        cp.setAttribute('clipPathUnits', 'userSpaceOnUse');
        const p = document.createElementNS(svgNS, 'path');
        if (pathD) p.setAttribute('d', pathD);
        else p.setAttribute('d', `M ${vb.x} ${vb.y} h ${vb.w} v ${vb.h} h ${-vb.w} z`);
        cp.appendChild(p);
        localDefs.appendChild(cp);
        svgEl.appendChild(localDefs);

        const imgNode = document.createElementNS(svgNS, 'image');
        imgNode.setAttribute('class', 'cover-image cover-generated');
        if (opt.CROSSORIGIN) imgNode.setAttribute('crossorigin', opt.CROSSORIGIN);
        imgNode.setAttribute('clip-path', `url(#${localCpId})`);

        svgEl.appendChild(imgNode);
        const frameImg = document.createElementNS(svgNS, 'image');
        frameImg.setAttribute('class', 'frame-image');
        if (opt.CROSSORIGIN) frameImg.setAttribute('crossorigin', opt.CROSSORIGIN);
        frameImg.setAttribute('href', opt.FRAME_PNG_URL);
        frameImg.setAttribute('x', String(vb.x));
        frameImg.setAttribute('y', String(vb.y));
        frameImg.setAttribute('width', String(vb.w));
        frameImg.setAttribute('height', String(vb.h));
        svgEl.appendChild(frameImg);

        coverWrap.insertBefore(svgEl, coverWrap.firstChild);

        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

        updateSvgPixelSize(svgEl, coverWrap);

        const wrapRect = coverWrap.getBoundingClientRect();
        const svgPixelW = (isFinite(wrapRect.width) && wrapRect.width > 0) ? wrapRect.width : coverWrap.clientWidth || vb.w;
        const svgPixelH = (isFinite(wrapRect.height) && wrapRect.height > 0) ? wrapRect.height : coverWrap.clientHeight || vb.h;
        const pxPerUnitX = svgPixelW / vb.w;
        const pxPerUnitY = svgPixelH / vb.h;

        const holePx = {
          x: (pathBBox.x - vb.x) * pxPerUnitX,
          y: (pathBBox.y - vb.y) * pxPerUnitY,
          width: pathBBox.width * pxPerUnitX,
          height: pathBBox.height * pxPerUnitY
        };

        let finalPx;
        if (opt.deform || !(natW > 0 && natH > 0)) {
          finalPx = { x: holePx.x, y: holePx.y, width: holePx.width, height: holePx.height, preserve: 'none' };
        } else {
          const scaleW = holePx.width / natW;
          const scaleH = holePx.height / natH;
          let scale = Math.min(scaleW, scaleH);
          if (!opt.allowUpscale) scale = Math.min(1, scale);
          const dispW = natW * scale;
          const dispH = natH * scale;
          const left = holePx.x + (holePx.width - dispW) / 2;
          const top = holePx.y + (holePx.height - dispH) / 2;
          finalPx = { x: left, y: top, width: dispW, height: dispH, preserve: 'xMidYMid meet' };
        }

        let initialPx;
        if (natW > 0 && natH > 0 && !opt.deform) {
          const containScale = Math.min(holePx.width / natW, holePx.height / natH);
          const initScale = Math.min(1, containScale);
          const initW = natW * initScale;
          const initH = natH * initScale;
          const initLeft = holePx.x + (holePx.width - initW) / 2;
          const initTop = holePx.y + (holePx.height - initH) / 2;
          initialPx = { x: initLeft, y: initTop, width: initW, height: initH, preserve: 'xMidYMid meet' };
        } else {
          initialPx = { ...finalPx };
        }

        const initialUser = {
          x: vb.x + (initialPx.x / pxPerUnitX),
          y: vb.y + (initialPx.y / pxPerUnitY),
          width: initialPx.width / pxPerUnitX,
          height: initialPx.height / pxPerUnitY,
          preserve: initialPx.preserve
        };

        imgNode.setAttribute('preserveAspectRatio', initialUser.preserve);
        imgNode.setAttribute('x', String(initialUser.x));
        imgNode.setAttribute('y', String(initialUser.y));
        imgNode.setAttribute('width', String(initialUser.width));
        imgNode.setAttribute('height', String(initialUser.height));

        void svgEl.getBoundingClientRect();

        // --- LOAD image as blob/objectURL and set href AFTER initial attributes are applied ---
        let objectUrl = null;
        try {
          objectUrl = await makeResizedObjectURLInner(
            coverUrl,
            Math.round(holePx.width),
            Math.round(holePx.height),
            opt.CROSSORIGIN,
            coverBlob
          );
        } catch (e) {
          objectUrl = coverUrl;
        }

        // --- STORE blob URL on the card so we can revoke it on cleanup ---
        try {
          if (objectUrl && typeof objectUrl === 'string' && objectUrl.startsWith('blob:')) {
            card.dataset._objUrl = objectUrl;
          }
        } catch (e) { /* ignore if dataset can't be set */ }

        // nyní nastavíš href/y apod.
        try {
          imgNode.setAttributeNS(null, 'href', objectUrl);
          imgNode.setAttributeNS(xlinkNS, 'xlink:href', objectUrl);
        } catch (e) {
          imgNode.setAttribute('href', objectUrl);
        }

        const finalUser = {
          x: vb.x + (finalPx.x / pxPerUnitX),
          y: vb.y + (finalPx.y / pxPerUnitY),
          width: finalPx.width / pxPerUnitX,
          height: finalPx.height / pxPerUnitY,
          preserve: finalPx.preserve
        };

        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

        imgNode.setAttribute('preserveAspectRatio', finalUser.preserve);
        imgNode.setAttribute('x', String(finalUser.x));
        imgNode.setAttribute('y', String(finalUser.y));
        imgNode.setAttribute('width', String(finalUser.width));
        imgNode.setAttribute('height', String(finalUser.height));

        updateSvgPixelSize(svgEl, coverWrap);

      if (objectUrl && objectUrl.startsWith('blob:')) {
        setTimeout(() => {
          try { URL.revokeObjectURL(objectUrl); } catch (e) {}
          try { delete card.dataset._objUrl; } catch (e) {}
        }, TIMINGS.OBJECTURL_LIFETIME_MS);
      }

      } catch (err) {
        console.error('applyFrameToCards (fixed-scale v2) error', err);
      }
    } // end for

    function hashString(s) {
      if (!s) return '0';
      let h = 2166136261 >>> 0;
      for (let i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i);
        h = Math.imul(h, 16777619) >>> 0;
      }
      return ('h' + (h >>> 0).toString(36));
    }
  }

  // --- reuseable IntersectionObserver for applyFrameToCards ---
  let __framesIO = null;
  let __framesObserved = null;

  function applyFramesWhenVisible(root = document, options = {}) {
    const {
      rootMargin = '200px',
      threshold = 0.01,
      observeOnce = true
    } = options || {};

    // Safety: disconnect previous observer to avoid leaks / duplicate observers
    if (__framesIO) {
      try { __framesIO.disconnect(); } catch (e) { /* ignore */ }
      __framesIO = null;
      __framesObserved = null;
    }

    // Collect cards under root
    const cards = (root && root.querySelectorAll) ? Array.from(root.querySelectorAll('.book-card')) : [];
    if (!cards.length) return null; // nothing to do

    // If no IntersectionObserver support -> fallback (apply immediately)
    if (!('IntersectionObserver' in window)) {
      cards.forEach(c => {
        try { applyFrameToCards(c).catch(()=>{}); } catch (e) { /* ignore */ }
      });
      return null;
    }

    // Create new observer and WeakSet to track processed nodes
    __framesObserved = new WeakSet();
    const observerRoot = (root instanceof Element) ? root : null;

    __framesIO = new IntersectionObserver((entries, obs) => {
      entries.forEach(entry => {
        const el = entry.target;
        // Only act when visible enough
        if (!entry.isIntersecting) return;

        // If already processed, unobserve and skip
        if (__framesObserved.has(el)) {
          try { obs.unobserve(el); } catch (e) { /* ignore */ }
          return;
        }

        // Mark as seen (prevents duplicate apply if observer fires multiple times)
        __framesObserved.add(el);

        // Apply frame for single card — catch errors to avoid breaking observer
        try {
          // applyFrameToCards accepts element or root; ensure we call it per-card
          applyFrameToCards(el).catch(() => {});
        } catch (e) {
          // swallow
        } finally {
          if (observeOnce) {
            try { obs.unobserve(el); } catch (e) { /* ignore */ }
          }
        }
      });
    }, { root: observerRoot, rootMargin, threshold });

    // Observe all current cards (only those not already processed)
    cards.forEach(c => {
      if (!__framesObserved.has(c)) {
        try { __framesIO.observe(c); } catch (e) { /* ignore */ }
      }
    });

    // Return observer so caller can disconnect if desired
    return __framesIO;
  }

  function disconnectApplyFramesObserver() {
    if (__framesIO) {
      try { __framesIO.disconnect(); } catch (e) {}
      __framesIO = null;
      __framesObserved = null;
    }
  }

  // ---------- render logic (safe DOM creation) ----------
  function renderBooks(items) {
    if (!booksGrid) return;
    booksGrid.classList.add('fade-out');
    setTimeout(() => {
      // tidy up observer + revoke any blob objectURLs from old cards
      try { disconnectApplyFramesObserver(); } catch(e){}

      qsa('.book-card', booksGrid).forEach(card => {
        const u = card.dataset._objUrl;
        if (u && typeof u === 'string' && u.startsWith('blob:')) {
          try { URL.revokeObjectURL(u); } catch (err) {}
          try { delete card.dataset._objUrl; } catch (e) {}
        }
      });

      booksGrid.innerHTML = '';
      const frag = document.createDocumentFragment();

      const escText = s => (s == null) ? '' : String(s);

      items.forEach(it => {
        const art = document.createElement('article');
        art.className = 'book-card';
        art.tabIndex = 0;
        art.dataset.category = it.category_slug || 'uncategorized';
        art.dataset.title = escText(it.nazov || '');
        art.dataset.author = escText(it.autor || '');
        art.dataset.desc = escText(it.popis || '');
        art.dataset.titleLower = (it.nazov || '').toLowerCase();
        art.dataset.titleSearch = (it.nazov || '').toLowerCase();

        const imgSrc = (it.obrazok && it.obrazok.trim()) ? it.obrazok.trim() : IMG_FALLBACK;
        art.dataset.cover = imgSrc;

        const inner = document.createElement('div');
        inner.className = 'card-inner';

      // --- pouze JEDNOU v souboru
        const SVG_NS = "http://www.w3.org/2000/svg";

      // BEZPEČNOST: běh jen v prohlížeči + validní vstupy
      if (typeof document === 'undefined') throw new Error('Document není dostupný — tento kód vyžaduje DOM (prohlížeč).');
      if (typeof it === 'undefined' || it === null) throw new Error('`it` není definováno nebo je null.');
      if (!inner || inner.nodeType !== 1) throw new Error('`inner` není DOM Element nebo není dostupný.');

      // Příklad: vykreslení badge pokud existuje kategorie
      if (it.category_nazov) {

        const meta = document.createElement('div');
        meta.className = 'card-meta';

        // jednorázové globální <svg> pro defs
      const EXISTING_SPRITE_ID = 'gold-defs-sprite';
      if (!document.getElementById(EXISTING_SPRITE_ID)) {
        const sprite = document.createElementNS(SVG_NS, "svg");
        sprite.setAttribute("id", EXISTING_SPRITE_ID);
        sprite.setAttribute("aria-hidden", "true");
        sprite.setAttribute("style", "position:absolute;width:0;height:0;overflow:hidden");

        const defs = document.createElementNS(SVG_NS, "defs");

        const gradient = document.createElementNS(SVG_NS, "linearGradient");
        gradient.setAttribute("id", "gold-gradient");
        gradient.setAttribute("x1", "0%"); gradient.setAttribute("y1", "0%");
        gradient.setAttribute("x2", "0%"); gradient.setAttribute("y2", "100%");
        [
          { offset: "0%", color: "#ffffff" },
          { offset: "5%", color: "#fff9b0" },
          { offset: "30%", color: "#ffe766" },
          { offset: "80%", color: "#ffd633" },
          { offset: "100%", color: "#fff9b0" }
        ].forEach(s => {
          const stop = document.createElementNS(SVG_NS, "stop");
          stop.setAttribute("offset", s.offset);
          stop.setAttribute("stop-color", s.color);
          gradient.appendChild(stop);
        });
        defs.appendChild(gradient);

        const filter = document.createElementNS(SVG_NS, "filter");
        filter.setAttribute("id", "gold-emboss");
        filter.setAttribute("x", "-20%");
        filter.setAttribute("y", "-20%");
        filter.setAttribute("width", "140%");
        filter.setAttribute("height", "140%");

        const lighting = document.createElementNS(SVG_NS, "feDiffuseLighting");
        lighting.setAttribute("in", "SourceAlpha");
        lighting.setAttribute("result", "light");
        lighting.setAttribute("lighting-color", "#ffffff");
        lighting.setAttribute("surfaceScale", "2");

        const pointLight = document.createElementNS(SVG_NS, "fePointLight");
        pointLight.setAttribute("x", "-20"); pointLight.setAttribute("y", "-20"); pointLight.setAttribute("z", "50");
        lighting.appendChild(pointLight);
        filter.appendChild(lighting);

        const comp = document.createElementNS(SVG_NS, "feComposite");
        comp.setAttribute("in", "SourceGraphic");
        comp.setAttribute("in2", "light");
        comp.setAttribute("operator", "arithmetic");
        comp.setAttribute("k1", "1"); comp.setAttribute("k2", "0"); comp.setAttribute("k3", "0"); comp.setAttribute("k4", "0");
        filter.appendChild(comp);

        defs.appendChild(filter);
        sprite.appendChild(defs);
        document.body.prepend(sprite);
      }

        // BADGE
        const svgBadge = document.createElementNS(SVG_NS, "svg");
        svgBadge.setAttribute("class", "badge-svg");
        svgBadge.setAttribute("width", "38.5%");
        svgBadge.setAttribute("height", "20%");
        svgBadge.setAttribute("viewBox", "0 0 196 104");
        svgBadge.setAttribute("preserveAspectRatio", "xMidYMid meet");
        svgBadge.setAttribute("aria-hidden", "true");

        // outline path (stejné pro všechny čtyři vrstvy)
        const outlinePathD = "M184.22,11.11c10.23,10.22,10.01,29.1,9.95,43.35-.51,15.22-1.63,29.58-14.04,37.54-14.69,8.65-41.83,5.42-59.13,5.69-26.44.02-52.44,2.13-78.61,3.33-23.81,2.39-39.06.02-40.53-27.84C-1.6,8.41,9.9,5.52,70.92,2.6,94.42,1.36,117.82.19,141.78.83c14.24.32,32.07-.12,42.45,10.28Z";

        function makePath(attrs) {
          const p = document.createElementNS(SVG_NS, "path");
          p.setAttribute("d", outlinePathD);
          Object.entries(attrs).forEach(([k,v]) => p.setAttribute(k, v));
          return p;
        }

        svgBadge.appendChild(makePath({ fill: "none", stroke: "#e6cfa1", "stroke-width": "5", opacity: "0.9" }));
        svgBadge.appendChild(makePath({ fill: "none", stroke: "#b58a44", "stroke-width": "3.5" }));
        svgBadge.appendChild(makePath({ fill: "none", stroke: "#4a3518", "stroke-width": "2.2", opacity: "0.85" }));
        svgBadge.appendChild(makePath({ fill: "none", stroke: "#fff8dc", "stroke-width": "0.8", opacity: "0.6" }));

        // bottom path for slight bend (použijeme pouze pro badge při výpočtu ohybu)
        const bottomPath = document.createElementNS(SVG_NS, "path");
        bottomPath.setAttribute("d", "M0,108 C30,90 166,90 196,108");
        bottomPath.setAttribute("fill", "none");
        bottomPath.setAttribute("stroke", "none");
        svgBadge.appendChild(bottomPath);

        // text group (pro badge chceme konkrétní element, abychom do něj přidávali tspany)
        const textGroup = document.createElementNS(SVG_NS, "text");
        textGroup.setAttribute("text-anchor", "middle");
        textGroup.setAttribute("class", "badge-text");

        // center + baseline pro badge (viewBox 0..196 -> střed = 98)
        textGroup.setAttribute("x", "98");
        textGroup.setAttribute("dominant-baseline", "middle");
        textGroup.setAttribute("xml:space", "preserve");

        svgBadge.appendChild(textGroup);

        // --- TITLE a AUTHOR SVG (pouze vytvoříme tady, text vložíme univerzální funkcí níže)
        const svgTitle = document.createElementNS(SVG_NS, "svg");
        svgTitle.setAttribute("class", "title-svg");
        svgTitle.setAttribute("viewBox", "0 0 196 104");
        svgTitle.setAttribute("width", "40%");
        svgTitle.setAttribute("height", "50%");

        const svgAuthor = document.createElementNS(SVG_NS, "svg");
        svgAuthor.setAttribute("class", "author-svg");
        svgAuthor.setAttribute("viewBox", "0 0 196 104");
        svgAuthor.setAttribute("width", "40%");
        svgAuthor.setAttribute("height", "30%");

        // --- accessibility: přidáme <title> pro čtečky obrazovky
        function ensureSvgTitle(svgEl, text) {
          svgEl.setAttribute('role', 'img');
          let existing = svgEl.querySelector('title');
          if (existing) {
            existing.textContent = text || '';
          } else {
            const titleEl = document.createElementNS(SVG_NS, 'title');
            titleEl.textContent = text || '';
            svgEl.appendChild(titleEl);
          }
        }

        // zavoláme pro oba SVG elementy
        ensureSvgTitle(svgTitle, it.nazov || '');
        ensureSvgTitle(svgAuthor, it.autor || '');

        // --- UNIVERZÁLNÍ FUNKCE PRO VLOŽENÍ TEXTU DO SVG (zachovává chování z badge)
        // async verze — protože čekáme případně na document.fonts.ready
        async function fitTextToSvgAdvanced(svg, text, {
          maxFont = 60,
          minFont = 10,
          padX = 0.10,
          padY = 0.10,
          lineHeight = 1.2,
          className = "badge-text",
          bendPath = null,
          textGroupEl = null,
          enforceMaxByInnerHeight = true
        } = {}) {
          const SVG_NS = "http://www.w3.org/2000/svg";

          // počkej na webfonty (pokud browser podporuje) — zabrání to chybným měřením
          if (document.fonts && document.fonts.status !== "loaded") {
            try { await document.fonts.ready; } catch (_) { /* ignore */ }
          }

          const vb = (svg.viewBox && svg.viewBox.baseVal) ? svg.viewBox.baseVal : { width: 196, height: 104 };
          const vbw = vb.width || 196;
          const vbh = vb.height || 104;

          const padW = vbw * padX;
          const padH = vbh * padY;
          const innerWidth = vbw - 2 * padW;
          const innerHeight = vbh - 2 * padH;

          const rawWords = (text || "").trim().split(/\s+/).filter(Boolean);
          if (rawWords.length === 0) return;

          let textEl = textGroupEl;
          if (!textEl) {
            textEl = document.createElementNS(SVG_NS, "text");
            textEl.setAttribute("class", className);
            textEl.setAttribute("text-anchor", "middle");
            textEl.setAttribute("style", "font-family: inherit;");
            textEl.setAttribute("x", String(vbw / 2));
            textEl.setAttribute("dominant-baseline", "hanging");
            textEl.setAttribute("xml:space", "preserve");
            svg.appendChild(textEl);
          } else {
            while (textEl.firstChild) textEl.removeChild(textEl.firstChild);
          }

          const wasInDOM = document.body.contains(svg);
          let prevStyles = null;
          if (!wasInDOM) {
            prevStyles = {
              position: svg.style.position || "",
              left: svg.style.left || "",
              top: svg.style.top || "",
              visibility: svg.style.visibility || ""
            };
            svg.style.position = "absolute";
            svg.style.left = "-9999px";
            svg.style.top = "-9999px";
            svg.style.visibility = "hidden";
            document.body.appendChild(svg);
          }

          // pomocný měřicí element — explicitně nastavíme stejný class a základní styly
          const tempText = document.createElementNS(SVG_NS, "text");
          tempText.setAttribute("visibility", "hidden");
          tempText.setAttribute("class", className);
          tempText.setAttribute("style", "font-family: inherit; white-space: pre; xml:space: preserve;");
          svg.appendChild(tempText);

          if (innerHeight <= 0) {
            if (svg.contains(tempText)) svg.removeChild(tempText);
            if (!textGroupEl && textEl && svg.contains(textEl)) svg.removeChild(textEl);
            if (!wasInDOM && prevStyles) {
              svg.style.position = prevStyles.position;
              svg.style.left = prevStyles.left;
              svg.style.top = prevStyles.top;
              svg.style.visibility = prevStyles.visibility;
              if (svg.parentNode === document.body) document.body.removeChild(svg);
            }
            return;
          }

          if (innerHeight < minFont) {
            minFont = Math.max(1, Math.floor(innerHeight / lineHeight));
          }

          let totalLen = 0;
          if (bendPath) {
            try { if (typeof bendPath.getTotalLength === "function") totalLen = bendPath.getTotalLength(); } catch (e) { totalLen = 0; }
          }

          const _measureCache = new Map();
          function px(n) { return `${n}px`; }

          function measureWidth(txt, font) {
            const key = `${font}:${txt}`;
            if (_measureCache.has(key)) return _measureCache.get(key);

            // explicitně nastavíme font-size s jednotkou px — to je důležité
            tempText.setAttribute("font-size", px(font));
            tempText.textContent = txt;
            let len = 0;
            try { len = tempText.getComputedTextLength(); } catch (e) { len = 0; }
            _measureCache.set(key, len);
            return len;
          }

          // wrapWords vrátí null pokud existuje slovo, které se nevejde při tomto fontu
          function wrapWords(font) {
            const lines = [];
            let cur = "";
            for (const w of rawWords) {
              if (measureWidth(w, font) > innerWidth) return null; // slovo se nevejde
              const test = cur ? (cur + " " + w) : w;
              if (measureWidth(test, font) <= innerWidth) {
                cur = test;
              } else {
                if (cur) lines.push(cur);
                cur = w;
              }
            }
            if (cur) lines.push(cur);
            return lines;
          }

          let lo = minFont;
          let hi = maxFont;
          if (enforceMaxByInnerHeight) hi = Math.min(hi, Math.floor(innerHeight));
          let bestFont = minFont;
          let bestLines = null;

          while (lo <= hi) {
            const mid = Math.floor((lo + hi) / 2);
            const candidate = wrapWords(mid);
            // vertical fit: počet řádků * mid * lineHeight <= innerHeight
            if (candidate && (candidate.length * mid * lineHeight <= innerHeight)) {
              bestFont = mid;
              bestLines = candidate;
              lo = mid + 1;
            } else {
              hi = mid - 1;
            }
          }

          let fontSize = bestFont;
          let lines = bestLines;

          if (!lines) {
            fontSize = minFont;
            lines = [];
            let cur = "";
            for (const w of rawWords) {
              if (measureWidth(w, fontSize) <= innerWidth) {
                const test = cur ? (cur + " " + w) : w;
                if (measureWidth(test, fontSize) <= innerWidth) {
                  cur = test;
                } else {
                  if (cur) lines.push(cur);
                  cur = w;
                }
              } else {
                if (cur) { lines.push(cur); cur = ""; }
                lines.push(w); // dlouhé slovo jako vlastní řádek
              }
            }
            if (cur) lines.push(cur);
          }

          if (svg.contains(tempText)) svg.removeChild(tempText);

          // vertikalni pozice (jemné ladění)
          const totalTextHeight = fontSize * lineHeight * lines.length;
          const startY = padH + (innerHeight - totalTextHeight) / 2 + fontSize * 0.8;

          // bend offset (pokud)
          let bendOffset = 0;
          try {
            if (totalLen > 0) {
              if (lines.length === 1) bendOffset = 0;
              else {
                const p = bendPath.getPointAtLength(totalLen * 0.5);
                bendOffset = (p.y - vbh) * 0.18;
              }
            }
          } catch (_) { bendOffset = 0; }

          // vykresleni — pozor: only use textLength pokud jsme na minFont (user wanted that)
          for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            const tspan = document.createElementNS(SVG_NS, "tspan");
            tspan.setAttribute("x", String(vbw / 2));
            tspan.setAttribute("y", String(startY + i * fontSize * lineHeight + bendOffset));
            tspan.setAttribute("font-size", px(fontSize));
            tspan.setAttribute("style", "font-family: inherit; white-space: pre; xml:space: preserve;");
            tspan.textContent = line;
            textEl.appendChild(tspan);

            let w;
            try { w = tspan.getComputedTextLength(); } catch (e) { w = 0; }

            // APLIKUJ LENGTH ADJUST JEN KDYŽ JSME NA minFont (nebo jde o jedině extrémně dlouhé slovo)
            const isLongSingleWord = (line === rawWords[0] && rawWords.length === 1) || (line.match(/^\S+$/) && measureWidth(line, minFont) > innerWidth);
            if (w > innerWidth) {
              if (fontSize <= minFont || isLongSingleWord) {
                tspan.setAttribute("textLength", String(innerWidth));
                tspan.setAttribute("lengthAdjust", "spacingAndGlyphs");
              } else {
                // neočekávané: měla by to zabránit — ale jako fallback snížíme font lokálně (mimo binární hledání)
                // zde znovu směr: znejistíme a zmenšíme font dokud se nevleze (bez měnění ostatních řádků)
                let localFont = fontSize;
                while (localFont > minFont && (measureWidth(line, localFont) > innerWidth)) localFont--;
                if (measureWidth(line, localFont) <= innerWidth) {
                  tspan.setAttribute("font-size", px(localFont));
                } else {
                  // pokud ani minFont nestačí, použij textLength
                  tspan.setAttribute("textLength", String(innerWidth));
                  tspan.setAttribute("lengthAdjust", "spacingAndGlyphs");
                }
              }
            }
          }

          if (!wasInDOM && prevStyles) {
            svg.style.position = prevStyles.position;
            svg.style.left = prevStyles.left;
            svg.style.top = prevStyles.top;
            svg.style.visibility = prevStyles.visibility;
            if (svg.parentNode === document.body) document.body.removeChild(svg);
          }
        }

        // Naplánujeme vložení textů — requestAnimationFrame zajistí, že DOM se stihne aktualizovat
        requestAnimationFrame(() => {
          // Badge: použijeme textGroup (který jsme vytvořili) a bottomPath pro ohyb
          fitTextToSvgAdvanced(svgBadge, it.category_nazov || "", {
            maxFont: 60,
            minFont: 34,
            padX: 0.15,
            padY: 0.15,
            lineHeight: 1.2,
            className: "badge-text",
            bendPath: bottomPath,
            textGroupEl: textGroup
          });

          // Title a Author: bez bendPath, jiná velikost písma
          fitTextToSvgAdvanced(svgTitle, it.nazov || "", {
            maxFont: 142,
            minFont: 80,
            padX: 0.05,
            padY: 0.08,
            lineHeight: 1.2,
            className: "book-title"
          });

          fitTextToSvgAdvanced(svgAuthor, it.autor || "", {
            maxFont: 70,
            minFont: 40,
            padX: 0.05,
            padY: 0.10,
            lineHeight: 1.2,
            className: "book-author"
          });
        });

        // Přidání do meta a do DOM
        meta.appendChild(svgBadge);
        meta.appendChild(svgTitle);
        meta.appendChild(svgAuthor);

        // inner musí existovat — ujisti se, že proměnná inner je definovaná ve vyšším scope
        inner.appendChild(meta);
      }

        const coverWrap = document.createElement('div');
        coverWrap.className = 'cover-wrap';
        coverWrap.style.transformStyle = 'preserve-3d';

        const img = document.createElement('img');
        img.className = 'book-cover';
        img.setAttribute('alt', it.nazov || '');
        img.dataset.src = imgSrc;
        img.onerror = function () { this.onerror = null; this.src = IMG_FALLBACK; };
        img.setAttribute('loading', 'lazy');

        coverWrap.appendChild(img);

        coverWrap.classList.add('open-detail');
        coverWrap.setAttribute('role', 'button');
        coverWrap.setAttribute('aria-label', `Otvori\u0165 ${it.nazov || 'kniha'}`);
        coverWrap.tabIndex = 0;

        coverWrap.dataset.title = it.nazov || '';
        coverWrap.dataset.author = it.autor || '';
        coverWrap.dataset.desc = it.popis || it.popis_short || '';
        coverWrap.dataset.cover = imgSrc;
        coverWrap.dataset.pdf = it.pdf || '';

        inner.appendChild(coverWrap);

        art.appendChild(inner);
        frag.appendChild(art);
      });

      booksGrid.appendChild(frag);

      // visuals + behaviors
      // <-- swapped: observe cards and apply frames when they enter viewport -->
      applyFramesWhenVisible(booksGrid);
      lazyLoadImages(booksGrid);
      revealStagger(booksGrid);

      booksGrid.classList.remove('fade-out');
      booksGrid.classList.add('fade-in');
      setTimeout(() => booksGrid.classList.remove('fade-in'), TIMINGS.FADE_IN_REMOVE_MS);
    }, TIMINGS.RENDER_DELAY_MS);
  }

  // ---------- modal handling (open/close) ----------
  function openModal(d) {
    if (!modal) return;
    modalCover.src = d.cover || IMG_FALLBACK;
    modalTitle.textContent = d.title || '';
    modalAuthor.textContent = d.author || '';
    modalDesc.textContent = d.desc || '';
    modalDownload.href = d.pdf || '#';
    modal.setAttribute('aria-hidden', 'false');
    try { modal.dataset._prevFocus = document.activeElement ? document.activeElement.id || document.activeElement.tagName : ''; } catch (e) {}
    modal.focus && modal.focus();
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    if (!modal) return;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    try {
      const prev = modal.dataset._prevFocus;
      if (prev) document.getElementById(prev)?.focus();
    } catch (e) {}
  }
  modalClose?.addEventListener('click', closeModal);
  modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal?.getAttribute('aria-hidden') === 'false') closeModal(); });

  // ---------- delegated click for details (one-time) ----------
  if (booksGrid) {
    booksGrid.addEventListener('click', e => {
      const card = e.target.closest('.book-card'); // hledá nejbližší obal knihy
      if (!card) return;

      openModal({
        title: card.dataset.title,
        author: card.dataset.author,
        desc: card.dataset.desc,
        cover: card.dataset.cover,
        pdf: card.dataset.pdf
      });
    });
  }

  // ---------- rotation ----------
  function startRotation() {
    stopRotation();
    rotationInterval = setInterval(() => {
      if (rotationPaused) return;
      const limit = detectLimit();
      const items = takeNextWindow(limit);
      if (items && items.length) renderBooks(items);
    }, TIMINGS.ROTATE_MS);
  }
  function stopRotation() { if (rotationInterval) { clearInterval(rotationInterval); rotationInterval = null; } }

  // ---------- helper: sliding window ----------
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
    }, TIMINGS.WINDOW_RESIZE_DEBOUNCE_MS);
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
    }, TIMINGS.SEARCH_DEBOUNCE_MS);
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

}); // DOMContentLoaded end