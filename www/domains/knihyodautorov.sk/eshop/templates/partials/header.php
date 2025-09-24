<?php
declare(strict_types=1);
require __DIR__ . '/header_loader.php';
/**
 * Header partial - EPIC theme
 *
 * Předpoklad: header_loader.php poskytuje $user, $categories, $cart_count, $csrf_token, $navActive, $pageTitle
 */
$pageTitleSafe = isset($pageTitle) && is_string($pageTitle) ? trim($pageTitle) : 'E-shop knihy';
$appName = $_ENV['APP_NAME'] ?? 'Knižnica Stratégov';
$navActive = $navActive ?? 'catalog';
$user = is_array($user ?? null) ? $user : null;
$cart_count = isset($cart_count) ? (int)$cart_count : 0;
$csrf_token = isset($csrf_token) ? (string)$csrf_token : null;

$displayName = $user['display_name'] ?? (($user['given_name'] ?? '') . ' ' . ($user['family_name'] ?? ''));
$displayName = trim($displayName) ?: null;
$avatarUrl = $user['avatar_url'] ?? null;

$logoUrl = $logo_url ?? '/assets/logo.png';
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pageTitleSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>

  <link rel="stylesheet" href="/eshop/css/app.css" media="screen">
  <link rel="preload" href="/eshop/js/app.js" as="script">
  <?php if ($csrf_token !== null && $csrf_token !== ''): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <?php endif; ?>
  <link rel="icon" href="/assets/favicon-epic.png" type="image/png">
</head>
<body class="page-<?= htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', strtolower($navActive)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> epic-theme">
<header class="site-header epic-header" role="banner" aria-label="Hlavná hlavička">
  <div class="wrap header-inner">
    <div class="brand" style="display:flex;align-items:center;gap:.75rem;">
      <a href="/eshop/index.php" class="brand-link" title="<?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="brand-logo">
        <div class="brand-text">
          <div class="app-name"><?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <div class="app-tag">Historická knižnica • Herné prvky • Epický výber</div>
        </div>
      </a>
    </div>

    <a class="skip-link" href="#content">Preskočiť na obsah</a>

    <div class="header-center">
      <form class="search-form" action="/eshop/catalog.php" method="get" role="search" aria-label="Vyhľadávanie kníh">
        <?php if ($csrf_token !== null): ?>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="search-wrap">
          <input name="q" type="search" placeholder="Hľadať: historické, stratégia, PDF..." aria-label="Hľadať knihy" autocomplete="off">
          <button class="btn btn-search" type="submit" aria-label="Hľadať">🔍</button>
        </div>
      </form>
    </div>

    <button id="nav-toggle" class="nav-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Otvoriť hlavné menu">☰</button>

    <div class="header-actions" aria-live="polite">
      <button id="theme-toggle" class="btn btn-ghost" title="Zmeniť vzhľad" aria-pressed="false" aria-label="Prepínač motívu">🜂</button>

      <a href="/eshop/cart.php" class="link-cart" title="Košík">
        <span class="icon">🛒</span>
        <span class="label">Košík</span>
        <?php if ($cart_count > 0): ?>
          <span class="small cart-badge" aria-live="polite"><?= (int)$cart_count ?></span>
        <?php endif; ?>
      </a>

      <?php if (!empty($user) && isset($user['id'])): ?>
        <div class="account-inline">
          <a href="/eshop/profile.php" class="avatar-link" title="Môj účet">
            <?php if (!empty($avatarUrl)): ?>
              <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($displayName ?? 'Profil', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="avatar">
            <?php else: ?>
              <span class="avatar avatar-initial"><?= htmlspecialchars(mb_substr($displayName ?? 'U',0,1,'UTF-8')) ?></span>
            <?php endif; ?>
          </a>
          <div class="account-meta">
            <div class="greeting">Vitaj, <strong><?= htmlspecialchars($displayName ?? 'Zákazník', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
            <div class="account-links">
              <a href="/eshop/profile.php">Môj účet</a> · <a href="/eshop/orders.php">Objednávky</a>
            </div>
          </div>
        </div>

        <form action="/eshop/logout.php" method="post" class="form-inline logout-form" style="display:inline;margin:0;padding:0;">
          <?php
          if (class_exists('CSRF') && method_exists('CSRF', 'hiddenInput')) {
              try { echo CSRF::hiddenInput('csrf'); } catch (\Throwable $_) {}
          } else {
              if ($csrf_token !== null) {
                  echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
              }
          }
          ?>
          <button type="submit" class="btn btn-ghost" aria-label="Odhlásiť sa">Odhlásiť</button>
        </form>
      <?php else: ?>
        <a href="/eshop/login.php" class="link-login">Prihlásiť sa</a>
        <a href="/eshop/register.php" class="link-register">Registrovať</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- decorative epic crest behind header -->
  <div class="header-crest" aria-hidden="true"></div>
</header>

<?php
// include nav partial if available
$navPartial = __DIR__ . '/nav.php';
if (file_exists($navPartial)) {
    try { include $navPartial; } catch (\Throwable $_) {}
}
?>

<main id="content" class="wrap" role="main" tabindex="-1">
<?php
// include flash partial if exists
$flashPartial = __DIR__ . '/flash.php';
if (file_exists($flashPartial)) {
    try { include $flashPartial; } catch (\Throwable $_) {}
}
?>