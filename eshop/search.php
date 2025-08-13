<?php
// /eshop/search.php
require __DIR__ . '/_init.php';
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') { header('Content-Type: application/json'); echo json_encode([]); exit; }

$useFT = false;
try {
    $res = $pdo->query("SHOW INDEX FROM books WHERE Key_name = 'ft_title_popis'")->fetchAll();
    if (!empty($res)) $useFT = true;
} catch (Throwable $e){}

if ($useFT) {
    $stmt = $pdo->prepare("SELECT id, nazov, slug FROM books WHERE MATCH(nazov,popis) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 10");
    $stmt->execute([$q]);
} else {
    $like = "%$q%";
    $stmt = $pdo->prepare("SELECT id, nazov, slug FROM books WHERE nazov LIKE ? OR popis LIKE ? LIMIT 10");
    $stmt->execute([$like,$like]);
}
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($res);
exit;