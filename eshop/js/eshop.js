// eshop/js/eshop.js
document.addEventListener('DOMContentLoaded', function(){
  // lazy load pomocou IntersectionObserver
  const imgs = document.querySelectorAll('.eshop-lazy');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(entries => {
      entries.forEach(en => {
        if (en.isIntersecting) {
          const img = en.target;
          const src = img.getAttribute('data-src');
          if (src) {
            img.src = src;
            img.removeAttribute('data-src');
          }
          io.unobserve(img);
        }
      });
    }, {rootMargin: '200px'});
    imgs.forEach(i => io.observe(i));
  } else {
    // fallback: načítaj všetky
    imgs.forEach(i => { const s = i.getAttribute('data-src'); if (s) i.src = s; });
  }

  // jednoduché UX: submit form on enter in search
  const form = document.getElementById('eshop-filter-form');
  if (form) {
    const search = form.querySelector('input[type="search"]');
    if (search) {
      search.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
          // necháme default submit
        }
      });
    }
  }
});
