<link rel="stylesheet" href="/css/hero.css" />

<section class="hero-section">
  <!-- Video pozadie -->
  <div class="video-background">
    <video id="video-background" autoplay muted loop playsinline poster="/assets/hero-fallback.png">
      <!-- Video pro mobily -->
      <source 
        src="/assets/backgroundmobile.mp4" 
        type="video/mp4" 
        data-media="(max-width: 768px)" 
        data-poster="/assets/hero-mobile-fallback.png" />

      <!-- Video pro desktopy -->
      <source 
        src="/assets/backgroundheroinfinity.mp4" 
        type="video/mp4" 
        data-media="(min-width: 769px)" 
        data-poster="/assets/hero-fallback.png" />

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