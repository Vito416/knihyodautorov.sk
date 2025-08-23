(function(){
  const hang = document.querySelector('.gallery-hang');
  const img = hang?.querySelector('.framed-image');
  const shadow = hang?.querySelector('.shadow');
  if(!hang || !img || !shadow) return;

  const strength = 12; // intenzita rotace (stupeň)
  const lift = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--lift-z')) || 28;

  // nastavíme počáteční transform (konzistence)
  img.style.transform = `translateZ(${lift}px) translateY(-2px) scale(.999)`;

  hang.addEventListener('pointermove', (e) => {
    const r = hang.getBoundingClientRect();
    const cx = r.left + r.width/2;
    const cy = r.top + r.height/2;
    const dx = (e.clientX - cx) / r.width;  // -0.5 .. 0.5 cca
    const dy = (e.clientY - cy) / r.height;
    const rx = (-dy * strength).toFixed(2);
    const ry = (dx * strength).toFixed(2);

    img.style.transform = `translateZ(${lift + 6}px) rotateX(${rx}deg) rotateY(${ry}deg)`;
    // lehce posunout a natáhnout shadow podle kurzoru
    const sx = (dx * 10).toFixed(1);
    const sy = (Math.abs(dx) * 3 + Math.abs(dy) * 2).toFixed(1);
    shadow.style.transform = `translateX(calc(-50% + ${sx}px)) translateY(-6px) rotateX(75deg) scaleX(${1 + Math.abs(dx)*0.08}) translateY(${sy}px)`;
  });

  hang.addEventListener('pointerleave', () => {
    img.style.transition = "transform 420ms cubic-bezier(.2,.9,.3,1)";
    img.style.transform = `translateZ(${lift}px) translateY(-2px) scale(.999)`;
    shadow.style.transition = "transform 420ms cubic-bezier(.2,.9,.3,1)";
    shadow.style.transform = `translateX(-50%) translateY(-6px) rotateX(75deg)`;
    // po pár set ms odmazat transition inline (nepovinné)
    setTimeout(() => {
      img.style.transition = "";
      shadow.style.transition = "";
    }, 450);
  });
})();

document.addEventListener('DOMContentLoaded', () => {
  const container = document.querySelector('.about-container');
  if (!container) return;

  const elements = container.querySelectorAll('[data-lines]');
  if (!elements.length) return;

  const originals = new Map();
  elements.forEach(el => {
    originals.set(el, parseInt(el.getAttribute('data-lines'), 10) || 0);
  });

  const mql = window.matchMedia('(max-width: 880px)');

  function apply(e) {
    const matches = e.matches;
    elements.forEach(el => {
      const orig = originals.get(el);
      if (!orig) return;

      el.setAttribute('data-lines', matches ? String(orig + 1) : String(orig));
    });
  }

  // první spuštění
  apply(mql);

  // nasloucháme změnám viewportu
  mql.addEventListener('change', apply);
});