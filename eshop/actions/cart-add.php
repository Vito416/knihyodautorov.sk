<?php
declare(strict_types=1);
/**
 * /eshop/actions/cart-add.php
 * Pridanie položky do košíka.
 * POST: _csrf (kľúč 'cart'), book_id, qty (voliteľné)
 */

require_once __DIR__ . '/../_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', 'Neplatný request (vyžaduje sa POST).');
    redirect('../catalog.php');
}

if (!csrf_verify_token($_POST['_csrf'] ?? null, 'cart')) {
    eshop_log('WARN', 'CSRF chybný pri cart-add');
    flash_set('error', 'Neplatný CSRF token.');
    redirect('../catalog.php');
}

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
if ($bookId <= 0) {
    flash_set('error', 'Neplatné ID knihy.');
    redirect('../catalog.php');
}

$qty = isset($_POST['qty']) ? max(1, (int)$_POST['qty']) : 1;

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v cart-add');
    flash_set('error', 'Interná chyba (DB).');
    redirect('../catalog.php');
}

try {
    $stmt = $pdoLocal->prepare("SELECT id, nazov, pdf_file, is_active FROM books WHERE id = ? LIMIT 1");
    $stmt->execute([$bookId]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b || (int)$b['is_active'] !== 1) {
        flash_set('error', 'Kniha neexistuje alebo nie je aktívna.');
        redirect('../catalog.php');
    }

    // Ak ide o digitálny produkt (pdf_file nie je prázdne), vynútime qty = 1
    if (!empty($b['pdf_file'])) {
        $qty = 1;
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    // Pridáme novú položku — necháme možnosť duplicitných riadkov, checkout-create ich znormalizuje
    $_SESSION['cart'][] = ['book_id' => $bookId, 'qty' => $qty];

    eshop_log('INFO', "Položka pridaná do košíka: book_id={$bookId}, qty={$qty}");
    flash_set('success', 'Kniha bola pridaná do košíka.');
    // redirect späť na detail knihy ak bol referer, inak do košíka
    $back = $_SERVER['HTTP_REFERER'] ?? '../cart.php';
    redirect($back);

} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba v cart-add: ' . $e->getMessage());
    flash_set('error', 'Chyba pri pridávaní do košíka.');
    redirect('../catalog.php');
}