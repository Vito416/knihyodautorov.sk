<?php
// /admin/settings.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_token($_POST['csrf'] ?? '')) { die('Neplatný CSRF token'); }
    // uložiť vybrané nastavenia do tabulky settings (k,v)
    $pairs = [
      'site_name' => $_POST['site_name'] ?? '',
      'sender_email' => $_POST['sender_email'] ?? '',
      'smtp_host' => $_POST['smtp_host'] ?? '',
      'smtp_user' => $_POST['smtp_user'] ?? '',
      'smtp_port' => $_POST['smtp_port'] ?? '',
      'company_name' => $_POST['company_name'] ?? '',
      'company_ico' => $_POST['company_ico'] ?? '',
      'support_babybox' => isset($_POST['support_babybox']) ? '1' : '0'
    ];
    $stmt = $pdo->prepare("REPLACE INTO settings (k,v) VALUES (?, ?)");
    foreach ($pairs as $k => $v) $stmt->execute([$k, $v]);
    header('Location: settings.php?saved=1'); exit;
}

$settingsRaw = $pdo->query("SELECT k,v FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1>Nastavenia</h1>
  <?php if (isset($_GET['saved'])): ?><div class="adm-alert adm-alert-success">Nastavenia uložené.</div><?php endif; ?>

  <form method="post" class="adm-form">
    <input type="hidden" name="csrf" value="<?= adm_esc(csrf_get_token()) ?>">
    <label>Názov stránky</label>
    <input name="site_name" type="text" value="<?= adm_esc($settingsRaw['site_name'] ?? '') ?>">
    <label>Odosielateľ e-mailov</label>
    <input name="sender_email" type="email" value="<?= adm_esc($settingsRaw['sender_email'] ?? '') ?>">

    <h3>SMTP</h3>
    <label>Host</label>
    <input name="smtp_host" type="text" value="<?= adm_esc($settingsRaw['smtp_host'] ?? '') ?>">
    <label>Užívateľ</label>
    <input name="smtp_user" type="text" value="<?= adm_esc($settingsRaw['smtp_user'] ?? '') ?>">
    <label>Port</label>
    <input name="smtp_port" type="text" value="<?= adm_esc($settingsRaw['smtp_port'] ?? '') ?>">

    <h3>Firma (pre faktúry)</h3>
    <label>Názov firmy</label>
    <input name="company_name" type="text" value="<?= adm_esc($settingsRaw['company_name'] ?? '') ?>">
    <label>IČO</label>
    <input name="company_ico" type="text" value="<?= adm_esc($settingsRaw['company_ico'] ?? '') ?>">

    <label><input type="checkbox" name="support_babybox" value="1" <?= (!empty($settingsRaw['support_babybox']) ? 'checked' : '') ?>> Podporovať babyboxy (časť výťažku)</label>

    <div class="adm-form-actions">
      <button class="adm-btn adm-btn-primary" type="submit">Uložiť</button>
    </div>
  </form>
</section>
<?php include __DIR__ . '/footer.php'; ?>
