// /eshop/js/index.js
// Konsolidované skripty pro shop: menu, reveal, carousel, parallax, skeleton loader, btt.
// Bez externích knihoven. Progressive enhancement + reduced-motion friendly.

(function () {
  'use strict';

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* -------------------- helpery -------------------- */
  function qs(sel, ctx = document) { return ctx.querySelector(sel); }
  function qsa(sel, ctx = document) { return Array.from(ctx.querySelectorAll(sel)); }

  /* -------------------- mobile menu toggle -------------------- */
  function initMenuToggle() {
    const toggle = qs('.menu-toggle');
    const nav = qs('.nav');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      nav.classList.toggle('open');
      document.body.classList.toggle('nav-open');
    });
  }

  /* -------------------- back to top -------------------- */
  function initBackToTop() {
    const btt = qs('#back-to-top');
    if (!btt) return;
    const showAt = 400;
    function update() {
      if (window.scrollY > showAt) btt.style.display = 'block';
      else btt.style.display = 'none';
    }
    window.addEventListener('scroll', () => { if (!prefersReducedMotion) update(); }, { passive: true });
    // immediate call
    update();
    btt.addEventListener('click', (e) => {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
    });
  }

  /* -------------------- reveal on scroll (IO) -------------------- */
  function initReveal() {
    const elements = qsa('[data-animate]');
    if (!elements.length) return;

    if ('IntersectionObserver' in window && !prefersReducedMotion) {
      const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
          if (e.isIntersecting) {
            e.target.classList.add('visible');
            io.unobserve(e.target);
          }
        });
      }, { root: null, rootMargin: '0px', threshold: 0.12 });

      elements.forEach(el => io.observe(el));
    } else {
      // fallback: simple on scroll check
      const reveal = () => {
        const H = window.innerHeight;
        elements.forEach(el => {
          const r = el.getBoundingClientRect();
          if (r.top < H * 0.9) el.classList.add('visible');
        });
      };
      window.addEventListener('scroll', reveal, { passive: true });
      window.addEventListener('load', reveal);
      reveal();
    }
  }

  /* -------------------- simple carousel (horizontal scroll) -------------------- */
  function initCarousel() {
    const track = qs('.carousel__track');
    const btnPrev = qs('.carousel__btn[data-action="prev"]');
    const btnNext = qs('.carousel__btn[data-action="next"]');
    if (!track || !btnPrev || !btnNext) return;

    const step = () => Math.max(300, Math.floor(track.clientWidth * 0.6));

    btnPrev.addEventListener('click', () => track.scrollBy({ left: -step(), behavior: 'smooth' }));
    btnNext.addEventListener('click', () => track.scrollBy({ left: step(), behavior: 'smooth' }));
  }

  /* -------------------- parallax for shop-header -------------------- */
  function initParallax() {
    const header = qs('.shop-header::before') ? null : qs('.shop-header');
    if (!header || prefersReducedMotion) return;
    // lightweight transform based on scroll
    window.addEventListener('scroll', () => {
      const y = Math.min(120, window.scrollY * 0.08);
      header.style.transform = `translateY(${y}px)`;
    }, { passive: true });
  }

  /* -------------------- skeleton loader for product cards -------------------- */
  function initSkeletons() {
    const cards = qsa('.book-card');
    if (!cards.length) return;

    cards.forEach(card => {
      // initially add skeleton if image present or absent
      card.classList.add('skeleton');
      const img = card.querySelector('img');
      if (img) {
        if (img.complete && img.naturalWidth !== 0) {
          // already loaded
          card.classList.remove('skeleton');
        } else {
          img.addEventListener('load', () => card.classList.remove('skeleton'));
          img.addEventListener('error', () => card.classList.remove('skeleton'));
        }
      } else {
        // no image -> remove skeleton (we show fallback block)
        card.classList.remove('skeleton');
      }
    });
  }

  /* -------------------- smooth internal anchors (progressive) -------------------- */
  function initSmoothAnchors() {
    const anchors = qsa('a[href^="#"]');
    if (!anchors.length) return;
    anchors.forEach(a => {
      a.addEventListener('click', (e) => {
        const href = a.getAttribute('href');
        if (!href || href === '#') return;
        const target = document.querySelector(href);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
      });
    });
  }

  /* -------------------- highlight keyboard focus for accessibility -------------------- */
  function initFocusVisible() {
    function handleFirstTab(e) {
      if (e.key === 'Tab') document.body.classList.add('show-focus');
      window.removeEventListener('keydown', handleFirstTab);
    }
    window.addEventListener('keydown', handleFirstTab);
  }

  /* -------------------- initialize everything -------------------- */
  function init() {
    initMenuToggle();
    initBackToTop();
    initReveal();
    initCarousel();
    initParallax();
    initSkeletons();
    initSmoothAnchors();
    initFocusVisible();

    // small enhancement: remove 'loading' class from body when page is interactive
    document.body.classList.remove('loading');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
