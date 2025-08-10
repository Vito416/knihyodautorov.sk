// top-books.js
document.addEventListener('DOMContentLoaded', function(){
  // jemné fade-in pre karty
  const cards = document.querySelectorAll('.topbook-card');
  cards.forEach((c,i) => {
    c.style.opacity = 0;
    c.style.transform = 'translateY(10px)';
    setTimeout(() => {
      c.style.transition = 'opacity 420ms ease, transform 420ms ease';
      c.style.opacity = 1;
      c.style.transform = 'translateY(0)';
    }, 80 * i);
  });

  // ak chceš autoplay karusel v budúcnosti - tu môžeš doplniť
});
