<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../prestashop.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();
ensure_stock_schema();
ensure_stock_sync_schema();
set_time_limit(0);

function bulk_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function bulk_channel(string $value): string {
  $value = strtoupper(trim($value));
  if (!in_array($value, ['PRESTASHOP', 'MERCADOLIBRE'], true)) {
    return 'NONE';
  }
  return $value;
}

function bulk_to_int($value): int {
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

function bulk_ps_reference_from_combination(array &$cache, string $baseUrl, string $apiKey, int $idCombination): string {
  if ($idCombination <= 0) {
    return '';
  }
  if (array_key_exists($idCombination, $cache)) {
    return (string)$cache[$idCombination];
  }

  $response = ps_request_with_credentials('GET', '/api/combinations/' . $idCombination . '?display=[id,reference]', $baseUrl, $apiKey);
  if ($response['code'] < 200 || $response['code'] >= 300) {
    $cache[$idCombination] = '';
    return '';
  }
  $sx = ps_xml_load((string)$response['body']);
  $reference = '';
  if (isset($sx->combination->reference)) {
    $reference = trim((string)$sx->combination->reference);
  }
  $cache[$idCombination] = $reference;
  return $reference;
}

function bulk_ps_reference_from_product(array &$cache, string $baseUrl, string $apiKey, int $idProduct): string {
  if ($idProduct <= 0) {
    return '';
  }
  if (array_key_exists($idProduct, $cache)) {
    return (string)$cache[$idProduct];
  }

  $response = ps_request_with_credentials('GET', '/api/products/' . $idProduct . '?display=[id,reference]', $baseUrl, $apiKey);
  if ($response['code'] < 200 || $response['code'] >= 300) {
    $cache[$idProduct] = '';
    return '';
  }
  $sx = ps_xml_load((string)$response['body']);
  $reference = '';
  if (isset($sx->product->reference)) {
    $reference = trim((string)$sx->product->reference);
  }
  $cache[$idProduct] = $reference;
  return $reference;
}

function bulk_ps_list_rows(array $site): array {
  $baseUrl = trim((string)($site['ps_base_url'] ?? ''));
  $apiKey = trim((string)($site['ps_api_key'] ?? ''));
  if ($baseUrl === '' || $apiKey === '') {
    throw new RuntimeException('Sitio PrestaShop sin credenciales.');
  }

  $query = http_build_query([
    'display' => '[id,id_product,id_product_attribute,quantity]',
    'limit' => '100000',
  ], '', '&', PHP_QUERY_RFC3986);
  $response = ps_request_with_credentials('GET', '/api/stock_availables?' . $query, $baseUrl, $apiKey);
  if ($response['code'] < 200 || $response['code'] >= 300) {
    throw new RuntimeException('No se pudo listar stock de PrestaShop (HTTP ' . $response['code'] . ').');
  }

  $sx = ps_xml_load((string)$response['body']);
  $rows = [];
  $productCache = [];
  $combinationCache = [];
  if (!isset($sx->stock_availables->stock_available)) {
    return $rows;
  }

  foreach ($sx->stock_availables->stock_available as $stockNode) {
    $idProduct = bulk_to_int((string)($stockNode->id_product ?? '0'));
    $idProductAttribute = bulk_to_int((string)($stockNode->id_product_attribute ?? '0'));
    $quantity = bulk_to_int((string)($stockNode->quantity ?? '0'));

    $sku = '';
    if ($idProductAttribute > 0) {
      $sku = bulk_ps_reference_from_combination($combinationCache, $baseUrl, $apiKey, $idProductAttribute);
    }
    if ($sku === '' && $idProduct > 0) {
      $sku = bulk_ps_reference_from_product($productCache, $baseUrl, $apiKey, $idProduct);
    }

    if ($sku === '') {
      continue;
    }

    $rows[] = [
      'sku' => $sku,
      'stock_remote' => $quantity,
      'id_product' => $idProduct,
      'id_product_attribute' => $idProductAttribute,
    ];
  }

  return $rows;
}

function bulk_ps_set_stock(array $site, int $idProduct, int $idProductAttribute, int $newQty): array {
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

function bulk_ml_item_sku(array $item): string {
  return stock_sync_ml_extract_sku($item, '');
}

function bulk_ml_list_rows(PDO $pdo, array $site, int $siteId): array {
  $mlUserId = stock_sync_ml_ensure_user_id($pdo, $siteId, $site);
  $rows = [];
  $offset = 0;
  $limit = 50;
  $maxPages = 200;

  for ($page = 0; $page < $maxPages; $page++) {
    $query = http_build_query([
      'search_type' => 'scan',
      'offset' => $offset,
      'limit' => $limit,
    ], '', '&', PHP_QUERY_RFC3986);
    $search = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $query);
    if ($search['code'] < 200 || $search['code'] >= 300) {
      throw new RuntimeException('No se pudo listar items de MercadoLibre (HTTP ' . $search['code'] . ').');
    }

    $itemIds = $search['json']['results'] ?? [];
    if (!is_array($itemIds) || count($itemIds) === 0) {
      break;
    }

    foreach ($itemIds as $itemIdRaw) {
      $itemId = trim((string)$itemIdRaw);
      if ($itemId === '') {
        continue;
      }

      $item = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '?include_attributes=all');
      if ($item['code'] < 200 || $item['code'] >= 300 || !is_array($item['json'])) {
        continue;
      }
      $itemJson = $item['json'];
      $variations = $itemJson['variations'] ?? [];
      if (is_array($variations) && count($variations) > 0) {
        foreach ($variations as $variation) {
          if (!is_array($variation)) {
            continue;
          }
          $sku = trim(stock_sync_ml_variation_sku($variation, $itemJson));
          if ($sku === '') {
            continue;
          }
          $rows[] = [
            'sku' => $sku,
            'stock_remote' => bulk_to_int($variation['available_quantity'] ?? 0),
            'item_id' => $itemId,
            'variation_id' => trim((string)($variation['id'] ?? '')),
          ];
        }
        continue;
      }

      $sku = trim(bulk_ml_item_sku($itemJson));
      if ($sku === '') {
        continue;
      }
      $rows[] = [
        'sku' => $sku,
        'stock_remote' => bulk_to_int($itemJson['available_quantity'] ?? 0),
        'item_id' => $itemId,
        'variation_id' => '',
      ];
    }

    $offset += $limit;
  }

  return $rows;
}

