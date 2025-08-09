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
