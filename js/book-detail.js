// book-detail.js
document.addEventListener("DOMContentLoaded", () => {
    console.log("Book detail loaded");

    // Příklad: Parallax efekt na pozadí
    document.addEventListener("mousemove", (e) => {
        const hero = document.querySelector(".book-hero");
        const x = (window.innerWidth / 2 - e.pageX) / 40;
        const y = (window.innerHeight / 2 - e.pageY) / 40;
        hero.style.transform = `translate(${x}px, ${y}px)`;
    });
});
