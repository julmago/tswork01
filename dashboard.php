<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_brands_schema();

$q = get('q','');
$params = [];
$products = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $st = db()->prepare("SELECT p.id, p.sku, p.name, COALESCE(b.name, p.brand) AS brand
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE p.sku LIKE ? OR p.name LIKE ? OR COALESCE(b.name, p.brand) LIKE ?
    ORDER BY p.name ASC
    LIMIT 200");
  $st->execute([$like,$like,$like]);
  $products = $st->fetchAll();
}

$st = db()->query("
  SELECT sl.id, sl.created_at, sl.name, sl.sync_target, sl.status,
         u.first_name, u.last_name
  FROM stock_lists sl
  JOIN users u ON u.id = sl.created_by
  ORDER BY sl.id DESC
  LIMIT 200
");
$lists = $st->fetchAll();
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
        <h2 class="page-title">Principal</h2>
        <span class="muted">Resumen rápido de listados y productos.</span>
      </div>
      <div class="inline-actions">
        <?php if (can_create_list()): ?>
          <a class="btn" href="list_new.php">+ Nuevo listado</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Buscador de productos</h3>
        <span class="muted small">Buscá por SKU, nombre o marca</span>
      </div>
      <form method="get" action="dashboard.php" class="stack">
        <div class="input-icon">
          <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por sku, nombre o marca">
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?>
            <a class="btn btn-ghost" href="dashboard.php">Limpiar</a>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($q !== ''): ?>
        <div class="stack">
          <h4 class="card-title">Resultados</h4>
          <?php if (!$products): ?>
            <p class="muted">No se encontraron productos.</p>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="table">
                <thead>
                  <tr><th>SKU</th><th>Nombre</th><th>Marca</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($products as $p): ?>
                    <tr>
                      <td><a href="product_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['sku']) ?></a></td>
                      <td><?= e($p['name']) ?></td>
                      <td><?= e($p['brand']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Listados recientes</h3>
      </div>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>id</th>
              <th>fecha</th>
              <th>nombre</th>
              <th>creador</th>
              <th>sync</th>
              <th>estado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lists as $l): ?>
              <tr style="cursor:pointer;" onclick="window.location='list_view.php?id=<?= (int)$l['id'] ?>'">
                <td><?= (int)$l['id'] ?></td>
                <td><?= e($l['created_at']) ?></td>
                <td><?= e($l['name']) ?></td>
                <td><?= e($l['first_name'] . ' ' . $l['last_name']) ?></td>
                <td>
                  <?php if ($l['sync_target'] === 'prestashop'): ?>
                    <span class="badge badge-success">prestashop</span>
                  <?php else: ?>
                    <span class="badge badge-muted">sin sync</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($l['status'] === 'open'): ?>
                    <span class="badge badge-success">Abierto</span>
                  <?php else: ?>
                    <span class="badge badge-warning">Cerrado</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

</body>
</html>
