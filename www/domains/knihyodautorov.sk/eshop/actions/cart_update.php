<?php
declare(strict_types=1);

/**
 * actions/cart_update.php
 *
 * Aktualizuje množstvá položiek v košíku (bulk update).
 * - POST: qty[<book_id>] = <int>
 * - Voliteľné CSRF: CSRF::validate() alebo CSRF::check()
 * - Podporuje AJAX (vracia JSON) alebo klasický form submit (redirect späť).
 *
 * Bezpečnostné body:
 *  - prepared statements
 *  - kontrola stock (ak stock > 0)
 *  - transakcia
 */

try {
    require_once __DIR__ . '/../../bootstrap.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }

    // PDO
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $pdo = Database::getInstance()->getPdo();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        // already available
    } else {
        throw new RuntimeException('Databáza nie je inicializovaná.');
    }

    // detekcia AJAX (Accept alebo X-Requested-With)
    $isAjax = (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0)
        || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    // CSRF
    if (class_exists('CSRF') && method_exists('CSRF','validate')) {
        try { CSRF::validate(); } catch (\Throwable $e) {
            $msg = 'CSRF token neplatný.';
            if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
            $_SESSION['flash_error'] = $msg;
            header('Location: /eshop/cart.php'); exit;
        }
    } elseif (class_exists('CSRF') && method_exists('CSRF','check')) {
        if (!CSRF::check($_POST['csrf'] ?? null)) {
            $msg = 'CSRF token neplatný.';
            if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
            $_SESSION['flash_error'] = $msg;
            header('Location: /eshop/cart.php'); exit;
        }
    }

    // zisti cart id (user alebo cookie)
    $userId = null;
    if (class_exists('Auth') && method_exists('Auth','currentUserId')) $userId = Auth::currentUserId();
    elseif (class_exists('SessionManager') && method_exists('SessionManager','getUserId')) $userId = SessionManager::getUserId();
    if ($userId !== null) { $userId = (int)$userId; if ($userId<=0) $userId=null; }

    $cartId = null;
    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM carts WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid'=>$userId]);
        $cartId = $stmt->fetchColumn() ?: null;
    }
    if (!$cartId) {
        $cookie = $_COOKIE['eshop_cart'] ?? '';
        if ($cookie && preg_match('/^[a-f0-9\-]{36}$/i',$cookie)) {
            $stmt = $pdo->prepare('SELECT id FROM carts WHERE id = :id LIMIT 1');
            $stmt->execute([':id'=>$cookie]);
            $cartId = $stmt->fetchColumn() ?: null;
        }
    }

    if (!$cartId) {
        $msg = 'Košík neexistuje.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
        $_SESSION['flash_error'] = $msg;
        header('Location: /eshop/catalog.php'); exit;
    }

    // parse quantities
    $qtys = $_POST['qty'] ?? [];
    if (!is_array($qtys)) $qtys = [];

    // normalize and validate input: map book_id -> int qty (>=1)
    $updates = [];
    foreach ($qtys as $k => $v) {
        $bid = (int)$k;
        $q = (int)$v;
        if ($bid <= 0) continue;
        if ($q < 1) $q = 1;
        $updates[$bid] = $q;
    }

    if (empty($updates)) {
        $msg = 'Žiadne položky na aktualizáciu.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
        $_SESSION['flash_info'] = $msg;
        header('Location: /eshop/cart.php'); exit;
    }

    // začni transakciu
    $pdo->beginTransaction();
    try {
        // pre bezpečnosť načítame všetky relevantné knihy naraz
        $placeholders = implode(',', array_fill(0, count($updates), '?'));
        $bookIds = array_keys($updates);

        $sel = $pdo->prepare("SELECT id, stock_quantity, is_available FROM books WHERE id IN ($placeholders) FOR UPDATE");
        $sel->execute($bookIds);
        $books = [];
        while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
            $books[(int)$r['id']] = $r;
        }

        // overenie a update
        foreach ($updates as $bookId => $requestedQty) {
            if (!isset($books[$bookId])) {
                // položka už neexistuje -> odstrániť z košíka ak existuje
                $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id');
                $del->execute([':cart_id'=>$cartId, ':book_id'=>$bookId]);
                continue;
            }
            $b = $books[$bookId];
            if (!(int)$b['is_available']) {
                // odstrániť, je nedostupné
                $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id');
                $del->execute([':cart_id'=>$cartId, ':book_id'=>$bookId]);
                continue;
            }
            $stock = (int)$b['stock_quantity'];
            $newQty = $requestedQty;
            if ($stock > 0 && $newQty > $stock) $newQty = $stock;

            // vykonať update
            $upd = $pdo->prepare('UPDATE cart_items SET quantity = :qty WHERE cart_id = :cart_id AND book_id = :book_id');
            $upd->execute([':qty'=>$newQty, ':cart_id'=>$cartId, ':book_id'=>$bookId]);
        }

        // aktualizuj čas v carts
        $u = $pdo->prepare('UPDATE carts SET updated_at = NOW() WHERE id = :id');
        $u->execute([':id'=>$cartId]);

        $pdo->commit();

        $msg = 'Košík aktualizovaný.';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>true,'message'=>$msg]);
            exit;
        }
        $_SESSION['flash_success'] = $msg;
        $referer = $_SERVER['HTTP_REFERER'] ?? '/eshop/cart.php';
        header('Location: ' . $referer);
        exit;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        if (class_exists('Logger') && method_exists('Logger','systemError')) Logger::systemError($e);
        $msg = 'Chyba pri aktualizácii košíka.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
        $_SESSION['flash_error'] = $msg;
        header('Location: /eshop/cart.php'); exit;
    }

} catch (\Throwable $outer) {
    if (class_exists('Logger') && method_exists('Logger','systemError')) Logger::systemError($outer);
    http_response_code(500);
    echo 'Server error';
    exit;
}
