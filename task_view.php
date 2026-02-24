<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();

$pdo = db();
$current_user_id = (int)(current_user()['id'] ?? 0);

$task_id = (int)get('id', '0');
if ($task_id <= 0) {
  abort(400, 'Falta id.');
}

$return_to = get('from', 'tasks_all.php');
$allowed_returns = ['tasks_all.php', 'tasks_my.php'];
if (!in_array($return_to, $allowed_returns, true)) {
  $return_to = 'tasks_all.php';
}
$return_label = $return_to === 'tasks_my.php' ? 'Mis tareas' : 'Todas las tareas';

$st = $pdo->prepare("
  SELECT t.*,
         cu.first_name AS created_first_name, cu.last_name AS created_last_name,
         cu.email AS created_email
  FROM tasks t
  JOIN users cu ON cu.id = t.created_by_user_id
  WHERE t.id = ?
  LIMIT 1
");
$st->execute([$task_id]);
$task = $st->fetch();
if (!$task) {
  abort(404, 'Tarea no encontrada.');
}

$assigned_users_st = $pdo->prepare("
  SELECT u.id, u.first_name, u.last_name, u.email
  FROM task_assignees ta
  JOIN users u ON u.id = ta.user_id
  WHERE ta.task_id = ?
  ORDER BY u.first_name, u.last_name
");
$assigned_users_st->execute([$task_id]);
$assigned_users = $assigned_users_st->fetchAll();
$assigned_user_ids = array_map('intval', array_column($assigned_users, 'id'));

$categories = task_categories((string)$task['category']);
$category_map = task_categories(null, true);
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types((string)$task['related_type']);
$related_types_map = task_related_types(null, true);
$users = task_users($pdo);

$creator_name = trim(($task['created_first_name'] ?? '') . ' ' . ($task['created_last_name'] ?? ''));

$error = '';
$can_edit = task_user_can_edit($pdo, $task_id, $current_user_id);
$can_delete_task = can_delete_task();
$saved = get('saved') === '1';
$status_updated = get('status_updated') === '1';
if (is_post() && post('action') === 'delete') {
  if (!$can_delete_task) {
    abort(403, 'No autorizado.');
  }
  if (!csrf_is_valid(post('csrf_token'))) {
    abort(403, 'Token inválido.');
  }
  try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM task_assignees WHERE task_id = ?")->execute([$task_id]);
    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$task_id]);
    $pdo->commit();
  } catch (Throwable $t) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    abort(500, 'No se pudo eliminar la tarea.');
  }
  redirect('tasks_all.php?message=deleted');
}
if (is_post() && post('action') === 'update') {
  if (!$can_edit) {
    abort(403, 'No tenés permisos para editar esta tarea.');
  }
  $title = trim((string)post('title'));
  $description = trim((string)post('description'));
  $category = post('category');
  $priority = post('priority');
  $status = post('status');
  $assigned_user_ids = $_POST['assigned_user_ids'] ?? [];
  $due_date = trim((string)post('due_date'));
  $related_type = post('related_type');

  if ($title === '') {
    $error = 'El título es obligatorio.';
  } elseif (!array_key_exists($category, $category_map)) {
    $error = 'La categoría es obligatoria.';
  } elseif (!array_key_exists($priority, $priorities)) {
    $error = 'La prioridad es obligatoria.';
  } elseif (!array_key_exists($status, $statuses)) {
    $error = 'El estado es obligatorio.';
  } elseif (!array_key_exists($related_type, $related_types_map)) {
    $error = 'El tipo relacionado es inválido.';
  } else {
    $assigned_ids = array_values(array_unique(array_filter(array_map('intval', (array)$assigned_user_ids))));
    $assigned_user_ids = $assigned_ids;
    $description = $description === '' ? null : $description;
    $due_date = $due_date === '' ? null : $due_date;
    if ($error === '') {
      if ($assigned_ids) {
        $placeholders = implode(',', array_fill(0, count($assigned_ids), '?'));
        $check = $pdo->prepare("SELECT id FROM users WHERE id IN ({$placeholders}) AND is_active = 1");
        $check->execute($assigned_ids);
        $valid_ids = $check->fetchAll(PDO::FETCH_COLUMN);
        $valid_ids = array_map('intval', $valid_ids);
        sort($valid_ids);
        $requested_ids = $assigned_ids;
        sort($requested_ids);
        if ($valid_ids !== $requested_ids) {
          $error = 'Hay usuarios asignados inválidos.';
        }
      }
    }
    if ($error === '') {
      $status_changed = $status !== $task['status'];
      $st = $pdo->prepare("
        UPDATE tasks
        SET title = ?,
            description = ?,
            category = ?,
            priority = ?,
            status = ?,
            due_date = ?,
            related_type = ?,
            updated_at = NOW()
        WHERE id = ?
      ");
      $st->execute([
        $title,
        $description,
        $category,
        $priority,
        $status,
        $due_date,
        $related_type,
        $task_id,
      ]);
      $pdo->prepare("DELETE FROM task_assignees WHERE task_id = ?")->execute([$task_id]);
      if ($assigned_ids) {
        $assign = $pdo->prepare("
          INSERT IGNORE INTO task_assignees (task_id, user_id, assigned_by_user_id)
          VALUES (?, ?, ?)
        ");
        foreach ($assigned_ids as $user_id) {
          $assign->execute([$task_id, $user_id, $current_user_id ?: null]);
        }
      }
      $redirect_params = [
        'id' => $task_id,
        'saved' => 1,
        'from' => $return_to,
      ];
      if ($status_changed) {
        $redirect_params['status_updated'] = 1;
      }
      redirect('task_view.php?' . http_build_query($redirect_params));
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
    <div class="page-header">
      <div>
        <h2 class="page-title">Detalle de tarea</h2>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="<?= e($return_to) ?>">Volver a <?= e($return_label) ?></a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($status_updated): ?>
    <div class="alert alert-success">Estado actualizado.</div>
  <?php elseif ($saved): ?>
    <div class="alert alert-success">Cambios guardados.</div>
  <?php endif; ?>

  <?php if (!$can_edit): ?>
    <div class="alert alert-warning">
      Solo lectura: la edición está disponible para la persona asignada.
    </div>
  <?php endif; ?>

  <form method="post" class="stack" id="task-update-form">
    <input type="hidden" name="action" value="update">
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Título y descripción</h3>
          <span class="muted small">Editá la información principal de la tarea.</span>
        </div>
      </div>
      <div class="stack">
        <div class="task-detail-item">
          <div class="task-detail-label">Asignados</div>
          <div class="task-detail-value">
            <?php if ($assigned_users): ?>
              <?php foreach ($assigned_users as $user): ?>
                <?php $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                <span class="badge badge-muted">
                  <?= e($user_name !== '' ? $user_name : ($user['email'] ?? '')) ?>
                </span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="muted">Sin asignar</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="task-title">Título</label>
          <input class="form-control" id="task-title" type="text" name="title" value="<?= e($task['title']) ?>" required <?= $can_edit ? '' : 'disabled' ?>>
        </div>
        <div class="form-group">
          <label class="form-label" for="task-description">Descripción</label>
          <textarea class="form-control" id="task-description" name="description" rows="6" <?= $can_edit ? '' : 'disabled' ?>><?= e((string)($task['description'] ?? '')) ?></textarea>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Detalle completo</h3>
          <span class="muted small">Información de estado, relación y auditoría.</span>
        </div>
      </div>
      <div class="stack">
        <div class="task-form-grid">
          <div class="task-form-assignees">
            <div class="form-group">
              <label class="form-label" for="task-assigned">Asignar a</label>
              <select class="form-control" id="task-assigned" name="assigned_user_ids[]" multiple <?= $can_edit ? '' : 'disabled' ?>>
                <option value="" disabled>Seleccionar usuarios</option>
                <?php foreach ($users as $user): ?>
                  <?php
                  $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                  $user_label = $user_name !== '' ? $user_name : ($user['email'] ?? '');
                  ?>
                  <option value="<?= (int)$user['id'] ?>" <?= in_array((int)$user['id'], $assigned_user_ids, true) ? 'selected' : '' ?>>
                    <?= e($user_label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="task-form-fields">
            <div class="form-group">
              <label class="form-label" for="task-category">Categoría</label>
              <select class="form-control" id="task-category" name="category" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($categories as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['category'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-priority">Prioridad</label>
              <select class="form-control" id="task-priority" name="priority" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($priorities as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['priority'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-status">Estado</label>
              <select class="form-control" id="task-status" name="status" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($statuses as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-due-date">Fecha límite</label>
              <input class="form-control" id="task-due-date" type="date" name="due_date" value="<?= e($task['due_date'] ? substr((string)$task['due_date'], 0, 10) : '') ?>" <?= $can_edit ? '' : 'disabled' ?>>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-related-type">Relacionado con</label>
              <select class="form-control" id="task-related-type" name="related_type" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($related_types as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['related_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-3);">
          <div class="task-detail-item">
            <div class="task-detail-label">Creado por</div>
            <div class="task-detail-value"><?= e($creator_name !== '' ? $creator_name : ($task['created_email'] ?? '')) ?></div>
          </div>
          <div class="task-detail-item">
            <div class="task-detail-label">Creada</div>
            <div class="task-detail-value"><?= e($task['created_at']) ?></div>
          </div>
          <div class="task-detail-item">
            <div class="task-detail-label">Actualizada</div>
            <div class="task-detail-value"><?= e($task['updated_at']) ?></div>
          </div>
        </div>
      </div>
    </div>

  </form>

  <?php if ($can_edit || $can_delete_task): ?>
    <div class="form-actions" style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($can_edit): ?>
          <button class="btn" type="submit" form="task-update-form">Guardar cambios</button>
          <a class="btn btn-ghost" href="<?= e($return_to) ?>">Cancelar</a>
        <?php endif; ?>
      </div>
      <?php if ($can_delete_task): ?>
        <div style="flex:1 1 180px; display:flex; justify-content:flex-end;">
          <form method="post" onsubmit="return confirm('¿Eliminar esta tarea? Esta acción no se puede deshacer.');">
            <input type="hidden" name="action" value="delete">
            <?= csrf_field() ?>
            <button class="btn btn-outline-danger" type="submit">Eliminar tarea</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

</body>
</html>
