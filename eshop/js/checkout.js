// /eshop/js/checkout.js - drobné UX
document.addEventListener('DOMContentLoaded', () => {
  // jednoduché potvrdenie pred submitom objednávky
  const form = document.querySelector('form[action="checkout.php"]');
  if (form) {
    form.addEventListener('submit', (e) => {
      const cartEmpty = document.querySelectorAll('.cart-table tbody tr').length === 0;
      if (cartEmpty) {
        e.preventDefault();
        alert('Košík je prázdny.');
        return;
      }
      if (!confirm('Potvrďte vytvorenie objednávky. Dostanete faktúru s platobnými inštrukciami.')) {
        e.preventDefault();
      }
    });
  }
});
