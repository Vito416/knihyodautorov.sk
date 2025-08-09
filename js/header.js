document.addEventListener("DOMContentLoaded", function() {
  const hamburger = document.querySelector(".hamburger");
  const nav = document.querySelector(".main-nav");
  const header = document.querySelector(".site-header");
  let scrollTimeout;

  // Toggle mobilného menu
  hamburger.addEventListener("click", () => {
    const expanded = hamburger.getAttribute("aria-expanded") === "true" || false;
    hamburger.setAttribute("aria-expanded", !expanded);
    nav.classList.toggle("open");
  });

  // Jemnější přidání třídy .scrolled při scrollovaní
  window.addEventListener("scroll", () => {
    clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(() => {
      if (window.scrollY > 50) {
        header.classList.add("scrolled");
      } else {
        header.classList.remove("scrolled");
      }
    }, 50); // malá prodleva pro plynulost
  });
});
