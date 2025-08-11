document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('contact-form');
  const status = document.getElementById('formStatus');
  const submit = document.getElementById('contactSubmit');

  function showStatus(msg, ok = true) {
    status.textContent = msg;
    status.style.color = ok ? '#cdeac3' : '#ffb3a3';
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();

    // honeypot
    if(form.hp_email && form.hp_email.value) {
      showStatus('Odeslání zamítnuto.', false);
      return;
    }

    // jednoduchá klientská validácia
    const name = form.name.value.trim();
    const email = form.email.value.trim();
    const subject = form.subject.value.trim();
    const message = form.message.value.trim();

    if(!name || !email || !subject || !message) {
      showStatus('Vyplňte prosím všetky povinné polia.', false);
      return;
    }
    // základná email regex
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if(!emailRe.test(email)) {
      showStatus('Zadajte platný email.', false);
      return;
    }

    // disable submit
    submit.disabled = true;
    submit.textContent = 'Odosielam...';

    // odešli AJAXom
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
      if(data.success) {
        showStatus(data.message || 'Správa odoslaná — ďakujeme!', true);
        form.reset();
      } else {
        showStatus(data.message || 'Chyba pri odoslaní.', false);
      }
    })
    .catch(err => {
      console.error(err);
      showStatus('Chyba pri spojení. Skúste neskôr.', false);
    })
    .finally(() => {
      submit.disabled = false;
      submit.textContent = 'Odoslať správu';
    });
  });

  // smooth scroll to form if anchor clicked
  document.querySelectorAll('.scroll-to-form').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      document.getElementById('contact-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
      document.getElementById('name').focus();
    });
  });

});
