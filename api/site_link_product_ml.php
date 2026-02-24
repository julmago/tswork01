<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();
ensure_stock_sync_schema();

function ml_link_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!is_post()) {
  ml_link_respond(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$siteId = (int)post('site_id', '0');
$sku = trim(post('sku'));
$itemId = strtoupper(trim(post('item_id')));
$variationId = trim(post('variation_id'));
$sellerId = trim(post('seller_id'));
if ($variationId === '') {
  $variationId = null;
}
if ($sellerId === '') {
  $sellerId = null;
}

if ($siteId <= 0 || $sku === '' || $itemId === '') {
  ml_link_respond(['ok' => false, 'error' => 'Datos incompletos para vincular.'], 422);
}

try {
  $pdo = db();

  $siteSt = $pdo->prepare("SELECT s.id
    FROM sites s
    LEFT JOIN site_connections sc ON sc.site_id = s.id
    WHERE s.id = ?
      AND (
        LOWER(COALESCE(s.conn_type, '')) = 'mercadolibre'
        OR UPPER(COALESCE(sc.channel_type, '')) = 'MERCADOLIBRE'
      )
    LIMIT 1");
  $siteSt->execute([$siteId]);
  if (!$siteSt->fetch()) {
    ml_link_respond(['ok' => false, 'error' => 'Sitio inválido para MercadoLibre.'], 404);
  }

  $productSt = $pdo->prepare('SELECT id, sku FROM products WHERE sku = ? LIMIT 1');
  $productSt->execute([$sku]);
  $product = $productSt->fetch();
  if (!$product) {
    ml_link_respond(['ok' => false, 'error' => 'No existe producto TS Work con ese SKU.'], 404);
  }

  $productId = (int)$product['id'];

  $up = $pdo->prepare("INSERT INTO site_product_map(site_id, product_id, remote_id, remote_variant_id, remote_sku, ml_item_id, ml_variation_id, ml_seller_id, ml_last_bind_at, updated_at)
    VALUES(?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      remote_id = VALUES(remote_id),
      remote_variant_id = VALUES(remote_variant_id),
      remote_sku = VALUES(remote_sku),
      ml_item_id = VALUES(ml_item_id),
      ml_variation_id = VALUES(ml_variation_id),
      ml_seller_id = VALUES(ml_seller_id),
      ml_last_bind_at = NOW(),
      updated_at = NOW()");
  $up->execute([$siteId, $productId, $itemId, $variationId, (string)$product['sku'], $itemId, $variationId, $sellerId]);

  ml_link_respond([
    'ok' => true,
    'site_id' => $siteId,
    'product_id' => $productId,
    'product_sku' => (string)$product['sku'],
    'item_id' => $itemId,
    'variation_id' => $variationId ?? '',
  ]);
} catch (Throwable $t) {
  ml_link_respond(['ok' => false, 'error' => 'No se pudo guardar la vinculación.'], 500);
}
