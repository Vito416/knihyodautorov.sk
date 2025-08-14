<?php
/**
 * /eshop/templates/header.php
 * Vylepšený header: preloady, logo fallback, search, mobile menu toggle, $extraCss / $extraJs
 * Očakáva premenné: $pageTitle, $metaDescription, $extraCss (array), $extraJs (array)
 */

if (!isset($pageTitle)) $pageTitle = 'Knihy od Autorov';
if (!isset($metaDescription)) $metaDescription = '';
if (!isset($extraCss) || !is_array($extraCss)) $extraCss = [];
if (!isset($extraJs)  || !is_array($extraJs))  $extraJs = [];

/* base URL (ak nie je definované) */
if (!isset($baseUrl)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'knihyodautorov.sk';
    $baseUrl = $scheme . '://' . $host;
}

/* logo fallback (ak nie je /assets/logo.png) */
$logoPublic = '/assets/logo.png';
$logoPath = realpath(__DIR__ . '/../../assets/logo.png');
if (!$logoPath) {
    $logoPublic = null;
}

/* shop texture fallback */
$shopTexturePublic = $shopTexturePublic ?? '/assets/shop-texture.jpg';
$shopTexturePath = realpath(__DIR__ . '/../../assets/shop-texture.jpg');

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES|ENT_HTML5); ?></title>
  <?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES|ENT_HTML5); ?>">
  <?php endif; ?>

  <!-- canonical (pokud je definováno) -->
  <?php if (!empty($_SERVER['REQUEST_URI'])): 
      $canonical = $baseUrl . strtok($_SERVER['REQUEST_URI'], '?');
  ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES|ENT_HTML5); ?>">
  <?php endif; ?>

  <!-- Preconnect / preload fonts (lepší FCP) -->
  <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <!-- Favicon -->
  <link rel="icon" href="/assets/favicon.ico" type="image/x-icon">

  <!-- Základné CSS (shared) -->
  <link rel="stylesheet" href="/eshop/css/eshop.css">
  <!-- Extra CSS (index.css alebo iné) -->
  <?php foreach ($extraCss as $css): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($css, ENT_QUOTES|ENT_HTML5); ?>">
  <?php endforeach; ?>

  <!-- Theme color -->
  <meta name="theme-color" content="#cf9b3a">

  <!-- Basic security headers for inline scripts: use nonces if server-side supports -->
</head>
<body class="<?= isset($bodyClass) ? htmlspecialchars($bodyClass, ENT_QUOTES|ENT_HTML5) : 'page'; ?>">

  <div class="bar" role="status" aria-live="polite">
    ✨ Vítame vás — nový výber epických titulov každý týždeň.
  </div>

  <header class="site-header" role="banner">
    <div class="container header__inner">
      <div class="brand-wrap" style="display:flex;align-items:center;gap:.75rem;">
        <a class="brand" href="/eshop/index.php" aria-label="Knihy od Autorov — domov">
          <?php if ($logoPublic): ?>
            <img src="<?= htmlspecialchars($logoPublic, ENT_QUOTES|ENT_HTML5); ?>" alt="Knihy od Autorov" style="height:44px; display:block;">
          <?php else: ?>
            <span class="brand-text">Knihy od Autorov</span>
          <?php endif; ?>
        </a>

        <!-- search (rychlé) -->
        <form class="header-search" action="/eshop/index.php" method="get" role="search" style="margin-left:1rem;">
          <label for="header-search-input" class="visually-hidden">Hľadať v katalógu</label>
          <input id="header-search-input" name="q" type="search" placeholder="Hľadať názov alebo autora..." value="<?= htmlspecialchars((string)($_GET['q'] ?? ''), ENT_QUOTES|ENT_HTML5); ?>" aria-label="Hľadať v katalógu">
        </form>
      </div>

      <!-- navigace -->
      <nav class="nav" role="navigation" aria-label="Hlavná navigácia">
        <a href="/eshop/catalog.php">Katalóg</a>
        <a href="/eshop/index.php">Obchód</a>
        <a href="/eshop/cart.php" aria-label="Košík">Košík<?php
            $cartCount = (function_exists('cart_count') ? (int)cart_count() : 0);
            echo $cartCount > 0 ? ' <sup class="cart-count" aria-hidden="true">'. $cartCount .'</sup>' : '';
        ?></a>
        <?php if (function_exists('auth_user_id') && auth_user_id()): ?>
          <a href="/eshop/account/account.php">Môj účet</a>
        <?php else: ?>
          <a href="/eshop/account/login.php">Prihlásiť</a>
          <a class="btn btn-primary" href="/eshop/account/register.php">Registrovať</a>
        <?php endif; ?>
      </nav>

      <!-- mobile menu toggle -->
      <button class="menu-toggle" aria-expanded="false" aria-controls="main-nav" aria-label="Otvoriť menu" style="margin-left:1rem;">
        ☰
      </button>
    </div>
  </header>

  <noscript>
    <div class="noscript-warning" style="background: #2b1d0f; color: #ffefc6; padding:.75rem; text-align:center;">
      Pre plné vizuálne zážitky a interakcie je potrebný JavaScript. Niektoré funkcie môžu byť obmedzené.
    </div>
  </noscript>

<!-- header.php končí tu; obsah stránky bude nasledovať -->
