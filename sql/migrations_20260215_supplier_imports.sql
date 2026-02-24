ALTER TABLE suppliers
  ADD COLUMN base_margin_percent DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER default_margin_percent,
  ADD COLUMN import_dedupe_mode ENUM('LAST','FIRST','MIN','MAX','PREFER_PROMO') NOT NULL DEFAULT 'LAST' AFTER is_active,
  ADD COLUMN import_default_cost_type ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD' AFTER import_dedupe_mode,
  ADD COLUMN import_default_units_per_pack INT NULL AFTER import_default_cost_type,
  ADD COLUMN import_discount_default DECIMAL(10,2) NULL AFTER import_default_units_per_pack;

UPDATE suppliers SET base_margin_percent = default_margin_percent WHERE base_margin_percent = 0;

CREATE TABLE supplier_import_runs (
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
);

CREATE TABLE supplier_import_rows (
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
);

CREATE TABLE product_supplier_cost_history (
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
);
