<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../prestashop.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/stock_sync.php';

function site_stock_bulk_to_int($value): int {
  if (is_int($value)) {
    return $value;
  }
  if (is_float($value)) {
    return (int)round($value);
  }
  if (is_string($value)) {
    return (int)round((float)str_replace(',', '.', $value));
  }
  return 0;
}

function site_stock_bulk_channel(string $value): string {
  $value = strtoupper(trim($value));
  if (!in_array($value, ['PRESTASHOP', 'MERCADOLIBRE'], true)) {
    return 'NONE';
  }
  return $value;
}

function ensure_site_stock_bulk_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS site_stock_bulk_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    action ENUM('import','export') NOT NULL,
    mode ENUM('set','add') NOT NULL,
    status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    debug_last_url TEXT NULL,
    debug_last_http INT NULL,
    debug_last_body_preview TEXT NULL,
    debug_pages_tried INT UNSIGNED NOT NULL DEFAULT 0,
    debug_last_phase VARCHAR(64) NULL,
    debug_last_offset INT NOT NULL DEFAULT 0,
    debug_last_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_site_stock_bulk_runs_site (site_id, created_at),
    KEY idx_site_stock_bulk_runs_status (status, updated_at),
    CONSTRAINT fk_site_stock_bulk_runs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $existing = [];
  $stColumns = $pdo->query('SHOW COLUMNS FROM site_stock_bulk_runs');
  foreach ($stColumns->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $name = strtolower((string)($column['Field'] ?? ''));
    if ($name !== '') {
      $existing[$name] = true;
    }
  }
  if (!isset($existing['debug_last_url'])) {
    $pdo->exec('ALTER TABLE site_stock_bulk_runs ADD COLUMN debug_last_url TEXT NULL AFTER last_error');
  }
  if (!isset($existing['debug_last_http'])) {
    $pdo->exec('ALTER TABLE site_stock_bulk_runs ADD COLUMN debug_last_http INT NULL AFTER debug_last_url');
  }
  if (!isset($existing['debug_last_body_preview'])) {
    $pdo->exec('ALTER TABLE site_stock_bulk_runs ADD COLUMN debug_last_body_preview TEXT NULL AFTER debug_last_http');
  }
  if (!isset($existing['debug_pages_tried'])) {
    $pdo->exec('ALTER TABLE site_stock_bulk_runs ADD COLUMN debug_pages_tried INT UNSIGNED NOT NULL DEFAULT 0 AFTER debug_last_body_preview');
  }
  if (!isset($existing['debug_last_phase'])) {
    $pdo->exec('ALTER TABLE site_stock_bulk_runs ADD COLUMN debug_last_phase VARCHAR(64) NULL AFTER debug_pages_tried');
  }
  if (!isset($existing['debug_last_offset'])) {
    $pdo->exec('ALTER TABLE site_stock_bulk_runs ADD COLUMN debug_last_offset INT NOT NULL DEFAULT 0 AFTER debug_last_phase');
  }
  if (!isset($existing['debug_last_count'])) {
    $pdo->exec('ALTER TABLE site_stock_bulk_runs ADD COLUMN debug_last_count INT NOT NULL DEFAULT 0 AFTER debug_last_offset');
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS site_stock_bulk_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(120) NULL,
    remote_qty INT NOT NULL DEFAULT 0,
    ts_qty_before INT NULL,
    ts_qty_after INT NULL,
    remote_qty_before INT NULL,
    remote_qty_after INT NULL,
    status ENUM('PENDING','OK','SKIP','ERROR') NOT NULL DEFAULT 'PENDING',
    message VARCHAR(255) NULL,
    external_id VARCHAR(120) NULL,
    external_variant_id VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_stock_bulk_rows_run_sku_remote (run_id, sku, external_id, external_variant_id),
    KEY idx_site_stock_bulk_rows_run_status (run_id, status, id),
    KEY idx_site_stock_bulk_rows_sku (sku),
    CONSTRAINT fk_site_stock_bulk_rows_run FOREIGN KEY (run_id) REFERENCES site_stock_bulk_runs(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $rowColumns = [];
  $stRowColumns = $pdo->query('SHOW COLUMNS FROM site_stock_bulk_rows');
  foreach ($stRowColumns->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $name = strtolower((string)($column['Field'] ?? ''));
    if ($name !== '') {
      $rowColumns[$name] = $column;
    }
  }
  if (isset($rowColumns['sku']) && strtoupper((string)($rowColumns['sku']['Null'] ?? 'NO')) !== 'YES') {
    $pdo->exec('ALTER TABLE site_stock_bulk_rows MODIFY sku VARCHAR(120) NULL');
  }

  $ready = true;
}

function site_stock_bulk_ps_extract_rows(array $json): ?array {
  if (!isset($json['stock_availables'])) {
    return null;
  }

  $stockAvailables = $json['stock_availables'];
  if (is_array($stockAvailables)) {
    if (array_key_exists('stock_available', $stockAvailables) && is_array($stockAvailables['stock_available'])) {
      return $stockAvailables['stock_available'];
    }
    return $stockAvailables;
  }

  return null;
}

function site_stock_bulk_ps_fetch_references(string $baseUrl, string $apiKey, string $resource, array $ids): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
  if (count($ids) === 0) {
    return [];
  }

  $references = [];
  $failedIds = [];
  $errors = [];
  $idChunks = array_chunk($ids, 75);
  foreach ($idChunks as $idChunk) {
    $offset = 0;
    $limit = 100;
    for ($page = 0; $page < 20; $page++) {
      $query = http_build_query([
        'filter[id]' => '[' . implode('|', $idChunk) . ']',
        'display' => '[id,reference]',
        'output_format' => 'JSON',
        'limit' => $offset . ',' . $limit,
      ], '', '&', PHP_QUERY_RFC3986);
      try {
        $response = ps_request_with_credentials('GET', '/api/' . $resource . '?' . $query, $baseUrl, $apiKey, null, ['Accept: application/json']);
      } catch (Throwable $t) {
        $failedIds = array_merge($failedIds, $idChunk);
        $errors[] = $t->getMessage();
        break;
      }
      if ($response['code'] < 200 || $response['code'] >= 300) {
        $failedIds = array_merge($failedIds, $idChunk);
        $errors[] = 'HTTP ' . (int)$response['code'] . ' en ' . $resource;
        break;
      }
      $json = json_decode((string)($response['body'] ?? ''), true);
      if (!is_array($json) || !isset($json[$resource]) || !is_array($json[$resource])) {
        $failedIds = array_merge($failedIds, $idChunk);
        $errors[] = 'JSON inválido en ' . $resource;
        break;
      }

      $container = $json[$resource];
      if (isset($container[$resource])) {
        $container = $container[$resource];
      }
      if (!is_array($container) || count($container) === 0) {
        break;
      }

      $items = array_is_list($container) ? $container : [$container];
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $entityId = site_stock_bulk_to_int($item['id'] ?? 0);
        if ($entityId <= 0) {
          continue;
        }
        $references[$entityId] = trim((string)($item['reference'] ?? ''));
      }

      if (count($items) < $limit) {
        break;
      }
      $offset += $limit;
    }
  }

  return [
    'references' => $references,
    'failed_ids' => array_values(array_unique(array_filter(array_map('intval', $failedIds), static fn(int $v): bool => $v > 0))),
    'errors' => $errors,
  ];
}

