<?php
// /admin/header.php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inc/helpers.php';
$admin = admin_user($pdo);
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Administrácia — Knihy od autorov</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
</head>
<body class="adm-body">
<header class="adm-topbar">
  <div class="adm-left">
    <a class="adm-brand" href="/admin/index.php">Knihy <span>od</span> autorov — Admin</a>
  </div>
  <div class="adm-right">
    <nav class="adm-nav" aria-label="Administrácia">
      <a href="/admin/index.php">Prehľad</a>
      <a href="/admin/books.php">Knihy</a>
      <a href="/admin/authors.php">Autori</a>
      <a href="/admin/orders.php">Objednávky</a>
      <a href="/admin/invoices.php">Faktúry</a>
      <a href="/admin/users.php">Užívatelia</a>
      <a href="/admin/settings.php">Nastavenia</a>
      <a href="/admin/reports.php">Reporty</a>
    </nav>

    <div class="adm-user">
      <?php if ($admin): ?>
        <span class="adm-username"><?= adm_esc($admin['username']) ?></span>
        <a class="adm-btn-link" href="/admin/logout.php">Odhlásiť</a>
      <?php else: ?>
        <a class="adm-btn-link" href="/admin/login.php">Prihlásiť</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="adm-main">
