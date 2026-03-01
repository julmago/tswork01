<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_import_lib.php';
require_login();
ensure_product_suppliers_schema();

$pdo = db();
$action = trim((string)get('action', ''));

function providers_csv_tmp_dir(): string {
  return __DIR__ . '/uploads/tmp';
}

function providers_csv_detect_delimiter(string $line): string {
  $candidates = [',', ';', "\t"];
  $bestDelimiter = ',';
  $bestCount = -1;
  foreach ($candidates as $delimiter) {
    $count = count(str_getcsv($line, $delimiter));
    if ($count > $bestCount) {
      $bestCount = $count;
      $bestDelimiter = $delimiter;
    }
  }
  return $bestDelimiter;
}

function providers_csv_header_and_preview(string $path): array {
  $fh = fopen($path, 'rb');
  if ($fh === false) {
    throw new RuntimeException('No se pudo abrir el archivo CSV.');
  }

  $firstLine = fgets($fh);
  if ($firstLine === false) {
    fclose($fh);
    throw new RuntimeException('El archivo CSV está vacío.');
  }

  $delimiter = providers_csv_detect_delimiter($firstLine);
  rewind($fh);

  $headers = fgetcsv($fh, 0, $delimiter);
  if (!is_array($headers) || count($headers) === 0) {
    fclose($fh);
    throw new RuntimeException('No se pudieron leer encabezados del CSV.');
  }
  $headers = array_map(static fn($value) => trim((string)$value), $headers);

  $preview = [];
  while (($row = fgetcsv($fh, 0, $delimiter)) !== false && count($preview) < 10) {
    $preview[] = $row;
  }
  fclose($fh);

  return ['delimiter' => $delimiter, 'headers' => $headers, 'preview' => $preview];
}

if ($action === 'provider_attach_csv') {
  require_permission(can_import_csv(), 'Sin permisos para importar proveedores.');
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
          <h2 class="page-title">Agregar proveedor · Paso 1</h2>
          <span class="muted">Subí un archivo CSV para vincular productos a un proveedor.</span>
        </div>
      </div>
      <div class="card">
        <form method="post" action="suppliers.php?action=provider_attach_csv_upload" enctype="multipart/form-data" class="stack">
          <label class="form-field">
            <span class="form-label">Archivo CSV</span>
            <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required>
          </label>
          <div class="inline-actions">
            <button class="btn btn-primary" type="submit">Siguiente</button>
            <a class="btn btn-ghost" href="suppliers.php">Volver</a>
          </div>
        </form>
      </div>
    </div>
  </main>
  </body>
  </html>
  <?php
  exit;
}

if ($action === 'provider_attach_csv_upload') {
  require_permission(can_import_csv(), 'Sin permisos para importar proveedores.');
  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('suppliers.php?action=provider_attach_csv');
  }
  if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
    abort(400, 'Archivo CSV requerido.');
  }
  $file = $_FILES['csv_file'];
  if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    abort(400, 'Error al subir CSV.');
  }
  $tmpName = (string)($file['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    abort(400, 'Archivo inválido.');
  }
  $name = (string)($file['name'] ?? '');
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext !== 'csv') {
    abort(400, 'Solo se aceptan archivos CSV.');
  }
  $maxBytes = 10 * 1024 * 1024;
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) {
    abort(400, 'Tamaño de CSV inválido (máximo 10MB).');
  }

  $dir = providers_csv_tmp_dir();
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    abort(500, 'No se pudo crear directorio temporal.');
  }

  $targetPath = $dir . '/provider_attach_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.csv';
  if (!move_uploaded_file($tmpName, $targetPath)) {
    abort(500, 'No se pudo guardar el CSV temporal.');
  }

  $_SESSION['provider_attach_csv_path'] = $targetPath;
  redirect('suppliers.php?action=provider_attach_csv_map');
}

