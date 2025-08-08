// authors-page.js
document.addEventListener('DOMContentLoaded', () => {
  // filter select
  const filterSelect = document.getElementById('filter-country');
  const cards = Array.from(document.querySelectorAll('.author-card'));
  const grid = document.getElementById('authorsGrid');

  // add small stagger data attributes for entrance animation
  cards.forEach((c, i) => {
    c.setAttribute('data-anim', i % 3); // 0,1,2 cascade
  });

  function applyFilter(country) {
    cards.forEach(card => {
      const c = card.getAttribute('data-country') || 'sk';
      if (country === 'all' || country === c) {
        card.style.display = '';
        // small reveal
        card.style.opacity = '';
      } else {
        card.style.display = 'none';
      }
    });
  }

  filterSelect.addEventListener('change', (e) => {
    applyFilter(e.target.value);
  });

  // keyboard accessibility: open books by Enter on card
  cards.forEach(card => {
    card.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        const link = card.querySelector('.btn');
        if (link) link.click();
      }
    });
  });

  // optional: simple search by name (if you add a search input later)
  // progressive enhancement: lazy-load author images
  const imgs = document.querySelectorAll('.author-image img');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries, obs) => {
      entries.forEach(en => {
        if (en.isIntersecting) {
          const img = en.target;
          const src = img.getAttribute('data-src');
          if (src) { img.src = src; img.removeAttribute('data-src'); }
          obs.unobserve(img);
        }
      });
    }, {rootMargin: '200px'});
    imgs.forEach(img => {
      // if you use data-src, observer will lazy load; if not, it's fine
      if (img.dataset.src) io.observe(img);
    });
  }

});
