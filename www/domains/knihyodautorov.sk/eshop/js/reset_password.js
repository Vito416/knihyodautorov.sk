// /www/eshop/js/reset_password.js
document.addEventListener('DOMContentLoaded', function(){
  const form = document.querySelector('#resetForm');
  if (!form) return;
  form.addEventListener('submit', async function(e){
    e.preventDefault();
    const data = new FormData(form);
    const resp = await fetch('/eshop/actions/reset_password.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    });
    const json = await resp.json();
    const out = document.querySelector('#resetMessage');
    out.textContent = json.message || (json.success ? 'Hotovo' : 'Chyba');
    if (json.success) {
      // prípadne redirect na login
      setTimeout(()=>window.location='/eshop/login.php', 1500);
    }
  });
});