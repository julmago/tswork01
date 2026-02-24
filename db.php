<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  global $config;
  $db = $config['db'];
  $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
  try {
    $configSource = $config['config_file'] ?? (__DIR__ . '/config.php');
    error_log(sprintf(
      '[%s] DB config source: %s | host=%s | db=%s | user=%s | pass_set=%s',
      date('c'),
      $configSource,
      $db['host'],
      $db['name'],
      $db['user'],
      $db['pass'] !== '' ? 'yes' : 'no'
    ));
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (PDOException $e) {
    error_log(sprintf('[%s] DB connection failed: %s', date('c'), $e->getMessage()));
    $debug = (bool)($config['debug'] ?? false);
    $message = 'No se pudo conectar con la base de datos. Verificá las credenciales en config.php o en tus variables de entorno.';
    if ($debug) {
      $message = sprintf('Error de base de datos: %s', $e->getMessage());
    }
    abort(500, $message);
  }
  return $pdo;
}

function ensure_product_suppliers_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();

  $columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM products");
  foreach ($st->fetchAll() as $row) {
    $columns[(string)$row['Field']] = true;
  }

  if (!isset($columns['sale_mode'])) {
    $pdo->exec("ALTER TABLE products ADD COLUMN sale_mode ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD' AFTER brand");
  }

  if (!isset($columns['sale_units_per_pack'])) {
    $pdo->exec("ALTER TABLE products ADD COLUMN sale_units_per_pack INT UNSIGNED NULL AFTER sale_mode");
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    default_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
    base_margin_percent DECIMAL(10,2) NOT NULL DEFAULT 0,
    import_dedupe_mode ENUM('LAST','FIRST','MIN','MAX','PREFER_PROMO') NOT NULL DEFAULT 'LAST',
    import_default_cost_type ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD',
    import_default_units_per_pack INT NULL,
    import_discount_default DECIMAL(10,2) NULL,
    import_sku_column VARCHAR(120) NULL,
    import_price_column VARCHAR(120) NULL,
    import_cost_type_column VARCHAR(120) NULL,
    import_units_per_pack_column VARCHAR(120) NULL,
    import_mapping_header_hash VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $supplier_columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM suppliers");
  foreach ($st->fetchAll() as $row) {
    $supplier_columns[(string)$row['Field']] = true;
  }

  if (!isset($supplier_columns['default_margin_percent'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN default_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER name");
  }

  if (!isset($supplier_columns['base_margin_percent'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN base_margin_percent DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER default_margin_percent");
    $pdo->exec("UPDATE suppliers SET base_margin_percent = default_margin_percent");
  }

  if (!isset($supplier_columns['import_dedupe_mode'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_dedupe_mode ENUM('LAST','FIRST','MIN','MAX','PREFER_PROMO') NOT NULL DEFAULT 'LAST' AFTER is_active");
  }

  if (!isset($supplier_columns['import_default_cost_type'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_default_cost_type ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD' AFTER import_dedupe_mode");
  }

  if (!isset($supplier_columns['import_default_units_per_pack'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_default_units_per_pack INT NULL AFTER import_default_cost_type");
  }

  if (!isset($supplier_columns['import_discount_default'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_discount_default DECIMAL(10,2) NULL AFTER import_default_units_per_pack");
  }

  if (!isset($supplier_columns['import_sku_column'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_sku_column VARCHAR(120) NULL AFTER import_discount_default");
  }

  if (!isset($supplier_columns['import_price_column'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_price_column VARCHAR(120) NULL AFTER import_sku_column");
  }

  if (!isset($supplier_columns['import_cost_type_column'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_cost_type_column VARCHAR(120) NULL AFTER import_price_column");
  }

  if (!isset($supplier_columns['import_units_per_pack_column'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_units_per_pack_column VARCHAR(120) NULL AFTER import_cost_type_column");
  }

  if (!isset($supplier_columns['import_mapping_header_hash'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN import_mapping_header_hash VARCHAR(64) NULL AFTER import_units_per_pack_column");
  }



  $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_cost_adjustments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    percent DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL,
    affected_rows INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_supplier_cost_adjustments_supplier (supplier_id, created_at),
    KEY idx_supplier_cost_adjustments_created_by (created_by)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(120) NOT NULL DEFAULT '',
    cost_type ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD',
    units_per_pack INT UNSIGNED NULL,
    supplier_cost DECIMAL(10,2) NULL,
    cost_unitario DECIMAL(10,4) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_ps_product (product_id),
    KEY idx_ps_supplier (supplier_id),
    KEY idx_ps_active (product_id, is_active),
    CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $product_supplier_columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM product_suppliers");
  foreach ($st->fetchAll() as $row) {
    $product_supplier_columns[(string)$row['Field']] = true;
  }

  if (!isset($product_supplier_columns['supplier_cost'])) {
    $pdo->exec("ALTER TABLE product_suppliers ADD COLUMN supplier_cost DECIMAL(10,2) NULL AFTER units_per_pack");
  }

  if (!isset($product_supplier_columns['cost_unitario'])) {
    $pdo->exec("ALTER TABLE product_suppliers ADD COLUMN cost_unitario DECIMAL(10,4) NULL AFTER supplier_cost");
  }

  $pdo->exec("UPDATE product_suppliers ps
    LEFT JOIN suppliers s ON s.id = ps.supplier_id
    LEFT JOIN products p ON p.id = ps.product_id
    SET ps.cost_unitario = CASE
      WHEN ps.supplier_cost IS NULL THEN NULL
      WHEN ps.cost_type = 'PACK' THEN ROUND(ps.supplier_cost / COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1), 4)
      ELSE ps.supplier_cost
    END
    WHERE ps.cost_unitario IS NULL");

  $productSupplierUniqueExists = false;
  $st = $pdo->query("SHOW INDEX FROM product_suppliers WHERE Key_name = 'uq_product_supplier_link'");
  if ($st->fetch()) {
    $productSupplierUniqueExists = true;
  }

  if (!$productSupplierUniqueExists) {
    $pdo->exec("DELETE ps_old
      FROM product_suppliers ps_old
      INNER JOIN product_suppliers ps_newer
        ON ps_old.product_id = ps_newer.product_id
       AND ps_old.supplier_id = ps_newer.supplier_id
       AND ps_old.id < ps_newer.id");

    $pdo->exec("ALTER TABLE product_suppliers
      ADD CONSTRAINT uq_product_supplier_link UNIQUE (product_id, supplier_id)");
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_import_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NULL,
    source_type ENUM('CSV','XLSX','TXT','PASTE') NOT NULL,
    extra_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0,
    supplier_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0,
    selected_sku_column VARCHAR(120) NULL,
    selected_price_column VARCHAR(120) NULL,
    selected_cost_type_column VARCHAR(120) NULL,
    selected_units_per_pack_column VARCHAR(120) NULL,
    dedupe_mode VARCHAR(30) NULL,
    mapping_header_hash VARCHAR(64) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL,
    notes VARCHAR(255) NULL,
    INDEX idx_supplier_import_runs_supplier (supplier_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_import_rows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    raw_price DECIMAL(10,2) NULL,
    price_column_name VARCHAR(120) NULL,
    discount_applied_percent DECIMAL(10,2) NULL,
    raw_cost_type ENUM('UNIDAD','PACK') NULL,
    raw_units_per_pack INT NULL,
    normalized_unit_cost DECIMAL(10,2) NULL,
    cost_calc_detail VARCHAR(255) NULL,
    matched_product_supplier_id INT UNSIGNED NULL,
    matched_product_id INT UNSIGNED NULL,
    status ENUM('MATCHED','UNMATCHED','DUPLICATE_SKU','INVALID') NOT NULL DEFAULT 'UNMATCHED',
    chosen_by_rule TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier_import_rows_run (run_id),
    INDEX idx_supplier_import_rows_sku (supplier_sku),
    INDEX idx_supplier_import_rows_matched_product (matched_product_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $runColumns = [];
  $st = $pdo->query("SHOW COLUMNS FROM supplier_import_runs");
  foreach ($st->fetchAll() as $row) {
    $runColumns[(string)$row['Field']] = true;
  }
  $runAlterMap = [
    'supplier_discount_percent' => "ALTER TABLE supplier_import_runs ADD COLUMN supplier_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER extra_discount_percent",
    'total_discount_percent' => "ALTER TABLE supplier_import_runs ADD COLUMN total_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER supplier_discount_percent",
    'selected_sku_column' => "ALTER TABLE supplier_import_runs ADD COLUMN selected_sku_column VARCHAR(120) NULL AFTER total_discount_percent",
    'selected_price_column' => "ALTER TABLE supplier_import_runs ADD COLUMN selected_price_column VARCHAR(120) NULL AFTER selected_sku_column",
    'selected_cost_type_column' => "ALTER TABLE supplier_import_runs ADD COLUMN selected_cost_type_column VARCHAR(120) NULL AFTER selected_price_column",
    'selected_units_per_pack_column' => "ALTER TABLE supplier_import_runs ADD COLUMN selected_units_per_pack_column VARCHAR(120) NULL AFTER selected_cost_type_column",
    'dedupe_mode' => "ALTER TABLE supplier_import_runs ADD COLUMN dedupe_mode VARCHAR(30) NULL AFTER selected_units_per_pack_column",
    'mapping_header_hash' => "ALTER TABLE supplier_import_runs ADD COLUMN mapping_header_hash VARCHAR(64) NULL AFTER dedupe_mode",
  ];
  foreach ($runAlterMap as $col => $sql) {
    if (!isset($runColumns[$col])) {
      $pdo->exec($sql);
    }
  }

  $rowColumns = [];
  $st = $pdo->query("SHOW COLUMNS FROM supplier_import_rows");
  foreach ($st->fetchAll() as $row) {
    $rowColumns[(string)$row['Field']] = true;
  }
  $rowAlterMap = [
    'price_column_name' => "ALTER TABLE supplier_import_rows ADD COLUMN price_column_name VARCHAR(120) NULL AFTER raw_price",
    'discount_applied_percent' => "ALTER TABLE supplier_import_rows ADD COLUMN discount_applied_percent DECIMAL(10,2) NULL AFTER price_column_name",
    'cost_calc_detail' => "ALTER TABLE supplier_import_rows ADD COLUMN cost_calc_detail VARCHAR(255) NULL AFTER normalized_unit_cost",
  ];
  foreach ($rowAlterMap as $col => $sql) {
    if (!isset($rowColumns[$col])) {
      $pdo->exec($sql);
    }
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS product_supplier_cost_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_supplier_id INT UNSIGNED NOT NULL,
    run_id INT UNSIGNED NULL,
    cost_before DECIMAL(10,2) NULL,
    cost_after DECIMAL(10,2) NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    changed_by INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    INDEX idx_ps_cost_history_ps (product_supplier_id),
    INDEX idx_ps_cost_history_run (run_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $ready = true;
}

function normalize_margin_percent_value($raw): ?string {
  $value = trim((string)$raw);
  if ($value === '') {
    $value = '0';
  }

  if (!preg_match('/^\d{1,3}(?:[\.,]\d{1,2})?$/', $value)) {
    return null;
  }

  $normalized = (float)str_replace(',', '.', $value);
  if ($normalized < 0 || $normalized > 999.99) {
    return null;
  }

  return number_format($normalized, 2, '.', '');
}

function normalize_site_margin_percent_value($raw): ?string {
  $value = trim((string)$raw);
  if ($value === '') {
    $value = '0';
  }

  if (!preg_match('/^-?\d{1,3}(?:[\.,]\d{1,2})?$/', $value)) {
    return null;
  }

  $normalized = (float)str_replace(',', '.', $value);
  if ($normalized < -100 || $normalized > 999.99) {
    return null;
  }

  return number_format($normalized, 2, '.', '');
}

function ensure_sites_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();

  $pdo->exec("CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    channel_type VARCHAR(20) NOT NULL DEFAULT 'PRESTASHOP',
    conn_type ENUM('none','prestashop','mercadolibre') NOT NULL DEFAULT 'none',
    conn_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sync_stock_enabled TINYINT(1) NOT NULL DEFAULT 0,
    stock_sync_mode ENUM('OFF','BIDIR','TS_TO_SITE','SITE_TO_TS') NOT NULL DEFAULT 'OFF',
    last_sync_at DATETIME NULL,
    margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    show_in_product TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sites_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $site_columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM sites");
  foreach ($st->fetchAll() as $row) {
    $site_columns[(string)$row['Field']] = true;
  }

  if (!isset($site_columns['is_visible'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
  }

  if (!isset($site_columns['show_in_product'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN show_in_product TINYINT(1) NOT NULL DEFAULT 1 AFTER is_visible");
  }

  if (!isset($site_columns['channel_type'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN channel_type VARCHAR(20) NOT NULL DEFAULT 'PRESTASHOP' AFTER name");
  }

  if (!isset($site_columns['conn_type'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN conn_type ENUM('none','prestashop','mercadolibre') NOT NULL DEFAULT 'none' AFTER channel_type");
  }

  if (!isset($site_columns['conn_enabled'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN conn_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER conn_type");
  }

  if (!isset($site_columns['sync_stock_enabled'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN sync_stock_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER conn_enabled");
  }

  if (!isset($site_columns['last_sync_at'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN last_sync_at DATETIME NULL AFTER sync_stock_enabled");
  }

  if (!isset($site_columns['stock_sync_mode'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN stock_sync_mode ENUM('OFF','BIDIR','TS_TO_SITE','SITE_TO_TS') NOT NULL DEFAULT 'OFF' AFTER sync_stock_enabled");
    $pdo->exec("UPDATE sites SET stock_sync_mode = CASE WHEN sync_stock_enabled = 1 THEN 'BIDIR' ELSE 'OFF' END WHERE stock_sync_mode = 'OFF'");
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS site_connections (
    site_id INT UNSIGNED NOT NULL,
    channel_type VARCHAR(20) NOT NULL DEFAULT 'PRESTASHOP',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    ps_base_url VARCHAR(255) NULL,
    ps_api_key VARCHAR(255) NULL,
    webhook_secret VARCHAR(255) NULL,
    ps_shop_id INT NULL,
    ml_client_id VARCHAR(100) NULL,
    ml_app_id VARCHAR(100) NULL,
    ml_client_secret VARCHAR(255) NULL,
    ml_redirect_uri VARCHAR(255) NULL,
    ml_access_token TEXT NULL,
    ml_refresh_token TEXT NULL,
    ml_expires_at DATETIME NULL,
    ml_token_expires_at DATETIME NULL,
    ml_connected_at DATETIME NULL,
    ml_user_id VARCHAR(40) NULL,
    ml_notification_secret VARCHAR(255) NULL,
    ml_notification_callback_url VARCHAR(255) NULL,
    ml_subscription_id VARCHAR(120) NULL,
    ml_subscription_topic VARCHAR(50) NULL,
    ml_subscription_updated_at DATETIME NULL,
    ml_status VARCHAR(20) NOT NULL DEFAULT 'DISCONNECTED',
    updated_at DATETIME NULL,
    PRIMARY KEY (site_id),
    CONSTRAINT fk_site_connections_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $connColumns = [];
  $st = $pdo->query("SHOW COLUMNS FROM site_connections");
  foreach ($st->fetchAll() as $row) {
    $connColumns[(string)$row['Field']] = $row;
  }

  if (!isset($connColumns['ml_redirect_uri'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_redirect_uri VARCHAR(255) NULL AFTER ml_client_secret");
  }
  if (!isset($connColumns['ml_app_id'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_app_id VARCHAR(100) NULL AFTER ml_client_id");
    $pdo->exec("UPDATE site_connections SET ml_app_id = ml_client_id WHERE COALESCE(ml_app_id, '') = ''");
  }
  if (!isset($connColumns['webhook_secret'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN webhook_secret VARCHAR(255) NULL AFTER ps_api_key");
  }
  if (!isset($connColumns['ml_access_token'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_access_token TEXT NULL AFTER ml_redirect_uri");
  }
  if (!isset($connColumns['ml_user_id'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_user_id VARCHAR(40) NULL AFTER ml_refresh_token");
  }
  if (!isset($connColumns['ml_expires_at'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_expires_at DATETIME NULL AFTER ml_refresh_token");
    $pdo->exec("UPDATE site_connections SET ml_expires_at = ml_token_expires_at WHERE ml_expires_at IS NULL");
  }
  if (!isset($connColumns['ml_token_expires_at'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_token_expires_at DATETIME NULL AFTER ml_refresh_token");
  }
  if (isset($connColumns['ml_refresh_token']) && stripos((string)($connColumns['ml_refresh_token']['Type'] ?? ''), 'text') === false) {
    $pdo->exec("ALTER TABLE site_connections MODIFY COLUMN ml_refresh_token TEXT NULL");
  }
  if (!isset($connColumns['ml_connected_at'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_connected_at DATETIME NULL AFTER ml_token_expires_at");
  }
  if (!isset($connColumns['ml_status'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_status VARCHAR(20) NOT NULL DEFAULT 'DISCONNECTED' AFTER ml_user_id");
  }
  if (!isset($connColumns['ml_subscription_id'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_subscription_id VARCHAR(120) NULL AFTER ml_user_id");
  }
  if (!isset($connColumns['ml_notification_secret'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_notification_secret VARCHAR(255) NULL AFTER ml_user_id");
  }
  if (!isset($connColumns['ml_notification_callback_url'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_notification_callback_url VARCHAR(255) NULL AFTER ml_notification_secret");
  }
  if (!isset($connColumns['ml_subscription_topic'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_subscription_topic VARCHAR(50) NULL AFTER ml_subscription_id");
  }
  if (!isset($connColumns['ml_subscription_updated_at'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN ml_subscription_updated_at DATETIME NULL AFTER ml_subscription_topic");
  }
  if (!isset($connColumns['updated_at'])) {
    $pdo->exec("ALTER TABLE site_connections ADD COLUMN updated_at DATETIME NULL AFTER ml_status");
  }

  $ready = true;
}

function ensure_brands_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();

  $pdo->exec("CREATE TABLE IF NOT EXISTS brands (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_brands_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM products");
  foreach ($st->fetchAll() as $row) {
    $columns[(string)$row['Field']] = true;
  }

  if (!isset($columns['brand_id'])) {
    $pdo->exec("ALTER TABLE products ADD COLUMN brand_id INT UNSIGNED NULL AFTER brand");
  }

  $pdo->exec("INSERT IGNORE INTO brands(name)
    SELECT DISTINCT TRIM(brand)
    FROM products
    WHERE TRIM(COALESCE(brand, '')) <> ''");

  $pdo->exec("UPDATE products p
    INNER JOIN brands b ON b.name = TRIM(p.brand)
    SET p.brand_id = b.id
    WHERE p.brand_id IS NULL
      AND TRIM(COALESCE(p.brand, '')) <> ''");

  $indexExists = false;
  $st = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_brand_id'");
  foreach ($st->fetchAll() as $row) {
    $indexExists = true;
    break;
  }
  if (!$indexExists) {
    $pdo->exec("ALTER TABLE products ADD KEY idx_products_brand_id (brand_id)");
  }

  $fkExists = false;
  $st = $pdo->prepare("SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'brand_id'
      AND REFERENCED_TABLE_NAME = 'brands'");
  $st->execute();
  if ($st->fetch()) {
    $fkExists = true;
  }

  if (!$fkExists) {
    $pdo->exec("ALTER TABLE products
      ADD CONSTRAINT fk_products_brand
      FOREIGN KEY (brand_id) REFERENCES brands(id)
      ON DELETE SET NULL");
  }

  $ready = true;
}

function fetch_brands(): array {
  ensure_brands_schema();
  $st = db()->query("SELECT id, name FROM brands ORDER BY name ASC");
  return $st->fetchAll();
}

function resolve_brand_id(string $brandName): ?int {
  ensure_brands_schema();
  $name = trim($brandName);
  if ($name === '') {
    return null;
  }

  $st = db()->prepare("INSERT IGNORE INTO brands(name) VALUES(?)");
  $st->execute([$name]);

  $st = db()->prepare("SELECT id FROM brands WHERE name = ? LIMIT 1");
  $st->execute([$name]);
  $brandId = $st->fetchColumn();
  if ($brandId === false) {
    return null;
  }

  return (int)$brandId;
}
