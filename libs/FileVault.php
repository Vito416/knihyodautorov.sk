<?php
// libs/FileVault.php
// Helper for encrypted files at rest. Uses Crypto::decrypt() which expects the file to contain base64(iv|tag|ciphertext)
class FileVault {
    // Decrypts an encrypted file and returns decrypted content (string).
    // WARNING: loads whole file into memory. For very large files, implement streaming with chunked AES-GCM.
    public static function decryptFileToString(string $encPath) : ?string {
        if (!file_exists($encPath)) return null;
        $b64 = file_get_contents($encPath);
        if ($b64 === false) return null;
        $plain = Crypto::decrypt($b64);
        return $plain;
    }

    // Streams decrypted content to output with proper headers. $downloadName used for Content-Disposition.
    public static function streamDecryptedFile(string $encPath, string $downloadName) : bool {
        $plain = self::decryptFileToString($encPath);
        if ($plain === null) return false;
        // send headers if not already sent
        if (!headers_sent()) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($downloadName).'"');
            header('Content-Length: '.strlen($plain));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }
        echo $plain;
        return true;
    }
}