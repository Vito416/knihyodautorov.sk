document.addEventListener("DOMContentLoaded", () => {
  // Lazy-load images (data-src)
  const lazyImgs = document.querySelectorAll('img.book-cover');
  const ioOpts = { root: null, rootMargin: '200px', threshold: 0.01 };
  const imgObserver = new IntersectionObserver((entries, obs) => {
    entries.forEach(e => {
      if(e.isIntersecting){
        const img = e.target;
        const src = img.getAttribute('data-src');
        if(src){ img.src = src; img.removeAttribute('data-src'); }
        obs.unobserve(img);
      }
    });
  }, ioOpts);
  lazyImgs.forEach(i => imgObserver.observe(i));

  // Reveal cards on scroll
  const cards = document.querySelectorAll('.book-card');
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if(entry.isIntersecting){
        entry.target.classList.add('revealed');
      }
    });
  }, { threshold: 0.15 });
  cards.forEach(c => revealObserver.observe(c));

  // Filtering
  const filterBtns = document.querySelectorAll('.filter-btn');
  const booksGrid = document.getElementById('booksGrid');

  function applyFilter(categoryFilter, originFilter){
    cards.forEach(card => {
      const cat = card.getAttribute('data-category') || '';
      const origin = card.getAttribute('data-origin') || '';
      let show = true;
      if(categoryFilter && categoryFilter !== '*' && categoryFilter !== 'all-authors'){
        show = (cat === categoryFilter);
      }
      if(originFilter && originFilter !== 'all-authors'){
        show = show && (origin === originFilter);
      }
      card.style.display = show ? '' : 'none';
    });
  }

  // track active filters
  let activeCategory = '*';
  let activeOrigin = 'all-authors';

  filterBtns.forEach(btn => {
    btn.addEventListener('click', (e) => {
      const filter = btn.getAttribute('data-filter');
      // group behavior: find group (category or origin) by reading parent .filter-group
      const group = btn.closest('.filter-group');
      // deactivate siblings in this group
      group.querySelectorAll('.filter-btn').forEach(s => s.classList.remove('active'));
      btn.classList.add('active');

      // detect which group - if first group contains 'novel' etc. treat as category, else origin
      const isCategory = ['novel','poetry','nonfiction','*'].includes(filter);
      if(isCategory || filter === '*'){
        activeCategory = filter;
      } else {
        activeOrigin = filter;
      }
      applyFilter(activeCategory, activeOrigin);
    });
  });

  // Modal logic
  const modal = document.getElementById('bookModal');
  const modalClose = modal.querySelector('.modal-close');
  const modalCover = document.getElementById('modalCover');
  const modalTitle = document.getElementById('modalTitle');
  const modalAuthor = document.getElementById('modalAuthor');
  const modalDesc = document.getElementById('modalDesc');
  const modalDownload = document.getElementById('modalDownload');

  document.querySelectorAll('.open-detail').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const title = btn.dataset.title || '';
      const author = btn.dataset.author || '';
      const desc = btn.dataset.desc || '';
      const cover = btn.dataset.cover || '';

      modalCover.src = cover;
      modalTitle.textContent = title;
      modalAuthor.textContent = author;
      modalDesc.textContent = desc;
      modalDownload.href = btn.dataset.pdf || '#';

      modal.setAttribute('aria-hidden','false');
      document.body.style.overflow = 'hidden';
    });
  });

  modalClose.addEventListener('click', () => {
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
  });

  modal.addEventListener('click', (e) => {
    if(e.target === modal) {
      modal.setAttribute('aria-hidden','true');
      document.body.style.overflow = '';
    }
  });

  // keyboard
  document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false'){
      modal.setAttribute('aria-hidden','true');
      document.body.style.overflow = '';
    }
  });
});
