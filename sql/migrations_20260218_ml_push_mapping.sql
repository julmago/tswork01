ALTER TABLE site_product_map
  ADD COLUMN IF NOT EXISTS ml_item_id VARCHAR(120) NULL AFTER remote_sku,
  ADD COLUMN IF NOT EXISTS ml_variation_id VARCHAR(120) NULL AFTER ml_item_id;

UPDATE site_product_map spm
INNER JOIN sites s ON s.id = spm.site_id
SET
  spm.ml_item_id = COALESCE(NULLIF(spm.ml_item_id, ''), spm.remote_id),
  spm.ml_variation_id = COALESCE(NULLIF(spm.ml_variation_id, ''), spm.remote_variant_id)
WHERE LOWER(s.conn_type) = 'mercadolibre';

CREATE TABLE IF NOT EXISTS stock_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_logs_product_created (product_id, created_at),
  KEY idx_stock_logs_site_created (site_id, created_at),
  CONSTRAINT fk_stock_logs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_logs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
