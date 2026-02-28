<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/stock_sync.php';
require_login();
ensure_brands_schema();

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id de listado.');

$st = db()->prepare("
  SELECT sl.*, u.first_name, u.last_name
  FROM stock_lists sl
  JOIN users u ON u.id = sl.created_by
  WHERE sl.id = ?
  LIMIT 1
");
$st->execute([$list_id]);
$list = $st->fetch();
if (!$list) abort(404, 'Listado no encontrado.');
ensure_stock_sync_schema();

function scan_json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function scan_find_products(string $q): array {
  $q = trim($q);
  if ($q === '') {
    return [];
  }

  $q2 = preg_replace('/\s+/', '', $q);
  $conditions = [
    'LOWER(p.sku) = LOWER(:q)',
    'LOWER(p.sku) = LOWER(:q2)',
    'LOWER(pc.code) = LOWER(:q)',
    'LOWER(pc.code) = LOWER(:q2)',
    'LOWER(ps.supplier_sku) = LOWER(:q)',
    'LOWER(ps.supplier_sku) = LOWER(:q2)',
  ];

  $barcodeCols = [];
  $stCols = db()->prepare("SHOW COLUMNS FROM products LIKE ?");
  foreach (['barcode', 'ean13'] as $col) {
    $stCols->execute([$col]);
    if ($stCols->fetch()) {
      $barcodeCols[] = $col;
    }
  }
  foreach ($barcodeCols as $col) {
    $conditions[] = 'LOWER(p.' . $col . ') = LOWER(:q)';
    $conditions[] = 'LOWER(p.' . $col . ') = LOWER(:q2)';
  }

  $sql = "
    SELECT DISTINCT
      p.id,
      p.sku,
      p.name,
      COALESCE(b.name, p.brand) AS brand,
      (
        SELECT MIN(ps2.supplier_sku)
        FROM product_suppliers ps2
        WHERE ps2.product_id = p.id
      ) AS supplier_sku,
      (
        SELECT MIN(pc2.code)
        FROM product_codes pc2
        WHERE pc2.product_id = p.id
      ) AS any_code
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN product_codes pc ON pc.product_id = p.id
    LEFT JOIN product_suppliers ps ON ps.product_id = p.id
    WHERE " . implode("\n      OR ", $conditions) . "
    ORDER BY p.name ASC
    LIMIT 50
  ";
  $st = db()->prepare($sql);
  $st->execute([':q' => $q, ':q2' => $q2]);
  return $st->fetchAll();
}

function list_add_product_qty(int $listId, int $productId): void {
  $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
    ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
  $st->execute([$listId, $productId]);
}

$message = (string)get('msg', '');
$error = '';
$unknown_code = (string)post('unknown_code','');
$search_term = '';
$search_results = [];
$should_focus_scan = false;
$clear_scan_input = false;
$scan_mode = (string)post('scan_mode', 'add');
$can_scan_action = can_scan();
$can_close_action = can_close_list();
$can_reopen_action = can_reopen_list();
$can_edit_product_action = can_edit_product();
$can_add_code_action = can_add_code();
$can_manage_unknown_code = $can_edit_product_action || $can_add_code_action;

if (is_post() && post('action') === 'scan_lookup') {
  require_permission($can_scan_action);
  if ($list['status'] !== 'open') {
    scan_json_response(['ok' => false, 'reason' => 'closed', 'message' => 'El listado está cerrado. Abrilo para seguir cargando.'], 400);
  }

  $scan_mode_req = (string)post('scan_mode', 'add');
  if ($scan_mode_req !== 'add') {
    scan_json_response(['ok' => false, 'reason' => 'mode_not_supported', 'message' => 'El modo resta usa el flujo normal.'], 400);
  }

  $code = trim((string)post('code', ''));
  if ($code === '') {
    scan_json_response(['ok' => false, 'reason' => 'empty', 'message' => 'Escaneá o pegá un código.'], 400);
  }

  $matches = scan_find_products($code);
  if (count($matches) === 0) {
    scan_json_response(['ok' => false, 'reason' => 'not_found', 'message' => 'No se encontró el producto.']);
  }

  if (count($matches) === 1) {
    $pid = (int)$matches[0]['id'];
    list_add_product_qty($list_id, $pid);
    scan_json_response(['ok' => true, 'mode' => 'added', 'product' => $matches[0]]);
  }

  scan_json_response(['ok' => true, 'mode' => 'choose', 'matches' => $matches]);
}

if (is_post() && post('action') === 'scan_add_choose') {
  require_permission($can_scan_action);
  if ($list['status'] !== 'open') {
    scan_json_response(['ok' => false, 'message' => 'El listado está cerrado. Abrilo para seguir cargando.'], 400);
  }

  $product_id = (int)post('product_id', '0');
  if ($product_id <= 0) {
    scan_json_response(['ok' => false, 'message' => 'Producto inválido.'], 400);
  }

  $st = db()->prepare('SELECT id, sku, name FROM products WHERE id = ? LIMIT 1');
  $st->execute([$product_id]);
  $product = $st->fetch();
  if (!$product) {
    scan_json_response(['ok' => false, 'message' => 'Producto inexistente.'], 404);
  }

  list_add_product_qty($list_id, (int)$product['id']);
  scan_json_response(['ok' => true, 'mode' => 'added', 'product' => $product]);
}

// Toggle open/closed
if (is_post() && post('action') === 'toggle_status') {
  if ($list['status'] === 'open') {
    require_permission($can_close_action);
    $new = 'closed';
  } else {
    require_permission($can_reopen_action, 'No tenés permisos para reabrir un listado cerrado');
    $new = 'open';
  }
  $st = db()->prepare("UPDATE stock_lists SET status = ? WHERE id = ?");
  $st->execute([$new, $list_id]);
  redirect("list_view.php?id={$list_id}");
}

// Delete item
if (is_post() && post('action') === 'delete_item') {
  require_permission(can_delete_list_item());
  $product_id = (int)post('product_id', '0');
  if ($product_id <= 0) {
    $error = 'Producto inválido.';
  } else {
    try {
      db()->beginTransaction();

      $st = db()->prepare("DELETE FROM stock_list_items WHERE stock_list_id = ? AND product_id = ?");
      $st->execute([$list_id, $product_id]);
      if ($st->rowCount() <= 0) {
        db()->rollBack();
        $error = 'El producto no está en el listado.';
      } else {
        $stCleanup = db()->prepare("DELETE FROM list_site_sync_progress WHERE list_id = ? AND product_id = ?");
        $stCleanup->execute([$list_id, $product_id]);

        db()->commit();
        $success = urlencode('Item eliminado.');
        redirect("list_view.php?id={$list_id}&msg={$success}");
      }
    } catch (Throwable $e) {
      if (db()->inTransaction()) {
        db()->rollBack();
      }
      $error = 'No se pudo eliminar el item. Intentá nuevamente.';
    }
  }
}

// Scan code
if (is_post() && post('action') === 'scan') {
  require_permission($can_scan_action);
  $should_focus_scan = true;
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = trim((string)post('code'));
    if ($code === '') {
      $error = 'Escaneá o pegá un código.';
    } else {
      $st = db()->prepare("
        SELECT pc.product_id, p.sku, p.name
        FROM product_codes pc
        JOIN products p ON p.id = pc.product_id
        WHERE pc.code_type = 'BARRA' AND LOWER(pc.code) = LOWER(?)
        LIMIT 1
      ");
      $st->execute([$code]);
      $found = $st->fetch();
      if (!$found) {
        $st = db()->prepare("
          SELECT p.id AS product_id, p.sku, p.name
          FROM products p
          WHERE LOWER(p.sku) = LOWER(?)
          LIMIT 1
        ");
        $st->execute([$code]);
        $found = $st->fetch();
      }

      if (!$found) {
        $st = db()->prepare("
          SELECT pc.product_id, p.sku, p.name
          FROM product_codes pc
          JOIN products p ON p.id = pc.product_id
          WHERE pc.code_type = 'MPN' AND LOWER(pc.code) = LOWER(?)
          LIMIT 1
        ");
        $st->execute([$code]);
        $found = $st->fetch();
      }

      if (!$found) {
        $error = 'Producto no encontrado';
        $unknown_code = $code;
      } else {
        $pid = (int)$found['product_id'];
        if ($scan_mode === 'subtract') {
          $st = db()->prepare("SELECT qty, synced_qty FROM stock_list_items WHERE stock_list_id = ? AND product_id = ? LIMIT 1");
          $st->execute([$list_id, $pid]);
          $item = $st->fetch();
          if (!$item) {
            $error = 'No se puede restar: el producto no está en el listado.';
          } elseif ((int)$item['qty'] <= 0) {
            $error = 'No se puede restar: la cantidad ya está en 0.';
          } elseif ((int)$item['qty'] === 1) {
            try {
              db()->beginTransaction();

              $st = db()->prepare("DELETE FROM stock_list_items WHERE stock_list_id = ? AND product_id = ?");
              $st->execute([$list_id, $pid]);

              $stCleanup = db()->prepare("DELETE FROM list_site_sync_progress WHERE list_id = ? AND product_id = ?");
              $stCleanup->execute([$list_id, $pid]);

              db()->commit();
              $message = "Restado: {$found['sku']} - {$found['name']}";
              $clear_scan_input = true;
            } catch (Throwable $e) {
              if (db()->inTransaction()) {
                db()->rollBack();
              }
              $error = 'No se pudo restar el producto. Intentá nuevamente.';
            }
          } else {
            $new_qty = (int)$item['qty'] - 1;
            $new_synced = min((int)$item['synced_qty'], $new_qty);
            $st = db()->prepare("UPDATE stock_list_items SET qty = ?, synced_qty = ?, updated_at = NOW() WHERE stock_list_id = ? AND product_id = ?");
            $st->execute([$new_qty, $new_synced, $list_id, $pid]);
            $message = "Restado: {$found['sku']} - {$found['name']}";
            $clear_scan_input = true;
          }
        } else {
          list_add_product_qty($list_id, $pid);
          $message = "Sumado: {$found['sku']} - {$found['name']}";
          $clear_scan_input = true;
        }
      }
    }
  }
}

// Asociar código a producto existente (desde el cartel)
if (is_post() && post('action') === 'associate_code') {
  require_permission($can_add_code_action);
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = trim((string)post('unknown_code'));
    $product_id = (int)post('product_id','0');
    if ($code === '' || $product_id <= 0) {
      $error = 'Falta código o producto.';
    } else {
      $st = db()->prepare("SELECT product_id FROM product_codes WHERE LOWER(code) = LOWER(?) LIMIT 1");
      $st->execute([$code]);
      $existing = $st->fetch();
      if ($existing && (int)$existing['product_id'] !== $product_id) {
        $error = 'Ese código ya está asignado a otro producto.';
      }
    }
    if (!$error) {
      // Insert code (si ya existe, error por UNIQUE)
      try {
        $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
        $st->execute([$product_id, $code]);
      } catch (Throwable $t) {
        $error = 'Ese código ya está asignado a otro producto.';
      }
      if (!$error) {
        // Sumar al listado
        $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
          ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
        $st->execute([$list_id, $product_id]);
        $message = "Código asociado y sumado +1.";
        $unknown_code = '';
        $clear_scan_input = true;
        $should_focus_scan = true;
      }
    }
  }
}

// Crear producto nuevo desde el cartel
if (is_post() && post('action') === 'create_product_from_code') {
  require_permission($can_edit_product_action);
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = trim((string)post('unknown_code'));
    $sku  = trim((string)post('new_sku'));
    $name = trim((string)post('new_name'));
    $brand = trim((string)post('new_brand'));
    if ($code === '' || $sku === '' || $name === '') {
      $error = 'Completá al menos SKU y Nombre para crear el producto.';
    } else {
      try {
        db()->beginTransaction();
        $st = db()->prepare("SELECT product_id FROM product_codes WHERE LOWER(code) = LOWER(?) LIMIT 1");
        $st->execute([$code]);
        $existing = $st->fetch();
        if ($existing) {
          throw new RuntimeException('CODE_USED');
        }
        $brand_id = resolve_brand_id($brand);
        $st = db()->prepare("INSERT INTO products(sku, name, brand, brand_id, updated_at) VALUES(?, ?, ?, ?, NOW())");
        $st->execute([$sku, $name, $brand, $brand_id]);
        $pid = (int)db()->lastInsertId();

        $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
        $st->execute([$pid, $code]);

        $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
          ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
        $st->execute([$list_id, $pid]);

        db()->commit();
        $message = "Producto creado y sumado +1.";
        $unknown_code = '';
        $clear_scan_input = true;
        $should_focus_scan = true;
      } catch (Throwable $t) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $t->getMessage() === 'CODE_USED'
          ? 'Ese código ya está asignado a otro producto.'
          : 'No se pudo crear. Verificá que el SKU y el código no estén repetidos.';
      }
    }
  }
}

