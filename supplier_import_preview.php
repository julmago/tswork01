<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();

try {
$runId = (int)($_GET['run_id'] ?? 0);
if ($runId <= 0) {
  throw new Exception('run_id inválido');
}

$runSt = db()->prepare('SELECT * FROM supplier_import_runs WHERE id = ?');
$runSt->execute([$runId]);
$run = $runSt->fetch();
if (!$run) {
  throw new Exception('No existe supplier_import_runs.id=' . $runId);
}

$cols = db()->query('SHOW COLUMNS FROM supplier_import_rows')->fetchAll(PDO::FETCH_COLUMN, 0);
$hasStatus = in_array('status', $cols, true);
$hasChosen = in_array('chosen_by_rule', $cols, true);
$hasDesc = in_array('description', $cols, true);
$hasRaw = in_array('raw_price', $cols, true);
$hasNorm = in_array('normalized_unit_cost', $cols, true);
$hasPriceCol = in_array('price_column_name', $cols, true);
$hasDisc = in_array('discount_applied_percent', $cols, true);
$hasCalc = in_array('cost_calc_detail', $cols, true);
$hasReason = in_array('reason', $cols, true);
$hasSupSku = in_array('supplier_sku', $cols, true) || in_array('supplier_code', $cols, true);
$hasIsValid = in_array('is_valid', $cols, true);
$hasProductId = in_array('product_id', $cols, true);
$hasMatchedProductId = in_array('matched_product_id', $cols, true);
$supplierSkuColumn = in_array('supplier_sku', $cols, true) ? 'supplier_sku' : (in_array('supplier_code', $cols, true) ? 'supplier_code' : null);

$supplierSt = db()->prepare('SELECT name FROM suppliers WHERE id = ? LIMIT 1');
$supplierSt->execute([(int)$run['supplier_id']]);
$supplierName = (string)($supplierSt->fetchColumn() ?: '—');
$run['supplier_name'] = $supplierName;

$supplierId = (int)$run['supplier_id'];
$chosenExpr = $hasChosen ? ' AND chosen_by_rule = 1' : '';
$matchExpr = $hasStatus ? "status = 'MATCHED'{$chosenExpr}" : ($hasIsValid ? 'is_valid = 1' : '1=1');

$unmatchedExpr = $hasStatus
  ? "status = 'UNMATCHED'"
  : ($hasIsValid ? 'is_valid = 0' : '0=1');
$duplicatesExpr = $hasStatus ? "status = 'DUPLICATE_SKU'" : '0=1';
$invalidExpr = $hasStatus
  ? "status = 'INVALID'"
  : ($hasIsValid ? 'is_valid = 0' : '0=1');

$updatedExpr = '0 AS updated_total';
if ($supplierSkuColumn !== null) {
  $updatedExpr = "COALESCE(SUM(CASE WHEN {$matchExpr} THEN (
    SELECT COUNT(*) FROM product_suppliers psx
    WHERE psx.supplier_id = ?
      AND psx.supplier_sku = supplier_import_rows.$supplierSkuColumn
      AND psx.is_active = 1
  ) ELSE 0 END), 0) AS updated_total";
}

$summarySql = "SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN {$matchExpr} THEN 1 ELSE 0 END) AS matched,
  SUM(CASE WHEN {$unmatchedExpr} THEN 1 ELSE 0 END) AS unmatched,
  SUM(CASE WHEN {$duplicatesExpr} THEN 1 ELSE 0 END) AS duplicates,
  SUM(CASE WHEN {$invalidExpr} THEN 1 ELSE 0 END) AS invalid,
  {$updatedExpr}
  FROM supplier_import_rows WHERE run_id = ?";
$summarySt = db()->prepare($summarySql);
if ($supplierSkuColumn !== null) {
  $summarySt->execute([$supplierId, $runId]);
} else {
  $summarySt->execute([$runId]);
}
$summary = $summarySt->fetch() ?: ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicates' => 0, 'invalid' => 0, 'updated_total' => 0];

$matchedSt = db()->prepare("SELECT
  r.*,
  COUNT(ps.id) AS matched_products_count,
  GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR ', ') AS product_skus,
  GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS product_names
  FROM supplier_import_rows r
  LEFT JOIN product_suppliers ps ON ps.supplier_id = ?" . ($supplierSkuColumn !== null ? " AND ps.supplier_sku = r.$supplierSkuColumn" : '') . " AND ps.is_active = 1
  LEFT JOIN products p ON p.id = ps.product_id
  WHERE r.run_id = ?" . ($hasStatus ? " AND r.status = 'MATCHED'" : '') . ($hasChosen ? ' AND r.chosen_by_rule = 1' : '') . "
  GROUP BY r.id
  ORDER BY " . ($supplierSkuColumn !== null ? "r.$supplierSkuColumn ASC, " : '') . "r.id ASC");
$matchedSt->execute([$supplierId, $runId]);
$matchedRows = $matchedSt->fetchAll();

$duplicateSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ?" . ($hasStatus ? " AND status = 'DUPLICATE_SKU'" : ' AND 0=1') . " ORDER BY " . ($supplierSkuColumn !== null ? "$supplierSkuColumn ASC, " : '') . "id ASC");
$duplicateSt->execute([$runId]);
$duplicateRows = $duplicateSt->fetchAll();

$unmatchedSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ?" . ($hasStatus ? " AND status IN ('UNMATCHED','INVALID')" : ($hasIsValid ? ' AND is_valid = 0' : ' AND 0=1')) . " ORDER BY " . ($supplierSkuColumn !== null ? "$supplierSkuColumn ASC, " : '') . "id ASC");
$unmatchedSt->execute([$runId]);
$unmatchedRows = $unmatchedSt->fetchAll();

