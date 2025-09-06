// File: libs/FileVault.php
<?php
declare(strict_types=1);
/**
* Simple FileVault helper implementing AES-256-GCM encryption at rest and streaming decryption.
*
* Usage:
* - FileVault::uploadAndEncrypt($tmpPath, $destPath);
* - FileVault::decryptAndStream($encryptedPath, $downloadName, $mimeType);
* - FileVault::deleteFile($path);
*
* Requires a symmetric key available as FILEVAULT_KEY constant or in $GLOBALS['config']['filevault_key'].
* The encrypted file format (binary) is: [version(1)][iv_len(1)][iv][tag_len(1)][tag][ciphertext]
*/


class FileVault
{
private const VERSION = 1;
private const CIPHER = 'aes-256-gcm';


private static function getKey(): string
{
if (defined('FILEVAULT_KEY') && FILEVAULT_KEY !== '') return FILEVAULT_KEY;
if (!empty($GLOBALS['config']['filevault_key'])) return (string)$GLOBALS['config']['filevault_key'];
// fallback to env
$k = getenv('FILEVAULT_KEY') ?: '';
if ($k !== '') return $k;
throw new RuntimeException('FileVault key not configured. Define FILEVAULT_KEY or $GLOBALS["config"]["filevault_key"].');
}


/**
* Encrypts and moves uploaded file to destination (outside webroot recommended).
* Returns path to encrypted file on success.
*/
public static function uploadAndEncrypt(string $sourceTmpPath, string $destinationPath): string
}


$written = file_put_contents($destinationPath, $payload, LOCK_EX);
if ($written === false) throw new RuntimeException('Unable to write encrypted file');


return $destinationPath;
}


/**
* Decrypts encrypted file and streams it to the client with appropriate headers.
*/
public static function decryptAndStream(string $encryptedPath, string $downloadName = null, string $mime = 'application/octet-stream'): void
{
if (!file_exists($encryptedPath)) {
http_response_code(404);
echo 'Súbor nenájdený.';
exit;
}
$data = file_get_contents($encryptedPath);
if ($data === false) {
http_response_code(500);
echo 'Chyba čítania súboru.';
exit;
}


$offset = 0;
$version = ord($data[$offset]); $offset += 1;
if ($version !== self::VERSION) {
http_response_code(500);
echo 'Nepodporovaná verzia šifrovania.';
exit;
}
$ivLen = ord($data[$offset]); $offset += 1;
$iv = substr($data, $offset, $ivLen); $offset += $ivLen;
$tagLen = ord($data[$offset]); $offset += 1;
$tag = substr($data, $offset, $tagLen); $offset += $tagLen;
$ciphertext = substr($data, $offset);


$key = self::getKey();
$plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
if ($plaintext === false) {
http_response_code(500);
echo 'Dešifrovanie zlyhalo.';
exit;
}


// Stream to client
if ($downloadName === null) $downloadName = basename($encryptedPath);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . strlen($plaintext));
echo $plaintext;
exit;
}


/**
* Delete file safely.
*/
public static function deleteFile(string $path): bool
{
if (!file_exists($path)) return true;
// Basic safety: ensure file is under storage directory if configured
$storageBase = __DIR__ . '/../www/storage';
$real = realpath($path);
if ($real === false) return false;
if (strpos($real, realpath($storageBase)) !== 0) {
// refuse to delete outside expected storage
return false;
}
return @unlink($real);
}
}