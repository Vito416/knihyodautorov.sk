// /admin/js/admin.js
document.addEventListener('DOMContentLoaded', function(){
  // flash hide
  setTimeout(()=> {
    document.querySelectorAll('.flash').forEach(el=> el.style.opacity='0');
  }, 3500);

  // SMTP test
  const smtpBtn = document.getElementById('smtp-test-btn');
  if (smtpBtn) {
    smtpBtn.addEventListener('click', async function(e){
      e.preventDefault();
      const email = document.getElementById('smtp-test-email').value || '';
      const btn = this;
      btn.disabled = true;
      const orig = btn.innerText;
      btn.innerText = 'Testujem...';
      try {
        const res = await fetch('/admin/smtp-test.php', {
          method:'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ email })
        });
        const data = await res.json();
        alert(data.ok ? 'Test e-mail odoslaný (skontrolujte doručenie).' : 'Chyba: ' + (data.error||'neznáma'));
      } catch(err){
        alert('Chyba pri teste SMTP: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.innerText = orig;
      }
    });
  }

  // simple confirm for delete links
  document.querySelectorAll('form[data-confirm]').forEach(form=>{
    form.addEventListener('submit', function(e){
      const msg = form.getAttribute('data-confirm') || 'Potvrďte akciu';
      if (!confirm(msg)) e.preventDefault();
    });
  });
});