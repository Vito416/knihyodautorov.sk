document.addEventListener('DOMContentLoaded', function() {

  // --- delegace kliků pro všechny knihy ---
  document.body.addEventListener('click', async function(e) {
    const card = e.target.closest('.book-card');
    if (!card) return;

    // ignorovat klik na přidat do košíku
    if (e.target.closest('form.add-to-cart-form, .modal-close')) return;

    e.preventDefault();

    // získat slug/id z .openDetail uvnitř karty
    const link = card.querySelector('.openDetail');
    if (!link) return;

    const slug = link.dataset.slug;
    const id = link.dataset.id;

    if (!slug && !id) return;

    let url = '/eshop/detail?fragment=1';
    if (slug) url += '&slug=' + encodeURIComponent(slug);
    else if (id) url += '&id=' + encodeURIComponent(id);

    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();

      // --- modal setup ---
      let modal = document.getElementById('bookModal');
      if (!modal) {
        modal = document.createElement('div');
        modal.id = 'bookModal';
        modal.className = 'modal';
        modal.innerHTML = `
          <div class="modal-overlay"></div>
          <div class="modal-box">
            <button class="modal-close">&times;</button>
            <div class="modal-body"></div>
          </div>`;
        document.body.appendChild(modal);

        modal.querySelector('.modal-close').onclick = () => modal.classList.remove('open');
        modal.querySelector('.modal-overlay').onclick = () => modal.classList.remove('open');
      }

      const body = modal.querySelector('.modal-body');
      body.innerHTML = html;

      // --- epic zoom pro cover ---
      const coverImg = body.querySelector('.book-cover img');
      if (coverImg) {
        coverImg.style.cursor = 'zoom-in';
        coverImg.onclick = () => {
          const epicImg = document.createElement('div');
          epicImg.className = 'epic-overlay';
          epicImg.innerHTML = `<img src="${coverImg.src}" style="max-width:90%;max-height:90%;border:5px solid gold;border-radius:10px;box-shadow:0 0 30px gold;">`;
          epicImg.onclick = () => document.body.removeChild(epicImg);
          document.body.appendChild(epicImg);
        };
      }

      modal.classList.add('open');

    } catch (err) {
      alert('Nepodařilo se načíst detail knihy.');
      console.error(err);
    }

  });

});
