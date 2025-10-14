// drobná utilita pro bezpečné escapování textu před vložením do innerHTML
function escapeHtml(s) {
  return String(s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

async function submitRegistration(formElem) {
  const formData = new FormData(formElem);

  try {
    const response = await fetch('/eshop/register', {
      method: 'POST',
      body: formData,
    });
    const data = await response.json();

    if (data && data.csrfToken) {
      document.querySelectorAll('input[name="csrf"]').forEach(input => {
        try { input.value = data.csrfToken; } catch (e) { /* ignore */ }
      });
    }

    const msgBox = document.querySelector('#register-message');
    if (msgBox) {
      if (data.success) {
        window.location.href = '/eshop/login';
      } else {
        const errText =
          data.message ||
          (data.errors ? Object.values(data.errors).join('<br>') : 'Neznáma chyba.');
        msgBox.innerHTML = `<div class="error">${errText}</div>`;
      }
    }
  } catch (err) {
    console.error('Chyba pri volaní register.php:', err);
    const msgBox = document.querySelector('#register-message');
    if (msgBox) {
      msgBox.innerHTML = `<div class="error">Server sa neozval. Skúste znova.</div>`;
    }
  }
}

window.submitRegistration = submitRegistration;