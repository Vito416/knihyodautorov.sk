<?php
declare(strict_types=1);

// fallback proměnné
$pageTitleSafe = isset($pageTitle) && is_string($pageTitle) ? trim($pageTitle) : 'E-shop knihy';
$appName       = $_ENV['APP_NAME'] ?? 'Knižnica Stratégov';
$navActive     = $navActive ?? 'home';
$user          = is_array($user ?? null) ? $user : null;
$cart_count    = isset($cart_count) ? (int) $cart_count : 0;

$displayName = $user['display_name'] ?? (($user['given_name'] ?? '') . ' ' . ($user['family_name'] ?? ''));
$displayName = trim($displayName) ?: null;
$avatarUrl   = $user['avatar_url'] ?? null;
$logoUrl     = $logo_url ?? '/eshop/assets/app/logo-header.png';

$categories = is_array($categories) ? $categories : [];
$activeClass = fn(string $k) => $navActive === $k ? ' active header_nav-item--active' : '';

$nav_list_id = 'header_nav_list';
$categories_dropdown_id = 'header_nav_categories';
$mobile_nav_id = 'header_nav_mobile';
// odkomentuj si to až bude potřeba... echo('header: user type=' . gettype($user) . ' dump=' . print_r($user, true));
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" href="/eshop/assets/app/favicon.ico" type="image/x-icon">
  <link rel="shortcut icon" href="/eshop/assets/app/favicon.ico" type="image/x-icon">
  <title><?= htmlspecialchars($pageTitleSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/eshop/css/app.css" media="screen">
  <link rel="stylesheet" href="/eshop/css/header.css" media="screen">
  <link rel="stylesheet" href="/eshop/css/catalog.css" media="screen">
  <link rel="stylesheet" href="/eshop/css/flash.css">
  <link rel="stylesheet" href="/eshop/css/checkout.css">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
</head>
<body class="page-<?= htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', strtolower($navActive)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> epic-theme">

<header class="header_root header_epic" role="banner" aria-label="Hlavná hlavička">
  <div class="wrap header_wrap">

    <!-- logo s efektem zlatého třpytu -->
    <div class="header_brand">
      <a href="/eshop/" class="header_brand-link" title="<?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
             class="header_brand-logo">
        <span class="header_brand-glow"></span>
      </a>
    </div>

    <!-- search -->
    <div class="header_center">
      <form class="header_search-form" action="/eshop/catalog" method="get" role="search" aria-label="Vyhľadávanie kníh" data-header-form="search">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="header_search-wrap">
          <label for="header_search_q" class="visually-hidden">Hľadať knihy</label>
          <input id="header_search_q" name="q" type="search" class="header_search-input" placeholder="Hľadať knihy…"
                 aria-label="Hľadať knihy" autocomplete="off" data-header-input="search-q"
                 value="<?= isset($_GET['q']) ? htmlspecialchars((string)$_GET['q'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>">
          <button type="submit" class="header_btn header_btn-search" aria-label="Hľadať" data-header-action="search-submit">🔍</button>
          <div id="header_search_suggestions" class="header_search-suggestions" aria-hidden="true" data-header-suggestions></div>
        </div>
      </form>
    </div>

    <!-- actions: theme toggle, cart, login/account -->
    <div class="header_actions" aria-live="polite" data-header-actions>
      <button class="header_btn header_btn--ghost header_theme-toggle" type="button" aria-pressed="false" aria-label="Prepínač motívu" data-header-action="theme-toggle">🌓</button>

      <!-- mini-cart s pergamen efektem -->
      <a class="header_link header_link-cart"
         title="<?= $cart_count > 0 ? 'Košík, ' . $cart_count . ' položiek' : 'Košík' ?>"
         aria-label="<?= $cart_count > 0 ? 'Košík, ' . $cart_count . ' položiek' : 'Košík' ?>"
         data-header-link="cart" data-header-cart-count="<?= $cart_count ?>">
        <span class="header_icon header_icon-cart" aria-hidden="true">🛒</span>
        <span class="header_cart-text visually-hidden">Košík</span>
        <?php if ($cart_count > 0): ?>
          <span class="header_cart-badge" role="status" aria-live="polite" aria-atomic="true" data-header-badge><?= $cart_count ?></span>
        <?php else: ?>
          <span class="header_cart-badge header_cart-badge--empty visually-hidden" aria-hidden="true" data-header-badge="0"></span>
        <?php endif; ?>
        <div class="header_cart-dropdown" aria-hidden="true"></div>
      </a>

      <?php if (!empty($user) && isset($user['id'])): ?>
        <div class="header_account header_account-inline" data-header-account>
          <a href="/eshop/profile" class="header_avatar-link" title="Môj účet" aria-label="Môj účet">
            <?php if ($avatarUrl): ?>
              <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   alt="<?= htmlspecialchars($displayName ?? 'Profil', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   class="header_avatar-img">
            <?php else: ?>
              <span class="header_avatar-initial" aria-hidden="true"><?= htmlspecialchars(mb_substr($displayName ?? 'U', 0, 1, 'UTF-8')) ?></span>
            <?php endif; ?>
          </a>
          <div class="header_account-meta">
            <div class="header_greeting">Vitaj, <strong><?= htmlspecialchars($displayName ?? 'Zákazník', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
            <div class="header_account-links">
              <a href="/eshop/profile" class="header_link-account">Môj účet</a> ·
              <a href="/eshop/orders" class="header_link-orders">Objednávky</a>
            </div>
          </div>
        </div>

        <form action="/eshop/logout" method="post" class="header_logout-form" data-header-form="logout">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <button type="submit" class="header_btn header_btn-ghost" aria-label="Odhlásiť sa">Odhlásiť</button>
        </form>
      <?php else: ?>
        <a href="/eshop/login" class="header_link header_link-login">Prihlásiť sa</a>
        <a href="/eshop/register" class="header_link header_link-register">Registrovať</a>
      <?php endif; ?>

    </div>
  </div>
</header>

<!-- nav s epickým dropdownem -->
<nav id="main-nav" class="header_nav header_nav-root" role="navigation" aria-label="Hlavná navigácia" itemscope itemtype="https://schema.org/SiteNavigationElement" data-header-nav>
  <div class="wrap header_nav-inner">
    <button class="header_nav-toggle" aria-controls="<?= htmlspecialchars($mobile_nav_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-expanded="false" aria-label="Otvoriť menu" data-header-toggle="nav">
      <span class="header_nav-toggle-box" aria-hidden="true">☰</span>
    </button>

    <ul id="<?= htmlspecialchars($nav_list_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="header_nav-list" role="menubar" aria-label="Hlavné menu" data-header-list>
      <li role="none" class="header_nav-item<?= $activeClass('home') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/" <?= $navActive==='home'?'aria-current="page"':'' ?>>Domov</a></li>
      <li role="none" class="header_nav-item<?= $activeClass('catalog') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/catalog" <?= $navActive==='catalog'?'aria-current="page"':'' ?>>Katalóg</a></li>
      <li role="none" class="header_nav-item<?= $activeClass('authors') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/authors" <?= $navActive==='authors'?'aria-current="page"':'' ?>>Autori</a></li>

      <li role="none" class="header_nav-item header_nav-item--has-dropdown<?= $activeClass('categories') ?>">
        <button class="header_nav-dropdown-toggle" aria-haspopup="true" aria-controls="<?= htmlspecialchars($categories_dropdown_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-expanded="false" data-header-toggle="categories">
          Kategórie <span class="header_nav-caret" aria-hidden="true">▾</span>
        </button>
        <div id="<?= htmlspecialchars($categories_dropdown_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="header_nav-dropdown" role="menu" aria-label="Kategórie" data-header-dropdown>
          <ul class="header_nav-sublist">
            <?php if(!empty($categories)): foreach($categories as $cat):
              $slugRaw = (string)($cat['slug'] ?? '');
              $slug = htmlspecialchars($slugRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
              $name = htmlspecialchars(html_entity_decode((string)($cat['nazov'] ?? 'Bez názvu')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
              $icon='📜'; $slugLower=strtolower($slugRaw);
              if(strpos($slugLower,'beletria')!==false)$icon='📖';
              if(strpos($slugLower,'detektiv')!==false||strpos($slugLower,'krimi')!==false)$icon='🕵️';
              if(strpos($slugLower,'non')!==false||strpos($slugLower,'nonfiction')!==false)$icon='📚';
              if(strpos($slugLower,'dets')!==false||strpos($slugLower,'dzieci')!==false||strpos($slugLower,'children')!==false)$icon='🧒';
            ?>
              <li role="none" class="header_nav-subitem">
                <a role="menuitem" class="header_nav-sublink" href="/eshop/catalog?cat=<?= $slug ?>" data-header-cat="<?= $slug ?>">
                  <span class="header_nav-icon" aria-hidden="true"><?= $icon ?></span>
                  <span class="header_nav-catname"><?= $name ?></span>
                  <span class="header_nav-hover-glow"></span>
                </a>
              </li>
            <?php endforeach; else: ?>
              <li role="none" class="header_nav-subitem header_nav-subitem--empty"><span class="header_nav-empty">Neboli nájdené žiadne kategórie</span></li>
            <?php endif; ?>
          </ul>
        </div>
      </li>

      <li role="none" class="header_nav-item<?= $activeClass('new') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/new" <?= $navActive==='new'?'aria-current="page"':'' ?>>Novinky <span class="header_badge header_badge--new" aria-hidden="true">Nové</span></a></li>
      <li role="none" class="header_nav-item<?= $activeClass('events') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/events" <?= $navActive==='events'?'aria-current="page"':'' ?>>Akcie <span class="header_badge header_badge--epic" aria-hidden="true">Zľavy</span></a></li>
      <li role="none" class="header_nav-item<?= $activeClass('gdpr') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/gdpr"<?= $navActive==='gdpr'?'aria-current="page"':'' ?>>Ochrana osobných údajov</a></li>
      <li role="none" class="header_nav-item<?= $activeClass('vop') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/vop"<?= $navActive==='vop'?'aria-current="page"':'' ?>>Obchodné podmienky</a></li>
      <li role="none" class="header_nav-item<?= $activeClass('reklamacie') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/reklamacie"<?= $navActive==='reklamacie'?'aria-current="page"':'' ?>>Reklamačný poriadok</a></li>
      <li role="none" class="header_nav-item<?= $activeClass('contact') ?>"><a role="menuitem" class="header_nav-link" href="/eshop/contact"<?= $navActive==='contact'?'aria-current="page"':'' ?>>Kontakt</a></li>
    </ul>
  </div>

  <div id="<?= htmlspecialchars($mobile_nav_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="header_nav-mobile" aria-hidden="true" data-header-mobile>
    <ul class="header_nav-mobile-list" role="menu" aria-label="Mobilné menu">
      <li role="none"><a role="menuitem" href="/eshop/">Domov</a></li>
      <li role="none"><a role="menuitem" href="/eshop/catalog">Katalóg</a></li>
      <li role="none"><a role="menuitem" href="/eshop/authors">Autori</a></li>
      <li role="none"><a role="menuitem" href="/eshop/new">Novinky</a></li>
      <li role="none"><a role="menuitem" href="/eshop/events">Akcie / Zľavy</a></li>
      <li role="none"><a role="menuitem" href="/eshop/gdpr">Ochrana osobných údajov</a></li>
      <li role="none"><a role="menuitem" href="/eshop/vop">Obchodné podmienky</a></li>
      <li role="none"><a role="menuitem" href="/eshop/reklamacie">Reklamačný poriadok</a></li>
      <li role="none"><a role="menuitem" href="/eshop/contact">Kontakt</a></li>
    </ul>
  </div>
</nav>
<main id="content" class="wrap main_wrap" role="main" tabindex="-1">