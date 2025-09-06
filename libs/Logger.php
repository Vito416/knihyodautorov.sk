<?php
class Logger {
    public static function info($msg){ error_log('[INFO] '.$msg); }
    public static function warn($msg){ error_log('[WARN] '.$msg); }
    public static function error($msg){ error_log('[ERROR] '.$msg); }
}