<?php
// templates/partials/header.php
declare(strict_types=1);

/**
 * Header partial (slovenčina).
 * - Predpokladá, že pred includom bol spustený loader alebo controller nastavil:
 *    $user (null|array), $categories (array), $cart_count (int),
 *    $csrf_token (string|null), $navActive (string|null), $pageTitle (string|null)
 *
 * - Ak loader nie je zaintegrovaný, snažíme sa ho includnúť pokusne.
 */

$partialsDir = __DIR__;

// try to include loader if not already
$loader = __DIR__ . '/header_loader.php';
if (file_exists($loader) && !defined('TEMPLATES_LOADER_INCLUDED')) {
    try { require_once $loader; } catch (\Throwable $_) {}
}

// safe defaults
$pageTitleSafe = isset($pageTitle) && is_string($pageTitle) ? trim($pageTitle) : 'E-shop knihy';
$appName = $_ENV['APP_NAME'] ?? 'KnihyOdAutorov';
$navActive = $navActive ?? 'catalog';
$user = is_array($user ?? null) ? $user : null;
$cart_count = isset($cart_count) ? (int)$cart_count : 0;
$csrf_token = isset($csrf_token) ? (string)$csrf_token : null;

// display name
$displayName = $user['display_name'] ?? ($user['given_name'] ?? '') . ' ' . ($user['family_name'] ?? '');
$displayName = trim($displayName) ?: null;

// logo: prefer inline cid names (Mailer) if passed via template variables
$logoCid = $logo_cid ?? ($__img_logo_cid ?? null);
$logoUrl = $logo_url ?? ($__img_logo_url ?? null);
if (!empty($logoCid)) {
    $logoSrc = 'cid:' . $logoCid;
} elseif (!empty($logoUrl)) {
    $logoSrc = $logoUrl;
} else {
    $logoSrc = '/assets/logo.png';
}

// minimal CSP-friendly preload for CSS/JS can be added by ops if needed
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

    <!-- optional: favicon -->
    <link rel="icon" href="/assets/favicon.ico" type="image/x-icon">
</head>
<body class="page-<?= htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', strtolower($navActive)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

<header class="site-header" role="banner" aria-label="Hlavná hlavička">
    <div class="wrap header-inner">
        <div class="brand" style="display:flex;align-items:center;gap:.5rem;">
            <a href="/eshop/index.php" class="brand-link" title="<?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" style="height:40px;vertical-align:middle;">
                <span style="margin-left:.25rem;"><?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </a>
        </div>

        <a class="skip-link" href="#content">Preskočiť na obsah</a>

        <button id="nav-toggle" class="nav-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Otvoriť hlavné menu">
            ☰
        </button>

        <div class="header-actions" aria-live="polite">
            <a href="/eshop/cart.php" class="link-cart" title="Košík">
                Košík
                <?php if ($cart_count > 0): ?>
                    <span class="small" aria-live="polite"> (<?= (int)$cart_count ?>)</span>
                <?php endif; ?>
            </a>

            <?php if (!empty($user) && isset($user['id'])): ?>
                <span class="greeting" aria-hidden="true">Vitajte, <?= htmlspecialchars($displayName ?? 'Zákazník', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a href="/eshop/profile.php" class="link-account">Môj účet</a>
                <a href="/eshop/orders.php" class="link-orders">Objednávky</a>

                <form action="/eshop/logout.php" method="post" class="form-inline" style="display:inline;margin:0;padding:0;">
                    <?php
                    if (class_exists('CSRF') && method_exists('CSRF', 'hiddenInput')) {
                        try { echo CSRF::hiddenInput('csrf'); } catch (\Throwable $_) {}
                    } else {
                        if ($csrf_token !== null) {
                            echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
                        }
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

<?php
// include nav partial (silne odporúčané)
$navPartial = __DIR__ . '/nav.php';
if (file_exists($navPartial)) {
    try { include $navPartial; } catch (\Throwable $_) {}
}
?>

<main id="content" class="wrap" role="main" tabindex="-1">
    <!-- flash area (templates/partials/flash.php môžu vložiť svoje správy sem) -->
    <?php
    // optionally include flash partial if exists
    $flashPartial = __DIR__ . '/flash.php';
    if (file_exists($flashPartial)) {
        try { include $flashPartial; } catch (\Throwable $_) {}
    }
    ?>