function site_stock_bulk_load_site(PDO $pdo, int $siteId): array {
  $st = $pdo->prepare('SELECT s.id, s.channel_type AS site_channel_type, sc.channel_type, sc.ps_base_url, sc.ps_api_key, sc.ml_client_id, sc.ml_client_secret, sc.ml_refresh_token, sc.ml_status, sc.ml_user_id
    FROM sites s
    LEFT JOIN site_connections sc ON sc.site_id = s.id
    WHERE s.id = ?
    LIMIT 1');
  $st->execute([$siteId]);
  $site = $st->fetch();
  if (!$site) {
    throw new RuntimeException('Sitio no encontrado.');
  }
  return is_array($site) ? $site : [];
}

function site_stock_bulk_preview_text(string $text, int $maxLen = 300): string {
  $flat = trim(preg_replace('/\s+/', ' ', $text) ?? '');
  if ($flat === '') {
    return '';
  }
  if (mb_strlen($flat) <= $maxLen) {
    return $flat;
  }
  return mb_substr($flat, 0, $maxLen) . '...';
}
function site_stock_bulk_ps_snapshot(array $site, ?PDO $pdo = null, int $runId = 0): array {
  $baseUrl = trim((string)($site['ps_base_url'] ?? ''));
  $apiKey = trim((string)($site['ps_api_key'] ?? ''));
  if ($baseUrl === '' || $apiKey === '') {
    throw new RuntimeException('Sitio PrestaShop sin credenciales.');
  }

  $offset = 0;
  $limit = 200;
  $rows = [];
  $errors = [];
  $debug = [
    'debug_last_url' => '',
    'debug_last_http' => 0,
    'debug_last_body_preview' => '',
    'debug_pages_tried' => 0,
    'debug_last_offset' => 0,
    'debug_last_count' => 0,
    'debug_last_phase' => 'snapshot',
    'debug_is_valid_empty' => false,
  ];
  $maxPages = 2000;
  $stDebug = null;
  if ($pdo !== null && $runId > 0) {
    $stDebug = $pdo->prepare('UPDATE site_stock_bulk_runs SET debug_last_url = ?, debug_last_http = ?, debug_last_body_preview = ?, debug_pages_tried = ?, debug_last_phase = ?, debug_last_offset = ?, debug_last_count = ? WHERE id = ?');
  }

  for ($page = 0; $page < $maxPages; $page++) {
    $query = http_build_query([
      'display' => '[id,id_product,id_product_attribute,quantity]',
      'limit' => $offset . ',' . $limit,
      'output_format' => 'JSON',
    ], '', '&', PHP_QUERY_RFC3986);

    $requestPath = '/stock_availables?' . $query;
    $response = ps_request_with_credentials('GET', $requestPath, $baseUrl, $apiKey, null, ['Accept: application/json']);
    $code = (int)($response['code'] ?? 0);
    $body = (string)($response['body'] ?? '');
    $debug['debug_last_url'] = rtrim($baseUrl, '/') . '/api' . $requestPath;
    $debug['debug_last_http'] = $code;
    $debug['debug_last_body_preview'] = site_stock_bulk_preview_text($body);
    $debug['debug_pages_tried'] = $page + 1;
    $debug['debug_last_offset'] = $offset;
    error_log('[site_stock_bulk][prestashop][snapshot] page=' . ($page + 1) . ' offset=' . $offset . ' http=' . $code);
    if ($stDebug instanceof PDOStatement) {
      $stDebug->execute([
        $debug['debug_last_url'],
        $debug['debug_last_http'] > 0 ? $debug['debug_last_http'] : null,
        $debug['debug_last_body_preview'],
        $debug['debug_pages_tried'],
        $debug['debug_last_phase'],
        $debug['debug_last_offset'],
        $debug['debug_last_count'],
        $runId,
      ]);
    }

    if ($code !== 200) {
      $preview = site_stock_bulk_preview_text($body);
      error_log('[site_stock_bulk][prestashop] HTTP ' . $code . ' body=' . $preview);
      $errors[] = 'HTTP ' . $code . ': ' . ($preview !== '' ? $preview : 'sin respuesta');
      return ['rows' => [], 'errors' => $errors, 'debug' => $debug];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      $preview = site_stock_bulk_preview_text($body);
      $errors[] = 'JSON inválido en PrestaShop: ' . ($preview !== '' ? $preview : 'respuesta vacía');
      return ['rows' => [], 'errors' => $errors, 'debug' => $debug];
    }

    $items = site_stock_bulk_ps_extract_rows($json);
    if (!is_array($items)) {
      $errors[] = 'Respuesta inesperada: falta stock_availables.';
      return ['rows' => [], 'errors' => $errors, 'debug' => $debug];
    }

    $itemsCount = count($items);
    if ($itemsCount === 0) {
      $debug['debug_is_valid_empty'] = true;
      $debug['debug_last_count'] = 0;
      break;
    }
    $debug['debug_last_count'] = $itemsCount;
    error_log('[site_stock_bulk][prestashop][snapshot] page=' . ($page + 1) . ' offset=' . $offset . ' count=' . $itemsCount);

    $pageRows = [];
    $productIds = [];
    $combinationIds = [];

    foreach ($items as $node) {
      if (!is_array($node)) {
        continue;
      }
      $idProduct = site_stock_bulk_to_int($node['id_product'] ?? 0);
      $idCombination = site_stock_bulk_to_int($node['id_product_attribute'] ?? 0);
      $qty = site_stock_bulk_to_int($node['quantity'] ?? 0);
      if ($idProduct <= 0) {
        continue;
      }
      $pageRows[] = [
        'id' => site_stock_bulk_to_int($node['id'] ?? 0),
        'id_product' => $idProduct,
        'id_product_attribute' => $idCombination,
        'remote_qty' => $qty,
      ];
      $productIds[] = $idProduct;
      if ($idCombination > 0) {
        $combinationIds[] = $idCombination;
      }
    }

    if (count($pageRows) === 0) {
      break;
    }

    $debug['debug_last_phase'] = 'resolve_combinations';
    $combResult = site_stock_bulk_ps_fetch_references($baseUrl, $apiKey, 'combinations', $combinationIds);
    $combRef = (array)($combResult['references'] ?? []);
    $combFailed = array_fill_keys(array_map('intval', (array)($combResult['failed_ids'] ?? [])), true);
    foreach ((array)($combResult['errors'] ?? []) as $error) {
      $errors[] = (string)$error;
    }

    $debug['debug_last_phase'] = 'resolve_products';
    $prodResult = site_stock_bulk_ps_fetch_references($baseUrl, $apiKey, 'products', $productIds);
    $prodRef = (array)($prodResult['references'] ?? []);
    $prodFailed = array_fill_keys(array_map('intval', (array)($prodResult['failed_ids'] ?? [])), true);
    foreach ((array)($prodResult['errors'] ?? []) as $error) {
      $errors[] = (string)$error;
    }

    foreach ($pageRows as $entry) {
      $sku = '';
      $rowStatus = 'PENDING';
      $rowMessage = null;
      if ($entry['id_product_attribute'] > 0) {
        $sku = trim((string)($combRef[$entry['id_product_attribute']] ?? ''));
      }
      if ($sku === '') {
        $sku = trim((string)($prodRef[$entry['id_product']] ?? ''));
      }
      $combinationFailed = $entry['id_product_attribute'] > 0 && isset($combFailed[(int)$entry['id_product_attribute']]);
      $productFailed = isset($prodFailed[(int)$entry['id_product']]);
      if ($sku === '' && ($combinationFailed || $productFailed)) {
        $rowStatus = 'ERROR';
        $rowMessage = 'No se pudo resolver SKU';
      }
      $rows[] = [
        'sku' => $sku !== '' ? $sku : null,
        'remote_qty' => (int)$entry['remote_qty'],
        'external_id' => (string)$entry['id_product'],
        'external_variant_id' => (string)$entry['id_product_attribute'],
        'status' => $rowStatus,
        'message' => $rowMessage,
      ];
    }

    if ($itemsCount < $limit) {
      break;
    }
    $offset += $limit;
  }

  return ['rows' => $rows, 'errors' => $errors, 'debug' => $debug];
}

function site_stock_bulk_ml_snapshot(PDO $pdo, array $site, int $siteId): array {
  $clientId = trim((string)($site['ml_client_id'] ?? ''));
  $clientSecret = trim((string)($site['ml_client_secret'] ?? ''));
  $refreshToken = trim((string)($site['ml_refresh_token'] ?? ''));
  $mlStatus = strtoupper(trim((string)($site['ml_status'] ?? '')));
  if ($clientId === '' || $clientSecret === '') {
    throw new RuntimeException('Sitio MercadoLibre sin credenciales.');
  }
  if ($refreshToken === '' && $mlStatus !== 'CONNECTED') {
    throw new RuntimeException('MercadoLibre sin refresh token. Volvé a conectar el sitio.');
  }

  $mlUserId = stock_sync_ml_ensure_user_id($pdo, $siteId, $site);
  $offset = 0;
  $limit = 50;
  $maxPages = 400;
  $rows = [];
  $errors = [];

  for ($page = 0; $page < $maxPages; $page++) {
    $query = http_build_query([
      'search_type' => 'scan',
      'offset' => $offset,
      'limit' => $limit,
    ], '', '&', PHP_QUERY_RFC3986);

    $searchUrl = 'https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $query;
    error_log('[site_stock_bulk][ml] GET ' . $searchUrl);
    try {
      $search = ml_api_request($siteId, 'GET', $searchUrl);
    } catch (Throwable $t) {
      return ['rows' => [], 'errors' => [$t->getMessage()]];
    }

    if ($search['code'] < 200 || $search['code'] >= 300) {
      $preview = site_stock_bulk_preview_text((string)($search['raw'] ?? ''));
      $errors[] = 'HTTP ' . (int)$search['code'] . ': ' . ($preview !== '' ? $preview : 'sin respuesta');
      return ['rows' => [], 'errors' => $errors];
    }

    $itemIds = $search['json']['results'] ?? [];
    if (!is_array($itemIds) || count($itemIds) === 0) {
      error_log('[site_stock_bulk][ml] items/search sin resultados query=' . $query);
      break;
    }

    $itemIds = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $itemIds), static fn(string $v): bool => $v !== ''));
    foreach (array_chunk($itemIds, 20) as $chunk) {
      $detailUrl = 'https://api.mercadolibre.com/items?ids=' . rawurlencode(implode(',', $chunk)) . '&include_attributes=all';
      try {
        $detail = ml_api_request($siteId, 'GET', $detailUrl);
      } catch (Throwable $t) {
        $errors[] = $t->getMessage();
        continue;
      }
      if ($detail['code'] < 200 || $detail['code'] >= 300 || !is_array($detail['json'])) {
        $preview = site_stock_bulk_preview_text((string)($detail['raw'] ?? ''));
        $errors[] = 'HTTP ' . (int)$detail['code'] . ': ' . ($preview !== '' ? $preview : 'sin respuesta');
        continue;
      }

      foreach ($detail['json'] as $detailRow) {
        if (!is_array($detailRow) || !isset($detailRow['body']) || !is_array($detailRow['body'])) {
          continue;
        }
        $item = $detailRow['body'];
        $itemId = trim((string)($item['id'] ?? ''));
        if ($itemId === '') {
          continue;
        }

        $variations = $item['variations'] ?? [];
        if (is_array($variations) && count($variations) > 0) {
          foreach ($variations as $variation) {
            if (!is_array($variation)) {
              continue;
            }
            $sku = trim(stock_sync_ml_variation_sku($variation, $item));
            if ($sku === '') {
              continue;
            }
            $rows[] = [
              'sku' => $sku,
              'remote_qty' => site_stock_bulk_to_int($variation['available_quantity'] ?? 0),
              'external_id' => $itemId,
              'external_variant_id' => trim((string)($variation['id'] ?? '')),
            ];
          }
          continue;
        }

        $sku = trim(stock_sync_ml_extract_sku($item, ''));
        if ($sku === '') {
          continue;
        }
        $rows[] = [
          'sku' => $sku,
          'remote_qty' => site_stock_bulk_to_int($item['available_quantity'] ?? 0),
          'external_id' => $itemId,
          'external_variant_id' => '',
        ];
      }
    }

    if (count($itemIds) < $limit) {
      break;
    }
    $offset += $limit;
  }

  return ['rows' => $rows, 'errors' => $errors];
}

