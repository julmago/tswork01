ALTER TABLE sites
  ADD COLUMN channel_type VARCHAR(20) NOT NULL DEFAULT 'PRESTASHOP' AFTER name;

CREATE TABLE IF NOT EXISTS site_connections (
  site_id INT UNSIGNED NOT NULL,
  channel_type VARCHAR(20) NOT NULL DEFAULT 'PRESTASHOP',
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  ps_base_url VARCHAR(255) NULL,
  ps_api_key VARCHAR(255) NULL,
  ps_shop_id INT NULL,
  ml_client_id VARCHAR(100) NULL,
  ml_client_secret VARCHAR(255) NULL,
  ml_refresh_token VARCHAR(255) NULL,
  PRIMARY KEY (site_id),
  CONSTRAINT fk_site_connections_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
