-- Normalización de marcas: tabla brands + products.brand_id
CREATE TABLE IF NOT EXISTS brands (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_brand_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'brand_id'
);
SET @sql_add_brand_id := IF(
  @has_brand_id = 0,
  'ALTER TABLE products ADD COLUMN brand_id INT UNSIGNED NULL AFTER brand',
  'SELECT 1'
);
PREPARE stmt_add_brand_id FROM @sql_add_brand_id;
EXECUTE stmt_add_brand_id;
DEALLOCATE PREPARE stmt_add_brand_id;

INSERT IGNORE INTO brands(name)
SELECT DISTINCT TRIM(brand)
FROM products
WHERE TRIM(COALESCE(brand, '')) <> '';

UPDATE products p
INNER JOIN brands b ON b.name = TRIM(p.brand)
SET p.brand_id = b.id
WHERE p.brand_id IS NULL
  AND TRIM(COALESCE(p.brand, '')) <> '';

SET @has_brand_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND INDEX_NAME = 'idx_products_brand_id'
);
SET @sql_add_brand_idx := IF(
  @has_brand_idx = 0,
  'ALTER TABLE products ADD KEY idx_products_brand_id (brand_id)',
  'SELECT 1'
);
PREPARE stmt_add_brand_idx FROM @sql_add_brand_idx;
EXECUTE stmt_add_brand_idx;
DEALLOCATE PREPARE stmt_add_brand_idx;

SET @has_brand_fk := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'brand_id'
    AND REFERENCED_TABLE_NAME = 'brands'
);
SET @sql_add_brand_fk := IF(
  @has_brand_fk = 0,
  'ALTER TABLE products ADD CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_add_brand_fk FROM @sql_add_brand_fk;
EXECUTE stmt_add_brand_fk;
DEALLOCATE PREPARE stmt_add_brand_fk;
