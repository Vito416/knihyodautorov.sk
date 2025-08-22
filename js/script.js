function resizeTitle(el) {
  // reset velikostí
  el.style.fontSize = "";
  const span = el.querySelector("span");
  if (span) span.style.fontSize = "";

  let fontSize = parseFloat(getComputedStyle(el).fontSize);
  let spanFontSize = span ? parseFloat(getComputedStyle(span).fontSize) : null;
  const lineHeight = parseFloat(getComputedStyle(el).lineHeight);
  const minFontSize = 14;

  // kolik řádků je povoleno (default = 1)
  const maxLines = parseInt(el.dataset.lines || "1", 10);
  const maxHeight = lineHeight * (maxLines + 0.2);

  // === 1) bez úprav ===
  if (el.scrollHeight <= maxHeight) return;

  // === 2) zmenšujeme dokud se nevejde nebo nedosáhneme min. velikosti ===
  while (el.scrollHeight > maxHeight && fontSize > minFontSize) {
    fontSize -= 1;
    el.style.fontSize = fontSize + "px";

    if (span && spanFontSize) {
      spanFontSize -= 1;
      span.style.fontSize = spanFontSize + "px";
    }
  }

  // === 3) fallback ===
  if (el.scrollHeight > maxHeight) {
    el.style.whiteSpace = "nowrap";
    el.style.overflow = "hidden";
    el.style.textOverflow = "ellipsis";
  }
}

function initResizeTitles() {
  const titles = document.querySelectorAll(".section-title");
  titles.forEach(el => resizeTitle(el));

  // reaguje na window resize
  window.addEventListener("resize", () => {
    titles.forEach(el => resizeTitle(el));
  });

  // reaguje i na změny obsahu/velikosti (např. AJAX, CMS)
  const ro = new ResizeObserver(() => {
    titles.forEach(el => resizeTitle(el));
  });
  titles.forEach(el => ro.observe(el));
}

window.addEventListener("DOMContentLoaded", initResizeTitles);