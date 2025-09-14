<?php
declare(strict_types=1);

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
     *   'options' => [PDO::ATTR_TIMEOUT => 5, ...],
     *   'init_commands' => [ "SET time_zone = '+00:00'", "SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'" ]
     * ]
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
        $initCommands = $config['init_commands'] ?? [];

        if (!$dsn) {
            throw new DatabaseException('Missing DSN in database configuration.');
        }

        // Bezpečnostní defaulty, které nelze přepsat
        $enforcedDefaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        $options = $givenOptions;
        foreach ($enforcedDefaults as $k => $v) {
            $options[$k] = $v;
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            // Run optional initialization commands (best-effort)
            if (!empty($initCommands) && is_array($initCommands)) {
                foreach ($initCommands as $cmd) {
                    if (!is_string($cmd)) continue;
                    try { $pdo->exec($cmd); } catch (PDOException $_) { /* ignore init failures */ }
                }
            }

            // Basic connectivity check
            try {
                $pdo->query('SELECT 1');
            } catch (PDOException $e) {
                // If the simple query fails, still create instance but note possible connectivity issues.
                error_log('[Database] connectivity check failed: ' . $e->getMessage());
            }

        } catch (PDOException $e) {
            // Minimal, non-sensitive log. Make sure log storage is secured in production.
            error_log('[Database] connection failed: ' . $e->getMessage());
            throw new DatabaseException('Failed to connect to database');
        }

        self::$instance = new self($config, $pdo);
    }

    /**
     * Vrátí singleton instanci Database.
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

    /**
     * Prepare and execute statement with safe explicit binding.
     * Returns PDOStatement on success or throws DatabaseException.
     */
    public function prepareAndRun(string $sql, array $params = []): \PDOStatement
    {
        try {
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare($sql);
            if ($stmt === false) {
                throw new DatabaseException('Failed to prepare statement.');
            }

            // explicit binding to better handle binary / null / int / bool types
            foreach ($params as $key => $value) {
                // normalize key name (:name or name)
                $paramName = (strpos((string)$key, ':') === 0) ? $key : ':' . $key;

                if ($value === null) {
                    $stmt->bindValue($paramName, null, PDO::PARAM_NULL);
                } elseif (is_int($value)) {
                    $stmt->bindValue($paramName, $value, PDO::PARAM_INT);
                } elseif (is_bool($value)) {
                    $stmt->bindValue($paramName, $value ? 1 : 0, PDO::PARAM_INT);
                } elseif (is_string($value)) {
                    // binary detection: contains null byte? treat as LOB
                    if (strpos($value, "\0") !== false && defined('PDO::PARAM_LOB')) {
                        $stmt->bindValue($paramName, $value, PDO::PARAM_LOB);
                    } else {
                        $stmt->bindValue($paramName, $value, PDO::PARAM_STR);
                    }
                } else {
                    // fallback to string casting
                    $stmt->bindValue($paramName, (string)$value, PDO::PARAM_STR);
                }
            }

            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            // Log sanitized SQL preview (no parameter values)
            error_log('[Database] SQL error: ' . $e->getMessage() . ' -- SQL: ' . $this->sanitizeSqlPreview($sql));
            throw new DatabaseException('Database query failed');
        }
    }

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

    /* transactions */
    public function beginTransaction(): bool
    {
        try { return $this->getPdo()->beginTransaction(); }
        catch (PDOException $e) { throw new DatabaseException('Failed to begin transaction'); }
    }

    public function commit(): bool
    {
        try { return $this->getPdo()->commit(); }
        catch (PDOException $e) { throw new DatabaseException('Failed to commit transaction'); }
    }

    public function rollback(): bool
    {
        try { return $this->getPdo()->rollBack(); }
        catch (PDOException $e) { throw new DatabaseException('Failed to rollback transaction'); }
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->getPdo()->lastInsertId($name);
    }

    /* sanitizace SQL preview pro log (neukládat parametry s citlivými údaji) */
    private function sanitizeSqlPreview(string $sql): string
    {
        $max = 300;
        return strlen($sql) > $max ? substr($sql, 0, $max) . '...' : $sql;
    }

    /* ----------------- ochrana singletonu ----------------- */
    private function __clone() {}
    public function __wakeup(): void
    {
        throw new DatabaseException('Cannot unserialize singleton');
    }

    /**
     * Optional helper: quick health check (best-effort).
     */
    public function ping(): bool
    {
        try {
            $this->getPdo()->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}