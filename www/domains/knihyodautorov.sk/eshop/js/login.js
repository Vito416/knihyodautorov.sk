// /www/eshop/js/login.js
document.addEventListener('DOMContentLoaded', function(){
  const form = document.querySelector('#loginForm');
  if (!form) return;
  form.addEventListener('submit', async function(e){
    e.preventDefault();
    const data = new FormData(form);
    const resp = await fetch('/eshop/actions/login.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    });
    const json = await resp.json();
    const msgEl = document.querySelector('#loginMessage');
    if (json.success) {
      msgEl.textContent = json.message || 'Prihlásenie prebehlo.';
      // pre redirect ak je v odpovedi redirect url
      if (json.redirect) window.location = json.redirect;
      else window.location.reload();
    } else {
      msgEl.textContent = json.message || 'Chyba pri prihlásení.';
    }
  });
});