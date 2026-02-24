<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_permission(can_create_list());

$error = '';
if (is_post()) {
  $name = post('name');
  if ($name === '') $error = 'Poné un nombre para el listado.';
  else {
    $u = current_user();
    $st = db()->prepare("INSERT INTO stock_lists(name, created_by) VALUES(?, ?)");
    $st->execute([$name, (int)$u['id']]);
    $id = (int)db()->lastInsertId();
    redirect("list_view.php?id={$id}");
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
      <h2 class="page-title">Nuevo Listado</h2>
      <span class="muted">Creá un nuevo listado de stock.</span>
    </div>
    <div class="card">
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="stack">
        <div class="form-group">
          <label class="form-label">Nombre</label>
          <input class="form-control" type="text" name="name" value="<?= e(post('name')) ?>" required>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Crear</button>
          <a class="btn btn-ghost" href="dashboard.php">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</main>
</body>
</html>
