<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_gateway();
if (!empty($_SESSION['profile_user_id'])) {
  redirect('dashboard.php');
}

$error = '';
$notice = '';
$activeUserId = null;
if (get('expired') === '1') {
  $notice = 'Sesión de perfil expirada por inactividad.';
}

if (is_post()) {
  $activeUserId = (int)post('user_id', '0');
  $pin = trim(post('pin'));
  if ($activeUserId <= 0) {
    $error = 'Seleccioná un perfil válido.';
  } elseif (!ctype_digit($pin) || strlen($pin) !== 4) {
    $error = 'El PIN debe tener 4 dígitos numéricos.';
  } else {
    try {
      $st = db()->prepare(
        "SELECT id, role, first_name, last_name, email, theme, pin, is_active
         FROM users
         WHERE id = ? AND is_active = 1
         LIMIT 1"
      );
      $st->execute([$activeUserId]);
      $u = $st->fetch();
      if (!$u) {
        $error = 'Perfil no encontrado o inactivo.';
      } elseif (empty($u['pin'])) {
        $error = 'PIN no configurado.';
      } elseif ($pin !== $u['pin']) {
        $error = 'PIN incorrecto.';
      } else {
        session_regenerate_id(true);
        unset($u['pin']);
        $_SESSION['user'] = $u;
        $_SESSION['logged_in'] = true;
        $_SESSION['gateway_logged'] = true;
        $_SESSION['profile_logged'] = true;
        $_SESSION['profile_user_id'] = (int)$u['id'];
        $_SESSION['profile_last_activity'] = time();
        refresh_gateway_session_cookie();
        redirect('dashboard.php');
      }
    } catch (Throwable $e) {
      error_log(sprintf(
        '[%s] Profile login error for user %s: %s in %s:%d',
        date('c'),
        $activeUserId,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
      ));
      $debug = (bool)($config['debug'] ?? false);
      if ($debug) {
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

$profiles = [];
try {
  $st = db()->query(
    "SELECT u.id, u.first_name, u.last_name, u.role, u.theme, r.role_name
     FROM users u
     LEFT JOIN roles r ON r.role_key = u.role
     WHERE u.is_active = 1
     ORDER BY u.first_name ASC, u.last_name ASC"
  );
  $profiles = $st->fetchAll();
} catch (Throwable $e) {
  error_log(sprintf('[%s] Profile list error: %s', date('c'), $e->getMessage()));
  $error = 'No se pudieron cargar los perfiles.';
}

$themes = theme_catalog();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Seleccionar perfil · TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
  <main class="page">
    <div class="container">
      <div class="page-header">
        <div>
          <h1 class="page-title">¿Quién entra ahora?</h1>
          <p class="muted">Elegí un perfil e ingresá el PIN de 4 dígitos.</p>
        </div>
        <a class="btn btn-ghost" href="logout.php">Salir del sistema</a>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>
      <?php if ($notice): ?>
        <div class="alert alert-warning"><?= e($notice) ?></div>
      <?php endif; ?>

      <?php if (empty($profiles)): ?>
        <div class="card">
          <p class="muted">No hay perfiles activos disponibles.</p>
        </div>
      <?php else: ?>
        <div class="grid grid-3">
          <?php foreach ($profiles as $profile): ?>
            <?php
              $themeKey = (string)($profile['theme'] ?? 'theme_default');
              $themeName = $themes[$themeKey]['name'] ?? $themeKey;
              $profileId = (int)$profile['id'];
              $isActive = $activeUserId === $profileId;
            ?>
            <div class="card profile-card<?= $isActive ? ' is-active' : '' ?>" data-profile-card>
              <div class="card-header">
                <h2 class="card-title"><?= e($profile['first_name'] ?? '') ?></h2>
                <span class="muted small"><?= e($profile['role_name'] ?: ($profile['role'] ?? '')) ?></span>
              </div>
              <p class="muted small"><?= e($profile['last_name'] ?? '') ?></p>
              <p class="muted small">Tema: <?= e($themeName) ?></p>
              <form method="post" class="stack profile-pin">
                <input type="hidden" name="user_id" value="<?= $profileId ?>">
                <div class="form-group">
                  <label class="form-label">PIN</label>
                  <input
                    class="form-control"
                    type="password"
                    name="pin"
                    data-profile-input
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="\d{4}"
                    maxlength="4"
                    required
                  >
                </div>
                <div class="form-actions">
                  <button class="btn" type="submit">Ingresar</button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
  <script>
    const cards = document.querySelectorAll('[data-profile-card]');
    const inputs = document.querySelectorAll('[data-profile-input]');
    const activateCard = (card) => {
      cards.forEach((item) => item.classList.remove('is-active'));
      if (card) {
        card.classList.add('is-active');
        const input = card.querySelector('input[name="pin"]');
        if (input) {
          input.focus();
        }
      }
    };
    inputs.forEach((input) => {
      input.addEventListener('focus', () => {
        activateCard(input.closest('[data-profile-card]'));
      });
    });
    const active = document.querySelector('[data-profile-card].is-active');
    if (active) {
      activateCard(active);
    }
  </script>
</body>
</html>
