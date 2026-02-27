<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';

require_login();
require_permission(hasAnyCashboxPerm('can_manage_cashboxes'), 'Sin permiso para administrar cajas.');

$message = '';
$error = '';
$default_denoms = [100, 500, 1000, 2000, 10000, 20000];
$user = current_user();
$is_superadmin = (($user['role'] ?? '') === 'superadmin');

if (is_post()) {
  if (!csrf_is_valid((string)post('csrf_token'))) {
    abort(403, 'Token inválido.');
  }

  $action = (string)post('action');

  if ($action === 'create_cashbox') {
    if (!hasAnyCashboxPerm('can_manage_cashboxes')) {
      http_response_code(403);
      $error = 'No autorizado para crear cajas.';
    }
    $name = trim((string)post('name'));
    if ($error !== '') {
      // Error already set.
    } elseif ($name === '') {
      $error = 'El nombre de la caja es obligatorio.';
    } elseif (mb_strlen($name) > 120) {
      $error = 'El nombre debe tener hasta 120 caracteres.';
    } else {
      try {
        db()->beginTransaction();
        $st = db()->prepare("INSERT INTO cashboxes (name, is_active, created_by_user_id) VALUES (?, 1, ?)");
        $st->execute([$name, (int)$user['id']]);
        $cashbox_id = (int)db()->lastInsertId();

        $denom_st = db()->prepare("INSERT INTO cash_denominations (cashbox_id, value, is_active, sort_order) VALUES (?, ?, 1, ?)");
        $sort = 10;
        foreach ($default_denoms as $value) {
          $denom_st->execute([$cashbox_id, $value, $sort]);
          $sort += 10;
        }
        db()->commit();
        $_SESSION['cashbox_id'] = $cashbox_id;
        $message = 'Caja creada correctamente.';
      } catch (Throwable $t) {
        if (db()->inTransaction()) {
          db()->rollBack();
        }
        $error = 'No se pudo crear la caja.';
      }
    }
  }

  if ($action === 'toggle') {
    $cashbox_id = (int)post('id');
    if (!hasCashboxPerm('can_manage_cashboxes', $cashbox_id)) {
      http_response_code(403);
      $error = 'No autorizado para actualizar esta caja.';
    } else {
    try {
      $st = db()->prepare("UPDATE cashboxes SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
      $st->execute([$cashbox_id]);
      $message = 'Estado de la caja actualizado.';
    } catch (Throwable $t) {
      $error = 'No se pudo actualizar el estado de la caja.';
    }
    }
  }

  if ($action === 'delete') {
    $cashbox_id = (int)post('id');
    if (!$is_superadmin) {
      http_response_code(403);
      $error = 'No autorizado para eliminar cajas.';
    } else {
      try {
        $st = db()->prepare("DELETE FROM cashboxes WHERE id = ?");
        $st->execute([$cashbox_id]);
        if (cashbox_selected_id() === $cashbox_id) {
          unset($_SESSION['cashbox_id']);
        }
        $message = 'Caja eliminada.';
      } catch (Throwable $t) {
        $error = 'No se pudo eliminar la caja.';
      }
    }
  }
}

$list_sql = "SELECT id, name, is_active, created_by_user_id, created_at FROM cashboxes ORDER BY id DESC";
$list_st = db()->query($list_sql);
$cashboxes = $list_st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="<?= e(app_body_class()) ?>">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Administrar cajas</h2>
      <span class="muted">Creá o eliminá cajas.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Nueva caja</h3>
      </div>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_cashbox">
        <div class="form-row">
          <div class="form-group" style="min-width: 260px;">
            <label class="form-label">Nombre</label>
            <input class="form-control" type="text" name="name" maxlength="120" required>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Crear caja</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Cajas existentes</h3>
      </div>
      <?php if ($cashboxes): ?>
        <table class="cash-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nombre</th>
              <th>Estado</th>
              <th>Creada por (ID)</th>
              <th>Creada</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cashboxes as $row): ?>
              <?php
              $row = is_array($row) ? $row : [];
              $cashbox_id = (int)($row['id'] ?? 0);
              $cashbox_name = $row['name'] ?? '';
              $is_active = isset($row['is_active']) ? (int)$row['is_active'] : null;
              $created_by_user_id = $row['created_by_user_id'] ?? '';
              $created_at = $row['created_at'] ?? '';
              $can_manage_cashbox = hasCashboxPerm('can_manage_cashboxes', $cashbox_id);
              $can_toggle = $can_manage_cashbox && $is_active !== null && $cashbox_id > 0;
              $toggle_label = $is_active === 1 ? 'Pausar' : 'Activar';
              $status_label = $is_active === null ? '—' : (string)$is_active;
              ?>
              <tr>
                <td><?= e((string)$cashbox_id) ?></td>
                <td><?= e($cashbox_name) ?></td>
                <td><?= e($status_label) ?></td>
                <td><?= e((string)$created_by_user_id) ?></td>
                <td><?= e((string)$created_at) ?></td>
                <td>
                  <form method="post" style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $cashbox_id ?>">
                    <input type="hidden" name="action" value="toggle">
                    <button class="btn btn-ghost" type="submit" <?= $can_toggle ? '' : 'disabled' ?>><?= $toggle_label ?></button>
                  </form>
                  <?php if ($is_superadmin): ?>
                    <form method="post" style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= $cashbox_id ?>">
                      <input type="hidden" name="action" value="delete">
                      <button class="btn btn-ghost" type="submit" onclick="return confirm('¿Eliminar esta caja?')">Eliminar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="alert alert-info">No hay cajas registradas.</div>
      <?php endif; ?>
    </div>
  </div>
</main>

</body>
</html>
