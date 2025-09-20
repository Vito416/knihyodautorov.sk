<?php
declare(strict_types=1);

/**
 * actions/cart_remove.php
 *
 * Odstráni jednu položku z košíka (book_id v POST).
 * - Podporuje AJAX (JSON) a klasické form submit (redirect).
 * - Voliteľné CSRF.
 */

try {
    require_once __DIR__ . '/../../bootstrap.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }

    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $pdo = Database::getInstance()->getPdo();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        // ok
    } else {
        throw new RuntimeException('Databáza nie je inicializovaná.');
    }

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

    $bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
    if ($bookId <= 0) {
        $msg = 'Neplatné ID položky.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
        $_SESSION['flash_error'] = $msg;
        header('Location: /eshop/cart.php'); exit;
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

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id');
        $del->execute([':cart_id'=>$cartId, ':book_id'=>$bookId]);
        $u = $pdo->prepare('UPDATE carts SET updated_at = NOW() WHERE id = :id');
        $u->execute([':id'=>$cartId]);
        $pdo->commit();

        $msg = 'Položka odstránená z košíka.';
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
        $msg = 'Chyba pri odstraňovaní položky.';
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
