// /www/eshop/js/register.js
document.addEventListener('DOMContentLoaded', function(){
  const form = document.querySelector('#registerForm');
  if (!form) return;
  form.addEventListener('submit', async function(e){
    e.preventDefault();
    const data = new FormData(form);
    const resp = await fetch('/eshop/actions/register.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    });
    const json = await resp.json();
    const out = document.querySelector('#registerMessage');
    if (json.success) out.textContent = json.message || 'Registrácia prebehla.';
    else {
      if (json.errors) {
        out.innerHTML = Object.values(json.errors).map(x => `<div>${x}</div>`).join('');
      } else out.textContent = json.message || 'Chyba pri registrácii.';
    }
  });
});