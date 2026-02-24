ALTER TABLE product_suppliers
  ADD COLUMN supplier_cost DECIMAL(10,2) NULL AFTER units_per_pack;

DELETE ps_old
FROM product_suppliers ps_old
INNER JOIN product_suppliers ps_newer
  ON ps_old.product_id = ps_newer.product_id
 AND ps_old.supplier_id = ps_newer.supplier_id
 AND ps_old.id < ps_newer.id;

ALTER TABLE product_suppliers
  ADD CONSTRAINT uq_product_supplier_link UNIQUE (product_id, supplier_id);
