<?php
// /admin/settings.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$csrf)) die('CSRF token invalid');

  $pairs = [
    'site_name' => trim((string)($_POST['site_name'] ?? 'Knihy od autorov')),
    'admin_email' => trim((string)($_POST['admin_email'] ?? '')),
    'support_babybox' => isset($_POST['support_babybox']) ? '1':'0',
    // SMTP
    'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
    'smtp_port' => trim((string)($_POST['smtp_port'] ?? '25')),
    'smtp_user' => trim((string)($_POST['smtp_user'] ?? '')),
    'smtp_pass' => trim((string)($_POST['smtp_pass'] ?? '')),
    'smtp_from' => trim((string)($_POST['smtp_from'] ?? '')),
  ];

  $up = $pdo->prepare("REPLACE INTO settings (k,v) VALUES (?,?)");
  foreach ($pairs as $k=>$v) {
    $up->execute([$k,$v]);
  }
  $success = 'Nastavenia uložené.';
}

$settingsStmt = $pdo->query("SELECT k,v FROM settings");
$settings = [];
while($r = $settingsStmt->fetch(PDO::FETCH_ASSOC)) $settings[$r['k']] = $r['v'] ?? '';

?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Nastavenia</title>
<link rel="stylesheet" href="/admin/css/admin.css">
<script src="/admin/js/admin.js" defer></script>
</head>
<body>
<main class="admin-shell">
  <header class="admin-top"><h1>Nastavenia</h1></header>

  <?php if ($success): ?><div class="notice"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <form method="post" class="panel">
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
    <div><label>Názov stránky</label><input type="text" name="site_name" value="<?php echo htmlspecialchars($settings['site_name'] ?? 'Knihy od Autorov'); ?>"></div>
    <div style="margin-top:10px;"><label>Admin e-mail (pre testy)</label><input type="email" id="admin-email" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email'] ?? ''); ?>"></div>
    <div style="margin-top:10px;"><label><input type="checkbox" name="support_babybox" <?php if(!empty($settings['support_babybox']) && $settings['support_babybox']=='1') echo 'checked'; ?>> Časť výťažku pre babyboxy</label></div>

    <hr style="margin:16px 0;border:none;border-top:1px solid rgba(255,255,255,0.03)">

    <h3>SMTP</h3>
    <div style="display:grid;grid-template-columns:1fr 120px;gap:10px;">
      <div><label>SMTP host</label><input type="text" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>"></div>
      <div><label>Port</label><input type="text" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '25'); ?>"></div>
    </div>
    <div style="margin-top:10px;display:flex;gap:10px;">
      <div style="flex:1"><label>SMTP user</label><input type="text" name="smtp_user" value="<?php echo htmlspecialchars($settings['smtp_user'] ?? ''); ?>"></div>
      <div style="flex:1"><label>SMTP pass</label><input type="text" name="smtp_pass" value="<?php echo htmlspecialchars($settings['smtp_pass'] ?? ''); ?>"></div>
    </div>
    <div style="margin-top:10px;"><label>From e-mail (From)</label><input type="text" name="smtp_from" value="<?php echo htmlspecialchars($settings['smtp_from'] ?? ''); ?>"></div>

    <div style="margin-top:12px;">
      <button class="btn" type="submit">Uložiť nastavenia</button>
      <button id="smtp-test-btn" class="btn ghost" type="button">Odoslať testovací e-mail</button>
      <input id="smtp-test-email" style="margin-left:8px;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.03);" placeholder="test@priklad.sk" value="<?php echo htmlspecialchars($settings['admin_email'] ?? ''); ?>">
    </div>
  </form>
</main>
</body>
</html>