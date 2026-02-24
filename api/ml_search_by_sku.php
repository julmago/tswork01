<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();
ensure_stock_sync_schema();

function ml_search_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function ml_search_value_matches_sku($value, string $sku): bool {
  if (is_scalar($value)) {
    return trim((string)$value) === $sku;
  }
  return false;
}

function ml_search_variation_matches_sku(array $variation, string $sku): bool {
  if (ml_search_value_matches_sku($variation['seller_custom_field'] ?? null, $sku)) {
    return true;
  }

  $candidateLists = [$variation['attributes'] ?? null, $variation['attribute_combinations'] ?? null];
  foreach ($candidateLists as $attributes) {
    if (!is_array($attributes)) {
      continue;
    }
    foreach ($attributes as $attribute) {
      if (!is_array($attribute)) {
        continue;
      }
      $id = strtoupper(trim((string)($attribute['id'] ?? '')));
      if (!in_array($id, ['SELLER_SKU', 'SELLER_CUSTOM_FIELD', 'SELLER_PRODUCT_ID'], true)) {
        continue;
      }
      if (
        ml_search_value_matches_sku($attribute['value_name'] ?? null, $sku)
        || ml_search_value_matches_sku($attribute['value_id'] ?? null, $sku)
        || ml_search_value_matches_sku($attribute['value'] ?? null, $sku)
      ) {
        return true;
      }
    }
  }

  return false;
}

function ml_search_fetch_item(int $siteId, string $itemId, array &$logs): ?array {
  $item = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '?include_attributes=all');
  if ($item['code'] < 200 || $item['code'] >= 300) {
    $logs[] = sprintf('GET /items/%s -> HTTP %d (omitido)', $itemId, (int)$item['code']);
    return null;
  }

  if (!is_array($item['json'])) {
    $logs[] = sprintf('GET /items/%s -> respuesta inválida (omitido)', $itemId);
    return null;
  }

  return $item['json'];
}

function ml_search_build_row(array $itemJson, string $itemId, ?string $variationId, string $matchSource): array {
  return [
    'item_id' => $itemId,
    'variation_id' => $variationId !== null ? $variationId : '',
    'title' => trim((string)($itemJson['title'] ?? '')),
    'status' => trim((string)($itemJson['status'] ?? '')),
    'available_quantity' => (int)($itemJson['available_quantity'] ?? 0),
    'seller_id' => trim((string)($itemJson['seller_id'] ?? '')),
    'match_source' => $matchSource,
  ];
}

$siteId = (int)get('site_id', '0');
$sku = trim((string)get('sku', ''));
if ($siteId <= 0 || $sku === '') {
  ml_search_respond(['ok' => false, 'error' => 'Parámetros inválidos.'], 422);
}

