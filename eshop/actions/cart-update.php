<?php
declare(strict_types=1);
/**
 * /eshop/actions/cart-update.php
 * Aktualizuje množstvá v košíku.
 * POST: _csrf (kľúč 'cart'), items => array( book_id => qty, ... )
 * Pre digitálne produkty (PDF) vynucujeme qty = 1.
 */

require_once __DIR__ . '/../_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', 'Neplatný request (vyžaduje sa POST).');
    redirect('../cart.php');
}

if (!csrf_verify_token($_POST['_csrf'] ?? null, 'cart')) {
    eshop_log('WARN', 'CSRF chybný pri cart-update');
    flash_set('error', 'Neplatný CSRF token.');
    redirect('../cart.php');
}

$itemsInput = $_POST['items'] ?? [];
if (!is_array($itemsInput)) {
    flash_set('error', 'Neplatné dáta pre aktualizáciu košíka.');
    redirect('../cart.php');
}

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v cart-update');
    flash_set('error', 'Interná chyba (DB).');
    redirect('../cart.php');
}

try {
    // Zvalidujeme book_id a pripravia sa mapy
    $bookIds = array_map('intval', array_keys($itemsInput));
    $bookIds = array_filter($bookIds, function($v){ return $v > 0; });
    if (empty($bookIds)) {
        // vymažeme košík
        $_SESSION['cart'] = [];
        flash_set('success', 'Košík bol aktualizovaný.');
        redirect('../cart.php');
    }

    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
    $stmt = $pdoLocal->prepare("SELECT id, pdf_file, is_active FROM books WHERE id IN ($placeholders)");
    $stmt->execute($bookIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $booksMap = [];
    foreach ($rows as $r) {
        $booksMap[(int)$r['id']] = $r;
    }

    $newCart = [];
    foreach ($itemsInput as $bidStr => $q) {
        $bid = (int)$bidStr;
        if ($bid <= 0) continue;
        if (!isset($booksMap[$bid]) || (int)$booksMap[$bid]['is_active'] !== 1) {
            continue; // ignorujeme neplatné položky
        }
        $qty = max(0, (int)$q);
        if ($qty === 0) continue; // ignorujeme 0 => neukladáme
        // ak je pdf, vymusi 1
        if (!empty($booksMap[$bid]['pdf_file'])) $qty = 1;
        // push (kompatibilne s checkout-create normalization)
        $newCart[] = ['book_id' => $bid, 'qty' => $qty];
    }

    $_SESSION['cart'] = $newCart;
    eshop_log('INFO', 'Košík aktualizovaný cez cart-update.');
    flash_set('success', 'Košík bol aktualizovaný.');
    redirect('../cart.php');

} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba v cart-update: ' . $e->getMessage());
    flash_set('error', 'Chyba pri aktualizácii košíka.');
    redirect('../cart.php');
}