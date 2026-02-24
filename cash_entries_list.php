<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();

$can_view_entries_detail = hasPerm('cash.view_entries_detail');
require_permission($can_view_entries_detail, 'Sin permiso para ver el detalle de entradas.');

$cashbox_id = (int)($_SESSION['cashbox_id'] ?? 0);
$cashbox_name = '—';
if ($cashbox_id > 0) {
  $name_st = db()->prepare("SELECT name FROM cashboxes WHERE id = ? LIMIT 1");
  $name_st->execute([$cashbox_id]);
  $cashbox_name = $name_st->fetchColumn() ?: '—';
}

$cashbox = null;
$can_edit_entries = hasPerm('cash.entries.edit');
$can_delete_entries = hasPerm('cash.entries.delete');
$show_entry_actions = $can_edit_entries || $can_delete_entries;
if ($cashbox_id > 0) {
  $cashbox = require_cashbox_selected();
  require_permission(hasCashboxPerm('can_view_balance', (int)$cashbox['id']), 'Sin permiso para ver el balance.');
}

$message = '';
$error = '';

if (is_post() && post('action') === 'delete_entry') {
  require_permission($can_delete_entries, 'Sin permiso para eliminar entradas.');
  if (!$cashbox) {
    $error = 'Seleccioná una caja activa para continuar.';
  } else {
    $entry_id = (int)post('id');
    if ($entry_id <= 0) {
      $error = 'Entrada inválida.';
    } else {
      $check = db()->prepare("SELECT id FROM cash_movements WHERE id = ? AND cashbox_id = ? AND type = 'entry' LIMIT 1");
      $check->execute([$entry_id, (int)$cashbox['id']]);
      if (!$check->fetchColumn()) {
        $error = 'Entrada no encontrada.';
      } else {
        $delete = db()->prepare("DELETE FROM cash_movements WHERE id = ? AND cashbox_id = ? AND type = 'entry'");
        $delete->execute([$entry_id, (int)$cashbox['id']]);
        $message = 'Entrada eliminada.';
      }
    }
  }
}

$entries = [];
if ($cashbox) {
  $st = db()->prepare(
    "SELECT cm.id, cm.detail, cm.amount, cm.created_at,
            u.first_name, u.last_name, u.email
     FROM cash_movements cm
     JOIN users u ON u.id = cm.user_id
     WHERE cm.cashbox_id = ? AND cm.type = 'entry'
     ORDER BY cm.created_at DESC, cm.id DESC"
  );
  $st->execute([(int)$cashbox['id']]);
  $entries = $st->fetchAll();
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
      <h2 class="page-title">Detalle de entradas</h2>
      <span class="muted">Caja activa: <?= e($cashbox_name) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$cashbox): ?>
      <div class="alert alert-warning">Seleccioná una caja activa para ver las entradas.</div>
      <div class="form-actions">
        <a class="btn" href="<?= url_path('cash_select.php') ?>">Elegir caja</a>
      </div>
    <?php elseif ($entries): ?>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Responsable</th>
              <th>Detalle</th>
              <th>Monto</th>
              <?php if ($show_entry_actions): ?>
                <th>Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $row): ?>
              <?php
              $entry_id = (int)($row['id'] ?? 0);
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
                <?php if ($show_entry_actions): ?>
                  <td class="table-actions">
                    <?php if ($can_edit_entries): ?>
                      <a class="btn btn-ghost" href="<?= url_path('cash_entry_edit.php?id=' . $entry_id) ?>">Modificar</a>
                    <?php endif; ?>
                    <?php if ($can_delete_entries): ?>
                      <form method="post" style="display: inline-flex; gap: 0.5rem;">
                        <input type="hidden" name="action" value="delete_entry">
                        <input type="hidden" name="id" value="<?= $entry_id ?>">
                        <button class="btn btn-ghost" type="submit" onclick="return confirm('¿Eliminar esta entrada?')">Eliminar</button>
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
      <div class="alert alert-info">No hay entradas registradas para esta caja.</div>
    <?php endif; ?>

    <div class="form-actions" style="margin-top: var(--space-4);">
      <a class="btn btn-ghost" href="<?= url_path('cash_balance.php') ?>">Volver</a>
    </div>
  </div>
</main>

</body>
</html>
