<?php
require __DIR__ . '/../../inc/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
$book_id = (int)($_GET['book_id'] ?? 0);
if (!$id) { header('Location: assets.php?book_id='.$book_id); exit; }
$stmt = $db->prepare('SELECT storage_path FROM book_assets WHERE id = ? LIMIT 1'); $stmt->execute([$id]); $p = $stmt->fetchColumn();
if ($p && file_exists($p)) unlink($p);
$db->prepare('DELETE FROM book_assets WHERE id = ?')->execute([$id]);
header('Location: assets.php?book_id='.$book_id); exit;