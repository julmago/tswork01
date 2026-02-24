<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_role(['admin', 'superadmin']);
ensure_product_suppliers_schema();

$pdo = db();
$id = (int)get('id', post('id', '0'));
if ($id <= 0) {
  abort(400, 'Proveedor inválido.');
}

$st = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$id]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
}

$error = '';
$message = '';
$summary = '';
$form = [
  'percent' => '',
  'note' => '',
];

if (is_post() && post('action') === 'apply_supplier_adjust') {
  $form['percent'] = trim((string)post('percent', ''));
  $form['note'] = trim((string)post('note', ''));

  if ($form['percent'] === '' || !is_numeric($form['percent'])) {
    $error = 'Porcentaje (%) inválido.';
  } else {
    $percent = round((float)$form['percent'], 2);
    if ($percent < -90 || $percent > 300) {
      $error = 'El porcentaje debe estar entre -90 y 300.';
    } elseif (mb_strlen($form['note']) > 255) {
      $error = 'La nota no puede superar 255 caracteres.';
    } else {
      $factor = 1 + ($percent / 100);
      $createdBy = (int)(current_user()['id'] ?? 0);
      $createdByValue = $createdBy > 0 ? $createdBy : null;

      try {
        $pdo->beginTransaction();

        $update = $pdo->prepare("UPDATE product_suppliers ps
          LEFT JOIN suppliers s ON s.id = ps.supplier_id
          LEFT JOIN products p ON p.id = ps.product_id
          SET ps.supplier_cost = ROUND(ps.supplier_cost * ?, 0),
              ps.cost_unitario = ROUND(
                ROUND(ps.supplier_cost * ?, 0)
                / CASE
                    WHEN ps.cost_type = 'PACK' THEN COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1)
                    ELSE 1
                  END,
                0
              ),
              ps.updated_at = NOW()
          WHERE ps.supplier_id = ?
            AND ps.supplier_cost IS NOT NULL");
        $update->execute([$factor, $factor, $id]);
        $affectedRows = (int)$update->rowCount();

        $ins = $pdo->prepare('INSERT INTO supplier_cost_adjustments(supplier_id, percent, note, affected_rows, created_by) VALUES(?, ?, ?, ?, ?)');
        $ins->execute([$id, $percent, $form['note'] !== '' ? $form['note'] : null, $affectedRows, $createdByValue]);

        $pdo->commit();

        $prefix = $percent >= 0 ? '+' : '';
        $summary = sprintf('Aplicado %s%s%% a %d productos del proveedor %s.', $prefix, number_format($percent, 2, '.', ''), $affectedRows, $supplier['name']);
        $message = 'Ajuste aplicado correctamente.';
        $form['percent'] = '';
        $form['note'] = '';
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $error = 'No se pudo aplicar el ajuste global.';
      }
    }
  }
}

$history = [];
$historySt = $pdo->prepare("SELECT sca.created_at, sca.percent, sca.affected_rows, sca.note,
    CONCAT(COALESCE(u.first_name, ''),
      CASE WHEN COALESCE(u.first_name, '') <> '' AND COALESCE(u.last_name, '') <> '' THEN ' ' ELSE '' END,
      COALESCE(u.last_name, '')
    ) AS user_full_name,
    u.email AS user_email
  FROM supplier_cost_adjustments sca
  LEFT JOIN users u ON u.id = sca.created_by
  WHERE sca.supplier_id = ?
  ORDER BY sca.id DESC
  LIMIT 20");
$historySt->execute([$id]);
$history = $historySt->fetchAll();
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
      <div>
        <h2 class="page-title">Ajuste global por proveedor</h2>
        <span class="muted">Proveedor: <?= e($supplier['name']) ?></span>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="suppliers.php">Volver</a>
      </div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($summary !== ''): ?><div class="alert alert-info"><?= e($summary) ?></div><?php endif; ?>

    <div class="card">
      <form method="post" class="stack" id="adjust-form">
        <input type="hidden" name="action" value="apply_supplier_adjust">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <label class="form-field">
          <span class="form-label">Proveedor</span>
          <input class="form-control" type="text" value="<?= e($supplier['name']) ?>" readonly>
        </label>

        <label class="form-field">
          <span class="form-label">Porcentaje (%)</span>
          <input class="form-control" type="number" name="percent" step="0.01" min="-90" max="300" value="<?= e($form['percent']) ?>" required>
        </label>

        <label class="form-field">
          <span class="form-label">Nota / Motivo (opcional)</span>
          <input class="form-control" type="text" name="note" maxlength="255" value="<?= e($form['note']) ?>" placeholder="Aumento lista Feb">
        </label>

        <p class="muted">Esto modifica DEFINITIVAMENTE el costo guardado de este proveedor en todos los productos vinculados. El cambio se aplica sobre el costo actual.</p>

        <div class="inline-actions">
          <button class="btn" type="submit">Aplicar ajuste</button>
          <a class="btn btn-ghost" href="suppliers.php">Volver</a>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Historial de ajustes</h3>
        <span class="muted small">Últimos 20</span>
      </div>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>%</th>
              <th>Afectados</th>
              <th>Nota</th>
              <th>Usuario</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$history): ?>
              <tr><td colspan="5">Sin ajustes registrados.</td></tr>
            <?php else: ?>
              <?php foreach ($history as $item): ?>
                <?php
                  $percent = (float)($item['percent'] ?? 0);
                  $prefix = $percent >= 0 ? '+' : '';
                  $userLabel = trim((string)($item['user_full_name'] ?? ''));
                  if ($userLabel === '') {
                    $userLabel = trim((string)($item['user_email'] ?? ''));
                  }
                  if ($userLabel === '') {
                    $userLabel = '—';
                  }
                ?>
                <tr>
                  <td><?= e((string)$item['created_at']) ?></td>
                  <td><?= e($prefix . number_format($percent, 2, '.', '') . '%') ?></td>
                  <td><?= (int)($item['affected_rows'] ?? 0) ?></td>
                  <td><?= $item['note'] !== null && $item['note'] !== '' ? e((string)$item['note']) : '—' ?></td>
                  <td><?= e($userLabel) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    const form = document.getElementById('adjust-form');
    if (!form) {
      return;
    }
    form.addEventListener('submit', function (event) {
      const percentField = form.querySelector('input[name="percent"]');
      const percent = Number(percentField ? percentField.value : '0');
      if (!Number.isFinite(percent)) {
        return;
      }
      const sign = percent >= 0 ? '+' : '';
      const supplier = <?= json_encode((string)$supplier['name'], JSON_UNESCAPED_UNICODE) ?>;
      const message = `Vas a aplicar ${sign}${percent.toFixed(2)}% al costo de TODOS los productos del proveedor ${supplier}. Esto no se puede deshacer automáticamente.`;
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  })();
</script>
</body>
</html>
