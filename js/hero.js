document.addEventListener("DOMContentLoaded", () => {
  const quotes = [
    '"Kniha je sen, ktorý držíš v ruke."',
    '"Slová majú moc meniť svet."',
    '"Čítanie je potravou pre dušu."',
    '"Každá stránka skrýva nový príbeh."'
  ];

  const quoteElement = document.querySelector(".changing-quote");
  let current = 0;
  let fadeDuration = 500; // ms

  setInterval(() => {
    // Fade out
    quoteElement.style.transition = `opacity ${fadeDuration}ms ease-in-out`;
    quoteElement.style.opacity = 0;

    setTimeout(() => {
      // Zmena textu
      current = (current + 1) % quotes.length;
      quoteElement.textContent = quotes[current];

      // Fade in
      quoteElement.style.opacity = 1;
    }, fadeDuration);
  }, 6000);
});

// --- fallback pro video: pokud autoplay selže nebo nastane error, přidáme .video-failed
(function(){
  const vb = document.querySelector('.video-background');
  const v = document.querySelector('#video-background');
  if(!vb || !v) return;

  // helper pro aktivaci fallbacku
  function enableVideoFallback(){
    vb.classList.add('video-failed');
  }

  // pokud video vyhodí chybu
  v.addEventListener('error', enableVideoFallback);
  v.addEventListener('stalled', enableVideoFallback);
  v.addEventListener('suspend', enableVideoFallback);

  // pokud autoplay bloknut (play() vrátí rejected promise)
  // zajistíme, že video je muted (autoplay často povolen pouze muted)
  v.muted = true;
  const p = v.play();
  if (p !== undefined) {
    p.then(() => {
      // úspěšně hraje — nic dělat nemusíme
      vb.classList.remove('video-failed');
    }).catch(() => {
      // autoplay zablokován -> aktivovat fallback
      enableVideoFallback();
    });
  }

  // navíc, pokud se později video zastaví (např. prohlížeč přerušil), zapnout fallback
  v.addEventListener('pause', function(){
    // pokud je pause a nejedná se o loop end, aktivujeme fallback jen když není záměrné pauznutí
    if (v.currentTime > 0 && !v.ended) enableVideoFallback();
  });

  // pokud se uživatel rozhodne video ručně spustit, odstranit fallback
  v.addEventListener('play', function(){
    vb.classList.remove('video-failed');
  });
})();

// Pokus o opětovné spuštění videa po prvním kliknutí
document.addEventListener('click', () => {
  const vb = document.querySelector('.video-background');
  const v = document.getElementById('video-background');
  
  if (vb && v && vb.classList.contains('video-failed')) {
    vb.classList.remove('video-failed'); // zrušíme fallback
    v.style.display = '';
    v.currentTime = 0; // začít od začátku
    v.load(); // načteme znovu zdroj
    v.play().catch(() => {
      // pokud se nepodaří, vrátíme fallback
      vb.classList.add('video-failed');
    });
  }
}, { once: true });
