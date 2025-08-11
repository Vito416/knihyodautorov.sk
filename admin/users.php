<?php
// /admin/users.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/csrf.php';

$users = $pdo->query("SELECT id, meno, email, newsletter, last_login, datum_registracie FROM users ORDER BY datum_registracie DESC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1>Užívatelia</h1>

  <div class="adm-actions">
    <a class="adm-btn" href="/admin/user-edit.php">Pridať užívateľa</a>
    <a class="adm-btn" href="/admin/export-users.php">Export CSV</a>
  </div>

  <table class="adm-table">
    <thead>
      <tr>
        <th>ID</th><th>Meno</th><th>Email</th><th>Newsletter</th><th>Posledné prihlásenie</th><th>Registrovaný</th><th>Akcie</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= adm_esc($u['id']) ?></td>
          <td><?= adm_esc($u['meno']) ?></td>
          <td><?= adm_esc($u['email']) ?></td>
          <td><?= !empty($u['newsletter']) ? 'Áno' : 'Nie' ?></td>
          <td><?= adm_esc($u['last_login'] ?? '-') ?></td>
          <td><?= adm_esc($u['datum_registracie'] ?? '-') ?></td>
          <td>
            <a class="adm-btn-small" href="/admin/user-edit.php?id=<?= adm_esc($u['id']) ?>">Upraviť</a>
            <form method="post" action="/admin/user-save.php" style="display:inline" onsubmit="return confirm('Naozaj odstrániť užívateľa?');">
              <input type="hidden" name="csrf" value="<?= adm_esc(csrf_get_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= adm_esc($u['id']) ?>">
              <button class="adm-btn-small adm-btn-danger" type="submit">Vymazať</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php include __DIR__ . '/footer.php'; ?>
