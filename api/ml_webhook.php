<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: text/plain; charset=utf-8');

function ml_webhook_log_path(): string {
  return __DIR__ . '/../logs/ml_web_webhook.log';
}

function ml_webhook_raw_log_path(): string {
  return __DIR__ . '/../logs/ml_webhook_raw.log';
}

function ml_webhook_stock_updates_log_path(): string {
  return __DIR__ . '/../logs/ml_stock_updates.log';
}

function ml_webhook_write_line(string $path, string $line): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    error_log('[ML_WEBHOOK] no se pudo crear directorio de logs: ' . $dir);
    error_log('[ML_WEBHOOK] ' . trim($line));
    return;
  }

  $writeOk = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
  if ($writeOk === false) {
    error_log('[ML_WEBHOOK] fallo escritura log en ' . $path);
    error_log('[ML_WEBHOOK] ' . trim($line));
  }
}

function ml_webhook_log(string $message, array $context = []): void {
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
  if ($context !== []) {
    $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  $line .= PHP_EOL;

  ml_webhook_write_line(ml_webhook_log_path(), $line);
}

function ml_webhook_log_raw_inbound(array $payload): void {
  $line = '[' . date('Y-m-d H:i:s') . '] RAW ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  ml_webhook_write_line(ml_webhook_raw_log_path(), $line);
}

function ml_webhook_log_stock_update(array $payload): void {
  $line = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  ml_webhook_write_line(ml_webhook_stock_updates_log_path(), $line);
}

function ml_webhook_ack(int $status = 200): void {
  http_response_code($status);
  echo "OK webhook endpoint\n";
  if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
  }
}

function ml_webhook_extract_order_id_from_resource(string $resource): ?string {
  $resource = trim($resource);
  if ($resource === '') {
    return null;
  }
  if (preg_match('~/(?:orders|packs)/([0-9]+)~i', $resource, $m)) {
    return trim((string)$m[1]);
  }
  return null;
}

