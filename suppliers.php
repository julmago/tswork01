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

function parse_price_int($raw): ?int {
  $normalized = supplier_import_normalize_price($raw);
  if ($normalized === null) {
    return null;
  }
  return (int)$normalized;
}

function providers_debug_enabled(): bool {
  if (defined('DEBUG') && DEBUG) {
    return true;
  }
  return (bool)($GLOBALS['config']['debug'] ?? false);
}

function providers_product_suppliers_columns(PDO $pdo): array {
  $st = $pdo->query('SHOW COLUMNS FROM product_suppliers');
  if (!$st) {
    throw new RuntimeException('No se pudieron leer columnas de product_suppliers.');
  }
  $columns = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = (string)($row['Field'] ?? '');
    if ($name !== '') {
      $columns[$name] = true;
    }
  }
  return $columns;
}

if ($action === 'provider_attach_csv') {
  require_permission(can_suppliers_attach_csv(), 'Sin permisos para agregar proveedores por CSV.');
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
  require_permission(can_suppliers_attach_csv(), 'Sin permisos para agregar proveedores por CSV.');
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
    throw new RuntimeException('No se pudo crear directorio temporal: ' . $dir);
  }

  $targetPath = $dir . '/provider_attach_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.csv';
  if (!move_uploaded_file($tmpName, $targetPath)) {
    abort(500, 'No se pudo guardar el CSV temporal.');
  }

  $_SESSION['provider_attach_csv_path'] = $targetPath;
  redirect('suppliers.php?action=provider_attach_csv_map');
}

