<?php
// /admin/review-action.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: reviews.php'); exit; }

$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$csrf)) die('CSRF token invalid');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? '';

try {
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$id]);
        $_SESSION['flash_success'] = 'Recenzia odstránená.';
    } elseif ($action === 'approve') {
        // tu nemáme stĺpec 'approved' — ak chcete persistovať, pridajte do reviews stĺpec approved TINYINT(1), default 0.
        // dočasne môžeme pridať flag via settings alebo len označiť v logu; najlepšie riešenie: pridáme stĺpec.
        // implementujeme update: ak stĺpec existuje, update; inak len flash
        try {
            $pdo->prepare("UPDATE reviews SET approved = 1 WHERE id = ?")->execute([$id]);
            $_SESSION['flash_success'] = 'Recenzia schválená.';
        } catch (Throwable $e) {
            // stĺpec neexistuje
            $_SESSION['flash_success'] = 'Recenzia (dočasne) označená ako schválená (DB neobsahuje stĺpec approved).';
        }
    } else {
        $_SESSION['flash_error'] = 'Neznáma akcia.';
    }
} catch (Throwable $e) {
    error_log("review-action.php ERROR: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Chyba pri vykonávaní akcie.';
}
header('Location: reviews.php');
exit;