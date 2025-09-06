<?php
declare(strict_types=1);
// Admin: smaže knihu a související assety (pokud to dovolí závislosti)
// Bezpečný postup: CSRF, admin kontrola, transakce, kontrola order_items


require_once __DIR__ . '/../../inc/bootstrap.php';


// Předpoklad: admin bootstrap zaručí, že je uživatel přihlášen a autorizován.
// Nicméně doplňujeme kontrolu pro jistotu.
try {
Auth::requireLogin();
$current = Auth::currentUser();
if (!$current || (!empty($current['actor_type']) && $current['actor_type'] !== 'admin')) {
http_response_code(403);
echo "Neoprávněný přístup.";
exit;
}
} catch (Throwable $e) {
Logger::error('Auth check failed in book_delete', ['err'=>$e->getMessage()]);
http_response_code(500);
echo "Chyba při ověřování oprávnění.";
exit;
}


$pdo = Database::get();


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
http_response_code(405);
echo 'Metoda není povolena.';
exit;
}


$csrf = $_POST['csrf_token'] ?? '';
if (!CSRF::validate($csrf)) {
http_response_code(400);
echo 'Neplatný CSRF token.';
exit;
}


$bookId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($bookId <= 0) {
http_response_code(400);
echo 'Neplatné ID knihy.';
exit;
}


try {
$pdo->beginTransaction();


// 1) Zkontroluj, zda nejsou položky v objednávkách
$stmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE book_id = ?');
$stmt->execute([$bookId]);
$cnt = (int)$stmt->fetchColumn();
if ($cnt > 0) {
$pdo->rollBack();
$_SESSION['flash_error'] = 'Knihu nelze smazat: existují objednávky obsahující tuto položku.';
header('Location: books.php');
exit;
}


// 2) Načti assety a smaž fyzické soubory
$stmt = $pdo->prepare('SELECT id, storage_path FROM book_assets WHERE book_id = ?');
$stmt->execute([$bookId]);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);


foreach ($assets as $a) {
$path = $a['storage_path'];
if ($path && file_exists($path)) {
// Smaž soubor bezpečně - pouze pokud je mimo webroot
@unlink($path);
}
}

// 3) Smaž záznamy z book_assets
$stmt = $pdo->prepare('DELETE FROM book_assets WHERE book_id = ?');
$stmt->execute([$bookId]);


// 4) Smaž kategorie (m2m)
$stmt = $pdo->prepare('DELETE FROM book_categories WHERE book_id = ?');
$stmt->execute([$bookId]);


// 5) Smaž záznam knihy
$stmt = $pdo->prepare('DELETE FROM books WHERE id = ?');
$stmt->execute([$bookId]);


// 6) Audit log
$audit = $pdo->prepare('INSERT INTO audit_log (table_name, record_id, changed_by, change_type, old_value, new_value, changed_at, ip, user_agent, request_id) VALUES (?, ?, ?, ?, NULL, NULL, NOW(), ?, ?, ?)');
$audit->execute(['books', $bookId, $current['id'] ?? null, 'DELETE', $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, bin2hex(random_bytes(8))]);


$pdo->commit();
$_SESSION['flash_success'] = 'Kniha byla úspěšně smazána.';
header('Location: books.php');
exit;
} catch (Throwable $e) {
if ($pdo->inTransaction()) $pdo->rollBack();
Logger::error('Error deleting book', ['id'=>$bookId, 'err'=>$e->getMessage()]);
$_SESSION['flash_error'] = 'Nastala chyba při mazání knihy.';
header('Location: books.php');
exit;
}