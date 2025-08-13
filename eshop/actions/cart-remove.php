<?php
declare(strict_types=1);
/**
 * /eshop/actions/cart-remove.php
 * Odstránenie položky z košíka.
 * POST: _csrf (kľúč 'cart'), book_id
 */

require_once __DIR__ . '/../_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', 'Neplatný request (vyžaduje sa POST).');
    redirect('../cart.php');
}

if (!csrf_verify_token($_POST['_csrf'] ?? null, 'cart')) {
    eshop_log('WARN', 'CSRF chybný pri cart-remove');
    flash_set('error', 'Neplatný CSRF token.');
    redirect('../cart.php');
}

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
if ($bookId <= 0) {
    flash_set('error', 'Neplatné ID knihy.');
    redirect('../cart.php');
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    flash_set('info', 'Košík je prázdny.');
    redirect('../cart.php');
}

try {
    $newCart = [];
    foreach ($_SESSION['cart'] as $row) {
        if (!is_array($row)) continue;
        $bid = isset($row['book_id']) ? (int)$row['book_id'] : 0;
        if ($bid === $bookId) continue; // odstranime
        $newCart[] = $row;
    }
    $_SESSION['cart'] = $newCart;
    eshop_log('INFO', "Položka {$bookId} odstránená z košíka");
    flash_set('success', 'Položka bola odstránená z košíka.');
    redirect('../cart.php');
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba v cart-remove: ' . $e->getMessage());
    flash_set('error', 'Chyba pri odstraňovaní položky z košíka.');
    redirect('../cart.php');
}