<?php
require_once __DIR__ . '/bootstrap.php';
if (!empty($_SESSION['profile_user_id'])) {
  redirect('dashboard.php');
}
if (has_gateway_session()) {
  redirect('select_profile.php');
}

$error = '';
if (is_post()) {
  $email = trim(post('email'));
  $pass  = post('password');
  if ($email === '' || $pass === '') {
    $error = 'Completá email y contraseña.';
  } else {
    try {
      $auth = auth_config();
      $gatewayEmail = (string)($auth['gateway_email'] ?? '');
      $gatewayHash = (string)($auth['gateway_password_hash'] ?? '');
      error_log(sprintf('[%s] Gateway login attempt for %s', date('c'), $email));
      if ($email !== $gatewayEmail) {
        error_log(sprintf('[%s] Gateway login email mismatch for %s', date('c'), $email));
        $error = 'Credenciales inválidas.';
      } elseif (!password_verify($pass, $gatewayHash)) {
        error_log(sprintf('[%s] Gateway login password mismatch for %s', date('c'), $email));
        $error = 'Credenciales inválidas.';
      } else {
        error_log(sprintf('[%s] Gateway login success for %s', date('c'), $email));
        session_regenerate_id(true);
        $_SESSION['gateway_logged'] = true;
        unset(
          $_SESSION['user'],
          $_SESSION['logged_in'],
          $_SESSION['profile_logged'],
          $_SESSION['profile_user_id'],
          $_SESSION['profile_last_activity']
        );
        refresh_gateway_session_cookie();
        redirect('select_profile.php');
      }
    } catch (Throwable $e) {
      error_log(sprintf(
        '[%s] Gateway login error for %s: %s in %s:%d',
        date('c'),
        $email,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
      ));
      if (!empty($debug)) {
        $error = sprintf(
          'Error interno: %s (%s:%d)',
          $e->getMessage(),
          $e->getFile(),
          $e->getLine()
        );
      } else {
        $error = 'Ocurrió un error interno. Intentá nuevamente más tarde.';
      }
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
  <main class="page">
    <div class="container">
      <div class="card login-card">
        <div class="card-header">
          <h2 class="card-title">Gateway</h2>
          <span class="muted small">Validación inicial</span>
        </div>
        <?php if ($error): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="stack">
          <div class="form-group">
            <label class="form-label">Mail</label>
            <input class="form-control" type="email" name="email" value="<?= e(post('email')) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Contraseña</label>
            <input class="form-control" type="password" name="password" required>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Ingresar</button>
          </div>
        </form>
        <p class="muted small">
          El gateway habilita la selección de perfiles internos.
        </p>
      </div>
    </div>
  </main>
</body>
</html>
