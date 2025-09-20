// /www/eshop/js/change_password.js
document.addEventListener('DOMContentLoaded', function(){
  const form = document.querySelector('#changePasswordForm');
  if (!form) return;
  form.addEventListener('submit', async function(e){
    e.preventDefault();
    const data = new FormData(form);
    const resp = await fetch('/eshop/actions/change_password.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    });
    const json = await resp.json();
    const out = document.querySelector('#changePasswordMessage');
    out.textContent = json.message || (json.success ? 'Hotovo' : 'Chyba');
  });
});