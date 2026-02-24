<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock_sync.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../includes/integrations/MercadoLibreAdapter.php';

ensure_stock_sync_schema();

$pdo = db();
$sites = $pdo->query("SELECT s.id, s.last_sync_at, s.sync_stock_enabled, s.stock_sync_mode, s.conn_enabled, s.conn_type,
  sc.ml_refresh_token
  FROM sites s
  LEFT JOIN site_connections sc ON sc.site_id = s.id
  WHERE s.is_active = 1 AND (s.conn_enabled = 1 OR sc.enabled = 1)")->fetchAll();

$moves = 0;
foreach ($sites as $site) {
  if (stock_sync_conn_type($site) !== 'mercadolibre') {
    continue;
  }
  if (!stock_sync_allows_pull($site)) {
    continue;
  }

  $refreshToken = trim((string)($site['ml_refresh_token'] ?? ''));
  if ($refreshToken === '') {
    continue;
  }

  $siteId = (int)$site['id'];
  $since = null;
  if (!empty($site['last_sync_at'])) {
    $since = gmdate('c', strtotime((string)$site['last_sync_at']));
  }

  try {
    $url = 'https://api.mercadolibre.com/orders/search?seller=me&sort=date_desc';
    if ($since !== null && trim($since) !== '') {
      $url .= '&order.date_created.from=' . rawurlencode($since);
    }
    $ordersResponse = ml_api_request($siteId, 'GET', $url);
    if ($ordersResponse['code'] < 200 || $ordersResponse['code'] >= 300) {
      throw new RuntimeException('No se pudieron obtener órdenes de MercadoLibre (HTTP ' . $ordersResponse['code'] . ').');
    }
    $ordersData = is_array($ordersResponse['json'] ?? null) ? $ordersResponse['json'] : [];
    $orders = is_array($ordersData['results'] ?? null) ? $ordersData['results'] : [];
    foreach ($orders as $order) {
      $orderId = (string)($order['id'] ?? '');
      $items = $order['order_items'] ?? [];
      if (!is_array($items)) {
        continue;
      }
      foreach ($items as $row) {
        $item = $row['item'] ?? [];
        $itemId = (string)($item['id'] ?? '');
        $variationId = isset($item['variation_id']) ? (string)$item['variation_id'] : null;
        $qty = (int)($row['quantity'] ?? 0);
        if ($itemId === '' || $qty <= 0) {
          continue;
        }

        $mapSt = $pdo->prepare('SELECT product_id FROM site_product_map WHERE site_id = ? AND remote_id = ? AND (remote_variant_id <=> ?) LIMIT 1');
        $mapSt->execute([$siteId, $itemId, $variationId]);
        $map = $mapSt->fetch();
        if (!$map) {
          continue;
        }

        $productId = (int)$map['product_id'];
        $eventKey = 'ml:' . $orderId . ':' . $itemId . ':' . ($variationId ?? '0');
        if (!stock_sync_register_lock($siteId, $productId, 'mercadolibre', $eventKey, hash('sha256', $eventKey))) {
          continue;
        }

        add_stock($productId, -$qty, 'Venta ML order ' . $orderId, 0, 'mercadolibre', $siteId, $eventKey);
        $moves++;
      }
    }

    $pdo->prepare('UPDATE sites SET last_sync_at = NOW() WHERE id = ?')->execute([$siteId]);
  } catch (Throwable $e) {
    error_log('[pull_mercadolibre] site_id=' . $siteId . ' error=' . $e->getMessage());
  }
}

echo json_encode(['ok' => true, 'moves' => $moves], JSON_UNESCAPED_UNICODE) . PHP_EOL;