function site_stock_bulk_insert_snapshot_rows(PDO $pdo, int $runId, array $rows): int {
  if (count($rows) === 0) {
    return 0;
  }

  $sql = 'INSERT INTO site_stock_bulk_rows (run_id, sku, remote_qty, status, message, external_id, external_variant_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE remote_qty = VALUES(remote_qty), status = VALUES(status), message = VALUES(message), ts_qty_before = NULL, ts_qty_after = NULL, remote_qty_before = NULL, remote_qty_after = NULL, updated_at = CURRENT_TIMESTAMP';
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
      site_stock_bulk_to_int($row['remote_qty'] ?? 0),
      $status,
      $message,
      trim((string)($row['external_id'] ?? '')),
      trim((string)($row['external_variant_id'] ?? '')),
    ]);
    $inserted++;
  }

  return $inserted;
}

function site_stock_bulk_ml_set_stock(int $siteId, string $itemId, string $variationId, int $newQty): array {
  if ($itemId === '') {
    return ['ok' => false, 'message' => 'Item ID vacío.'];
  }
  $endpoint = $variationId !== ''
    ? 'https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '/variations/' . rawurlencode($variationId)
    : 'https://api.mercadolibre.com/items/' . rawurlencode($itemId);

  try {
    $response = ml_api_request($siteId, 'PUT', $endpoint, ['available_quantity' => $newQty]);
    if ($response['code'] < 200 || $response['code'] >= 300) {
      return ['ok' => false, 'message' => 'Error al actualizar stock en MercadoLibre (HTTP ' . $response['code'] . ').'];
    }
    return ['ok' => true, 'message' => 'Actualizado.'];
  } catch (Throwable $t) {
    return ['ok' => false, 'message' => $t->getMessage()];
  }
}

