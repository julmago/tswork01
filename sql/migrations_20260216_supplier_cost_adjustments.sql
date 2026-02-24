CREATE TABLE IF NOT EXISTS supplier_cost_adjustments (
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
);