$psCols = db()->query('SHOW COLUMNS FROM product_suppliers')->fetchAll(PDO::FETCH_COLUMN, 0);
$costCol = null;
foreach (['cost_received', 'supplier_cost', 'cost_provider', 'cost_proveedor', 'cost'] as $candidateCol) {
  if (in_array($candidateCol, $psCols, true)) {
    $costCol = $candidateCol;
    break;
  }
}
$costSelect = $costCol !== null ? "ps.$costCol AS cost," : '';
$unsyncedSql = "SELECT
  p.id,
  p.sku,
  p.name,
  ps.supplier_sku,
  $costSelect
  ps.is_active
  FROM product_suppliers ps
  INNER JOIN products p ON p.id = ps.product_id
  WHERE ps.supplier_id = ?
    AND COALESCE(ps.supplier_sku, '') <> ''
    AND NOT EXISTS (
      SELECT 1
      FROM supplier_import_rows r
      WHERE r.run_id = ?
        AND r.supplier_sku = ps.supplier_sku
    )
  ORDER BY p.name ASC
  LIMIT 500";

$unsyncedSt = db()->prepare($unsyncedSql);
$unsyncedSt->execute([$supplierId, $runId]);
$unsyncedRows = $unsyncedSt->fetchAll();
} catch (Throwable $e) {
  error_log("supplier_import_preview ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());

  if (defined('DEBUG') && DEBUG) {
    die('<pre>' . $e->getMessage() . "\n\n" . $e->getTraceAsString() . '</pre>');
  }

  http_response_code(500);
  die('Ocurrió un error interno.');
}
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
            <td><?= e((string)($row['supplier_sku'] ?? $row['supplier_code'] ?? '—')) ?></td>
            <td><?= e((string)($row['product_skus'] ?? '—')) ?></td>
            <td><?= e((string)($row['product_names'] ?? '—')) ?></td>
            <td>
              <?php $matchCount = (int)($row['matched_products_count'] ?? 0); ?>
              <?php if ($matchCount > 1): ?>
                <?= e((string)($row['supplier_sku'] ?? $row['supplier_code'] ?? '—')) ?> -> <?= $matchCount ?> productos actualizados (<?= e((string)($row['product_skus'] ?? '')) ?>)
              <?php else: ?>
                <?= $matchCount ?> producto actualizado
              <?php endif; ?>
            </td>
            <td><?= ($row['normalized_unit_cost'] ?? null) !== null ? (int)round((float)$row['normalized_unit_cost'], 0) : '—' ?></td>
            <td><?= ($row['raw_price'] ?? null) !== null ? (int)round((float)$row['raw_price'], 0) : '—' ?></td>
            <td><?= e((string)($row['price_column_name'] ?? '—')) ?></td>
            <td><?= ($row['discount_applied_percent'] ?? null) !== null ? e(number_format((float)$row['discount_applied_percent'], 2, '.', '')) . '%' : '—' ?></td>
            <td><?= e((string)($row['cost_calc_detail'] ?? '—')) ?></td>
            <td><?= e((string)($row['reason'] ?? '—')) ?><?= (int)($row['chosen_by_rule'] ?? 0) === 1 ? ' [elegida]' : '' ?></td>
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
            <td><?= e((string)($row['supplier_sku'] ?? $row['supplier_code'] ?? '—')) ?></td>
            <td><?= e((string)($row['description'] ?? '—')) ?></td>
            <td><?= ($row['normalized_unit_cost'] ?? null) !== null ? (int)round((float)$row['normalized_unit_cost'], 0) : '—' ?></td>
            <td><?= e((string)($row['status'] ?? '—')) ?></td>
            <td><?= e((string)($row['reason'] ?? '—')) ?></td>
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
            <td><?= e((string)($row['supplier_sku'] ?? $row['supplier_code'] ?? '—')) ?></td>
            <td><?= e((string)($row['description'] ?? '—')) ?></td>
            <td><?= ($row['raw_price'] ?? null) !== null ? (int)round((float)$row['raw_price'], 0) : '—' ?></td>
            <td><?= e((string)($row['price_column_name'] ?? '—')) ?></td>
            <td><?= ($row['discount_applied_percent'] ?? null) !== null ? e(number_format((float)$row['discount_applied_percent'], 2, '.', '')) . '%' : '—' ?></td>
            <td><?= e((string)($row['cost_calc_detail'] ?? '—')) ?></td>
            <td><?= e((string)($row['status'] ?? '—')) ?></td>
            <td><?= e((string)($row['reason'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Faltantes en el archivo (existen en TSWork, pero no vinieron en este CSV)</h3></div>
      <p class="muted">Productos vinculados a este proveedor en TSWork cuyo SKU proveedor no aparece en el archivo de esta corrida.</p>
      <div class="table-wrapper"><table class="table">
        <thead><tr><th>SKU</th><th>Nombre</th><th>SKU Proveedor</th><th>Costo</th><th>Activo</th><th>Ver</th></tr></thead>
        <tbody>
        <?php if (!$unsyncedRows): ?>
          <tr><td colspan="6">No hay faltantes. ✅ (Todo lo vinculado en TSWork apareció en el archivo)</td></tr>
        <?php else: foreach ($unsyncedRows as $row): ?>
          <tr>
            <td><?= e((string)$row['sku']) ?></td>
            <td><?= e((string)$row['name']) ?></td>
            <td><?= e((string)($row['supplier_sku'] ?? '—')) ?></td>
            <td><?= e(isset($row['cost']) ? (string)$row['cost'] : '—') ?></td>
            <td><?= (int)$row['is_active'] === 1 ? 'Sí' : 'No' ?></td>
            <td><a href="product_view.php?id=<?= (int)$row['id'] ?>">Ver</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</main>
</body>
</html>
