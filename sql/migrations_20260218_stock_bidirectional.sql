ALTER TABLE sites
  ADD COLUMN conn_type ENUM('none','prestashop','mercadolibre') NOT NULL DEFAULT 'none' AFTER channel_type,
  ADD COLUMN conn_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER conn_type,
  ADD COLUMN sync_stock_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER conn_enabled,
  ADD COLUMN last_sync_at DATETIME NULL AFTER sync_stock_enabled;

ALTER TABLE ts_stock_moves
  ADD COLUMN origin ENUM('tswork','prestashop','mercadolibre') NOT NULL DEFAULT 'tswork' AFTER reason,
  ADD COLUMN event_id VARCHAR(120) NULL AFTER origin;

CREATE TABLE IF NOT EXISTS site_product_map (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  remote_id VARCHAR(120) NOT NULL,
  remote_variant_id VARCHAR(120) NULL,
  remote_sku VARCHAR(120) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_site_product_remote (site_id, remote_id, remote_variant_id),
  UNIQUE KEY uq_site_product_local (site_id, product_id),
  KEY idx_site_product_map_product (product_id),
  CONSTRAINT fk_site_product_map_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_site_product_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ts_sync_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  payload_json TEXT NULL,
  payload_hash CHAR(64) NULL,
  origin ENUM('tswork','prestashop','mercadolibre') NOT NULL DEFAULT 'tswork',
  source_site_id INT UNSIGNED NULL,
  status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ts_sync_jobs_status (status, created_at),
  KEY idx_ts_sync_jobs_site_product (site_id, product_id),
  KEY idx_ts_sync_jobs_payload_hash (payload_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ts_sync_locks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  origin ENUM('tswork','prestashop','mercadolibre') NOT NULL,
  event_key VARCHAR(190) NOT NULL,
  payload_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_sync_locks_event (site_id, product_id, origin, event_key),
  KEY idx_ts_sync_locks_hash (payload_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
