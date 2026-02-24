<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();
require_permission(hasPerm('tasks_settings'), 'Sin permiso');

$pdo = db();

$sections = [
  'category' => [
    'title' => 'Categorías',
    'table' => 'task_categories',
    'task_field' => 'category',
    'new_label' => 'Nueva categoría',
    'singular' => 'categoría',
  ],
  'relation' => [
    'title' => 'Relacionado con',
    'table' => 'task_relations',
    'task_field' => 'related_type',
    'new_label' => 'Nuevo tipo de relación',
    'singular' => 'tipo de relación',
  ],
];

$messages = [];
$errors = [];

$action = post('action');
$type = post('type');

if (is_post() && isset($sections[$type])) {
  $section = $sections[$type];
  $table = $section['table'];
  $task_field = $section['task_field'];

  if ($action === 'save') {
    $id = (int)post('id', '0');
    $name = trim((string)post('name'));
    $sort_order = (int)post('sort_order', '0');
    $is_active = post('is_active') === '1' ? 1 : 0;

    if ($name === '') {
      $errors[] = 'El nombre es obligatorio.';
    } else {
      $duplicate = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1");
      $duplicate->execute([$name, $id]);
      if ($duplicate->fetch()) {
        $errors[] = 'Ya existe un nombre igual (sin distinguir mayúsculas/minúsculas).';
      }
    }

    if (!$errors) {
      if ($id > 0) {
        $current = $pdo->prepare("SELECT name FROM {$table} WHERE id = ? LIMIT 1");
        $current->execute([$id]);
        $current_row = $current->fetch();
        if (!$current_row) {
          $errors[] = 'No se encontró el registro a editar.';
        } else {
          $old_name = (string)$current_row['name'];
          $update = $pdo->prepare("UPDATE {$table} SET name = ?, sort_order = ?, is_active = ? WHERE id = ?");
          $update->execute([$name, $sort_order, $is_active, $id]);
          if ($old_name !== $name) {
            $update_tasks = $pdo->prepare("UPDATE tasks SET {$task_field} = ? WHERE {$task_field} = ?");
            $update_tasks->execute([$name, $old_name]);
          }
          $messages[] = 'Cambios guardados.';
        }
      } else {
        $insert = $pdo->prepare("INSERT INTO {$table} (name, sort_order, is_active, created_at) VALUES (?, ?, ?, NOW())");
        $insert->execute([$name, $sort_order, $is_active]);
        $messages[] = 'Registro creado.';
      }
    }
  }

  if ($action === 'toggle') {
    $id = (int)post('id', '0');
    $is_active = post('is_active') === '1' ? 1 : 0;
    $update = $pdo->prepare("UPDATE {$table} SET is_active = ? WHERE id = ?");
    $update->execute([$is_active, $id]);
    $messages[] = 'Estado actualizado.';
  }

  if ($action === 'delete') {
    $id = (int)post('id', '0');
    $current = $pdo->prepare("SELECT name FROM {$table} WHERE id = ? LIMIT 1");
    $current->execute([$id]);
    $current_row = $current->fetch();
    if (!$current_row) {
      $errors[] = 'No se encontró el registro a borrar.';
    } else {
      $name = (string)$current_row['name'];
      $count = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE {$task_field} = ?");
      $count->execute([$name]);
      $total = (int)$count->fetchColumn();
      if ($total > 0) {
        $errors[] = 'No se puede borrar porque hay tareas usando esta opción. Desactivala en su lugar.';
      } else {
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $delete->execute([$id]);
        $messages[] = 'Registro eliminado.';
      }
    }
  }
}

$edit_type = get('edit_type');
$edit_id = (int)get('edit_id', '0');
$edit_item = null;
if ($edit_type && isset($sections[$edit_type]) && $edit_id > 0) {
  $table = $sections[$edit_type]['table'];
  $st = $pdo->prepare("SELECT id, name, sort_order, is_active FROM {$table} WHERE id = ? LIMIT 1");
  $st->execute([$edit_id]);
  $edit_item = $st->fetch();
}

