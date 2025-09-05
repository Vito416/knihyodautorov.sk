<?php
class Database {
    private static $instance = null;
    private $pdo;
    private function __construct($cfg){
        $this->pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], $cfg['options']);
    }
    public static function init($cfg){
        if (self::$instance === null) self::$instance = new self($cfg);
    }
    public static function get(){
        if (self::$instance === null) throw new Exception('Database not initialized');
        return self::$instance->pdo;
    }
}