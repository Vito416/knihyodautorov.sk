<?php
// templates/partials/header.php
declare(strict_types=1);

// Partial: hlavička (slovenčina). Exposed variables:
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)

$pageTitleSafe = isset($pageTitle) && is_string($pageTitle) ? trim($pageTitle) : 'E-shop knihy';
$appName = 'E-shop';
$displayName = $user['display_name'] ?? null;

// sanitize navActive for body class
$navClass = 'catalog';
if (!empty($navActive) && is_string($navActive)) {
    $navClass = preg_replace('/[^a-z0-9_-]/', '', strtolower($navActive));
    if ($navClass === '') $navClass = 'catalog';
}

// best-effort CSRF meta token (so JS can read it). It's OK that token() creates a session token.
$csrfMeta = '';
if (class_exists('CSRF') && method_exists('CSRF', 'token')) {
    try {
        $t = CSRF::token();
        if (is_string($t) && $t !== '') $csrfMeta = $t;
    } catch (\Throwable $_) {
        $csrfMeta = '';
    }
}
?>
<!doctype html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($pageTitleSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>

    <!-- hlavné CSS (buildovaný, fingerprintovaný v produkcii) -->
    <link rel="stylesheet" href="/eshop/css/app.css" media="screen">

    <?php if ($csrfMeta !== ''): ?>
        <meta name="csrf-token" content="<?= htmlspecialchars($csrfMeta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <?php endif; ?>
</head>
<body class="page-<?= htmlspecialchars($navClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

<header class="site-header" role="banner" aria-label="Hlavná hlavička">
    <div class="wrap header-inner">
        <div class="brand">
            <a href="/eshop/index.php" class="brand-link" title="<?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </a>
        </div>

        <a class="skip-link" href="#content">Preskočiť na obsah</a>

        <!-- mobilné menu toggle -->
        <button id="nav-toggle" class="nav-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Otvoriť hlavné menu">
            ☰
        </button>

        <div class="header-actions" aria-live="polite">
            <?php if (!empty($user) && isset($user['id'])): ?>
                <span class="greeting">Vitajte, <?= htmlspecialchars($displayName ?? ($user['email'] ?? 'Zákazník'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a href="/eshop/profile.php" class="link-account">Môj účet</a>
                <a href="/eshop/orders.php" class="link-orders">Objednávky</a>

                <!-- Bezpečný logout cez POST s CSRF -->
                <form action="/eshop/logout.php" method="post" class="form-inline" style="display:inline;margin:0;padding:0;">
                    <?php
                    if (class_exists('CSRF') && method_exists('CSRF', 'hiddenInput')) {
                        try { echo CSRF::hiddenInput('csrf'); } catch (\Throwable $_) {}
                    }
                    ?>
                    <button type="submit" class="btn btn-link" aria-label="Odhlásiť sa">Odhlásiť</button>
                </form>

            <?php else: ?>
                <a href="/eshop/login.php" class="link-login">Prihlásiť sa</a>
                <a href="/eshop/register.php" class="link-register">Registrovať</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- NAV partial: vložte "templates/partials/nav.php" hneď za header v layout-e -->