<?php
// Pripojenie k databáze cez PDO a existujúceho konfiguračného súboru
require_once __DIR__ . '/../../../db/config/config.php';

try {
    // Ak konfiguračný súbor nespecifikuje spojenie, vytvoríme PDO manuálne (predpokladáme premenné z configu).
    if (!isset($pdo)) {
        $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Nepodařilo sa pripojiť k databáze: " . $e->getMessage());
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
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        heslo_hash VARCHAR(255) NOT NULL,
        heslo_algo VARCHAR(50) DEFAULT NULL,
        is_active BOOLEAN NOT NULL DEFAULT FALSE,
        must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
        last_login_at DATETIME NULL,
        last_login_ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        actor_type ENUM('zakaznik','admin') NOT NULL DEFAULT 'zakaznik'
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "pouzivatelia");

    // Tabuľka user_profiles
    $sql = "CREATE TABLE IF NOT EXISTS user_profiles (
        user_id INT PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL DEFAULT '',
        phone_enc VARBINARY(255) NULL,
        address_enc VARBINARY(255) NULL,
        company_name_enc VARBINARY(255) NULL,
        street_enc VARBINARY(255) NULL,
        city_enc VARBINARY(255) NULL,
        zip_enc VARBINARY(255) NULL,
        tax_id_enc VARBINARY(255) NULL,
        vat_id_enc VARBINARY(255) NULL,
        country_code CHAR(2) NULL,
        encryption_algo VARCHAR(50) NULL,
        key_version INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "user_profiles");

    // Tabuľka user_identities
    $sql = "CREATE TABLE IF NOT EXISTS user_identities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        provider VARCHAR(100) NOT NULL,
        provider_user_id VARCHAR(255) NOT NULL,
        email_verified BOOLEAN NOT NULL DEFAULT FALSE,
        raw_profile JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE(provider, provider_user_id),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "user_identities");

    // Tabuľka roles
    $sql = "CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazov VARCHAR(100) NOT NULL UNIQUE,
        popis TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "roles");

    // Tabuľka permissions
    $sql = "CREATE TABLE IF NOT EXISTS permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazov VARCHAR(100) NOT NULL UNIQUE,
        popis TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "permissions");

    // Tabuľka user_roles
    $sql = "CREATE TABLE IF NOT EXISTS user_roles (
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, role_id),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "user_roles");

    // Tabuľka role_permissions
    $sql = "CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INT NOT NULL,
        permission_id INT NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "role_permissions");

    // Tabuľka two_factor
    $sql = "CREATE TABLE IF NOT EXISTS two_factor (
        user_id INT NOT NULL,
        method VARCHAR(50) NOT NULL,
        secret VARBINARY(255) NULL,
        enabled BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        PRIMARY KEY (user_id, method),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "two_factor");

    // Tabuľka sessions
    $sql = "CREATE TABLE IF NOT EXISTS session_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(128) NOT NULL,
        event VARCHAR(64) NOT NULL,
        user_id BIGINT NULL,
        ip_hash VARCHAR(128) NULL,
        ip_hash_key VARCHAR(64) NULL,       -- verze klíče / key-id (optional)
        ua VARCHAR(255) NULL,
        meta_json JSON NULL,
        outcome VARCHAR(32) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        -- Indexy
        INDEX idx_session_id (session_id),
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at),
        INDEX idx_event (event),
        INDEX idx_ip_hash (ip_hash),
        INDEX idx_ip_hash_key (ip_hash_key),
        CONSTRAINT fk_session_audit_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    createTable($pdo, $sql, "session_audit");

    // Tabuľka auth_events
    $sql = "CREATE TABLE IF NOT EXISTS auth_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        type ENUM('login_success','login_failure','logout','password_reset','lockout') NOT NULL,
        ip_hash VARCHAR(128) NULL,
        ip_hash_key VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        meta JSON NULL,
        meta_email VARCHAR(255) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email'))) PERSISTENT,
        INDEX idx_meta_email (meta_email),
        INDEX idx_ver_user (user_id),
        INDEX idx_ver_time (occurred_at),
        INDEX idx_ver_type_time (type, occurred_at),
        INDEX idx_ip_hash (ip_hash),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "auth_events");

    // Tabuľka register_events
    $sql = "CREATE TABLE IF NOT EXISTS register_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        type ENUM('register_success','register_failure') NOT NULL,
        ip_hash VARCHAR(128) NULL,
        ip_hash_key VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        meta JSON NULL,
        INDEX idx_reg_user (user_id),
        INDEX idx_reg_time (occurred_at),
        INDEX idx_reg_type_time (type, occurred_at),
        INDEX idx_reg_ip (ip_hash),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "register_events");

    // Tabuľka verify_events
    $sql = "CREATE TABLE IF NOT EXISTS verify_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        type ENUM('verify_success','verify_failure') NOT NULL,
        ip_hash VARCHAR(128) NULL,
        ip_hash_key VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        meta JSON NULL,
        INDEX idx_ver_user (user_id),
        INDEX idx_ver_time (occurred_at),
        INDEX idx_ver_type_time (type, occurred_at),
        INDEX idx_ver_ip (ip_hash),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "verify_events");

    // Tabuľka system_error (odporúčané: binárne IP pre IPv4/IPv6)
    $sql = "CREATE TABLE IF NOT EXISTS system_error (
        id INT AUTO_INCREMENT PRIMARY KEY,
        level ENUM('notice','warning','error','critical') NOT NULL,
        message TEXT NOT NULL,
        exception_class VARCHAR(255) NULL,
        file VARCHAR(1024) NULL,
        line INT UNSIGNED NULL,
        stack_trace MEDIUMTEXT NULL,
        token VARCHAR(255) NULL,
        context JSON NULL,
        fingerprint VARCHAR(64) NULL,
        occurrences INT UNSIGNED NOT NULL DEFAULT 1,
        user_id INT NULL,
        ip_hash VARCHAR(128) NULL,
        ip_hash_key VARCHAR(64) NULL,
        ip_text VARCHAR(45) NULL, -- optional, human-readable IP
        user_agent VARCHAR(255) NULL,
        url VARCHAR(2048) NULL,
        method VARCHAR(10) NULL,
        http_status SMALLINT UNSIGNED NULL,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        resolved_by INT NULL,
        resolved_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_err_level (level),
        INDEX idx_err_time (created_at),
        INDEX idx_err_user (user_id),
        INDEX idx_err_fp (fingerprint),
        INDEX idx_err_ip (ip_hash),
        INDEX idx_err_resolved (resolved),
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL,
        FOREIGN KEY (resolved_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "system_error");

    // Tabuľka user_consents
    $sql = "CREATE TABLE IF NOT EXISTS user_consents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        consent_type VARCHAR(50) NOT NULL,
        version VARCHAR(50) NOT NULL,
        granted BOOLEAN NOT NULL,
        granted_at DATETIME NOT NULL,
        source VARCHAR(100) NULL,
        meta JSON NULL,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "user_consents");

    // Tabuľka authors
    $sql = "CREATE TABLE IF NOT EXISTS authors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meno VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        bio TEXT NULL,
        foto VARCHAR(255) NULL,
        story LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "authors");

    // Tabuľka categories
    $sql = "CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazov VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        parent_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "categories");

    // Tabuľka books
    $sql = "CREATE TABLE IF NOT EXISTS books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        description TEXT NULL,
        price DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        author_id INT NOT NULL,
        main_category_id INT NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        is_available BOOLEAN NOT NULL DEFAULT TRUE,
        stock_quantity INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE RESTRICT,
        FOREIGN KEY (main_category_id) REFERENCES categories(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "books");

    // Tabuľka book_assets
    $sql = "CREATE TABLE IF NOT EXISTS book_assets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        asset_type ENUM('cover','pdf','epub','mobi','sample','extra') NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        size_bytes BIGINT NOT NULL,
        storage_path TEXT NULL,
        content_hash VARCHAR(64) NULL,
        download_filename VARCHAR(255) NULL,
        is_encrypted BOOLEAN NOT NULL DEFAULT FALSE,
        encryption_algo VARCHAR(50) NULL,
        encryption_key_enc VARBINARY(255) NULL,
        encryption_iv VARBINARY(255) NULL,
        encryption_tag VARBINARY(255) NULL,
        key_version INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "book_assets");

    // Tabuľka book_categories
    $sql = "CREATE TABLE IF NOT EXISTS book_categories (
        book_id INT NOT NULL,
        category_id INT NOT NULL,
        PRIMARY KEY (book_id, category_id),
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "book_categories");

    // Tabuľka carts
    $sql = "CREATE TABLE IF NOT EXISTS carts (
        id CHAR(36) PRIMARY KEY,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "carts");

    // Tabuľka cart_items
    $sql = "CREATE TABLE IF NOT EXISTS cart_items (
        cart_id CHAR(36) NOT NULL,
        book_id INT NOT NULL,
        quantity INT NOT NULL,
        price_snapshot DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        PRIMARY KEY (cart_id, book_id),
        FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "cart_items");

    // Tabuľka orders
    $sql = "CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        status ENUM('pending','paid','failed','cancelled','refunded','completed') NOT NULL DEFAULT 'pending',
        bill_full_name VARCHAR(255) NULL,
        bill_company VARCHAR(255) NULL,
        bill_street VARCHAR(255) NULL,
        bill_city VARCHAR(100) NULL,
        bill_zip VARCHAR(20) NULL,
        bill_country_code CHAR(2) NULL,
        bill_tax_id VARCHAR(50) NULL,
        bill_vat_id VARCHAR(50) NULL,
        currency CHAR(3) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        tax_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "orders");

    // Tabuľka order_items
    $sql = "CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        book_id INT NOT NULL,
        title_snapshot VARCHAR(255) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL,
        tax_rate DECIMAL(5,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "order_items");

    // Tabuľka order_item_downloads
    $sql = "CREATE TABLE IF NOT EXISTS order_item_downloads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        book_id INT NOT NULL,
        asset_id INT NOT NULL,
        download_token VARCHAR(255) NOT NULL,
        max_uses INT NOT NULL,
        used INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        last_ip VARCHAR(45) NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
        FOREIGN KEY (asset_id) REFERENCES book_assets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "order_item_downloads");

    // Tabuľka invoices
    $sql = "CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NULL,
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
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "invoices");

    // Tabuľka invoice_items
    $sql = "CREATE TABLE IF NOT EXISTS invoice_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        line_no INT NOT NULL,
        description TEXT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL,
        tax_rate DECIMAL(5,2) NOT NULL,
        tax_amount DECIMAL(10,2) NOT NULL,
        line_total DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "invoice_items");

    // Tabuľka payments
    $sql = "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NULL,
        gateway VARCHAR(100) NOT NULL,
        transaction_id VARCHAR(100) NOT NULL,
        status ENUM('pending','authorized','paid','failed','refunded') NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        details JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE(transaction_id),
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "payments");

    // Tabuľka payment_logs
    $sql = "CREATE TABLE IF NOT EXISTS payment_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NOT NULL,
        log_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        message TEXT NOT NULL,
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "payment_logs");

    // Tabuľka refunds
    $sql = "CREATE TABLE IF NOT EXISTS refunds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL,
        reason TEXT NULL,
        status VARCHAR(50) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        details JSON NULL,
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "refunds");

    // Tabuľka coupons
    $sql = "CREATE TABLE IF NOT EXISTS coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
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
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "coupons");

    // Tabuľka coupon_redemptions
    $sql = "CREATE TABLE IF NOT EXISTS coupon_redemptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        coupon_id INT NOT NULL,
        user_id INT NOT NULL,
        order_id INT NOT NULL,
        redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        amount_applied DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "coupon_redemptions");

    // Tabuľka countries
    $sql = "CREATE TABLE IF NOT EXISTS countries (
        iso2 CHAR(2) PRIMARY KEY,
        nazov VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "countries");

    // Tabuľka tax_rates
    $sql = "CREATE TABLE IF NOT EXISTS tax_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        country_iso2 CHAR(2) NOT NULL,
        category ENUM('ebook','physical') NOT NULL,
        rate DECIMAL(5,2) NOT NULL,
        valid_from DATE NOT NULL,
        valid_to DATE NULL,
        FOREIGN KEY (country_iso2) REFERENCES countries(iso2) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "tax_rates");

    // Tabuľka vat_validations
    $sql = "CREATE TABLE IF NOT EXISTS vat_validations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vat_id VARCHAR(50) NOT NULL,
        country_iso2 CHAR(2) NOT NULL,
        valid BOOLEAN NOT NULL,
        checked_at DATETIME NOT NULL,
        raw JSON NULL,
        FOREIGN KEY (country_iso2) REFERENCES countries(iso2) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "vat_validations");

    // Tabuľka app_settings
    $sql = "CREATE TABLE IF NOT EXISTS app_settings (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT NULL,
        type ENUM('string','int','bool','json','secret') NOT NULL,
        section VARCHAR(100) NULL,
        description TEXT NULL,
        protected BOOLEAN NOT NULL DEFAULT FALSE,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        FOREIGN KEY (updated_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "app_settings");

    // Tabuľka audit_log
    $sql = "CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(100) NOT NULL,
        record_id INT NOT NULL,
        changed_by INT NULL,
        change_type ENUM('INSERT','UPDATE','DELETE') NOT NULL,
        old_value JSON NULL,
        new_value JSON NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        request_id VARCHAR(100) NULL,
        FOREIGN KEY (changed_by) REFERENCES pouzivatelia(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "audit_log");

    // Tabuľka webhook_outbox
    $sql = "CREATE TABLE IF NOT EXISTS webhook_outbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(100) NOT NULL,
        payload JSON NULL,
        status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        retries INT NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "webhook_outbox");

    // Tabuľka email_verifications (required pre register/verify/resend)
    $sql = "CREATE TABLE IF NOT EXISTS email_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,           -- sha256 hex (alebo HMAC hex neskôr)
        key_version INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE,
        UNIQUE (user_id, token_hash),
        INDEX (expires_at)
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "email_verifications");

    // Index pre rýchle čistenie expirovaných tokenov
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ev_expires ON email_verifications (expires_at)");
    } catch (PDOException $e) {
        error_log('Index creation (email_verifications) : ' . $e->getMessage());
    }

    // ----------------------------
    // Notifications: safe in-place upgrade (add missing columns/indexes)
    // ----------------------------
    $table = 'notifications';

    // create table skeleton if not exists (minimal) — keeps existing if present
    $createIfMissing = "
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        channel ENUM('email','push') NOT NULL,
        template VARCHAR(100) NOT NULL,
        payload JSON NULL,
        status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        retries INT NOT NULL DEFAULT 0,
        max_retries INT NOT NULL DEFAULT 6,
        next_attempt_at DATETIME NULL,
        scheduled_at DATETIME NULL,
        sent_at DATETIME NULL,
        error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES pouzivatelia(id) ON DELETE CASCADE
    ) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
    ROW_FORMAT=DYNAMIC;
    ";
    createTable($pdo, $createIfMissing, "notifications");

    // Helper to check column existence
    function column_exists(PDO $pdo, string $table, string $column): bool {
        $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1");
        $q->execute([':t' => $table, ':c' => $column]);
        return (bool)$q->fetch();
    }

    // Add missing columns (non-destructive)
    $adds = [
        "ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME NULL",
        "ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL",
        "ADD COLUMN IF NOT EXISTS locked_by VARCHAR(100) NULL",
        "ADD COLUMN IF NOT EXISTS priority INT NOT NULL DEFAULT 0"
    ];

    foreach ($adds as $alterPart) {
        // MySQL does not support ADD COLUMN IF NOT EXISTS on some versions; check and alter when needed
        // Extract column name from string (simple parse)
        if (preg_match('/ADD COLUMN IF NOT EXISTS\s+([a-zA-Z0-9_]+)/', $alterPart, $m)) {
            $col = $m[1];
            if (!column_exists($pdo, $table, $col)) {
                try {
                    $pdo->exec("ALTER TABLE {$table} " . str_replace('IF NOT EXISTS ', '', $alterPart));
                } catch (PDOException $e) {
                    error_log('[initdb] alter notifications add column ' . $col . ' failed: ' . $e->getMessage());
                }
            }
        } else {
            // fallback attempt
            try {
                $pdo->exec("ALTER TABLE {$table} " . $alterPart);
            } catch (PDOException $e) {
                error_log('[initdb] alter notifications failed: ' . $e->getMessage());
            }
        }
    }

    // Ensure composite index exists (safe check)
    try {
        $indexName = 'idx_notifications_ready';
        $check = $pdo->prepare("
            SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :idx LIMIT 1
        ");
        $check->execute([':t' => $table, ':idx' => $indexName]);
        if (!$check->fetch()) {
            $pdo->exec("CREATE INDEX {$indexName} ON {$table} (status, next_attempt_at, scheduled_at, priority, created_at)");
        }
    } catch (PDOException $e) {
        try {
            $pdo->exec("CREATE INDEX idx_notifications_ready ON {$table} (status, next_attempt_at, scheduled_at, priority, created_at)");
        } catch (PDOException $ex) {
            error_log('Index creation (notifications) : ' . $ex->getMessage());
        }
    }

    // Tabuľka system_jobs
    $sql = "CREATE TABLE IF NOT EXISTS system_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_type VARCHAR(100) NOT NULL,
        payload JSON NULL,
        status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
        retries INT NOT NULL DEFAULT 0,
        scheduled_at DATETIME NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        error TEXT NULL
    ) ENGINE=InnoDB;";
    createTable($pdo, $sql, "system_jobs");

}

