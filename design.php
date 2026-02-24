<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_permission(hasPerm('menu_design'));

$themes = theme_catalog();
$current_theme = current_theme();
$message = '';
$error = '';

if (is_post() && post('action') === 'apply') {
  $selected = post('theme');
  if (!isset($themes[$selected])) {
    $error = 'Tema inválido.';
  } else {
    $u = current_user();
    $st = db()->prepare("UPDATE users SET theme = ? WHERE id = ?");
    $st->execute([$selected, (int)$u['id']]);
    $_SESSION['user']['theme'] = $selected;
    $current_theme = $selected;
    $message = 'Tema actualizado.';
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
    <div class="page-header theme-page-header">
      <h2 class="page-title">Plantillas de diseño</h2>
      <span class="muted">Elegí una plantilla para cambiar el look del sistema.</span>
    </div>

    <?php if ($message || $error): ?>
      <div class="theme-alerts">
        <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="themes-grid">
      <?php foreach ($themes as $key => $theme): ?>
        <div class="card theme-card">
          <div class="theme-card__header">
            <h3 class="card-title"><?= e($theme['name']) ?></h3>
            <span class="badge badge-success theme-card__badge <?= $current_theme === $key ? '' : 'theme-card__badge--hidden' ?>">Activo</span>
          </div>
          <p class="muted theme-card__desc"><?= e($theme['description']) ?></p>
          <div class="form-actions theme-card__actions">
            <form method="post" style="margin:0;">
              <input type="hidden" name="action" value="apply">
              <input type="hidden" name="theme" value="<?= e($key) ?>">
              <button class="btn" type="submit">Aplicar</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</main>
</body>
</html>