function bulk_ml_set_stock(int $siteId, string $itemId, string $variationId, int $newQty): array {
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

$siteId = (int)post('site_id', '0');
$action = trim((string)post('action', ''));
$mode = trim((string)post('mode', ''));
if ($siteId <= 0) {
  bulk_respond(['ok' => false, 'error' => 'site_id inválido.'], 422);
}
if (!in_array($action, ['import', 'export'], true)) {
  bulk_respond(['ok' => false, 'error' => 'Acción inválida.'], 422);
}
if (!in_array($mode, ['set', 'add'], true)) {
  bulk_respond(['ok' => false, 'error' => 'Modo inválido.'], 422);
}

$pdo = db();
$st = $pdo->prepare('SELECT s.id, s.channel_type AS site_channel_type, sc.channel_type, sc.ps_base_url, sc.ps_api_key, sc.ml_client_id, sc.ml_client_secret, sc.ml_refresh_token, sc.ml_status, sc.ml_user_id
  FROM sites s
  LEFT JOIN site_connections sc ON sc.site_id = s.id
  WHERE s.id = ?
  LIMIT 1');
$st->execute([$siteId]);
$site = $st->fetch();
if (!$site) {
  bulk_respond(['ok' => false, 'error' => 'Sitio no encontrado.'], 404);
}

$channel = bulk_channel((string)($site['channel_type'] ?? $site['site_channel_type'] ?? 'NONE'));
if ($channel === 'NONE') {
  bulk_respond(['ok' => false, 'error' => 'Sitio sin conexión configurada.'], 422);
}

try {
  $remoteRows = [];
  if ($channel === 'PRESTASHOP') {
    $remoteRows = bulk_ps_list_rows($site);
  } elseif ($channel === 'MERCADOLIBRE') {
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
    $remoteRows = bulk_ml_list_rows($pdo, $site, $siteId);
  }

  usort($remoteRows, static function (array $a, array $b): int {
    return strcasecmp((string)$a['sku'], (string)$b['sku']);
  });

  $resultRows = [];

  $stProducts = $pdo->query('SELECT p.id, p.sku, COALESCE(ps.qty, 0) AS qty FROM products p LEFT JOIN ts_product_stock ps ON ps.product_id = p.id');
  $products = $stProducts ? $stProducts->fetchAll() : [];
  $productBySku = [];
  foreach ($products as $product) {
    $skuKey = mb_strtoupper(trim((string)($product['sku'] ?? '')));
    if ($skuKey === '') {
      continue;
    }
    if (!isset($productBySku[$skuKey])) {
      $productBySku[$skuKey] = [];
    }
    $productBySku[$skuKey][] = [
      'id' => (int)$product['id'],
      'sku' => trim((string)$product['sku']),
      'qty' => bulk_to_int($product['qty'] ?? 0),
    ];
  }

  foreach ($remoteRows as $remoteRow) {
    $sku = trim((string)($remoteRow['sku'] ?? ''));
    $skuKey = mb_strtoupper($sku);
    $remoteQty = bulk_to_int($remoteRow['stock_remote'] ?? 0);

    if ($sku === '') {
      continue;
    }

    if (!isset($productBySku[$skuKey]) || count($productBySku[$skuKey]) === 0) {
      $resultRows[] = [
        'sku' => $sku,
        'status' => 'omitido',
        'stock_origin' => $action === 'import' ? $remoteQty : null,
        'stock_dest_before' => null,
        'stock_dest_after' => null,
        'message' => 'SKU no existe en TSWork.',
      ];
      continue;
    }

    foreach ($productBySku[$skuKey] as $tsProduct) {
      $tsQtyBefore = bulk_to_int($tsProduct['qty'] ?? 0);
      if ($action === 'import') {
        $newTsQty = $mode === 'add' ? $tsQtyBefore + $remoteQty : $remoteQty;
        set_stock((int)$tsProduct['id'], $newTsQty, 'Bulk import sitio ' . $siteId . ' SKU ' . $sku, 0, strtolower($channel), $siteId, null, 'sync_pull_bulk');

        $resultRows[] = [
          'sku' => $sku,
          'status' => 'actualizado',
          'stock_origin' => $remoteQty,
          'stock_dest_before' => $tsQtyBefore,
          'stock_dest_after' => $newTsQty,
          'message' => 'TSWork actualizado.',
        ];
        continue;
      }

      $remoteBefore = $remoteQty;
      $remoteAfter = $mode === 'add' ? $remoteBefore + $tsQtyBefore : $tsQtyBefore;
      $setResult = ['ok' => false, 'message' => 'Canal no soportado'];
      if ($channel === 'PRESTASHOP') {
        $setResult = bulk_ps_set_stock($site, (int)($remoteRow['id_product'] ?? 0), (int)($remoteRow['id_product_attribute'] ?? 0), $remoteAfter);
      } elseif ($channel === 'MERCADOLIBRE') {
        $setResult = bulk_ml_set_stock($siteId, trim((string)($remoteRow['item_id'] ?? '')), trim((string)($remoteRow['variation_id'] ?? '')), $remoteAfter);
      }

      $resultRows[] = [
        'sku' => $sku,
        'status' => !empty($setResult['ok']) ? 'actualizado' : 'error',
        'stock_origin' => $tsQtyBefore,
        'stock_dest_before' => $remoteBefore,
        'stock_dest_after' => $remoteAfter,
        'message' => (string)($setResult['message'] ?? ''),
      ];
    }
  }

  usort($resultRows, static function (array $a, array $b): int {
    return strcasecmp((string)$a['sku'], (string)$b['sku']);
  });

  bulk_respond([
    'ok' => true,
    'rows' => $resultRows,
    'processed' => count($resultRows),
    'total_remote' => count($remoteRows),
  ]);
} catch (Throwable $t) {
  bulk_respond(['ok' => false, 'error' => $t->getMessage()], 500);
}
