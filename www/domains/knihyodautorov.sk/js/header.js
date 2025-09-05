document.addEventListener("DOMContentLoaded", function() {
  const header = document.querySelector(".site-header");
  const hamburger = document.querySelector(".hamburger");
  const nav = document.querySelector(".main-nav");
  let isMenuOpen = false;
  let rafScheduled = false;
  let progressEl = null;

  if (!header) return;

  function ensureProgress() {
    progressEl = document.querySelector(".top-progress");
    if (!progressEl) {
      progressEl = document.createElement("div");
      progressEl.className = "top-progress";
      header.appendChild(progressEl);
    }
  }
  ensureProgress();

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
      nav.style.top = "";
      nav.style.maxHeight = "";
      nav.style.left = "";
      nav.style.right = "";
      nav.style.width = "";
      nav.style.boxSizing = "";
      if (isMenuOpen) toggleMenu(false);
    }
  }

  function updateProgress() {
    const doc = document.documentElement;
    const scrollTop = window.scrollY || doc.scrollTop;
    const scrollHeight = doc.scrollHeight - doc.clientHeight;
    const pct = scrollHeight > 0 ? Math.min(100, Math.max(0, (scrollTop / scrollHeight) * 100)) : 0;
    if (progressEl) progressEl.style.width = pct + "%";
  }

  function onScroll() {
    if (!rafScheduled) {
      rafScheduled = true;
      window.requestAnimationFrame(() => {
        (window.scrollY || 0) > 50 ? header.classList.add("scrolled") : header.classList.remove("scrolled");
        updateProgress();
        rafScheduled = false;
      });
    }
  }

  function onDocClick(e) {
    if (!nav) return;
    if (nav.contains(e.target) || (hamburger && hamburger.contains(e.target))) return;
    toggleMenu(false);
  }

  function lockBodyScroll() {
    document.body.style.overflow = 'hidden';
  }

  function unlockBodyScroll() {
    document.body.style.overflow = '';
  }

  function toggleMenu(force) {
    if (!nav || !hamburger) return;
    isMenuOpen = typeof force === "boolean" ? force : !isMenuOpen;

    if (isMenuOpen) {
      setMobileNavPosition();
      nav.classList.add("open");
      hamburger.setAttribute("aria-expanded", "true");
      lockBodyScroll();
      document.addEventListener("click", onDocClick, true);
    } else {
      nav.classList.remove("open");
      hamburger.setAttribute("aria-expanded", "false");
      document.removeEventListener("click", onDocClick, true);
      unlockBodyScroll();
    }
  }

  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", () => setTimeout(setMobileNavPosition, 80));
  window.addEventListener("orientationchange", () => setTimeout(setMobileNavPosition, 120));

  if (hamburger) {
    hamburger.addEventListener("click", e => {
      e.stopPropagation();
      toggleMenu();
    });
  }

  document.addEventListener("keydown", e => {
    if (e.key === "Escape" && isMenuOpen) toggleMenu(false);
  });

  nav.addEventListener("click", e => {
    if (isMenuOpen && e.target.closest("a")) toggleMenu(false);
  });

  window.addEventListener("unload", () => {
    document.documentElement.style.top = "";
    document.body.style.overflow = "";
  });

  setMobileNavPosition();
  updateProgress();
});