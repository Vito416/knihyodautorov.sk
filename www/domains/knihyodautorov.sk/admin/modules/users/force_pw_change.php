<?php
// admin/modules/users/force_pw_change.php
require __DIR__ . '/../../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: list.php'); exit; }
if (!CSRF::validate($_POST['csrf_token'] ?? '')) { die('CSRF'); }
$uid = (int)($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? 'set';
if (!$uid) { header('Location: list.php'); exit; }
if ($action === 'set') {
    $db->prepare('UPDATE pouzivatelia SET must_change_password=1 WHERE id=?')->execute([$uid]);
} else {
    $db->prepare('UPDATE pouzivatelia SET must_change_password=0 WHERE id=?')->execute([$uid]);
}
header('Location: list.php'); exit;