// Buscar productos existentes para asociar
if (is_post() && post('action') === 'search_products') {
  require_permission($can_manage_unknown_code);
  $unknown_code = trim((string)post('unknown_code'));
  $search_term = trim((string)post('product_search'));
  if ($search_term !== '') {
    $like = '%' . $search_term . '%';
    $st = db()->prepare("SELECT p.id, p.sku, p.name, COALESCE(b.name, p.brand) AS brand
      FROM products p
      LEFT JOIN brands b ON b.id = p.brand_id
      WHERE p.sku LIKE ? OR p.name LIKE ? OR COALESCE(b.name, p.brand) LIKE ?
      ORDER BY p.name ASC
      LIMIT 200");
    $st->execute([$like, $like, $like]);
    $search_results = $st->fetchAll();
  }
}

// Volver a cargar list data after actions
$st = db()->prepare("
  SELECT sl.*, u.first_name, u.last_name
  FROM stock_lists sl
  JOIN users u ON u.id = sl.created_by
  WHERE sl.id = ?
  LIMIT 1
");
$st->execute([$list_id]);
$list = $st->fetch();

$items = db()->prepare("
  SELECT p.id AS product_id, p.sku, p.name,
    (
      SELECT MIN(ps.supplier_sku)
      FROM product_suppliers ps
      WHERE ps.product_id = p.id
    ) AS supplier_sku,
    i.qty, i.synced_qty, i.updated_at
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY i.updated_at DESC, p.name ASC
");
$items->execute([$list_id]);
$items = $items->fetchAll();

$push_sites = stock_sync_push_enabled_sites();
$push_site_ids = [];
$push_site_headers = [];
foreach ($push_sites as $site) {
  $site_id = (int)($site['id'] ?? 0);
  if ($site_id <= 0) {
    continue;
  }
  $push_site_ids[] = $site_id;
  $name = trim((string)($site['name'] ?? ('Sitio #' . $site_id)));
  if ($name === '') {
    $name = 'Sitio #' . $site_id;
  }
  $push_site_headers[$site_id] = $name;
}

$item_site_progress = [];
if (count($items) > 0 && count($push_site_ids) > 0) {
  $product_ids = [];
  foreach ($items as $row) {
    $product_ids[] = (int)$row['product_id'];
  }

  $site_placeholders = implode(',', array_fill(0, count($push_site_ids), '?'));
  $prod_placeholders = implode(',', array_fill(0, count($product_ids), '?'));
  $stProgress = db()->prepare("SELECT list_id, product_id, site_id, synced_qty, target_qty, status
    FROM list_site_sync_progress
    WHERE list_id = ?
      AND site_id IN ($site_placeholders)
      AND product_id IN ($prod_placeholders)");
  $params = array_merge([$list_id], $push_site_ids, $product_ids);
  $stProgress->execute($params);
  foreach ($stProgress->fetchAll() as $row) {
    $pid = (int)$row['product_id'];
    $sid = (int)$row['site_id'];
    if (!isset($item_site_progress[$pid])) {
      $item_site_progress[$pid] = [];
    }
    $item_site_progress[$pid][$sid] = [
      'synced_qty' => max(0, (int)$row['synced_qty']),
      'target_qty' => max(0, (int)$row['target_qty']),
      'status' => (string)$row['status'],
    ];
  }
}

$total_units = 0;
$total_pending = 0;
foreach ($items as $it) {
  $qty = (int)$it['qty'];
  $synced_qty = min((int)$it['synced_qty'], $qty);
  $total_units += $qty;
  $total_pending += max(0, $qty - $synced_qty);
}

$sync_blocked = $list['status'] !== 'open';
$can_sync = !$sync_blocked && $total_pending > 0;
$can_sync_all = false;
if (!$sync_blocked && count($push_site_ids) > 0) {
  foreach ($items as $it) {
    $pid = (int)$it['product_id'];
    $target_qty = max(0, (int)$it['qty']);
    foreach ($push_site_ids as $site_id) {
      $progress = $item_site_progress[$pid][$site_id] ?? null;
      $status = (string)($progress['status'] ?? 'pending');
      if ($status === 'na') {
        continue;
      }
      $synced = max(0, (int)($progress['synced_qty'] ?? 0));
      if ($synced < $target_qty || $status !== 'done') {
        $can_sync_all = true;
        break 2;
      }
    }
  }
}
$can_sync_action = can_sync_prestashop();
$can_delete_action = can_delete_list_item();
$showActionsColumn = $can_delete_action;
$list_name = trim((string)$list['name']);
$page_title = $list_name !== '' ? $list_name : 'Listado';
$show_subtitle = $list_name !== '' && $list_name !== $page_title;

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
  <style>
    .row-link {
      color: inherit;
      text-decoration: none;
    }

    .row-link:hover {
      text-decoration: underline;
    }

    .dv-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .55);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }

    .dv-modal[hidden] {
      display: none;
    }

    .dv-modal-card {
      width: min(980px, 92vw);
      max-height: 80vh;
      overflow: auto;
      background: var(--card, #fff);
      border-radius: 10px;
      border: 1px solid rgba(127, 127, 127, .25);
    }

    .dv-modal-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 14px;
      border-bottom: 1px solid rgba(127, 127, 127, .2);
    }

    .dv-modal-body {
      padding: 14px;
    }
  </style>
</head>
<body class="<?= e(app_body_class()) ?>">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title"><?= e($page_title) ?></h2>
      <?php if ($show_subtitle): ?>
        <span class="muted"><?= e($list_name) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card stack">
      <div class="form-row">
        <div><strong>id:</strong> <?= (int)$list['id'] ?></div>
        <div><strong>fecha:</strong> <?= e($list['created_at']) ?></div>
        <div><strong>creador:</strong> <?= e($list['first_name'] . ' ' . $list['last_name']) ?></div>
        <div>
          <strong>sync:</strong>
          <?php if ($list['sync_target'] === 'prestashop'): ?>
            <span class="badge badge-success">prestashop</span>
          <?php else: ?>
            <span class="badge badge-muted">sin sync</span>
          <?php endif; ?>
        </div>
        <div>
          <strong>estado:</strong>
          <?php if ($list['status'] === 'open'): ?>
            <span class="badge badge-success">Abierto</span>
          <?php else: ?>
            <span class="badge badge-warning">Cerrado</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="inline-actions">
        <a class="btn btn-ghost" href="download_excel.php?id=<?= (int)$list['id'] ?>">Descargar Excel</a>

        <?php if ($list['status'] === 'open' && $can_close_action): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="toggle_status">
            <button class="btn btn-secondary" type="submit">Cerrar</button>
          </form>
        <?php elseif ($list['status'] === 'closed' && $can_reopen_action): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="toggle_status">
            <button class="btn btn-secondary" type="submit">Abrir</button>
          </form>
        <?php endif; ?>

        <?php if ($can_sync_action): ?>
          <form method="post" style="display:inline;" action="ps_sync.php?id=<?= (int)$list['id'] ?>">
            <button class="btn" type="submit" <?= $can_sync ? '' : 'disabled' ?>>
              Sincronizar a PrestaShop
            </button>
            <?php if ($sync_blocked): ?>
              <span class="muted small">(listado cerrado)</span>
            <?php elseif (!$can_sync): ?>
              <span class="muted small">(sin pendientes)</span>
            <?php endif; ?>
          </form>

          <form method="post" style="display:inline;" action="all_sync.php?id=<?= (int)$list['id'] ?>">
            <button class="btn" type="submit" <?= $can_sync_all ? '' : 'disabled' ?>>
              <?= $can_sync_all ? 'Sincronizar Todo' : 'Todo sincronizado' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="inline-actions">
        <span class="kpi">Total unidades: <?= (int)$total_units ?></span>
        <span class="kpi">Productos distintos: <?= count($items) ?></span>
      </div>
    </div>

    <?php if ($unknown_code !== '' && $can_manage_unknown_code): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Código no encontrado</h3>
          <span class="badge badge-danger"><?= e($unknown_code) ?></span>
        </div>

        <div class="stack">
          <?php if ($can_add_code_action): ?>
          <div>
            <h4>1) Asociar a un producto existente</h4>
            <form method="post" class="stack">
              <input type="hidden" name="action" value="search_products">
              <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
              <div class="form-group">
                <label class="form-label">Buscar producto (por nombre / sku / marca)</label>
                <input class="form-control" type="text" name="product_search" value="<?= e($search_term) ?>" placeholder="Escribí y buscá abajo" />
              </div>
              <div class="form-actions">
                <button class="btn" type="submit">Buscar</button>
              </div>
            </form>
            <?php if ($search_term === ''): ?>
              <p class="muted small">Usá el botón Buscar para ver resultados y elegir el producto.</p>
            <?php elseif (!$search_results): ?>
              <p class="muted">No hubo resultados con esa búsqueda.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table class="table">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>Nombre</th>
                      <th>Marca</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($search_results as $res): ?>
                      <tr>
                        <td><?= e($res['sku']) ?></td>
                        <td><?= e($res['name']) ?></td>
                        <td><?= e($res['brand']) ?></td>
                        <td class="table-actions">
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="associate_code">
                            <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
                            <input type="hidden" name="product_id" value="<?= (int)$res['id'] ?>">
                            <button class="btn" type="submit">Asociar</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($can_edit_product_action): ?>
            <div>
              <h4>2) Crear producto nuevo y sumar</h4>
              <form method="post" class="stack">
                <input type="hidden" name="action" value="create_product_from_code">
                <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label">SKU (obligatorio)</label>
                    <input class="form-control" type="text" name="new_sku" value="<?= e(post('new_sku')) ?>" required>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Nombre (obligatorio)</label>
                    <input class="form-control" type="text" name="new_name" value="<?= e(post('new_name')) ?>" required>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Marca</label>
                    <input class="form-control" type="text" name="new_brand" value="<?= e(post('new_brand')) ?>">
                  </div>
                </div>
                <div class="form-actions">
                  <button class="btn" type="submit">Crear y sumar +1</button>
                  <a class="btn btn-ghost" href="list_view.php?id=<?= (int)$list_id ?>">Cancelar</a>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($can_scan_action): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Cargar por escaneo</h3>
          <?php if ($list['status'] !== 'open'): ?>
            <span class="badge badge-warning">Listado cerrado</span>
          <?php endif; ?>
        </div>
        <form method="post" class="form-row">
          <input type="hidden" name="action" value="scan">
          <div class="form-group">
            <label class="form-label">Modo</label>
            <select name="scan_mode">
              <option value="add" <?= $scan_mode === 'add' ? 'selected' : '' ?>>Sumar +1</option>
              <option value="subtract" <?= $scan_mode === 'subtract' ? 'selected' : '' ?>>Restar -1</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Código</label>
            <input class="form-control" type="text" id="scan-code" name="code" value="<?= e($unknown_code !== '' ? $unknown_code : post('code')) ?>" autofocus placeholder="Escaneá acá (enter)..." <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>
          </div>
          <div class="form-group" style="align-self:end;">
            <button class="btn" type="submit" <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>Aplicar</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Items</h3>
        <span class="muted small"><?= count($items) ?> productos</span>
      </div>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>sku</th>
              <th>sku proveedor</th>
              <th>nombre</th>
              <th>cantidad</th>
              <th>PrestaShop</th>
              <?php foreach ($push_site_ids as $site_id): ?>
                <th><?= e($push_site_headers[$site_id] ?? ('Sitio #' . $site_id)) ?></th>
              <?php endforeach; ?>
              <?php if ($showActionsColumn): ?>
                <th>acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$items): ?>
              <tr><td colspan="<?= ($showActionsColumn ? 6 : 5) + count($push_site_ids) ?>">Sin items todavía.</td></tr>
            <?php else: ?>
              <?php foreach ($items as $it): ?>
                <?php
                  $qty = (int)$it['qty'];
                  $synced_qty = min((int)$it['synced_qty'], $qty);
                  $product_id = (int)$it['product_id'];
                ?>
                <tr>
                  <td>
                    <a class="row-link" href="product_view.php?id=<?= $product_id ?>"><?= e($it['sku']) ?></a>
                  </td>
                  <td><?= e($it['supplier_sku'] ?? '—') ?></td>
                  <td>
                    <a class="row-link" href="product_view.php?id=<?= $product_id ?>"><?= e($it['name']) ?></a>
                  </td>
                  <td><?= $qty ?></td>
                  <td>
                    <?php if ($synced_qty >= $qty): ?>
                      <span class="badge badge-success"><?= $synced_qty ?>/<?= $qty ?></span>
                    <?php elseif ($synced_qty > 0): ?>
                      <span class="badge badge-warning"><?= $synced_qty ?>/<?= $qty ?></span>
                    <?php else: ?>
                      <span class="badge badge-muted">0/<?= $qty ?></span>
                    <?php endif; ?>
                  </td>
                  <?php foreach ($push_site_ids as $site_id): ?>
                    <?php
                      $progress = $item_site_progress[(int)$it['product_id']][$site_id] ?? null;
                      $site_status = (string)($progress['status'] ?? 'pending');
                      if ($site_status === 'na') {
                        $site_synced = null;
                        $site_target = null;
                      } else {
                        $site_target = $qty;
                        $site_synced = min($site_target, max(0, (int)($progress['synced_qty'] ?? 0)));
                      }
                    ?>
                    <td>
                      <?php if ($site_synced === null): ?>
                        <span class="badge badge-muted">N/A</span>
                      <?php elseif ($site_synced >= $site_target): ?>
                        <span class="badge badge-success"><?= $site_synced ?>/<?= $site_target ?></span>
                      <?php elseif ($site_synced > 0): ?>
                        <span class="badge badge-warning"><?= $site_synced ?>/<?= $site_target ?></span>
                      <?php else: ?>
                        <span class="badge badge-muted">0/<?= $site_target ?></span>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                  <?php if ($showActionsColumn): ?>
                    <td class="table-actions">
                      <?php if ($can_delete_action): ?>
                        <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar este item del listado?');">
                          <input type="hidden" name="action" value="delete_item">
                          <input type="hidden" name="product_id" value="<?= $product_id ?>">
                          <button class="btn btn-danger" type="submit">Eliminar</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php require_once __DIR__ . '/include/partials/messages_block.php'; ?>
    <?php ts_messages_block('listado', $list_id); ?>
  </div>
