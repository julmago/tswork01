<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_brands_schema();

function scan_api_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function scan_api_has_product_column(string $column): bool {
  static $columns = null;
  if ($columns === null) {
    $columns = [];
    $st = db()->query('SHOW COLUMNS FROM products');
    foreach ($st->fetchAll() as $row) {
      $columns[(string)$row['Field']] = true;
    }
  }
  return isset($columns[$column]);
}

function scan_api_exact_matches(string $q): array {
  $q = trim($q);
  if ($q === '') {
    return [];
  }

  $conditions = [
    'LOWER(p.sku) = LOWER(:q)',
    'LOWER(pc.code) = LOWER(:q)',
    'LOWER(ps.supplier_sku) = LOWER(:q)',
  ];

  foreach (['barcode', 'ean13'] as $column) {
    if (scan_api_has_product_column($column)) {
      $conditions[] = 'LOWER(p.' . $column . ') = LOWER(:q)';
    }
  }

  $sql = "SELECT DISTINCT p.id, p.sku, p.name
    FROM products p
    LEFT JOIN product_codes pc ON pc.product_id = p.id
    LEFT JOIN product_suppliers ps ON ps.product_id = p.id
    WHERE " . implode(' OR ', $conditions) . "
    ORDER BY p.name ASC
    LIMIT 50";

  $st = db()->prepare($sql);
  $st->execute([':q' => $q]);
  return $st->fetchAll();
}

function scan_api_search_matches(string $q): array {
  $q = trim($q);
  if ($q === '') {
    return [];
  }

  $exact = scan_api_exact_matches($q);
  $seen = [];
  foreach ($exact as $row) {
    $seen[(int)$row['id']] = true;
  }

  $like = '%' . $q . '%';
  $sql = "SELECT DISTINCT p.id, p.sku, p.name
    FROM products p
    LEFT JOIN product_codes pc ON pc.product_id = p.id
    LEFT JOIN product_suppliers ps ON ps.product_id = p.id
    WHERE p.name LIKE :like
      OR p.sku LIKE :like
      OR pc.code LIKE :like
      OR ps.supplier_sku LIKE :like";

  foreach (['barcode', 'ean13'] as $column) {
    if (scan_api_has_product_column($column)) {
      $sql .= ' OR p.' . $column . ' LIKE :like';
    }
  }

  $sql .= ' ORDER BY p.name ASC LIMIT 50';
  $st = db()->prepare($sql);
  $st->execute([':like' => $like]);
  foreach ($st->fetchAll() as $row) {
    $id = (int)$row['id'];
    if (!isset($seen[$id])) {
      $exact[] = $row;
      $seen[$id] = true;
    }
  }

  return $exact;
}

