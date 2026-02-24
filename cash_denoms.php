<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();

$cashbox = require_cashbox_selected(false);
require_permission(hasCashboxPerm('can_configure_bills', (int)$cashbox['id']), 'Sin permiso para configurar billetes.');
$message = '';
$error = '';

if (is_post() && post('action') === 'add_denom') {
  $value = (int)post('value');
  if ($value <= 0) {
    $error = 'El valor debe ser mayor a 0.';
  } else {
    $st = db()->prepare("SELECT COUNT(*) FROM cash_denominations WHERE cashbox_id = ? AND value = ?");
    $st->execute([(int)$cashbox['id'], $value]);
    if ((int)$st->fetchColumn() > 0) {
      $error = 'Ese billete ya existe para esta caja.';
    } else {
      $sort_order = (int)post('sort_order');
      $st = db()->prepare("INSERT INTO cash_denominations (cashbox_id, value, is_active, sort_order) VALUES (?, ?, 1, ?)");
      $st->execute([(int)$cashbox['id'], $value, $sort_order]);
      $message = 'Billete agregado.';
    }
  }
}

if (is_post() && post('action') === 'toggle_denom') {
  $denom_id = (int)post('denom_id');
  $is_active = post('is_active') === '1' ? 1 : 0;
  $st = db()->prepare("UPDATE cash_denominations SET is_active = ? WHERE id = ? AND cashbox_id = ?");
  $st->execute([$is_active, $denom_id, (int)$cashbox['id']]);
  $message = 'Estado actualizado.';
}

if (is_post() && post('action') === 'update_order') {
  $orders = post('sort_order');
  if (is_array($orders)) {
    $st = db()->prepare("UPDATE cash_denominations SET sort_order = ? WHERE id = ? AND cashbox_id = ?");
    foreach ($orders as $denom_id => $sort_order) {
      $st->execute([(int)$sort_order, (int)$denom_id, (int)$cashbox['id']]);
    }
    $message = 'Orden actualizado.';
  }
}

$list_st = db()->prepare("SELECT id, value, is_active, sort_order
  FROM cash_denominations
  WHERE cashbox_id = ?
  ORDER BY sort_order ASC, value ASC");
$list_st->execute([(int)$cashbox['id']]);
$denominations = $list_st->fetchAll();
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
      <h2 class="page-title">Configurar billetes</h2>
      <span class="muted">Caja: <?= e($cashbox['name']) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Agregar billete</h3>
      </div>
      <form method="post" class="stack">
        <input type="hidden" name="action" value="add_denom">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Valor</label>
            <input class="form-control" type="number" name="value" min="1" required>
          </div>
          <div class="form-group">
            <label class="form-label">Orden</label>
            <input class="form-control" type="number" name="sort_order" min="0" value="0">
          </div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Agregar</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Billetes configurados</h3>
      </div>
      <?php if ($denominations): ?>
        <form method="post" id="denom-order-form">
          <input type="hidden" name="action" value="update_order">
          <table class="cash-table">
            <thead>
              <tr>
                <th>Valor</th>
                <th>Orden</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($denominations as $denom): ?>
                <?php $is_active = (int)$denom['is_active'] === 1; ?>
                <tr>
                  <td>$<?= number_format((int)$denom['value'], 0, ',', '.') ?></td>
                  <td>
                    <input class="form-control" type="number" name="sort_order[<?= (int)$denom['id'] ?>]" value="<?= (int)$denom['sort_order'] ?>" style="max-width: 120px;">
                  </td>
                  <td><?= $is_active ? 'Activo' : 'Inactivo' ?></td>
                  <td>
                    <button class="btn btn-ghost" type="submit" form="toggle-denom-<?= (int)$denom['id'] ?>">
                      <?= $is_active ? 'Desactivar' : 'Activar' ?>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="form-actions" style="margin-top: var(--space-3);">
            <button class="btn" type="submit">Guardar orden</button>
            <a class="btn btn-ghost" href="<?= url_path('cash_select.php') ?>">Volver</a>
          </div>
        </form>
        <?php foreach ($denominations as $denom): ?>
          <?php $is_active = (int)$denom['is_active'] === 1; ?>
          <form method="post" id="toggle-denom-<?= (int)$denom['id'] ?>">
            <input type="hidden" name="action" value="toggle_denom">
            <input type="hidden" name="denom_id" value="<?= (int)$denom['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $is_active ? '0' : '1' ?>">
          </form>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="alert alert-info">No hay billetes configurados.</div>
      <?php endif; ?>
    </div>
  </div>
</main>

</body>
</html>
