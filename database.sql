-- Entrada de Stock Simple (MySQL 5.7+/8.0+)
-- NOTA: En este MVP las contraseñas se guardan en texto plano (NO recomendado).
-- El login actual usa PIN en texto plano (pin) y un gateway global.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role VARCHAR(32) NOT NULL DEFAULT 'superadmin',
  first_name VARCHAR(80) NOT NULL,
  last_name  VARCHAR(80) NOT NULL,
  email      VARCHAR(190) NOT NULL,
  password_plain VARCHAR(190) NOT NULL,
  pin VARCHAR(6) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  theme VARCHAR(32) NOT NULL DEFAULT 'theme_default',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_tasks_seen_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  role_key VARCHAR(32) NOT NULL,
  role_name VARCHAR(64) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_key VARCHAR(32) NOT NULL,
  perm_key VARCHAR(64) NOT NULL,
  perm_value TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (role_key, perm_key),
  CONSTRAINT fk_role_permissions_role
    FOREIGN KEY (role_key) REFERENCES roles(role_key)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cashboxes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cashboxes_active (is_active),
  CONSTRAINT fk_cashboxes_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_cashbox_permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_key VARCHAR(32) NOT NULL,
  cashbox_id INT UNSIGNED NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_open_module TINYINT(1) NOT NULL DEFAULT 0,
  can_manage_cashboxes TINYINT(1) NOT NULL DEFAULT 0,
  can_view_balance TINYINT(1) NOT NULL DEFAULT 0,
  can_create_entries TINYINT(1) NOT NULL DEFAULT 0,
  can_create_exits TINYINT(1) NOT NULL DEFAULT 0,
  can_configure_bills TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_cashbox (role_key, cashbox_id),
  KEY idx_role_cashbox_role (role_key),
  KEY idx_role_cashbox_cashbox (cashbox_id),
  CONSTRAINT fk_role_cashbox_role
    FOREIGN KEY (role_key) REFERENCES roles(role_key)
    ON DELETE CASCADE,
  CONSTRAINT fk_role_cashbox_cashbox
    FOREIGN KEY (cashbox_id) REFERENCES cashboxes(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_movements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cashbox_id INT UNSIGNED NOT NULL,
  type ENUM('entry','exit') NOT NULL,
  detail VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cash_movements_cashbox_date (cashbox_id, created_at),
  KEY idx_cash_movements_cashbox_type (cashbox_id, type),
  CONSTRAINT fk_cash_movements_cashbox
    FOREIGN KEY (cashbox_id) REFERENCES cashboxes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_cash_movements_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_denominations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cashbox_id INT UNSIGNED NOT NULL,
  value INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cash_denoms_value (cashbox_id, value),
  KEY idx_cash_denoms_active (cashbox_id, is_active),
  CONSTRAINT fk_cash_denoms_cashbox
    FOREIGN KEY (cashbox_id) REFERENCES cashboxes(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cash_denominations (cashbox_id, value, is_active, sort_order)
SELECT c.id, d.value, 1, d.sort_order
FROM cashboxes c
JOIN (
  SELECT 100 AS value, 10 AS sort_order UNION ALL
  SELECT 500 AS value, 20 AS sort_order UNION ALL
  SELECT 1000 AS value, 30 AS sort_order UNION ALL
  SELECT 2000 AS value, 40 AS sort_order UNION ALL
  SELECT 10000 AS value, 50 AS sort_order UNION ALL
  SELECT 20000 AS value, 60 AS sort_order
) d ON 1=1
ON DUPLICATE KEY UPDATE value = VALUES(value);

CREATE TABLE IF NOT EXISTS brands (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(80) NOT NULL,
  name VARCHAR(190) NOT NULL,
  brand VARCHAR(120) NOT NULL DEFAULT '',
  brand_id INT UNSIGNED NULL,
  sale_mode ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD',
  sale_units_per_pack INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_brand_id (brand_id),
  CONSTRAINT fk_products_brand
    FOREIGN KEY (brand_id) REFERENCES brands(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  default_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
  base_margin_percent DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  import_dedupe_mode ENUM('LAST','FIRST','MIN','MAX','PREFER_PROMO') NOT NULL DEFAULT 'LAST',
  import_default_cost_type ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD',
  import_default_units_per_pack INT NULL,
  import_discount_default DECIMAL(10,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_suppliers (
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
  UNIQUE KEY uq_product_supplier_link (product_id, supplier_id),
  CONSTRAINT fk_ps_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ps_supplier
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS supplier_import_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NULL,
  source_type ENUM('CSV','XLSX','TXT','PASTE') NOT NULL,
  extra_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at DATETIME NULL,
  notes VARCHAR(255) NULL,
  INDEX idx_supplier_import_runs_supplier (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_import_rows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  supplier_sku VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  raw_price DECIMAL(10,2) NULL,
  raw_cost_type ENUM('UNIDAD','PACK') NULL,
  raw_units_per_pack INT NULL,
  normalized_unit_cost DECIMAL(10,2) NULL,
  matched_product_supplier_id INT UNSIGNED NULL,
  matched_product_id INT UNSIGNED NULL,
  status ENUM('MATCHED','UNMATCHED','DUPLICATE_SKU','INVALID') NOT NULL DEFAULT 'UNMATCHED',
  chosen_by_rule TINYINT(1) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_supplier_import_rows_run (run_id),
  INDEX idx_supplier_import_rows_sku (supplier_sku),
  INDEX idx_supplier_import_rows_matched_product (matched_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_supplier_cost_history (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  code VARCHAR(190) NOT NULL,
  code_type ENUM('BARRA','MPN') NOT NULL DEFAULT 'BARRA',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_codes_code (code),
  KEY idx_product_codes_product (product_id),
  CONSTRAINT fk_product_codes_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_lists (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  sync_target VARCHAR(40) NOT NULL DEFAULT '', -- 'prestashop' o ''
  synced_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_stock_lists_created_by (created_by),
  CONSTRAINT fk_stock_lists_user
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_list_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_list_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  synced_qty INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_list_product (stock_list_id, product_id),
  KEY idx_items_list (stock_list_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_list
    FOREIGN KEY (stock_list_id) REFERENCES stock_lists(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_items_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  category VARCHAR(120) NULL DEFAULT NULL,
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  assigned_user_id INT UNSIGNED NULL DEFAULT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  due_date DATE NULL,
  related_type VARCHAR(120) NULL DEFAULT NULL,
  related_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tasks_assigned (assigned_user_id),
  KEY idx_tasks_category (category),
  KEY idx_tasks_status (status),
  KEY idx_tasks_created_by (created_by_user_id),
  CONSTRAINT fk_tasks_assigned_user
    FOREIGN KEY (assigned_user_id) REFERENCES users(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_tasks_created_user
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_assignees (
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by_user_id INT UNSIGNED NULL,
  PRIMARY KEY (task_id, user_id),
  KEY idx_task_assignees_user (user_id),
  CONSTRAINT fk_task_assignees_task
    FOREIGN KEY (task_id) REFERENCES tasks(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_task_assignees_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO task_assignees (task_id, user_id)
SELECT id, assigned_user_id
FROM tasks
WHERE assigned_user_id IS NOT NULL AND assigned_user_id <> 0;

CREATE TABLE IF NOT EXISTS task_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_relations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_relations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO task_categories (name, is_active, sort_order) VALUES
('deposito', 1, 10),
('publicaciones', 1, 20),
('sincronizacion', 1, 30),
('mantenimiento', 1, 40),
('administrativo', 1, 50),
('incidencias', 1, 60)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO task_relations (name, is_active, sort_order) VALUES
('general', 1, 10),
('list', 1, 20),
('product', 1, 30)
ON DUPLICATE KEY UPDATE name = VALUES(name);


CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(120) NOT NULL,
  `value` TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores iniciales (podés editarlos desde la pantalla Config PrestaShop)
INSERT INTO settings(`key`,`value`) VALUES
('prestashop_url',''),
('prestashop_api_key',''),
('prestashop_mode','replace')
ON DUPLICATE KEY UPDATE `value` = `value`;
