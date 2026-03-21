<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../prestashop.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/site_stock_bulk.php';
require_once __DIR__ . '/stock_sync.php';

function ensure_site_price_bulk_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS site_price_bulk_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    adjustment_percent DECIMAL(10,4) NOT NULL DEFAULT 0,
    status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_site_price_bulk_runs_site (site_id, created_at),
    KEY idx_site_price_bulk_runs_status (status, updated_at),
    CONSTRAINT fk_site_price_bulk_runs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS site_price_bulk_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(120) NULL,
    ts_price_before DECIMAL(12,2) NULL,
    adjustment_percent DECIMAL(10,4) NOT NULL DEFAULT 0,
    final_price DECIMAL(12,2) NULL,
    remote_price_before DECIMAL(12,2) NULL,
    remote_price_after DECIMAL(12,2) NULL,
    status ENUM('PENDING','OK','SKIP','ERROR') NOT NULL DEFAULT 'PENDING',
    message VARCHAR(255) NULL,
    external_id VARCHAR(120) NULL,
    external_variant_id VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_price_bulk_rows_run_sku_remote (run_id, sku, external_id, external_variant_id),
    KEY idx_site_price_bulk_rows_run_status (run_id, status, id),
    KEY idx_site_price_bulk_rows_sku (sku),
    CONSTRAINT fk_site_price_bulk_rows_run FOREIGN KEY (run_id) REFERENCES site_price_bulk_runs(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $ready = true;
}

function site_price_bulk_to_float($value): ?float {
  if ($value === null || $value === '') {
    return null;
  }
  if (is_int($value) || is_float($value)) {
    return (float)$value;
  }
  if (is_string($value)) {
    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
      return null;
    }
    return (float)$normalized;
  }
  return null;
}

function site_price_bulk_normalize_percent($value): float {
  $parsed = site_price_bulk_to_float($value);
  return $parsed === null ? 0.0 : $parsed;
}

function site_price_bulk_format_decimal(float $value): string {
  return number_format($value, 2, '.', '');
}

function site_price_bulk_calculate_final(float $tsworkPrice, float $adjustmentPercent): float {
  return round($tsworkPrice + ($tsworkPrice * $adjustmentPercent / 100), 2);
}

function site_price_bulk_insert_snapshot_rows(PDO $pdo, int $runId, array $rows, float $adjustmentPercent): int {
  if (count($rows) === 0) {
    return 0;
  }

  $sql = 'INSERT INTO site_price_bulk_rows (run_id, sku, adjustment_percent, status, message, external_id, external_variant_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE adjustment_percent = VALUES(adjustment_percent), status = VALUES(status), message = VALUES(message), ts_price_before = NULL, final_price = NULL, remote_price_before = NULL, remote_price_after = NULL, updated_at = CURRENT_TIMESTAMP';
  $st = $pdo->prepare($sql);
  $inserted = 0;
  foreach ($rows as $row) {
    $skuRaw = $row['sku'] ?? null;
    $sku = null;
    if (is_string($skuRaw)) {
      $trimmed = trim($skuRaw);
      $sku = $trimmed !== '' ? $trimmed : null;
    }
    $status = strtoupper(trim((string)($row['status'] ?? 'PENDING')));
    if (!in_array($status, ['PENDING', 'ERROR'], true)) {
      $status = 'PENDING';
    }
    $message = trim((string)($row['message'] ?? ''));
    if ($message === '') {
      $message = null;
    }
    $st->execute([
      $runId,
      $sku,
      site_price_bulk_format_decimal($adjustmentPercent),
      $status,
      $message,
      trim((string)($row['external_id'] ?? '')),
      trim((string)($row['external_variant_id'] ?? '')),
    ]);
    $inserted++;
  }

  return $inserted;
}

function site_price_bulk_fetch_products_by_sku(PDO $pdo, array $skuList): array {
  if (count($skuList) === 0) {
    return [];
  }

  $supplierColumns = [];
  $stSupplierColumns = $pdo->query('SHOW COLUMNS FROM suppliers');
  foreach ($stSupplierColumns->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $field = strtolower((string)($column['Field'] ?? ''));
    if ($field !== '') {
      $supplierColumns[$field] = true;
    }
  }

  $supplierMarginExpr = '0';
  foreach (['base_percent', 'base_margin_percent', 'default_margin_percent'] as $field) {
    if (isset($supplierColumns[$field])) {
      $safeField = str_replace('`', '``', $field);
      $supplierMarginExpr = "COALESCE(s.`{$safeField}`, 0)";
      break;
    }
  }

  $supplierDiscountExpr = '0';
  foreach (['discount_percent', 'supplier_discount_percent', 'import_discount_default'] as $field) {
    if (isset($supplierColumns[$field])) {
      $safeField = str_replace('`', '``', $field);
      $supplierDiscountExpr = "COALESCE(s.`{$safeField}`, 0)";
      break;
    }
  }

  $placeholders = implode(',', array_fill(0, count($skuList), '?'));
  $sql = "SELECT p.id, p.sku, p.sale_mode, p.sale_units_per_pack,
      ps.id AS supplier_link_id, ps.supplier_cost, ps.cost_type, ps.units_per_pack,
      {$supplierMarginExpr} AS supplier_base_percent,
      {$supplierDiscountExpr} AS supplier_discount_percent,
      COALESCE(s.import_default_units_per_pack, 0) AS supplier_default_units_per_pack
    FROM products p
    LEFT JOIN product_suppliers ps ON ps.product_id = p.id AND ps.is_active = 1
    LEFT JOIN suppliers s ON s.id = ps.supplier_id
    WHERE UPPER(TRIM(p.sku)) IN ($placeholders)
    ORDER BY p.id ASC, ps.id ASC";
  $st = $pdo->prepare($sql);
  $st->execute($skuList);

  $productsBySku = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $skuKey = mb_strtoupper(trim((string)($row['sku'] ?? '')));
    if ($skuKey === '' || isset($productsBySku[$skuKey])) {
      continue;
    }
    $productsBySku[$skuKey] = $row;
  }

  return $productsBySku;
}

