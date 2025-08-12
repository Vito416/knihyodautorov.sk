<?php
// /admin/partials/header.php
declare(strict_types=1);
// Tento header predpokladá, že predtým bol includnutý /admin/bootstrap.php (obsahuje $pdo a admin helpers)
// Nevoláme session_start() tu – to robí bootstrap.php

// bezpečné escape (ak nie je definované globálne esc(), použijeme lokálne)
if (!function_exists('admin_esc')) {
    function admin_esc($s) {
        if (function_exists('esc')) return esc($s);
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$adminInfo = null;
if (function_exists('admin_user')) {
    $adminInfo = admin_user($pdo);
}
$brand = '/assets/logoobdelnikbezpozadi.png';
?>
<link rel="stylesheet" href="/admin/css/admin.css">
<header class="admin-header" role="banner" aria-label="Admin hlavička">
  <div class="admin-header-inner">
    <a class="admin-brand" href="/admin/index.php" title="Administrácia - Knihy od autorov">
      <img src="<?php echo admin_esc($brand); ?>" alt="logo" class="admin-brand-logo" onerror="this.onerror=null;this.src='/assets/favicon.ico'">
      <div class="admin-brand-text">
        <span class="brand-main">Knihy <strong>od</strong> autorov</span>
        <small class="brand-sub">Administrácia</small>
      </div>
    </a>

    <nav class="admin-nav" role="navigation" aria-label="Hlavné administratívne menu">
      <button id="admin-hamburger" class="admin-hamburger" aria-expanded="false" aria-controls="admin-nav-list">☰</button>
      <ul id="admin-nav-list" class="admin-nav-list">
        <li><a href="/admin/index.php">Prehľad</a></li>
        <li><a href="/admin/books.php">Knihy</a></li>
        <li><a href="/admin/authors.php">Autori</a></li>
        <li><a href="/admin/orders.php">Objednávky</a></li>
        <li><a href="/admin/invoices.php">Faktúry</a></li>
        <li><a href="/admin/settings.php">Nastavenia</a></li>
        <li><a href="/admin/audit.php">Audit</a></li>
      </ul>
    </nav>

    <div class="admin-user-area" role="region" aria-label="Admin používateľ">
      <?php if (!empty($adminInfo) && is_array($adminInfo)): ?>
        <div class="admin-user">
          <div class="admin-user-name"><?php echo admin_esc($adminInfo['username'] ?? $adminInfo['email'] ?? 'Správca'); ?></div>
          <div class="admin-user-sub"><?php echo admin_esc($adminInfo['role'] ?? ''); ?></div>
          <a class="btn-ghost" href="/admin/logout.php" title="Odhlásiť">Odhlásiť</a>
        </div>
      <?php else: ?>
        <a class="btn" href="/admin/login.php">Prihlásiť</a>
      <?php endif; ?>
    </div>
  </div>
</header>