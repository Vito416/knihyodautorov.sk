document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('register-form');
  const messages = document.getElementById('register-messages');
  const modal = document.getElementById('register-modal');
  const modalClose = modal.querySelector('.modal-close');
  const modalOk = modal.querySelector('#modal-ok');
  const redirectUrl = modal.dataset.redirect || 'login.php';
  let autoCloseTimeout = null;

  function showModal() {
    modal.classList.add('visible');
    autoCloseTimeout = setTimeout(hideModal, 5000);
  }

  function hideModal() {
    modal.classList.remove('visible');
    if (autoCloseTimeout) clearTimeout(autoCloseTimeout);
    setTimeout(() => { window.location.href = redirectUrl; }, 200);
  }

  modalClose.addEventListener('click', hideModal);
  modalOk.addEventListener('click', hideModal);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    messages.innerHTML = '';

    const formData = new FormData(form);

    try {
      const res = await fetch('/action/register_send.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.success) {
        showModal();
        form.reset();
      } else if (data.errors) {
        for (const key in data.errors) {
          const p = document.createElement('p');
          p.className = 'error';
          p.textContent = data.errors[key];
          messages.appendChild(p);
        }
      }
    } catch (err) {
      console.error(err);
      messages.innerHTML = `<p class="error">Nepodarilo sa spracovať registráciu.</p>`;
    }
  });
});