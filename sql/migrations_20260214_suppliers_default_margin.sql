ALTER TABLE suppliers
  ADD COLUMN default_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER name;