if ($action === 'provider_attach_csv_map') {
  require_permission(can_import_csv(), 'Sin permisos para importar proveedores.');
  $path = (string)($_SESSION['provider_attach_csv_path'] ?? '');
  if ($path === '' || !is_file($path)) {
    redirect('suppliers.php?action=provider_attach_csv');
  }

  $analysis = providers_csv_header_and_preview($path);
  $headers = $analysis['headers'];
  $previewRows = $analysis['preview'];
  $stSup = $pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC');
  $allSuppliers = $stSup ? $stSup->fetchAll() : [];
  $costTypes = ['UNIDAD' => 'Unidad', 'PACK' => 'Pack', 'CAJA' => 'Caja', 'OTRO' => 'Otro'];
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
          <h2 class="page-title">Agregar proveedor · Paso 2</h2>
          <span class="muted">Mapeá columnas y completá datos del vínculo.</span>
        </div>
      </div>

      <div class="card stack">
        <form method="post" action="suppliers.php?action=provider_attach_csv_run" class="stack">
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4);">
            <label class="form-field">
              <span class="form-label">SKU (TSWork)</span>
              <select class="form-control" name="col_sku" required>
                <option value="">Seleccionar</option>
                <?php foreach ($headers as $header): ?>
                  <option value="<?= e($header) ?>"><?= e($header) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="form-field">
              <span class="form-label">SKU proveedor</span>
              <select class="form-control" name="col_sku_provider">
                <option value="">(Opcional)</option>
                <?php foreach ($headers as $header): ?>
                  <option value="<?= e($header) ?>"><?= e($header) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="form-field">
              <span class="form-label">Precio</span>
              <select class="form-control" name="col_price" required>
                <option value="">Seleccionar</option>
                <?php foreach ($headers as $header): ?>
                  <option value="<?= e($header) ?>"><?= e($header) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="form-field">
              <span class="form-label">Proveedor</span>
              <select class="form-control" name="supplier_id" required>
                <option value="">Seleccionar</option>
                <?php foreach ($allSuppliers as $supplier): ?>
                  <option value="<?= (int)$supplier['id'] ?>"><?= e((string)$supplier['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="form-field">
              <span class="form-label">Tipo de costo recibido</span>
              <select class="form-control" name="cost_type" required>
                <option value="">Seleccionar</option>
                <?php foreach ($costTypes as $value => $label): ?>
                  <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="inline-actions">
            <button class="btn btn-primary" type="submit">Importar</button>
            <a class="btn btn-ghost" href="suppliers.php?action=provider_attach_csv">Volver</a>
          </div>
        </form>
      </div>

      <div class="card">
        <h3 style="margin-top:0;">Vista previa (10 filas)</h3>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <?php foreach ($headers as $header): ?>
                  <th><?= e($header) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!$previewRows): ?>
                <tr><td colspan="<?= count($headers) ?>">Sin filas para previsualizar.</td></tr>
              <?php else: ?>
                <?php foreach ($previewRows as $row): ?>
                  <tr>
                    <?php foreach ($headers as $idx => $_): ?>
                      <td><?= e((string)($row[$idx] ?? '')) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
  </body>
  </html>
  <?php
  exit;
}

