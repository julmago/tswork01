<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();

function stock_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$action = post('action');
if ($action === '') {
  $action = get('action');
}

$product_id = (int)(post('product_id') !== '' ? post('product_id') : get('product_id', '0'));
if ($product_id <= 0) {
  stock_json(['ok' => false, 'error' => 'product_id inválido.'], 400);
}

$user_id = (int)(current_user()['id'] ?? 0);

try {
  if ($action === 'get_stock') {
    $stock = get_stock($product_id);
    stock_json(['ok' => true, 'stock' => $stock, 'moves' => get_stock_moves($product_id, 20)]);
  }

  if ($action === 'set_stock') {
    if (!is_post()) {
      stock_json(['ok' => false, 'error' => 'Método inválido.'], 405);
    }
    $qty_raw = trim(post('qty'));
    if ($qty_raw === '' || !preg_match('/^-?\d+$/', $qty_raw)) {
      stock_json(['ok' => false, 'error' => 'qty debe ser entero.'], 422);
    }
    $stock = set_stock($product_id, (int)$qty_raw, post('note'), $user_id);
    stock_json(['ok' => true, 'stock' => $stock]);
  }

  if ($action === 'add_stock') {
    if (!is_post()) {
      stock_json(['ok' => false, 'error' => 'Método inválido.'], 405);
    }
    $delta_raw = trim(post('delta'));
    if ($delta_raw === '' || !preg_match('/^-?\d+$/', $delta_raw)) {
      stock_json(['ok' => false, 'error' => 'delta debe ser entero.'], 422);
    }
    $stock = add_stock($product_id, (int)$delta_raw, post('note'), $user_id);
    stock_json(['ok' => true, 'stock' => $stock]);
  }

  stock_json(['ok' => false, 'error' => 'Acción inválida.'], 400);
} catch (InvalidArgumentException $e) {
  stock_json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
  stock_json(['ok' => false, 'error' => 'No se pudo procesar stock.'], 500);
}
