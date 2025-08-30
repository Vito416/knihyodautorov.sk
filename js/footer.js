document.addEventListener('DOMContentLoaded', () => {
  const gdprModal = document.getElementById('gdprModal');
  const gdprCloseBtn = document.getElementById('closeGdprBtn');
  const gdprLink = document.querySelector('.footer-gdpr');

  if (!gdprModal || !gdprCloseBtn || !gdprLink) return;

  function openGdprModal(e) {
    e.preventDefault();
    gdprModal.classList.add('active');
    document.body.style.overflow = 'hidden'; // zamkne scroll
  }

  function closeGdprModal() {
    gdprModal.classList.remove('active');
    document.body.style.overflow = ''; // obnoví scroll
  }

  gdprLink.addEventListener('click', openGdprModal);
  gdprCloseBtn.addEventListener('click', closeGdprModal);
  gdprModal.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeGdprModal();
  });

  window.openGdprModal = openGdprModal; // pokud to chceš mít dostupné globálně
});