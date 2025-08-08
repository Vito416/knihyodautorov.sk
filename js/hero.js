document.addEventListener("DOMContentLoaded", () => {
  const quotes = [
    '"Kniha je sen, ktorý držíš v ruke."',
    '"Slová majú moc meniť svet."',
    '"Čítanie je potravou pre dušu."',
    '"Každá stránka skrýva nový príbeh."'
  ];

  const quoteElement = document.querySelector(".changing-quote");
  let current = 0;

  setInterval(() => {
    current = (current + 1) % quotes.length;
    quoteElement.style.opacity = 0;
    setTimeout(() => {
      quoteElement.textContent = quotes[current];
      quoteElement.style.opacity = 1;
    }, 500);
  }, 6000);
});
