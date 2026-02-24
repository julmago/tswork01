<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();

$cashbox_id = (int)($_SESSION['cashbox_id'] ?? 0);
$cashbox_name = '—';
if ($cashbox_id > 0) {
  $name_st = db()->prepare("SELECT name FROM cashboxes WHERE id = ? LIMIT 1");
  $name_st->execute([$cashbox_id]);
  $cashbox_name = $name_st->fetchColumn() ?: '—';
}

$cashbox = null;
if ($cashbox_id > 0) {
  $cashbox = require_cashbox_selected();
  require_permission(hasPerm('cash.exits.edit'), 'Sin permiso para modificar salidas.');
}

$exit_id = (int)get('id');
$exit = null;
if ($cashbox && $exit_id > 0) {
  $st = db()->prepare("SELECT id, detail, amount, created_at, user_id
    FROM cash_movements
    WHERE id = ? AND cashbox_id = ? AND type = 'exit'
    LIMIT 1");
  $st->execute([$exit_id, (int)$cashbox['id']]);
  $exit = $st->fetch();
}

$users = db()->query("SELECT id, first_name, last_name, email FROM users ORDER BY first_name ASC, last_name ASC")->fetchAll();

$message = '';
$error = '';

if (is_post() && post('action') === 'save_exit') {
  if (!$cashbox) {
    $error = 'Seleccioná una caja activa para continuar.';
  } elseif (!$exit) {
    $error = 'Salida no encontrada.';
  }

  $detail = trim((string)post('detail'));
  $amount_raw = trim((string)post('amount'));
  $amount_raw = str_replace([' ', ','], ['', '.'], $amount_raw);
  $amount = (float)$amount_raw;
  $created_raw = trim((string)post('created_at'));
  $user_id = (int)post('user_id');

  if ($error !== '') {
    // keep error
  } elseif ($detail === '') {
    $error = 'El detalle es obligatorio.';
  } elseif ($amount <= 0) {
    $error = 'El monto debe ser mayor a 0.';
  } elseif ($created_raw === '') {
    $error = 'La fecha es obligatoria.';
  } elseif ($user_id <= 0) {
    $error = 'El responsable es obligatorio.';
  } else {
    $created_ts = strtotime($created_raw);
    if ($created_ts === false) {
      $error = 'La fecha ingresada no es válida.';
    } else {
      $user_check = db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
      $user_check->execute([$user_id]);
      if (!$user_check->fetchColumn()) {
        $error = 'Responsable inválido.';
      } else {
        $created_at = date('Y-m-d H:i:s', $created_ts);
        $update = db()->prepare("UPDATE cash_movements
          SET detail = ?, amount = ?, created_at = ?, user_id = ?
          WHERE id = ? AND cashbox_id = ? AND type = 'exit'");
        $update->execute([$detail, $amount, $created_at, $user_id, $exit_id, (int)$cashbox['id']]);
        $message = 'Salida actualizada correctamente.';
        $exit = array_merge($exit ?: [], [
          'detail' => $detail,
          'amount' => $amount,
          'created_at' => $created_at,
          'user_id' => $user_id,
        ]);
      }
    }
  }
}

$detail_value = (string)($exit['detail'] ?? '');
$amount_value = isset($exit['amount']) ? (float)$exit['amount'] : 0.0;
$created_value = (string)($exit['created_at'] ?? '');
$created_input = $created_value !== '' ? date('Y-m-d\TH:i', strtotime($created_value)) : '';
$user_value = (int)($exit['user_id'] ?? 0);
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
      <h2 class="page-title">Modificar salida</h2>
      <span class="muted">Caja activa: <?= e($cashbox_name) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$cashbox): ?>
      <div class="alert alert-warning">Seleccioná una caja activa para continuar.</div>
      <div class="form-actions">
        <a class="btn" href="<?= url_path('cash_select.php') ?>">Elegir caja</a>
      </div>
    <?php elseif (!$exit): ?>
      <div class="alert alert-warning">Salida no encontrada.</div>
    <?php else: ?>
      <div class="card" style="max-width: 640px;">
        <div class="card-header">
          <h3 class="card-title">Datos de la salida</h3>
        </div>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="save_exit">
          <div class="form-group">
            <label class="form-label">Detalle</label>
            <input class="form-control" type="text" name="detail" required maxlength="255" value="<?= e($detail_value) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Monto</label>
            <input class="form-control" type="number" name="amount" step="0.01" min="0" required value="<?= e(number_format($amount_value, 2, '.', '')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Fecha y hora</label>
            <input class="form-control" type="datetime-local" name="created_at" required value="<?= e($created_input) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Responsable</label>
            <select class="form-control" name="user_id" required>
              <option value="">Seleccionar</option>
              <?php foreach ($users as $user): ?>
                <?php
                $user_id = (int)($user['id'] ?? 0);
                $label = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
                if ($label === '') {
                  $label = (string)($user['email'] ?? 'Usuario');
                } else {
                  $label = $label . ' · ' . (string)($user['email'] ?? '');
                }
                ?>
                <option value="<?= $user_id ?>" <?= $user_id === $user_value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Guardar cambios</button>
            <a class="btn btn-ghost" href="<?= url_path('cash_exits_list.php') ?>">Volver</a>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</main>

</body>
</html>
