<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$email = $_POST['email'] ?? '';
$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? null;
$err = '';
$info = '';

/* ----------------- Helpers ----------------- */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_safe_return_to(?string $url): bool {
    if (!$url) return false;
    if (strpos($url, "\n") !== false || strpos($url, "\r") !== false) return false;
    $decoded = rawurldecode($url);
    if (preg_match('#^[a-zA-Z0-9+\-.]+://#', $decoded)) return false;
    $path = parse_url($decoded, PHP_URL_PATH);
    return $path !== null && $path !== '' && $path[0] === '/' && strpos($path, '//') === false && strpos($path, '..') === false;
}

/* ----------------- Handle POST ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailNorm = strtolower(trim($email));
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!CSRF::validate($csrfToken)) {
        Logger::warn('Login attempt with invalid CSRF token', null, ['email' => $emailNorm]);
        $err = 'CSRF token neplatný';
    } elseif (!filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
        $err = 'Neplatné prihlasovacie údaje.';
    } else {
        try {
            [$user, $status] = Auth::login($db, $emailNorm, $password, (int)($_ENV['LOGIN_MAX_FAILED'] ?? 5));

            if (!$user) {
                $err = 'Neplatné prihlasovacie údaje.';
            } elseif ($status === 'MUST_CHANGE_PASSWORD') {
                $loc = '/eshop/change-password.php';
                if ($returnTo && is_safe_return_to($returnTo)) {
                    $loc .= '?return_to=' . urlencode($returnTo);
                }
                header('Location: ' . $loc, true, 302);
                exit;
            } else {
                // session
                SessionManager::createSession(
                    $db,
                    (int)$user['id'],
                    (int)($_ENV['SESSION_LIFETIME_DAYS'] ?? 30),
                    true,
                    'Lax'
                );

                // redirect logic
                $loc = '/eshop/';
                if ($returnTo && is_safe_return_to($returnTo)) {
                    $decoded = rawurldecode($returnTo);
                    $path = parse_url($decoded, PHP_URL_PATH) ?: '';
                    $query = parse_url($decoded, PHP_URL_QUERY);
                    $finalReturn = $path . ($query ? '?' . $query : '');

                    if (Auth::isAdmin($user)) {
                        $loc = strpos($path, '/admin') === 0 ? $finalReturn : '/admin/';
                    } else {
                        $loc = strpos($path, '/admin') === 0 ? '/eshop/' : $finalReturn;
                    }
                } elseif (Auth::isAdmin($user)) {
                    $loc = '/admin/';
                }

                header('Location: ' . $loc, true, 302);
                exit;
            }
        } catch (\Throwable $e) {
            Logger::systemError($e);
            $err = 'Prihlásenie zlyhalo. Skúste to neskôr.';
        }
    }
}

// CSRF token
$csrfToken = CSRF::token();

// Info messages
if (isset($_GET['registered']) && $_GET['registered'] == 1) {
    $info = 'Registrácia bola úspešne dokončená. Skontrolujte si svoj e-mail a aktivujte svoj účet.';
}

$verificationMessages = [
    0 => 'E-mail bol úspešne potvrdený. Môžete sa prihlásiť.',
    1 => 'Účet neexistuje.',
    2 => 'Účet je zablokovaný na 15 minút kvôli príliš veľa neúspešným pokusom.',
    3 => 'Príliš veľa neúspešných pokusov. Skúste to neskôr.',
    4 => 'Neplatný alebo expirovaný odkaz. Pošlite nový ověřovací e-mail.',
    5 => 'Tento odkaz už bol použitý.',
    6 => 'Odkaz vypršal.',
    7 => 'Tento odkaz už bol použitý alebo účet je aktívny.',
    8 => 'Došlo k chybe. Skúste neskôr.',
];

if (isset($_GET['verified'])) {
    $code = (int)$_GET['verified'];
    $info = $verificationMessages[$code] ?? 'Neznámy stav overenia.';
}
?>
<!doctype html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Prihlásenie</title>
    <link rel="stylesheet" href="assets/css/base.css">
</head>
<body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
    <h1>Prihlásenie</h1>

    <?php if ($err): ?>
        <p class="error"><?= e($err) ?></p>
    <?php elseif ($info): ?>
        <p class="info"><?= e($info) ?></p>
    <?php endif; ?>

    <form method="post" novalidate>
        <label>Email
            <input type="email" name="email" required value="<?= e($email) ?>">
        </label>
        <label>Heslo
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <?php if ($returnTo && is_safe_return_to($returnTo)): ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <?php endif; ?>
        <button type="submit">Prihlásiť</button>
    </form>
    <p><a href="register.php">Registrovať</a></p>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body>
</html>