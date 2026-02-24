<?php

if (!function_exists('pricing_to_float')) {
  function pricing_to_float($value, float $default = 0.0): float {
    if ($value === null || $value === '') {
      return $default;
    }

    return (float)$value;
  }
}

if (!function_exists('get_effective_unit_cost')) {
  function get_effective_unit_cost(array $ps_row, array $supplier_row = []): ?float {
    $supplierCost = $ps_row['supplier_cost'] ?? null;
    if ($supplierCost === null || $supplierCost === '') {
      return null;
    }

    $effectiveCost = (float)$supplierCost;

    $costType = strtoupper((string)($ps_row['cost_type'] ?? 'UNIDAD'));
    if ($costType === 'PACK') {
      $unitsPerPack = (int)($ps_row['units_per_pack'] ?? 0);
      if ($unitsPerPack <= 0) {
        $unitsPerPack = (int)($supplier_row['units_per_pack_default']
          ?? $supplier_row['import_default_units_per_pack']
          ?? 0);
      }
      if ($unitsPerPack <= 0) {
        $unitsPerPack = (int)($ps_row['units_pack']
          ?? $ps_row['sale_units_per_pack']
          ?? $supplier_row['units_pack']
          ?? $supplier_row['sale_units_per_pack']
          ?? 0);
      }
      if ($unitsPerPack <= 0) {
        $unitsPerPack = 1;
      }

      return $effectiveCost / $unitsPerPack;
    }

    return $effectiveCost;
  }
}

if (!function_exists('get_cost_for_product_mode')) {
  function get_cost_for_product_mode(?float $unit_cost, array $product_row): ?float {
    if ($unit_cost === null) {
      return null;
    }

    $modeSale = strtoupper((string)($product_row['sale_mode'] ?? 'UNIDAD'));
    if ($modeSale === 'PACK') {
      $unitsPack = (int)($product_row['sale_units_per_pack'] ?? 0);
      if ($unitsPack <= 0) {
        return null;
      }

      return $unit_cost * $unitsPack;
    }

    return $unit_cost;
  }
}

if (!function_exists('get_final_site_price')) {
  function get_final_site_price(?float $cost_for_mode, array $supplier_row, array $site_row, float $import_extra_discount = 0.0): ?int {
    if ($cost_for_mode === null) {
      return null;
    }

    $supplierDiscount = pricing_to_float(
      $supplier_row['discount_percent']
        ?? $supplier_row['supplier_discount_percent']
        ?? 0,
      0.0
    );

    $rawCost = $cost_for_mode
      * (1 - ($supplierDiscount / 100))
      * (1 - ($import_extra_discount / 100));

    $supplierBase = pricing_to_float(
      $supplier_row['base_percent']
        ?? $supplier_row['base_margin_percent']
        ?? $supplier_row['default_margin_percent']
        ?? 0,
      0.0
    );
    $siteMargin = pricing_to_float($site_row['margin_percent'] ?? 0, 0.0);

    $finalPrice = $rawCost
      * (1 + ($supplierBase / 100))
      * (1 + ($siteMargin / 100));

    return (int)round($finalPrice, 0);
  }
}

if (!function_exists('get_price_unavailable_reason')) {
  function get_price_unavailable_reason(array $ps_row, array $product_row): ?string {
    $modeSale = strtoupper((string)($product_row['sale_mode'] ?? 'UNIDAD'));
    if ($modeSale === 'PACK' && (int)($product_row['sale_units_per_pack'] ?? 0) <= 0) {
      return 'Faltan unidades pack';
    }

    if (($ps_row['supplier_cost'] ?? null) === null || $ps_row['supplier_cost'] === '') {
      return 'Falta costo proveedor';
    }

    return null;
  }
}
