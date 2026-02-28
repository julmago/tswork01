<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/pricing.php';
require_once __DIR__ . '/include/stock.php';
require_once __DIR__ . '/include/stock_sync.php';
require_login();
ensure_product_suppliers_schema();
ensure_brands_schema();
ensure_sites_schema();
ensure_stock_schema();
ensure_stock_sync_schema();
ensure_product_codes_schema();

$id = (int)get('id','0');
if ($id <= 0) abort(400, 'Falta id.');

$st = db()->prepare("SELECT p.*, b.name AS brand_name
  FROM products p
  LEFT JOIN brands b ON b.id = p.brand_id
  WHERE p.id = ?
  LIMIT 1");
$st->execute([$id]);
$product = $st->fetch();
if (!$product) abort(404, 'Producto no encontrado.');

$error = '';
$message = '';
$can_product_edit_master = can_edit_product();
$can_add_code = can_add_code();
$can_edit_data = $can_product_edit_master && hasPerm('product_edit_data');
$can_edit_providers = $can_product_edit_master && hasPerm('product_edit_providers');
$can_edit_stock = $can_product_edit_master && hasPerm('product_edit_stock');
$can_edit_ml = $can_product_edit_master && hasPerm('product_edit_ml');
$can_pull_ps_stock = $can_edit_stock && hasPerm('product_stock_pull_prestashop');

$parse_supplier_cost_decimal = static function (string $supplier_cost_raw): ?int {
  $supplier_cost_raw = str_replace(',', '.', trim($supplier_cost_raw));
  if ($supplier_cost_raw === '') {
    return null;
  }

  return max(0, (int)round((float)$supplier_cost_raw));
};


$is_non_blocking_stock_push_error = static function (string $error): bool {
  $normalized = mb_strtolower(trim($error));
  if ($normalized === '') {
    return false;
  }

  return str_contains($normalized, 'falta vincular item id/variante');
};
if (is_post() && post('action') === 'update') {
  require_permission($can_edit_data);
  $sku = post('sku');
  $name = post('name');
  $brand_id = (int)post('brand_id', '0');
  $sale_mode = post('sale_mode', 'UNIDAD');
  $sale_units_per_pack = post('sale_units_per_pack');

  if (!in_array($sale_mode, ['UNIDAD', 'PACK'], true)) {
    $sale_mode = 'UNIDAD';
  }

  $sale_units_per_pack_value = null;
  if ($sale_mode === 'PACK') {
    $sale_units_per_pack_value = (int)$sale_units_per_pack;
    if ($sale_units_per_pack_value <= 0) {
      $error = 'Si el modo de venta es Pack, indicá unidades por pack mayores a 0.';
    }
  }

  if ($error === '' && ($sku === '' || $name === '')) {
    $error = 'SKU y Nombre son obligatorios.';
  } elseif ($error === '') {
    try {
      $brand_name = '';
      $brand_id_value = null;
      if ($brand_id > 0) {
        $st = db()->prepare("SELECT id, name FROM brands WHERE id = ? LIMIT 1");
        $st->execute([$brand_id]);
        $brand_row = $st->fetch();
        if ($brand_row) {
          $brand_id_value = (int)$brand_row['id'];
          $brand_name = (string)$brand_row['name'];
        }
      }

      $st = db()->prepare("UPDATE products SET sku=?, name=?, brand=?, brand_id=?, sale_mode=?, sale_units_per_pack=?, updated_at=NOW() WHERE id=?");
      $st->execute([$sku, $name, $brand_name, $brand_id_value, $sale_mode, $sale_units_per_pack_value, $id]);
      $message = 'Producto actualizado.';
    } catch (Throwable $t) {
      $error = 'No se pudo actualizar. Puede que el SKU ya exista.';
    }
  }
}

if (is_post() && post('action') === 'add_code') {
  require_permission($can_add_code);
  $is_ajax_request = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
  $code = trim(post('code'));
  $code_type = post('code_type');
  $confirm_duplicate = post('confirm_duplicate') === '1';
  if (!in_array($code_type, ['BARRA','MPN'], true)) {
    $code_type = 'BARRA';
  }

  if ($code === '') {
    if ($is_ajax_request) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'ok' => false,
        'message' => 'empty_code',
        'error' => 'Escaneá un código.',
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $error = 'Escaneá un código.';
  } else {
    try {
      if (in_array($code_type, ['BARRA', 'MPN'], true) && !$confirm_duplicate) {
        $st = db()->prepare("SELECT pc.product_id, p.sku, p.name
          FROM product_codes pc
          JOIN products p ON p.id = pc.product_id
          WHERE pc.code = ?
            AND pc.code_type = ?
            AND pc.product_id <> ?
          LIMIT 1");
        $st->execute([$code, $code_type, $id]);
        $existing_code = $st->fetch();

        if ($existing_code) {
          if ($is_ajax_request) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
              'ok' => false,
              'needs_confirm' => true,
              'message' => 'duplicate_code',
              'existing' => [
                'product_id' => (int)$existing_code['product_id'],
                'sku' => (string)$existing_code['sku'],
                'name' => (string)$existing_code['name'],
              ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
          }

          $error = 'Ese código ya existe en otro producto.';
        }
      }

      if ($error !== '') {
        if ($is_ajax_request) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode([
            'ok' => false,
            'message' => 'duplicate_code',
            'error' => $error,
          ], JSON_UNESCAPED_UNICODE);
          exit;
        }
      }

      $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, ?)");
      $st->execute([$id, $code, $code_type]);

      if ($is_ajax_request) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
          'ok' => true,
          'message' => 'code_added',
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }

      $message = 'Código agregado.';
    } catch (Throwable $t) {
      $error = 'No se pudo agregar el código.';
      if ($is_ajax_request) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
          'ok' => false,
          'message' => 'insert_error',
          'error' => $error,
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
  }
}

if (is_post() && post('action') === 'delete_code') {
  require_permission($can_add_code);
  $code_id = (int)post('code_id','0');
  if ($code_id > 0) {
    $st = db()->prepare("DELETE FROM product_codes WHERE id = ? AND product_id = ?");
    $st->execute([$code_id, $id]);
    $message = 'Código eliminado.';
  }
}

