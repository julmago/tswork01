<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
require_permission(hasAnyCashboxPerm('can_open_module'), 'Sin permiso para acceder a Caja.');

$active_cashbox_id = cashbox_selected_id();
if ($active_cashbox_id > 0) {
  $active_cashbox = fetch_cashbox_by_id($active_cashbox_id, true);
  if ($active_cashbox && hasCashboxPerm('can_open_module', $active_cashbox_id)) {
    redirect(url_path('cash_select.php'));
  }
  unset($_SESSION['cashbox_id']);
}

$cashboxes = fetch_accessible_cashboxes('can_open_module');
$redirect_target = url_path('cash_select.php');
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
      <h2 class="page-title">Elegir caja</h2>
      <span class="muted">Seleccioná la caja con la que vas a operar.</span>
    </div>

    <?php if ($cashboxes): ?>
      <div class="cash-actions">
        <?php foreach ($cashboxes as $cashbox): ?>
          <?php $cashbox_id = (int)$cashbox['id']; ?>
          <a class="cash-action-card" href="<?= url_path('cash_set.php?id=' . $cashbox_id . '&redirect=' . rawurlencode($redirect_target)) ?>">
            <?= e($cashbox['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-info">No hay cajas activas disponibles. Contactá a un administrador.</div>
    <?php endif; ?>
  </div>
</main>

</body>
</html>
