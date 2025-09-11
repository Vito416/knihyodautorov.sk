<?php
require __DIR__ . '/inc/bootstrap.php';

if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * --- Konfigurace / fallback definice (pokud nejsou definovány jinde) ---
 * Doporučeno: definujte APP_NAME, APP_URL, KEYS_DIR, případně env PASSWORD_PEPPER v bootstrapu nebo v prostředí.
 */
if (!defined('APP_NAME')) {
    define('APP_NAME', $_ENV['APP_NAME'] ?? 'KnihyOdAutorov');
}
if (!defined('APP_URL')) {
    // Nastavte v prostředí přes APP_URL, např. https://example.com
    define('APP_URL', $_ENV['APP_URL'] ?? 'https://example.com');
}

/**
 * Argon2id parametry — upravte podle výkonu vašeho serveru.
 * - memory_cost: v KiB (65536 = 64 MiB)
 * - time_cost: počet iterací
 * - threads: paralelismus (1..)
 *
 * Doporučení: nastavte tak, aby hashování trvalo ~100-300ms na vašem cílovém serveru.
 */
$ARGON2_OPTS = [
    'memory_cost' => $_ENV['ARGON_MEMORY_KIB'] ?? (1 << 16), // default 65536 KiB = 64 MiB
    'time_cost'   => $_ENV['ARGON_TIME_COST'] ?? 4,
    'threads'     => $_ENV['ARGON_THREADS'] ?? 2,
];

/**
 * Helper: získat pepper z KeyManager (preferované) nebo z env
 * Vrátí buď string s raw-byty pepperu, nebo NULL pokud není k dispozici.
 */
function loadPepper(): ?string
{
    // pokud není KeyManager dostupný, fallback na env
    try {
        if (class_exists('KeyManager')) {
            // Pokusíme se najít KEY_DIR z env nebo konstanty; nastavte to v bootstrapu na produkci.
            $keysDir = $_ENV['KEYS_DIR'] ?? (defined('KEYS_DIR') ? KEYS_DIR : null);
            // Basename pro soubor s pepperem. Doporučené jméno: password_pepper (vytvoří file password_pepper_v1.bin)
            $basename = 'password_pepper';
            try {
                $info = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', $keysDir, $basename, false);
                if (!empty($info['raw'])) {
                    return $info['raw']; // binary string
                }
            } catch (Throwable $e) {
                // nevadí, zkusíme env fallback níže
                error_log('[KeyManager] getRawKeyBytes failed: ' . $e->getMessage());
            }
        }

        // Fallback: env proměnná PASSWORD_PEPPER v base64
        $b64 = $_ENV['PASSWORD_PEPPER'] ?? getenv('PASSWORD_PEPPER') ?: '';
        if ($b64 !== '') {
            $raw = base64_decode($b64, true);
            if ($raw !== false) return $raw;
            error_log('[register] PASSWORD_PEPPER env is set but invalid base64');
        }
    } catch (Throwable $e) {
        error_log('[register] loadPepper unexpected error: ' . $e->getMessage());
    }
    return null;
}

/**
 * Příprava hesla před vlastním password_hash:
 * - pokud existuje pepper (raw bytes), vrátí HMAC-SHA256 (binary) hesla s pepperem
 * - jinak vrátí původní heslo (string)
 *
 * POZOR: Při přihlašování musíte aplikovat stejný krok před password_verify.
 */
function password_preprocess_for_hash(string $password, ?string $pepper)
{
    if ($pepper === null) {
        return $password;
    }
    // vrací binary string (raw) -> password_hash funguje i na binární hodnotě
    return hash_hmac('sha256', $password, $pepper, true);
}

/**
 * Pomocná validace IP
 */
function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip === null) return null;
    // basic validation
    return filter_var($ip, FILTER_VALIDATE_IP) ?: null;
}

/* ====== začátek logiky registrace ====== */

$err = '';
$email = '';

/** kontrola, zda máme DB a config - pokud ne, fail loudly (dev konfigurace) */
if (!isset($db) || !($db instanceof PDO)) {
    error_log('[register] $db not configured or not PDO');
    // pro bezpečnost nezobrazujeme interní chybu uživateli, jen zobrazíme obecnou hlášku
    $err = 'Registrácia momentálne nie je dostupná. Prosím skúste neskôr.';
}

