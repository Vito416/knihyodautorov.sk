<?php
require __DIR__ . '/inc/bootstrap.php';

// Bezpečný fallback pre $db (ak bootstrap náhodou nedefinoval)
$dbConn = (isset($db) && $db instanceof PDO) ? $db : null;

$err = '';
$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? null;

/**
 * Jednoduchý safe validator pre return_to (nezávislý od login.php).
 * - zakáže schémy (http://), CR/LF, double-slash, dot-traversal, musí začínať '/'
 */
function is_safe_return_to(?string $url): bool {
    if ($url === null || $url === '') return false;
    if (strpos($url, "\n") !== false || strpos($url, "\r") !== false) return false;

    $decoded = rawurldecode($url);
    // disallow absolute urls with scheme
    if (preg_match('#^[a-zA-Z0-9+\-.]+://#', $decoded)) return false;

    $path = parse_url($decoded, PHP_URL_PATH);
    if ($path === null) return false;
    if ($path === '' || $path[0] !== '/') return false;
    if (strpos($path, '//') !== false) return false;
    if (strpos($path, '..') !== false) return false;

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!Auth::validateCsrfToken($csrf)) {
        // CSRF invalid — zobrazíme chybu používateľovi (neodhaľujeme veľa detailov)
        $err = 'Neplatný formulár (CSRF). Ak problém pretrváva, obnovte stránku a skúste to znova.';
    } else {
        // Provedeme logout (Auth::logout sa postará o cookie + session destroy + revokáciu v DB ak db je predané)
        Auth::logout($dbConn);

        // bezpečné presmerovanie (preferujeme return_to ak je validné)
        $loc = '/';
        if ($returnTo && is_safe_return_to($returnTo)) {
            // rawurldecode preto, aby sme nezachovali percent-encoding v url
            $loc = rawurldecode($returnTo);
        }

        header('Location: ' . $loc, true, 302);
        exit;
    }
}

// Priprav CSRF token pre formulár
$csrfToken = Auth::ensureCsrfToken();
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <title>Odhlásenie</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/css/base.css">
</head>
<body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
  <h1>Odhlásenie</h1>

  <?php if ($err): ?>
    <p class="error"><?= htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
  <?php endif; ?>

  <p>Ste si istý, že sa chcete odhlásiť?</p>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <?php if ($returnTo && is_safe_return_to($returnTo)): ?>
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <?php endif; ?>
    <button type="submit">Áno — odhlásiť ma</button>
    <a href="<?= htmlspecialchars( (is_safe_return_to($returnTo) ? rawurldecode($returnTo) : '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="button">Zostať / zrušiť</a>
  </form>

</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body>
</html>