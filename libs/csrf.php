<?php
class CSRF {
    public static function token(){
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
    public static function validate($token){
        return hash_equals($_SESSION['csrf_token'] ?? '', (string)$token);
    }
}