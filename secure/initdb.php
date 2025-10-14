<?php

declare(strict_types=1);

use BlackCat\Core\Database;
use BlackCat\Core\Security\KeyManager;
use BlackCat\Core\Security\Crypto;

$PROJECT_ROOT = realpath(dirname(__DIR__, 3));
if ($PROJECT_ROOT === false) {
    error_log('[bootstrap] Cannot resolve PROJECT_ROOT');
    http_response_code(500);
    exit;
}
$configFile = $PROJECT_ROOT . '/secure/config.php';
if (!file_exists($configFile)) {
    error_log('[bootstrap] Missing secure/config.php');
    http_response_code(500);
    exit;
}
require_once $configFile;
if (!isset($config) || !is_array($config)) {
    error_log('[bootstrap] secure/config.php must define $config array');
    http_response_code(500);
    exit;
}
require_once $PROJECT_ROOT . '/libs/autoload.php';
if (!class_exists(BlackCat\Core\Database::class, true)) {
        error_log('[bootstrap_minimal] Class BlackCat\\Core\\Database not found by autoloader');
        http_response_code(500);
        exit;
    }
try {
    // Použijte konstantu třídy místo prostého stringu
    if (!class_exists(BlackCat\Core\Database::class, true)) {
        throw new RuntimeException('Database class not available (autoload error)');
    }

    if (empty($config['adb']) || !is_array($config['adb'])) {
        throw new RuntimeException('Missing $config[\'adb\']');
    }

    Database::init($config['adb']);
    $database = Database::getInstance();
    $pdo = $database->getPdo();
} catch (Throwable $e) {
    // logujeme místo echo => žádné "headers already sent"
    error_log('Database initialization failed: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

if (!($pdo instanceof PDO)) {
    error_log('DB variable is not a PDO instance after init');
    http_response_code(500);
    exit;
}

// Funkcia na vytvorenie tabuľky s hlásením
function createTable($pdo, $sql, $tableName) {
    try {
        $pdo->exec($sql);
        echo "<p>Tabuľka $tableName bola úspešne vytvorená.</p>";
    } catch (PDOException $e) {
        echo "<p>Chyba pri vytváraní tabuľky $tableName: " . $e->getMessage() . "</p>";
    }
}

// Funkcia na vloženie demo dát s hlásením
function executeQuery($pdo, $sql, $desc) {
    try {
        $pdo->exec($sql);
        echo "<p>$desc bol úspešne vložený.</p>";
    } catch (PDOException $e) {
        echo "<p>Chyba pri vkladaní $desc: " . $e->getMessage() . "</p>";
    }
}

// Spracovanie akcií po stlačení tlačidiel
if (isset($_POST['create_db'])) {
    // 1. Vytvorenie tabuliek podľa výskumu

    // Tabuľka pouzivatelia
    $sql = "CREATE TABLE IF NOT EXISTS pouzivatelia (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email_enc LONGBLOB NULL,
        email_key_version VARCHAR(64) NULL,
        email_hash VARBINARY(32) NULL,
        email_hash_key_version VARCHAR(64) NULL,
        heslo_hash VARCHAR(255) NOT NULL,
        heslo_algo VARCHAR(64) NULL,
        heslo_key_version VARCHAR(64) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        failed_logins INT NOT NULL DEFAULT 0,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        last_login_at DATETIME(6) NULL,
        last_login_ip_hash VARBINARY(32) NULL,
        last_login_ip_key VARCHAR(64) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        actor_type ENUM('zakaznik','admin') NOT NULL DEFAULT 'zakaznik',
        INDEX idx_last_login_at (last_login_at),
        INDEX idx_is_active (is_active),
        INDEX idx_actor_type (actor_type),
        INDEX idx_last_login_ip_hash (last_login_ip_hash),
        INDEX idx_pouzivatelia_email_hash (email_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "pouzivatelia");

    // Tabuľka login_attempts
    $sql = "CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip_hash VARBINARY(32) NOT NULL,
        attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        success TINYINT(1) NOT NULL DEFAULT 0,           -- 0 = failure, 1 = success
        user_id BIGINT UNSIGNED NULL,
        username_hash VARBINARY(32) NULL,                -- optional, HMAC of username/email
        auth_event_id BIGINT UNSIGNED NULL,              -- optional FK to auth_events
        INDEX idx_ip_success_time (ip_hash, success, attempted_at),
        INDEX idx_attempted_at (attempted_at),
        INDEX idx_username_hash (username_hash),
        INDEX idx_auth_event_id (auth_event_id),
        INDEX idx_user_time (user_id, attempted_at),
        CONSTRAINT chk_success_boolean CHECK (success IN (0,1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "login_attempts");

    // Tabuľka user_profiles
    $sql = "CREATE TABLE IF NOT EXISTS user_profiles (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        profile_enc LONGBLOB DEFAULT NULL,
        key_version VARCHAR(64) DEFAULT NULL,
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "user_profiles");

    // Tabuľka user_identities
    $sql = "CREATE TABLE IF NOT EXISTS user_identities (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(100) NOT NULL,
        provider_user_id VARCHAR(255) NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        UNIQUE KEY ux_provider_user (provider, provider_user_id),
        INDEX idx_user_id (user_id),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "user_identities");

    // Tabuľka permissions
    $sql = "CREATE TABLE IF NOT EXISTS permissions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nazov VARCHAR(100) NOT NULL UNIQUE,
        popis TEXT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "permissions");

    // Tabuľka two_factor
    $sql = "CREATE TABLE IF NOT EXISTS two_factor (
        user_id BIGINT UNSIGNED NOT NULL,
        method VARCHAR(50) NOT NULL,
        secret VARBINARY(255) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT FALSE,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        last_used_at DATETIME(6) NULL,
        PRIMARY KEY (user_id, method),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "two_factor");

    // Tabuľka session_audit
    $sql = "CREATE TABLE IF NOT EXISTS session_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_token VARBINARY(32) NULL,
        session_token_key_version VARCHAR(64) NULL,
        csrf_key_version VARCHAR(64) NULL,
        session_id VARCHAR(128) NULL,
        event VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        ip_hash VARBINARY(32) NULL,
        ip_hash_key VARCHAR(64) NULL,
        ua VARCHAR(512) NULL,
        meta_json JSON NULL,
        outcome VARCHAR(32) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX idx_session_token (session_token),
        INDEX idx_session_id (session_id),
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at),
        INDEX idx_event (event),
        INDEX idx_ip_hash (ip_hash),
        INDEX idx_ip_hash_key (ip_hash_key),
        INDEX idx_event_created_at (event, created_at),
        INDEX idx_session_audit_user_event_time (user_id, event, created_at),
        INDEX idx_session_audit_token_time (session_token, created_at),
        CONSTRAINT fk_session_audit_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "session_audit");

    // Tabuľka sessions
    $sql = "CREATE TABLE IF NOT EXISTS sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token_hash VARBINARY(32) NOT NULL,        -- HMAC-SHA256 raw binary (32 bytes)
        token_hash_key VARCHAR(64) NULL,          -- key version string (e.g. 'v2' or 'env')
        token_fingerprint VARBINARY(32) NULL,     -- sha256(cookieToken) binary 32
        token_issued_at DATETIME(6) NULL,
        user_id BIGINT UNSIGNED NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        expires_at DATETIME(6) NULL,
        failed_decrypt_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_failed_decrypt_at DATETIME(6) NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        ip_hash VARBINARY(32) NULL,
        ip_hash_key VARCHAR(64) NULL,
        user_agent VARCHAR(512) NULL,
        session_blob LONGBLOB NULL,
        UNIQUE KEY uq_token_hash (token_hash),
        INDEX (user_id, created_at),
        INDEX idx_user_id (user_id),
        INDEX idx_expires_at (expires_at),
        INDEX idx_last_seen (last_seen_at),
        INDEX idx_token_hash_key (token_hash_key),
        CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "sessions");

    // Tabuľka auth_events
    $sql = "CREATE TABLE IF NOT EXISTS auth_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        type ENUM('login_success','login_failure','logout','password_reset','lockout') NOT NULL,
        ip_hash VARBINARY(32) NULL,
        ip_hash_key VARCHAR(64) NULL,
        user_agent VARCHAR(512) NULL,
        occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        meta JSON NULL,
        meta_email VARCHAR(255) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email'))) STORED,
        INDEX idx_meta_email (meta_email),
        INDEX idx_ver_user (user_id),
        INDEX idx_ver_time (occurred_at),
        INDEX idx_ver_type_time (type, occurred_at),
        INDEX idx_ip_hash (ip_hash),
        CONSTRAINT fk_auth_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        createTable($pdo, $sql, "auth_events");

    // Tabuľka register_events
    $sql = "CREATE TABLE IF NOT EXISTS register_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        type ENUM('register_success','register_failure') NOT NULL,
        ip_hash VARBINARY(32) NULL,
        ip_hash_key VARCHAR(64) NULL,
        user_agent VARCHAR(512) NULL,
        occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        meta JSON NULL,
        INDEX idx_reg_user (user_id),
        INDEX idx_reg_time (occurred_at),
        INDEX idx_reg_type_time (type, occurred_at),
        INDEX idx_reg_ip (ip_hash),
        CONSTRAINT fk_register_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "register_events");

    // Tabuľka verify_events
    $sql = "CREATE TABLE IF NOT EXISTS verify_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        type ENUM('verify_success','verify_failure') NOT NULL,
        ip_hash VARBINARY(32) NULL,
        ip_hash_key VARCHAR(64) NULL,
        user_agent VARCHAR(512) NULL,
        occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        meta JSON NULL,
        INDEX idx_ver_user (user_id),
        INDEX idx_ver_time (occurred_at),
        INDEX idx_ver_type_time (type, occurred_at),
        INDEX idx_ver_ip (ip_hash),
        CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "verify_events");

    // Tabuľka system_error (odporúčané: binárne IP pre IPv4/IPv6)
    $sql = "CREATE TABLE IF NOT EXISTS system_error (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        level ENUM('notice','warning','error','critical') NOT NULL,
        message TEXT NOT NULL,
        exception_class VARCHAR(255) NULL,
        file VARCHAR(1024) NULL,
        line INT UNSIGNED NULL,
        stack_trace MEDIUMTEXT NULL,
        token VARCHAR(255) NULL,
        context JSON NULL,
        fingerprint VARCHAR(64) NULL,           -- hash() vrací hex(64) v Loggeru
        occurrences INT UNSIGNED NOT NULL DEFAULT 1,
        user_id BIGINT UNSIGNED NULL,
        ip_hash VARBINARY(32) NULL,
        ip_hash_key VARCHAR(64) NULL,
        ip_text VARCHAR(45) NULL,
        user_agent VARCHAR(512) NULL,
        url VARCHAR(2048) NULL,
        method VARCHAR(10) NULL,
        http_status SMALLINT UNSIGNED NULL,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        resolved_by BIGINT UNSIGNED NULL,
        resolved_at DATETIME(6) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        last_seen DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX idx_err_level (level),
        INDEX idx_err_time (created_at),
        INDEX idx_err_user (user_id),
        INDEX idx_err_ip (ip_hash),
        INDEX idx_err_resolved (resolved),
        UNIQUE KEY uq_err_fp (fingerprint),
        CONSTRAINT fk_err_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL,
        CONSTRAINT fk_err_resolved_by FOREIGN KEY (resolved_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    createTable($pdo, $sql, "system_error");

    // Tabuľka user_consents
    $sql = "CREATE TABLE IF NOT EXISTS user_consents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        consent_type VARCHAR(50) NOT NULL,
        version VARCHAR(50) NOT NULL,
        granted TINYINT(1) NOT NULL,
        granted_at DATETIME(6) NOT NULL,
        source VARCHAR(100) NULL,
        meta JSON NULL,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "user_consents");

    // Tabuľka authors
    $sql = "CREATE TABLE IF NOT EXISTS authors (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meno VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        bio TEXT NULL,
        foto VARCHAR(255) NULL,
        story LONGTEXT NULL,
        books_count INT NOT NULL DEFAULT 0,
        ratings_count INT NOT NULL DEFAULT 0,
        rating_sum INT NOT NULL DEFAULT 0,
        avg_rating DECIMAL(3,2) NULL DEFAULT NULL,
        last_rating_at DATETIME(6) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        INDEX idx_authors_avg_rating (avg_rating),
        INDEX idx_authors_books_count (books_count)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "authors");

    // Tabuľka categories
    $sql = "CREATE TABLE IF NOT EXISTS categories (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nazov VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        parent_id BIGINT UNSIGNED NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
        INDEX idx_categories_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "categories");

    // Tabuľka books
    $sql = "CREATE TABLE IF NOT EXISTS books (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        short_description VARCHAR(512) NULL,
        full_description LONGTEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'EUR',
        author_id BIGINT UNSIGNED NOT NULL,
        main_category_id BIGINT UNSIGNED NOT NULL,
        isbn VARCHAR(32) NULL,
        language VARCHAR(16) NULL,
        pages INT NULL,
        publisher VARCHAR(255) NULL,
        published_at DATE NULL,
        sku VARCHAR(64) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_available TINYINT(1) NOT NULL DEFAULT 1,
        stock_quantity INT NOT NULL DEFAULT 0,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE RESTRICT,
        FOREIGN KEY (main_category_id) REFERENCES categories(id) ON DELETE RESTRICT,
        INDEX idx_books_author_id (author_id),
        INDEX idx_books_main_category_id (main_category_id),
        INDEX idx_books_sku (sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    createTable($pdo, $sql, "books");

    // Tabuľka reviews
    $sql = "CREATE TABLE IF NOT EXISTS reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NULL ON UPDATE CURRENT_TIMESTAMP(6),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_reviews_book_id (book_id),
    INDEX idx_reviews_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "reviews");

    // Tabuľka crypto_keys
    $sql = "CREATE TABLE IF NOT EXISTS crypto_keys (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        basename VARCHAR(100) NOT NULL,          -- např. 'password_pepper', 'crypto_key'
        version INT NOT NULL,                     -- numerická verze (1,2,3)
        filename VARCHAR(255) NULL,               -- např. 'crypto_key_v3.bin' (neobsahuje path sensitive)
        file_path VARCHAR(1024) NULL,             -- volitelné, relativní / bezpečně nastavitelné
        fingerprint CHAR(64) NULL,                -- sha256 hex of raw key OR of file contents
        key_meta JSON NULL,                       -- volitelně: extra metadata (alg, length, notes)
        status ENUM('active','retired','compromised','archived') NOT NULL DEFAULT 'active',
        is_backup_encrypted TINYINT(1) NOT NULL DEFAULT 0, -- pokud ukládáš encrypted blob
        backup_blob LONGBLOB NULL,                -- pouze pokud explicitně chcete ukládat šifrovaný dump (silně discouraged)
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        activated_at DATETIME(6) NULL,
        retired_at DATETIME(6) NULL,
        replaced_by BIGINT UNSIGNED NULL,                     -- FK na keys.id (novější verze)
        notes TEXT NULL,
        CONSTRAINT uq_keys_basename_version UNIQUE (basename, version),
        CONSTRAINT fk_keys_created_by FOREIGN KEY (created_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL,
        CONSTRAINT fk_keys_replaced_by FOREIGN KEY (replaced_by) REFERENCES crypto_keys(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "crypto_keys");

   // Tabuľka key_events
    $sql = "CREATE TABLE IF NOT EXISTS key_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        key_id BIGINT UNSIGNED NULL,                           -- FK na keys.id (může být NULL při globálních událostech)
        basename VARCHAR(100) NULL,                -- duplicitně pro události bez key_id
        event_type ENUM('created','rotated','activated','retired','compromised','deleted','used_encrypt','used_decrypt','access_failed','backup','restore') NOT NULL,
        actor_id BIGINT UNSIGNED NULL,             -- who/what triggered (cron/admin user id)
        job_id BIGINT UNSIGNED NULL,                           -- optional reference to key_rotation_jobs.id
        note TEXT NULL,
        meta JSON NULL,                            -- optional structured data (e.g. filename, fingerprint, env)
        source ENUM('cron','admin','api','manual') NOT NULL DEFAULT 'admin',
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        CONSTRAINT fk_key_events_key FOREIGN KEY (key_id) REFERENCES crypto_keys(id) ON DELETE SET NULL,
        CONSTRAINT fk_key_events_actor FOREIGN KEY (actor_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    createTable($pdo, $sql, "key_events");
        try {
        $pdo->exec("CREATE INDEX idx_key_events_key_created ON key_events (key_id, created_at)");
        } catch (PDOException $e) { /* fallback / log */ }

   // Tabuľka key_rotation_jobs
    $sql = "CREATE TABLE IF NOT EXISTS key_rotation_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        basename VARCHAR(100) NOT NULL,            -- který klíč rotujeme
        target_version INT NULL,                    -- expected new version (optional)
        scheduled_at DATETIME(6) NULL,
        started_at DATETIME(6) NULL,
        finished_at DATETIME(6) NULL,
        status ENUM('pending','running','done','failed','cancelled') NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        executed_by BIGINT UNSIGNED NULL,
        result TEXT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        CONSTRAINT fk_key_rotation_jobs_user FOREIGN KEY (executed_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
        createTable($pdo, $sql, "key_rotation_jobs");
        try {
        $pdo->exec("CREATE INDEX idx_key_rotation_jobs_basename_sched ON key_rotation_jobs (basename, scheduled_at)");
        } catch (PDOException $e) { /* fallback / log */ }

   // Tabuľka key_usage
    $sql = "CREATE TABLE IF NOT EXISTS key_usage (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        key_id BIGINT UNSIGNED NOT NULL,
        date DATE NOT NULL,
        encrypt_count INT NOT NULL DEFAULT 0,
        decrypt_count INT NOT NULL DEFAULT 0,
        verify_count INT NOT NULL DEFAULT 0,
        last_used_at DATETIME(6) NULL,
        CONSTRAINT fk_key_usage_key FOREIGN KEY (key_id) REFERENCES crypto_keys(id) ON DELETE CASCADE,
        UNIQUE KEY uq_key_usage_key_date (key_id, date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "key_usage");

    // Tabuľka jwt_tokens
    $sql = "CREATE TABLE IF NOT EXISTS jwt_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        jti CHAR(36) NOT NULL UNIQUE,
        user_id BIGINT UNSIGNED NULL,
        token_hash VARBINARY(32) NOT NULL,               -- binary HMAC-SHA256 of refresh token
        token_hash_algo VARCHAR(50) NULL,                 -- e.g. 'hmac-sha256'
        token_hash_key_version VARCHAR(64) NULL,          -- e.g. 'v2' or 'env'
        type ENUM('refresh','api') NOT NULL DEFAULT 'refresh',
        scopes VARCHAR(255) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        expires_at DATETIME(6) NULL,
        last_used_at DATETIME(6) NULL,
        ip_hash VARBINARY(32) NULL, ip_hash_key VARCHAR(64) NULL,
        replaced_by BIGINT UNSIGNED NULL,                 -- id of token that replaced this (rotation)
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        meta JSON NULL,
        CONSTRAINT fk_jwt_tokens_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL,
        CONSTRAINT fk_jwt_tokens_replaced_by FOREIGN KEY (replaced_by) REFERENCES jwt_tokens(id) ON DELETE SET NULL,
        UNIQUE KEY uq_jwt_token_hash (token_hash),
        INDEX idx_jwt_user (user_id),
        INDEX idx_jwt_expires (expires_at),
        INDEX idx_jwt_revoked_user (revoked, user_id),
        INDEX idx_jwt_last_used (last_used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    createTable($pdo, $sql, "jwt_tokens");

    // Tabuľka book_assets
    $sql = "CREATE TABLE IF NOT EXISTS book_assets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        book_id BIGINT UNSIGNED NOT NULL,
        asset_type ENUM('cover','pdf','epub','mobi','sample','extra') NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        size_bytes BIGINT NOT NULL,
        storage_path TEXT NULL,
        content_hash VARCHAR(64) NULL,
        download_filename VARCHAR(255) NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        encryption_algo VARCHAR(50) NULL,
        encryption_key_enc VARBINARY(255) NULL,
        encryption_iv VARBINARY(255) NULL,
        encryption_tag VARBINARY(255) NULL,
        key_version VARCHAR(64) NULL,
        key_id BIGINT UNSIGNED NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        CONSTRAINT fk_book_assets_key FOREIGN KEY (key_id) REFERENCES crypto_keys(id) ON DELETE SET NULL,
        CONSTRAINT fk_book_assets_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
        INDEX idx_book_assets_book (book_id),
        INDEX idx_book_assets_type (asset_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    createTable($pdo, $sql, "book_assets");

    // Tabuľka book_categories
    $sql = "CREATE TABLE IF NOT EXISTS book_categories (
        book_id BIGINT UNSIGNED NOT NULL,
        category_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (book_id, category_id),
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
        INDEX idx_book_categories_category (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "book_categories");

        // Tabuľka inventory_reservations
    $sql = "CREATE TABLE IF NOT EXISTS inventory_reservations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NULL,
        book_id BIGINT UNSIGNED NOT NULL,
        qty INT NOT NULL,
        reserved_until DATETIME(6) NOT NULL,
        status ENUM('pending','confirmed','expired','cancelled') NOT NULL DEFAULT 'pending',
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX idx_res_book (book_id),
        INDEX idx_res_order (order_id),
        INDEX idx_res_status_until (status, reserved_until),
        CONSTRAINT fk_res_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "inventory_reservations");


    // Tabuľka carts
    $sql = "CREATE TABLE IF NOT EXISTS carts (
        id CHAR(36) PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "carts");

    // Tabuľka cart_items
    $sql = "CREATE TABLE IF NOT EXISTS cart_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cart_id CHAR(36) NOT NULL,
        book_id BIGINT UNSIGNED NOT NULL,
        sku VARCHAR(64) NULL,
        variant JSON NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        price_snapshot DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        meta JSON NULL,
        PRIMARY KEY (id),
        INDEX idx_cart_id (cart_id),
        FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "cart_items");

    // Tabuľka orders
    $sql = "CREATE TABLE IF NOT EXISTS orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(32) NOT NULL UNIQUE,
        public_order_no VARCHAR(64) NULL,
        user_id BIGINT UNSIGNED NULL,
        status ENUM('pending','paid','failed','cancelled','refunded','completed') NOT NULL DEFAULT 'pending',
        encrypted_customer_blob LONGBLOB NULL,
        encrypted_customer_blob_key_version VARCHAR(64) NULL,
        currency CHAR(3) NOT NULL,
        metadata JSON NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        tax_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(100) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        INDEX idx_orders_user_id (user_id),
        INDEX idx_orders_status (status),
        INDEX idx_orders_user_status (user_id, status),
        INDEX idx_orders_uuid (uuid),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "orders");

    // Tabuľka order_items
    $sql = "CREATE TABLE IF NOT EXISTS order_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NULL,
        book_id BIGINT UNSIGNED NULL,
        product_ref INT NULL,
        title_snapshot VARCHAR(255) NOT NULL,
        sku_snapshot VARCHAR(64) NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL,
        tax_rate DECIMAL(5,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        INDEX idx_order_items_order_id (order_id),
        INDEX idx_order_items_book_id (book_id),
        CONSTRAINT fk_order_items_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "order_items");

    // Tabuľka order_item_downloads
    $sql = "CREATE TABLE IF NOT EXISTS order_item_downloads (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        book_id BIGINT UNSIGNED NOT NULL,
        asset_id BIGINT UNSIGNED NOT NULL,
        download_token_hash VARBINARY(32) NULL,
        token_key_version VARCHAR(64) NULL,
        encryption_key_version INT NULL,
        max_uses INT NOT NULL,
        used INT NOT NULL DEFAULT 0,
        expires_at DATETIME(6) NOT NULL,
        last_used_at DATETIME(6) NULL,
        ip_hash VARBINARY(32) NULL, ip_hash_key VARCHAR(64) NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
        FOREIGN KEY (asset_id) REFERENCES book_assets(id) ON DELETE CASCADE,
        INDEX idx_oid_download_token_hash (download_token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "order_item_downloads");

    // Tabuľka invoices
    $sql = "CREATE TABLE IF NOT EXISTS invoices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NULL,
        invoice_number VARCHAR(100) NOT NULL UNIQUE,
        variable_symbol VARCHAR(50) NULL,
        issue_date DATE NOT NULL,
        due_date DATE NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        discount_total DECIMAL(10,2) NOT NULL,
        tax_total DECIMAL(10,2) NOT NULL,
        total DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        qr_data LONGTEXT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "invoices");

    // Tabuľka invoice_items
    $sql = "CREATE TABLE IF NOT EXISTS invoice_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_id BIGINT UNSIGNED NOT NULL,
        line_no INT NOT NULL,
        description TEXT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL,
        tax_rate DECIMAL(5,2) NOT NULL,
        tax_amount DECIMAL(10,2) NOT NULL,
        line_total DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        UNIQUE KEY uq_invoice_line (invoice_id, line_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "invoice_items");

    // Tabuľka payments
    $sql = "CREATE TABLE IF NOT EXISTS payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NULL,
        gateway VARCHAR(100) NOT NULL,
        transaction_id VARCHAR(255) NULL,
        provider_event_id VARCHAR(255) NULL,
        status ENUM('initiated','pending','authorized','paid','cancelled','part-refunded','refunded','failed') NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        details JSON NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        UNIQUE KEY uq_payments_transaction_id (transaction_id),
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
        INDEX idx_payments_order (order_id),
        INDEX idx_payments_provider_event (provider_event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "payments");

    // Tabuľka payment_logs
    $sql = "CREATE TABLE IF NOT EXISTS payment_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_id BIGINT UNSIGNED NOT NULL,
        log_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        message TEXT NOT NULL,
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "payment_logs");

    // Tabuľka payment_webhooks
    $sql = "CREATE TABLE IF NOT EXISTS payment_webhooks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        payment_id BIGINT UNSIGNED NULL,
        gw_id VARCHAR(255) NULL,
        payload_hash CHAR(64) NOT NULL,
        payload JSON NULL,
        from_cache TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX idx_payment_webhooks_payment (payment_id),
        INDEX idx_payment_webhooks_gw_id (gw_id),
        INDEX idx_payment_webhooks_hash (payload_hash),
        CONSTRAINT fk_payment_webhooks_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "payment_webhooks");

    // Tabuľka idempotency_keys
    $sql = "CREATE TABLE IF NOT EXISTS idempotency_keys (
        key_hash CHAR(64) NOT NULL PRIMARY KEY,  -- sha256 hex of provided key
        payment_id BIGINT UNSIGNED NULL DEFAULT NULL,  -- může být NULL během rezervace
        order_id BIGINT UNSIGNED NULL DEFAULT NULL,    -- volitelně, pro rychlé párování
        gateway_payload JSON NULL,                     -- malý GoPay JSON snapshot
        redirect_url VARCHAR(1024) NULL,               -- link pro redirect (redundantně kvůli rychlosti)
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        ttl_seconds INT NOT NULL DEFAULT 86400,
        INDEX idx_idemp_payment (payment_id),
        INDEX idx_idemp_order (order_id),
        INDEX idx_idemp_created_at (created_at),
        CONSTRAINT fk_idemp_payment FOREIGN KEY (payment_id)
            REFERENCES payments(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "idempotency_keys");
    
    // Tabuľka refunds
    $sql = "CREATE TABLE IF NOT EXISTS refunds (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        reason TEXT NULL,
        status VARCHAR(50) NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        details JSON NULL,
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "refunds");

    // Tabuľka coupons
    $sql = "CREATE TABLE IF NOT EXISTS coupons (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(100) NOT NULL UNIQUE,
        type ENUM('percent','fixed') NOT NULL,
        value DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NULL,
        starts_at DATE NOT NULL,
        ends_at DATE NULL,
        max_redemptions INT NOT NULL DEFAULT 0,
        min_order_amount DECIMAL(10,2) NULL,
        applies_to JSON NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "coupons");

    // Tabuľka coupon_redemptions
    $sql = "CREATE TABLE IF NOT EXISTS coupon_redemptions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coupon_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        redeemed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        amount_applied DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "coupon_redemptions");

    // Tabuľka countries
    $sql = "CREATE TABLE IF NOT EXISTS countries (
        iso2 CHAR(2) PRIMARY KEY,
        nazov VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "countries");

    // Tabuľka tax_rates
    $sql = "CREATE TABLE IF NOT EXISTS tax_rates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        country_iso2 CHAR(2) NOT NULL,
        category ENUM('ebook','physical') NOT NULL,
        rate DECIMAL(5,2) NOT NULL,
        valid_from DATE NOT NULL,
        valid_to DATE NULL,
        FOREIGN KEY (country_iso2) REFERENCES countries(iso2) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "tax_rates");

    // Tabuľka vat_validations
    $sql = "CREATE TABLE IF NOT EXISTS vat_validations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vat_id VARCHAR(50) NOT NULL,
        country_iso2 CHAR(2) NOT NULL,
        valid TINYINT(1) NOT NULL,
        checked_at DATETIME(6) NOT NULL,
        raw JSON NULL,
        FOREIGN KEY (country_iso2) REFERENCES countries(iso2) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "vat_validations");

    // Tabuľka app_settings
    $sql = "CREATE TABLE IF NOT EXISTS app_settings (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT NULL,
        type ENUM('string','int','bool','json','secret') NOT NULL,
        section VARCHAR(100) NULL,
        description TEXT NULL,
        protected BOOLEAN NOT NULL DEFAULT FALSE,
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_by BIGINT UNSIGNED NULL,
        FOREIGN KEY (updated_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "app_settings");

    // Tabuľka audit_log
    $sql = "CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(100) NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        changed_by BIGINT UNSIGNED NULL,
        change_type ENUM('INSERT','UPDATE','DELETE') NOT NULL,
        old_value JSON NULL,
        new_value JSON NULL,
        changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(512) NULL,
        request_id VARCHAR(100) NULL,
        FOREIGN KEY (changed_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    createTable($pdo, $sql, "audit_log");

    // Tabuľka webhook_outbox
    $sql = "CREATE TABLE IF NOT EXISTS webhook_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(100) NOT NULL,
        payload JSON NULL,
        status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        retries INT NOT NULL DEFAULT 0,
        next_attempt_at DATETIME(6) NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
    createTable($pdo, $sql, "webhook_outbox");

    // Tabuľka gopay_notify_log (pre asynchrónne notifikácie, retry, monitoring)
    $sql = "CREATE TABLE IF NOT EXISTS gopay_notify_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        transaction_id VARCHAR(255) NULL,
        received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        processing_by VARCHAR(100) NULL,
        processing_until DATETIME(6) NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(255) NULL,
        status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
        UNIQUE KEY ux_notify_payment (transaction_id),
        INDEX idx_status_received (status, received_at),
        CONSTRAINT fk_notify_payment FOREIGN KEY (transaction_id) REFERENCES payments(transaction_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
        createTable($pdo, $sql, "gopay_notify_log");

    // Tabuľka email_verifications (required pre register/verify/resend)
    $sql = "CREATE TABLE IF NOT EXISTS email_verifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        selector CHAR(12) NOT NULL,
        validator_hash VARBINARY(32) NOT NULL, -- raw HMAC-SHA256
        key_version VARCHAR(64) DEFAULT NULL,
        expires_at DATETIME(6) NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        used_at DATETIME(6) NULL,
        CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE,
        UNIQUE KEY ux_ev_selector (selector),
        INDEX idx_ev_user (user_id),
        INDEX idx_ev_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "email_verifications");

    // Tabuľka notifications (jednoduchá, idempotentná)
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        channel ENUM('email','push') NOT NULL,
        template VARCHAR(100) NOT NULL,
        payload JSON NULL,
        status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        retries INT NOT NULL DEFAULT 0,
        max_retries INT NOT NULL DEFAULT 6,
        next_attempt_at DATETIME(6) NULL,
        scheduled_at DATETIME(6) NULL,
        sent_at DATETIME(6) NULL,
        error TEXT NULL,
        last_attempt_at DATETIME(6) NULL,
        locked_until DATETIME(6) NULL,
        locked_by VARCHAR(100) NULL,
        priority INT NOT NULL DEFAULT 0,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        INDEX idx_notifications_status_scheduled (status, scheduled_at),
        INDEX idx_notifications_next_attempt (next_attempt_at),
        INDEX idx_notifications_locked_until (locked_until),
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "notifications");

    // Tabuľka newsletter_subscribers (doladené podľa pouzivatelia)
    $sql = "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        CONSTRAINT fk_ns_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL,
        email_enc LONGBLOB NULL,
        email_key_version VARCHAR(64) NULL,
        email_hash VARBINARY(32) NULL,
        email_hash_key_version VARCHAR(64) NULL,
        confirm_selector CHAR(12) DEFAULT NULL,
        confirm_validator_hash VARBINARY(32) DEFAULT NULL,
        confirm_key_version VARCHAR(64) DEFAULT NULL,
        confirm_expires DATETIME(6) DEFAULT NULL,
        confirmed_at DATETIME(6) DEFAULT NULL,
        unsubscribe_token_hash VARBINARY(32) DEFAULT NULL,
        unsubscribe_token_key_version VARCHAR(64) DEFAULT NULL,
        unsubscribed_at DATETIME(6) DEFAULT NULL,
        origin VARCHAR(100) DEFAULT NULL,
        ip_hash VARBINARY(32) DEFAULT NULL,
        ip_hash_key_version VARCHAR(64) DEFAULT NULL,
        meta JSON DEFAULT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        UNIQUE KEY ux_ns_email_hash (email_hash),
        UNIQUE KEY ux_ns_confirm_selector (confirm_selector),
        INDEX idx_ns_user (user_id),
        INDEX idx_ns_confirm_expires (confirm_expires),
        INDEX idx_ns_unsubscribed_at (unsubscribed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "newsletter_subscribers");
    
    // Tabuľka system_jobs (jednoduchá)
    $sql = "CREATE TABLE IF NOT EXISTS system_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_type VARCHAR(100) NOT NULL,
        payload JSON NULL,
        status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
        retries INT NOT NULL DEFAULT 0,
        scheduled_at DATETIME(6) NULL,
        started_at DATETIME(6) NULL,
        finished_at DATETIME(6) NULL,
        error TEXT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "system_jobs");

    // Tabuľka worker_locks (jednoduchá)
    $sql = "CREATE TABLE IF NOT EXISTS worker_locks (
    name VARCHAR(191) NOT NULL PRIMARY KEY,
    locked_until DATETIME(6) NOT NULL,
    INDEX (locked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "worker_locks");

}