$pdo = db();
$siteSt = $pdo->prepare("SELECT s.id, sc.ml_user_id
  FROM sites s
  LEFT JOIN site_connections sc ON sc.site_id = s.id
  WHERE s.id = ?
    AND (
      LOWER(COALESCE(s.conn_type, '')) = 'mercadolibre'
      OR UPPER(COALESCE(sc.channel_type, '')) = 'MERCADOLIBRE'
    )
  LIMIT 1");
$siteSt->execute([$siteId]);
$site = $siteSt->fetch();
if (!$site) {
  ml_search_respond(['ok' => false, 'error' => 'Sitio de MercadoLibre inválido.'], 404);
}

try {
  $logs = [];
  $me = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/users/me');
  if ($me['code'] < 200 || $me['code'] >= 300) {
    ml_search_respond(['ok' => false, 'error' => 'No se pudo consultar /users/me (HTTP ' . $me['code'] . ').'], 500);
  }
  $mlUserId = trim((string)($me['json']['id'] ?? ''));
  if ($mlUserId === '') {
    ml_search_respond(['ok' => false, 'error' => 'MercadoLibre no devolvió user_id.'], 500);
  }
  $storedUserId = trim((string)($site['ml_user_id'] ?? ''));
  if ($storedUserId !== $mlUserId) {
    $up = $pdo->prepare('UPDATE site_connections SET ml_user_id = ?, updated_at = NOW() WHERE site_id = ?');
    $up->execute([$mlUserId, $siteId]);
    $logs[] = $storedUserId === ''
      ? 'users/me validado: se guardó ml_user_id en site_connections.'
      : 'users/me validado: ml_user_id cambió, se actualizó site_connections.';
  } else {
    $logs[] = 'users/me validado: ml_user_id coincide con site_connections.';
  }

  $rows = [];
  $seen = [];

  $query = http_build_query(['seller_sku' => $sku], '', '&', PHP_QUERY_RFC3986);
  $search = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $query);
  if ($search['code'] < 200 || $search['code'] >= 300) {
    ml_search_respond(['ok' => false, 'error' => 'No se pudo buscar items por seller_sku (HTTP ' . $search['code'] . ').'], 500);
  }

  $itemIds = $search['json']['results'] ?? [];
  if (!is_array($itemIds)) {
    $itemIds = [];
  }
  $logs[] = 'Intento A /items/search?seller_sku: resultados=' . count($itemIds);

  foreach ($itemIds as $itemIdRaw) {
    $itemId = trim((string)$itemIdRaw);
    if ($itemId === '') {
      continue;
    }
    $itemJson = ml_search_fetch_item($siteId, $itemId, $logs);
    if ($itemJson === null) {
      continue;
    }

    $itemMatched = ml_search_value_matches_sku($itemJson['seller_custom_field'] ?? null, $sku);
    if ($itemMatched) {
      $key = $itemId . '|';
      $rows[] = ml_search_build_row($itemJson, $itemId, null, 'item.seller_custom_field');
      $seen[$key] = true;
    }

    $variations = $itemJson['variations'] ?? [];
    if (!is_array($variations)) {
      continue;
    }
    foreach ($variations as $variation) {
      if (!is_array($variation) || !ml_search_variation_matches_sku($variation, $sku)) {
        continue;
      }
      $variationId = trim((string)($variation['id'] ?? ''));
      if ($variationId === '') {
        continue;
      }
      $key = $itemId . '|' . $variationId;
      if (isset($seen[$key])) {
        continue;
      }
      $rows[] = ml_search_build_row($itemJson, $itemId, $variationId, 'variations.attributes.SELLER_SKU');
      $seen[$key] = true;
    }
  }

  if (count($rows) === 0) {
    $queryText = http_build_query(['q' => $sku], '', '&', PHP_QUERY_RFC3986);
    $searchText = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $queryText);
    if ($searchText['code'] >= 200 && $searchText['code'] < 300) {
      $itemIdsText = $searchText['json']['results'] ?? [];
      if (!is_array($itemIdsText)) {
        $itemIdsText = [];
      }
      $logs[] = 'Intento B /items/search?q: resultados=' . count($itemIdsText);

      foreach ($itemIdsText as $itemIdRaw) {
        $itemId = trim((string)$itemIdRaw);
        if ($itemId === '') {
          continue;
        }
        $itemJson = ml_search_fetch_item($siteId, $itemId, $logs);
        if ($itemJson === null) {
          continue;
        }

        if (ml_search_value_matches_sku($itemJson['seller_custom_field'] ?? null, $sku)) {
          $key = $itemId . '|';
          if (!isset($seen[$key])) {
            $rows[] = ml_search_build_row($itemJson, $itemId, null, 'item.seller_custom_field(q)');
            $seen[$key] = true;
          }
        }

        $variations = $itemJson['variations'] ?? [];
        if (!is_array($variations)) {
          continue;
        }
        foreach ($variations as $variation) {
          if (!is_array($variation) || !ml_search_variation_matches_sku($variation, $sku)) {
            continue;
          }
          $variationId = trim((string)($variation['id'] ?? ''));
          if ($variationId === '') {
            continue;
          }
          $key = $itemId . '|' . $variationId;
          if (!isset($seen[$key])) {
            $rows[] = ml_search_build_row($itemJson, $itemId, $variationId, 'variations.attributes.SELLER_SKU(q)');
            $seen[$key] = true;
          }
        }
      }
    } else {
      $logs[] = 'Intento B /items/search?q falló HTTP ' . $searchText['code'];
    }
  }

  if (count($rows) === 0) {
    $offset = 0;
    $limit = 50;
    $page = 0;
    $maxPages = 20;
    $scannedItems = 0;

    while ($page < $maxPages) {
      $scanQuery = http_build_query([
        'search_type' => 'scan',
        'limit' => $limit,
        'offset' => $offset,
      ], '', '&', PHP_QUERY_RFC3986);
      $scan = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $scanQuery);
      if ($scan['code'] < 200 || $scan['code'] >= 300) {
        ml_search_respond(['ok' => false, 'error' => 'No se pudo escanear items del vendedor (HTTP ' . $scan['code'] . ').'], 500);
      }

      $scanItemIds = $scan['json']['results'] ?? [];
      if (!is_array($scanItemIds) || count($scanItemIds) === 0) {
        $logs[] = 'Intento C scan offset=' . $offset . ': resultados=0';
        break;
      }

      $logs[] = 'Intento C scan offset=' . $offset . ': resultados=' . count($scanItemIds);
      foreach ($scanItemIds as $itemIdRaw) {
        $itemId = trim((string)$itemIdRaw);
        if ($itemId === '') {
          continue;
        }
        $scannedItems++;
        $itemJson = ml_search_fetch_item($siteId, $itemId, $logs);
        if ($itemJson === null) {
          continue;
        }

        if (ml_search_value_matches_sku($itemJson['seller_custom_field'] ?? null, $sku)) {
          $key = $itemId . '|';
          if (!isset($seen[$key])) {
            $rows[] = ml_search_build_row($itemJson, $itemId, null, 'item.seller_custom_field(scan)');
            $seen[$key] = true;
          }
        }

        $variations = $itemJson['variations'] ?? [];
        if (!is_array($variations)) {
          continue;
        }
        foreach ($variations as $variation) {
          if (!is_array($variation) || !ml_search_variation_matches_sku($variation, $sku)) {
            continue;
          }
          $variationId = trim((string)($variation['id'] ?? ''));
          if ($variationId === '') {
            continue;
          }
          $key = $itemId . '|' . $variationId;
          if (!isset($seen[$key])) {
            $rows[] = ml_search_build_row($itemJson, $itemId, $variationId, 'variations.attributes.SELLER_SKU(scan)');
            $seen[$key] = true;
          }
        }
      }

      $offset += $limit;
      $page++;
    }

    $logs[] = 'Intento C scan completado: items_inspeccionados=' . $scannedItems . ', matches=' . count($rows);
  }

  if (count($rows) === 0) {
    stock_sync_log('ML search SKU sin resultados', [
      'site_id' => $siteId,
      'sku' => $sku,
      'ml_user_id' => $mlUserId,
      'logs' => $logs,
    ]);
    $logs[] = 'Sin coincidencias por item.seller_custom_field ni variations[].attributes[SELLER_SKU].';
  } else {
    stock_sync_log('ML search SKU con resultados', [
      'site_id' => $siteId,
      'sku' => $sku,
      'ml_user_id' => $mlUserId,
      'matches' => count($rows),
    ]);
  }

  ml_search_respond(['ok' => true, 'seller_id' => $mlUserId, 'rows' => array_values($rows), 'logs' => $logs]);
} catch (Throwable $t) {
  ml_search_respond(['ok' => false, 'error' => $t->getMessage()], 500);
}
