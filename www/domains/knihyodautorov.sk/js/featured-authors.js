// featured-authors.js - drobné animácie a lazy-load obrázkov
document.addEventListener('DOMContentLoaded', function(){
  const cards = document.querySelectorAll('.fauthor-card');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries, obs) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('fauthor-visible');
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    cards.forEach(c => io.observe(c));
  } else {
    cards.forEach(c => c.classList.add('fauthor-visible'));
  }
});
