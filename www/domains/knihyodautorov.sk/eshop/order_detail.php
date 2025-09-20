<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

/**
 * order_detail.php
 *
 * - vyžaduje přihlášení
 * - zobrazí detail objednávky včetně položek
 * - kontroluje, že objednávka patří uživateli
 * - pokud je zaplaceno, zobrazí linky na stažení (download.php)
 */

$db = Database::getInstance();
$userId = SessionManager::validateSession($db);

if ($userId === null) {
    header('Location: login.php');
    exit;
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    echo Templates::render('pages/order_detail.php', ['error' => 'Neplatné ID objednávky.']);
    exit;
}

try {
    // objednávka
    $stmt = $db->prepare("SELECT id, created_at, status, total_price, currency
                          FROM objednavky
                          WHERE id = :id AND user_id = :uid
                          LIMIT 1");
    $stmt->bindValue(':id', $orderId, \PDO::PARAM_INT);
    $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
    $stmt->execute();
    $order = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$order) {
        echo Templates::render('pages/order_detail.php', ['error' => 'Objednávka nenalezena.']);
        exit;
    }

    // položky
    $stmt = $db->prepare("SELECT oi.id, oi.book_id, oi.quantity, oi.unit_price, oi.total_price, b.title, b.format
                          FROM objednavky_items oi
                          JOIN knihy b ON b.id = oi.book_id
                          WHERE oi.order_id = :oid");
    $stmt->bindValue(':oid', $orderId, \PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // přidat download linky pokud zaplaceno
    if ($order['status'] === 'paid') {
        foreach ($items as &$item) {
            if ($item['format'] === 'pdf') {
                $item['download_url'] = "download.php?order_id={$orderId}&book_id={$item['book_id']}";
            }
        }
        unset($item);
    }

    echo Templates::render('pages/order_detail.php', [
        'order' => $order,
        'items' => $items,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) {
        try { Logger::systemError($e, $userId); } catch (\Throwable $_) {}
    }
    echo Templates::render('pages/order_detail.php', [
        'error' => 'Nepodařilo se načíst detail objednávky.',
    ]);
}