$pepper = null;
try {
    $pepper = loadPepper();
    if ($pepper === null) {
        error_log('[register] No pepper loaded from KeyManager or ENV; proceeding without pepper (NOT recommended in production)');
    } else {
        // Best practice: neukládejte pepper do logu
        error_log('[register] pepper loaded (version ok)');
    }
} catch (Throwable $e) {
    error_log('[register] loadPepper error: ' . $e->getMessage());
    $pepper = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!Auth::validateCsrfToken($csrf)) {
        error_log('Register: CSRF token invalid for ' . ($email ?: '[no email]'));
        $err = 'Neplatný formulár (CSRF). Skúste stránku obnoviť a opakovať.';
    } else {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Neplatný email.';
        } elseif (mb_strlen($password) < 12) {
            $err = 'Heslo musí mať aspoň 12 znakov.';
        } else {
            try {
                // Defensive check for mass registrations from same IP (simple)
                $ip = client_ip();
                if ($ip) {
                    $sth = $db->prepare('SELECT COUNT(*) FROM pouzivatelia WHERE created_at >= (NOW() - INTERVAL 1 HOUR) AND last_login_ip = ?');
                    $sth->execute([$ip]);
                    $cnt = (int)$sth->fetchColumn();
                    if ($cnt >= 20) {
                        $err = 'Príliš veľa registrácií z tejto IP. Skúste to neskôr.';
                    }
                }

                if ($err === '') {
                    // Preprocess password with pepper (HMAC) BEFORE hashing.
                    $pwdForHash = password_preprocess_for_hash($password, $pepper);

                    // create Argon2id hash
                    $hash = password_hash($pwdForHash, PASSWORD_ARGON2ID, $ARGON2_OPTS);
                    if ($hash === false) {
                        throw new RuntimeException('Failed to hash password (password_hash returned false).');
                    }
                    $pwInfo = password_get_info($hash);
                    $pwAlgo = $pwInfo['algoName'] ?? null;

                    $db->beginTransaction();

                    // ensure email does not exist
                    $chk = $db->prepare('SELECT id FROM pouzivatelia WHERE email = ? LIMIT 1');
                    $chk->execute([$email]);
                    if ($chk->fetchColumn()) {
                        $db->rollBack();
                        $err = 'Účet s týmto e-mailom už existuje.';
                    } else {
                        // insert user as inactive
                        $ins = $db->prepare('INSERT INTO pouzivatelia (email, heslo_hash, heslo_algo, is_active, actor_type, last_login_ip, created_at, updated_at)
                                             VALUES (?, ?, ?, 0, ?, ?, NOW(), NOW())');
                        $actorType = 'zakaznik';
                        $ins->execute([$email, $hash, $pwAlgo, $actorType, $ip]);
                        $newUserId = (int)$db->lastInsertId();

                        // ensure role "Zákazník"
                        $roleName = 'Zákazník';
                        // SELECT ... FOR UPDATE requires transaction (we are in transaction)
                        $rsel = $db->prepare('SELECT id FROM roles WHERE nazov = ? LIMIT 1 FOR UPDATE');
                        $rsel->execute([$roleName]);
                        $roleId = $rsel->fetchColumn();
                        if (!$roleId) {
                            $rins = $db->prepare('INSERT INTO roles (nazov, popis, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
                            $rins->execute([$roleName, 'Automaticky vytvorená rola pre nových používateľov']);
                            $roleId = (int)$db->lastInsertId();
                        } else {
                            $roleId = (int)$roleId;
                        }

                        $urChk = $db->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ? LIMIT 1');
                        $urChk->execute([$newUserId, $roleId]);
                        if (!$urChk->fetchColumn()) {
                            $uassign = $db->prepare('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())');
                            $uassign->execute([$newUserId, $roleId]);
                        }

                        // create empty profile if missing
                        $pchk = $db->prepare('SELECT 1 FROM user_profiles WHERE user_id = ? LIMIT 1');
                        $pchk->execute([$newUserId]);
                        if (!$pchk->fetchColumn()) {
                            $pcreate = $db->prepare('INSERT INTO user_profiles (user_id, full_name, updated_at) VALUES (?, ?, NOW())');
                            $pcreate->execute([$newUserId, '']);
                        }

                        // generate verification token (raw token sent via email)
                        $tokenRaw = bin2hex(random_bytes(32));

                        // Use HMAC-SHA256 with pepper for token hash if pepper exists; else use plain sha256
                        if ($pepper !== null) {
                            // pepper is binary; use hash_hmac -> hex string
                            $tokenHash = hash_hmac('sha256', $tokenRaw, $pepper);
                        } else {
                            $tokenHash = hash('sha256', $tokenRaw);
                        }

                        $expiresAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

                        $tins = $db->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at, key_version, created_at) VALUES (?, ?, ?, ?, NOW())');
                        // if we have version info from KeyManager we could store it; for now store 0 or v1
                        $keyVersion = ($pepper !== null && isset($info['version'])) ? $info['version'] : 'v0';
                        $tins->execute([$newUserId, $tokenHash, $expiresAt, $keyVersion]);

                        // prepare notification payload
                        $base = rtrim(defined('APP_URL') ? APP_URL : 'https://example.com', '/');
                        $verifyUrl = $base . '/verify_email.php?uid=' . $newUserId . '&token=' . $tokenRaw;
                        $payloadArr = [
                            'to' => $email,
                            'subject' => sprintf('%s: potvrďte svoj e-mail', defined('APP_NAME') ? APP_NAME : 'Naša služba'),
                            'template' => 'verify_email',
                            'vars' => [
                                'verify_url' => $verifyUrl,
                                'expires_at' => $expiresAt,
                                'site' => defined('APP_NAME') ? APP_NAME : null
                            ]
                        ];
                        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        // insert notification (pending)
                        $nins = $db->prepare('INSERT INTO notifications (user_id, channel, template, payload, status, scheduled_at, created_at, retries, max_retries)
                                              VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0, ?)');
                        $maxRetries = 6;
                        $nins->execute([$newUserId, 'email', 'verify_email', $payload, 'pending', $maxRetries]);
                        $notifId = (int)$db->lastInsertId();

                        $db->commit();

                        // Best-effort immediate send: only via PHPMailer (no fallback to mail())
                        try {
                            if (
                                class_exists('\PHPMailer\PHPMailer\PHPMailer')
                                && !empty($config['smtp']['host'])
                                && !empty($config['smtp']['from_email'])
                            ) {
                                $smtp = $config['smtp'];
                                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                                $mail->isSMTP();
                                $mail->Host = $smtp['host'];
                                $mail->SMTPAuth = !empty($smtp['user']);
                                if (!empty($smtp['user'])) {
                                    $mail->Username = $smtp['user'];
                                    $mail->Password = $smtp['pass'];
                                }
                                if (!empty($smtp['port'])) $mail->Port = (int)$smtp['port'];
                                if (!empty($smtp['secure'])) $mail->SMTPSecure = $smtp['secure'];
                                $mail->Timeout = isset($config['smtp']['timeout']) ? (int)$config['smtp']['timeout'] : 10;
                                $mail->SMTPAutoTLS = true;

                                $fromEmail = $smtp['from_email'];
                                $fromName  = $smtp['from_name'] ?? (defined('APP_NAME') ? APP_NAME : null);
                                $mail->setFrom($fromEmail, $fromName);
                                $mail->addAddress($payloadArr['to']);
                                $mail->Subject = $payloadArr['subject'];
                                $mail->Body = "Dobrý deň,\n\nKliknite na tento odkaz pre overenie e-mailu:\n\n" .
                                            $payloadArr['vars']['verify_url'] . "\n\nOdkaz platí do: " .
                                            $payloadArr['vars']['expires_at'] . "\n\nS pozdravom";
                                $mail->isHTML(false);

                                $mail->send();

                                // mark notification as sent
                                $db->prepare("UPDATE notifications SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?")
                                    ->execute([$notifId]);
                            }
                        } catch (\Exception $e) {
                            error_log('[register immediate_send] ' . $e->getMessage());
                        }

                        header('Location: register_success.php');
                        exit;
                    }
                }
            } catch (PDOException $ex) {
                if ($db->inTransaction()) $db->rollBack();
                $sqlState = $ex->getCode();
                $mysqlErrNo = isset($ex->errorInfo[1]) ? (int)$ex->errorInfo[1] : null;
                if ($sqlState === '23000' || $mysqlErrNo === 1062) {
                    $err = 'Účet s týmto e-mailom už existuje.';
                } else {
                    error_log('[register] PDOException: ' . $ex->getMessage());
                    $err = 'Registrácia zlyhala. Skúste to prosím neskôr.';
                }
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[register] Exception: ' . $ex->getMessage());
                $err = 'Registrácia sa nepodarila. Skúste to prosím neskôr.';
            }
        }
    }
}

$csrfToken = Auth::ensureCsrfToken();
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <title>Registrácia</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/css/base.css">
</head>
<body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
  <h1>Registrácia</h1>
  <?php if ($err): ?>
    <p class="error"><?= e($err) ?></p>
  <?php endif; ?>
  <form method="post" novalidate>
    <label>Email
      <input type="email" name="email" required value="<?= e($email) ?>" autocomplete="email">
    </label>
    <label>Heslo (min. 12 znakov)
      <input type="password" name="password" required minlength="12" autocomplete="new-password">
    </label>
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <button type="submit">Registrovať</button>
  </form>
  <p><a href="login.php">Prihlásiť sa</a></p>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body>
</html>