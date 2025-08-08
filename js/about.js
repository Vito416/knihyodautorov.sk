document.addEventListener("DOMContentLoaded", () => {
    const aboutItems = document.querySelectorAll(".about-text, .about-image-container, .about-highlight");

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if(entry.isIntersecting){
                entry.target.style.opacity = "1";
                entry.target.style.transform = "translateY(0)";
                entry.target.style.transition = "all 1s ease";
            }
        });
    }, { threshold: 0.2 });

    aboutItems.forEach(el => observer.observe(el));
});
