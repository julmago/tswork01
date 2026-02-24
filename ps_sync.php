<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prestashop.php';
require_login();
require_permission(can_sync_prestashop());

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id de listado.');

$st = db()->prepare("SELECT * FROM stock_lists WHERE id = ? LIMIT 1");
$st->execute([$list_id]);
$list = $st->fetch();
if (!$list) abort(404, 'Listado no encontrado.');
if ($list['status'] !== 'open') abort(400, 'Este listado está cerrado y no se puede sincronizar.');

$st = db()->prepare("
  SELECT p.id AS product_id, p.sku, p.name, i.qty, i.synced_qty
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY p.name ASC
");
$st->execute([$list_id]);
$items = $st->fetchAll();

$results = [];
$ok = 0; $fail = 0; $skip = 0;
$total_sent = 0;
$pending_after_total = 0;
$mode = ps_mode(); // replace|add

foreach ($items as $it) {
  $sku = (string)$it['sku'];
  $qty = (int)$it['qty'];
  $synced_qty = min((int)$it['synced_qty'], $qty);
  $pending_qty = $qty - $synced_qty;

  if ($pending_qty <= 0) {
    $results[] = ['sku'=>$sku,'name'=>$it['name'],'qty'=>0,'status'=>'SIN PENDIENTE','detail'=>'No hay unidades nuevas para enviar.'];
    $pending_after_total += 0;
    $skip++;
    continue;
  }

  try {
    $match = ps_find_by_reference($sku);
    if (!$match) {
      throw new RuntimeException("No existe en PrestaShop por reference/SKU.");
    }

    $id_product = (int)$match['id_product'];
    $id_attr = (int)$match['id_product_attribute'];

    $id_sa = ps_find_stock_available_id($id_product, $id_attr);
    if (!$id_sa) {
      $stock_message = "El producto existe pero no tiene stock creado en PrestaShop. Abrí el producto en PrestaShop, tocá el stock y guardá.";
      try {
        $id_sa = ps_create_stock_available($id_product, $id_attr, $qty);
      } catch (Throwable $stock_error) {
        throw new RuntimeException($stock_message . " (No se pudo crear automáticamente.)");
      }
    }

    $cur = ps_get_stock_available($id_sa);
    $current_qty = (int)$cur['qty'];
    if ($mode === 'add') {
      $new_qty = $current_qty + $pending_qty;
    } else {
      $new_qty = $synced_qty + $pending_qty;
      if ($new_qty < 0) {
        $new_qty = 0;
      }
    }

    error_log(sprintf(
      '[PrestaShop] Sync stock | SKU: %s | current_qty: %d | listado_qty: %d | target_qty: %d',
      $sku,
      $current_qty,
      $pending_qty,
      $new_qty
    ));

    ps_update_stock_available_quantity($id_sa, $new_qty);

    $st = db()->prepare("UPDATE stock_list_items SET synced_qty = LEAST(qty, synced_qty + ?) WHERE stock_list_id = ? AND product_id = ?");
    $st->execute([$pending_qty, $list_id, (int)$it['product_id']]);

    $total_sent += $pending_qty;
    $pending_after_total += max(0, $qty - min($qty, $synced_qty + $pending_qty));
    $results[] = ['sku'=>$sku,'name'=>$it['name'],'qty'=>$pending_qty,'status'=>'OK','detail'=>"stock_available #{$id_sa} => {$new_qty}"];
    $ok++;
  } catch (Throwable $t) {
    $pending_after_total += max(0, $qty - $synced_qty);
    $results[] = ['sku'=>$sku,'name'=>$it['name'],'qty'=>$pending_qty,'status'=>'ERROR','detail'=>$t->getMessage()];
    $fail++;
  }
}

$synced = $fail === 0 && $total_sent > 0;
if ($total_sent > 0) {
  $st = db()->prepare("UPDATE stock_lists SET sync_target='prestashop', synced_at=NOW() WHERE id = ?");
  $st->execute([$list_id]);
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>
<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Sincronización PrestaShop</h2>
      <span class="muted">Listado #<?= (int)$list_id ?></span>
    </div>

    <div class="card stack">
      <div class="form-row">
        <div><strong>Modo:</strong> <?= e($mode === 'add' ? 'Sumar (+)' : 'Reemplazar (=)') ?></div>
        <div><strong>Total enviados en esta sincronización:</strong> <?= (int)$total_sent ?></div>
        <div><strong>Pendiente después de sincronizar:</strong> <?= (int)$pending_after_total ?></div>
      </div>

      <?php if ($synced): ?>
        <div class="alert alert-success"><strong>OK:</strong> sincronización completa.</div>
      <?php else: ?>
        <div class="alert alert-danger"><strong>Atención:</strong> hubo errores (<?= (int)$fail ?>).</div>
        <p class="muted small">Solución típica: corregí SKUs/reference en PrestaShop o en tu catálogo y volvé a intentar.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr><th>SKU</th><th>Nombre</th><th>Cant.</th><th>Resultado</th><th>Detalle</th></tr>
          </thead>
          <tbody>
            <?php foreach ($results as $r): ?>
              <tr>
                <td><?= e($r['sku']) ?></td>
                <td><?= e($r['name']) ?></td>
                <td><?= (int)$r['qty'] ?></td>
                <td>
                  <?php if ($r['status'] === 'OK'): ?>
                    <span class="badge badge-success"><?= e($r['status']) ?></span>
                  <?php elseif ($r['status'] === 'SIN PENDIENTE'): ?>
                    <span class="badge badge-muted"><?= e($r['status']) ?></span>
                  <?php else: ?>
                    <span class="badge badge-danger"><?= e($r['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e($r['detail']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$results): ?>
              <tr><td colspan="5">El listado no tiene items.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="form-actions">
        <a class="btn btn-ghost" href="list_view.php?id=<?= (int)$list_id ?>">Volver al listado</a>
      </div>
    </div>
  </div>
</main>
</body>
</html>