function site_price_bulk_resolve_tswork_price(array $productRow, array $siteRow): array {
  if (!isset($productRow['supplier_link_id']) || (int)$productRow['supplier_link_id'] <= 0) {
    return ['price' => null, 'reason' => 'precio vacío'];
  }

  $effectiveUnitCost = get_effective_unit_cost($productRow, [
    'import_default_units_per_pack' => $productRow['supplier_default_units_per_pack'] ?? 0,
    'discount_percent' => $productRow['supplier_discount_percent'] ?? 0,
  ]);
  $costForMode = get_cost_for_product_mode($effectiveUnitCost, $productRow);
  $priceReason = get_price_unavailable_reason($productRow, $productRow);
  if ($costForMode === null) {
    return ['price' => null, 'reason' => $priceReason ?? 'precio vacío'];
  }

  $finalPrice = get_final_site_price($costForMode, [
    'base_percent' => $productRow['supplier_base_percent'] ?? 0,
    'discount_percent' => $productRow['supplier_discount_percent'] ?? 0,
  ], $siteRow, 0.0);

  if ($finalPrice === null) {
    return ['price' => null, 'reason' => $priceReason ?? 'precio vacío'];
  }

  return ['price' => round((float)$finalPrice, 2), 'reason' => null];
}

function site_price_bulk_ml_set_price(int $siteId, string $itemId, string $variationId, float $newPrice): array {
  if ($itemId === '') {
    return ['ok' => false, 'message' => 'Item ID vacío.'];
  }
  $endpoint = $variationId !== ''
    ? 'https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '/variations/' . rawurlencode($variationId)
    : 'https://api.mercadolibre.com/items/' . rawurlencode($itemId);

  try {
    $response = ml_api_request($siteId, 'PUT', $endpoint, ['price' => $newPrice]);
    if ($response['code'] < 200 || $response['code'] >= 300) {
      return ['ok' => false, 'message' => 'Error al actualizar precio en MercadoLibre (HTTP ' . $response['code'] . ').'];
    }
    return ['ok' => true, 'message' => 'Actualizado.'];
  } catch (Throwable $t) {
    return ['ok' => false, 'message' => $t->getMessage()];
  }
}

function site_price_bulk_ps_set_price(array $site, int $idProduct, int $idProductAttribute, float $newPrice): array {
  $baseUrl = rtrim(trim((string)($site['ps_base_url'] ?? '')), '/');
  $apiKey = ps_normalize_api_key((string)($site['ps_api_key'] ?? ''));
  if ($baseUrl === '' || $apiKey === '') {
    return ['ok' => false, 'message' => 'Sitio PrestaShop sin credenciales.'];
  }

  try {
    $resource = $idProductAttribute > 0 ? 'combinations' : 'products';
    $entityId = $idProductAttribute > 0 ? $idProductAttribute : $idProduct;
    if ($entityId <= 0) {
      return ['ok' => false, 'message' => 'ID remoto inválido para precio.'];
    }

    $template = ps_request_with_credentials('GET', '/api/' . $resource . '/' . $entityId, $baseUrl, $apiKey);
    if ($template['code'] < 200 || $template['code'] >= 300) {
      return ['ok' => false, 'message' => 'No se pudo leer ' . $resource . ' (HTTP ' . $template['code'] . ').'];
    }

    $sx = ps_xml_load((string)$template['body']);
    $node = $sx->{$resource === 'products' ? 'product' : 'combination'} ?? null;
    if ($node === null) {
      return ['ok' => false, 'message' => 'Respuesta XML inválida para precio.'];
    }

    $node->price = site_price_bulk_format_decimal($newPrice);
    $payload = $sx->asXML();
    if ($payload === false) {
      return ['ok' => false, 'message' => 'No se pudo preparar XML para precio.'];
    }

    $update = ps_request_with_credentials('PUT', '/api/' . $resource . '/' . $entityId, $baseUrl, $apiKey, $payload);
    if ($update['code'] < 200 || $update['code'] >= 300) {
      return ['ok' => false, 'message' => 'Error al actualizar precio en PrestaShop (HTTP ' . $update['code'] . ').'];
    }
    return ['ok' => true, 'message' => 'Actualizado.'];
  } catch (Throwable $t) {
    return ['ok' => false, 'message' => $t->getMessage()];
  }
}