if (is_post() && post('action') === 'add_supplier_link') {
  require_permission($can_edit_providers);
  $supplier_id = (int)post('supplier_id', '0');
  $supplier_sku = post('supplier_sku');
  $cost_type = post('cost_type', 'UNIDAD');
  $units_per_pack = post('units_per_pack');
  $supplier_cost_raw = trim((string)post('supplier_cost'));

  if (!in_array($cost_type, ['UNIDAD', 'PACK'], true)) {
    $cost_type = 'UNIDAD';
  }

  $units_per_pack_value = null;
  if ($cost_type === 'PACK') {
    $units_per_pack_value = (int)$units_per_pack;
    if ($units_per_pack_value <= 0) {
      $error = 'Si el costo del proveedor es Pack, indicá unidades por pack mayores a 0.';
    }
  }


  $supplier_cost_value = $parse_supplier_cost_decimal($supplier_cost_raw);
  $supplier_for_cost = [];
  if ($supplier_id > 0) {
    $st = db()->prepare('SELECT import_default_units_per_pack FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplier_id]);
    $supplier_for_cost = $st->fetch() ?: [];
  }

  $cost_unitario_value = get_effective_unit_cost([
    'supplier_cost' => $supplier_cost_value,
    'cost_type' => $cost_type,
    'units_per_pack' => $units_per_pack_value,
    'units_pack' => (int)($product['sale_units_per_pack'] ?? 0),
  ], $supplier_for_cost);
  $cost_unitario_value = ($cost_unitario_value === null) ? null : (int)round($cost_unitario_value, 0);

  if ($error === '' && $supplier_id <= 0) {
    $error = 'Seleccioná un proveedor.';
  }

  if ($error === '') {
    try {
      db()->beginTransaction();
      $st = db()->prepare("UPDATE product_suppliers SET is_active = 0, updated_at = NOW() WHERE product_id = ?");
      $st->execute([$id]);

      $st = db()->prepare("INSERT INTO product_suppliers(product_id, supplier_id, supplier_sku, cost_type, units_per_pack, supplier_cost, cost_unitario, is_active, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE supplier_sku = VALUES(supplier_sku), cost_type = VALUES(cost_type), units_per_pack = VALUES(units_per_pack), supplier_cost = VALUES(supplier_cost), cost_unitario = VALUES(cost_unitario), is_active = 1, updated_at = NOW()");
      $st->execute([$id, $supplier_id, $supplier_sku, $cost_type, $units_per_pack_value, $supplier_cost_value, $cost_unitario_value]);
      db()->commit();
      $message = 'Proveedor vinculado.';
    } catch (Throwable $t) {
      if (db()->inTransaction()) db()->rollBack();
      $error = 'No se pudo vincular el proveedor.';
    }
  }
}

if (is_post() && post('action') === 'update_supplier_link') {
  require_permission($can_edit_providers);
  $link_id = (int)post('edit_link_id', '0');
  $supplier_id = (int)post('supplier_id', '0');
  $supplier_sku = post('supplier_sku');
  $cost_type = post('cost_type', 'UNIDAD');
  $units_per_pack = post('units_per_pack');
  $supplier_cost_raw = trim((string)post('supplier_cost'));

  if ($link_id <= 0) {
    $error = 'Vínculo inválido para editar.';
  }

  if (!in_array($cost_type, ['UNIDAD', 'PACK'], true)) {
    $cost_type = 'UNIDAD';
  }

  $units_per_pack_value = null;
  if ($cost_type === 'PACK') {
    $units_per_pack_value = (int)$units_per_pack;
    if ($units_per_pack_value <= 0) {
      $error = 'Si el costo del proveedor es Pack, indicá unidades por pack mayores a 0.';
    }
  }

  $supplier_cost_value = $parse_supplier_cost_decimal($supplier_cost_raw);
  $supplier_for_cost = [];
  if ($supplier_id > 0) {
    $st = db()->prepare('SELECT import_default_units_per_pack FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplier_id]);
    $supplier_for_cost = $st->fetch() ?: [];
  }

  $cost_unitario_value = get_effective_unit_cost([
    'supplier_cost' => $supplier_cost_value,
    'cost_type' => $cost_type,
    'units_per_pack' => $units_per_pack_value,
    'units_pack' => (int)($product['sale_units_per_pack'] ?? 0),
  ], $supplier_for_cost);
  $cost_unitario_value = ($cost_unitario_value === null) ? null : (int)round($cost_unitario_value, 0);

  if ($error === '' && $supplier_id <= 0) {
    $error = 'Seleccioná un proveedor.';
  }

  if ($error === '') {
    try {
      $st = db()->prepare("UPDATE product_suppliers SET supplier_id = ?, supplier_sku = ?, cost_type = ?, units_per_pack = ?, supplier_cost = ?, cost_unitario = ?, updated_at = NOW() WHERE id = ? AND product_id = ?");
      $st->execute([$supplier_id, $supplier_sku, $cost_type, $units_per_pack_value, $supplier_cost_value, $cost_unitario_value, $link_id, $id]);
      $message = 'Proveedor actualizado.';
    } catch (Throwable $t) {
      $error = 'No se pudo actualizar el proveedor vinculado.';
    }
  }
}

if (is_post() && post('action') === 'delete_supplier_link') {
  require_permission($can_edit_providers);
  $link_id = (int)post('link_id', '0');
  if ($link_id > 0) {
    $st = db()->prepare("DELETE FROM product_suppliers WHERE id = ? AND product_id = ?");
    $st->execute([$link_id, $id]);
    $message = 'Proveedor desvinculado.';
  }
}

if (is_post() && post('action') === 'set_active_supplier') {
  require_permission($can_edit_providers);
  $link_id = (int)post('link_id', '0');
  if ($link_id > 0) {
    try {
      db()->beginTransaction();
      $st = db()->prepare("UPDATE product_suppliers SET is_active = 0, updated_at = NOW() WHERE product_id = ?");
      $st->execute([$id]);

      $st = db()->prepare("UPDATE product_suppliers SET is_active = 1, updated_at = NOW() WHERE id = ? AND product_id = ?");
      $st->execute([$link_id, $id]);
      db()->commit();
      $message = 'Proveedor activo actualizado.';
    } catch (Throwable $t) {
      if (db()->inTransaction()) db()->rollBack();
      $error = 'No se pudo marcar el proveedor activo.';
    }
  }
}

if (is_post() && post('action') === 'create_supplier_inline') {
  require_permission($can_edit_providers);

  header('Content-Type: application/json; charset=utf-8');

  $supplier_name = trim(post('supplier_name'));
  $default_margin_percent = normalize_margin_percent_value(post('default_margin_percent'));

  if ($supplier_name === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Ingresá el nombre del proveedor.']);
    exit;
  }

  if ($default_margin_percent === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Base (%) inválida. Usá un valor entre 0 y 999.99.']);
    exit;
  }

  try {
    $st = db()->prepare('SELECT id, name, default_margin_percent FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $st->execute([$supplier_name]);
    $existing = $st->fetch();

    if ($existing) {
      echo json_encode([
        'ok' => true,
        'supplier' => [
          'id' => (int)$existing['id'],
          'name' => (string)$existing['name'],
          'default_margin_percent' => number_format((float)$existing['default_margin_percent'], 2, '.', ''),
        ],
        'existing' => true,
        'message' => 'Ese proveedor ya existe. Se seleccionó el existente.',
      ]);
      exit;
    }

    $st = db()->prepare('INSERT INTO suppliers(name, default_margin_percent, is_active, updated_at) VALUES(?, ?, 1, NOW())');
    $st->execute([$supplier_name, $default_margin_percent]);

    $supplier_id = (int)db()->lastInsertId();

    echo json_encode([
      'ok' => true,
      'supplier' => [
        'id' => $supplier_id,
        'name' => $supplier_name,
        'default_margin_percent' => $default_margin_percent,
      ],
      'existing' => false,
    ]);
    exit;
  } catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'No se pudo crear el proveedor.']);
    exit;
  }
}

