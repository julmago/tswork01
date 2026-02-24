ALTER TABLE suppliers
  ADD COLUMN global_adjust_percent DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER import_mapping_header_hash,
  ADD COLUMN global_adjust_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER global_adjust_percent;