function ml_webhook_validate_secret(array $site, string $rawBody): bool {
  $secret = trim((string)($site['ml_notification_secret'] ?? ''));
  if ($secret === '') {
    return true;
  }

  $provided = trim((string)($_SERVER['HTTP_X_ML_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_MELI_SECRET'] ?? ''));
  if ($provided === '') {
    $provided = trim((string)($_GET['secret'] ?? ''));
  }

  $providedSignature = trim((string)($_SERVER['HTTP_X_ML_SIGNATURE'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
  if (str_starts_with($providedSignature, 'sha256=')) {
    $providedSignature = substr($providedSignature, 7);
  }

  if ($provided !== '' && hash_equals($secret, $provided)) {
    return true;
  }

  if ($providedSignature !== '') {
    $expected = hash_hmac('sha256', $rawBody, $secret);
    if (hash_equals($expected, $providedSignature)) {
      return true;
    }
  }

  return false;
}

function ml_webhook_server_headers(): array {
  $headers = [];
  foreach ($_SERVER as $key => $value) {
    if (!is_string($key) || !is_string($value)) {
      continue;
    }
    if (str_starts_with($key, 'HTTP_')) {
      $name = str_replace('_', '-', substr($key, 5));
      $headers[$name] = $value;
    }
  }
  return $headers;
}

function ml_webhook_ml_fetch_log(array $resp): array {
  return [
    'status' => (int)($resp['code'] ?? 0),
    'body_preview' => mb_substr((string)($resp['raw'] ?? ''), 0, 300),
  ];
}

function ml_webhook_parse_input(string $rawBody): array {
  $input = json_decode($rawBody, true);
  if (is_array($input)) {
    return $input;
  }

  if (isset($_POST) && is_array($_POST) && count($_POST) > 0) {
    return $_POST;
  }

  parse_str($rawBody, $parsed);
  return is_array($parsed) ? $parsed : [];
}

function ml_webhook_build_rows_from_item(int $siteId, string $topic, string $resource, string $itemId): array {
  $rows = [];
  $itemResponse = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/items/' . rawurlencode($itemId));
  ml_webhook_log('ML fetch item response', ['item_id' => $itemId] + ml_webhook_ml_fetch_log($itemResponse));

  if ((int)$itemResponse['code'] < 200 || (int)$itemResponse['code'] >= 300) {
    return [];
  }

  $item = is_array($itemResponse['json'] ?? null) ? $itemResponse['json'] : [];
  $variations = $item['variations'] ?? [];
  if (is_array($variations) && count($variations) > 0) {
    foreach ($variations as $variation) {
      if (!is_array($variation)) {
        continue;
      }
      $variationId = trim((string)($variation['id'] ?? ''));
      if ($variationId === '') {
        continue;
      }
      $rows[] = ['topic' => $topic, 'resource' => $resource, 'item_id' => $itemId, 'variation_id' => $variationId, 'qty' => (int)($variation['available_quantity'] ?? 0)];
    }
    return $rows;
  }

  $rows[] = ['topic' => $topic, 'resource' => $resource, 'item_id' => $itemId, 'variation_id' => null, 'qty' => (int)($item['available_quantity'] ?? 0)];
  return $rows;
}

function ml_webhook_find_links(PDO $pdo, int $siteId, string $itemId, ?string $variationId): array {
  $variationId = $variationId !== null ? trim($variationId) : null;
  if ($variationId === '') {
    $variationId = null;
  }

  $st = $pdo->prepare('SELECT l.product_id, l.ml_item_id, l.ml_variation_id, l.last_push_ts, l.last_sync_source, p.sku
    FROM ts_ml_links l
    INNER JOIN products p ON p.id = l.product_id
    WHERE l.site_id = ?
      AND l.ml_item_id = ?
      AND ((? IS NULL AND (l.ml_variation_id IS NULL OR l.ml_variation_id = "" OR l.ml_variation_id = "0")) OR l.ml_variation_id = ?)');
  $st->execute([$siteId, $itemId, $variationId, $variationId]);
  $rows = $st->fetchAll();
  if (is_array($rows) && count($rows) > 0) {
    return $rows;
  }

  $st = $pdo->prepare('SELECT spm.product_id, spm.ml_item_id, spm.ml_variation_id, NULL AS last_push_ts, NULL AS last_sync_source, p.sku
    FROM site_product_map spm
    INNER JOIN products p ON p.id = spm.product_id
    WHERE spm.site_id = ?
      AND spm.ml_item_id = ?
      AND ((? IS NULL AND (spm.ml_variation_id IS NULL OR spm.ml_variation_id = "" OR spm.ml_variation_id = "0")) OR spm.ml_variation_id = ?)');
  $st->execute([$siteId, $itemId, $variationId, $variationId]);
  $rows = $st->fetchAll();
  return is_array($rows) ? $rows : [];
}

function ml_webhook_should_skip_loop(?string $lastPushTs, ?string $lastSyncSource, int $windowSeconds = 20): bool {
  $source = strtoupper(trim((string)$lastSyncSource));
  if ($source !== 'TSWORK') {
    return false;
  }
  $lastPushTs = trim((string)$lastPushTs);
  if ($lastPushTs === '') {
    return false;
  }
  $ts = strtotime($lastPushTs);
  if ($ts === false) {
    return false;
  }
  return (time() - $ts) <= $windowSeconds;
}

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$rawBody = (string)file_get_contents('php://input');
$input = ml_webhook_parse_input($rawBody);

ml_webhook_log('Webhook inbound', [
  'method' => $requestMethod,
  'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
  'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
  'headers' => ml_webhook_server_headers(),
  'raw_body' => mb_substr($rawBody, 0, 2000),
]);
ml_webhook_log_raw_inbound([
  'method' => $requestMethod,
  'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
  'querystring' => (string)($_SERVER['QUERY_STRING'] ?? ''),
  'headers' => ml_webhook_server_headers(),
  'raw_body' => $rawBody,
  'post' => $_POST,
]);

if ($requestMethod === 'GET') {
  ml_webhook_ack(200);
  exit;
}

if ($requestMethod !== 'POST') {
  ml_webhook_ack(200);
  ml_webhook_log('Ignored non-POST webhook request', ['method' => $requestMethod]);
  exit;
}

ml_webhook_ack(200);

ensure_sites_schema();
ensure_stock_schema();
ensure_stock_sync_schema();

$topic = strtolower(trim((string)($input['topic'] ?? $input['type'] ?? '')));
$resource = trim((string)($input['resource'] ?? ''));
$mlUserId = trim((string)($input['user_id'] ?? $input['userId'] ?? ''));

ml_webhook_log('Webhook parsed payload', [
  'topic' => $topic,
  'resource' => $resource,
  'user_id' => $mlUserId,
]);

if ($topic === '' || $resource === '' || $mlUserId === '') {
  ml_webhook_log('Payload incompleto, se ignora', ['topic' => $topic, 'resource' => $resource, 'user_id' => $mlUserId]);
  exit;
}

$site = stock_sync_ml_find_site_by_user_id($mlUserId);
if (!$site) {
  ml_webhook_log('No existe sitio ML activo para user_id', ['user_id' => $mlUserId]);
  exit;
}
$siteId = (int)($site['id'] ?? 0);
if ($siteId <= 0) {
  ml_webhook_log('Sitio inválido para webhook', ['site' => $site]);
  exit;
}

if (!ml_webhook_validate_secret($site, $rawBody)) {
  ml_webhook_log('Webhook no autorizado por secreto', ['site_id' => $siteId]);
  exit;
}

$mlStatus = strtoupper(trim((string)($site['ml_status'] ?? '')));
if ($mlStatus !== 'CONNECTED') {
  ml_webhook_log('Sitio ML no conectado', ['site_id' => $siteId, 'ml_status' => $mlStatus]);
  exit;
}

$refreshToken = trim((string)($site['ml_refresh_token'] ?? ''));
if ($refreshToken === '') {
  ml_webhook_log('site sin token', ['site_id' => $siteId, 'topic' => $topic, 'resource' => $resource]);
  exit;
}

$rows = [];
if ($topic === 'items' || $topic === 'stock') {
  $itemId = stock_sync_ml_extract_item_id_from_resource($resource);
  if ($itemId === null || $itemId === '') {
    ml_webhook_log('ML webhook items/stock sin item_id válido', ['site_id' => $siteId, 'resource' => $resource]);
    exit;
  }
  $rows = ml_webhook_build_rows_from_item($siteId, $topic, $resource, $itemId);
} elseif ($topic === 'orders') {
  $orderId = ml_webhook_extract_order_id_from_resource($resource);
  if ($orderId === null || $orderId === '') {
    ml_webhook_log('ML webhook orders sin order_id válido', ['site_id' => $siteId, 'resource' => $resource]);
    exit;
  }

  $orderResponse = ml_api_request($siteId, 'GET', 'https://api.mercadolibre.com/orders/' . rawurlencode($orderId));
  ml_webhook_log('ML fetch order response', ['site_id' => $siteId, 'order_id' => $orderId] + ml_webhook_ml_fetch_log($orderResponse));

  if ((int)$orderResponse['code'] < 200 || (int)$orderResponse['code'] >= 300) {
    ml_webhook_log('ML webhook fallo al consultar order', ['site_id' => $siteId, 'order_id' => $orderId, 'http_code' => $orderResponse['code']]);
    exit;
  }

  $order = is_array($orderResponse['json'] ?? null) ? $orderResponse['json'] : [];
  $orderItems = $order['order_items'] ?? [];
  if (!is_array($orderItems) || count($orderItems) === 0) {
    ml_webhook_log('ML webhook order sin items', ['site_id' => $siteId, 'order_id' => $orderId]);
    exit;
  }

  foreach ($orderItems as $orderItemRow) {
    $orderItem = is_array($orderItemRow) ? (array)($orderItemRow['item'] ?? []) : [];
    $itemId = trim((string)($orderItem['id'] ?? ''));
    if ($itemId === '') {
      continue;
    }

    $variationId = trim((string)($orderItem['variation_id'] ?? ''));
    if ($variationId === '') {
      $variationId = null;
    }

    $itemRows = ml_webhook_build_rows_from_item($siteId, $topic, $resource, $itemId);
    foreach ($itemRows as $itemRow) {
      if ($variationId !== null && trim((string)($itemRow['variation_id'] ?? '')) !== $variationId) {
        continue;
      }
      $rows[] = $itemRow;
    }

    if ($variationId !== null && count(array_filter($itemRows, static fn(array $r): bool => trim((string)($r['variation_id'] ?? '')) === $variationId)) === 0) {
      $rows[] = ['topic' => $topic, 'resource' => $resource, 'item_id' => $itemId, 'variation_id' => $variationId, 'qty' => 0];
    }
  }
} else {
  ml_webhook_log('ML webhook recibido (topic ignorado)', ['topic' => $topic, 'site_id' => $siteId, 'resource' => $resource]);
  exit;
}

if (count($rows) === 0) {
  ml_webhook_log('ML webhook sin filas para procesar', ['site_id' => $siteId, 'topic' => $topic, 'resource' => $resource]);
  exit;
}

$pdo = db();
$propagatedProducts = [];
foreach ($rows as $row) {
  $itemId = trim((string)($row['item_id'] ?? ''));
  if ($itemId === '') {
    continue;
  }
  $variationId = $row['variation_id'] !== null ? trim((string)$row['variation_id']) : null;
  if ($variationId === '') {
    $variationId = null;
  }

  stock_propagation_trace('WEBHOOK IN', [
    'topic' => trim((string)($row['topic'] ?? $topic)),
    'resource' => (string)($row['resource'] ?? $resource),
    'site_id' => $siteId,
    'item_id' => $itemId,
    'variation_id' => $variationId,
  ]);

  $links = ml_webhook_find_links($pdo, $siteId, $itemId, $variationId);
  if (count($links) === 0) {
    ml_webhook_log('sin vínculo', ['site_id' => $siteId, 'item_id' => $itemId, 'variation_id' => $variationId]);
    continue;
  }

  foreach ($links as $link) {
    $productId = (int)($link['product_id'] ?? 0);
    if ($productId <= 0) {
      continue;
    }

    if (ml_webhook_should_skip_loop((string)($link['last_push_ts'] ?? ''), (string)($link['last_sync_source'] ?? ''), 20)) {
      ml_webhook_log('Ignorado por ventana anti-loop', [
        'site_id' => $siteId,
        'product_id' => $productId,
        'item_id' => $itemId,
        'variation_id' => $variationId,
        'last_push_ts' => (string)($link['last_push_ts'] ?? ''),
        'last_sync_source' => (string)($link['last_sync_source'] ?? ''),
      ]);
      stock_propagation_trace('PROPAGATION DECISION', [
        'site_id' => $siteId,
        'product_id' => $productId,
        'reason' => 'SKIP anti_loop',
      ]);
      continue;
    }

    $qty = (int)$row['qty'];
    $currentStock = get_stock($productId);
    $oldStock = (int)($currentStock['qty'] ?? 0);
    $eventId = 'ml-webhook-' . sha1($siteId . '|' . $itemId . '|' . ($variationId ?? '0') . '|' . $qty . '|' . gmdate('YmdHi'));
    $note = sprintf('ML webhook | item_id=%s | variation_id=%s | topic=%s', $itemId, $variationId ?? '-', (string)$row['topic']);

    set_stock($productId, $qty, $note, 0, 'mercadolibre', $siteId, $eventId, 'sync_pull_ml');

    stock_sync_mark_update_state($productId, $siteId, 'ml_webhook_pull', $eventId, $qty);

    $up = $pdo->prepare('UPDATE ts_ml_links SET last_sync_source = ?, last_push_ts = NOW() WHERE site_id = ? AND product_id = ? AND ml_item_id = ? AND ((? IS NULL AND (ml_variation_id IS NULL OR ml_variation_id = "" OR ml_variation_id = "0")) OR ml_variation_id = ?)');
    $up->execute(['ML', $siteId, $productId, $itemId, $variationId, $variationId]);

    stock_sync_write_log($productId, $siteId, 'sync_pull_ml', [
      'connector' => 'mercadolibre',
      'source_site_id' => $siteId,
      'item_id' => $itemId,
      'variation_id' => $variationId,
      'qty' => $qty,
      'resource' => (string)$row['resource'],
      'topic' => trim((string)($row['topic'] ?? $topic)),
      'reason' => 'ML webhook',
      'old_stock' => $oldStock,
      'new_stock' => $qty,
    ]);

    ml_webhook_log('Stock actualizado en TSWork por webhook', [
      'site_id' => $siteId,
      'product_id' => $productId,
      'item_id' => $itemId,
      'variation_id' => $variationId,
      'old_stock' => $oldStock,
      'new_stock' => $qty,
    ]);
    stock_propagation_trace('TSWORK STOCK UPDATED', [
      'product_id' => $productId,
      'old_qty' => $oldStock,
      'new_qty' => $qty,
    ]);

    ml_webhook_log_stock_update([
      'sku' => (string)($link['sku'] ?? ''),
      'product_id' => $productId,
      'item_id' => $itemId,
      'variation_id' => $variationId,
      'old_stock' => $oldStock,
      'new_stock' => $qty,
    ]);

    $sku = trim((string)($link['sku'] ?? ''));
    if ($sku !== '') {
      $propagationKey = $productId . '|' . $qty;
      if (!isset($propagatedProducts[$propagationKey])) {
        $propagatedProducts[$propagationKey] = true;
        $pushStatus = stock_sync_propagate_webhook_update($productId, $sku, $qty, $siteId, 'mercadolibre', $eventId, 20);
        ml_webhook_log('Propagación webhook ML ejecutada', [
          'source_site_id' => $siteId,
          'product_id' => $productId,
          'sku' => $sku,
          'new_stock' => $qty,
          'results' => $pushStatus,
        ]);
      }
    }
  }
}

exit;
