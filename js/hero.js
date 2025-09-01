document.addEventListener("DOMContentLoaded", () => {
  // --- Citáty ---
  const quotes = [
    'Kniha je sen, ktorý držíš v ruke.',
    'Slová majú moc meniť svet.',
    'Čítanie je potravou pre dušu.',
    'Každá stránka skrýva nový príbeh.',
    'Kniha otvára dvere mysli.',
    'Čítaj a objavuj nové svety.',
    'Slová tvoria mosty medzi ľuďmi.',
    'Každá kniha je dobrodružstvo.',
    'Čítanie živí dušu a myseľ.',
    'Knihy šepkajú príbehy ticha.',
    'Stránky plné fantázie a snov.',
    'Čítanie je cesta k múdrosti.',
    'Kniha je priateľ na celý život.',
    'Slová majú silu meniť srdcia.',
    'Každý list skrýva nový svet.',
    'Kniha otvára nové obzory.',
    'Slová sú kľúč k srdcu.',
    'Čítanie rozvíja fantáziu a myseľ.',
    'Každá stránka prináša dobrodružstvo.',
    'Knihy sú mosty medzi svetmi.',
    'Čítanie je poklad duše.',
    'Slová tvoria svet okolo nás.',
    'Kniha je tichý spoločník.',
    'Čítaj a snívaj bez hraníc.',
    'Stránky plné múdrosti a snov.',
    'Každá kniha je cesta.',
    'Knihy rozprávajú príbehy života.',
    'Čítanie prebúdza myšlienky.',
    'Slová liečia a inšpirujú.',
    'Kniha je dar pre dušu.'
  ];

  const quoteElement = document.querySelector(".changing-quote");
  if (quoteElement) {
    let current = 0;
    const delay = 6000; // ms
    const fade = 500;   // ms

    function showNextQuote() {
      quoteElement.classList.add("fade-out");
      setTimeout(() => {
        current = (current + 1) % quotes.length;
        quoteElement.textContent = quotes[current];
        quoteElement.classList.remove("fade-out");
      }, fade);
    }

    setInterval(showNextQuote, delay);
  }

// --- Video background fallback (minimální změna pro funkční replay) ---
const wrapper = document.querySelector(".video-background");
const video = document.getElementById("video-background");

if (wrapper && video) {
  const MOBILE_MAX = 768;
  const desktopSrc = "/assets/backgroundheroinfinity.mp4";
  const mobileSrc = "/assets/backgroundmobile.mp4";
  const desktopPoster = "/assets/hero-fallback.png";
  const mobilePoster = "/assets/hero-mobile-fallback.png";

  function setSource() {
    const isMobile = window.innerWidth <= MOBILE_MAX;
    video.src = isMobile ? mobileSrc : desktopSrc;
    video.poster = isMobile ? mobilePoster : desktopPoster;
  }

  setSource();
  window.addEventListener("resize", setSource);

  function enableFallback() { wrapper.classList.add("video-failed"); }
  function disableFallback() { wrapper.classList.remove("video-failed"); }

  video.muted = true;

  // Robustní play: pokud forceReload=true, natvrdo smaže src a znovu jej nastaví
  function tryPlay(forceReload = false) {
    if (forceReload) {
      try { video.pause(); } catch (e) {}
      video.removeAttribute("src"); // vyčistit předchozí "failed" stav
      video.load();
      setSource(); // znovu nastavíme správný src podle velikosti
    }
    video.load();
    video.play()
      .then(() => {
        disableFallback();
      })
      .catch((err) => {
        console.warn("Video play failed:", err);
        enableFallback();
      });
  }

  // první pokus o přehrání
  tryPlay();

  // eventy zůstanou stejné jako předtím
  ["error", "stalled", "suspend", "abort", "emptied"].forEach(evt =>
    video.addEventListener(evt, enableFallback)
  );

  video.addEventListener("pause", () => {
    if (video.currentTime > 0 && !video.ended) enableFallback();
  });
  video.addEventListener("play", disableFallback);

  // když video skončí, zkusíme ho znovu natvrdo přehrát
  video.addEventListener("ended", () => {
    tryPlay(true);
  });

  // kliknutí zkusí vždy force reload + play (odstraněno once: true)
  document.addEventListener("click", () => {
    tryPlay(true);
  });
}

});