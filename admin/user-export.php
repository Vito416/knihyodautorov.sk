<?php
// /admin/user-export.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="users_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output','w'); fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['id','meno','email','telefon','datum_registracie','newsletter']);

$stmt = $pdo->query("SELECT id, meno, email, telefon, datum_registracie, newsletter FROM users ORDER BY datum_registracie DESC");
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, [$r['id'],$r['meno'],$r['email'],$r['telefon'],$r['datum_registracie'],$r['newsletter']]);
}
fclose($out); exit;