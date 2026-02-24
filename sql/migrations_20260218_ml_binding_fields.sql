ALTER TABLE site_product_map
  ADD COLUMN IF NOT EXISTS ml_seller_id VARCHAR(120) NULL AFTER ml_variation_id,
  ADD COLUMN IF NOT EXISTS ml_last_bind_at DATETIME NULL AFTER ml_seller_id;
