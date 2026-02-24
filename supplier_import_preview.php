<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();

$runId = (int)get('run_id', '0');
if ($runId <= 0) {
  abort(400, 'Importación inválida.');
}

$stRun = db()->prepare('SELECT r.*, s.name AS supplier_name FROM supplier_import_runs r INNER JOIN suppliers s ON s.id = r.supplier_id WHERE r.id = ? LIMIT 1');
$stRun->execute([$runId]);
$run = $stRun->fetch();
if (!$run) {
  abort(404, 'Importación no encontrada.');
}

$summarySt = db()->prepare("SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN status = 'MATCHED' AND chosen_by_rule = 1 THEN 1 ELSE 0 END) AS matched,
  SUM(CASE WHEN status = 'UNMATCHED' THEN 1 ELSE 0 END) AS unmatched,
  SUM(CASE WHEN status = 'DUPLICATE_SKU' THEN 1 ELSE 0 END) AS duplicates,
  SUM(CASE WHEN status = 'INVALID' THEN 1 ELSE 0 END) AS invalid,
  COALESCE(SUM(CASE WHEN status = 'MATCHED' AND chosen_by_rule = 1 THEN (
    SELECT COUNT(*) FROM product_suppliers psx
    WHERE psx.supplier_id = ?
      AND psx.supplier_sku = supplier_import_rows.supplier_sku
      AND psx.is_active = 1
  ) ELSE 0 END), 0) AS updated_total
  FROM supplier_import_rows WHERE run_id = ?");
$summarySt->execute([(int)$run['supplier_id'], $runId]);
$summary = $summarySt->fetch() ?: ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicates' => 0, 'invalid' => 0, 'updated_total' => 0];

$matchedSt = db()->prepare("SELECT
  r.*,
  COUNT(ps.id) AS matched_products_count,
  GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR ', ') AS product_skus,
  GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS product_names
  FROM supplier_import_rows r
  LEFT JOIN product_suppliers ps ON ps.supplier_id = ? AND ps.supplier_sku = r.supplier_sku AND ps.is_active = 1
  LEFT JOIN products p ON p.id = ps.product_id
  WHERE r.run_id = ? AND r.status = 'MATCHED'
  GROUP BY r.id
  ORDER BY r.supplier_sku ASC, r.id ASC");
$matchedSt->execute([(int)$run['supplier_id'], $runId]);
$matchedRows = $matchedSt->fetchAll();

$duplicateSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ? AND status = 'DUPLICATE_SKU' ORDER BY supplier_sku ASC, id ASC");
$duplicateSt->execute([$runId]);
$duplicateRows = $duplicateSt->fetchAll();

$unmatchedSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ? AND status IN ('UNMATCHED','INVALID') ORDER BY supplier_sku ASC, id ASC");
$unmatchedSt->execute([$runId]);
$unmatchedRows = $unmatchedSt->fetchAll();
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
        <h2 class="page-title">Previsualización importación</h2>
        <span class="muted">Proveedor: <?= e((string)$run['supplier_name']) ?> | Fuente: <?= e((string)$run['source_type']) ?> | price_col=<?= e((string)($run['selected_price_column'] ?? '—')) ?> | dedupe=<?= e((string)($run['dedupe_mode'] ?? '—')) ?></span>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="suppliers_import.php?supplier_id=<?= (int)$run['supplier_id'] ?>">Volver</a>
      </div>
    </div>

    <div class="card">
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: var(--space-4);">
        <div><strong>Total</strong><br><?= (int)$summary['total'] ?></div>
        <div><strong>Matched</strong><br><?= (int)$summary['matched'] ?></div>
        <div><strong>Productos actualizados</strong><br><?= (int)$summary['updated_total'] ?></div>
        <div><strong>Unmatched</strong><br><?= (int)$summary['unmatched'] ?></div>
        <div><strong>Duplicados</strong><br><?= (int)$summary['duplicates'] ?></div>
        <div><strong>Invalid</strong><br><?= (int)$summary['invalid'] ?></div>
      </div>
      <div class="inline-actions" style="margin-top:var(--space-3);">
        <?php if ((int)$summary['matched'] > 0 && empty($run['applied_at'])): ?>
          <form method="post" action="supplier_import_apply.php">
            <input type="hidden" name="run_id" value="<?= (int)$run['id'] ?>">
            <button class="btn" type="submit">Aplicar importación</button>
          </form>
        <?php endif; ?>
        <?php if (!empty($run['applied_at'])): ?><span class="muted">Aplicada en <?= e((string)$run['applied_at']) ?></span><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Matcheados (OK)</h3></div>
      <div class="table-wrapper"><table class="table">
        <thead><tr><th>SKU proveedor</th><th>SKU interno</th><th>Producto</th><th>Match</th><th>Costo unitario</th><th>Costo raw</th><th>price_col</th><th>discount_applied</th><th>cost_calc</th><th>Regla</th></tr></thead>
        <tbody>
        <?php if (!$matchedRows): ?>
          <tr><td colspan="10">Sin filas.</td></tr>
        <?php else: foreach ($matchedRows as $row): ?>
          <tr>
            <td><?= e((string)$row['supplier_sku']) ?></td>
            <td><?= e((string)($row['product_skus'] ?? '—')) ?></td>
            <td><?= e((string)($row['product_names'] ?? '—')) ?></td>
            <td>
              <?php $matchCount = (int)($row['matched_products_count'] ?? 0); ?>
              <?php if ($matchCount > 1): ?>
                <?= e((string)$row['supplier_sku']) ?> -> <?= $matchCount ?> productos actualizados (<?= e((string)($row['product_skus'] ?? '')) ?>)
              <?php else: ?>
                <?= $matchCount ?> producto actualizado
              <?php endif; ?>
            </td>
            <td><?= $row['normalized_unit_cost'] !== null ? (int)round((float)$row['normalized_unit_cost'], 0) : '—' ?></td>
            <td><?= $row['raw_price'] !== null ? (int)round((float)$row['raw_price'], 0) : '—' ?></td>
            <td><?= e((string)($row['price_column_name'] ?? '')) ?></td>
            <td><?= $row['discount_applied_percent'] !== null ? e(number_format((float)$row['discount_applied_percent'], 2, '.', '')) . '%' : '—' ?></td>
            <td><?= e((string)($row['cost_calc_detail'] ?? '')) ?></td>
            <td><?= e((string)($row['reason'] ?? '')) ?><?= (int)$row['chosen_by_rule'] === 1 ? ' [elegida]' : '' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Duplicados</h3></div>
      <div class="table-wrapper"><table class="table">
        <thead><tr><th>SKU proveedor</th><th>Descripción</th><th>Costo unitario</th><th>Estado</th><th>Motivo</th></tr></thead>
        <tbody>
        <?php if (!$duplicateRows): ?>
          <tr><td colspan="5">Sin duplicados descartados.</td></tr>
        <?php else: foreach ($duplicateRows as $row): ?>
          <tr>
            <td><?= e((string)$row['supplier_sku']) ?></td>
            <td><?= e((string)($row['description'] ?? '')) ?></td>
            <td><?= $row['normalized_unit_cost'] !== null ? (int)round((float)$row['normalized_unit_cost'], 0) : '—' ?></td>
            <td><?= e((string)$row['status']) ?></td>
            <td><?= e((string)($row['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">No matcheados / inválidos</h3></div>
      <div class="table-wrapper"><table class="table">
        <thead><tr><th>SKU proveedor</th><th>Descripción</th><th>Precio</th><th>price_col</th><th>discount_applied</th><th>cost_calc</th><th>Estado</th><th>Motivo</th></tr></thead>
        <tbody>
        <?php if (!$unmatchedRows): ?>
          <tr><td colspan="8">Sin filas no vinculadas.</td></tr>
        <?php else: foreach ($unmatchedRows as $row): ?>
          <tr>
            <td><?= e((string)$row['supplier_sku']) ?></td>
            <td><?= e((string)($row['description'] ?? '')) ?></td>
            <td><?= $row['raw_price'] !== null ? (int)round((float)$row['raw_price'], 0) : '—' ?></td>
            <td><?= e((string)($row['price_column_name'] ?? '')) ?></td>
            <td><?= $row['discount_applied_percent'] !== null ? e(number_format((float)$row['discount_applied_percent'], 2, '.', '')) . '%' : '—' ?></td>
            <td><?= e((string)($row['cost_calc_detail'] ?? '')) ?></td>
            <td><?= e((string)$row['status']) ?></td>
            <td><?= e((string)($row['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</main>
</body>
</html>
