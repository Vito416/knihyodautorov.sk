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
    // set initial pre-transition state and per-card transition-delay (no per-card setTimeouts)
    cards.forEach((c, idx) => {
      c.style.opacity = '0';
      c.style.transform = 'translateY(20px) rotateX(6deg)';
      // keep transition definition inline to avoid dependency on external CSS changes
      c.style.transition = 'transform .45s cubic-bezier(.2,.8,.2,1), opacity .45s';
      c.style.transitionDelay = (TIMINGS.REVEAL_BASE_MS + idx * TIMINGS.REVEAL_STEP_MS) + 'ms';
    });

    // Force a single reflow so that the browser registers the initial styles,
    // then add the revealed class to trigger the transitions with the delays above.
    // This avoids creating many JS timers while preserving the exact delays.
    void (root.offsetHeight);

    // add class so CSS rules for '.book-card.book-card-revealed' apply
    cards.forEach(c => c.classList.add('book-card-revealed'));

    // clear inline starting styles so CSS-defined end-state can transition to
    cards.forEach(c => {
      c.style.opacity = '';
      c.style.transform = '';
      // keep transition and transitionDelay inline; optionally remove if using CSS
      // c.style.transition = '';
    });
  }

  // ---------- image preloader ----------
  async function tryImagePathsSimple(original, timeout = TIMINGS.TRYIMAGE_PRELOAD_TIMEOUT_MS) {
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

  // ---------- image resizing/canvas + fetch helper ----------
  async function makeResizedObjectURL(srcUrl, dstPxW, dstPxH, crossOrigin) {
    // normalize
    dstPxW = Math.max(1, Math.round(dstPxW || 1));
    dstPxH = Math.max(1, Math.round(dstPxH || 1));

    try {
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

      const blob = await resp.blob();

      // 2) try createImageBitmap (preferred)
      let bitmap = null;
      try {
        bitmap = await createImageBitmap(blob);
      } catch (e) {
        // createImageBitmap may not be available; we'll fallback to Image element
        bitmap = null;
      }

      // 3) prepare canvas and draw (either from bitmap or from Image)
      const canvas = document.createElement('canvas');
      canvas.width = dstPxW;
      canvas.height = dstPxH;
      const ctx = canvas.getContext('2d');

      if (bitmap) {
        // draw bitmap stretched to canvas size (this deforms to exactly dstPxW/dstPxH)
        ctx.drawImage(bitmap, 0, 0, bitmap.width, bitmap.height, 0, 0, canvas.width, canvas.height);
      } else {
        // fallback: create tmp image from blob and draw it
        const tmpUrl = URL.createObjectURL(blob);
        try {
          await new Promise((resolve, reject) => {
            const img = new Image();
            if (crossOrigin) img.crossOrigin = crossOrigin;
            let done = false;
            img.onload = () => { if (done) return; done = true; resolve(img); };
            img.onerror = (err) => { if (done) return; done = true; reject(err); };
            img.src = tmpUrl;
            // small safety timeout
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
        // Use PNG for lossless; change to 'image/jpeg' + quality if you want smaller files
        canvas.toBlob((outBlob) => {
          if (!outBlob) return resolve(srcUrl); // fallback to original url if export failed
          resolve(URL.createObjectURL(outBlob));
        }, 'image/png');
      });

    } catch (err) {
      // If anything fails (likely CORS), return original URL as fallback
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

    // helper: fetch + resize into exact pixel dims -> return objectURL (blob:)
    async function makeResizedObjectURL(srcUrl, dstPxW, dstPxH, crossOrigin) {
      // normalize
      dstPxW = Math.max(1, Math.round(dstPxW || 1));
      dstPxH = Math.max(1, Math.round(dstPxH || 1));

      try {
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

        const blob = await resp.blob();

        // 2) try createImageBitmap (preferred)
        let bitmap = null;
        try {
          bitmap = await createImageBitmap(blob);
        } catch (e) {
          // createImageBitmap may not be available; we'll fallback to Image element
          bitmap = null;
        }

        // 3) prepare canvas and draw (either from bitmap or from Image)
        const canvas = document.createElement('canvas');
        canvas.width = dstPxW;
        canvas.height = dstPxH;
        const ctx = canvas.getContext('2d');

        if (bitmap) {
          // draw bitmap stretched to canvas size (this deforms to exactly dstPxW/dstPxH)
          ctx.drawImage(bitmap, 0, 0, bitmap.width, bitmap.height, 0, 0, canvas.width, canvas.height);
        } else {
          // fallback: create tmp image from blob and draw it
          const tmpUrl = URL.createObjectURL(blob);
          try {
            await new Promise((resolve, reject) => {
              const img = new Image();
              if (crossOrigin) img.crossOrigin = crossOrigin;
              let done = false;
              img.onload = () => { if (done) return; done = true; resolve(img); };
              img.onerror = (err) => { if (done) return; done = true; reject(err); };
              img.src = tmpUrl;
              // small safety timeout
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
          // Use PNG for lossless; change to 'image/jpeg' + quality if you want smaller files
          canvas.toBlob((outBlob) => {
            if (!outBlob) return resolve(srcUrl); // fallback to original url if export failed
            resolve(URL.createObjectURL(outBlob));
          }, 'image/png');
        });

      } catch (err) {
        // If anything fails (likely CORS), return original URL as fallback
        return srcUrl;
      }
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
        try { coverUrl = await tryImagePathsSimple(coverUrlRaw, TIMINGS.TRYIMAGE_PRELOAD_TIMEOUT_MS); } catch (e) { coverUrl = IMG_FALLBACK; }

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
        else p.setAttribute('d', `M ${vb.x} ${vb.y} h ${vb.w} v ${vb.h} h ${-vb.w} z`); // full rect fallback
        cp.appendChild(p);
        localDefs.appendChild(cp);
        svgEl.appendChild(localDefs);

        const imgNode = document.createElementNS(svgNS, 'image');
        imgNode.setAttribute('class', 'cover-image cover-generated');
        if (opt.CROSSORIGIN) imgNode.setAttribute('crossorigin', opt.CROSSORIGIN);
        // attach local clip-path
        imgNode.setAttribute('clip-path', `url(#${localCpId})`);

        // DON'T set href yet — set after initial sizing to avoid flicker
        // append image and frame (frame on top)
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

        // insert svg quickly (so layout stabilizes)
        coverWrap.insertBefore(svgEl, coverWrap.firstChild);

        // two RAFs
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

        // ensure svg pixel sizing updated BEFORE calculating px ratios
        updateSvgPixelSize(svgEl, coverWrap);

        // measure actual svg pixels and compute pxPerUnit
        const wrapRect = coverWrap.getBoundingClientRect();
        const svgPixelW = (isFinite(wrapRect.width) && wrapRect.width > 0) ? wrapRect.width : coverWrap.clientWidth || vb.w;
        const svgPixelH = (isFinite(wrapRect.height) && wrapRect.height > 0) ? wrapRect.height : coverWrap.clientHeight || vb.h;
        const pxPerUnitX = svgPixelW / vb.w;
        const pxPerUnitY = svgPixelH / vb.h;

        // compute path bbox in px
        const holePx = {
          x: (pathBBox.x - vb.x) * pxPerUnitX,
          y: (pathBBox.y - vb.y) * pxPerUnitY,
          width: pathBBox.width * pxPerUnitX,
          height: pathBBox.height * pxPerUnitY
        };

        // compute final dims in px (what we want at the end)
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

        // --- compute initial (shrink-only) px state and apply IMMEDIATELY ---
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

        // convert px -> user units for initial
        const initialUser = {
          x: vb.x + (initialPx.x / pxPerUnitX),
          y: vb.y + (initialPx.y / pxPerUnitY),
          width: initialPx.width / pxPerUnitX,
          height: initialPx.height / pxPerUnitY,
          preserve: initialPx.preserve
        };

        // set initial attributes IMMEDIATELY (shrink into hole)
        imgNode.setAttribute('preserveAspectRatio', initialUser.preserve);
        imgNode.setAttribute('x', String(initialUser.x));
        imgNode.setAttribute('y', String(initialUser.y));
        imgNode.setAttribute('width', String(initialUser.width));
        imgNode.setAttribute('height', String(initialUser.height));

        // force a reflow/read to ensure browser applied attributes before moving
        void svgEl.getBoundingClientRect();

// --- LOAD image as blob/objectURL and set href AFTER initial attributes are applied ---
let objectUrl = null;
try {
  // vytváří menší bitmapu přes canvas podle holePx (pixelové rozměry)
  // použijeme holePx.width/height (v pixelech) — proto Math.round
  objectUrl = await makeResizedObjectURL(coverUrl, Math.round(holePx.width), Math.round(holePx.height), opt.CROSSORIGIN);
} catch (e) {
  objectUrl = coverUrl;
}


        // set href in both modern and xlink namespaces
        try {
          imgNode.setAttributeNS(null, 'href', objectUrl);
          imgNode.setAttributeNS(xlinkNS, 'xlink:href', objectUrl);
        } catch (e) {
          // last-ditch
          imgNode.setAttribute('href', objectUrl);
        }

        // convert finalPx -> user units
        const finalUser = {
          x: vb.x + (finalPx.x / pxPerUnitX),
          y: vb.y + (finalPx.y / pxPerUnitY),
          width: finalPx.width / pxPerUnitX,
          height: finalPx.height / pxPerUnitY,
          preserve: finalPx.preserve
        };

        // wait a tick to let image start decoding, then apply final sizing (two RAFs)
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

        imgNode.setAttribute('preserveAspectRatio', finalUser.preserve);
        imgNode.setAttribute('x', String(finalUser.x));
        imgNode.setAttribute('y', String(finalUser.y));
        imgNode.setAttribute('width', String(finalUser.width));
        imgNode.setAttribute('height', String(finalUser.height));

        // final pixel sizing as safety
        updateSvgPixelSize(svgEl, coverWrap);

        // cleanup objectURL after a moment (allow browser to keep it cached/rendered)
        if (objectUrl && objectUrl.startsWith('blob:')) {
          setTimeout(() => { try { URL.revokeObjectURL(objectUrl); } catch (e) {} }, TIMINGS.OBJECTURL_LIFETIME_MS);
        }

      } catch (err) {
        console.error('applyFrameToCards (fixed-scale v2) error', err);
      }
    } // end for

    // helper: stable hash (unchanged)
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

  // ---------- render logic (safe DOM creation) ----------
  function renderBooks(items) {
    if (!booksGrid) return;
    booksGrid.classList.add('fade-out');
    setTimeout(() => {
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
        art.dataset.titleLower = (it.nazov || '').toLowerCase();
        art.dataset.titleSearch = (it.nazov || '').toLowerCase();

        const imgSrc = (it.obrazok && it.obrazok.trim()) ? it.obrazok.trim() : IMG_FALLBACK;
        art.dataset.cover = imgSrc;

        const inner = document.createElement('div');
        inner.className = 'card-inner';

        if (it.category_nazov) {
          const meta = document.createElement('div');
          meta.className = 'card-meta';
          const badge = document.createElement('span');
          badge.className = 'badge';
          badge.textContent = it.category_nazov;
          meta.appendChild(badge);

          const h3 = document.createElement('h3');
          h3.className = 'book-title';
          h3.textContent = it.nazov || '';
          meta.appendChild(h3);

          const pAuthor = document.createElement('p');
          pAuthor.className = 'book-author';
          pAuthor.textContent = it.autor || '';
          meta.appendChild(pAuthor);

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

        // make cover itself the activator (accessible)
        coverWrap.classList.add('open-detail');
        coverWrap.setAttribute('role', 'button');
        coverWrap.setAttribute('aria-label', `Otvori\u0165 ${it.nazov || 'kniha'}`);
        coverWrap.tabIndex = 0; // focusable by keyboard

        // move data used by modal to coverWrap
        coverWrap.dataset.title = it.nazov || '';
        coverWrap.dataset.author = it.autor || '';
        coverWrap.dataset.desc = it.popis || it.popis_short || '';
        coverWrap.dataset.cover = imgSrc;
        coverWrap.dataset.pdf = it.pdf || '';

        inner.appendChild(coverWrap);
        // (není potřeba přidávat tlačítko)

        art.appendChild(inner);
        frag.appendChild(art);
      });

      booksGrid.appendChild(frag);

      // visuals + behaviors
      applyFrameToCards(booksGrid).catch(e => console.warn('applyFrameToCards error', e));
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
    // store focus
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
      const btn = e.target.closest('.open-detail');
      if (!btn) return;
      openModal({
        title: btn.dataset.title,
        author: btn.dataset.author,
        desc: btn.dataset.desc,
        cover: btn.dataset.cover,
        pdf: btn.dataset.pdf
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