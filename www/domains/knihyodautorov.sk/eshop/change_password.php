<?php
// change_password.php - used for forced password change or standard password update
require __DIR__ . '/inc/bootstrap.php';
Auth::requireLogin();
$user_id = $_SESSION['user_id'];
$err = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { $err = 'CSRF token neplatný'; }
    else {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($new !== $confirm) $err = 'Heslá sa nezhodujú';
        elseif (strlen($new) < 8) $err = 'Heslo musí mať aspoň 8 znakov';
        else {
            // verify old
            $stmt = $db->prepare('SELECT heslo_hash FROM pouzivatelia WHERE id = ? LIMIT 1'); $stmt->execute([$user_id]); $hash = $stmt->fetchColumn();
            if (!password_verify($old, $hash)) $err = 'Nesprávne pôvodné heslo';
            else {
                $newh = password_hash($new, PASSWORD_DEFAULT);
                $db->prepare('UPDATE pouzivatelia SET heslo_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?')->execute([$newh, $user_id]);
                $ok = 'Heslo úspešne zmenené';
            }
        }
    }
}
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Zmena hesla</title></head><body>
<?php include __DIR__ . '/templates/layout-header.php'; ?>
<main>
  <h1>Zmeniť heslo</h1>
  <?php if ($err) echo '<p class="error">'.e($err).'</p>'; ?>
  <?php if ($ok) echo '<p class="success">'.e($ok).'</p>'; ?>
  <form method="post">
    <label>Staré heslo<input type="password" name="old_password" required></label><br>
    <label>Nové heslo<input type="password" name="new_password" required></label><br>
    <label>Potvrdiť nové heslo<input type="password" name="confirm_password" required></label><br>
    <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
    <button type="submit">Zmeniť heslo</button>
  </form>
</main>
</body></html>