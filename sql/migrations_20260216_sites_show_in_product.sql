ALTER TABLE sites
  ADD COLUMN show_in_product TINYINT(1) NOT NULL DEFAULT 1 AFTER is_visible;
