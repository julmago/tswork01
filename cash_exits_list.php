<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();

$can_view_exits_detail = hasPerm('cash.view_exits_detail');
require_permission($can_view_exits_detail, 'Sin permiso para ver el detalle de salidas.');

$cashbox_id = (int)($_SESSION['cashbox_id'] ?? 0);
$cashbox_name = '—';
if ($cashbox_id > 0) {
  $name_st = db()->prepare("SELECT name FROM cashboxes WHERE id = ? LIMIT 1");
  $name_st->execute([$cashbox_id]);
  $cashbox_name = $name_st->fetchColumn() ?: '—';
}

$cashbox = null;
$can_edit_exits = hasPerm('cash.exits.edit');
$can_delete_exits = hasPerm('cash.exits.delete');
$show_exit_actions = $can_edit_exits || $can_delete_exits;
if ($cashbox_id > 0) {
  $cashbox = require_cashbox_selected();
  require_permission(hasCashboxPerm('can_view_balance', (int)$cashbox['id']), 'Sin permiso para ver el balance.');
}

$message = '';
$error = '';

if (is_post() && post('action') === 'delete_exit') {
  require_permission($can_delete_exits, 'Sin permiso para eliminar salidas.');
  if (!$cashbox) {
    $error = 'Seleccioná una caja activa para continuar.';
  } else {
    $exit_id = (int)post('id');
    if ($exit_id <= 0) {
      $error = 'Salida inválida.';
    } else {
      $check = db()->prepare("SELECT id FROM cash_movements WHERE id = ? AND cashbox_id = ? AND type = 'exit' LIMIT 1");
      $check->execute([$exit_id, (int)$cashbox['id']]);
      if (!$check->fetchColumn()) {
        $error = 'Salida no encontrada.';
      } else {
        $delete = db()->prepare("DELETE FROM cash_movements WHERE id = ? AND cashbox_id = ? AND type = 'exit'");
        $delete->execute([$exit_id, (int)$cashbox['id']]);
        $message = 'Salida eliminada.';
      }
    }
  }
}

$exits = [];
if ($cashbox) {
  $st = db()->prepare(
    "SELECT cm.id, cm.detail, cm.amount, cm.created_at,
            u.first_name, u.last_name, u.email
     FROM cash_movements cm
     JOIN users u ON u.id = cm.user_id
     WHERE cm.cashbox_id = ? AND cm.type = 'exit'
     ORDER BY cm.created_at DESC, cm.id DESC"
  );
  $st->execute([(int)$cashbox['id']]);
  $exits = $st->fetchAll();
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
      <h2 class="page-title">Detalle de salidas</h2>
      <span class="muted">Caja activa: <?= e($cashbox_name) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$cashbox): ?>
      <div class="alert alert-warning">Seleccioná una caja activa para ver las salidas.</div>
      <div class="form-actions">
        <a class="btn" href="<?= url_path('cash_select.php') ?>">Elegir caja</a>
      </div>
    <?php elseif ($exits): ?>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Responsable</th>
              <th>Detalle</th>
              <th>Monto</th>
              <?php if ($show_exit_actions): ?>
                <th>Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($exits as $row): ?>
              <?php
              $exit_id = (int)($row['id'] ?? 0);
              $detail = (string)($row['detail'] ?? '');
              $amount = (float)($row['amount'] ?? 0);
              $created_at = (string)($row['created_at'] ?? '');
              $date_label = $created_at !== '' ? date('d/m/Y H:i', strtotime($created_at)) : '—';
              $responsible = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
              if ($responsible === '') {
                $responsible = (string)($row['email'] ?? '—');
              }
              ?>
              <tr>
                <td><?= e($date_label) ?></td>
                <td><?= e($responsible) ?></td>
                <td><?= e($detail) ?></td>
                <td>$<?= number_format($amount, 2, ',', '.') ?></td>
                <?php if ($show_exit_actions): ?>
                  <td class="table-actions">
                    <?php if ($can_edit_exits): ?>
                      <a class="btn btn-ghost" href="<?= url_path('cash_exit_edit.php?id=' . $exit_id) ?>">Modificar</a>
                    <?php endif; ?>
                    <?php if ($can_delete_exits): ?>
                      <form method="post" style="display: inline-flex; gap: 0.5rem;">
                        <input type="hidden" name="action" value="delete_exit">
                        <input type="hidden" name="id" value="<?= $exit_id ?>">
                        <button class="btn btn-ghost" type="submit" onclick="return confirm('¿Eliminar esta salida?')">Eliminar</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="alert alert-info">No hay salidas registradas para esta caja.</div>
    <?php endif; ?>

    <div class="form-actions" style="margin-top: var(--space-4);">
      <a class="btn btn-ghost" href="<?= url_path('cash_balance.php') ?>">Volver</a>
    </div>
  </div>
</main>

</body>
</html>