function task_settings_fetch(PDO $pdo, string $table): array {
  $st = $pdo->query("SELECT id, name, sort_order, is_active FROM {$table} ORDER BY sort_order ASC, name ASC");
  return $st->fetchAll();
}

$categories = task_settings_fetch($pdo, $sections['category']['table']);
$relations = task_settings_fetch($pdo, $sections['relation']['table']);
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
      <h2 class="page-title">Configuración de tareas</h2>
      <span class="muted">Administrá categorías y tipos de relación.</span>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul>
        <?php foreach ($errors as $error): ?>
          <li><?= e($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($messages): ?>
    <div class="alert alert-success">
      <?php foreach ($messages as $message): ?>
        <div><?= e($message) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="stack">
    <?php foreach ($sections as $section_key => $section): ?>
      <?php
        $items = $section_key === 'category' ? $categories : $relations;
        $is_editing = $edit_type === $section_key && $edit_item;
      ?>
      <div class="card">
        <div class="card-header">
          <div>
            <h3 class="card-title"><?= e($section['title']) ?></h3>
            <span class="muted small">Administrá el orden, estado y nombre.</span>
          </div>
          <div class="inline-actions">
            <a class="btn btn-ghost" href="task_settings.php?edit_type=<?= e($section_key) ?>">
              <?= e($section['new_label']) ?>
            </a>
          </div>
        </div>

        <form method="post" class="stack">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="type" value="<?= e($section_key) ?>">
          <input type="hidden" name="id" value="<?= $is_editing ? (int)$edit_item['id'] : 0 ?>">
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-4);">
            <label class="form-field">
              <span class="form-label">Nombre</span>
              <input class="form-control" type="text" name="name" value="<?= e($is_editing ? (string)$edit_item['name'] : '') ?>" required>
            </label>
            <label class="form-field">
              <span class="form-label">Orden</span>
              <input class="form-control" type="number" name="sort_order" value="<?= e($is_editing ? (string)$edit_item['sort_order'] : '0') ?>">
            </label>
            <label class="form-field">
              <span class="form-label">Activo</span>
              <select class="form-control" name="is_active">
                <option value="1" <?= $is_editing && (int)$edit_item['is_active'] !== 1 ? '' : 'selected' ?>>Sí</option>
                <option value="0" <?= $is_editing && (int)$edit_item['is_active'] !== 1 ? 'selected' : '' ?>>No</option>
              </select>
            </label>
          </div>
          <div class="inline-actions">
            <button class="btn" type="submit"><?= $is_editing ? 'Guardar cambios' : 'Crear' ?></button>
            <?php if ($is_editing): ?>
              <a class="btn btn-ghost" href="task_settings.php">Cancelar</a>
            <?php endif; ?>
          </div>
        </form>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Orden</th>
                <th>Nombre</th>
                <th>Activo</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$items): ?>
                <tr>
                  <td colspan="4" class="muted">No hay registros cargados.</td>
                </tr>
              <?php endif; ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= e((string)$item['sort_order']) ?></td>
                  <td><?= e((string)$item['name']) ?></td>
                  <td>
                    <form method="post">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="type" value="<?= e($section_key) ?>">
                      <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                      <input type="hidden" name="is_active" value="<?= (int)$item['is_active'] === 1 ? '0' : '1' ?>">
                      <button class="btn btn-ghost btn-sm" type="submit">
                        <?= (int)$item['is_active'] === 1 ? 'Activo' : 'Inactivo' ?>
                      </button>
                    </form>
                  </td>
                  <td>
                    <div class="inline-actions">
                      <a class="btn btn-ghost btn-sm" href="task_settings.php?edit_type=<?= e($section_key) ?>&edit_id=<?= (int)$item['id'] ?>">Editar</a>
                      <form method="post" onsubmit="return confirm('¿Seguro que querés eliminar este registro?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="type" value="<?= e($section_key) ?>">
                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">Eliminar</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</main>

</body>
</html>
