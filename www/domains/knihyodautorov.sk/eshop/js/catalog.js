// /www/eshop/js/catalog.js
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.addToCart').forEach(function(btn){
    btn.addEventListener('click', async function(e){
      e.preventDefault();
      const id = btn.getAttribute('data-id');
      if (!id) return;
      // jednoduché POST (musí existovať actions/cart_add.php)
      const fd = new FormData();
      fd.append('product_id', id);
      fd.append('quantity', 1);
      // CSRF token: optional read from DOM e.g. document.querySelector('input[name="csrf_token"]').value
      const csrfEl = document.querySelector('input[name="csrf_token"]');
      if (csrfEl) fd.append('csrf', csrfEl.value);
      try {
        const res = await fetch('/eshop/actions/cart_add.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();
        if (j.success) {
          alert(j.message || 'Produkt pridaný do košíka.');
        } else {
          alert(j.message || 'Chyba pri pridávaní do košíka.');
        }
      } catch (err) {
        alert('Chyba pri kontakte so serverom.');
      }
    });
  });
});
