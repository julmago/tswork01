<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_permission(can_import_csv());
ensure_brands_schema();

$error = '';
$message = '';
$errors = [];
$summary = [
  'created' => 0,
  'updated' => 0,
  'imported' => 0,
  'skipped' => 0,
];

$expected_headers = ['SKU', 'TITULO', 'MARCA', 'MPN', 'BARRA'];

if (is_post()) {
  if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $error = 'Subí un archivo CSV válido.';
  } else {
    $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$fh) {
      $error = 'No se pudo leer el archivo.';
    } else {
      $headers = fgetcsv($fh, 0, ';');
      if (!$headers) {
        $error = 'El CSV está vacío.';
      } else {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        $normalized = array_map(function ($h) {
          return strtoupper(trim((string)$h));
        }, $headers);
        if (array_slice($normalized, 0, count($expected_headers)) !== $expected_headers) {
          $error = 'Encabezados inválidos. Deben ser: SKU;TITULO;MARCA;MPN;BARRA';
        } else {
          $line = 1;
          while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $line++;
            if (!$row || (count($row) === 1 && trim((string)$row[0]) === '')) {
              continue;
            }
            $row = array_pad($row, 5, '');
            $sku = trim((string)$row[0]);
            $title = trim((string)$row[1]);
            $brand = trim((string)$row[2]);
            $mpn = trim((string)$row[3]);
            $barra = trim((string)$row[4]);

            if ($sku === '') {
              $errors[] = "Línea {$line}: SKU vacío.";
              $summary['skipped']++;
              continue;
            }
            if ($title === '') {
              $errors[] = "Línea {$line}: TITULO vacío.";
              $summary['skipped']++;
              continue;
            }

            $codes = [];
            if ($barra !== '') $codes[] = ['code' => $barra, 'type' => 'BARRA'];
            if ($mpn !== '') $codes[] = ['code' => $mpn, 'type' => 'MPN'];

            try {
              db()->beginTransaction();
              $st = db()->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
              $st->execute([$sku]);
              $existing = $st->fetch();
              $product_id = $existing ? (int)$existing['id'] : 0;

              $conflict_code = '';
              foreach ($codes as $code) {
                $st = db()->prepare("SELECT product_id FROM product_codes WHERE LOWER(code) = LOWER(?) LIMIT 1");
                $st->execute([$code['code']]);
                $code_row = $st->fetch();
                if ($code_row && (int)$code_row['product_id'] !== $product_id) {
                  $conflict_code = $code['code'];
                  break;
                }
              }

              if ($conflict_code !== '') {
                db()->rollBack();
                $errors[] = "Línea {$line}: el código {$conflict_code} ya está asociado a otro producto.";
                $summary['skipped']++;
                continue;
              }

              $brand_id = resolve_brand_id($brand);

              if ($product_id > 0) {
                $st = db()->prepare("UPDATE products SET name = ?, brand = ?, brand_id = ?, updated_at = NOW() WHERE id = ?");
                $st->execute([$title, $brand, $brand_id, $product_id]);
                $summary['updated']++;
              } else {
                $st = db()->prepare("INSERT INTO products(sku, name, brand, brand_id, updated_at) VALUES(?, ?, ?, ?, NOW())");
                $st->execute([$sku, $title, $brand, $brand_id]);
                $product_id = (int)db()->lastInsertId();
                $summary['created']++;
              }

              foreach ($codes as $code) {
                $st = db()->prepare("SELECT id FROM product_codes WHERE product_id = ? AND LOWER(code) = LOWER(?) LIMIT 1");
                $st->execute([$product_id, $code['code']]);
                $existing_code = $st->fetch();
                if (!$existing_code) {
                  $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, ?)");
                  $st->execute([$product_id, $code['code'], $code['type']]);
                }
              }

              db()->commit();
              $summary['imported']++;
            } catch (Throwable $t) {
              if (db()->inTransaction()) db()->rollBack();
              $errors[] = "Línea {$line}: error al importar.";
              $summary['skipped']++;
            }
          }

          $message = "Importación finalizada. Filas importadas: {$summary['imported']}. Nuevos: {$summary['created']}. Actualizados: {$summary['updated']}. Omitidos: {$summary['skipped']}.";
        }
      }
      fclose($fh);
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
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Importar productos (CSV)</h2>
      <span class="muted">Cargá el archivo con el formato requerido.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <p><strong>Formato requerido</strong></p>
      <ul>
        <li>Separador: <span class="code">;</span></li>
        <li>Charset: UTF-8</li>
        <li>Encabezados: <span class="code">SKU;TITULO;MARCA;MPN;BARRA</span></li>
      </ul>
      <p>Reglas:</p>
      <ul>
        <li>SKU es único y obligatorio.</li>
        <li>BARRA y MPN se guardan como códigos del producto.</li>
        <li>Si un código ya está asociado a otro producto, esa fila se omite y se registra el error.</li>
      </ul>
    </div>

    <div class="card">
      <form method="post" enctype="multipart/form-data" class="stack">
        <div class="form-group">
          <label class="form-label">Archivo CSV</label>
          <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Importar</button>
          <a class="btn btn-ghost" href="product_list.php">Volver</a>
        </div>
      </form>
    </div>

    <?php if ($errors): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Errores</h3>
        </div>
        <ul>
          <?php foreach ($errors as $row_error): ?>
            <li><?= e($row_error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</main>

</body>
</html>
