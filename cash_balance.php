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
  require_permission(hasCashboxPerm('can_view_balance', (int)$cashbox['id']), 'Sin permiso para ver el balance.');
}
$can_view_entries_detail = hasPerm('cash.view_entries_detail');
$can_view_exits_detail = hasPerm('cash.view_exits_detail');

$total_entries = 0.0;
$total_exits = 0.0;
if ($cashbox) {
  $st = db()->prepare("SELECT
    SUM(CASE WHEN type = 'entry' THEN amount ELSE 0 END) AS total_entries,
    SUM(CASE WHEN type = 'exit' THEN amount ELSE 0 END) AS total_exits
    FROM cash_movements
    WHERE cashbox_id = ?");
  $st->execute([(int)$cashbox['id']]);
  $totals = $st->fetch();
  $total_entries = (float)($totals['total_entries'] ?? 0);
  $total_exits = (float)($totals['total_exits'] ?? 0);
}
$balance = $total_entries - $total_exits;
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
      <h2 class="page-title">Balance de caja</h2>
      <span class="muted">Caja activa: <?= e($cashbox_name) ?></span>
    </div>

    <?php if (!$cashbox): ?>
      <div class="alert alert-warning">Seleccioná una caja activa para ver el balance.</div>
    <?php endif; ?>

    <div class="grid grid-3">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Total Entradas</h3>
        </div>
        <strong style="font-size: 1.4rem;">$<?= number_format($total_entries, 2, ',', '.') ?></strong>
        <?php if ($cashbox && $can_view_entries_detail): ?>
          <div class="form-actions" style="margin-top: var(--space-3);">
            <a class="btn btn-ghost" href="<?= url_path('cash_entries_list.php') ?>">Ver detalle</a>
          </div>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Total Salidas</h3>
        </div>
        <strong style="font-size: 1.4rem;">$<?= number_format($total_exits, 2, ',', '.') ?></strong>
        <?php if ($cashbox && $can_view_exits_detail): ?>
          <div class="form-actions" style="margin-top: var(--space-3);">
            <a class="btn btn-ghost" href="<?= url_path('cash_exits_list.php') ?>">Ver detalle</a>
          </div>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Balance</h3>
        </div>
        <strong style="font-size: 1.4rem;">$<?= number_format($balance, 2, ',', '.') ?></strong>
      </div>
    </div>

    <div class="form-actions" style="margin-top: var(--space-4);">
      <a class="btn btn-ghost" href="<?= url_path('cash_select.php') ?>">Volver</a>
    </div>
  </div>
</main>

</body>
</html>
