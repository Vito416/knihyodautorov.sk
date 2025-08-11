<?php
// /admin/inc/upload.php
declare(strict_types=1);

/**
 * Jednoduchý handler obrázkov:
 * - $field: POST field name
 * - $destDir: absolute or relative path (server) where to save; must exist & be writable
 * - returns filename (string) on success or null
 */
function adm_handle_image_upload(string $field, string $destDir, array $allowed = ['image/png','image/jpeg','image/webp']): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $info = getimagesize($_FILES[$field]['tmp_name']);
    if (!$info || !in_array($info['mime'], $allowed, true)) return null;

    $ext = image_type_to_extension($info[2], false);
    $name = 'img_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = rtrim($destDir, '/') . '/' . $name;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) return null;
    // nastavenie práv (hosting)
    @chmod($dest, 0644);
    return $name;
}
