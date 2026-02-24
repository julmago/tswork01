<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();
ensure_stock_sync_schema();

function ml_link_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!is_post()) {
  ml_link_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$productId = (int)post('product_id', '0');
$siteId = (int)post('site_id', '0');
$itemId = strtoupper(trim(post('ml_item_id')));
$variationId = trim(post('ml_variation_id'));
$mlSku = trim(post('ml_sku'));
$title = trim(post('title'));

if ($variationId === '') {
  $variationId = null;
}
if ($mlSku === '') {
  $mlSku = null;
}
if ($title === '') {
  $title = null;
}

if ($productId <= 0 || $siteId <= 0 || $itemId === '') {
  ml_link_json(['ok' => false, 'error' => 'Datos incompletos para vincular.'], 422);
}

try {
  $pdo = db();

  $productSt = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
  $productSt->execute([$productId]);
  if (!$productSt->fetch()) {
    ml_link_json(['ok' => false, 'error' => 'Producto inválido.'], 404);
  }

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
    ml_link_json(['ok' => false, 'error' => 'Sitio inválido para MercadoLibre.'], 404);
  }

  $existsSt = $pdo->prepare('SELECT id FROM ts_ml_links WHERE product_id = ? AND site_id = ? AND ml_item_id = ? AND ml_variation_id <=> ? LIMIT 1');
  $existsSt->execute([$productId, $siteId, $itemId, $variationId]);
  $existing = $existsSt->fetch();
  if ($existing) {
    ml_link_json(['ok' => true, 'already_linked' => true, 'link_id' => (int)$existing['id']]);
  }

  $ins = $pdo->prepare('INSERT INTO ts_ml_links(product_id, site_id, ml_item_id, ml_variation_id, ml_sku, title, created_at) VALUES(?, ?, ?, ?, ?, ?, NOW())');
  $ins->execute([$productId, $siteId, $itemId, $variationId, $mlSku, $title]);

  ml_link_json([
    'ok' => true,
    'already_linked' => false,
    'link_id' => (int)$pdo->lastInsertId(),
  ]);
} catch (Throwable $t) {
  ml_link_json(['ok' => false, 'error' => 'No se pudo guardar la vinculación.'], 500);
}
