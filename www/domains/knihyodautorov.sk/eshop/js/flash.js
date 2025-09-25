document.addEventListener('DOMContentLoaded', () => {
  const flashes = document.querySelectorAll('.flash-messages .flash-dismiss');

  flashes.forEach(btn => {
    btn.addEventListener('click', () => {
      const flash = btn.closest('.flash-info, .flash-success, .flash-warning, .flash-error');
      if (!flash) return;

      // přidej CSS třídu pro animaci
      flash.classList.add('flash-hide');

      // počkej na konec animace, pak prvek smaž
      flash.addEventListener('transitionend', () => {
        flash.remove();
      }, { once: true });
    });
  });
});