if ($action === 'provider_attach_csv_run') {
  require_permission(can_import_csv(), 'Sin permisos para importar proveedores.');
  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('suppliers.php?action=provider_attach_csv_map');
  }

  $path = (string)($_SESSION['provider_attach_csv_path'] ?? '');
  if ($path === '' || !is_file($path)) {
    redirect('suppliers.php?action=provider_attach_csv');
  }

  $analysis = providers_csv_header_and_preview($path);
  $headers = $analysis['headers'];
  $delimiter = $analysis['delimiter'];

  $colSku = trim((string)post('col_sku', ''));
  $colSkuProvider = trim((string)post('col_sku_provider', ''));
  $colPrice = trim((string)post('col_price', ''));
  $supplierId = (int)post('supplier_id', '0');
  $costType = trim((string)post('cost_type', ''));

  if ($colSku === '' || !in_array($colSku, $headers, true)) {
    abort(400, 'Columna SKU inválida.');
  }
  if ($colPrice === '' || !in_array($colPrice, $headers, true)) {
    abort(400, 'Columna Precio inválida.');
  }
  if ($colSkuProvider !== '' && !in_array($colSkuProvider, $headers, true)) {
    abort(400, 'Columna SKU proveedor inválida.');
  }
  if ($supplierId <= 0) {
    abort(400, 'Proveedor inválido.');
  }
  if ($costType === '') {
    abort(400, 'Tipo de costo requerido.');
  }

  $stSupplier = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
  $stSupplier->execute([$supplierId]);
  $supplier = $stSupplier->fetch();
  if (!$supplier) {
    abort(404, 'Proveedor no encontrado.');
  }

  $idxSku = array_search($colSku, $headers, true);
  $idxSkuProvider = $colSkuProvider !== '' ? array_search($colSkuProvider, $headers, true) : false;
  $idxPrice = array_search($colPrice, $headers, true);

  $fh = fopen($path, 'rb');
  if ($fh === false) {
    abort(500, 'No se pudo abrir el CSV.');
  }
  fgetcsv($fh, 0, $delimiter);

  $stProduct = $pdo->prepare('SELECT id FROM products WHERE sku = :sku LIMIT 1');
  $stCountActive = $pdo->prepare('SELECT COUNT(*) FROM product_suppliers WHERE product_id = :pid AND is_active = 1');
  $stFindLink = $pdo->prepare('SELECT id, is_active FROM product_suppliers WHERE product_id = :pid AND supplier_id = :sid LIMIT 1');
  $stUpdate = $pdo->prepare('UPDATE product_suppliers SET supplier_sku = :supplier_sku, cost_received = :cost_received, cost_type_received = :cost_type_received WHERE id = :id');
  $stInsert = $pdo->prepare('INSERT INTO product_suppliers (product_id, supplier_id, supplier_sku, cost_received, cost_type_received, is_active) VALUES (:product_id, :supplier_id, :supplier_sku, :cost_received, :cost_type_received, :is_active)');

  $totalRows = 0;
  $newLinks = 0;
  $updatedLinks = 0;
  $notFound = 0;
  $newActive = 0;
  $newInactive = 0;

  while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    $totalRows++;
    $sku = trim((string)($row[(int)$idxSku] ?? ''));
    if ($sku === '') {
      continue;
    }

    $supplierSku = $idxSkuProvider !== false ? trim((string)($row[(int)$idxSkuProvider] ?? '')) : '';
    $rawPrice = $row[(int)$idxPrice] ?? '';
    $normalizedPrice = supplier_import_normalize_price($rawPrice);
    $priceInteger = $normalizedPrice === null ? null : (int)round($normalizedPrice, 0);

    $stProduct->execute([':sku' => $sku]);
    $productId = (int)$stProduct->fetchColumn();
    if ($productId <= 0) {
      $notFound++;
      continue;
    }

    $stFindLink->execute([':pid' => $productId, ':sid' => $supplierId]);
    $existing = $stFindLink->fetch();
    if ($existing) {
      $stUpdate->execute([
        ':supplier_sku' => $supplierSku !== '' ? $supplierSku : null,
        ':cost_received' => $priceInteger,
        ':cost_type_received' => $costType,
        ':id' => (int)$existing['id'],
      ]);
      $updatedLinks++;
      continue;
    }

    $stCountActive->execute([':pid' => $productId]);
    $activeCount = (int)$stCountActive->fetchColumn();
    $isActive = $activeCount === 0 ? 1 : 0;
    if ($isActive === 1) {
      $newActive++;
    } else {
      $newInactive++;
    }

    $stInsert->execute([
      ':product_id' => $productId,
      ':supplier_id' => $supplierId,
      ':supplier_sku' => $supplierSku !== '' ? $supplierSku : null,
      ':cost_received' => $priceInteger,
      ':cost_type_received' => $costType,
      ':is_active' => $isActive,
    ]);
    $newLinks++;
  }
  fclose($fh);
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
          <h2 class="page-title">Importación finalizada</h2>
          <span class="muted">Proveedor: <?= e((string)$supplier['name']) ?></span>
        </div>
      </div>
      <div class="card stack">
        <ul>
          <li>Total filas: <?= (int)$totalRows ?></li>
          <li>Vínculos nuevos: <?= (int)$newLinks ?></li>
          <li>Vínculos actualizados: <?= (int)$updatedLinks ?></li>
          <li>Productos no encontrados: <?= (int)$notFound ?></li>
          <li>Nuevos activos: <?= (int)$newActive ?></li>
          <li>Nuevos inactivos: <?= (int)$newInactive ?></li>
        </ul>
        <div class="inline-actions">
          <a class="btn" href="suppliers.php">Volver</a>
        </div>
      </div>
    </div>
  </main>
  </body>
  </html>
  <?php
  exit;
}

