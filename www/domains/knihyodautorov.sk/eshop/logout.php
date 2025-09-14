<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

// Bezpečný fallback pro $db (pokud bootstrap nedefinoval)
$dbConn = (isset($db) && $db instanceof PDO) ? $db : null;

$err = '';
$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? null;

/**
 * Jednoduchý safe validator pro return_to
 */
function is_safe_return_to(?string $url): bool {
    if (!$url) return false;
    if (strpos($url, "\n") !== false || strpos($url, "\r") !== false) return false;

    $decoded = rawurldecode($url);
    if (preg_match('#^[a-zA-Z0-9+\-.]+://#', $decoded)) return false;

    $path = parse_url($decoded, PHP_URL_PATH);
    return $path !== null && $path !== '' && $path[0] === '/' && strpos($path, '//') === false && strpos($path, '..') === false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!CSRF::validate($csrf)) {
        $err = 'Neplatný formulár (CSRF). Obnovte stránku a skúste to znovu.';
    } else {
        // --- moderní logout ---
        $userId = $_SESSION['user_id'] ?? null;
        SessionManager::destroySession($dbConn);
        Logger::session('session_destroyed', $userId);

        // bezpečný redirect
        $loc = '/';
        if ($returnTo && is_safe_return_to($returnTo)) {
            $loc = $returnTo;
        }

        header('Location: ' . $loc, true, 302);
        exit;
    }
}

// CSRF token pro formulář
$csrfToken = CSRF::token();
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
    <a href="<?= htmlspecialchars((is_safe_return_to($returnTo) ? $returnTo : '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="button">Zostať / zrušiť</a>
  </form>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body>
</html>