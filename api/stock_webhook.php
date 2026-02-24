<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');

function stock_webhook_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}


function stock_webhook_log_invalid_payload(string $reason, string $rawBody, $decoded = null): void {
  $preview = trim($rawBody);
  if (strlen($preview) > 2000) {
    $preview = substr($preview, 0, 2000) . '...(truncated)';
  }

  $safeDecoded = null;
  if (is_array($decoded)) {
    $safeDecoded = $decoded;
    unset($safeDecoded['signature'], $safeDecoded['secret'], $safeDecoded['webhook_secret'], $safeDecoded['token'], $safeDecoded['api_key']);
  }

  error_log('[stock_webhook] Payload inválido: ' . $reason . ' raw=' . $preview . ' decoded=' . json_encode($safeDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  stock_webhook_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$sourceHeader = strtolower(trim((string)($_SERVER['HTTP_X_TSWORK_SOURCE'] ?? '')));
if ($sourceHeader === 'tswork') {
  stock_webhook_json(['ok' => true, 'ignored' => true, 'reason' => 'Evento originado por TSWork.']);
}

ensure_sites_schema();
ensure_stock_schema();
ensure_stock_sync_schema();

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || trim($rawBody) === '') {
  stock_webhook_log_invalid_payload('body vacío', is_string($rawBody) ? $rawBody : '');
  stock_webhook_json(['ok' => false, 'error' => 'Body vacío.'], 422);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
  stock_webhook_log_invalid_payload('json inválido', $rawBody);
  stock_webhook_json(['ok' => false, 'error' => 'JSON inválido.'], 422);
}

$siteId = (int)($data['shop_id'] ?? $data['site_id'] ?? 0);
$sku = trim((string)($data['sku'] ?? ''));
$qtyRaw = $data['stock'] ?? $data['qty_new'] ?? null;
$event = trim((string)($data['event'] ?? 'webhook_stock'));
$timestamp = trim((string)($data['timestamp'] ?? ''));
if ($timestamp === '') {
  $timestamp = gmdate('c');
}

if ($sku === '' || !is_numeric($qtyRaw)) {
  stock_webhook_log_invalid_payload('faltan sku/stock válidos', $rawBody, $data);
  stock_webhook_json(['ok' => false, 'error' => 'Payload inválido.'], 422);
}

$qtyNew = (int)$qtyRaw;
$traceId = 'wh-' . substr(sha1((string)$siteId . '|' . $sku . '|' . $timestamp . '|' . bin2hex(random_bytes(6))), 0, 16);
$pdo = db();
if ($siteId > 0) {
  $siteSt = $pdo->prepare('SELECT s.id, s.name, s.stock_sync_mode, s.sync_stock_enabled FROM sites s WHERE s.id = ? LIMIT 1');
  $siteSt->execute([$siteId]);
  $site = $siteSt->fetch();
  if (!$site) {
    stock_webhook_json(['ok' => false, 'error' => 'Site no encontrado.'], 404);
  }
}

if ($siteId > 0 && isset($site) && !stock_sync_allows_pull($site)) {
  stock_webhook_json(['ok' => true, 'ignored' => true, 'reason' => 'Modo sin pull Sitio→TSWork.']);
}

$st = $pdo->prepare('SELECT id, sku FROM products WHERE sku = ? ORDER BY id ASC');
$st->execute([$sku]);
$products = $st->fetchAll();
if (!$products) {
  stock_webhook_json(['ok' => false, 'error' => 'SKU no encontrado en TS Work.', 'site_id' => $siteId, 'sku' => $sku], 404);
}

if (count($products) > 1) {
  stock_webhook_json([
    'ok' => false,
    'error' => 'SKU duplicado en TS Work. Abortado para evitar inconsistencias.',
    'site_id' => $siteId,
    'sku' => $sku,
    'matches' => array_map(static fn(array $row): int => (int)$row['id'], $products),
  ], 409);
}

$productId = (int)$products[0]['id'];
$prev = get_stock($productId);
$note = sprintf(
  'sync_pull_webhook site_id=%d sku=%s prev=%d new=%d event=%s ts=%s',
  $siteId,
  $sku,
  (int)$prev['qty'],
  $qtyNew,
  $event,
  $timestamp
);
$eventId = 'ps-webhook-' . sha1($siteId . '|' . $sku . '|' . $timestamp . '|' . $event);
$stock = set_stock($productId, $qtyNew, $note, 0, 'prestashop', $siteId, $eventId, 'sync_pull_webhook');
stock_propagation_trace('WEBHOOK_IN', [
  'trace_id' => $traceId,
  'origin_site_id' => $siteId,
  'sku' => $sku,
  'new_stock' => $qtyNew,
]);
stock_propagation_trace('TS_UPDATED', [
  'trace_id' => $traceId,
  'sku' => $sku,
  'new_stock' => (int)$stock['qty'],
]);
$pushStatus = [];
if ($siteId > 0 && isset($site)) {
  $originMode = stock_sync_mode($site);
  if ($originMode === 'SITE_TO_TS') {
    $pushStatus = stock_sync_propagate_webhook_update($productId, $sku, (int)$stock['qty'], $siteId, 'prestashop', $eventId, 20, 'SITE_WEBHOOK', $traceId, $originMode);
  } else {
    $pushStatus = sync_push_stock_to_sites($sku, (int)$stock['qty'], $siteId, $productId);
  }
} else {
  $pushStatus = sync_push_stock_to_sites($sku, (int)$stock['qty'], null, $productId);
}

$okPushCount = 0;
$errorPushes = [];
foreach ($pushStatus as $status) {
  if ((bool)($status['ok'] ?? false)) {
    $okPushCount++;
  } else {
    $errorPushes[] = 'sitio ' . (int)($status['site_id'] ?? 0) . ': ' . ((string)($status['error'] ?? '') !== '' ? (string)$status['error'] : 'error desconocido');
  }
}

if ($errorPushes) {
  $st = db()->prepare("UPDATE ts_stock_moves SET note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note, '') = '' THEN '' ELSE ' | ' END, ?) WHERE product_id = ? ORDER BY id DESC LIMIT 1");
  $st->execute(['sync_push ERROR: ' . implode(' ; ', $errorPushes), $productId]);
} elseif ($okPushCount > 0) {
  $st = db()->prepare("UPDATE ts_stock_moves SET reason = 'sync_push', note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note, '') = '' THEN '' ELSE ' | ' END, ?) WHERE product_id = ? ORDER BY id DESC LIMIT 1");
  $st->execute(['sync_push OK: ' . $okPushCount . ' sitio(s) / sku ' . $sku, $productId]);
}

stock_webhook_json([
  'ok' => true,
  'site_id' => $siteId,
  'sku' => $sku,
  'product_id' => $productId,
  'prev_qty' => (int)$prev['qty'],
  'new_qty' => (int)$stock['qty'],
  'push_ok' => $okPushCount,
  'push_errors' => $errorPushes,
]);
