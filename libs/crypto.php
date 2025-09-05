<?php
class Crypto {
    private static $key_bytes = null; // raw bytes
    public static function init_from_base64($b64){
        self::$key_bytes = base64_decode($b64);
        if (strlen(self::$key_bytes) !== 32) throw new Exception('Crypto key must be 32 bytes (base64)');
    }
    // AES-256-GCM encrypt, returns base64 of iv|tag|ciphertext
    public static function encrypt(string $plaintext): string{
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::$key_bytes, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt(string $b64){
        $data = base64_decode($b64);
        $iv = substr($data,0,12);
        $tag = substr($data,12,16);
        $cipher = substr($data,28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::$key_bytes, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain===false?null:$plain;
    }
}