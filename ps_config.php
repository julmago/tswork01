<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/prestashop.php';
require_permission(hasPerm('menu_config_prestashop'));

$error = '';
$message = '';
$test_error = '';
$test_result = null;
$test_sku = '';

if (is_post() && post('action') === 'save') {
  $url = trim(post('prestashop_url'));
  $key = trim(post('prestashop_api_key'));
  $mode = trim(post('prestashop_mode','replace'));
  if (!in_array($mode, ['replace','add'], true)) $mode = 'replace';

  // Normalización simple
  $url = rtrim($url, "/");

  setting_set('prestashop_url', $url);
  setting_set('prestashop_api_key', $key);
  setting_set('prestashop_mode', $mode);

  $message = 'Configuración guardada.';
}

if (is_post() && post('action') === 'test') {
  $test_sku = trim(post('prestashop_test_sku'));
  if ($test_sku === '') {
    $test_error = 'Ingresá un SKU para probar.';
  } else {
    try {
      $match = ps_find_by_reference($test_sku);
      if ($match) {
        $test_result = [
          'found' => true,
          'type' => $match['type'],
          'id_product' => $match['id_product'],
          'id_product_attribute' => $match['id_product_attribute'],
        ];
      } else {
        $test_result = ['found' => false];
      }
    } catch (Throwable $e) {
      $test_error = $e->getMessage();
    }
  }
}

$prestashop_url = setting_get('prestashop_url','');
$prestashop_api_key = setting_get('prestashop_api_key','');
$prestashop_mode = setting_get('prestashop_mode','replace');
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
      <h2 class="page-title">Config PrestaShop</h2>
      <span class="muted">Configurar credenciales y modo de sincronización.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <form method="post" class="stack">
        <input type="hidden" name="action" value="save">
        <div class="form-group">
          <label class="form-label">URL base (sin / final)</label>
          <input class="form-control" type="text" name="prestashop_url" value="<?= e($prestashop_url) ?>" placeholder="https://mitienda.com">
        </div>

        <div class="form-group">
          <label class="form-label">API Key (Webservice)</label>
          <input class="form-control" type="text" name="prestashop_api_key" value="<?= e($prestashop_api_key) ?>">
          <div class="muted small">Se usa por Basic Auth (API Key como usuario, contraseña vacía).</div>
        </div>

        <div class="form-group">
          <label class="form-label">Modo de sincronización</label>
          <select name="prestashop_mode">
            <option value="replace" <?= $prestashop_mode==='replace'?'selected':'' ?>>Reemplazar (= qty del listado)</option>
            <option value="add" <?= $prestashop_mode==='add'?'selected':'' ?>>Sumar (+ qty del listado)</option>
          </select>
        </div>

        <div class="form-actions">
          <button class="btn" type="submit">Guardar</button>
          <a class="btn btn-ghost" href="dashboard.php">Volver</a>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Probar conexión / Probar SKU</h3>
      </div>
      <p class="muted small">Se usa la misma búsqueda que la sincronización. Revisá los logs del servidor para ver URL, status, content-type y el inicio de la respuesta.</p>
      <?php if ($test_error): ?><div class="alert alert-danger"><?= e($test_error) ?></div><?php endif; ?>
      <?php if (is_array($test_result)): ?>
        <?php if ($test_result['found']): ?>
          <div class="alert alert-success">
            Encontrado (<?= e($test_result['type']) ?>):
            product_id=<?= (int)$test_result['id_product'] ?>,
            combo_id=<?= (int)$test_result['id_product_attribute'] ?>
          </div>
        <?php else: ?>
          <div class="alert alert-warning">No encontrado.</div>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" class="form-row">
        <input type="hidden" name="action" value="test">
        <div class="form-group">
          <label class="form-label">SKU</label>
          <input class="form-control" type="text" name="prestashop_test_sku" value="<?= e($test_sku) ?>" placeholder="MS-06">
        </div>
        <div class="form-group" style="align-self:end;">
          <button class="btn" type="submit">Probar</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Permisos que necesita la API Key</h3>
      </div>
      <ul>
        <li><strong>GET</strong> sobre <span class="code">products</span>, <span class="code">combinations</span>, <span class="code">stock_availables</span></li>
        <li><strong>PUT</strong> sobre <span class="code">stock_availables</span></li>
      </ul>
    </div>
  </div>
</main>

</body>
</html>
