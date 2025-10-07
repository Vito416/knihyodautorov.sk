(function(){
  // KONFIGURACE
  const PHRASES = [
    "Nájdi svoju ďalšiu knihu",
    "Nájdi ďalší epický príbeh",
    "Vstúp do sveta novej knihy",
    "Vyber si knihu, čo zmení deň",
    "Získaj knižný poklad",
    "Prečítaj niečo, čo ťa unáša",
    "Nájdi titul, ktorý ťa zoberie"
];
  const ROTATE_INTERVAL = 4200; // ms (doba mezi změnami)
  const FADE_OUT_MS = 420;      // musí odpovídat CSS fadeOut
  const FADE_IN_MS = 520;       // musí odpovídat CSS fadeIn

  // elementy
  const titleEl = document.getElementById('main-title');
  if(!titleEl) return;

  // uchovej původní index pokud má text
  let index = PHRASES.indexOf(titleEl.textContent.trim());
  if(index === -1) index = 0;

  let timer = null;
  let paused = false;

  function showNext(){
    // fade out -> change -> fade in
    titleEl.classList.remove('fade-in');
    titleEl.classList.add('fade-out');

    setTimeout(()=>{
      index = (index + 1) % PHRASES.length;
      // nastavit nový text
      titleEl.textContent = PHRASES[index];
      // accessibility: announce
      titleEl.setAttribute('aria-live','polite');
      // fade in
      titleEl.classList.remove('fade-out');
      titleEl.classList.add('fade-in');
    }, FADE_OUT_MS);
  }

  function start(){
    if(timer) clearInterval(timer);
    timer = setInterval(()=> {
      if(!paused) showNext();
    }, ROTATE_INTERVAL);
  }

  function stop(){
    if(timer) { clearInterval(timer); timer = null; }
  }

  // pause při hoveru a při fokusu (UX)
  titleEl.addEventListener('mouseenter', ()=> { paused = true; });
  titleEl.addEventListener('mouseleave', ()=> { paused = false; });
  titleEl.addEventListener('focus', ()=> { paused = true; }, true);
  titleEl.addEventListener('blur', ()=> { paused = false; }, true);

  // init: čekej malé zpoždění a pak startni
  setTimeout(start, 700);

  // export (debug) - umožní použít v konzoli window.__rotateTitle()
  window.__rotateTitle = {
    start, stop, showNext,
    setIntervalMs(ms){ clearInterval(timer); start(); }
  };
})();