<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock_sync.php';
require_once __DIR__ . '/../includes/integrations/PrestashopAdapter.php';
require_once __DIR__ . '/../includes/integrations/MercadoLibreAdapter.php';

ensure_stock_sync_schema();

$pdo = db();
$limit = 50;

$jobs = $pdo->query("SELECT id, site_id, product_id, payload_json, attempts FROM ts_sync_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT {$limit}")->fetchAll();

foreach ($jobs as $job) {
  $jobId = (int)$job['id'];
  $siteId = (int)$job['site_id'];
  $productId = (int)$job['product_id'];

  $pdo->prepare("UPDATE ts_sync_jobs SET status = 'running', attempts = attempts + 1, updated_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$jobId]);

  try {
    $payload = json_decode((string)$job['payload_json'], true);
    if (!is_array($payload) || !array_key_exists('qty', $payload)) {
      throw new RuntimeException('Payload inválido.');
    }

    $siteSt = $pdo->prepare("SELECT s.id, s.conn_type, s.conn_enabled, s.sync_stock_enabled, s.stock_sync_mode, sc.channel_type, sc.enabled,
      sc.ps_base_url, sc.ps_api_key, sc.ml_refresh_token
      FROM sites s
      LEFT JOIN site_connections sc ON sc.site_id = s.id
      WHERE s.id = ? LIMIT 1");
    $siteSt->execute([$siteId]);
    $site = $siteSt->fetch();
    if (!$site) {
      throw new RuntimeException('Sitio inexistente.');
    }

    if (!stock_sync_allows_push($site)) {
      throw new RuntimeException('Modo de sincronización sin push TS→Sitio.');
    }

    $productSt = $pdo->prepare('SELECT sku FROM products WHERE id = ? LIMIT 1');
    $productSt->execute([$productId]);
    $product = $productSt->fetch();
    $sku = trim((string)($product['sku'] ?? ''));

    $qty = (int)$payload['qty'];
    $connType = stock_sync_conn_type($site);
    if ($connType === 'prestashop') {
      $map = stock_sync_load_ml_mapping($pdo, $siteId, $productId, $sku);
      if (!$map) {
        throw new RuntimeException('Falta mapping en site_product_map para site_id=' . $siteId . ' product_id=' . $productId . ' sku=' . $sku);
      }
      PrestashopAdapter::updateStock((string)$site['ps_base_url'], (string)$site['ps_api_key'], (string)$map['remote_id'], $map['remote_variant_id'] !== null ? (string)$map['remote_variant_id'] : null, $qty);
    } elseif ($connType === 'mercadolibre') {
      $links = stock_sync_load_ml_links($pdo, $siteId, $productId);
      if (count($links) === 0) {
        throw new RuntimeException('No se puede sincronizar a MercadoLibre: falta vincular Item ID/Variante para este producto.');
      }
      foreach ($links as $link) {
        $itemId = trim((string)($link['ml_item_id'] ?? ''));
        $variationId = trim((string)($link['ml_variation_id'] ?? ''));
        if ($itemId === '') {
          continue;
        }
        $variationToken = $variationId !== '' ? $variationId : null;
        $endpoint = $variationToken !== null
          ? 'https://api.mercadolibre.com/items/' . rawurlencode($itemId) . '/variations/' . rawurlencode($variationToken)
          : 'https://api.mercadolibre.com/items/' . rawurlencode($itemId);
        $response = ml_api_request($siteId, 'PUT', $endpoint, ['available_quantity' => $qty]);
        if ($response['code'] < 200 || $response['code'] >= 300) {
          throw new RuntimeException('Error al actualizar stock en MercadoLibre (HTTP ' . $response['code'] . ').');
        }
        stock_sync_ml_mark_push($pdo, $siteId, $itemId, $variationId !== '' ? $variationId : null, 'TSWORK');
      }
    } else {
      throw new RuntimeException('Tipo de conexión no soportado para sync de stock.');
    }

    $pdo->prepare("UPDATE ts_sync_jobs SET status = 'done', last_error = NULL, updated_at = NOW() WHERE id = ?")->execute([$jobId]);
  } catch (Throwable $e) {
    $pdo->prepare("UPDATE ts_sync_jobs SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")
      ->execute([mb_substr($e->getMessage(), 0, 2000), $jobId]);
  }
}

echo json_encode(['ok' => true, 'processed' => count($jobs)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
