// /eshop/js/eshop-auth.js
document.addEventListener('DOMContentLoaded', function(){
  const frm = document.querySelector('form[data-eshop-form]');
  if (!frm) return;
  frm.addEventListener('submit', (e) => {
    const pass = frm.querySelector('input[name="password"]');
    if (pass && pass.value.length > 0 && pass.value.length < 6) {
      e.preventDefault();
      alert('Heslo musí mať minimálne 6 znakov.');
      pass.focus();
    }
  });
});