if (isset($_POST['insert_demo'])) {
    // 2. Vloží demo dáta

    // Používateľské roly
    executeQuery($pdo, "INSERT IGNORE INTO roles (nazov, popis) VALUES
        ('Majiteľ','Najvyššia rola'),
        ('Správca','Admin účtu'),
        ('Zákazník','Bežný používateľ')", "Role");

    // Admin účet s náhodným heslom
    $adminEmail = 'admin@example.com';
    $adminPassword = bin2hex(random_bytes(4)); // náhodné heslo
    $options = [
    'memory_cost' => 1<<17,  // 128 MB
    'time_cost' => 4,        // 4 průchody
    'threads' => 2            // paralelní vlákna
    ];
    $adminHash = password_hash($adminPassword, PASSWORD_ARGON2ID, $options);
    $pwInfo = password_get_info($adminHash);
    $pwAlgo = $pwInfo['algoName'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO pouzivatelia (email, heslo_hash, heslo_algo, actor_type, is_active, must_change_password, created_at, updated_at)
        VALUES (:email, :hash, :algo, 'admin', TRUE, TRUE, NOW(), NOW())");
    $stmt->execute([':email' => $adminEmail, ':hash' => $adminHash, ':algo' => $pwAlgo]);
    echo "<p>Admin heslo: $adminPassword</p>";

    // Priradenie roly Správca adminovi
    $adminId = $pdo->lastInsertId();
    $roleSpravca = $pdo->query("SELECT id FROM roles WHERE nazov = 'Majiteľ'")->fetchColumn();
    if ($roleSpravca) {
        executeQuery($pdo, "INSERT INTO user_roles (user_id, role_id) VALUES ($adminId, $roleSpravca)", "Priradenie roly Majiteľ adminovi");
    }

    // Ukážkový autor
    executeQuery($pdo, "INSERT INTO authors (meno, slug, bio, story, created_at, updated_at)
        VALUES ('Janko Kráľ', 'janko-kral', 'Slovenský spisovateľ', 'Životopisné informácie o Jankovi Kráľovi', NOW(), NOW())", "Autor");
    $authorId = $pdo->lastInsertId();

    // Ukážková kategória
    executeQuery($pdo, "INSERT INTO categories (nazov, slug, created_at, updated_at)
        VALUES ('Beletria','beletria', NOW(), NOW())", "Kategória");
    $categoryId = $pdo->lastInsertId();

    // Ukážková kniha
    executeQuery($pdo, "INSERT INTO books (title, slug, description, price, currency, author_id, main_category_id, is_active, is_available, stock_quantity, created_at, updated_at)
        VALUES ('Príkladová kniha', 'prikladova-kniha', 'Opis príkladovej knihy', 19.99, 'EUR', $authorId, $categoryId, TRUE, TRUE, 100, NOW(), NOW())", "Kniha");
    $bookId = $pdo->lastInsertId();

    // Priradenie kniha-kategória
    executeQuery($pdo, "INSERT INTO book_categories (book_id, category_id) VALUES ($bookId, $categoryId)", "Spojenie kniha-kategória");

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
        $destination = __DIR__ . '/db/initdb.php';
        if (@rename($current, $destination)) {
            echo "<p>Skript bol presunutý do adresára /db/.</p>";
            exit;
        } else {
            echo "<p>Presun sa nepodaril – skontrolujte práva na adresár /db/.</p>";
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
    <form method="post" onsubmit="return confirm('Naozaj chcete presunúť initdb.php do /db/?');">
        <button type="submit" name="move_script">Presunúť do /db/</button>
    </form>
</body>
</html>