<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

/**
 * orders.php
 *
 * - vyžaduje přihlášení
 * - vypíše seznam objednávek uživatele
 * - data: číslo objednávky, stav, datum, celková cena
 */

$db = Database::getInstance();
$userId = SessionManager::validateSession($db);

if ($userId === null) {
    header('Location: login.php');
    exit;
}

try {
    $stmt = $db->prepare("SELECT id, created_at, status, total_price, currency
                          FROM objednavky
                          WHERE user_id = :uid
                          ORDER BY created_at DESC");
    $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo Templates::render('pages/orders.php', [
        'orders' => $orders,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) {
        try { Logger::systemError($e, $userId); } catch (\Throwable $_) {}
    }

    echo Templates::render('pages/orders.php', [
        'orders' => [],
        'error' => 'Nepodařilo se načíst objednávky.',
    ]);
}