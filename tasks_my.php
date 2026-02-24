<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();

$pdo = db();
$current_user_id = (int)(current_user()['id'] ?? 0);

$category_options = task_categories();
$category_labels = task_categories(null, true);
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types(null, true);
$priority_badges = [
  'low' => 'badge-muted',
  'medium' => 'badge-warning',
  'high' => 'badge-danger',
];
$status_badges = [
  'pending' => 'badge-muted',
  'in_progress' => 'badge-warning',
  'completed' => 'badge-success',
];

$category = get('category');
$status = get('status');
$view = get('view', 'my');
$message = get('message');
$success_message = $message === 'updated' ? 'Tarea actualizada.' : '';

$allowed_views = ['my', 'my_pending', 'my_progress', 'my_all'];
if (!in_array($view, $allowed_views, true)) {
  $view = 'my';
}

$where = ['ta.user_id = ?'];
$params = [$current_user_id];
if ($view === 'my') {
  $where[] = "t.status <> 'completed'";
} elseif ($view === 'my_pending') {
  $where[] = "t.status = 'pending'";
} elseif ($view === 'my_progress') {
  $where[] = "t.status = 'in_progress'";
}
if ($category !== '' && array_key_exists($category, $category_options)) {
  $where[] = 't.category = ?';
  $params[] = $category;
}
if ($status !== '' && array_key_exists($status, $statuses)) {
  $where[] = 't.status = ?';
  $params[] = $status;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);
$st = $pdo->prepare("
  SELECT t.*,
         cu.first_name AS created_first_name, cu.last_name AS created_last_name,
         cu.email AS created_email
  FROM tasks t
  JOIN task_assignees ta ON ta.task_id = t.id
  JOIN users cu ON cu.id = t.created_by_user_id
  {$where_sql}
  ORDER BY t.created_at DESC
  LIMIT 300
");
$st->execute($params);
$tasks = $st->fetchAll();
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
        <h2 class="page-title">Mis tareas</h2>
        <span class="muted">Gestioná el estado y las asignaciones de tus tareas.</span>
      </div>
      <div class="inline-actions">
        <a class="btn" href="task_new.php">+ Crear tarea</a>
      </div>
    </div>

    <?php if ($success_message): ?>
      <div class="alert alert-success"><?= e($success_message) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Tareas asignadas a mí</h3>
        </div>
        <div class="inline-actions">
          <a class="btn btn-ghost" href="tasks_all.php">Todas las tareas</a>
          <a class="btn btn-ghost" href="tasks_my.php">Mis tareas</a>
        </div>
      </div>

      <div class="inline-actions" style="padding: 0 var(--space-4) var(--space-3); flex-wrap: wrap;">
        <?php
        $base_params = array_filter([
          'category' => $category !== '' ? $category : null,
          'status' => $status !== '' ? $status : null,
        ], static fn($value) => $value !== null);
        $views = [
          'my' => 'Mis tareas',
          'my_pending' => 'Mis tareas pendientes',
          'my_progress' => 'Mis tareas en progreso',
          'my_all' => 'Todas mis tareas',
        ];
        ?>
        <?php foreach ($views as $key => $label): ?>
          <?php $link = 'tasks_my.php?' . http_build_query(array_merge($base_params, ['view' => $key])); ?>
          <a class="btn <?= $view === $key ? '' : 'btn-ghost' ?>" href="<?= e($link) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>

      <form method="get" action="tasks_my.php" class="stack">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="filters-grid">
          <label class="form-field">
            <span class="form-label">Categoría</span>
            <select class="form-control" name="category">
              <option value="">Todas</option>
              <?php foreach ($category_options as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="form-field">
            <span class="form-label">Estado</span>
            <select class="form-control" name="status">
              <option value="">Todos</option>
              <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="filters-actions">
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn btn-ghost" href="tasks_my.php">Limpiar</a>
          </div>
        </div>
      </form>

      <div class="table-wrap">
        <table class="table task-table">
          <thead>
            <tr>
              <th class="col-task">Tarea</th>
              <th class="col-short">Categoría</th>
              <th class="col-short">Prioridad</th>
              <th class="col-short">Estado</th>
              <th class="col-short">Vence</th>
              <th class="col-short">Relación</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$tasks): ?>
              <tr>
                <td colspan="6" class="muted">No tenés tareas asignadas.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($tasks as $task): ?>
              <?php
                $related_label = task_label($related_types, (string)$task['related_type']);
                $priority_class = $priority_badges[$task['priority']] ?? 'badge-muted';
                $status_class = $status_badges[$task['status']] ?? 'badge-muted';
                $task_url = 'task_view.php?id=' . (int)$task['id'] . '&from=tasks_my.php';
              ?>
              <tr>
                <td class="col-task">
                  <div class="task-title">
                    <a class="task-title-link" href="<?= e($task_url) ?>"><?= e($task['title']) ?></a>
                  </div>
                  <?php if (!empty($task['description'])): ?>
                    <div class="muted small task-desc"><?= e($task['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="col-short">
                  <span class="badge badge-muted"><?= e(task_label($category_labels, (string)$task['category'])) ?></span>
                </td>
                <td class="col-short">
                  <span class="badge <?= e($priority_class) ?>"><?= e(task_label($priorities, (string)$task['priority'])) ?></span>
                </td>
                <td class="col-short">
                  <span class="badge <?= e($status_class) ?>"><?= e(task_label($statuses, (string)$task['status'])) ?></span>
                </td>
                <td class="col-short"><?= $task['due_date'] ? e($task['due_date']) : '<span class="muted">-</span>' ?></td>
                <td class="col-short">
                  <span class="badge badge-muted"><?= e($related_label) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="task-cards">
        <?php if (!$tasks): ?>
          <div class="muted">No tenés tareas asignadas.</div>
        <?php endif; ?>
        <?php foreach ($tasks as $task): ?>
          <?php
            $related_label = task_label($related_types, (string)$task['related_type']);
            $priority_class = $priority_badges[$task['priority']] ?? 'badge-muted';
            $status_class = $status_badges[$task['status']] ?? 'badge-muted';
            $task_url = 'task_view.php?id=' . (int)$task['id'] . '&from=tasks_my.php';
          ?>
          <article class="task-card">
            <div>
              <div class="task-title">
                <a class="task-title-link" href="<?= e($task_url) ?>"><?= e($task['title']) ?></a>
              </div>
              <?php if (!empty($task['description'])): ?>
                <div class="muted small task-desc"><?= e($task['description']) ?></div>
              <?php endif; ?>
            </div>
            <div class="task-card__meta">
              <div class="task-card__meta-row">
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Categoría</span>
                  <span class="badge badge-muted"><?= e(task_label($category_labels, (string)$task['category'])) ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Prioridad</span>
                  <span class="badge <?= e($priority_class) ?>"><?= e(task_label($priorities, (string)$task['priority'])) ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Estado</span>
                  <span class="badge <?= e($status_class) ?>"><?= e(task_label($statuses, (string)$task['status'])) ?></span>
                </div>
              </div>
              <div class="task-card__meta-row">
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Vence</span>
                  <span><?= $task['due_date'] ? e($task['due_date']) : '-' ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Relación</span>
                  <span class="badge badge-muted"><?= e($related_label) ?></span>
                </div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
</main>

</body>
</html>
