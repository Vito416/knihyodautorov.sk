<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$err = '';
$email = '';
$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? null;
/* ----------------- Helpers ----------------- */
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Safe return_to validator
 */
function is_safe_return_to(?string $url): bool {
    if ($url === null || $url === '') return false;
    if (strpos($url, "\n") !== false || strpos($url, "\r") !== false) return false;
    $decoded = rawurldecode($url);
    if (preg_match('#^[a-zA-Z0-9+\-.]+://#', $decoded)) return false;
    $path = parse_url($decoded, PHP_URL_PATH);
    if ($path === null) return false;
    if ($path === '' || $path[0] !== '/') return false;
    if (strpos($path, '//') !== false) return false;
    if (strpos($path, '..') !== false) return false;
    return true;
}

/* ----------------- Pepper loader (strict: KeyManager only, no fallback) ----------------- */
$pepper = null;
try {
    if (!class_exists('KeyManager')) {
        if (class_exists('Logger')) Logger::critical('KeyManager class missing - cannot load PASSWORD_PEPPER');
        throw new RuntimeException('KeyManager not available');
    }

    $pepper = KeyManager::getPasswordPepper(); // will throw if missing/invalid
    if (!is_string($pepper) || $pepper === '') {
        throw new RuntimeException('KeyManager returned empty pepper');
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) {
        Logger::systemError($e, null, null, ['stage' => 'loadPepper'], true);
        Logger::critical('PASSWORD_PEPPER unavailable; authentication disabled');
    } else {
        error_log('[login] PASSWORD_PEPPER unavailable: ' . $e->getMessage());
    }
    // fail closed: do not allow authentication if pepper unavailable
    $err = 'Prihlasovanie momentálne nie je možné. Skúste to neskôr.';
}

function password_preprocess_for_hash(string $password, ?string $pepper)
{
    if ($pepper === null) throw new RuntimeException('password_preprocess_for_hash requires pepper');
    return hash_hmac('sha256', $password, $pepper, true);
}

/* ----------------- Argon2 options ----------------- */
$ARGON2_OPTS = [
    'memory_cost' => (int)($_ENV['ARGON_MEMORY_KIB'] ?? (1 << 16)),
    'time_cost'   => (int)($_ENV['ARGON_TIME_COST'] ?? 4),
    'threads'     => (int)($_ENV['ARGON_THREADS'] ?? 2),
];

/* ----------------- Throttling: count failures from auth_events (no new tables) ----------------- */
function count_recent_failures_from_auth_events(PDO $db, ?int $userId, string $email, ?string $ip, int $minutes = 15): int {
    $minutes = (int)$minutes;

    $conds = ["type = 'login_failure'", "occurred_at >= (NOW() - INTERVAL {$minutes} MINUTE)"];
    $params = [];
    $sub = [];

    if ($userId !== null) {
        $sub[] = 'user_id = :uid';
        $params[':uid'] = $userId;
    } else {
        // přesné porovnání emailu v JSON meta (MySQL 5.7+)
        $sub[] = "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) = :email";
        $params[':email'] = $email;
    }

    if ($ip !== null) {
        $sub[] = 'ip = :ip';
        $params[':ip'] = $ip;
    }

    if (!empty($sub)) {
        $conds[] = '(' . implode(' OR ', $sub) . ')';
    }

    $sql = 'SELECT COUNT(*) FROM auth_events WHERE ' . implode(' AND ', $conds);
    $sth = $db->prepare($sql);
    $sth->execute($params);
    return (int)$sth->fetchColumn();
}

