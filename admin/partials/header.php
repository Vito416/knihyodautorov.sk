<?php
// /admin/partials/header.php

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

// Bezpečne načítame bootstrap (ak už bol načítaný, require_once ho nezdvojuje)
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/notifications.php';

// Helper pre escape, ak nie je definovaný
if (!function_exists('admin_esc')) {
    function admin_esc($s) {
        if (function_exists('esc')) return esc($s);
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$admin = null;
try {
    $admin = function_exists('admin_user') ? admin_user($pdo) : null;
} catch (Throwable $e) {
    $admin = null;
}

$siteName = 'Knihy od Autorov';
$logo = '/assets/logoobdelnikbezpozadi.png';
$nowYear = (int)date('Y');

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin — <?php echo admin_esc($siteName); ?></title>

  <!-- hlavné admin CSS + drobné partial CSS -->
  <link rel="stylesheet" href="/admin/css/admin.css" />
  <link rel="stylesheet" href="/admin/css/partials.css" />

  <!-- hlavné admin JS (deferred) -->
  <script src="/admin/js/admin.js" defer></script>

  <!-- drobné meta -->
  <meta name="robots" content="noindex,nofollow" />
</head>
<body class="admin-body">

<header class="admin-header">
  <div class="admin-header-inner">
    <div class="brand">
      <a href="/" class="brand-link" title="Prejsť na web">
        <img src="<?php echo admin_esc($logo); ?>" alt="<?php echo admin_esc($siteName); ?>" class="brand-logo" onerror="this.onerror=null;this.src='/assets/favicon.ico'">
        <div class="brand-text">
          <div class="brand-title">Knihy <span>od</span> autorov</div>
          <div class="brand-sub">Admin rozhranie</div>
        </div>
      </a>
    </div>

    <nav class="admin-nav" aria-label="Hlavná navigácia administrácie">
      <a class="nav-link" href="/admin/index.php">Prehľad</a>
      <a class="nav-link" href="/admin/books.php">Knihy</a>
      <a class="nav-link" href="/admin/authors.php">Autori</a>
      <a class="nav-link" href="/admin/orders.php">Objednávky <span class="badge-pending-count"><?php // JS môže aktualizovať ?></span></a>
      <a class="nav-link" href="/admin/users.php">Užívatelia</a>
      <a class="nav-link" href="/admin/settings.php">Nastavenia</a>
      <a class="nav-link" href="/admin/exports.php">Exporty</a>
      <a class="nav-link" href="/admin/debug/lib-test.php">Debug</a>
    </nav>

    <div class="admin-tools">
      <form class="admin-search" action="/admin/search.php" method="get" role="search" aria-label="Hľadať v administrácii">
        <input name="q" type="search" placeholder="Vyhľadaj knihu / autora / objednávku…" aria-label="Vyhľadaj" />
      </form>

      <div class="admin-user">
        <?php if ($admin): ?>
          <button class="user-btn" id="user-menu-toggle" aria-expanded="false" aria-haspopup="true">
            <span class="user-name"><?php echo admin_esc($admin['username'] ?? $admin['email'] ?? 'Admin'); ?></span>
            <span class="user-role"><?php echo admin_esc($admin['role'] ?? 'správca'); ?></span>
          </button>
          <div class="user-menu" id="user-menu" role="menu" aria-hidden="true">
            <a role="menuitem" href="/admin/profile.php">Môj profil</a>
            <a role="menuitem" href="/admin/settings.php">Nastavenia</a>
            <a role="menuitem" href="/admin/actions/logout.php">Odhlásiť</a>
          </div>
        <?php else: ?>
          <a class="btn" href="/admin/login.php">Prihlásiť</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>

<main id="admin-main" class="admin-main container" role="main" aria-live="polite">
<!-- main obsah začne tu -->