function scan_api_add_to_list(int $listId, int $productId, int $delta): void {
  if ($delta > 0) {
    $st = db()->prepare('INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, ?)
      ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = NOW()');
    $st->execute([$listId, $productId, $delta]);
    return;
  }

  $st = db()->prepare('SELECT qty, synced_qty FROM stock_list_items WHERE stock_list_id = ? AND product_id = ? LIMIT 1');
  $st->execute([$listId, $productId]);
  $item = $st->fetch();
  if (!$item) {
    throw new RuntimeException('No se puede restar: el producto no está en el listado.');
  }

  $qty = (int)$item['qty'];
  if ($qty <= 0) {
    throw new RuntimeException('No se puede restar: la cantidad ya está en 0.');
  }

  $newQty = $qty + $delta;
  if ($newQty <= 0) {
    $st = db()->prepare('DELETE FROM stock_list_items WHERE stock_list_id = ? AND product_id = ?');
    $st->execute([$listId, $productId]);

    $stCleanup = db()->prepare('DELETE FROM list_site_sync_progress WHERE list_id = ? AND product_id = ?');
    $stCleanup->execute([$listId, $productId]);
    return;
  }

  $newSynced = min((int)$item['synced_qty'], $newQty);
  $st = db()->prepare('UPDATE stock_list_items SET qty = ?, synced_qty = ?, updated_at = NOW() WHERE stock_list_id = ? AND product_id = ?');
  $st->execute([$newQty, $newSynced, $listId, $productId]);
}

if (!is_post()) {
  scan_api_json(['ok' => false, 'message' => 'Método inválido.'], 405);
}

$action = trim((string)post('action'));

try {
  if ($action === 'lookup') {
    require_permission(can_scan());
    $q = trim((string)post('q'));
    if ($q === '') {
      scan_api_json(['ok' => false, 'message' => 'Escaneá o pegá un código.'], 422);
    }

    $matches = scan_api_exact_matches($q);
    if (count($matches) === 1) {
      scan_api_json(['ok' => true, 'mode' => 'one', 'product' => $matches[0]]);
    }
    if (count($matches) > 1) {
      scan_api_json(['ok' => true, 'mode' => 'many', 'matches' => $matches]);
    }
    scan_api_json(['ok' => true, 'mode' => 'none']);
  }

  if ($action === 'search') {
    require_permission(can_scan());
    $q = trim((string)post('q'));
    scan_api_json(['ok' => true, 'matches' => scan_api_search_matches($q)]);
  }

  if ($action === 'link_code') {
    require_permission(can_add_code());
    $productId = (int)post('product_id', '0');
    $code = trim((string)post('code'));
    $confirm = (int)post('confirm', '0') === 1;

    if ($productId <= 0 || $code === '') {
      scan_api_json(['ok' => false, 'message' => 'Falta producto o código.'], 422);
    }

    $st = db()->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    if (!$st->fetch()) {
      scan_api_json(['ok' => false, 'message' => 'Producto inválido.'], 404);
    }

    $st = db()->prepare('SELECT id, product_id FROM product_codes WHERE LOWER(code) = LOWER(?) LIMIT 1');
    $st->execute([$code]);
    $existing = $st->fetch();

    if ($existing && (int)$existing['product_id'] !== $productId && !$confirm) {
      scan_api_json([
        'ok' => false,
        'needs_confirm' => true,
        'message' => 'El código ya está vinculado a otro producto. Confirmá para reasignarlo.',
      ], 409);
    }

    if ($existing && (int)$existing['product_id'] !== $productId && $confirm) {
      $st = db()->prepare('UPDATE product_codes SET product_id = ? WHERE id = ?');
      $st->execute([$productId, (int)$existing['id']]);
      scan_api_json(['ok' => true]);
    }

    if (!$existing) {
      $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
      $st->execute([$productId, $code]);
    }

    scan_api_json(['ok' => true]);
  }

  if ($action === 'create_product') {
    require_permission(can_edit_product());
    $sku = trim((string)post('sku'));
    $name = trim((string)post('name'));
    $brandId = (int)post('brand_id', '0');
    $code = trim((string)post('code'));

    if ($sku === '' || $name === '' || $code === '') {
      scan_api_json(['ok' => false, 'message' => 'Completá SKU, Nombre y código.'], 422);
    }

    $brandName = '';
    $brandIdValue = null;
    if ($brandId > 0) {
      $st = db()->prepare('SELECT id, name FROM brands WHERE id = ? LIMIT 1');
      $st->execute([$brandId]);
      $brand = $st->fetch();
      if ($brand) {
        $brandIdValue = (int)$brand['id'];
        $brandName = (string)$brand['name'];
      }
    }

    db()->beginTransaction();
    $st = db()->prepare('INSERT INTO products(sku, name, brand, brand_id, updated_at) VALUES(?, ?, ?, ?, NOW())');
    $st->execute([$sku, $name, $brandName, $brandIdValue]);
    $productId = (int)db()->lastInsertId();

    $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
    $st->execute([$productId, $code]);
    db()->commit();

    scan_api_json(['ok' => true, 'product_id' => $productId]);
  }

  if ($action === 'add_to_list') {
    require_permission(can_scan());
    $listId = (int)post('list_id', '0');
    $productId = (int)post('product_id', '0');
    $delta = (int)post('delta', '0');

    if ($listId <= 0 || $productId <= 0 || $delta === 0) {
      scan_api_json(['ok' => false, 'message' => 'Parámetros inválidos.'], 422);
    }

    $st = db()->prepare('SELECT id, status FROM stock_lists WHERE id = ? LIMIT 1');
    $st->execute([$listId]);
    $list = $st->fetch();
    if (!$list) {
      scan_api_json(['ok' => false, 'message' => 'Listado no encontrado.'], 404);
    }
    if ((string)$list['status'] !== 'open') {
      scan_api_json(['ok' => false, 'message' => 'El listado está cerrado.'], 409);
    }

    $st = db()->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    if (!$st->fetch()) {
      scan_api_json(['ok' => false, 'message' => 'Producto inexistente.'], 404);
    }

    scan_api_add_to_list($listId, $productId, $delta);
    scan_api_json(['ok' => true]);
  }

  scan_api_json(['ok' => false, 'message' => 'Acción inválida.'], 400);
} catch (Throwable $e) {
  if (db()->inTransaction()) {
    db()->rollBack();
  }
  scan_api_json(['ok' => false, 'message' => 'No se pudo completar la operación.'], 500);
}
