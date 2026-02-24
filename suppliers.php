<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();

$pdo = db();
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
<body class="app-body">
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
