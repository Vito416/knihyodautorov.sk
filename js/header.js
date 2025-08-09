/* header.js - final patch
   - správné pravé zarovnání navigace (margin-left:auto)
   - dynamické nastavení top a maxHeight pro mobilní menu (jen pro <=768)
   - progress bar s rAF a throttlingem
   - jemný parallax loga (respekt k prefers-reduced-motion)
   - správa lock-scroll bez content shift (no-scroll + top)
   - click-outside, ESC zavření, cleanup
*/

document.addEventListener("DOMContentLoaded", function () {
  const header = document.querySelector(".site-header");
  const hamburger = document.querySelector(".hamburger");
  const nav = document.querySelector(".main-nav");
  const logoImg = document.querySelector(".logo img");
  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  let isMenuOpen = false;
  let lastScroll = 0;
  let rafScheduled = false;
  let progressEl = null;

  if (!header) return;

  /* vytvoří progress element, pokud neexistuje */
  function ensureProgress() {
    progressEl = document.querySelector(".top-progress");
    if (!progressEl) {
      progressEl = document.createElement("div");
      progressEl.className = "top-progress";
      header.appendChild(progressEl);
    }
  }
  ensureProgress();

  /* nastaví top a maxHeight pouze pokud jsme v mobilním breakpointu */
  function setMobileNavPosition() {
    if (!nav) return;
    const isMobile = window.innerWidth <= 768;
    const headerHeight = Math.ceil(header.getBoundingClientRect().height || 0);

    if (isMobile) {
      nav.style.top = headerHeight + "px";
      nav.style.maxHeight = (window.innerHeight - headerHeight) + "px";
      nav.style.left = "0";
      nav.style.right = "0";
      nav.style.width = "100%";
      nav.style.boxSizing = "border-box";
    } else {
      // reset na desktop - necháme v toku dokumentu
      nav.style.top = "";
      nav.style.maxHeight = "";
      nav.style.left = "";
      nav.style.right = "";
      nav.style.width = "";
      nav.style.boxSizing = "";
      // při přepnutí na desktop zavřít menu, pokud je otevřené
      if (isMenuOpen) toggleMenu(false);
    }
  }

  /* update progress - rAF throttle */
  function updateProgress() {
    const doc = document.documentElement;
    const scrollTop = (window.pageYOffset || doc.scrollTop) - (doc.clientTop || 0);
    const scrollHeight = doc.scrollHeight - doc.clientHeight;
    const pct = scrollHeight > 0 ? Math.min(100, Math.max(0, (scrollTop / scrollHeight) * 100)) : 0;
    if (progressEl) progressEl.style.width = pct + "%";
  }

  /* jemný parallax loga */
  function updateParallax() {
    if (!logoImg || reducedMotion) return;
    // limit posunu v px
    const maxTranslate = 6;
    const scrollY = Math.max(0, window.scrollY || window.pageYOffset);
    const docH = window.innerHeight || document.documentElement.clientHeight;
    const t = Math.min(1, scrollY / (docH * 0.6));
    const translateY = (t * maxTranslate).toFixed(2);
    logoImg.style.transform = `translateY(${translateY}px)`;
  }

  /* scroll handler přes rAF */
  function onScroll() {
    lastScroll = window.scrollY || window.pageYOffset;
    if (!rafScheduled) {
      rafScheduled = true;
      window.requestAnimationFrame(() => {
        // scrolled class
        if (lastScroll > 50) header.classList.add("scrolled");
        else header.classList.remove("scrolled");
        updateProgress();
        updateParallax();
        rafScheduled = false;
      });
    }
  }

  /* helper: zavřít menu při kliknutí mimo */
  function onDocClick(e) {
    if (!nav) return;
    if (nav.contains(e.target) || (hamburger && hamburger.contains(e.target))) return;
    toggleMenu(false);
  }

  /* lock scroll: použijeme .no-scroll a top pro zachování pozice bez shiftu */
  function lockBodyScroll() {
    const scrollY = window.scrollY || window.pageYOffset;
    document.documentElement.classList.add("no-scroll");
    document.body.classList.add("no-scroll");
    document.documentElement.style.top = `-${scrollY}px`;
    document.body.style.top = `-${scrollY}px`;
    // uložíme do dataset aktuální posun
    document.documentElement.dataset.scrollY = scrollY;
  }
  function unlockBodyScroll() {
    const saved = parseInt(document.documentElement.dataset.scrollY || "0", 10);
    document.documentElement.classList.remove("no-scroll");
    document.body.classList.remove("no-scroll");
    document.documentElement.style.top = "";
    document.body.style.top = "";
    // obnovit scroll pozici
    window.scrollTo(0, saved || 0);
    document.documentElement.dataset.scrollY = 0;
  }

  /* toggle mobile menu */
  function toggleMenu(force) {
    if (!nav || !hamburger) return;
    if (typeof force === "boolean") isMenuOpen = force;
    else isMenuOpen = !isMenuOpen;

    if (isMenuOpen) {
      setMobileNavPosition();
      nav.classList.add("open");
      hamburger.setAttribute("aria-expanded", "true");
      // lock scroll pomocí no-scroll
      lockBodyScroll();
      // click outside listener
      setTimeout(() => document.addEventListener("click", onDocClick), 0);
    } else {
      nav.classList.remove("open");
      hamburger.setAttribute("aria-expanded", "false");
      document.removeEventListener("click", onDocClick);
      unlockBodyScroll();
    }
  }

  /* inicializace */
  setMobileNavPosition();
  updateProgress();
  updateParallax();

  /* event listeners */
  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", () => setTimeout(setMobileNavPosition, 80));
  window.addEventListener("orientationchange", () => setTimeout(setMobileNavPosition, 120));

  if (hamburger) {
    hamburger.addEventListener("click", function (e) {
      e.stopPropagation();
      toggleMenu();
    });
  }

  // ESC k zavření
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && isMenuOpen) toggleMenu(false);
  });

  // cleanup
  window.addEventListener("unload", function () {
    // restore styles
    document.documentElement.classList.remove("no-scroll");
    document.body.classList.remove("no-scroll");
    document.documentElement.style.top = "";
    document.body.style.top = "";
  });
});
