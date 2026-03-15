<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_import_lib.php';
require_login();
ensure_product_suppliers_schema();

$pdo = db();
$id = (int)get('id', post('id', '0'));
if ($id <= 0) {
  abort(400, 'Proveedor inválido.');
}

$st = $pdo->prepare('SELECT id, name, base_margin_percent, import_dedupe_mode, import_default_cost_type, import_default_units_per_pack, import_discount_default FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$id]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
}

$dedupeModeLabels = [
  'LAST' => 'Ultimo precio',
  'FIRST' => 'Primer precio',
  'MIN' => 'Precio mas bajo',
  'MAX' => 'Precio mas alto',
  'PREFER_PROMO' => 'Precio promo',
];

$error = '';
$psBulkError = '';
$psBulkSites = [];
if (can_suppliers_ps_bulk()) {
  $psSitesSt = $pdo->query("SELECT s.id, s.name, sc.ps_base_url, sc.ps_api_key
    FROM sites s
    INNER JOIN site_connections sc ON sc.site_id = s.id
    WHERE s.is_active = 1
      AND s.conn_enabled = 1
      AND LOWER(s.conn_type) = 'prestashop'
    ORDER BY s.name ASC");
  $psBulkSites = $psSitesSt->fetchAll();
}
$form = [
  'name' => (string)$supplier['name'],
  'base_margin_percent' => number_format((float)$supplier['base_margin_percent'], 2, '.', ''),
  'import_discount_default' => $supplier['import_discount_default'] !== null ? number_format((float)$supplier['import_discount_default'], 2, '.', '') : '0',
  'import_dedupe_mode' => (string)$supplier['import_dedupe_mode'],
  'import_default_cost_type' => (string)$supplier['import_default_cost_type'],
  'import_default_units_per_pack' => $supplier['import_default_units_per_pack'] !== null ? (string)$supplier['import_default_units_per_pack'] : '',
];



if (is_post() && post('action') === 'ps_bulk_step1') {
  if (!can_suppliers_ps_bulk()) {
    abort403();
  }

  $siteId = (int)post('site_id', '0');
  $selectedSite = null;
  foreach ($psBulkSites as $siteRow) {
    if ((int)$siteRow['id'] === $siteId) {
      $selectedSite = $siteRow;
      break;
    }
  }

  if ($selectedSite === null) {
    $psBulkError = 'Seleccioná un sitio PrestaShop válido.';
  } elseif (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
    $psBulkError = 'Subí un archivo CSV.';
  } else {
    $file = $_FILES['csv_file'];
    $tmpName = (string)($file['tmp_name'] ?? '');
    $origName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $uploadErr = (int)($file['error'] ?? UPLOAD_ERR_OK);

    if ($uploadErr !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
      $psBulkError = 'No se pudo procesar el archivo CSV.';
    } elseif ($ext !== 'csv') {
      $psBulkError = 'El archivo debe ser .csv';
    } else {
      $tmpDir = __DIR__ . '/uploads/tmp';
      if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        $psBulkError = 'No se pudo crear el directorio temporal.';
      } else {
        $safeToken = bin2hex(random_bytes(12));
        $targetPath = $tmpDir . '/ps_bulk_' . $id . '_' . $safeToken . '.csv';
        if (!move_uploaded_file($tmpName, $targetPath)) {
          $psBulkError = 'No se pudo guardar el CSV temporal.';
        } else {
          $_SESSION['ps_bulk_supplier_id'] = $id;
          $_SESSION['ps_bulk_site_id'] = $siteId;
          $_SESSION['ps_bulk_csv_path'] = $targetPath;
          redirect('suppliers_ps_bulk.php?step=2');
        }
      }
    }
  }
}

