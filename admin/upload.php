<?php
// admin/upload.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

// rýchla admin kontrola
if (!isset($_SESSION['admin_id'])) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/upload.php'));
    exit;
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$token = $_SESSION['csrf_token'];

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // over token
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($token, $posted)) {
        $messages[] = ['type'=>'error','text'=>'Neplatný CSRF token.'];
    } else {
        // ktoré pole uploadujeme?
        $type = $_POST['type'] ?? 'image'; // 'image' alebo 'pdf'
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $messages[] = ['type'=>'error','text'=>'Súbor sa nepodarilo nahrať alebo žiadny súbor nebol vybraný.'];
        } else {
            $file = $_FILES['file'];
            $maxSize = 12 * 1024 * 1024; // 12 MB max
            if ($file['size'] > $maxSize) {
                $messages[] = ['type'=>'error','text'=>'Súbor je príliš veľký (max 12 MB).'];
            } else {
                $origName = $file['name'];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($type === 'image') {
                    $allowed = ['jpg','jpeg','png','webp'];
                    $destDir = __DIR__ . '/../books-img/';
                } else {
                    $allowed = ['pdf'];
                    $destDir = __DIR__ . '/../books-pdf/';
                }
                if (!in_array($ext, $allowed)) {
                    $messages[] = ['type'=>'error','text'=>'Nepovolený formát súboru. Očakávané: ' . implode(', ',$allowed)];
                } else {
                    // sanitize base name
                    $base = pathinfo($origName, PATHINFO_FILENAME);
                    $base = preg_replace('/[^A-Za-z0-9_\- ]+/u', '', $base);
                    $base = substr($base, 0, 60);
                    $safe = strtolower(str_replace(' ', '-', $base));
                    $unique = uniqid('', true);
                    $finalName = $safe . '-' . preg_replace('/[^0-9a-z\.]/','',str_replace('.', '', $unique)) . '.' . $ext;
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    $target = $destDir . $finalName;
                    if (move_uploaded_file($file['tmp_name'], $target)) {
                        // nastav správne permisie
                        @chmod($target, 0644);
                        $messages[] = ['type'=>'success','text'=>'Súbor nahraný: ' . $finalName];
                        $messages[] = ['type'=>'info','text'=>'Použi tento názov do poľa (PDF/obálka): <code>' . htmlspecialchars($finalName) . '</code>'];
                    } else {
                        $messages[] = ['type'=>'error','text'=>'Nepodarilo sa presunúť súbor.'];
                    }
                }
            }
        }
    }
}

// include header
if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/admin/css/admin-products.css">

<section class="admin-upload" style="max-width:900px;margin:30px auto">
  <h1>Nahrávanie súborov</h1>

  <?php foreach ($messages as $m): ?>
    <div style="margin:8px 0;padding:10px;border-radius:6px;<?= $m['type']==='error' ? 'background:#ffecec;color:#900' : ($m['type']==='success' ? 'background:#e6ffef;color:#0b7a3a' : 'background:#f2f2f2;color:#333') ?>">
      <?php if (isset($m['text'])) echo $m['text']; ?>
    </div>
  <?php endforeach; ?>

  <div style="display:flex;gap:20px;flex-wrap:wrap">
    <form method="post" enctype="multipart/form-data" style="background:#fff;padding:16px;border-radius:8px;flex:1;min-width:320px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="type" value="image">
      <h3>Nahranie obálky (image)</h3>
      <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp" required>
      <p style="font-size:0.9rem;color:#666">Max 12 MB. Podporované: jpg, jpeg, png, webp.</p>
      <button type="submit" style="background:#c08a2e;color:#fff;padding:8px 12px;border-radius:6px;border:0">Nahrať obrázok</button>
    </form>

    <form method="post" enctype="multipart/form-data" style="background:#fff;padding:16px;border-radius:8px;flex:1;min-width:320px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="type" value="pdf">
      <h3>Nahranie PDF</h3>
      <input type="file" name="file" accept=".pdf" required>
      <p style="font-size:0.9rem;color:#666">Max 12 MB. Podporované: pdf.</p>
      <button type="submit" style="background:#0b6b3a;color:#fff;padding:8px 12px;border-radius:6px;border:0">Nahrať PDF</button>
    </form>
  </div>

  <p style="margin-top:14px;color:#666">Po nahratí skopírujte názov súboru do formulára pri pridávaní produktu v administrácii.</p>
</section>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>
