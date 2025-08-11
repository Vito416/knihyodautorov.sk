<?php
// /admin/author-save.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: authors.php'); exit; }

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    die('CSRF token invalid.');
}

// hodnoty
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$meno = trim((string)($_POST['meno'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$bio = trim((string)($_POST['bio'] ?? ''));

if ($meno === '') {
    $_SESSION['flash_error'] = 'Meno autora je povinné.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'authors.php'));
    exit;
}

// slug generation
if ($slug === '') {
    $slug = mb_strtolower(trim(preg_replace('/[^a-z0-9\-]+/i', '-', str_replace(' ', '-', iconv('UTF-8','ASCII//TRANSLIT',$meno)))), 'UTF-8');
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
}

// handle photo upload
$uploadDir = __DIR__ . '/../assets/authors/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
$fotoName = null;

if (!empty($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['foto'];
    $allowed = ['image/jpeg','image/png'];
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, $allowed)) {
        $_SESSION['flash_error'] = 'Nepodporovaný formát obrázka.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'authors.php'));
        exit;
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        $_SESSION['flash_error'] = 'Obrázok je príliš veľký (max 3MB).';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'authors.php'));
        exit;
    }
    $ext = $mime === 'image/png' ? '.png' : '.jpg';
    $fotoName = 'author_' . bin2hex(random_bytes(8)) . $ext;
    move_uploaded_file($f['tmp_name'], $uploadDir . $fotoName);
}

// remove old photo?
$removePhoto = !empty($_POST['remove_photo']) && $_POST['remove_photo'] == '1';

try {
    if ($id > 0) {
        // fetch old foto
        $old = $pdo->prepare("SELECT foto FROM authors WHERE id = ? LIMIT 1");
        $old->execute([$id]);
        $oldRow = $old->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("UPDATE authors SET meno = ?, slug = ?, bio = ?, foto = COALESCE(?, foto) WHERE id = ?")
            ->execute([$meno, $slug, $bio, $fotoName, $id]);

        if ($removePhoto && !empty($oldRow['foto'])) {
            @unlink($uploadDir . $oldRow['foto']);
            $pdo->prepare("UPDATE authors SET foto = NULL WHERE id = ?")->execute([$id]);
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO authors (meno, slug, bio, foto) VALUES (?, ?, ?, ?)");
        $stmt->execute([$meno, $slug, $bio, $fotoName]);
    }
    $_SESSION['flash_success'] = 'Autor uložený.';
    header('Location: authors.php');
    exit;
} catch (Throwable $e) {
    error_log("author-save.php ERROR: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Došlo k chybe pri ukladaní.';
    header('Location: authors.php');
    exit;
}