if (is_post() && post('action') === 'update_supplier') {
  $form['name'] = trim(post('name'));
  $form['base_margin_percent'] = trim((string)post('base_margin_percent', '0'));
  $form['import_discount_default'] = trim((string)post('import_discount_default', '0'));
  $form['import_dedupe_mode'] = strtoupper(trim((string)post('import_dedupe_mode', 'LAST')));
  $form['import_default_cost_type'] = strtoupper(trim((string)post('import_default_cost_type', 'UNIDAD')));
  $form['import_default_units_per_pack'] = trim((string)post('import_default_units_per_pack', ''));

  $name = $form['name'];
  $margin = normalize_margin_percent_value($form['base_margin_percent']);
  $dedupeMode = $form['import_dedupe_mode'];
  $defaultCostType = $form['import_default_cost_type'];
  $defaultDiscount = supplier_import_normalize_discount($form['import_discount_default']);

  if (!in_array($dedupeMode, ['LAST', 'FIRST', 'MIN', 'MAX', 'PREFER_PROMO'], true)) {
    $dedupeMode = 'LAST';
    $form['import_dedupe_mode'] = 'LAST';
  }
  if (!in_array($defaultCostType, ['UNIDAD', 'PACK'], true)) {
    $defaultCostType = 'UNIDAD';
    $form['import_default_cost_type'] = 'UNIDAD';
  }

  $defaultUnits = null;
  if ($defaultCostType === 'PACK') {
    if ($form['import_default_units_per_pack'] === '') {
      $error = 'Completá Unidades para costo tipo PACK.';
    } else {
      $defaultUnits = (int)$form['import_default_units_per_pack'];
      if ($defaultUnits <= 0) {
        $error = 'Unidades inválidas.';
      }
    }
  }

  if ($name === '') {
    $error = 'Ingresá el nombre del proveedor.';
  } elseif ($margin === null) {
    $error = 'Base (%) inválida. Usá un valor entre 0 y 999.99.';
  } elseif ($defaultDiscount === null) {
    $error = 'Descuento (%) inválido. Usá un valor entre -100 y 100.';
  }

  if ($error === '') {
    try {
      $st = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
      $st->execute([$name, $id]);
      if ($st->fetch()) {
        $error = 'Ese proveedor ya existe.';
      } else {
        $st = $pdo->prepare('UPDATE suppliers SET name = ?, default_margin_percent = ?, base_margin_percent = ?, import_dedupe_mode = ?, import_default_cost_type = ?, import_default_units_per_pack = ?, import_discount_default = ?, updated_at = NOW() WHERE id = ?');
        $st->execute([$name, $margin, $margin, $dedupeMode, $defaultCostType, $defaultUnits, $defaultDiscount, $id]);
        redirect('suppliers.php?updated=1');
      }
    } catch (Throwable $t) {
      $error = 'No se pudo guardar el proveedor.';
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
  <style>
    .supplier-form-grid { display:grid; gap: var(--space-4); grid-template-columns:1fr; }
    .supplier-inline-grid { display:grid; gap: var(--space-3); grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .supplier-cost-grid { display:grid; gap: var(--space-3); grid-template-columns:1fr; align-items:end; }
    @media (min-width: 1100px) {
      .supplier-form-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      .supplier-cost-grid.is-pack { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
  </style>
</head>
<body class="<?= e(app_body_class()) ?>">
<?php require __DIR__ . '/partials/header.php'; ?>
<main class="page">
  <div class="container">
    <div class="page-header">
      <div><h2 class="page-title">Modificar proveedor</h2></div>
      <div class="inline-actions"><a class="btn btn-ghost" href="suppliers.php">Listado</a></div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <form method="post" class="stack">
        <input type="hidden" name="action" value="update_supplier">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="supplier-form-grid">
          <label class="form-field">
            <span class="form-label">Nombre del proveedor</span>
            <input class="form-control" type="text" name="name" maxlength="190" required value="<?= e($form['name']) ?>">
          </label>
          <div class="supplier-inline-grid">
            <label class="form-field">
              <span class="form-label">Base (%)</span>
              <input class="form-control" type="number" name="base_margin_percent" min="0" max="999.99" step="0.01" required value="<?= e($form['base_margin_percent']) ?>">
            </label>
            <label class="form-field">
              <span class="form-label">Descuento (%)</span>
              <input class="form-control" type="number" min="-100" max="100" step="0.01" name="import_discount_default" value="<?= e($form['import_discount_default']) ?>">
            </label>
          </div>
          <label class="form-field">
            <span class="form-label">Regla duplicados</span>
            <select class="form-control" name="import_dedupe_mode">
              <?php foreach ($dedupeModeLabels as $mode => $label): ?>
                <option value="<?= e($mode) ?>" <?= $form['import_dedupe_mode'] === $mode ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="supplier-cost-grid<?= $form['import_default_cost_type'] === 'PACK' ? ' is-pack' : '' ?>" id="supplier-cost-grid">
            <label class="form-field">
              <span class="form-label">Tipo de costo</span>
              <select class="form-control" name="import_default_cost_type" id="import-default-cost-type">
                <option value="UNIDAD" <?= $form['import_default_cost_type'] === 'UNIDAD' ? 'selected' : '' ?>>UNIDAD</option>
                <option value="PACK" <?= $form['import_default_cost_type'] === 'PACK' ? 'selected' : '' ?>>PACK</option>
              </select>
            </label>
            <label class="form-field" id="import-default-units-field" style="display: none;">
              <span class="form-label">Unidades</span>
              <input class="form-control" type="number" min="1" step="1" name="import_default_units_per_pack" value="<?= e($form['import_default_units_per_pack']) ?>">
            </label>
          </div>
        </div>

        <div class="inline-actions">
          <button class="btn" type="submit">Guardar</button>
          <a class="btn btn-ghost" href="suppliers.php">Cancelar</a>
        </div>
      </form>
    </div>

    <?php if (can_suppliers_ps_bulk()): ?>
      <div class="card" style="margin-top:var(--space-4)">
        <form method="post" enctype="multipart/form-data" class="stack">
          <input type="hidden" name="action" value="ps_bulk_step1">
          <div class="page-header">
            <div><h3 class="page-title" style="margin:0">PrestaShop (acciones masivas)</h3></div>
          </div>
          <?php if ($psBulkError !== ''): ?><div class="alert alert-danger"><?= e($psBulkError) ?></div><?php endif; ?>
          <div class="supplier-form-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
            <label class="form-field">
              <span class="form-label">Sitio PrestaShop</span>
              <select class="form-control" name="site_id" required>
                <option value="">Seleccionar...</option>
                <?php foreach ($psBulkSites as $site): ?>
                  <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="form-field">
              <span class="form-label">CSV de SKUs proveedor</span>
              <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required>
            </label>
          </div>
          <div class="inline-actions">
            <button class="btn" type="submit">Siguiente</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</main>
<script>
  const defaultCostType = document.getElementById('import-default-cost-type');
  const defaultUnitsField = document.getElementById('import-default-units-field');
  const supplierCostGrid = document.getElementById('supplier-cost-grid');
  if (defaultCostType && defaultUnitsField && supplierCostGrid) {
    const syncDefaultCostType = () => {
      const isPack = defaultCostType.value === 'PACK';
      defaultUnitsField.style.display = isPack ? 'block' : 'none';
      supplierCostGrid.classList.toggle('is-pack', isPack);
    };
    defaultCostType.addEventListener('change', syncDefaultCostType);
    syncDefaultCostType();
  }
</script>
</body>
</html>