if ($action === 'provider_attach_csv_map') {
  require_permission(can_suppliers_attach_csv(), 'Sin permisos para agregar proveedores por CSV.');
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
          <div class="grid grid-3" style="gap: var(--space-4);">
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
          <div class="grid grid-3" style="gap: var(--space-4); margin-top: var(--space-3);">
            <label class="form-field">
              <span class="form-label">SKU proveedor</span>
              <select class="form-control" name="col_sku_provider" required>
                <option value="">Seleccionar</option>
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
              <span class="form-label">Estado Primario</span>
              <select class="form-control" name="primary_state" required>
                <option value="0" selected>Inactivo</option>
                <option value="1">Activo</option>
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
  require_permission(can_suppliers_attach_csv(), 'Sin permisos para agregar proveedores por CSV.');
  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('suppliers.php?action=provider_attach_csv_map');
  }

  $error = '';
  $supplier = ['name' => ''];
  $path = (string)($_SESSION['provider_attach_csv_path'] ?? '');
  $delimiter = '';
  $headers = [];
  $headersNorm = [];
  $colSku = trim((string)post('col_sku', ''));
  $colSkuProvider = trim((string)post('col_sku_provider', ''));
  $colPrice = trim((string)post('col_price', ''));
  $supplierId = (int)post('supplier_id', '0');
  $costType = trim((string)post('cost_type', ''));
  $primaryState = (int)($_POST['primary_state'] ?? 0);
  $firstParsedRow = null;

  $totalRows = 0;
  $newLinks = 0;
  $updatedLinks = 0;
  $notFound = 0;
  $newActive = 0;
  $newInactive = 0;

  try {
    if ($path === '' || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('CSV no encontrado o no legible: ' . $path);
    }
    if (empty($_POST['col_sku']) || empty($_POST['col_sku_provider']) || empty($_POST['col_price']) || $supplierId <= 0 || $costType === '') {
      throw new RuntimeException('Faltan campos obligatorios del mapeo.');
    }
    if (!in_array($primaryState, [0, 1], true)) {
      throw new RuntimeException('Estado primario inválido.');
    }

    $analysis = providers_csv_header_and_preview($path);
    $headers = $analysis['headers'];
    $delimiter = $analysis['delimiter'];
    if (!$headers || count($headers) === 0) {
      throw new RuntimeException('CSV sin encabezados');
    }
    $headersNorm = array_map(static fn($h) => mb_strtolower(trim((string)$h)), $headers);

    $selectedSkuHeaderNorm = mb_strtolower($colSku);
    $selectedPriceHeaderNorm = mb_strtolower($colPrice);
    $selectedSupplierSkuHeaderNorm = $colSkuProvider !== '' ? mb_strtolower($colSkuProvider) : '';

    $idxSku = array_search($selectedSkuHeaderNorm, $headersNorm, true);
    if ($idxSku === false) {
      throw new RuntimeException('No encuentro columna SKU en header.');
    }
    $idxPrice = array_search($selectedPriceHeaderNorm, $headersNorm, true);
    if ($idxPrice === false) {
      throw new RuntimeException('No encuentro columna Precio en header.');
    }
    $idxSkuProvider = array_search($selectedSupplierSkuHeaderNorm, $headersNorm, true);
    if ($idxSkuProvider === false) {
      throw new RuntimeException('No encuentro columna SKU proveedor en header.');
    }

    $stSupplier = $pdo->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
    $stSupplier->execute([$supplierId]);
    $supplier = $stSupplier->fetch();
    if (!$supplier) {
      throw new RuntimeException('Proveedor no encontrado.');
    }

    $columns = providers_product_suppliers_columns($pdo);
    $supplierSkuColumn = isset($columns['supplier_sku']) ? 'supplier_sku' : (isset($columns['supplier_code']) ? 'supplier_code' : null);
    if ($supplierSkuColumn === null) {
      throw new RuntimeException('No existe columna para SKU de proveedor (supplier_sku/supplier_code).');
    }
    $costColumn = isset($columns['cost_received']) ? 'cost_received' : (isset($columns['supplier_cost']) ? 'supplier_cost' : (isset($columns['cost_unitario']) ? 'cost_unitario' : null));
    if ($costColumn === null) {
      throw new RuntimeException('No existe columna para costo (cost_received/supplier_cost/cost_unitario).');
    }
    $costTypeColumn = isset($columns['cost_type_received']) ? 'cost_type_received' : (isset($columns['cost_type']) ? 'cost_type' : null);
    if ($costTypeColumn === null) {
      throw new RuntimeException('No existe columna para tipo de costo (cost_type_received/cost_type).');
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
      throw new RuntimeException('No se pudo abrir el CSV.');
    }
    $readHeaders = fgetcsv($fh, 0, $delimiter);
    if ($readHeaders === false) {
      fclose($fh);
      throw new RuntimeException('CSV sin encabezados');
    }

    $stProduct = $pdo->prepare('SELECT id FROM products WHERE sku = :sku LIMIT 1');
    $stFindActive = $pdo->prepare('SELECT id, supplier_id FROM product_suppliers WHERE product_id = :pid AND is_active = 1 LIMIT 1');
    $stDeactivateActive = $pdo->prepare('UPDATE product_suppliers SET is_active = 0 WHERE product_id = :pid AND is_active = 1');
    $stFindLink = $pdo->prepare('SELECT id, is_active FROM product_suppliers WHERE product_id = :pid AND supplier_id = :sid LIMIT 1');
    $stActivateLink = $pdo->prepare('UPDATE product_suppliers SET is_active = 1 WHERE id = :id');

    $updateSql = "UPDATE product_suppliers SET {$supplierSkuColumn} = :supplier_sku, {$costColumn} = :cost_value, {$costTypeColumn} = :cost_type WHERE id = :id";
    $insertSql = "INSERT INTO product_suppliers (product_id, supplier_id, {$supplierSkuColumn}, {$costColumn}, {$costTypeColumn}, is_active) VALUES (:product_id, :supplier_id, :supplier_sku, :cost_value, :cost_type, :is_active)";
    try {
      $stUpdate = $pdo->prepare($updateSql);
      $stInsert = $pdo->prepare($insertSql);
    } catch (Throwable $e) {
      throw new RuntimeException('Error preparando SQL dinámico: ' . $e->getMessage() . ' | UPDATE: ' . $updateSql . ' | INSERT: ' . $insertSql, 0, $e);
    }

    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
      $totalRows++;
      if ($firstParsedRow === null) {
        $firstParsedRow = $row;
      }
      $sku = trim((string)($row[(int)$idxSku] ?? ''));
      if ($sku === '') {
        continue;
      }

      $supplierSku = trim((string)($row[(int)$idxSkuProvider] ?? ''));
      $priceInt = parse_price_int($row[(int)$idxPrice] ?? '');

      $stProduct->execute([':sku' => $sku]);
      $productId = (int)$stProduct->fetchColumn();
      if ($productId <= 0) {
        $notFound++;
        continue;
      }

      $stFindLink->execute([':pid' => $productId, ':sid' => $supplierId]);
      $existing = $stFindLink->fetch();
      $stFindActive->execute([':pid' => $productId]);
      $activeLink = $stFindActive->fetch();
      $hasActive = $activeLink !== false;

      if ($existing) {
        try {
          $stUpdate->execute([
            ':supplier_sku' => $supplierSku !== '' ? $supplierSku : null,
            ':cost_value' => $priceInt,
            ':cost_type' => $costType,
            ':id' => (int)$existing['id'],
          ]);
        } catch (Throwable $e) {
          throw new RuntimeException('Error en UPDATE product_suppliers: ' . $e->getMessage() . ' | SQL: ' . $updateSql, 0, $e);
        }

        if ($primaryState === 1) {
          try {
            $stDeactivateActive->execute([':pid' => $productId]);
            $stActivateLink->execute([':id' => (int)$existing['id']]);
          } catch (Throwable $e) {
            throw new RuntimeException('Error ajustando estado activo en UPDATE: ' . $e->getMessage(), 0, $e);
          }
        } elseif (!$hasActive) {
          try {
            $stActivateLink->execute([':id' => (int)$existing['id']]);
          } catch (Throwable $e) {
            throw new RuntimeException('Error activando vínculo existente sin activo previo: ' . $e->getMessage(), 0, $e);
          }
        }

        $updatedLinks++;
        continue;
      }

      if ($primaryState === 1) {
        try {
          $stDeactivateActive->execute([':pid' => $productId]);
        } catch (Throwable $e) {
          throw new RuntimeException('Error desactivando vínculo activo previo: ' . $e->getMessage(), 0, $e);
        }
      }

      $isActive = 0;
      if ($primaryState === 1 || !$hasActive) {
        $isActive = 1;
      }

      if ($isActive === 1) {
        $newActive++;
      } else {
        $newInactive++;
      }

      try {
        $stInsert->execute([
          ':product_id' => $productId,
          ':supplier_id' => $supplierId,
          ':supplier_sku' => $supplierSku !== '' ? $supplierSku : null,
          ':cost_value' => $priceInt,
          ':cost_type' => $costType,
          ':is_active' => $isActive,
        ]);
      } catch (Throwable $e) {
        throw new RuntimeException('Error en INSERT product_suppliers: ' . $e->getMessage() . ' | SQL: ' . $insertSql, 0, $e);
      }
      $newLinks++;
    }
    fclose($fh);
  } catch (Throwable $e) {
    error_log("provider_attach_csv_run ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (defined('DEBUG') && DEBUG) {
      die('<pre>provider_attach_csv_run ERROR:' . "\n" . e($e->getMessage()) . "\n\n" . e($e->getTraceAsString()) . '</pre>');
    }
    if (providers_debug_enabled()) {
      die('<pre>provider_attach_csv_run ERROR:' . "\n" . e($e->getMessage()) . "\n\n" . e($e->getTraceAsString()) . '</pre>');
    }
    $error = 'Error al importar CSV. Revisá el log del servidor.';
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
          <h2 class="page-title">Importación finalizada</h2>
          <span class="muted">Proveedor: <?= e((string)$supplier['name']) ?></span>
        </div>
      </div>
      <div class="card stack">
        <?php if ($error !== ''): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <ul>
          <li>Total filas: <?= (int)$totalRows ?></li>
          <li>Vínculos nuevos: <?= (int)$newLinks ?></li>
          <li>Vínculos actualizados: <?= (int)$updatedLinks ?></li>
          <li>Productos no encontrados: <?= (int)$notFound ?></li>
          <li>Nuevos activos: <?= (int)$newActive ?></li>
          <li>Nuevos inactivos: <?= (int)$newInactive ?></li>
        </ul>
        <?php if (providers_debug_enabled()): ?>
          <div class="card" style="margin:0;">
            <h3 style="margin-top:0;">Debug</h3>
            <ul>
              <li>path: <code><?= e($path) ?></code></li>
              <li>delimiter detectado: <code><?= e($delimiter) ?></code></li>
              <li>headers detectados: <code><?= e(json_encode($headers, JSON_UNESCAPED_UNICODE) ?: '[]') ?></code></li>
              <li>columnas seleccionadas: <code><?= e(json_encode(['col_sku' => $colSku, 'col_sku_provider' => $colSkuProvider, 'col_price' => $colPrice], JSON_UNESCAPED_UNICODE) ?: '{}') ?></code></li>
              <li>primera fila parseada: <code><?= e(json_encode($firstParsedRow, JSON_UNESCAPED_UNICODE) ?: 'null') ?></code></li>
            </ul>
          </div>
        <?php endif; ?>
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
        <?php if (can_suppliers_attach_csv()): ?>
          <a class="btn" href="suppliers.php?action=provider_attach_csv">Agregar proveedor</a>
        <?php endif; ?>
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
