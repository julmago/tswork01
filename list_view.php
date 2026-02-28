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

function list_add_product_qty(int $listId, int $productId): void {
  $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
    ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
  $st->execute([$listId, $productId]);
}

$message = (string)get('msg', '');
$error = '';
$should_focus_scan = false;
$clear_scan_input = false;
$scan_mode = (string)post('scan_mode', 'add');
$can_scan_action = can_scan();
$can_close_action = can_close_list();
$can_reopen_action = can_reopen_list();
$can_edit_product_action = can_edit_product();
$can_add_code_action = can_add_code();
$brands = fetch_brands();

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
      padding: 16px;
    }

    .dv-modal[hidden] {
      display: none;
    }

    .dv-modal-card {
      width: min(1100px, 95vw);
      max-height: 85vh;
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
            <input class="form-control" type="text" id="scan-code" name="code" value="<?= e(post('code')) ?>" autofocus placeholder="Escaneá acá (enter)..." <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>
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
<div id="scanModal" class="dv-modal" hidden>
  <div class="dv-modal-card card">
    <div class="dv-modal-head">
      <div>
        <strong id="scanModalTitle">Resultado del escaneo</strong>
        <div class="muted small">Código escaneado: <span id="scanCodeBadge" class="badge badge-danger"></span></div>
      </div>
      <button type="button" class="btn" id="scanModalClose">Cerrar</button>
    </div>
    <div class="dv-modal-body stack">
      <div id="scanModalFeedback" class="alert alert-danger" hidden></div>
      <div id="scanManySection" hidden>
        <h4>Se encontraron múltiples coincidencias</h4>
        <table class="table">
          <thead><tr><th>SKU</th><th>Nombre</th><th></th></tr></thead>
          <tbody id="scanManyBody"></tbody>
        </table>
      </div>

      <div id="scanNoneSection" hidden>
        <?php if (!$can_add_code_action && !$can_edit_product_action): ?>
          <div class="muted">No tenés permisos para vincular o crear productos desde este modal.</div>
        <?php endif; ?>
        <?php if ($can_add_code_action): ?>
        <h4>Buscar producto existente</h4>
        <div class="form-row">
          <div class="form-group" style="flex:1;">
            <input type="text" id="scanSearchText" class="form-control" placeholder="SKU, nombre, código de barra o código proveedor">
          </div>
          <div class="form-group">
            <button type="button" class="btn" id="scanSearchBtn">Buscar</button>
          </div>
        </div>
        <table class="table">
          <thead><tr><th>SKU</th><th>Nombre</th><th></th></tr></thead>
          <tbody id="scanSearchBody"><tr><td colspan="3" class="muted">Buscá un producto para vincular.</td></tr></tbody>
        </table>

        <?php endif; ?>

        <?php if ($can_edit_product_action): ?>
        <h4>Crear producto nuevo</h4>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">SKU</label>
            <input type="text" id="scanNewSku" class="form-control">
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Nombre</label>
            <input type="text" id="scanNewName" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Marca</label>
            <select id="scanNewBrand" class="form-control">
              <option value="0">Sin Marca</option>
              <?php foreach ($brands as $brand): ?>
                <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="button" class="btn" id="scanCreateBtn">Crear y vincular</button>
        <?php endif; ?>
      </div>
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
  var modal = document.getElementById('scanModal');
  var modalTitle = document.getElementById('scanModalTitle');
  var codeBadge = document.getElementById('scanCodeBadge');
  var manySection = document.getElementById('scanManySection');
  var manyBody = document.getElementById('scanManyBody');
  var noneSection = document.getElementById('scanNoneSection');
  var closeBtn = document.getElementById('scanModalClose');
  var searchText = document.getElementById('scanSearchText');
  var searchBtn = document.getElementById('scanSearchBtn');
  var searchBody = document.getElementById('scanSearchBody');
  var newSku = document.getElementById('scanNewSku');
  var newName = document.getElementById('scanNewName');
  var newBrand = document.getElementById('scanNewBrand');
  var createBtn = document.getElementById('scanCreateBtn');
  var scannedCode = '';
  var feedback = document.getElementById('scanModalFeedback');

  function setFeedback(message) {
    if (!feedback) return;
    if (!message) {
      feedback.hidden = true;
      feedback.textContent = '';
      return;
    }
    feedback.hidden = false;
    feedback.textContent = message;
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function(ch) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','\'':'&#039;','"':'&quot;'})[ch] || ch;
    });
  }

  async function postScanApi(action, payload) {
    payload = payload || {};
    payload.action = action;
    var body = new URLSearchParams(payload);
    var res = await fetch('api/scan.php', { method: 'POST', body: body });
    var data = await res.json();
    return { res: res, data: data };
  }

  function closeModal() {
    setFeedback('');
    if (modal) modal.hidden = true;
    if (manyBody) manyBody.innerHTML = '';
    if (codeInput) {
      codeInput.value = '';
      codeInput.focus();
    }
  }

  function openModal(title, code) {
    setFeedback('');
    scannedCode = code;
    modalTitle.textContent = title;
    codeBadge.textContent = code;
    if (modal) modal.hidden = false;
  }

  async function addToList(productId) {
    var result = await postScanApi('add_to_list', {
      list_id: '<?= (int)$list_id ?>',
      product_id: String(productId),
      delta: '1'
    });
    if (!result.data.ok) {
      throw new Error(result.data.message || 'No se pudo sumar al listado.');
    }
  }

  async function linkAndAdd(productId) {
    var link = await postScanApi('link_code', { product_id: String(productId), code: scannedCode });
    if (!link.data.ok && link.data.needs_confirm) {
      var sure = window.confirm(link.data.message || 'El código ya existe en otro producto. ¿Reasignar?');
      if (!sure) return;
      link = await postScanApi('link_code', { product_id: String(productId), code: scannedCode, confirm: '1' });
    }
    if (!link.data.ok) {
      throw new Error(link.data.message || 'No se pudo vincular el código.');
    }

    await addToList(productId);
    window.location.reload();
  }

  function renderMany(matches) {
    manySection.hidden = false;
    noneSection.hidden = true;
    manyBody.innerHTML = '';
    matches.forEach(function(item) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>' + escapeHtml(item.sku || '') + '</td>' +
        '<td>' + escapeHtml(item.name || '') + '</td>' +
        '<td><button type="button" class="btn" data-pid="' + escapeHtml(item.id) + '">Elegir</button></td>';
      manyBody.appendChild(tr);
    });

    manyBody.querySelectorAll('button[data-pid]').forEach(function(btn) {
      btn.addEventListener('click', async function() {
        try {
          await addToList(btn.getAttribute('data-pid'));
          window.location.reload();
        } catch (err) {
          setFeedback(err.message || 'No se pudo agregar.');
        }
      });
    });
  }

  function renderSearch(matches) {
    if (!searchBody) return;
    if (!matches.length) {
      searchBody.innerHTML = '<tr><td colspan="3" class="muted">Sin resultados.</td></tr>';
      return;
    }

    searchBody.innerHTML = '';
    matches.forEach(function(item) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>' + escapeHtml(item.sku || '') + '</td>' +
        '<td>' + escapeHtml(item.name || '') + '</td>' +
        '<td><button type="button" class="btn" data-link-id="' + escapeHtml(item.id) + '">Vincular</button></td>';
      searchBody.appendChild(tr);
    });

    searchBody.querySelectorAll('button[data-link-id]').forEach(function(btn) {
      btn.addEventListener('click', async function() {
        try {
          await linkAndAdd(btn.getAttribute('data-link-id'));
        } catch (err) {
          setFeedback(err.message || 'No se pudo vincular.');
        }
      });
    });
  }

  function openNotFound(code) {
    manySection.hidden = true;
    noneSection.hidden = false;
    if (searchText) searchText.value = '';
    if (searchBody) searchBody.innerHTML = '<tr><td colspan="3" class="muted">Buscá un producto para vincular.</td></tr>';
    if (newSku) newSku.value = '';
    if (newName) newName.value = '';
    if (newBrand) newBrand.value = '0';
    openModal('Código no encontrado', code);
  }

  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (modal) {
    modal.addEventListener('click', function(ev) {
      if (ev.target === modal) closeModal();
    });
  }

  if (searchBtn) {
    searchBtn.addEventListener('click', async function() {
      var q = ((searchText && searchText.value) || '').trim();
      var result = await postScanApi('search', { q: q });
      if (!result.data.ok) {
        setFeedback(result.data.message || 'No se pudo buscar.');
        return;
      }
      renderSearch(result.data.matches || []);
    });
  }

  if (createBtn) {
    createBtn.addEventListener('click', async function() {
      var sku = ((newSku && newSku.value) || '').trim();
      var name = ((newName && newName.value) || '').trim();
      if (!sku || !name) {
        setFeedback('Completá SKU y Nombre.');
        return;
      }

      var result = await postScanApi('create_product', {
        sku: sku,
        name: name,
        brand_id: String((newBrand && newBrand.value) || '0'),
        code: scannedCode
      });
      if (!result.data.ok) {
        setFeedback(result.data.message || 'No se pudo crear el producto.');
        return;
      }

      await addToList(result.data.product_id);
      window.location.reload();
    });
  }

  scanForm.addEventListener('submit', async function(ev) {
    if (modeSelect && modeSelect.value !== 'add') return;
    ev.preventDefault();

    var code = ((codeInput && codeInput.value) || '').trim();
    if (!code) return;

    try {
      var result = await postScanApi('lookup', { q: code });
      if (!result.data.ok) {
        setFeedback(result.data.message || 'No se pudo buscar.');
        return;
      }

      if (result.data.mode === 'one' && result.data.product && result.data.product.id) {
        await addToList(result.data.product.id);
        window.location.reload();
        return;
      }

      if (result.data.mode === 'many') {
        openModal('Múltiples coincidencias', code);
        renderMany(result.data.matches || []);
        return;
      }

      openNotFound(code);
    } catch (err) {
      setFeedback('No se pudo completar el escaneo.');
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
