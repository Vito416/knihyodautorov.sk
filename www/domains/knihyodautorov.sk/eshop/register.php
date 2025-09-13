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
    // Nastavte v prostředí přes APP_URL, např. https://example.com/eshop/
    define('APP_URL', $_ENV['APP_URL'] ?? 'https://example.com/eshop/');
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
                Logger::systemError($e);
            }
        }

        // Fallback: env proměnná PASSWORD_PEPPER v base64
        $b64 = $_ENV['PASSWORD_PEPPER'] ?? getenv('PASSWORD_PEPPER') ?: '';
        if ($b64 !== '') {
            $raw = base64_decode($b64, true);
            if ($raw !== false) return $raw;
            Logger::systemError(new RuntimeException('PASSWORD_PEPPER env invalid base64'));
        }
    } catch (Throwable $e) {
        Logger::systemError($e);
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

/* ====== začátek logiky registrace ====== */

$err = '';
$email = '';

/** kontrola, zda máme DB a config - pokud ne, fail loudly (dev konfigurace) */
if (!isset($db) || !($db instanceof PDO)) {
    error_log('[register] $db not configured or not PDO');
    // pro bezpečnost nezobrazujeme interní chybu uživateli, jen zobrazíme obecnou hlášku
    $err = 'Registrácia momentálne nie je dostupná. Prosím skúste neskôr.';
}

// pepper bude načítaný povinne pri generovaní overovacieho tokenu cez KeyManager (fail-fast)
$pepper = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!Auth::validateCsrfToken($csrf)) {
        Logger::register('register_failure', null, ['reason'=>'csrf_invalid', 'email'=>$email]);
        $err = 'Neplatný formulár (CSRF). Skúste stránku obnoviť a opakovať.';
    } else {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Logger::register('register_failure', null, ['reason'=>'invalid_email', 'email'=>$email]);
            $err = 'Neplatný email.';
        } elseif (mb_strlen($password) < 12) {
            Logger::register('register_failure', null, ['reason'=>'short_password', 'email'=>$email]);
            $err = 'Heslo musí mať aspoň 12 znakov.';
        } else {
            try {
                    // --- kontrola limitu registrací podle IP ---
                    $sth = $db->prepare(
                        'SELECT COUNT(*) AS cnt_short, 
                                SUM(CASE WHEN created_at >= (NOW() - INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS cnt_day 
                        FROM register_events 
                        WHERE ip = ? AND created_at >= (NOW() - INTERVAL 2 HOUR)'
                    );
                    $sth->execute([Logger::getClientIp()]);
                    $row = $sth->fetch(PDO::FETCH_ASSOC);

                    if (($row['cnt_short'] ?? 0) >= 5) {
                        Logger::register('register_failure', null, ['reason'=>'ip_limit_short', 'email'=>$email]);
                        $err = 'Príliš veľa registrácií z tejto IP za posledné 2 hodiny.';
                    } elseif (($row['cnt_day'] ?? 0) >= 20) {
                        Logger::register('register_failure', null, ['reason'=>'ip_limit_day', 'email'=>$email]);
                        $err = 'Príliš veľa registrácií z tejto IP za posledných 24 hodín.';
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
                        Logger::register('register_failure', null, ['reason'=>'email_exists', 'email'=>$email]);
                        $err = 'Účet s týmto e-mailom už existuje.';
                    } else {
                        // insert user as inactive
                        $ins = $db->prepare('INSERT INTO pouzivatelia (email, heslo_hash, heslo_algo, is_active, actor_type, created_at, updated_at)
                                             VALUES (?, ?, ?, 0, ?, NOW(), NOW())');
                        $actorType = 'zakaznik';
                        $ins->execute([$email, $hash, $pwAlgo, $actorType]);
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
                    // ---------------------------
                    // generate verification token & create DB records (HMAC + encrypted payload)
                    // ---------------------------

                    // REQUIRE: load pepper (mandatory) from versioned key file in KEYS_DIR
                    try {
                        if (!class_exists('KeyManager')) {
                            throw new RuntimeException('KeyManager class not available; cannot load PASSWORD_PEPPER.');
                        }
                        // getRawKeyBytes will throw if key file missing/invalid
                        $pepperInfo = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', KEYS_DIR, 'password_pepper', false);
                        $pepperRaw = $pepperInfo['raw']; // binary
                        $pepperVersionStr = $pepperInfo['version'] ?? 'v1';
                        $pepperVersion = (int) filter_var($pepperVersionStr, FILTER_SANITIZE_NUMBER_INT);
                        if ($pepperVersion <= 0) throw new RuntimeException('Invalid pepper version: ' . $pepperVersionStr);
                        } catch (Throwable $e) {
                            if ($db->inTransaction()) $db->rollBack();
                            error_log('[register] Required PASSWORD_PEPPER missing or invalid: ' . $e->getMessage());

                            $_SESSION['error'] = 'Registrácia momentálne nie je dostupná. Prosím skúste neskôr alebo kontaktujte podporu.';
                            header('Location: login.php');
                            exit;
                        }
                    // raw token (hex) used only for immediate send in this request
                    $tokenRaw = bin2hex(random_bytes(32));
                    $expiresAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

                    // HMAC token hash using pepper (store hex) — record mandatory pepper version
                    $tokenHash = hash_hmac('sha256', $tokenRaw, $pepperRaw);

                    // insert email_verifications with explicit key_version
                    $tins = $db->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at, key_version, created_at) VALUES (?, ?, ?, ?, NOW())');
                    $tins->execute([$newUserId, $tokenHash, $expiresAt, $pepperVersion]);

                    // ---------------------------
                    // encrypt the raw token for notifications payload using APP crypto key
                    // ---------------------------
                    try {
                        // ensure libsodium
                        if (method_exists('KeyManager','requireSodium')) KeyManager::requireSodium();

                        $cryptoInfo = KeyManager::getRawKeyBytes('APP_CRYPTO_KEY', KEYS_DIR, 'crypto_key', false);
                        $cryptoKey = $cryptoInfo['raw']; // binary
                        $cryptoVersion = $cryptoInfo['version'] ?? 'v1';

                        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($tokenRaw, '', $nonce, $cryptoKey);
                        $encTokenB64 = base64_encode($nonce . $cipher);
                        } catch (Throwable $e) {
                            if ($db->inTransaction()) $db->rollBack();
                            error_log('[register] APP_CRYPTO_KEY load/encrypt error: ' . $e->getMessage());

                            $_SESSION['error'] = 'Registrácia momentálne nie je dostupná. Prosím skúste neskôr alebo kontaktujte podporu.';
                            header('Location: login.php');
                            exit;
                        }
                    // build subject ensuring UTF-8 validity (fix for garbled subject)
                    $subject = sprintf('%s: potvrďte svoj e-mail', defined('APP_NAME') ? APP_NAME : 'Naša služba');
                    // ensure $subject is valid UTF-8 (convert if needed)
                    if (!mb_check_encoding($subject, 'UTF-8')) {
                        $subject = mb_convert_encoding($subject, 'UTF-8', 'auto');
                    }

                    // prepare notification payload WITHOUT raw token (encrypted instead)
                    $payloadArr = [
                        'to' => $email,
                        'subject' => $subject,
                        'template' => 'verify_email',
                        'vars' => [
                            'encrypted_token' => $encTokenB64,
                            'crypto_key_version' => $cryptoVersion,
                            'expires_at' => $expiresAt,
                            'site' => defined('APP_NAME') ? APP_NAME : null
                        ]
                    ];
                    $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    // insert notification (pending) — worker will decrypt when sending
                    $nins = $db->prepare('INSERT INTO notifications (user_id, channel, template, payload, status, scheduled_at, created_at, retries, max_retries)
                                        VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0, ?)');
                    $maxRetries = 6;
                    $nins->execute([$newUserId, 'email', 'verify_email', $payload, 'pending', $maxRetries]);
                    $notifId = (int)$db->lastInsertId();

                    $db->commit();
                    Logger::register('register_success', $newUserId, ['email'=>$email,]);
                    // Best-effort immediate send via PHPMailer (do not include raw token in DB)
                    // Build verify URL for immediate send (we still have $tokenRaw locally)
                    $base = rtrim(defined('APP_URL') ? APP_URL : 'https://example.com', '/');
                    $verifyUrl = $base . '/verify_email.php?uid=' . $newUserId . '&token=' . $tokenRaw;

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

                            // --- ensure UTF-8 encoding for subject and body ---
                            $mail->CharSet = 'UTF-8';
                            $mail->Encoding = 'base64';

                            $fromEmail = $smtp['from_email'];
                            $fromName  = $smtp['from_name'] ?? (defined('APP_NAME') ? APP_NAME : null);
                            $mail->setFrom($fromEmail, $fromName);

                            // use explicit $email and $subject
                            $mail->addAddress($email);
                            $mail->Subject = $subject;

                            $expiryDisplay = $payloadArr['vars']['expires_at'] ?? $expiresAt;

                            $mail->Body = "Dobrý deň,\n\nKliknite na tento odkaz pre overenie e-mailu:\n\n" .
                                        $verifyUrl . "\n\nOdkaz platí do: " .
                                        $expiryDisplay . "\n\nS pozdravom";
                            $mail->isHTML(false);

                            $mail->send();

                            // mark notification as sent
                            $db->prepare("UPDATE notifications SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?")
                                ->execute([$notifId]);
                        }
                    } catch (\Exception $e) {
                        // best-effort: leave notification pending, record error+schedule retry
                        Logger::systemError($e, $newUserId ?? null);
                        try {
                            $errMsg = substr($e->getMessage(), 0, 1000);
                            $db->prepare("UPDATE notifications SET error = ?, retries = retries + 1, next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
                                ->execute([$errMsg, $notifId]);
                        } catch (\Throwable $inner) {
                            Logger::systemError($inner, $newUserId ?? null);
                        }
                    }
                        header('Location: login.php?registered=1');
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
                    Logger::systemError($ex);
                    $err = 'Registrácia zlyhala. Skúste to prosím neskôr.';
                }
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                Logger::systemError($ex);
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