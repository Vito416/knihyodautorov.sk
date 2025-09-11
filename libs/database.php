<?php
declare(strict_types=1);

use PDO;
use PDOException;
use RuntimeException;

class DatabaseException extends RuntimeException {}

final class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private array $config = [];

    /**
     * Soukromý konstruktor — singleton
     */
    private function __construct(array $config, PDO $pdo)
    {
        $this->config = $config;
        $this->pdo = $pdo;
    }

    /**
     * Inicializace (volej z bootstrapu) - eager connect
     *
     * Konfigurace: [
     *   'dsn' => 'mysql:host=...;dbname=...;charset=utf8mb4',
     *   'user' => 'dbuser',
     *   'pass' => 'secret',
     *   'options' => [PDO::ATTR_TIMEOUT => 5, ...] // NEPŘEPISUJ bezpečnostní defaulty
     * ]
     *
     * Tato metoda se musí volat dříve než Database::getInstance() / getPdo().
     */
    public static function init(array $config): void
    {
        if (self::$instance !== null) {
            throw new DatabaseException('Database already initialized');
        }

        $dsn = $config['dsn'] ?? null;
        $user = $config['user'] ?? null;
        $pass = $config['pass'] ?? null;
        $givenOptions = $config['options'] ?? [];

        if (!$dsn) {
            throw new DatabaseException('Missing DSN in database configuration.');
        }

        // Bezpečnostní defaulty, které NEPŮJDOU přepsat
        $enforcedDefaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // PDO::ATTR_PERSISTENT => false, // implicitně false unless user sets; lze vynutit pokud chceš
        ];

        // Poskládáme options tak, aby uživatel nemohl přepsat 'enforcedDefaults'
        $options = $givenOptions;
        foreach ($enforcedDefaults as $k => $v) {
            $options[$k] = $v; // vynutíme bezpečnostní hodnoty
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            // Volitelné: explicitní nastavení časové zóny / init příkazy apod.:
            // $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            // Loguj interně - do produkčního logu (nevracej citlivé údaje klientovi)
            error_log('[Database] connection failed: ' . $e->getMessage());
            // Zahoď upravenou/generic výjimku bez přihlašovacích údajů
            throw new DatabaseException('Failed to connect to database');
        }

        self::$instance = new self($config, $pdo);
    }

    /**
     * Vrátí singleton instanci Database.
     * Pokud init nebylo voláno, vyhodí DatabaseException.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new DatabaseException('Database not initialized. Call Database::init($config) in bootstrap.');
        }
        return self::$instance;
    }

    /**
     * Vrátí PDO instanci (init musí být volané předtím)
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            // bezpečnostní ochrana — nechcem lazy connect
            throw new DatabaseException('Database not initialized properly (PDO missing).');
        }
        return $this->pdo;
    }

    /**
     * Ptá se, zda je DB initnuta
     */
    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    /* ----------------- Helper metody ----------------- */

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepareAndRun($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepareAndRun($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Execute an INSERT/UPDATE/DELETE and return affected rows.
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepareAndRun($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Prepare and execute statement. Exceptions bubbled as DatabaseException.
     */
    public function prepareAndRun(string $sql, array $params = []): \PDOStatement
    {
        try {
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare($sql);
            if ($stmt === false) {
                throw new DatabaseException('Failed to prepare statement.');
            }
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('[Database] SQL error: ' . $e->getMessage() . ' -- SQL: ' . $this->sanitizeSqlPreview($sql));
            throw new DatabaseException('Database query failed');
        }
    }

    public function beginTransaction(): bool
    {
        try {
            return $this->getPdo()->beginTransaction();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to begin transaction');
        }
    }

    public function commit(): bool
    {
        try {
            return $this->getPdo()->commit();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to commit transaction');
        }
    }

    public function rollback(): bool
    {
        try {
            return $this->getPdo()->rollBack();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to rollback transaction');
        }
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->getPdo()->lastInsertId($name);
    }

    /* sanitizace SQL preview pro log (neukládat parametry s citlivými údaji) */
    private function sanitizeSqlPreview(string $sql): string
    {
        // jen krátké preview bez parametrů
        $max = 300;
        return strlen($sql) > $max ? substr($sql, 0, $max) . '...' : $sql;
    }

    /* ----------------- ochrana singletonu ----------------- */
    private function __clone() {}
    private function __wakeup() {}
}