$q = trim(get('q', ''));
$page = max(1, (int)get('page', '1'));
$limit = 25;
$offset = ($page - 1) * $limit;

$message = '';
if (get('created') === '1') {
  $message = 'Proveedor creado.';
}
if (get('updated') === '1') {
  $message = 'Proveedor modificado.';
}

$where = '';
$params = [];
if ($q !== '') {
  $where = 'WHERE s.name LIKE :q';
  $params[':q'] = '%' . $q . '%';
}

$countSql = "SELECT COUNT(*) FROM suppliers s $where";
$countSt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
  $countSt->bindValue($key, $value, PDO::PARAM_STR);
}
$countSt->execute();
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$listSql = "SELECT s.id, s.name, s.base_margin_percent, s.import_dedupe_mode, s.is_active
  FROM suppliers s
  $where
  ORDER BY s.name ASC
  LIMIT :limit OFFSET :offset";
$listSt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
  $listSt->bindValue($key, $value, PDO::PARAM_STR);
}
$listSt->bindValue(':limit', $limit, PDO::PARAM_INT);
$listSt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listSt->execute();
$suppliers = $listSt->fetchAll();

$queryBase = [];
if ($q !== '') $queryBase['q'] = $q;
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);
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
        <h2 class="page-title">Proveedores</h2>
        <span class="muted">Administrá proveedores y reglas de importación.</span>
      </div>
      <div class="inline-actions">
        <a class="btn" href="suppliers_new.php">Nuevo proveedor</a>
        <a class="btn" href="suppliers.php?action=provider_attach_csv">Agregar proveedor</a>
      </div>
    </div>

    <?php if ($message !== ''): ?>
      <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="get" action="suppliers.php" class="stack">
        <div class="input-icon">
          <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre">
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?><a class="btn btn-ghost" href="suppliers.php">Limpiar</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Base (%)</th>
              <th>Duplicados</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$suppliers): ?>
              <tr><td colspan="5">Sin proveedores.</td></tr>
            <?php else: ?>
              <?php foreach ($suppliers as $supplier): ?>
                <tr>
                  <td><?= e($supplier['name']) ?></td>
                  <td><?= e(number_format((float)$supplier['base_margin_percent'], 2, '.', '')) ?></td>
                  <td><?= e((string)$supplier['import_dedupe_mode']) ?></td>
                  <td><?= (int)$supplier['is_active'] === 1 ? 'Activo' : 'No' ?></td>
                  <td>
                    <div class="inline-actions">
                      <a class="btn btn-ghost btn-sm" href="suppliers_edit.php?id=<?= (int)$supplier['id'] ?>">Modificar</a>
                      <a class="btn btn-ghost btn-sm" href="suppliers_import.php?supplier_id=<?= (int)$supplier['id'] ?>">Importar lista</a>
                      <a class="btn btn-ghost btn-sm" href="supplier_adjust.php?id=<?= (int)$supplier['id'] ?>">Ajuste global</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="inline-actions">
        <?php
          $prevQuery = $queryBase;
          $prevQuery['page'] = $prevPage;
          $nextQuery = $queryBase;
          $nextQuery['page'] = $nextPage;
        ?>
        <?php if ($page > 1): ?>
          <a class="btn btn-ghost" href="suppliers.php?<?= e(http_build_query($prevQuery)) ?>">&laquo; Anterior</a>
        <?php else: ?>
          <span class="muted">&laquo; Anterior</span>
        <?php endif; ?>
        <span class="muted">Página <?= (int)$page ?> de <?= (int)$totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn-ghost" href="suppliers.php?<?= e(http_build_query($nextQuery)) ?>">Siguiente &raquo;</a>
        <?php else: ?>
          <span class="muted">Siguiente &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</body>
</html>
