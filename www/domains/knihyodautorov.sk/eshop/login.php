<?php
require __DIR__ . '/inc/bootstrap.php';

$err = '';
$email = '';

/**
 * Safe return_to validator (improved)
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

$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? null;

/* ensure e() exists */
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* ----------------- KeyManager / pepper helpers ----------------- */
/**
 * Vrátí raw pepper bytes (binary) nebo null pokud není k dispozici.
 * Preferuje KeyManager (file), fallback na env PASSWORD_PEPPER (base64).
 */
function loadPepper(): ?string
{
    try {
        if (class_exists('KeyManager')) {
            $keysDir = $_ENV['KEYS_DIR'] ?? (defined('KEYS_DIR') ? KEYS_DIR : null);
            $basename = 'password_pepper';
            try {
                $info = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', $keysDir, $basename, false);
                if (!empty($info['raw'])) {
                    return $info['raw']; // binary string
                }
            } catch (Throwable $e) {
                error_log('[KeyManager] getRawKeyBytes failed: ' . $e->getMessage());
            }
        }

        $b64 = $_ENV['PASSWORD_PEPPER'] ?? getenv('PASSWORD_PEPPER') ?: '';
        if ($b64 !== '') {
            $raw = base64_decode($b64, true);
            if ($raw !== false) return $raw;
            error_log('[login] PASSWORD_PEPPER env is set but invalid base64');
        }
    } catch (Throwable $e) {
        error_log('[login] loadPepper unexpected: ' . $e->getMessage());
    }
    return null;
}

/**
 * Preprocess hesla pred password_hash / password_verify:
 * - ak je pepper (binary), vracia binary HMAC-SHA256(password, pepper)
 * - inak vracia originálny string password
 *
 * POZOR: pri verifikácii musis uplatnit rovnaký krok.
 */
function password_preprocess_for_hash(string $password, ?string $pepper)
{
    if ($pepper === null) return $password;
    return hash_hmac('sha256', $password, $pepper, true); // binary
}

/* ----------------- Argon2 parametre (možno upraviť v env) ----------------- */
$ARGON2_OPTS = [
    'memory_cost' => (int)($_ENV['ARGON_MEMORY_KIB'] ?? (1 << 16)), // 65536 KiB = 64 MiB
    'time_cost'   => (int)($_ENV['ARGON_TIME_COST'] ?? 4),
    'threads'     => (int)($_ENV['ARGON_THREADS'] ?? 2),
];

