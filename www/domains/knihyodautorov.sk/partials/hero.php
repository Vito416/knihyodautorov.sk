<link rel="stylesheet" href="/css/hero.css" />

<section class="hero-section">
  <!-- Video pozadie -->
  <?php
  // paths - uprav podle potřeby
  $desktopSrc    = '/assets/backgroundheroinfinity.mp4';
  $mobileSrc     = '/assets/backgroundmobile.mp4';
  $desktopPoster = '/assets/hero-fallback.png';
  $mobilePoster  = '/assets/hero-mobile-fallback.png';
  ?>
  <div class="video-background">
    <video
      id="video-background"
      autoplay
      muted
      loop
      playsinline
      preload="metadata"
      poster="<?= htmlspecialchars($desktopPoster, ENT_QUOTES) ?>"
      src="<?= htmlspecialchars($desktopSrc, ENT_QUOTES) ?>"
      data-desktop-src="<?= htmlspecialchars($desktopSrc, ENT_QUOTES) ?>"
      data-mobile-src="<?= htmlspecialchars($mobileSrc, ENT_QUOTES) ?>"
      data-desktop-poster="<?= htmlspecialchars($desktopPoster, ENT_QUOTES) ?>"
      data-mobile-poster="<?= htmlspecialchars($mobilePoster, ENT_QUOTES) ?>"
      >
      Váš prehliadač nepodporuje prehrávanie videa na pozadí.
    </video>
  </div>

  <div class="hero-content">
  <h1 class="epic-title">
    Objav <span>svet príbehov</span> v digitálnej podobe
  </h1>
  <p class="changing-quote">
    Kniha je sen, ktorý držíš v ruke.
  </p>

  <div class="cta-wrapper">
    <a href="#booksPromo" class="cta-button sample">
      <i class="fas fa-book-open"></i> Ukážka zdarma
    </a>
    <a href="/eshop.php" class="cta-button shop">
      <i class="fas fa-shopping-cart"></i> Navštíviť e-shop
    </a>
  </div>
</div>
</section>

<script src="/js/hero.js" defer></script>