if (isset($_POST['insert_demo'])) {
    // 2. Vloží demo dáta
    if (!defined('KEYS_DIR')) define('KEYS_DIR', $config['paths']['keys']);

    // --- vytvoření admin účtu se zahashovaným + zašifrovaným emailem ---
    // Pozn.: tento skript VYPOVÍDÁ plain heslo (jak požaduješ).

    $adminEmail = 'admin@example.com';
    $adminPassword = bin2hex(random_bytes(6)); // náhodné heslo

    // Argon2 parametry (stejné jako jinde)
    $options = [
        'memory_cost' => (int)($_ENV['ARGON_MEMORY_KIB'] ?? (1 << 17)),
        'time_cost'   => (int)($_ENV['ARGON_TIME_COST'] ?? 4),
        'threads'     => (int)($_ENV['ARGON_THREADS'] ?? 2),
    ];

    try {
        // keysDir z configu
        $keysDir = KEYS_DIR;

        // ----- získat pepper (KeyManager preferred, fallback env) -----
        $pepRaw = null;
        $hesloKeyVer = null;
        if (class_exists(KeyManager::class, true)) {
            try {
                $pinfo = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', $keysDir, 'password_pepper', false, 32);
                if (!empty($pinfo['raw']) && is_string($pinfo['raw']) && strlen($pinfo['raw']) === 32) {
                    $pepRaw = $pinfo['raw'];
                    $hesloKeyVer = $pinfo['version'] ?? null;
                }
            } catch (Throwable $e) {
                // silent fallback to env
                $pepRaw = null;
                $hesloKeyVer = null;
            }
        }

        if ($pepRaw === null) {
            $b64 = $_ENV['PASSWORD_PEPPER'] ?? '';
            if ($b64 !== '') {
                $decoded = base64_decode($b64, true);
                if ($decoded !== false && strlen($decoded) === 32) {
                    $pepRaw = $decoded;
                    $hesloKeyVer = 'env';
                }
            }
        }

        // ----- password: HMAC-SHA256 with pepper (binary) if available, potom Argon2 hash -----
        $pwInput = $pepRaw !== null ? hash_hmac('sha256', $adminPassword, $pepRaw, true) : $adminPassword;
        $adminHash = password_hash($pwInput, PASSWORD_ARGON2ID, $options);
        if ($adminHash === false) {
            throw new RuntimeException('password_hash failed');
        }
        $pwInfo = password_get_info($adminHash);
        $pwAlgo = $pwInfo['algoName'] ?? null;

        // uvolnit paměť pepperu pokud to KeyManager podporuje
        if ($pepRaw !== null && class_exists(KeyManager::class, true)) {
            try { KeyManager::memzero($pepRaw); } catch (Throwable $_) {}
        }

        // ----- email normalizace + derive HMAC hash (binary) -----
        $emailNorm = strtolower(trim($adminEmail));
        $emailHashBin = null;
        $emailHashVer = null;
        if (class_exists(KeyManager::class, true)) {
            try {
                $h = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', $keysDir, 'email_hash_key', $emailNorm);
                $emailHashBin = $h['hash'] ?? null;
                $emailHashVer = $h['version'] ?? null;
            } catch (Throwable $e) {
                $emailHashBin = null;
                $emailHashVer = null;
            }
        }

        // ----- optional email encryption (Crypto) -----
        $emailEncPayload = null;
        $emailEncKeyVer = null;
        if (class_exists(Crypto::class, true) && class_exists(KeyManager::class, true)) {
            try {
                Crypto::initFromKeyManager($keysDir);
                $emailEncPayload = Crypto::encrypt($emailNorm, 'binary'); // versioned binary payload
                $info = KeyManager::locateLatestKeyFile($keysDir, 'email_key');
                $emailEncKeyVer = $info['version'] ?? null;
                Crypto::clearKey();
            } catch (Throwable $e) {
                $emailEncPayload = null;
                $emailEncKeyVer = null;
            }
        }

        // ----- DB INSERT (transaction) -----
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO pouzivatelia
            (email_enc, email_key_version, email_hash, email_hash_key_version, heslo_hash, heslo_algo, heslo_key_version, actor_type, is_active, must_change_password, created_at, updated_at)
            VALUES (:email_enc, :enc_ver, :email_hash, :hash_ver, :hash, :algo, :heslo_key_ver, 'admin', TRUE, TRUE, NOW(), NOW())");

        // bind email_enc
        if ($emailEncPayload !== null) {
            $stmt->bindValue(':email_enc', $emailEncPayload, PDO::PARAM_LOB);
        } else {
            $stmt->bindValue(':email_enc', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':enc_ver', $emailEncKeyVer, $emailEncKeyVer !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        // bind email_hash
        if ($emailHashBin !== null) {
            $stmt->bindValue(':email_hash', $emailHashBin, PDO::PARAM_LOB);
        } else {
            $stmt->bindValue(':email_hash', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':hash_ver', $emailHashVer, $emailHashVer !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        // bind password info
        $stmt->bindValue(':hash', $adminHash, PDO::PARAM_STR);
        $stmt->bindValue(':algo', $pwAlgo, $pwAlgo !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':heslo_key_ver', $hesloKeyVer, $hesloKeyVer !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->execute();
        $adminId = $pdo->lastInsertId();

        $pdo->commit();

        // výstup s plain heslem (podle tvého požadavku)
        echo "<p>Admin účet vytvořen (id={$adminId}). Heslo: " . htmlspecialchars($adminPassword, ENT_QUOTES | ENT_SUBSTITUTE) . "</p>";
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
        echo "<p>Chyba při vytváření admin účtu: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Seed demo autorov (idempotentné, ON DUPLICATE KEY UPDATE podľa slug)
    $demoAuthors = [
        [
            'meno' => 'Ján Novák',
            'slug' => 'jan-novak',
            'bio'  => 'Autor beletrie a povídek, zameraný na sociálne témy.',
            'story'=> 'Narodil sa v Bratislave, pôsobil ako redaktor a neskôr debutoval románom.',
            'books_count'   => 3,
            'ratings_count' => 10,
            'rating_sum'    => 42, // avg 4.20
            'last_rating_offset_days' => 2,
        ],
        [
            'meno' => 'Lucia Horváthová',
            'slug' => 'lucia-horvathova',
            'bio'  => 'Poetka a autorka literatúry pre mládež.',
            'story'=> 'Vyrastala na východe, autorka viacerých básnických zbierok.',
            'books_count'   => 5,
            'ratings_count' => 24,
            'rating_sum'    => 96, // avg 4.00
            'last_rating_offset_days' => 10,
        ],
        [
            'meno' => 'Peter Kováč',
            'slug' => 'peter-kovac',
            'bio'  => 'Autor detektívnych románov a krimi bestsellerov.',
            'story'=> 'Predtým žurnalista, dnes píše napäté krimi príbehy.',
            'books_count'   => 7,
            'ratings_count' => 55,
            'rating_sum'    => 233, // avg 4.24
            'last_rating_offset_days' => 1,
        ],
        [
            'meno' => 'Martina Čechová',
            'slug' => 'martina-cechova',
            'bio'  => 'Publicistka a autorka populárno-náučných kníh.',
            'story'=> 'Študovala históriu, píše pre magazíny a knižné publikácie.',
            'books_count'   => 2,
            'ratings_count' => 4,
            'rating_sum'    => 15, // avg 3.75
            'last_rating_offset_days' => 30,
        ],
        [
            'meno' => 'Tomáš Rybár',
            'slug' => 'tomas-rybar',
            'bio'  => 'Mladý sci-fi autor so záujmom o AI a budúcnosť.',
            'story'=> 'Pochádza z Košíc, publikuje krátke sci-fi poviedky a e-knihy.',
            'books_count'   => 4,
            'ratings_count' => 12,
            'rating_sum'    => 50, // avg 4.17
            'last_rating_offset_days' => 5,
        ],
    ];

    try {
        $pdo->beginTransaction();

        $sql = "
        INSERT INTO authors (
            meno, slug, bio, story,
            books_count, ratings_count, rating_sum, avg_rating, last_rating_at,
            created_at, updated_at
        ) VALUES (
            :meno, :slug, :bio, :story,
            :books_count, :ratings_count, :rating_sum, :avg_rating, :last_rating_at,
            NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            meno = VALUES(meno),
            bio = VALUES(bio),
            story = VALUES(story),
            books_count = VALUES(books_count),
            ratings_count = VALUES(ratings_count),
            rating_sum = VALUES(rating_sum),
            avg_rating = VALUES(avg_rating),
            last_rating_at = VALUES(last_rating_at),
            updated_at = NOW()
        ";
        $stmt = $pdo->prepare($sql);

        $selectId = $pdo->prepare("SELECT id FROM authors WHERE slug = :slug LIMIT 1");

        $firstAuthorId = null;
        foreach ($demoAuthors as $i => $a) {
            $ratings_count = (int)$a['ratings_count'];
            $rating_sum = (int)$a['rating_sum'];
            $avg = $ratings_count > 0 ? round($rating_sum / $ratings_count, 2) : null;

            // last_rating_at demo: NOW() - offset days, alebo NULL ak žiadne hodnotenia
            if ($ratings_count > 0 && !empty($a['last_rating_offset_days'])) {
                $offset = (int)$a['last_rating_offset_days'];
                $lastRatingAt = (new DateTimeImmutable("now"))->sub(new DateInterval("P{$offset}D"))->format('Y-m-d H:i:s');
            } else {
                $lastRatingAt = null;
            }

            $stmt->execute([
                ':meno' => $a['meno'],
                ':slug' => $a['slug'],
                ':bio'  => $a['bio'],
                ':story'=> $a['story'],
                ':books_count'   => $a['books_count'],
                ':ratings_count' => $ratings_count,
                ':rating_sum'    => $rating_sum,
                ':avg_rating'    => $avg,
                ':last_rating_at'=> $lastRatingAt,
            ]);

            // vyzvedni id (nový alebo existujúci)
            $selectId->execute([':slug' => $a['slug']]);
            $id = (int)$selectId->fetchColumn();
            if ($i === 0) $firstAuthorId = $id;
        }

        $pdo->commit();

        // kompatibilita so starým kódom (napr. $authorId očekávané po vložení)
        $authorId = $firstAuthorId;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // zachovej tvoje executeQuery/logger konvence, nebo aspoň error_log
        error_log('Seed authors failed: ' . $e->getMessage());
        throw $e;
    }

    // -------------------- Demo: 5 autorov, 3 kategórie, 5 kníh, assets, reviews --------------------
    try {
        if (!($pdo instanceof PDO)) throw new RuntimeException('PDO not available for demo inserts.');

        $pdo->beginTransaction();

        // --- Demo authors (idempotent: podle slug) ---
        $demoAuthors = [
            ['meno'=>'Janko Kráľ','slug'=>'janko-kral','bio'=>'Slovenský spisovateľ','story'=>'Životopisné informácie o Jankovi Kráľovi.'],
            ['meno'=>'Mária Novotná','slug'=>'maria-novotna','bio'=>'Súčasná autorka poviedok','story'=>'Krátky životopis Márie Novotnej.'],
            ['meno'=>'Peter Horváth','slug'=>'peter-horvath','bio'=>'Autor detektívok','story'=>'Peter píše napínavé detektívky s lokálnym nádychom.'],
            ['meno'=>'Anna Bielik','slug'=>'anna-bielik','bio'=>'Fantasy autorka','story'=>'Anna vytvára bohaté fantasy svety plné detailov.'],
            ['meno'=>'Lukáš Šimko','slug'=>'lukas-simko','bio'=>'Non-fiction a eseje','story'=>'Eseje o kultúre a spoločnosti.'],
        ];
        $authorIds = [];
        $stExist = $pdo->prepare("SELECT id FROM authors WHERE slug = :slug LIMIT 1");
        $stIns = $pdo->prepare("INSERT INTO authors (meno, slug, bio, story, created_at, updated_at) VALUES (:meno, :slug, :bio, :story, NOW(), NOW())");
        foreach ($demoAuthors as $a) {
            $stExist->execute([':slug'=>$a['slug']]);
            $id = (int)$stExist->fetchColumn();
            if ($id === 0) {
                $stIns->execute([':meno'=>$a['meno'], ':slug'=>$a['slug'], ':bio'=>$a['bio'], ':story'=>$a['story']]);
                $id = (int)$pdo->lastInsertId();
            }
            $authorIds[$a['slug']] = $id;
        }

        // --- Demo categories ---
        $demoCats = [
            ['nazov'=>'Beletria','slug'=>'beletria'],
            ['nazov'=>'Detektívky','slug'=>'detektivky'],
            ['nazov'=>'Eseje & Non-fiction','slug'=>'non-fiction'],
        ];
        $catIds = [];
        $stExistCat = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug LIMIT 1");
        $stInsCat = $pdo->prepare("INSERT INTO categories (nazov, slug, created_at, updated_at) VALUES (:nazov, :slug, NOW(), NOW())");
        foreach ($demoCats as $c) {
            $stExistCat->execute([':slug'=>$c['slug']]);
            $id = (int)$stExistCat->fetchColumn();
            if ($id === 0) {
                $stInsCat->execute([':nazov'=>$c['nazov'], ':slug'=>$c['slug']]);
                $id = (int)$pdo->lastInsertId();
            }
            $catIds[$c['slug']] = $id;
        }

        // --- Demo books (5) ---
        $demoBooks = [
            [
                'title'=>'Príbeh Janka', 'slug'=>'pribeh-janka',
                'short'=>'Knižka o živote Janka.', 'full'=>'Plný obsah: historický príbeh, anekdoty, reflexie.',
                'price'=>9.99, 'currency'=>'EUR', 'author_slug'=>'janko-kral', 'category_slug'=>'beletria',
                'isbn'=>'978-1-11111-111-1','language'=>'sk','pages'=>180,'publisher'=>'Slovenské vydavateľstvo','published_at'=>'2022-06-01','sku'=>'JK-001','stock'=>50
            ],
            [
                'title'=>'Nočné stopy', 'slug'=>'nocne-stopy',
                'short'=>'Krátke detektívne poviedky.','full'=>'Séria krátkych príbehov so zvratom na konci každej kapitoly.',
                'price'=>12.50, 'currency'=>'EUR', 'author_slug'=>'peter-horvath', 'category_slug'=>'detektivky',
                'isbn'=>'978-1-22222-222-2','language'=>'sk','pages'=>240,'publisher'=>'Krimi Press','published_at'=>'2023-02-20','sku'=>'PH-002','stock'=>30
            ],
            [
                'title'=>'Snové ríše', 'slug'=>'snove-riese',
                'short'=>'Fantasy pre mladých aj dospelých.','full'=>'Epické putovanie cez sveta snov a mýtov.',
                'price'=>18.00, 'currency'=>'EUR', 'author_slug'=>'anna-bielik', 'category_slug'=>'beletria',
                'isbn'=>'978-1-33333-333-3','language'=>'sk','pages'=>420,'publisher'=>'FantasyHouse','published_at'=>'2021-11-11','sku'=>'AB-003','stock'=>20
            ],
            [
                'title'=>'Eseje o kultúre', 'slug'=>'eseje-o-kulture',
                'short'=>'Výber esejí o spoločnosti.','full'=>'Hloubkové úvahy o trendoch a kultúre 21. storočia.',
                'price'=>14.99, 'currency'=>'EUR', 'author_slug'=>'lukas-simko', 'category_slug'=>'non-fiction',
                'isbn'=>'978-1-44444-444-4','language'=>'sk','pages'=>160,'publisher'=>'Akademia','published_at'=>'2020-09-30','sku'=>'LS-004','stock'=>40
            ],
            [
                'title'=>'Poviedky Márie', 'slug'=>'poviedky-marie',
                'short'=>'Súbor moderných poviedok.','full'=>'Emotívne a precízne napísané príbehy o bežnom živote.',
                'price'=>11.49, 'currency'=>'EUR', 'author_slug'=>'maria-novotna', 'category_slug'=>'beletria',
                'isbn'=>'978-1-55555-555-5','language'=>'sk','pages'=>200,'publisher'=>'Novotná Press','published_at'=>'2024-03-15','sku'=>'MN-005','stock'=>60
            ],
        ];

        $stExistBook = $pdo->prepare("SELECT id FROM books WHERE slug = :slug LIMIT 1");
        $stInsBook = $pdo->prepare("
            INSERT INTO books
                (title, slug, short_description, full_description, price, currency, author_id, main_category_id,
                isbn, language, pages, publisher, published_at, sku, is_active, is_available, stock_quantity, created_at, updated_at)
            VALUES
                (:title, :slug, :short, :full, :price, :currency, :author_id, :main_category_id,
                :isbn, :language, :pages, :publisher, :published_at, :sku, 1, 1, :stock, NOW(), NOW())
        ");

        $bookIds = [];
        foreach ($demoBooks as $b) {
            $stExistBook->execute([':slug'=>$b['slug']]);
            $id = (int)$stExistBook->fetchColumn();
            if ($id === 0) {
                $author_id = $authorIds[$b['author_slug']] ?? null;
                $cat_id = $catIds[$b['category_slug']] ?? null;
                if (empty($author_id) || empty($cat_id)) {
                    throw new RuntimeException('Missing author or category for demo book ' . $b['slug']);
                }
                $stInsBook->execute([
                    ':title'=>$b['title'], ':slug'=>$b['slug'], ':short'=>$b['short'], ':full'=>$b['full'],
                    ':price'=>$b['price'], ':currency'=>$b['currency'], ':author_id'=>$author_id, ':main_category_id'=>$cat_id,
                    ':isbn'=>$b['isbn'], ':language'=>$b['language'], ':pages'=>$b['pages'], ':publisher'=>$b['publisher'],
                    ':published_at'=>$b['published_at'], ':sku'=>$b['sku'], ':stock'=>$b['stock']
                ]);
                $id = (int)$pdo->lastInsertId();
            }
            $bookIds[$b['slug']] = $id;
        }

        // --- Assign books to categories (many-to-many) if not exist ---
        $stExistBC = $pdo->prepare("SELECT 1 FROM book_categories WHERE book_id = :bid AND category_id = :cid LIMIT 1");
        $stInsBC = $pdo->prepare("INSERT INTO book_categories (book_id, category_id) VALUES (:bid, :cid)");
        foreach ($bookIds as $slug => $bid) {
            $mainCid = $demoBooks[array_search($slug, array_column($demoBooks,'slug'))]['category_slug'] ?? null;
            $cid = $catIds[$mainCid] ?? null;
            if ($cid) {
                $stExistBC->execute([':bid'=>$bid, ':cid'=>$cid]);
                if (!$stExistBC->fetchColumn()) {
                    $stInsBC->execute([':bid'=>$bid, ':cid'=>$cid]);
                }
            }
        }

        // --- Add a simple cover asset for each book if missing ---
        $stExistAsset = $pdo->prepare("SELECT 1 FROM book_assets WHERE book_id = :bid AND asset_type = 'cover' LIMIT 1");
        $stInsAsset = $pdo->prepare("
            INSERT INTO book_assets (book_id, asset_type, filename, mime_type, size_bytes, storage_path, content_hash, download_filename, is_encrypted, created_at)
            VALUES (:bid, 'cover', :fn, 'image/jpeg', :size, :path, :hash, :dl, 0, NOW())
        ");
        foreach ($bookIds as $slug => $bid) {
            $filename = "book1.png";
            $stExistAsset->execute([':bid'=>$bid]);
            if (!$stExistAsset->fetchColumn()) {
                $path = "storage/books/covers/{$filename}";
                $hash = hash('sha256', $filename);
                $stInsAsset->execute([':bid'=>$bid, ':fn'=>$filename, ':size'=>12345, ':path'=>$path, ':hash'=>$hash, ':dl'=>$filename]);
            }
        }

        // --- Demo reviews (idempotent by identical text/rating/book) ---
        $stExistReview = $pdo->prepare("SELECT id FROM reviews WHERE book_id = :bid AND rating = :rating AND review_text = :text LIMIT 1");
        $stInsReview = $pdo->prepare("INSERT INTO reviews (book_id, user_id, rating, review_text, created_at) VALUES (:bid, NULL, :rating, :text, NOW())");

        // some sample reviews for a few books
        $sampleReviews = [
            ['slug'=>'pribeh-janka','rating'=>5,'text'=>'Nádherný príbeh, veľa emócií.'],
            ['slug'=>'pribeh-janka','rating'=>4,'text'=>'Páčilo sa mi, niekde pomalé tempo.'],
            ['slug'=>'nocne-stopy','rating'=>5,'text'=>'Napínavé! Autor vie udržať čitateľa.'],
            ['slug'=>'snove-riese','rating'=>4,'text'=>'Vizuálne bohaté, niekedy rozvláčne.'],
            ['slug'=>'eseje-o-kulture','rating'=>4,'text'=>'Podnetné myšlienky, dobre spracované.'],
            ['slug'=>'poviedky-marie','rating'=>5,'text'=>'Silné a emotívne poviedky.'],
        ];
        foreach ($sampleReviews as $r) {
            $bid = $bookIds[$r['slug']] ?? null;
            if (!$bid) continue;
            $stExistReview->execute([':bid'=>$bid, ':rating'=>$r['rating'], ':text'=>$r['text']]);
            if (!$stExistReview->fetchColumn()) {
                $stInsReview->execute([':bid'=>$bid, ':rating'=>$r['rating'], ':text'=>$r['text']]);
            }
        }

        // --- Update authors aggregates (books_count, ratings_count, rating_sum, avg_rating, last_rating_at) ---
        // This computes aggregates from current books+reviews and writes into authors table.
        $aggSql = "
            UPDATE authors a
            LEFT JOIN (
                SELECT b.author_id AS author_id,
                    COUNT(DISTINCT b.id) AS books_count,
                    COUNT(r.id) AS ratings_count,
                    COALESCE(SUM(r.rating),0) AS rating_sum,
                    CASE WHEN COUNT(r.id)>0 THEN ROUND(SUM(r.rating)/COUNT(r.id),2) ELSE NULL END AS avg_rating,
                    MAX(r.created_at) AS last_rating_at
                FROM books b
                LEFT JOIN reviews r ON r.book_id = b.id
                GROUP BY b.author_id
            ) ag ON ag.author_id = a.id
            SET a.books_count = COALESCE(ag.books_count,0),
                a.ratings_count = COALESCE(ag.ratings_count,0),
                a.rating_sum = COALESCE(ag.rating_sum,0),
                a.avg_rating = ag.avg_rating,
                a.last_rating_at = ag.last_rating_at,
                a.updated_at = NOW()
        ";
        $pdo->exec($aggSql);

        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
        error_log('[demo-insert] ' . $e->getMessage());
        throw $e;
    }

    // Referenčné údaje: krajiny a DPH
    executeQuery($pdo, "INSERT INTO countries (iso2, nazov) VALUES ('SK','Slovensko')", "Štát (SK)");
    executeQuery($pdo, "INSERT INTO tax_rates (country_iso2, category, rate, valid_from)
        VALUES ('SK','ebook', 10.00, '2020-01-01')", "DPH (ebook)");
    executeQuery($pdo, "INSERT INTO tax_rates (country_iso2, category, rate, valid_from)
        VALUES ('SK','physical', 20.00, '2020-01-01')", "DPH (physical)");
}
    // Po stlačení tlačidla "presun"
    if (isset($_POST['move_script'])) {
        $current = __FILE__;
        $destination = $PROJECT_ROOT . '/secure/initdb.php';
        if (@rename($current, $destination)) {
            echo "<p>Skript bol presunutý do adresára /secure/.</p>";
            exit;
        } else {
            echo "<p>Presun sa nepodaril – skontrolujte práva na adresár /secure/.</p>";
        }
    }
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Inicializácia databázy</title>
</head>
<body>
    <h1>Inicializácia databázy e-shopu</h1>
    <form method="post">
        <button type="submit" name="create_db">Vytvoriť databázu</button>
    </form>
    <form method="post">
        <button type="submit" name="insert_demo">Nahrať demo</button>
    </form>
    <form method="post" onsubmit="return confirm('Naozaj chcete presunúť initdb.php do /secure/?');">
        <button type="submit" name="move_script">Presunúť do /secure/</button>
    </form>
</body>
</html>