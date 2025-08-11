<?php
// /admin/user-edit.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/csrf.php';

$id = (int)($_GET['id'] ?? 0);
$user = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT id, meno, email, newsletter, last_login, datum_registracie FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1><?= $id ? 'Upraviť užívateľa' : 'Pridať užívateľa' ?></h1>

  <form method="post" action="/admin/user-save.php" class="adm-form">
    <input type="hidden" name="csrf" value="<?= adm_esc(csrf_get_token()) ?>">
    <input type="hidden" name="id" value="<?= adm_esc($user['id'] ?? '') ?>">
    <label>Meno</label>
    <input name="meno" type="text" value="<?= adm_esc($user['meno'] ?? '') ?>" required>
    <label>Email</label>
    <input name="email" type="email" value="<?= adm_esc($user['email'] ?? '') ?>" required>
    <label>Odobera newsletter</label>
    <select name="newsletter">
      <option value="1" <?= (!empty($user['newsletter']) ? 'selected' : '') ?>>Áno</option>
      <option value="0" <?= (empty($user['newsletter']) ? 'selected' : '') ?>>Nie</option>
    </select>
    <?php if (!empty($user['datum_registracie'])): ?>
      <label>Registrovaný</label>
      <input type="text" value="<?= adm_esc($user['datum_registracie']) ?>" readonly>
    <?php endif; ?>

    <div class="adm-form-actions">
      <button class="adm-btn adm-btn-primary" type="submit">Uložiť</button>
      <a href="/admin/users.php" class="adm-btn">Späť</a>
    </div>
  </form>
</section>
<?php include __DIR__ . '/footer.php'; ?>