function site_stock_bulk_ps_set_stock(array $site, int $idProduct, int $idProductAttribute, int $newQty): array {
  $baseUrl = trim((string)($site['ps_base_url'] ?? ''));
  $apiKey = trim((string)($site['ps_api_key'] ?? ''));
  if ($baseUrl === '' || $apiKey === '') {
    return ['ok' => false, 'message' => 'Sitio PrestaShop sin credenciales.'];
  }

  try {
    $stockAvailableId = ps_find_stock_available_id_with_credentials($idProduct, $idProductAttribute, $baseUrl, $apiKey);
    if ($stockAvailableId === null || $stockAvailableId <= 0) {
      return ['ok' => false, 'message' => 'No se encontró stock_available para el SKU.'];
    }

    $template = ps_request_with_credentials('GET', '/api/stock_availables/' . $stockAvailableId, $baseUrl, $apiKey);
    if ($template['code'] < 200 || $template['code'] >= 300) {
      return ['ok' => false, 'message' => 'No se pudo leer stock_available (HTTP ' . $template['code'] . ').'];
    }

    $sx = ps_xml_load((string)$template['body']);
    if (!isset($sx->stock_available)) {
      return ['ok' => false, 'message' => 'Respuesta XML inválida de stock_available.'];
    }

    $sx->stock_available->quantity = (string)$newQty;
    $payload = $sx->asXML();
    if ($payload === false) {
      return ['ok' => false, 'message' => 'No se pudo preparar XML para actualización.'];
    }

    $update = ps_request_with_credentials('PUT', '/api/stock_availables/' . $stockAvailableId, $baseUrl, $apiKey, $payload);
    if ($update['code'] < 200 || $update['code'] >= 300) {
      return ['ok' => false, 'message' => 'Error al actualizar stock en PrestaShop (HTTP ' . $update['code'] . ').'];
    }
    return ['ok' => true, 'message' => 'Actualizado.'];
  } catch (Throwable $t) {
    return ['ok' => false, 'message' => $t->getMessage()];
  }
}
