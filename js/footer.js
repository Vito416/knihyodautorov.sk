document.addEventListener("mousemove", (e) => {
    const footer = document.querySelector(".site-footer");
    const x = (e.clientX / window.innerWidth - 0.5) * 10;
    const y = (e.clientY / window.innerHeight - 0.5) * 10;
    footer.style.backgroundPosition = `${50 + x}% ${50 + y}%`;
});
