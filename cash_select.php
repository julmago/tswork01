<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
require_permission(hasAnyCashboxPerm('can_open_module'), 'Sin permiso para acceder a Caja.');

$active_cashbox_id = cashbox_selected_id();
$active_cashbox = null;

if ($active_cashbox_id > 0) {
  $active_cashbox = fetch_cashbox_by_id($active_cashbox_id, true);
  if (!$active_cashbox || !hasCashboxPerm('can_open_module', $active_cashbox_id)) {
    unset($_SESSION['cashbox_id']);
    $active_cashbox_id = 0;
  }
}

if ($active_cashbox_id <= 0) {
  redirect(url_path('cash_select_list.php'));
}

$active_cashbox_name = $active_cashbox ? $active_cashbox['name'] : 'Sin seleccionar';
$can_view_balance = $active_cashbox_id > 0 && hasCashboxPerm('can_view_balance', $active_cashbox_id);
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
      <h2 class="page-title">Caja</h2>
      <span class="muted">Caja activa: <?= e($active_cashbox_name) ?></span>
    </div>

    <div class="cash-actions">
      <a class="cash-action-card" href="<?= url_path('cash_entry.php') ?>">ENTRADA</a>
      <a class="cash-action-card" href="<?= url_path('cash_exit.php') ?>">SALIDA</a>
      <?php if ($can_view_balance): ?>
        <a class="cash-action-card" href="<?= url_path('cash_balance.php') ?>">CAJA</a>
      <?php endif; ?>
    </div>
  </div>
</main>

</body>
</html>
