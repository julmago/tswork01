<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_import_lib.php';
require_login();
ensure_product_suppliers_schema();

$supplierId = (int)get('supplier_id', '0');
if ($supplierId <= 0) {
  abort(400, 'Proveedor inválido.');
}

$st = db()->prepare('SELECT id, name FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$supplierId]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
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
      <div>
        <h2 class="page-title">Importar lista</h2>
        <span class="muted">Proveedor: <?= e((string)$supplier['name']) ?></span>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="suppliers.php">Listado</a>
      </div>
    </div>

    <div class="card">
      <form method="post" action="supplier_import.php" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="supplier_id" value="<?= (int)$supplier['id'] ?>">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4);">
          <label class="form-field">
            <span class="form-label">Tipo de fuente</span>
            <select class="form-control" name="source_type" id="source-type-select" required>
              <option value="FILE">Archivo</option>
              <option value="PASTE">Pegar texto</option>
            </select>
          </label>
          <label class="form-field" id="source-file-field">
            <span class="form-label">Archivo</span>
            <input class="form-control" type="file" name="source_file" id="source-file-input" accept=".csv,.xlsx,.xls,.txt,.pdf">
            <small class="muted" id="detected-file-format">Detectado: —</small>
          </label>
        </div>
        <label class="form-field" id="paste-text-field" style="display:none;">
          <span class="form-label">Pegar texto</span>
          <textarea class="form-control" name="paste_text" id="paste-text-input" rows="10" placeholder="Pegá contenido desde WhatsApp / email / txt"></textarea>
        </label>
        <label class="form-field" id="paste-separator-field" style="display:none; max-width: 280px;">
          <span class="form-label">Separador</span>
          <select class="form-control" name="paste_separator">
            <option value="AUTO">Automático</option>
            <option value="TAB">Tab</option>
            <option value="SEMICOLON">;</option>
            <option value="COMMA">,</option>
            <option value="PIPE">| (pipe)</option>
          </select>
        </label>
        <p class="muted">El sistema detecta formato automáticamente para archivos y analiza encabezados antes del Paso 2.</p>
        <div class="inline-actions">
          <button class="btn" type="submit">Continuar a Paso 2</button>
          <a class="btn btn-ghost" href="suppliers.php">Listado</a>
        </div>
      </form>
    </div>
  </div>
</main>
<script>
  const sourceType = document.getElementById('source-type-select');
  const pasteField = document.getElementById('paste-text-field');
  const pasteSeparatorField = document.getElementById('paste-separator-field');
  const fileField = document.getElementById('source-file-field');
  const fileInput = document.getElementById('source-file-input');
  const detectedFileFormat = document.getElementById('detected-file-format');

  const detectFromName = (name) => {
    const ext = (name.split('.').pop() || '').toLowerCase();
    if (ext === 'xlsx') return 'XLSX';
    if (ext === 'xls') return 'XLS';
    if (ext === 'csv') return 'CSV';
    if (ext === 'txt') return 'TXT';
    if (ext === 'pdf') return 'PDF';
    return 'Desconocido';
  };

  if (sourceType && pasteField && fileField && pasteSeparatorField) {
    const sync = () => {
      const isPaste = sourceType.value === 'PASTE';
      pasteField.style.display = isPaste ? 'block' : 'none';
      pasteSeparatorField.style.display = isPaste ? 'block' : 'none';
      fileField.style.display = isPaste ? 'none' : 'block';
    };
    sourceType.addEventListener('change', sync);
    sync();
  }

  if (fileInput && detectedFileFormat) {
    fileInput.addEventListener('change', () => {
      const f = fileInput.files && fileInput.files[0];
      if (!f) {
        detectedFileFormat.textContent = 'Detectado: —';
        return;
      }
      detectedFileFormat.textContent = `Detectado: ${detectFromName(f.name)}`;
    });
  }
</script>
</body>
</html>
