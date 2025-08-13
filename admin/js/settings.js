// /admin/js/settings.js
document.addEventListener('DOMContentLoaded', function(){
  const form = document.getElementById('settingsForm');
  const msg = document.getElementById('settingsMsg');
  const smtpForm = document.getElementById('smtpForm');
  const smtpMsg = document.getElementById('smtpMsg');
  const btnTestSmtp = document.getElementById('btnRunSmtpTest');
  const btnGeneralTest = document.getElementById('btnTestSmtp');

  // univerzálny AJAX handler pre POST (formData)
  async function postAjax(url, data) {
    const resp = await fetch(url, {method:'POST', body: data, credentials: 'same-origin'});
    if (!resp.ok) throw new Error('Network response not ok: ' + resp.status);
    return resp.json();
  }

  // uložiť nastavenia
  form.addEventListener('submit', function(e){
    e.preventDefault();
    msg.textContent = 'Ukladám…';
    const fd = new FormData(form);
    // zabezpečenie
    fd.set('ajax','1');
    fd.set('action','save');
    postAjax('/admin/settings.php', fd)
      .then(d => {
        if (d.ok) {
          msg.style.color = '#0b6b3a';
          msg.textContent = d.message || 'Uložené';
        } else {
          msg.style.color = '#7a1f1f';
          msg.textContent = d.message || 'Chyba pri ukladaní';
        }
      }).catch(err=>{
        msg.style.color = '#7a1f1f';
        msg.textContent = 'Chyba: '+err.message;
      });
  });

  // rychly test SMTP z SMTP bloku
  btnTestSmtp && btnTestSmtp.addEventListener('click', function(e){
    // precompat: odkazuje sa na smtpForm polia
    const host = document.getElementById('smtp_host').value || '';
    const port = document.getElementById('smtp_port').value || '25';
    const fd = new FormData();
    fd.set('ajax','1');
    fd.set('action','test_smtp');
    fd.set('smtp_host', host);
    fd.set('smtp_port', port);
    smtpMsg.style.color = '#333';
    smtpMsg.textContent = 'Testujem pripojenie…';
    postAjax('/admin/settings.php', fd)
      .then(d=>{
        if (d.ok) { smtpMsg.style.color = '#0b6b3a'; smtpMsg.textContent = d.message || 'OK'; }
        else { smtpMsg.style.color = '#8a1f1f'; smtpMsg.textContent = d.message || 'Chyba SMTP'; }
      }).catch(err=>{
        smtpMsg.style.color = '#8a1f1f';
        smtpMsg.textContent = 'Chyba: '+err.message;
      });
  });

  // tlačidlo btnTestSmtp (v hornej časti) aktivuje rovnaký test
  btnGeneralTest && btnGeneralTest.addEventListener('click', function(){
    document.getElementById('btnRunSmtpTest').click();
  });
});