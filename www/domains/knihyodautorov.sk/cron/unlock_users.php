<?php
require __DIR__ . '/inc/bootstrap.php';

// --- jednoduché zabezpečení přes GET token ---
$secret = 'ZDE_DÁŠ_NĚJAKÝ_SILNÝ_SECRET_TOKEN';
$token = $_GET['token'] ?? '';

if ($token !== $secret) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

try {
    $now = new DateTime('now', new DateTimeZone('UTC'));

    // Odblokujeme všechny uživatele, kde is_locked=1 a locked_until <= nyní
    $stmt = $db->prepare('UPDATE pouzivatelia 
                          SET is_locked = 0, failed_logins = 0, locked_until = NULL
                          WHERE is_locked = 1 AND locked_until <= ?');
    $stmt->execute([$now->format('Y-m-d H:i:s')]);

    echo 'Odblokováno uživatelů: ' . $stmt->rowCount();

} catch (Throwable $e) {
    error_log('[unlock_users] ' . $e->getMessage());
    http_response_code(500);
    echo 'Chyba serveru';
}