/* ----------------- Handle POST (skip if pepper failed above) ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!Auth::validateCsrfToken($token)) {
        if (class_exists('Logger')) Logger::warn('Login attempt with invalid CSRF token', null, ['email' => $email]);
        $err = 'CSRF token neplatný';
    } else {
        $emailNorm = strtolower(trim($email));
        if (!filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
            $err = 'Neplatné prihlasovacie údaje.';
        } else {
            try {
                $stmt = $db->prepare('SELECT id, heslo_hash, heslo_algo, is_active, must_change_password, actor_type FROM pouzivatelia WHERE email = ? LIMIT 1');
                $stmt->execute([$emailNorm]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $userId = $row ? (int)$row['id'] : null;

                $max_failed = (int)($_ENV['LOGIN_MAX_FAILED'] ?? 5);
                $lockout_minutes = (int)($_ENV['LOGIN_LOCKOUT_MINUTES'] ?? 15);

                $ipText = class_exists('Logger') ? Logger::getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? null);

                $failCount = count_recent_failures_from_auth_events($db, $userId, $emailNorm, $ipText, $lockout_minutes);
                if ($failCount >= $max_failed) {
                    if (class_exists('Logger')) Logger::auth('lockout', $userId, ['email' => $emailNorm, 'recent_failures' => $failCount], $ipText);
                    $err = 'Príliš veľa neúspešných pokusov. Skúste to neskôr.';
                } else {
                    if (!$row) {
                        if (class_exists('Logger')) Logger::auth('login_failure', null, ['email' => $emailNorm], $ipText);
                        $err = 'Neplatné prihlasovacie údaje.';
                    } else {
                        if ((int)$row['is_active'] !== 1) {
                            if (class_exists('Logger')) Logger::auth('login_failure', $userId, ['reason' => 'inactive'], $ipText);
                            $err = 'Účet nie je aktívny. Skontrolujte svoj e-mail pre overenie.';
                        } else {
                            $storedHash = $row['heslo_hash'];
                            $usedPreprocess = false;

                            $pwdPre = password_preprocess_for_hash($password, $pepper);

                            $verified = false;
                            if ($storedHash !== null && $storedHash !== '') {
                                if (@password_verify($pwdPre, $storedHash)) {
                                    $verified = true;
                                    $usedPreprocess = true;
                                } elseif (@password_verify($password, $storedHash)) {
                                    $verified = true;
                                    $usedPreprocess = false;
                                }
                            }

                            if (!$verified) {
                                if (class_exists('Logger')) Logger::auth('login_failure', $userId, ['email' => $emailNorm], $ipText);
                                $err = 'Neplatné prihlasovacie údaje.';
                            } else {
                                if (class_exists('Logger')) Logger::auth('login_success', $userId, ['used_pepper' => (bool)$usedPreprocess, 'algo' => $row['heslo_algo'] ?? null], $ipText);

                                $rehashNeeded = password_needs_rehash($storedHash, PASSWORD_ARGON2ID, $ARGON2_OPTS);
                                if (!$usedPreprocess || $rehashNeeded) {
                                    try {
                                        $newPwdForHash = password_preprocess_for_hash($password, $pepper);
                                        $newHash = password_hash($newPwdForHash, PASSWORD_ARGON2ID, $ARGON2_OPTS);
                                        if ($newHash !== false) {
                                            $upd = $db->prepare('UPDATE pouzivatelia SET heslo_hash = ?, heslo_algo = ?, updated_at = NOW() WHERE id = ?');
                                            $upd->execute([$newHash, 'argon2id', $userId]);
                                            if (class_exists('Logger')) Logger::info('Password hash upgraded for user', $userId, ['algo' => 'argon2id']);
                                        }
                                    } catch (\Throwable $e) {
                                        if (class_exists('Logger')) Logger::systemError($e, $userId);
                                    }
                                }

                                session_regenerate_id(true);
                                $_SESSION['user_id'] = $userId;
                                $_SESSION['user_email'] = $emailNorm;
                                $_SESSION['logged_in_at'] = time();

                                try {
                                    $upd2 = $db->prepare('UPDATE pouzivatelia SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?');
                                    $upd2->execute([$ipText, $userId]);
                                } catch (\Throwable $e) {
                                    if (class_exists('Logger')) Logger::warn('update last_login failed', $userId, ['exception' => $e->getMessage()]);
                                }

                                $mustChange = isset($row['must_change_password']) && ((int)$row['must_change_password'] === 1);
                                if ($mustChange) {
                                    $loc = '/eshop/change-password.php';
                                    if ($returnTo && is_safe_return_to($returnTo)) {
                                        $loc .= '?return_to=' . urlencode($returnTo);
                                    }
                                    header('Location: ' . $loc, true, 302);
                                    exit;
                                }

                                // určujeme, či má používateľ admin práva podľa actor_type (načítané pri prvom SELECT)
                                $isAdmin = false;
                                if (isset($row['actor_type']) && $row['actor_type'] === 'admin') {
                                    $isAdmin = true;
                                }

                                if ($returnTo && is_safe_return_to($returnTo)) {
                                    $decoded = rawurldecode($returnTo);
                                    $path = parse_url($decoded, PHP_URL_PATH) ?: '';
                                    $query = parse_url($decoded, PHP_URL_QUERY);
                                    $final = $path . ($query ? '?' . $query : '');

                                    if ($isAdmin) {
                                        if (strpos($path, '/admin') === 0) {
                                            header('Location: ' . $final, true, 302);
                                            exit;
                                        } else {
                                            header('Location: /admin/', true, 302);
                                            exit;
                                        }
                                    } else {
                                        if (strpos($path, '/admin') === 0) {
                                            header('Location: /eshop/', true, 302);
                                            exit;
                                        } else {
                                            header('Location: ' . $final, true, 302);
                                            exit;
                                        }
                                    }
                                }

                                if ($isAdmin) {
                                    header('Location: /admin/', true, 302);
                                    exit;
                                } else {
                                    header('Location: /eshop/', true, 302);
                                    exit;
                                }
                            }
                        }
                    }
                }

            } catch (PDOException $ex) {
                if (class_exists('Logger')) Logger::systemError($ex);
                $err = 'Prihlásenie zlyhalo. Skúste to neskôr.';
            } catch (\Throwable $ex) {
                if (class_exists('Logger')) Logger::systemError($ex);
                $err = 'Prihlásenie zlyhalo. Skúste to neskôr.';
            }
        }
    }
}

$csrfToken = Auth::ensureCsrfToken();
?>
<!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prihlásenie</title>
<link rel="stylesheet" href="assets/css/base.css">
</head><body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
<h1>Prihlásenie</h1>
<?php if ($err) echo '<p class="error">'.e($err).'</p>'; ?>
<form method="post" novalidate>
  <label>Email<input type="email" name="email" required value="<?= e($email) ?>"></label>
  <label>Heslo<input type="password" name="password" required autocomplete="current-password"></label>
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
  <?php if ($returnTo && is_safe_return_to($returnTo)): ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
  <?php endif; ?>
  <button type="submit">Prihlásiť</button>
</form>
<?php if (isset($_GET['registered']) && $_GET['registered'] == 1):
    echo '<p style="color:green;">
        Registrácia bola úspešne dokončená. Skontrolujte si svoj e-mail a aktivujte svoj účet.
    </p>';
endif;

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
    $msg = $verificationMessages[$code] ?? 'Neznámy stav overenia.';
    $color = $code === 0 ? 'green' : 'red';
    echo "<p style='color:$color;'>$msg</p>";
}
?>
<p><a href="register.php">Registrovať</a></p>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>