/* ----------------- Simple login throttle storage -----------------
   Použijeme tabuľku login_attempts (pokud neexistuje, vytvoríme ji).
   Schéma: id PK, user_id NULLABLE, email, ip, success TINYINT, created_at
   Tento jednoduchý log umožní per-user i per-ip počítat neúspešné pokusy.
-------------------------------------------------------------------*/
function ensure_login_attempts_table(PDO $db): void {
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NULL,
  email VARCHAR(255) NOT NULL,
  ip VARBINARY(45) NULL,
  success TINYINT(1) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (email),
  INDEX (user_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $db->exec($sql);
}

/* Insert attempt */
function record_login_attempt(PDO $db, ?int $userId, string $email, ?string $ip, bool $success): void {
    $ipBin = $ip !== null ? $ip : null;
    $sth = $db->prepare('INSERT INTO login_attempts (user_id, email, ip, success) VALUES (?, ?, ?, ?)');
    $sth->execute([$userId, $email, $ipBin, $success ? 1 : 0]);
}

/* Count recent failures for user or ip */
function count_recent_failures(PDO $db, ?int $userId, ?string $email, ?string $ip, int $minutes = 15): int {
    $params = [];
    $conds = [];
    if ($userId !== null) {
        $conds[] = 'user_id = ?';
        $params[] = $userId;
    } else {
        $conds[] = 'email = ?';
        $params[] = $email;
    }
    if ($ip !== null) {
        $conds[] = 'ip = ?';
        $params[] = $ip;
    }
    $conds[] = 'success = 0';
    $conds[] = "created_at >= (NOW() - INTERVAL {$minutes} MINUTE)";
    $sql = 'SELECT COUNT(*) FROM login_attempts WHERE ' . implode(' AND ', $conds);
    $sth = $db->prepare($sql);
    $sth->execute($params);
    return (int)$sth->fetchColumn();
}

/* ----------------- Validate input and process POST ----------------- */

$pepper = loadPepper();
if ($pepper === null) {
    error_log('[login] Pepper not loaded; running without pepper (not recommended)');
}

try {
    // Ensure throttle table exists (no harm if already present)
    ensure_login_attempts_table($db);
} catch (Throwable $e) {
    // pokud to selže, logujeme - funkčnost přihlášení tím ale neni přímo blokována
    error_log('[login] ensure_login_attempts_table failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!Auth::validateCsrfToken($token)) {
        error_log('Login: CSRF token invalid for ' . ($email ?: '[no email]'));
        $err = 'CSRF token neplatný';
    } else {
        $emailNorm = strtolower(trim($email));
        // basic email format check (avoid heavy DB queries for invalid strings)
        if (!filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
            $err = 'Neplatné prihlasovacie údaje.'; // vague message
        } else {
            try {
                // fetch user by email
                $stmt = $db->prepare('SELECT id, heslo_hash, heslo_algo, is_active, must_change_password FROM pouzivatelia WHERE email = ? LIMIT 1');
                $stmt->execute([$emailNorm]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $userId = $row ? (int)$row['id'] : null;

                // per-user / per-ip throttling thresholds (can be env)
                $max_failed = (int)($_ENV['LOGIN_MAX_FAILED'] ?? 5);
                $lockout_minutes = (int)($_ENV['LOGIN_LOCKOUT_MINUTES'] ?? 15);

                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                // count failures (user-specific if exists else by email+ip)
                $failCount = count_recent_failures($db, $userId, $emailNorm, $ip, $lockout_minutes);
                if ($failCount >= $max_failed) {
                    // Do not reveal whether account exists
                    $err = 'Príliš veľa neúspešných pokusov. Skúste to neskôr.';
                    // record this blocked attempt (non-success)
                    record_login_attempt($db, $userId, $emailNorm, $ip, false);
                } else {
                    // if no user found -> simply record failed attempt and generic error
                    if (!$row) {
                        record_login_attempt($db, null, $emailNorm, $ip, false);
                        // don't reveal whether email exists
                        $err = 'Neplatné prihlasovacie údaje.';
                    } else {
                        // lockout / is_active checks
                        if ((int)$row['is_active'] !== 1) {
                            // user exists but not active — generic message
                            record_login_attempt($db, $userId, $emailNorm, $ip, false);
                            $err = 'Účet nie je aktívny. Skontrolujte svoj e-mail pre overenie.';
                        } else {
                            $storedHash = $row['heslo_hash'];
                            $storedAlgo = $row['heslo_algo'] ?? '';

                            // preprocess password with pepper (binary or string)
                            $pwdPre = password_preprocess_for_hash($password, $pepper);

                            $verified = false;
                            $usedPreprocess = false;

                            // First, try verification using preprocessed password (this covers new Argon2id+pepper)
                            if ($storedHash !== null && $storedHash !== '' && password_verify($pwdPre, $storedHash)) {
                                $verified = true;
                                $usedPreprocess = true;
                            } else {
                                // Fallback: some existing accounts may have been hashed without pepper (or using bcrypt)
                                if ($storedHash !== null && $storedHash !== '' && password_verify($password, $storedHash)) {
                                    $verified = true;
                                    $usedPreprocess = false;
                                }
                            }

                            if (!$verified) {
                                // wrong password
                                record_login_attempt($db, $userId, $emailNorm, $ip, false);
                                $err = 'Neplatné prihlasovacie údaje.';
                            } else {
                                // success!
                                record_login_attempt($db, $userId, $emailNorm, $ip, true);

                                // If password was verified with "raw" (no pepper / older algo), upgrade to Argon2id+pepper
                                $rehashNeeded = password_needs_rehash($storedHash, PASSWORD_ARGON2ID, $ARGON2_OPTS);
                                if (!$usedPreprocess || $rehashNeeded) {
                                    try {
                                        $newPwdForHash = password_preprocess_for_hash($password, $pepper);
                                        $newHash = password_hash($newPwdForHash, PASSWORD_ARGON2ID, $ARGON2_OPTS);
                                        if ($newHash !== false) {
                                            $upd = $db->prepare('UPDATE pouzivatelia SET heslo_hash = ?, heslo_algo = ?, updated_at = NOW() WHERE id = ?');
                                            $upd->execute([$newHash, 'argon2id', $userId]);
                                            // NOTE: do not log the new hash
                                        }
                                    } catch (Throwable $e) {
                                        error_log('[login] rehash/update failed: ' . $e->getMessage());
                                        // do not block login on inability to rehash; continue
                                    }
                                }

                                // session handling: regenerate id, set session vars
                                session_regenerate_id(true);
                                $_SESSION['user_id'] = $userId;
                                $_SESSION['user_email'] = $emailNorm;
                                $_SESSION['logged_in_at'] = time();
                                // optionally load roles, prefs etc here

                                // update last_login_at, last_login_ip
                                try {
                                    $upd2 = $db->prepare('UPDATE pouzivatelia SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?');
                                    $upd2->execute([$ip, $userId]);
                                } catch (Throwable $e) {
                                    error_log('[login] update last_login failed: ' . $e->getMessage());
                                }

                                // handle forced password change flag if present
                                $mustChange = isset($row['must_change_password']) && ((int)$row['must_change_password'] === 1);
                                if ($mustChange) {
                                    // redirect to change-password
                                    $loc = '/eshop/change-password.php';
                                    if ($returnTo && is_safe_return_to($returnTo)) {
                                        $loc .= '?return_to=' . urlencode($returnTo);
                                    }
                                    header('Location: ' . $loc, true, 302);
                                    exit;
                                }

                                // determine admin role (simple check)
                                $isAdmin = false;
                                try {
                                    $stmt2 = $db->prepare('SELECT is_admin FROM pouzivatelia WHERE id = ? LIMIT 1');
                                    $stmt2->execute([$userId]);
                                    $r2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                                    if ($r2 && isset($r2['is_admin'])) {
                                        $val = $r2['is_admin'];
                                        if (is_numeric($val)) $isAdmin = ((int)$val === 1);
                                        else $isAdmin = in_array(strtolower((string)$val), ['1','y','yes','true','t'], true);
                                    }
                                } catch (Throwable $e) {
                                    error_log('[login] admin check failed: ' . $e->getMessage());
                                }

                                // redirect respecting return_to safety and admin restrictions
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

                                // default redirect
                                if ($isAdmin) {
                                    header('Location: /admin/', true, 302);
                                    exit;
                                } else {
                                    header('Location: /eshop/', true, 302);
                                    exit;
                                }
                            } // verified
                        } // active
                    } // user exists
                } // throttling ok
            } catch (PDOException $ex) {
                error_log('[login] PDOException: ' . $ex->getMessage());
                $err = 'Prihlásenie zlyhalo. Skúste to neskôr.';
            } catch (Throwable $ex) {
                error_log('[login] Exception: ' . $ex->getMessage());
                $err = 'Prihlásenie zlyhalo. Skúste to neskôr.';
            }
        }
    }
}

// generate CSRF token for form
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
<p><a href="register.php">Registrovať</a></p>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>