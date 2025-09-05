// resize-title.js (OPRAVENO)
// Inteligentní škálování .section-title + span (řeší unitless line-height a reset inline font-size)

;(function () {
  const DEFAULTS = {
    minFontSize: 8,
    maxExtraLinesCap: 3,
    spanShrinkExponent: 1.6,
    charSample: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ěščřžýáíéóúůťďň ĚŠČŘŽÝÁÍÉÓÚŮŤĎŇ,.- '
  };

  function parsePx(value, fallback = 0) {
    const v = parseFloat(value);
    return Number.isFinite(v) ? v : fallback;
  }
  function parseNumber(val, fallback = NaN) {
    if (val === null || val === undefined) return fallback;
    const s = String(val).trim();
    if (s === '') return fallback;
    const m = s.match(/^(-?[\d.]+)/);
    return m ? parseFloat(m[1]) : fallback;
  }
  function cssVar(el, name) {
    const v = getComputedStyle(el).getPropertyValue(name);
    return v ? v.trim() : '';
  }
  function getFontShorthand(style, fontSize) {
    const fontStyle = style.fontStyle || 'normal';
    const fontWeight = style.fontWeight || '400';
    const family = style.fontFamily || 'sans-serif';
    return `${fontStyle} ${fontWeight} ${fontSize}px ${family}`;
  }
  function estimateAverageCharWidth(el, fontSize) {
    try {
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      const style = getComputedStyle(el);
      ctx.font = getFontShorthand(style, fontSize);
      const sample = DEFAULTS.charSample;
      const w = ctx.measureText(sample).width;
      return Math.max(4, w / sample.length);
    } catch (e) {
      return Math.max(6, fontSize * 0.45);
    }
  }

  function computeAllowedLines(el, fontSize, allowedLinesOverride) {
    if (Number.isFinite(allowedLinesOverride) && allowedLinesOverride > 0) {
      // PATCH: respektuj přímo hodnotu override (např. data-max-lines="4")
      return Math.max(1, Math.floor(allowedLinesOverride));
    }

    const text = (el.textContent || '').trim();
    const totalChars = Math.max(1, text.length);
    const avgCharW = estimateAverageCharWidth(el, fontSize);
    const charsPerLine = Math.max(6, Math.floor((el.clientWidth || 200) / avgCharW));
    const estimatedLinesNeeded = Math.ceil(totalChars / charsPerLine);

    let cap = DEFAULTS.maxExtraLinesCap;
    if (window.innerWidth >= 1400) cap = Math.min(cap, 2);
    else if (window.innerWidth >= 900) cap = Math.min(cap, 2);
    else if (window.innerWidth >= 600) cap = Math.min(cap, 3);
    else cap = Math.min(cap, 4);

    const dataMax = parseInt(el.dataset.maxLines || '', 10);
    if (Number.isFinite(dataMax) && dataMax > 0) cap = dataMax;

    // původně: Math.max(1, Math.min(estimatedLinesNeeded, cap))
    return Math.max(1, Math.min(estimatedLinesNeeded, cap));
  }

  function getLineHeightPx(el, fontSize) {
    const style = getComputedStyle(el);
    const lhRaw = style.lineHeight;
    if (!lhRaw || lhRaw === 'normal') return Math.round(fontSize * 1.15);

    const s = String(lhRaw).trim();
    // pokud obsahuje 'px' -> parse as px
    if (s.endsWith('px')) {
      return parsePx(s, Math.round(fontSize * 1.15));
    }
    // pokud je unitless (např. "1.04") -> treat as multiplier
    const num = parseFloat(s);
    if (Number.isFinite(num) && Math.abs(num) > 0.01) {
      return Math.round(fontSize * num);
    }
    // fallback
    return Math.round(fontSize * 1.15);
  }

  function fitsAtFontSize(el, span, titleFontSize, spanFontBase, titleFontBase, allowedHeight, exponent) {
    el.style.fontSize = titleFontSize + 'px';
    if (span && spanFontBase != null) {
      const newSpan = spanFontBase * Math.pow(titleFontSize / titleFontBase, exponent);
      span.style.fontSize = newSpan + 'px';
    }
    // menší tolerance
    return el.scrollHeight <= allowedHeight + 1; // zvýšená tolerance o 1px
  }

  function resizeTitle(el) {
    if (!el) return;

    // --- RESET INLINE FONT SIZE PŘED MĚŘENÍM (důležité) ---
    el.style.whiteSpace = '';
    el.style.overflow = '';
    el.style.textOverflow = '';
    // vyčistit inline font-size, aby getComputedStyle četl CSS hodnoty
    el.style.fontSize = '';
    const span = el.querySelector('span');
    if (span) span.style.fontSize = '';

    // vždy resetuj i pomocnou třídu (aby nezůstala z minulého průchodu)
    el.classList.remove('rt--compact');

    // nyní bezpečně načti computed styl (to je MAX z CSS)
    const style = getComputedStyle(el);
    const titleBase = parsePx(style.fontSize, 16);
    const spanBase = span ? parsePx(getComputedStyle(span).fontSize, titleBase * 0.75) : null;

    // načti parametry (data-* > CSS var > DEFAULTS)
    const dataMin = parseNumber(el.dataset.minFontSize, NaN);
    const cssMin = parseNumber(cssVar(el, '--rt-min-font-size'), NaN);
    const minFontSize = Number.isFinite(dataMin) ? dataMin
                        : (Number.isFinite(cssMin) ? cssMin : DEFAULTS.minFontSize);

    const dataMaxLines = parseNumber(el.dataset.maxLines, NaN);
    const cssMaxLines = parseNumber(cssVar(el, '--rt-max-lines'), NaN);
    const allowedLinesOverride = Number.isFinite(dataMaxLines) ? dataMaxLines
                                : (Number.isFinite(cssMaxLines) ? cssMaxLines : NaN);

    const dataSpanExp = parseNumber(el.dataset.spanExp, NaN);
    const cssSpanExp = parseNumber(cssVar(el, '--rt-span-exp'), NaN);
    const spanExp = Number.isFinite(dataSpanExp) ? dataSpanExp
                    : (Number.isFinite(cssSpanExp) ? cssSpanExp : DEFAULTS.spanShrinkExponent);

    const cssOverflow = (cssVar(el, '--rt-overflow') || '').toLowerCase() || 'ellipsis';

    // načti compact threshold (priorita: data-* > CSS var > default 18px)
    const dataCompact = parseNumber(el.dataset.compactAt, NaN);
    const cssCompact = parseNumber(cssVar(el, '--rt-compact-at'), NaN);
    const compactThreshold = Number.isFinite(dataCompact)
      ? dataCompact
      : (Number.isFinite(cssCompact) ? cssCompact : 18);

    // spočítej dovolené rozměry
    const allowedLines = computeAllowedLines(el, titleBase, allowedLinesOverride);
    const lineHeight = getLineHeightPx(el, titleBase);
    const allowedHeight = lineHeight * (allowedLines + 0.18);


    // pokud se to vejde hned, obnovíme původní styl (max = CSS hodnoty)
    if (el.scrollHeight <= allowedHeight) {
      el.style.fontSize = titleBase + 'px';
      if (span && spanBase != null) span.style.fontSize = spanBase + 'px';
      el.classList.remove('rt--too-small');
      return;
    }

    // binary search: hledáme největší písmo mezi minFontSize a titleBase
    let low = minFontSize;
    let high = titleBase;
    let best = minFontSize;

    for (let i = 0; i < 20; i++) {
      const mid = (low + high) / 2;
      if (fitsAtFontSize(el, span, mid, spanBase, titleBase, allowedHeight, spanExp)) {
        best = mid;
        low = mid;
      } else {
        high = mid;
      }
    }

    const finalTitle = Math.max(minFontSize, best);
    el.style.fontSize = finalTitle.toFixed(2) + 'px';
    if (span && spanBase != null) {
      const newSpan = spanBase * Math.pow(finalTitle / titleBase, spanExp);

      if (newSpan < compactThreshold) {
        // fallback: vypnout inline override a dát CSS volnost
        el.classList.add('rt--compact');
        span.style.fontSize = '';
      } else {
        // běžné chování
        span.style.fontSize = Math.max(6, newSpan).toFixed(2) + 'px';
      }
    }

    // fallback
    if (el.scrollHeight > allowedHeight + 1) {
      el.classList.add('rt--too-small');
      if (cssOverflow === 'wrap' || cssOverflow === 'none') {
        // necháme zalomit a necháme CSS fallback styly upravit vzhled
      } else {
        el.style.whiteSpace = 'nowrap';
        el.style.overflow = 'hidden';
        el.style.textOverflow = 'ellipsis';
      }
    } else {
      el.classList.remove('rt--too-small');
    }
  }

  function initResizeTitles() {
    const titles = Array.from(document.querySelectorAll('.section-title'));
    if (!titles.length) return;

    const runAll = () => titles.forEach(el => {
      try { resizeTitle(el); } catch (e) { /* safe */ }
    });

    runAll();

    let tId = null;
    window.addEventListener('resize', () => {
      if (tId) clearTimeout(tId);
      tId = setTimeout(runAll, 110);
    });

    const ro = new ResizeObserver(runAll);
    titles.forEach(el => ro.observe(el));

    const mo = new MutationObserver(runAll);
    titles.forEach(el => mo.observe(el, { childList: true, subtree: true, characterData: true }));
  }

  window.resizeTitle = resizeTitle;
  window.initResizeTitles = initResizeTitles;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initResizeTitles);
  } else {
    initResizeTitles();
  }

})();