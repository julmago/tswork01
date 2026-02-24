<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_stock_schema();

function set_stock_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!is_post()) {
  set_stock_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

$token = post('csrf_token');
if ($token === '' || !csrf_is_valid($token)) {
  set_stock_json(['ok' => false, 'error' => 'CSRF inválido.'], 419);
}

if (!can_set_stock()) {
  set_stock_json(['ok' => false, 'error' => 'Sin permisos.'], 403);
}

$product_ids = $_POST['product_ids'] ?? [];
if (!is_array($product_ids)) {
  $product_ids = [];
}

$clean_ids = [];
foreach ($product_ids as $idRaw) {
  $id = (int)$idRaw;
  if ($id > 0) {
    $clean_ids[$id] = $id;
  }
}
$clean_ids = array_values($clean_ids);

if (!$clean_ids) {
  set_stock_json(['ok' => false, 'error' => 'Debe seleccionar al menos 1 producto.'], 422);
}

$new_qty_raw = trim((string)($_POST['new_qty'] ?? ''));
if ($new_qty_raw === '' || !preg_match('/^-?\d+$/', $new_qty_raw)) {
  set_stock_json(['ok' => false, 'error' => 'new_qty debe ser entero.'], 422);
}
$new_qty = (int)$new_qty_raw;

$note = trim((string)($_POST['note'] ?? ''));
$user_id = (int)(current_user()['id'] ?? 0);

$checkProductSt = db()->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
$updated_count = 0;
$errors = [];

foreach ($clean_ids as $product_id) {
  try {
    $checkProductSt->execute([$product_id]);
    if (!$checkProductSt->fetchColumn()) {
      $errors[] = ['product_id' => $product_id, 'error' => 'Producto inexistente'];
      continue;
    }

    set_stock($product_id, $new_qty, $note, $user_id, 'tswork', null, null, 'SET');
    $updated_count++;
  } catch (Throwable $e) {
    $errors[] = ['product_id' => $product_id, 'error' => 'No se pudo actualizar'];
  }
}

set_stock_json([
  'ok' => true,
  'updated_count' => $updated_count,
  'errors' => $errors,
]);
