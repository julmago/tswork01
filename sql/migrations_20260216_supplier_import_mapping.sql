ALTER TABLE suppliers
  ADD COLUMN import_sku_column VARCHAR(120) NULL AFTER import_discount_default,
  ADD COLUMN import_price_column VARCHAR(120) NULL AFTER import_sku_column,
  ADD COLUMN import_cost_type_column VARCHAR(120) NULL AFTER import_price_column,
  ADD COLUMN import_units_per_pack_column VARCHAR(120) NULL AFTER import_cost_type_column,
  ADD COLUMN import_mapping_header_hash VARCHAR(64) NULL AFTER import_units_per_pack_column;

ALTER TABLE supplier_import_runs
  ADD COLUMN supplier_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER extra_discount_percent,
  ADD COLUMN total_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER supplier_discount_percent,
  ADD COLUMN selected_sku_column VARCHAR(120) NULL AFTER total_discount_percent,
  ADD COLUMN selected_price_column VARCHAR(120) NULL AFTER selected_sku_column,
  ADD COLUMN selected_cost_type_column VARCHAR(120) NULL AFTER selected_price_column,
  ADD COLUMN selected_units_per_pack_column VARCHAR(120) NULL AFTER selected_cost_type_column,
  ADD COLUMN dedupe_mode VARCHAR(30) NULL AFTER selected_units_per_pack_column,
  ADD COLUMN mapping_header_hash VARCHAR(64) NULL AFTER dedupe_mode;

ALTER TABLE supplier_import_rows
  ADD COLUMN price_column_name VARCHAR(120) NULL AFTER raw_price,
  ADD COLUMN discount_applied_percent DECIMAL(10,2) NULL AFTER price_column_name,
  ADD COLUMN cost_calc_detail VARCHAR(255) NULL AFTER normalized_unit_cost;
