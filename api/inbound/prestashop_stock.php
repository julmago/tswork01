<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../include/stock_sync.php';
require_once __DIR__ . '/../../include/stock.php';

header('Content-Type: application/json; charset=utf-8');

function inbound_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  inbound_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}


$sourceHeader = strtolower(trim((string)($_SERVER['HTTP_X_TSWORK_SOURCE'] ?? '')));
if ($sourceHeader === 'tswork') {
  inbound_json(['ok' => true, 'ignored' => true, 'reason' => 'Evento originado por TSWork.']);
}

ensure_stock_sync_schema();

$siteId = (int)($_POST['site_id'] ?? 0);
$remoteId = trim((string)($_POST['remote_id'] ?? ''));
$remoteVariantId = trim((string)($_POST['remote_variant_id'] ?? ''));
$newQtyRaw = trim((string)($_POST['new_qty'] ?? ''));
$eventId = trim((string)($_POST['event_id'] ?? ''));
$token = trim((string)($_POST['token'] ?? ''));

if ($siteId <= 0 || $remoteId === '' || $newQtyRaw === '' || !preg_match('/^-?\d+$/', $newQtyRaw)) {
  inbound_json(['ok' => false, 'error' => 'Parámetros inválidos.'], 422);
}
if ($eventId === '') {
  $eventId = 'ps-' . sha1($siteId . '|' . $remoteId . '|' . $remoteVariantId . '|' . $newQtyRaw . '|' . microtime(true));
}

$pdo = db();
$siteSt = $pdo->prepare('SELECT s.id, s.conn_type, s.sync_stock_enabled, s.stock_sync_mode, s.conn_enabled, sc.ps_api_key FROM sites s LEFT JOIN site_connections sc ON sc.site_id = s.id WHERE s.id = ? LIMIT 1');
$siteSt->execute([$siteId]);
$site = $siteSt->fetch();
if (!$site) {
  inbound_json(['ok' => false, 'error' => 'Site no encontrado.'], 404);
}

if ($token === '' || !hash_equals(trim((string)($site['ps_api_key'] ?? '')), $token)) {
  inbound_json(['ok' => false, 'error' => 'Token inválido.'], 403);
}

$variant = $remoteVariantId === '' ? null : $remoteVariantId;
$mapSt = $pdo->prepare('SELECT product_id FROM site_product_map WHERE site_id = ? AND remote_id = ? AND (remote_variant_id <=> ?) LIMIT 1');
$mapSt->execute([$siteId, $remoteId, $variant]);
$map = $mapSt->fetch();
if (!$map) {
  inbound_json(['ok' => false, 'error' => 'No hay mapping para remote_id/variant en site_product_map.'], 404);
}

$productId = (int)$map['product_id'];
if (!stock_sync_allows_pull($site)) {
  inbound_json(['ok' => true, 'ignored' => true, 'reason' => 'Modo sin pull Sitio→TSWork.']);
}

if (!stock_sync_register_lock($siteId, $productId, 'prestashop', $eventId, hash('sha256', $eventId))) {
  inbound_json(['ok' => true, 'ignored' => true, 'reason' => 'Evento ya procesado.']);
}

$qty = (int)$newQtyRaw;
$note = 'Inbound PrestaShop event_id=' . $eventId;
$stock = set_stock($productId, $qty, $note, 0, 'prestashop', $siteId, $eventId);
$skuSt = $pdo->prepare('SELECT sku FROM products WHERE id = ? LIMIT 1');
$skuSt->execute([$productId]);
$skuRow = $skuSt->fetch();
$sku = trim((string)($skuRow['sku'] ?? ''));

stock_sync_mark_update_state($productId, $siteId, 'prestashop_webhook_pull', $eventId, (int)$stock['qty']);

$pushStatus = [];
if ($sku !== '' && stock_sync_allows_pull($site)) {
  $originMode = stock_sync_mode($site);

  if ($originMode === 'SITE_TO_TS') {
    $pushStatus = stock_sync_chain_propagate_pull_update($productId, $sku, (int)$stock['qty'], $siteId);
  } else {
    $pushStatus = stock_sync_propagate_webhook_update($productId, $sku, (int)$stock['qty'], $siteId, 'prestashop', $eventId, 20);
  }
}

inbound_json(['ok' => true, 'stock' => $stock, 'push_status' => $pushStatus]);