</main>
<?php if ($can_scan_action && $list['status'] === 'open' && ($should_focus_scan || $clear_scan_input)): ?>
<script>
  (function() {
    var input = document.getElementById('scan-code');
    if (!input) return;
    <?php if ($clear_scan_input): ?>
    input.value = '';
    <?php endif; ?>
    input.focus();
  })();
</script>
<?php endif; ?>
<?php if ($can_scan_action && $list['status'] === 'open'): ?>
<div id="scanPickModal" class="dv-modal" hidden>
  <div class="dv-modal-card">
    <div class="dv-modal-head">
      <div>
        <strong>Se encontraron varias coincidencias</strong>
        <div class="muted small">Elegí el producto correcto para agregar al listado</div>
      </div>
      <button type="button" class="btn" id="scanPickClose">Cerrar</button>
    </div>
    <div class="dv-modal-body">
      <table class="table">
        <thead>
          <tr>
            <th>SKU</th>
            <th>SKU PROVEEDOR</th>
            <th>NOMBRE</th>
            <th>MARCA</th>
            <th>ACCIÓN</th>
          </tr>
        </thead>
        <tbody id="scanPickBody"></tbody>
      </table>
    </div>
  </div>
</div>
<script>
  (function() {
    var scanForm = document.querySelector('form input[name="action"][value="scan"]');
    if (!scanForm) return;
    scanForm = scanForm.closest('form');
    if (!scanForm) return;

    var modeSelect = scanForm.querySelector('select[name="scan_mode"]');
    var codeInput = scanForm.querySelector('input[name="code"]');
    var modal = document.getElementById('scanPickModal');
    var modalBody = document.getElementById('scanPickBody');
    var closeBtn = document.getElementById('scanPickClose');

    function escapeHtml(value) {
      return String(value || '').replace(/[&<>'"]/g, function(ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','\'':'&#039;','"':'&quot;'})[ch] || ch;
      });
    }

    function closeModal() {
      if (modal) modal.hidden = true;
      if (modalBody) modalBody.innerHTML = '';
      if (codeInput) {
        codeInput.value = '';
        codeInput.focus();
      }
    }

    async function chooseProduct(productId) {
      var payload = new URLSearchParams();
      payload.set('action', 'scan_add_choose');
      payload.set('product_id', String(productId));
      var res = await fetch('list_view.php?id=<?= (int)$list_id ?>', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: payload
      });
      var data = await res.json();
      if (!data.ok) {
        alert(data.message || 'No se pudo agregar el producto.');
        return;
      }
      window.location.reload();
    }

    function openScanPick(matches) {
      if (!modal || !modalBody) return;
      modalBody.innerHTML = '';
      matches.forEach(function(m) {
        var tr = document.createElement('tr');
        tr.innerHTML =
          '<td>' + escapeHtml(m.sku || '') + '</td>' +
          '<td>' + escapeHtml(m.supplier_sku || '—') + '</td>' +
          '<td>' + escapeHtml(m.name || '') + '</td>' +
          '<td>' + escapeHtml(m.brand || '') + '</td>' +
          '<td><button type="button" class="btn btn-primary" data-pid="' + escapeHtml(m.id) + '">Elegir</button></td>';
        modalBody.appendChild(tr);
      });

      modalBody.querySelectorAll('button[data-pid]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          chooseProduct(btn.getAttribute('data-pid'));
        });
      });

      modal.hidden = false;
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }
    if (modal) {
      modal.addEventListener('click', function(ev) {
        if (ev.target === modal) closeModal();
      });
    }

    scanForm.addEventListener('submit', async function(ev) {
      if (!modeSelect || modeSelect.value !== 'add') return;
      ev.preventDefault();

      var payload = new URLSearchParams(new FormData(scanForm));
      payload.set('action', 'scan_lookup');

      try {
        var res = await fetch('list_view.php?id=<?= (int)$list_id ?>', {
          method: 'POST',
          headers: {'X-Requested-With': 'XMLHttpRequest'},
          body: payload
        });
        var data = await res.json();
        if (!data.ok) {
          alert(data.message || 'No se pudo buscar el producto.');
          return;
        }

        if (data.mode === 'choose') {
          openScanPick(data.matches || []);
          return;
        }

        window.location.reload();
      } catch (err) {
        alert('No se pudo completar el escaneo.');
      }
    });
  })();
</script>
<?php endif; ?>
</body>
</html>
