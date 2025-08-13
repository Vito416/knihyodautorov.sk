<?php
declare(strict_types=1);
/**
 * /eshop/account/verify.php
 * Ověření emailu přes token (např. link: verify.php?token=xxx&uid=NN)
 */
require_once __DIR__ . '/../_init.php';

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'verify.php: PDO nie je dostupné.');
    flash_set('error', 'Interná chyba (DB).');
    redirect('/eshop/index.php');
}

// tidy input
$token = trim((string)($_GET['token'] ?? ''));
$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;

if ($token === '' || $uid <= 0) {
    flash_set('error', 'Neplatný verifikačný odkaz.');
    redirect('/eshop/account/login.php');
}

try {
    $stmt = $pdoLocal->prepare("SELECT id, verify_token FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        flash_set('error', 'Používateľ nenájdený.');
        redirect('/eshop/account/register.php');
    }

    $stored = (string)($row['verify_token'] ?? '');
    if ($stored === '') {
        flash_set('info', 'Email už bol overený alebo token nie je nastavený.');
        redirect('/eshop/account/login.php');
    }

    // bezpečná kontrola tokenu (hash_equals)
    if (!hash_equals($stored, $token)) {
        flash_set('error', 'Neplatný verifikačný token.');
        redirect('/eshop/account/register.php');
    }

    // úprava DB: nastavíme email_verified = 1 a vymažeme verify_token
    $upd = $pdoLocal->prepare("UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?");
    $upd->execute([$uid]);

    flash_set('success', 'Email bol úspešne overený. Môžete sa prihlásiť.');
    redirect('/eshop/account/login.php');

} catch (Throwable $e) {
    eshop_log('ERROR', 'verify.php: chyba pri overovaní: ' . $e->getMessage());
    flash_set('error', 'Chyba pri overovaní. Kontaktujte podporu.');
    redirect('/eshop/account/login.php');
}