if (is_post() && post('action') === 'stock_set') {
  require_permission($can_edit_stock);
  $qty_raw = trim(post('stock_set_qty'));
  $note = post('stock_note');

  if ($qty_raw === '' || !preg_match('/^-?\d+$/', $qty_raw)) {
    $error = 'El stock debe ser un entero.';
  } else {
    try {
      $stockResult = set_stock($id, (int)$qty_raw, $note, (int)(current_user()['id'] ?? 0));
      $pushStatus = sync_push_stock_to_sites((string)$product['sku'], (int)$stockResult['qty'], null, $id);

      $okPushCount = 0;
      $errorPushes = [];
      foreach ($pushStatus as $status) {
        if ($status['ok']) {
          $okPushCount++;
        } else {
          $errorPushes[] = 'sitio ' . (int)$status['site_id'] . ': ' . ($status['error'] !== '' ? $status['error'] : 'error desconocido');
        }
      }

      if ($errorPushes) {
        $st = db()->prepare("UPDATE ts_stock_moves SET note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note, '') = '' THEN '' ELSE ' | ' END, ?) WHERE product_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute(['sync_push ERROR: ' . implode(' ; ', $errorPushes), $id]);
        $hasBlockingErrors = false;
        foreach ($errorPushes as $errorPush) {
          if (!$is_non_blocking_stock_push_error($errorPush)) {
            $hasBlockingErrors = true;
            break;
          }
        }

        if ($hasBlockingErrors) {
          $error = 'Error enviando stock a sitios: ' . implode(' ; ', $errorPushes);
        } else {
          $message = 'Stock actualizado. Se omitió la sincronización en MercadoLibre para publicaciones sin Item ID/Variante vinculados.';
        }
      } elseif ($okPushCount > 0) {
        $st = db()->prepare("UPDATE ts_stock_moves SET reason = 'sync_push', note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note, '') = '' THEN '' ELSE ' | ' END, ?) WHERE product_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute(['sync_push OK: ' . $okPushCount . ' sitio(s) / sku ' . (string)$product['sku'], $id]);
        $message = 'Stock actualizado. Stock enviado a sitios OK.';
      } else {
        $message = 'Stock actualizado.';
      }
    } catch (InvalidArgumentException $e) {
      $error = $e->getMessage();
    } catch (Throwable $e) {
      $error = 'No se pudo actualizar el stock.';
    }
  }
}

if (is_post() && post('action') === 'stock_add') {
  require_permission($can_edit_stock);
  $delta_raw = trim(post('stock_delta'));
  $note = post('stock_note');

  if ($delta_raw === '' || !preg_match('/^-?\d+$/', $delta_raw)) {
    $error = 'El ajuste debe ser un entero.';
  } else {
    try {
      $stockResult = add_stock($id, (int)$delta_raw, $note, (int)(current_user()['id'] ?? 0));
      $pushStatus = sync_push_stock_to_sites((string)$product['sku'], (int)$stockResult['qty'], null, $id);

      $okPushCount = 0;
      $errorPushes = [];
      foreach ($pushStatus as $status) {
        if ($status['ok']) {
          $okPushCount++;
        } else {
          $errorPushes[] = 'sitio ' . (int)$status['site_id'] . ': ' . ($status['error'] !== '' ? $status['error'] : 'error desconocido');
        }
      }

      if ($errorPushes) {
        $st = db()->prepare("UPDATE ts_stock_moves SET note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note, '') = '' THEN '' ELSE ' | ' END, ?) WHERE product_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute(['sync_push ERROR: ' . implode(' ; ', $errorPushes), $id]);
        $hasBlockingErrors = false;
        foreach ($errorPushes as $errorPush) {
          if (!$is_non_blocking_stock_push_error($errorPush)) {
            $hasBlockingErrors = true;
            break;
          }
        }

        if ($hasBlockingErrors) {
          $error = 'Error enviando stock a sitios: ' . implode(' ; ', $errorPushes);
        } else {
          $message = 'Stock ajustado. Se omitió la sincronización en MercadoLibre para publicaciones sin Item ID/Variante vinculados.';
        }
      } elseif ($okPushCount > 0) {
        $st = db()->prepare("UPDATE ts_stock_moves SET reason = 'sync_push', note = CONCAT(COALESCE(note, ''), CASE WHEN COALESCE(note, '') = '' THEN '' ELSE ' | ' END, ?) WHERE product_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute(['sync_push OK: ' . $okPushCount . ' sitio(s) / sku ' . (string)$product['sku'], $id]);
        $message = 'Stock ajustado. Stock enviado a sitios OK.';
      } else {
        $message = 'Stock ajustado.';
      }
    } catch (InvalidArgumentException $e) {
      $error = $e->getMessage();
    } catch (Throwable $e) {
      $error = 'No se pudo ajustar el stock.';
    }
  }
}

if (is_post() && post('action') === 'pull_stock_prestashop') {
  require_permission($can_pull_ps_stock);
  $siteId = (int)post('site_id', '0');
  $syncSites = get_prestashop_sync_sites();
  $targetSite = null;
  foreach ($syncSites as $syncSite) {
    if ((int)$syncSite['id'] === $siteId) {
      $targetSite = $syncSite;
      break;
    }
  }

  if ($targetSite === null) {
    $error = 'Sitio PrestaShop inválido para traer stock.';
  } else {
    $pulledQty = pull_stock_from_prestashop($targetSite, (string)$product['sku']);
    if ($pulledQty === null) {
      $error = 'No se pudo traer stock desde PrestaShop.';
    } else {
      try {
        $previousQty = (int)get_stock($id)['qty'];
        set_stock(
          $id,
          $pulledQty,
          'sync_pull: sitio ' . (string)$targetSite['id'] . ' / sku ' . (string)$product['sku'],
          (int)(current_user()['id'] ?? 0),
          'prestashop',
          (int)$targetSite['id'],
          'sync_pull_' . (string)$targetSite['id'] . '_' . (string)$id . '_' . time()
        );
        $pdo = db();
        $st = $pdo->prepare("UPDATE ts_stock_moves SET reason = 'sync_pull', note = ? WHERE product_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute(['sync_pull: sitio ' . (string)$targetSite['id'] . ' / sku ' . (string)$product['sku'] . ' / prev=' . $previousQty . ' new=' . $pulledQty, $id]);
        $message = 'Stock traído desde PrestaShop: ' . $pulledQty;
      } catch (Throwable $e) {
        $error = 'No se pudo actualizar stock local con el valor de PrestaShop.';
      }
    }
  }
}

$brands = fetch_brands();

// recargar
$st = db()->prepare("SELECT p.*, b.name AS brand_name
  FROM products p
  LEFT JOIN brands b ON b.id = p.brand_id
  WHERE p.id = ?
  LIMIT 1");
$st->execute([$id]);
$product = $st->fetch();

$st = db()->prepare("SELECT id, code, code_type, created_at FROM product_codes WHERE product_id = ? ORDER BY id DESC");
$st->execute([$id]);
$codes = $st->fetchAll();

$st = db()->query("SELECT id, name, default_margin_percent FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
$suppliers = $st->fetchAll();

$st = db()->prepare("SELECT ps.id, ps.supplier_id, ps.supplier_sku, ps.cost_type, ps.units_per_pack, ps.supplier_cost, ps.cost_unitario, ps.is_active, s.name AS supplier_name,
  CASE
    WHEN ps.supplier_cost IS NULL THEN NULL
    WHEN ps.cost_type = 'PACK' THEN ROUND(ps.supplier_cost / COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1), 4)
    ELSE ps.supplier_cost
  END AS normalized_unit_cost,
  CASE
    WHEN p.sale_mode = 'PACK' AND COALESCE(p.sale_units_per_pack, 0) > 0 THEN
      ROUND((CASE
        WHEN ps.supplier_cost IS NULL THEN NULL
        WHEN ps.cost_type = 'PACK' THEN ps.supplier_cost / COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1)
        ELSE ps.supplier_cost
      END) * p.sale_units_per_pack, 0)
    ELSE
      ROUND((CASE
        WHEN ps.supplier_cost IS NULL THEN NULL
        WHEN ps.cost_type = 'PACK' THEN ps.supplier_cost / COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1)
        ELSE ps.supplier_cost
      END), 0)
  END AS normalized_product_cost
  FROM product_suppliers ps
  INNER JOIN products p ON p.id = ps.product_id
  INNER JOIN suppliers s ON s.id = ps.supplier_id
  WHERE ps.product_id = ?
  ORDER BY ps.is_active DESC, ps.id DESC");
$st->execute([$id]);
$supplier_links = $st->fetchAll();

$st = db()->prepare("SELECT s.id, s.name
  FROM sites s
  LEFT JOIN site_connections sc ON sc.site_id = s.id
  WHERE s.is_active = 1
    AND (
      LOWER(COALESCE(s.conn_type, '')) = 'mercadolibre'
      OR UPPER(COALESCE(sc.channel_type, '')) = 'MERCADOLIBRE'
    )
  ORDER BY s.name ASC, s.id ASC");
$st->execute();
$ml_sites = $st->fetchAll();

$ml_site_names = [];
foreach ($ml_sites as $mlSite) {
  $ml_site_names[(int)$mlSite['id']] = (string)$mlSite['name'];
}

$ml_links = [];
if ($ml_sites) {
  $st = db()->prepare('SELECT l.id, l.product_id, l.site_id, l.ml_item_id, l.ml_variation_id, l.ml_sku, l.title, l.created_at
    FROM ts_ml_links l
    WHERE l.product_id = ?
    ORDER BY l.site_id ASC, l.id DESC');
  $st->execute([$id]);
  $ml_links = $st->fetchAll();
}


$ml_last_sync_by_site = [];
if ($ml_sites) {
  $mlLastSyncSt = db()->prepare("SELECT site_id, MAX(created_at) AS last_sync_at FROM stock_logs WHERE product_id = ? AND action = 'sync_pull_ml' AND site_id IS NOT NULL GROUP BY site_id");
  $mlLastSyncSt->execute([$id]);
  foreach ($mlLastSyncSt->fetchAll() as $mlSyncRow) {
    $mlLastSiteId = (int)($mlSyncRow['site_id'] ?? 0);
    if ($mlLastSiteId <= 0) {
      continue;
    }
    $ml_last_sync_by_site[$mlLastSiteId] = (string)($mlSyncRow['last_sync_at'] ?? '');
  }
}

$ts_stock = get_stock($id);
$ts_stock_moves = get_stock_moves($id, 20);
$prestashop_sync_sites = get_prestashop_sync_sites();
$running_qty = (int)$ts_stock['qty'];
foreach ($ts_stock_moves as $index => $move) {
  $stock_resultante = $move['stock_resultante'] ?? null;
  if ($stock_resultante === null) {
    $stock_resultante = $running_qty;
    $running_qty -= (int)$move['delta'];
  }
  $ts_stock_moves[$index]['result_qty'] = (int)$stock_resultante;
}


if (is_post() && post('action') === 'ml_push_stock') {
  require_permission($can_edit_ml);

  if (!$ml_sites) {
    $error = 'No hay sitios MercadoLibre configurados.';
  } elseif (!$ml_links) {
    $error = 'No hay vínculos de MercadoLibre para este producto.';
  } else {
    $currentQty = (int)($ts_stock['qty'] ?? 0);

    $allSites = stock_sync_active_sites();
    $siteById = [];
    foreach ($allSites as $s) {
      $sid = (int)($s['id'] ?? 0);
      if ($sid > 0) {
        $siteById[$sid] = $s;
      }
    }

    $linkedSiteIds = [];
    foreach ($ml_links as $lnk) {
      $sid = (int)($lnk['site_id'] ?? 0);
      if ($sid > 0) {
        $linkedSiteIds[$sid] = true;
      }
    }
    $linkedSiteIds = array_keys($linkedSiteIds);

    $mlPushErrors = [];

    foreach ($linkedSiteIds as $mlSiteId) {
      $site = $siteById[$mlSiteId] ?? null;
      if (!$site) {
        $mlPushErrors[] = "Sitio ML #{$mlSiteId} no encontrado.";
        continue;
      }

      if (!stock_sync_allows_push($site)) {
        continue;
      }

      $res = sync_stock_to_mercadolibre_with_result($site, (string)$product['sku'], $currentQty, (int)$id);

      if (!$res || ($res['ok'] ?? false) !== true) {
        $mlPushErrors[] = "ML " . ($ml_site_names[$mlSiteId] ?? ('#' . $mlSiteId)) . ": " . ($res['error'] ?? 'Error desconocido');
      }
    }

    if ($mlPushErrors) {
      $error = 'Error actualizando stock en MercadoLibre: ' . implode(' | ', $mlPushErrors);
    } else {
      $message = 'Stock enviado a MercadoLibre correctamente.';
    }
  }
}

$supplier_margin_column = 'default_margin_percent';
$supplier_discount_column = null;
$supplier_columns_st = db()->query("SHOW COLUMNS FROM suppliers");
if ($supplier_columns_st) {
  $supplier_margin_column = null;
  foreach ($supplier_columns_st->fetchAll() as $supplier_column) {
    $field = (string)($supplier_column['Field'] ?? '');
    if ($field === 'base_percent' || $field === 'base_margin_percent') {
      $supplier_margin_column = $field;
    }
    if ($field === 'default_margin_percent' && $supplier_margin_column === null) {
      $supplier_margin_column = $field;
    } elseif ($supplier_margin_column === null && stripos($field, 'margin') !== false) {
      $supplier_margin_column = $field;
    }
    if ($field === 'discount_percent') {
      $supplier_discount_column = $field;
    }
    if ($field === 'import_discount_default' && $supplier_discount_column === null) {
      $supplier_discount_column = $field;
    }
  }
}

$supplier_margin_expr = '0';
if ($supplier_margin_column !== null) {
  $safe_supplier_margin_column = str_replace('`', '``', $supplier_margin_column);
  $supplier_margin_expr = "COALESCE(s.`{$safe_supplier_margin_column}`, 0)";
}

$supplier_discount_expr = '0';
if ($supplier_discount_column !== null) {
  $safe_supplier_discount_column = str_replace('`', '``', $supplier_discount_column);
  $supplier_discount_expr = "COALESCE(s.`{$safe_supplier_discount_column}`, 0)";
}

$st = db()->prepare("SELECT ps.id, ps.supplier_cost, ps.cost_unitario, ps.cost_type, ps.units_per_pack,
  {$supplier_margin_expr} AS supplier_base_percent,
  {$supplier_discount_expr} AS supplier_discount_percent,
  COALESCE(s.import_default_units_per_pack, 0) AS supplier_default_units_per_pack,
  COALESCE(p.sale_units_per_pack, 0) AS units_pack
  FROM product_suppliers ps
  INNER JOIN suppliers s ON s.id = ps.supplier_id
  INNER JOIN products p ON p.id = ps.product_id
  WHERE ps.product_id = ? AND ps.is_active = 1
  ORDER BY ps.id ASC
  LIMIT 1");
$st->execute([$id]);
$active_supplier_link = $st->fetch();

$site_prices = [];
$st = db()->query("SELECT id, name, margin_percent, is_active, is_visible, show_in_product FROM sites WHERE show_in_product = 1 ORDER BY id ASC");
if ($st) {
  $site_prices = $st->fetchAll();
}

$active_supplier_name = '—';
foreach ($supplier_links as $supplier_link) {
  if ((int)($supplier_link['is_active'] ?? 0) === 1) {
    $active_supplier_name = trim((string)($supplier_link['supplier_name'] ?? ''));
    break;
  }
}
if ($active_supplier_name === '') {
  $active_supplier_name = '—';
}

$tab_param = trim((string)get('tab', 'resumen'));
$allowed_tabs = ['resumen', 'datos', 'proveedores', 'precio', 'stock', 'codigo', 'ml', 'mensajes'];
$initial_tab = in_array($tab_param, $allowed_tabs, true) ? $tab_param : 'resumen';

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
  <style>
    .inline-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(6, 10, 18, 0.72);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1200;
      padding: 16px;
    }

    .inline-modal-backdrop.is-open {
      display: flex;
    }

    .inline-modal {
      width: 100%;
      max-width: 420px;
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.12);
      background: var(--panel, #131a2a);
      box-shadow: 0 18px 44px rgba(0, 0, 0, 0.45);
      padding: 18px;
    }

    .inline-modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 12px;
    }

    .inline-modal-feedback {
      margin-top: 10px;
      color: #ff7d7d;
      font-size: 13px;
    }

    .product-table-wrapper {
      margin-top: var(--space-4);
    }

    .supplier-form-row {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: var(--space-3);
      align-items: start;
    }

    .supplier-col-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: var(--space-3);
      align-items: start;
    }

    .supplier-col-3 {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: var(--space-3);
      align-items: start;
    }

    .supplier-col-3.is-pack {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .supplier-col-3 .form-group {
      margin-bottom: 0;
    }

    .supplier-col-3 .form-group.is-hidden {
      display: none;
    }

    .tabs-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
    }

    .tabs-toolbar .btn.is-active {
      background: var(--accent, #2f6df6);
      border-color: var(--accent, #2f6df6);
      color: #fff;
    }

    .tab-panel {
      display: none;
    }

    .tab-panel.is-active {
      display: block;
    }

    @media (max-width: 980px) {
      .supplier-form-row,
      .supplier-col-2,
      .supplier-col-3,
      .supplier-col-3.is-pack {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>
</head>
<body class="<?= e(app_body_class()) ?>">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Producto</h2>
      <span class="muted">SKU <?= e($product['sku']) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="tabs-toolbar" role="tablist" aria-label="Secciones del producto">
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="resumen">Resumen</button>
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="datos">Datos</button>
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="proveedores">Proveedores</button>
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="precio">Precio</button>
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="stock">Stock</button>
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="codigo">Código</button>
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="ml">Mercadolibre</button>
      <button class="btn btn-ghost js-tab-btn" type="button" data-tab-target="mensajes">Mensaje</button>
    </div>

    <div id="tab-resumen" class="tab-panel">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Resumen</h3>
        </div>
        <div class="card-body">
          <?php
            $summary_stock_qty = (int)$ts_stock['qty'];
            $summary_ml_linked = (bool)$ml_links;
            $summary_has_supplier = trim((string)$active_supplier_name) !== '' && trim((string)$active_supplier_name) !== '—';
          ?>
          <div class="stack">
            <div><strong><?= e((string)$product['name']) ?></strong></div>

            <div class="form-row" style="grid-template-columns:repeat(3, minmax(0, 1fr));">
              <div class="form-group">
                <label class="form-label">SKU</label>
                <input class="form-control" type="text" value="<?= e((string)$product['sku']) ?>" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">Marca</label>
                <input class="form-control" type="text" value="<?= e((string)($product['brand_name'] ?? $product['brand'] ?? '')) ?>" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">Stock actual</label>
                <input class="form-control" type="text" value="<?= $summary_stock_qty ?>" readonly>
              </div>
            </div>

            <div class="form-row" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
              <div class="form-group">
                <label class="form-label">Proveedor</label>
                <input class="form-control" type="text" value="<?= e($summary_has_supplier ? $active_supplier_name : 'Sin proveedor') ?>" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">MercadoLibre</label>
                <input class="form-control" type="text" value="<?= $summary_ml_linked ? 'Vinculado' : 'No vinculado' ?>" readonly>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="tab-datos" class="tab-panel">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Datos del producto</h3>
      </div>
      <?php if ($can_edit_data): ?>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="update">
          <div class="form-row" style="grid-template-columns:repeat(3, minmax(0, 1fr));">
            <div class="form-group">
              <label class="form-label">SKU</label>
              <input class="form-control" type="text" name="sku" value="<?= e($product['sku']) ?>" required>
            </div>
            <div class="form-group" style="grid-column: span 2;">
              <label class="form-label">Nombre</label>
              <input class="form-control" type="text" name="name" value="<?= e($product['name']) ?>" required>
            </div>
          </div>
          <div class="form-row" style="grid-template-columns:repeat(3, minmax(0, 1fr));">
            <div class="form-group">
              <label class="form-label">Marca</label>
              <select class="form-control" name="brand_id">
                <option value="">Sin marca</option>
                <?php foreach ($brands as $brand): ?>
                  <option value="<?= (int)$brand['id'] ?>" <?= (int)($product['brand_id'] ?? 0) === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Modo de venta</label>
              <select class="form-control" name="sale_mode" id="sale-mode-select" required>
                <option value="UNIDAD" <?= ($product['sale_mode'] ?? 'UNIDAD') === 'UNIDAD' ? 'selected' : '' ?>>Unidad</option>
                <option value="PACK" <?= ($product['sale_mode'] ?? '') === 'PACK' ? 'selected' : '' ?>>Pack</option>
              </select>
            </div>
            <div class="form-group" id="sale-units-group" style="display:none;">
              <label class="form-label">Unidades pack</label>
              <input class="form-control" type="number" min="1" step="1" name="sale_units_per_pack" id="sale-units-input" value="<?= e((string)($product['sale_units_per_pack'] ?? '')) ?>">
            </div>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Guardar cambios</button>
            <a class="btn btn-ghost" href="product_list.php">Volver</a>
          </div>
        </form>
      <?php else: ?>
        <div class="stack">
          <div><strong>SKU:</strong> <?= e($product['sku']) ?></div>
          <div><strong>Nombre:</strong> <?= e($product['name']) ?></div>
          <div><strong>Marca:</strong> <?= e($product['brand_name'] ?? $product['brand']) ?></div>
          <div><strong>Modo de venta:</strong> <?= e(($product['sale_mode'] ?? 'UNIDAD') === 'PACK' ? 'Pack' : 'Unidad') ?></div>
          <?php if (($product['sale_mode'] ?? 'UNIDAD') === 'PACK'): ?>
            <div><strong>Unidades por pack:</strong> <?= (int)($product['sale_units_per_pack'] ?? 0) ?></div>
          <?php endif; ?>
          <div class="form-actions">
            <a class="btn btn-ghost" href="product_list.php">Volver</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
    </div>

    <div id="tab-stock" class="tab-panel">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Stock (TS Work)</h3>
        <span class="muted small">Actual: <?= (int)$ts_stock['qty'] ?></span>
      </div>
      <div class="card-body stack">
        <div><strong>Stock actual:</strong> <?= (int)$ts_stock['qty'] ?></div>

        <?php if ($can_edit_stock): ?>
          <form method="post" class="stack">
            <input type="hidden" name="action" value="stock_set">
            <div class="form-row" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
              <div class="form-group">
                <label class="form-label">Setear stock</label>
                <input class="form-control" type="number" step="1" name="stock_set_qty" required>
              </div>
              <div class="form-group">
                <label class="form-label">Nota (opcional)</label>
                <input class="form-control" type="text" name="stock_note" maxlength="1000" placeholder="Motivo o comentario">
              </div>
            </div>
            <div class="form-actions">
              <button class="btn" type="submit">Guardar</button>
            </div>
          </form>

          <form method="post" class="stack">
            <input type="hidden" name="action" value="stock_add">
            <div class="form-row" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
              <div class="form-group">
                <label class="form-label">Sumar / Restar</label>
                <input class="form-control" type="number" step="1" name="stock_delta" required>
              </div>
              <div class="form-group">
                <label class="form-label">Nota (opcional)</label>
                <input class="form-control" type="text" name="stock_note" maxlength="1000" placeholder="Motivo o comentario">
              </div>
            </div>
            <div class="form-actions">
              <button class="btn" type="submit">Guardar</button>
            </div>
          </form>

          <?php if ($can_pull_ps_stock && $prestashop_sync_sites): ?>
            <form method="post" class="stack">
              <input type="hidden" name="action" value="pull_stock_prestashop">
              <div class="form-row" style="grid-template-columns:2fr 1fr; align-items:end;">
                <div class="form-group">
                  <label class="form-label">Sitio PrestaShop</label>
                  <select class="form-control" name="site_id" required>
                    <?php foreach ($prestashop_sync_sites as $syncSite): ?>
                      <option value="<?= (int)$syncSite['id'] ?>">#<?= (int)$syncSite['id'] ?> - <?= e((string)($syncSite['name'] ?? 'Sitio')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-actions" style="margin:0;">
                  <button class="btn btn-ghost" type="submit">Traer stock desde PrestaShop</button>
                </div>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>

        <div class="table-wrapper product-table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>fecha</th>
                <th>delta</th>
                <th>stock resultante</th>
                <th>motivo</th>
                <th>usuario</th>
                <th>nota</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$ts_stock_moves): ?>
                <tr><td colspan="6">Sin movimientos todavía.</td></tr>
              <?php else: ?>
                <?php foreach ($ts_stock_moves as $move): ?>
                  <?php
                    $delta = (int)$move['delta'];
                    $user_name = trim((string)($move['user_name'] ?? ''));
                    if ($user_name === '') {
                      $user_name = (string)($move['user_email'] ?? 'Sistema');
                    }
                  ?>
                  <tr>
                    <td><?= e((string)$move['created_at']) ?></td>
                    <td><?= $delta > 0 ? '+' . $delta : (string)$delta ?></td>
                    <td><?= (int)$move['result_qty'] ?></td>
                    <td><?= e((string)$move['reason']) ?></td>
                    <td><?= e($user_name) ?></td>
                    <td><?= e((string)($move['note'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    </div>

    <div id="tab-ml" class="tab-panel">
    <?php if ($ml_sites): ?>
      <div class="card">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
          <div>
            <h3 class="card-title">MercadoLibre (vínculo)</h3>
            <span class="muted small">Permite múltiples publicaciones/variantes por producto</span>
          </div>
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
            <span class="muted small">
              Stock actual (TSWork): <strong><?= (int)($ts_stock['qty'] ?? 0) ?></strong>
            </span>
            <?php if ($can_edit_ml): ?>
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="ml_push_stock">
                <button class="btn btn-ghost" type="submit" <?= $ml_links ? '' : 'disabled' ?>>Actualizar Stock</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body stack">
          <?php if (!$ml_links): ?>
            <p class="muted small">Para actualizar stock primero vinculá al menos una publicación.</p>
          <?php endif; ?>
          <?php if ($can_edit_ml): ?>
            <div class="stack" id="ml-link-form">
              <div class="form-row" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
                <div class="form-group">
                  <label class="form-label">Sitio MercadoLibre</label>
                  <select class="form-control" name="ml_site_id" id="ml-site-select" required>
                    <option value="">Seleccionar</option>
                    <?php foreach ($ml_sites as $mlSite): ?>
                      <option value="<?= (int)$mlSite['id'] ?>"><?= e($mlSite['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">SKU TSWork</label>
                  <input class="form-control" type="text" value="<?= e((string)$product['sku']) ?>" readonly>
                </div>
              </div>
              <div class="form-actions">
                <button class="btn btn-ghost" type="button" id="ml-search-btn">Buscar por SKU en ML</button>
              </div>
              <p class="muted small" id="ml-bind-status"></p>
            </div>

            <div class="table-wrapper" id="ml-search-results-wrap" style="display:none;">
              <table class="table">
                <thead><tr><th>SKU</th><th>Título</th><th>Precio</th><th>Stock</th><th>Item ID</th><th>Variation ID</th><th>Acciones</th></tr></thead>
                <tbody id="ml-search-results-body"></tbody>
              </table>
            </div>

            <div class="table-wrapper product-table-wrapper">
              <table class="table">
                <thead><tr><th>Sitio ML</th><th>SKU</th><th>Título</th><th>Item ID</th><th>Variation ID</th><th>Última sync ML</th><th>Acción</th></tr></thead>
                <tbody id="ml-links-existing-body">
                  <?php if (!$ml_links): ?>
                    <tr><td colspan="7">Sin vínculos guardados.</td></tr>
                  <?php else: ?>
                    <?php foreach ($ml_links as $link): ?>
                      <tr data-link-id="<?= (int)$link['id'] ?>">
                        <td><?= e($ml_site_names[(int)$link['site_id']] ?? ('#' . (int)$link['site_id'])) ?></td>
                        <td><?= trim((string)($link['ml_sku'] ?? '')) !== '' ? e((string)$link['ml_sku']) : '—' ?></td>
                        <td><?= trim((string)($link['title'] ?? '')) !== '' ? e((string)$link['title']) : '—' ?></td>
                        <td><?= e((string)$link['ml_item_id']) ?></td>
                        <td><?= trim((string)($link['ml_variation_id'] ?? '')) !== '' ? e((string)$link['ml_variation_id']) : '—' ?></td>
                        <td><?= trim((string)($ml_last_sync_by_site[(int)$link['site_id']] ?? '')) !== '' ? e((string)$ml_last_sync_by_site[(int)$link['site_id']]) : '—' ?></td>
                        <td><button class="btn btn-danger js-ml-unlink" type="button" data-link-id="<?= (int)$link['id'] ?>">Desvincular</button></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="table">
                <thead><tr><th>Sitio ML</th><th>SKU</th><th>Título</th><th>Item ID</th><th>Variation ID</th><th>Última sync ML</th></tr></thead>
                <tbody>
                  <?php if (!$ml_links): ?>
                    <tr><td colspan="6">Sin vínculos guardados.</td></tr>
                  <?php else: ?>
                    <?php foreach ($ml_links as $link): ?>
                      <tr>
                        <td><?= e($ml_site_names[(int)$link['site_id']] ?? ('#' . (int)$link['site_id'])) ?></td>
                        <td><?= trim((string)($link['ml_sku'] ?? '')) !== '' ? e((string)$link['ml_sku']) : '—' ?></td>
                        <td><?= trim((string)($link['title'] ?? '')) !== '' ? e((string)$link['title']) : '—' ?></td>
                        <td><?= e((string)$link['ml_item_id']) ?></td>
                        <td><?= trim((string)($link['ml_variation_id'] ?? '')) !== '' ? e((string)$link['ml_variation_id']) : '—' ?></td>
                        <td><?= trim((string)($ml_last_sync_by_site[(int)$link['site_id']] ?? '')) !== '' ? e((string)$ml_last_sync_by_site[(int)$link['site_id']]) : '—' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">MercadoLibre (vínculo)</h3>
        </div>
        <div class="card-body">
          <p class="muted">No hay sitios de MercadoLibre configurados.</p>
        </div>
      </div>
    <?php endif; ?>
    </div>

    <div id="tab-codigo" class="tab-panel">
<div class="card">
      <div class="card-header">
        <h3 class="card-title">Códigos</h3>
        <span class="muted small"><?= count($codes) ?> registrados</span>
      </div>
      <div class="card-body product-codes-body">
        <?php if ($can_add_code): ?>
          <form method="post" class="form-row product-codes-form" id="product-codes-form">
            <input type="hidden" name="action" value="add_code">
            <input type="hidden" name="confirm_duplicate" value="0" id="product-codes-confirm-duplicate">
            <div class="form-group">
              <label class="form-label">Código</label>
              <input class="form-control" type="text" name="code" placeholder="Escaneá código" autofocus>
            </div>
            <div class="form-group">
              <label class="form-label">Tipo</label>
              <select name="code_type">
                <option value="BARRA">BARRA</option>
                <option value="MPN">MPN</option>
              </select>
            </div>
            <div class="form-group" style="align-self:end;">
              <button class="btn" type="submit">Agregar</button>
            </div>
          </form>
        <?php endif; ?>

<div class="table-wrapper product-table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>código</th>
                <th>tipo</th>
                <th>fecha</th>
                <?php if ($can_add_code): ?>
                  <th>acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!$codes): ?>
                <tr><td colspan="<?= $can_add_code ? 4 : 3 ?>">Sin códigos todavía.</td></tr>
              <?php else: ?>
                <?php foreach ($codes as $c): ?>
                  <tr>
                    <td><?= e($c['code']) ?></td>
                    <td><?= e($c['code_type']) ?></td>
                    <td><?= e($c['created_at']) ?></td>
                    <?php if ($can_add_code): ?>
                      <td class="table-actions">
                        <form method="post" style="display:inline;">
                          <input type="hidden" name="action" value="delete_code">
                          <input type="hidden" name="code_id" value="<?= (int)$c['id'] ?>">
                          <button class="btn btn-danger" type="submit">Eliminar</button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    </div>

    <div id="tab-proveedores" class="tab-panel">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Proveedores vinculados</h3>
        <span class="muted small"><?= count($supplier_links) ?> vinculados</span>
      </div>
      <div class="card-body product-linked-suppliers-body">
        <?php if ($can_edit_providers): ?>
          <form method="post" class="stack product-linked-suppliers-form">
            <input type="hidden" name="action" value="add_supplier_link" id="supplier-link-action">
            <input type="hidden" name="edit_link_id" value="" id="edit-link-id-input">
            <div class="form-row product-supplier-form supplier-form-row">
              <div class="form-group">
                <label class="form-label">SKU / Código del proveedor</label>
                <input class="form-control" type="text" name="supplier_sku">
              </div>
              <div class="supplier-col-2">
                <div class="form-group">
                  <label class="form-label">Proveedor</label>
                  <select class="form-control" name="supplier_id" id="supplier-id-select" required>
                    <option value="">Seleccionar</option>
                    <option value="__new__">+ Agregar proveedor…</option>
                    <?php foreach ($suppliers as $supplier): ?>
                      <option value="<?= (int)$supplier['id'] ?>"><?= e($supplier['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Costo del proveedor</label>
                  <input class="form-control" type="number" step="1" min="0" pattern="\d*" inputmode="numeric" name="supplier_cost" id="supplier-cost-input" placeholder="0">
                </div>
              </div>
              <div>
                <div class="product-supplier-cost-layout supplier-col-3" id="cost-layout-group">
                  <div class="form-group">
                    <label class="form-label">Tipo de costo recibido</label>
                    <select class="form-control" name="cost_type" id="cost-type-select">
                      <option value="UNIDAD">Unidad</option>
                      <option value="PACK">Pack</option>
                    </select>
                  </div>
                  <div class="form-group is-hidden" id="cost-units-group" data-toggle-hidden="1">
                    <label class="form-label">Unidades por pack</label>
                    <input class="form-control" type="number" min="1" step="1" name="units_per_pack" id="cost-units-input">
                  </div>
                </div>
              </div>
            </div>
            <div class="form-actions product-supplier-actions">
              <button class="btn" type="submit" id="supplier-link-submit-btn">Agregar proveedor</button>
              <button class="btn btn-ghost" type="button" id="supplier-link-cancel-btn" style="display:none;">Cancelar edición</button>
            </div>
          </form>
        <?php endif; ?>

        <div class="table-wrapper product-table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>proveedor</th>
                <th>sku proveedor</th>
                <th>costo recibido</th>
                <th>unidades pack</th>
                <th>costo proveedor</th>
                <th>costo unitario</th>
                <th>activo</th>
                <?php if ($can_edit_providers): ?>
                  <th>acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!$supplier_links): ?>
                <tr><td colspan="<?= $can_edit_providers ? 8 : 7 ?>">Sin proveedores vinculados.</td></tr>
              <?php else: ?>
                <?php foreach ($supplier_links as $link): ?>
                  <tr>
                    <td><?= e($link['supplier_name']) ?></td>
                    <td><?= e($link['supplier_sku']) ?></td>
                    <td><?= e($link['cost_type'] === 'PACK' ? 'Pack' : 'Unidad') ?></td>
                    <td><?= $link['cost_type'] === 'PACK' ? (int)$link['units_per_pack'] : '-' ?></td>
                    <td><?= ($link['supplier_cost'] === null || trim((string)$link['supplier_cost']) === '') ? '—' : number_format(round((float)$link['supplier_cost']), 0, '', '') ?></td>
                    <td><?= ($link['normalized_unit_cost'] === null || trim((string)$link['normalized_unit_cost']) === '') ? '—' : number_format(round((float)$link['normalized_unit_cost']), 0, '', '') ?></td>
                    <td><?= (int)$link['is_active'] === 1 ? 'Sí' : 'No' ?></td>
                    <?php if ($can_edit_providers): ?>
                      <td class="table-actions">
                        <button
                          class="btn btn-ghost js-edit-supplier-link"
                          type="button"
                          data-link-id="<?= (int)$link['id'] ?>"
                          data-supplier-id="<?= (int)$link['supplier_id'] ?>"
                          data-supplier-sku="<?= e($link['supplier_sku']) ?>"
                          data-cost-type="<?= e($link['cost_type']) ?>"
                          data-units-per-pack="<?= (int)($link['units_per_pack'] ?? 0) ?>"
                          data-supplier-cost="<?= ($link['supplier_cost'] === null || trim((string)$link['supplier_cost']) === '') ? '' : number_format(round((float)$link['supplier_cost']), 0, '', '') ?>"
                          style="margin-right:6px;"
                        >Modificar</button>
                        <?php if ((int)$link['is_active'] !== 1): ?>
                          <form method="post" style="display:inline; margin-right:6px;">
                            <input type="hidden" name="action" value="set_active_supplier">
                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                            <button class="btn btn-ghost" type="submit">Marcar activo</button>
                          </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;">
                          <input type="hidden" name="action" value="delete_supplier_link">
                          <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                          <button class="btn btn-danger" type="submit">Eliminar</button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    </div>

    <div id="tab-precio" class="tab-panel">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Precios por sitio</h3>
        <span class="muted small"><?= count($site_prices) ?> visibles en producto</span>
      </div>
      <div class="card-body">
        <div class="table-wrapper product-table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>sitio</th>
                <th>margen (%)</th>
                <th>estado</th>
                <th>mostrar en lista</th>
                <th>mostrar en producto</th>
                <th>precio</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$site_prices): ?>
                <tr><td colspan="6">Sin sitios configurados para producto.</td></tr>
              <?php else: ?>
                <?php foreach ($site_prices as $site): ?>
                  <tr>
                    <td><?= e($site['name']) ?></td>
                    <td><?= e(number_format((float)$site['margin_percent'], 2, '.', '')) ?></td>
                    <td><?= (int)$site['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td><?= (int)$site['is_visible'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td><?= (int)$site['show_in_product'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td>
                      <?php
                        if (!$active_supplier_link) {
                          echo '—';
                        } else {
                          $effective_unit_cost = get_effective_unit_cost($active_supplier_link, [
                            'import_default_units_per_pack' => $active_supplier_link['supplier_default_units_per_pack'] ?? 0,
                            'discount_percent' => $active_supplier_link['supplier_discount_percent'] ?? 0,
                          ]);
                          $cost_for_mode = get_cost_for_product_mode($effective_unit_cost, $product);
                          $price_reason = get_price_unavailable_reason($active_supplier_link, $product);

                          if ($cost_for_mode === null) {
                            $title = $price_reason ?? 'Precio incompleto';
                            echo '<span title="' . e($title) . '">—</span>';
                          } else {
                            $final_price = get_final_site_price($cost_for_mode, [
                              'base_percent' => $active_supplier_link['supplier_base_percent'] ?? 0,
                              'discount_percent' => $active_supplier_link['supplier_discount_percent'] ?? 0,
                            ], $site, 0.0);

                            if ($final_price === null) {
                              $title = $price_reason ?? 'Precio incompleto';
                              echo '<span title="' . e($title) . '">—</span>';
                            } else {
                              echo e((string)(int)$final_price);
                            }
                          }
                        }
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    </div>

    <div id="tab-mensajes" class="tab-panel">
    <?php require_once __DIR__ . '/include/partials/messages_block.php'; ?>
    <?php ts_messages_block('product', $id, ['accordion' => true]); ?>
    </div>
  </div>
</main>

<div class="inline-modal-backdrop" id="supplier-inline-modal" aria-hidden="true">
  <div class="inline-modal" role="dialog" aria-modal="true" aria-labelledby="supplier-inline-modal-title">
    <div class="card-header" style="padding:0; margin-bottom:10px;">
      <h3 class="card-title" id="supplier-inline-modal-title">Nuevo proveedor</h3>
    </div>
    <form id="supplier-inline-form" class="stack">
      <div class="form-group">
        <label class="form-label" for="supplier-inline-name">Nombre del proveedor</label>
        <input class="form-control" type="text" id="supplier-inline-name" name="supplier_name" maxlength="190" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="supplier-inline-margin">Base (%)</label>
        <input class="form-control" type="number" id="supplier-inline-margin" name="default_margin_percent" min="0" max="999.99" step="0.01" placeholder="0, 20, 30..." value="0" required>
      </div>
      <p class="inline-modal-feedback" id="supplier-inline-feedback" hidden></p>
      <div class="inline-modal-actions">
        <button class="btn btn-ghost" type="button" id="supplier-inline-cancel">Cancelar</button>
        <button class="btn" type="submit" id="supplier-inline-submit">Agregar</button>
      </div>
    </form>
  </div>
</div>

<script>
  const tabButtons = Array.from(document.querySelectorAll('.js-tab-btn'));
  const tabPanels = {
    resumen: document.getElementById('tab-resumen'),
    datos: document.getElementById('tab-datos'),
    proveedores: document.getElementById('tab-proveedores'),
    precio: document.getElementById('tab-precio'),
    stock: document.getElementById('tab-stock'),
    codigo: document.getElementById('tab-codigo'),
    ml: document.getElementById('tab-ml'),
    mensajes: document.getElementById('tab-mensajes')
  };
  const initialTab = <?= json_encode($initial_tab) ?>;

  const showTab = (tabName) => {
    const selectedTab = Object.prototype.hasOwnProperty.call(tabPanels, tabName) ? tabName : 'resumen';
    Object.entries(tabPanels).forEach(([name, panel]) => {
      if (!panel) return;
      panel.classList.toggle('is-active', name === selectedTab);
    });
    tabButtons.forEach((button) => {
      const isActive = button.dataset.tabTarget === selectedTab;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
  };

  tabButtons.forEach((button) => {
    button.addEventListener('click', () => {
      showTab(button.dataset.tabTarget || 'resumen');
    });
  });

  showTab(initialTab);

  const productCodesForm = document.getElementById('product-codes-form');
  const productCodesConfirmInput = document.getElementById('product-codes-confirm-duplicate');

  if (productCodesForm && productCodesConfirmInput) {
    productCodesForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      const body = new URLSearchParams(new FormData(productCodesForm));

      const sendRequest = async (confirmDuplicate) => {
        body.set('confirm_duplicate', confirmDuplicate ? '1' : '0');
        const response = await fetch(window.location.href, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        });

        return response.json();
      };

      try {
        let payload = await sendRequest(false);

        if (payload && payload.needs_confirm === true) {
          const existing = payload.existing || {};
          const shouldContinue = window.confirm(
            `Este código ya existe en otro producto:\nSKU: ${existing.sku || '—'}\nNombre: ${existing.name || '—'}\n¿Querés agregarlo de todas formas?`
          );

          if (!shouldContinue) {
            return;
          }

          payload = await sendRequest(true);
        }

        if (payload && payload.ok === true) {
          window.location.reload();
          return;
        }

        window.alert((payload && payload.error) ? payload.error : 'No se pudo agregar el código.');
      } catch (error) {
        window.alert('No se pudo agregar el código.');
      } finally {
        productCodesConfirmInput.value = '0';
      }
    });
  }

  const saleModeSelect = document.getElementById('sale-mode-select');
  const saleUnitsGroup = document.getElementById('sale-units-group');
  const saleUnitsInput = document.getElementById('sale-units-input');

  const costTypeSelect = document.getElementById('cost-type-select');
  const costUnitsGroup = document.getElementById('cost-units-group');
  const costUnitsInput = document.getElementById('cost-units-input');

  const toggleByMode = (select, group, input) => {
    if (!select || !group || !input) return;
    const isPack = select.value === 'PACK';
    if (group.dataset.toggleHidden === '1') {
      group.classList.toggle('is-hidden', !isPack);
      const packLayout = document.getElementById('cost-layout-group');
      if (packLayout) {
        packLayout.classList.toggle('is-pack', isPack);
      }
    } else {
      group.style.display = isPack ? '' : 'none';
    }
    input.required = isPack;
    if (!isPack) {
      input.value = '';
    }
  };

  if (saleModeSelect) {
    saleModeSelect.addEventListener('change', () => toggleByMode(saleModeSelect, saleUnitsGroup, saleUnitsInput));
    toggleByMode(saleModeSelect, saleUnitsGroup, saleUnitsInput);
  }

  if (costTypeSelect) {
    costTypeSelect.addEventListener('change', () => toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput));
    toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput);
  }

  const mlSiteSelect = document.getElementById('ml-site-select');

  const mlSearchBtn = document.getElementById('ml-search-btn');
  const mlBindStatus = document.getElementById('ml-bind-status');
  const mlSearchWrap = document.getElementById('ml-search-results-wrap');
  const mlSearchBody = document.getElementById('ml-search-results-body');
  const productSku = <?= json_encode((string)$product['sku']) ?>;
  const productId = <?= (int)$id ?>;
  const mlLinksExistingBody = document.getElementById('ml-links-existing-body');

  const toIntDisplay = (value) => Number.isFinite(Number(value)) ? String(parseInt(value, 10)) : '0';

  if (mlSearchBtn) {
    mlSearchBtn.addEventListener('click', async () => {
      if (!mlSiteSelect || !mlBindStatus || !mlSearchWrap || !mlSearchBody) {
        return;
      }
      const siteId = mlSiteSelect.value;
      if (!siteId) {
        mlBindStatus.textContent = 'Seleccioná un sitio de MercadoLibre para buscar.';
        return;
      }

      mlBindStatus.textContent = 'Consultando...';
      mlSearchBody.innerHTML = '';
      mlSearchWrap.style.display = 'none';

      const url = `api/site_test_sku.php?site_id=${encodeURIComponent(siteId)}&sku=${encodeURIComponent(productSku)}`;
      try {
        const response = await fetch(url, { credentials: 'same-origin' });
        const payload = await response.json();
        if (!payload || payload.ok !== true) {
          mlBindStatus.textContent = payload && payload.error ? payload.error : 'No se pudo buscar en MercadoLibre.';
          return;
        }

        const rows = Array.isArray(payload.rows) ? payload.rows : [];
        if (rows.length === 0) {
          mlBindStatus.textContent = 'No se encontraron publicaciones para este SKU.';
          return;
        }

        rows.forEach((row) => {
          const tr = document.createElement('tr');
          const rowVariationId = String(row.variation_id || '');
          const rowItemId = String(row.item_id || '');
          const rowSku = String(row.sku || '');
          tr.innerHTML = `
            <td>${rowSku || ''}</td>
            <td>${row.title || ''}</td>
            <td>${toIntDisplay(row.price)}</td>
            <td>${toIntDisplay(row.stock)}</td>
            <td>${rowItemId}</td>
            <td>${rowVariationId || '—'}</td>
            <td></td>
          `;
          const actionsTd = tr.lastElementChild;
          if (rowItemId && actionsTd) {
            const linkBtn = document.createElement('button');
            linkBtn.type = 'button';
            linkBtn.className = 'btn btn-ghost';
            linkBtn.textContent = 'Vincular';
            linkBtn.addEventListener('click', async () => {
              linkBtn.disabled = true;

              const body = new URLSearchParams();
              body.append('product_id', String(productId || ''));
              body.append('site_id', String(siteId || ''));
              body.append('ml_item_id', rowItemId);
              body.append('ml_variation_id', rowVariationId);
              body.append('ml_sku', rowSku);
              body.append('title', String(row.title || ''));

              try {
                const saveResponse = await fetch('api/ml_link.php', {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                  },
                  body: body.toString()
                });
                const savePayload = await saveResponse.json();
                if (!savePayload || savePayload.ok !== true) {
                  mlBindStatus.textContent = savePayload && savePayload.error ? savePayload.error : 'No se pudo vincular.';
                  return;
                }
                if (savePayload.already_linked) {
                  mlBindStatus.textContent = 'Ya vinculado.';
                  return;
                }

                mlBindStatus.textContent = rowVariationId
                  ? `Vinculación guardada (${rowItemId} / ${rowVariationId}).`
                  : `Vinculación guardada (${rowItemId}).`;

                if (mlLinksExistingBody) {
                  const trLink = document.createElement('tr');
                  trLink.dataset.linkId = String(savePayload.link_id || '');
                  trLink.innerHTML = `
                    <td>${(mlSiteSelect.options[mlSiteSelect.selectedIndex] || {}).text || ''}</td>
                    <td>${rowSku || '—'}</td>
                    <td>${row.title || '—'}</td>
                    <td>${rowItemId}</td>
                    <td>${rowVariationId || '—'}</td>
                    <td><button class="btn btn-danger js-ml-unlink" type="button" data-link-id="${savePayload.link_id || ''}">Desvincular</button></td>
                  `;

                  const emptyRow = mlLinksExistingBody.querySelector('tr td[colspan="6"]');
                  if (emptyRow) {
                    mlLinksExistingBody.innerHTML = '';
                  }
                  mlLinksExistingBody.prepend(trLink);
                }
              } catch (err) {
                mlBindStatus.textContent = 'No se pudo vincular.';
              } finally {
                linkBtn.disabled = false;
              }
            });
            actionsTd.appendChild(linkBtn);
          } else if (actionsTd) {
            actionsTd.textContent = '—';
          }
          mlSearchBody.appendChild(tr);
        });

        mlSearchWrap.style.display = '';
        mlBindStatus.textContent = `Resultados encontrados: ${rows.length}.`;
      } catch (err) {
        mlBindStatus.textContent = 'No se pudo probar el SKU.';
      }
    });
  }

  const bindMlUnlinkButtons = () => {
    document.querySelectorAll('.js-ml-unlink').forEach((button) => {
      if (button.dataset.bound === '1') return;
      button.dataset.bound = '1';
      button.addEventListener('click', async () => {
        const linkId = String(button.dataset.linkId || '').trim();
        if (!linkId) return;
        button.disabled = true;
        const body = new URLSearchParams();
        body.append('link_id', linkId);
        try {
          const resp = await fetch('api/ml_unlink.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
          });
          const payload = await resp.json();
          if (!payload || payload.ok !== true) {
            if (mlBindStatus) {
              mlBindStatus.textContent = payload && payload.error ? payload.error : 'No se pudo desvincular.';
            }
            return;
          }

          const row = button.closest('tr');
          if (row) row.remove();
          if (mlBindStatus) {
            mlBindStatus.textContent = 'Vínculo eliminado.';
          }
          if (mlLinksExistingBody && mlLinksExistingBody.children.length === 0) {
            mlLinksExistingBody.innerHTML = '<tr><td colspan="7">Sin vínculos guardados.</td></tr>';
          }
        } catch (e) {
          if (mlBindStatus) {
            mlBindStatus.textContent = 'No se pudo desvincular.';
          }
        } finally {
          button.disabled = false;
        }
      });
    });
  };

  bindMlUnlinkButtons();
  if (mlLinksExistingBody) {
    const observer = new MutationObserver(() => bindMlUnlinkButtons());
    observer.observe(mlLinksExistingBody, { childList: true, subtree: true });
  }

  const supplierSelect = document.getElementById('supplier-id-select');
  const supplierLinkForm = document.querySelector('.product-linked-suppliers-form');
  const supplierLinkActionInput = document.getElementById('supplier-link-action');
  const editLinkIdInput = document.getElementById('edit-link-id-input');
  const supplierSkuInput = supplierLinkForm ? supplierLinkForm.querySelector('input[name="supplier_sku"]') : null;
  const supplierCostInput = document.getElementById('supplier-cost-input');
  const supplierLinkSubmitBtn = document.getElementById('supplier-link-submit-btn');
  const supplierLinkCancelBtn = document.getElementById('supplier-link-cancel-btn');
  const editSupplierButtons = document.querySelectorAll('.js-edit-supplier-link');

  const normalizeSupplierCostValue = (value) => {
    if (value === undefined || value === null) return '';
    const normalized = String(value).replace(',', '.').replace(/[^0-9.]/g, '');
    if (normalized === '') return '';

    const firstDot = normalized.indexOf('.');
    const compact = firstDot >= 0
      ? normalized.slice(0, firstDot + 1) + normalized.slice(firstDot + 1).replace(/\./g, '')
      : normalized;

    const parsed = parseFloat(compact);
    if (!Number.isFinite(parsed) || parsed < 0) return '';
    return String(Math.round(parsed));
  };

  const bindCostInput = (input) => {
    if (!input) return;

    const sanitize = () => {
      input.value = normalizeSupplierCostValue(input.value);
    };

    input.addEventListener('input', sanitize);
    input.addEventListener('blur', sanitize);
    sanitize();
  };

  const resetSupplierLinkForm = () => {
    if (!supplierLinkForm) return;
    supplierLinkForm.reset();
    if (supplierLinkActionInput) supplierLinkActionInput.value = 'add_supplier_link';
    if (editLinkIdInput) editLinkIdInput.value = '';
    if (supplierLinkSubmitBtn) supplierLinkSubmitBtn.textContent = 'Agregar proveedor';
    if (supplierLinkCancelBtn) supplierLinkCancelBtn.style.display = 'none';
    toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput);
  };

  editSupplierButtons.forEach((button) => {
    button.addEventListener('click', () => {
      if (!supplierLinkForm) return;
      if (supplierLinkActionInput) supplierLinkActionInput.value = 'update_supplier_link';
      if (editLinkIdInput) editLinkIdInput.value = button.dataset.linkId || '';
      if (supplierSkuInput) supplierSkuInput.value = button.dataset.supplierSku || '';
      if (supplierSelect) supplierSelect.value = button.dataset.supplierId || '';
      if (costTypeSelect) costTypeSelect.value = button.dataset.costType || 'UNIDAD';
      if (costUnitsInput) {
        costUnitsInput.value = button.dataset.costType === 'PACK'
          ? (button.dataset.unitsPerPack || '')
          : '';
      }
      if (supplierCostInput) supplierCostInput.value = normalizeSupplierCostValue(button.dataset.supplierCost || '');
      if (supplierLinkSubmitBtn) supplierLinkSubmitBtn.textContent = 'Guardar cambios';
      if (supplierLinkCancelBtn) supplierLinkCancelBtn.style.display = '';
      toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput);
      supplierLinkForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  if (supplierLinkForm) {
    supplierLinkForm.addEventListener('submit', () => {
      if (supplierCostInput) {
        supplierCostInput.value = normalizeSupplierCostValue(supplierCostInput.value);
      }
    });
  }

  bindCostInput(supplierCostInput);

  if (supplierLinkCancelBtn) {
    supplierLinkCancelBtn.addEventListener('click', () => {
      resetSupplierLinkForm();
    });
  }

  const supplierModal = document.getElementById('supplier-inline-modal');
  const supplierInlineForm = document.getElementById('supplier-inline-form');
  const supplierInlineNameInput = document.getElementById('supplier-inline-name');
  const supplierInlineMarginInput = document.getElementById('supplier-inline-margin');
  const supplierInlineCancelBtn = document.getElementById('supplier-inline-cancel');
  const supplierInlineSubmitBtn = document.getElementById('supplier-inline-submit');
  const supplierInlineFeedback = document.getElementById('supplier-inline-feedback');
  const supplierNewValue = '__new__';
  let previousSupplierValue = supplierSelect ? supplierSelect.value : '';

  const closeSupplierModal = () => {
    if (!supplierModal) return;
    supplierModal.classList.remove('is-open');
    supplierModal.setAttribute('aria-hidden', 'true');
    supplierInlineForm.reset();
    supplierInlineFeedback.hidden = true;
    supplierInlineFeedback.textContent = '';
    supplierInlineSubmitBtn.disabled = false;
  };

  const openSupplierModal = () => {
    if (!supplierModal) return;
    supplierModal.classList.add('is-open');
    supplierModal.setAttribute('aria-hidden', 'false');
    setTimeout(() => supplierInlineNameInput.focus(), 0);
  };

  if (supplierSelect) {
    supplierSelect.addEventListener('focus', () => {
      if (supplierSelect.value !== supplierNewValue) {
        previousSupplierValue = supplierSelect.value;
      }
    });

    supplierSelect.addEventListener('change', () => {
      if (supplierSelect.value === supplierNewValue) {
        openSupplierModal();
      } else {
        previousSupplierValue = supplierSelect.value;
      }
    });
  }

  if (supplierInlineCancelBtn) {
    supplierInlineCancelBtn.addEventListener('click', () => {
      if (supplierSelect) supplierSelect.value = previousSupplierValue;
      closeSupplierModal();
    });
  }

  if (supplierModal) {
    supplierModal.addEventListener('click', (event) => {
      if (event.target === supplierModal) {
        if (supplierSelect) supplierSelect.value = previousSupplierValue;
        closeSupplierModal();
      }
    });
  }

  if (supplierInlineForm) {
    supplierInlineForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      const supplierName = supplierInlineNameInput.value.trim();
      const supplierMargin = supplierInlineMarginInput ? supplierInlineMarginInput.value.trim() : '0';
      if (supplierName === '') {
        supplierInlineFeedback.hidden = false;
        supplierInlineFeedback.textContent = 'Ingresá un nombre.';
        supplierInlineNameInput.focus();
        return;
      }

      if (supplierMargin === '') {
        supplierInlineFeedback.hidden = false;
        supplierInlineFeedback.textContent = 'Ingresá una base (%).';
        supplierInlineMarginInput?.focus();
        return;
      }

      supplierInlineSubmitBtn.disabled = true;
      supplierInlineFeedback.hidden = true;

      try {
        const body = new URLSearchParams();
        body.append('action', 'create_supplier_inline');
        body.append('supplier_name', supplierName);
        body.append('default_margin_percent', supplierMargin);

        const response = await fetch(window.location.href, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: body.toString(),
        });

        const data = await response.json();
        if (!response.ok || !data.ok || !data.supplier) {
          throw new Error(data.message || 'No se pudo crear el proveedor.');
        }

        const supplierId = String(data.supplier.id);
        let option = supplierSelect.querySelector('option[value="' + supplierId.replace(/"/g, '\"') + '"]');
        if (!option) {
          option = document.createElement('option');
          option.value = supplierId;
          option.textContent = data.supplier.name;
          supplierSelect.appendChild(option);
        }
        supplierSelect.value = supplierId;
        previousSupplierValue = supplierSelect.value;
        closeSupplierModal();
      } catch (error) {
        supplierInlineFeedback.hidden = false;
        supplierInlineFeedback.textContent = error instanceof Error ? error.message : 'No se pudo crear el proveedor.';
      } finally {
        supplierInlineSubmitBtn.disabled = false;
      }
    });
  }
</script>

</body>
</html>
