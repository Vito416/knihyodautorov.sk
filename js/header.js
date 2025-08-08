document.addEventListener("DOMContentLoaded", function() {
  const hamburger = document.querySelector(".hamburger");
  const nav = document.querySelector(".main-nav");
  const header = document.querySelector(".site-header");

  // Toggle mobilné menu
  hamburger.addEventListener("click", () => {
    const expanded = hamburger.getAttribute("aria-expanded") === "true" || false;
    hamburger.setAttribute("aria-expanded", !expanded);
    nav.classList.toggle("open");
  });

  // Pridať triedu .scrolled pri scrollovaní
  window.addEventListener("scroll", () => {
    if(window.scrollY > 50){
      header.classList.add("scrolled");
    } else {
      header.classList.remove("scrolled");
    }
  });
});
