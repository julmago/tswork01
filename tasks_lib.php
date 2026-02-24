<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

function task_categories(?string $selected = null, bool $include_inactive = false): array {
  $pdo = db();
  $st = $pdo->query("SELECT name, is_active FROM task_categories ORDER BY sort_order ASC, name ASC");
  $rows = $st->fetchAll();
  $fallback = [
    'deposito' => 'Depósito',
    'publicaciones' => 'Publicaciones',
    'sincronizacion' => 'Sincronización',
    'mantenimiento' => 'Mantenimiento / Orden',
    'administrativo' => 'Administrativo',
    'incidencias' => 'Incidencias',
  ];
  if (!$rows) {
    return $fallback;
  }
  $options = [];
  foreach ($rows as $row) {
    $name = (string)$row['name'];
    $is_active = (int)$row['is_active'] === 1;
    if ($include_inactive || $is_active) {
      $options[$name] = $name;
    }
  }
  if ($selected !== null && $selected !== '' && !array_key_exists($selected, $options)) {
    $selected_label = $selected;
    foreach ($rows as $row) {
      if ((string)$row['name'] === $selected) {
        if ((int)$row['is_active'] !== 1) {
          $selected_label = $selected . ' (inactivo)';
        }
        break;
      }
    }
    $options[$selected] = $selected_label;
  }
  return $options;
}

function task_priorities(): array {
  return [
    'low' => 'Baja',
    'medium' => 'Media',
    'high' => 'Alta',
  ];
}

function task_statuses(): array {
  return [
    'pending' => 'Pendiente',
    'in_progress' => 'En progreso',
    'completed' => 'Completada',
  ];
}

function task_related_types(?string $selected = null, bool $include_inactive = false): array {
  $pdo = db();
  $st = $pdo->query("SELECT name, is_active FROM task_relations ORDER BY sort_order ASC, name ASC");
  $rows = $st->fetchAll();
  $fallback = [
    'list' => 'Listado',
    'product' => 'Producto',
    'general' => 'General',
  ];
  if (!$rows) {
    return $fallback;
  }
  $options = [];
  foreach ($rows as $row) {
    $name = (string)$row['name'];
    $is_active = (int)$row['is_active'] === 1;
    if ($include_inactive || $is_active) {
      $options[$name] = $name;
    }
  }
  if ($selected !== null && $selected !== '' && !array_key_exists($selected, $options)) {
    $selected_label = $selected;
    foreach ($rows as $row) {
      if ((string)$row['name'] === $selected) {
        if ((int)$row['is_active'] !== 1) {
          $selected_label = $selected . ' (inactivo)';
        }
        break;
      }
    }
    $options[$selected] = $selected_label;
  }
  return $options;
}

function task_label(array $map, string $key, string $fallback = ''): string {
  if (isset($map[$key])) {
    return $map[$key];
  }
  return $fallback !== '' ? $fallback : $key;
}

function task_users(PDO $pdo): array {
  $st = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 ORDER BY first_name ASC, last_name ASC");
  return $st->fetchAll();
}

function task_fetch_by_id(PDO $pdo, int $task_id): ?array {
  $st = $pdo->prepare("SELECT id FROM tasks WHERE id = ?");
  $st->execute([$task_id]);
  $task = $st->fetch();
  return $task ?: null;
}

function task_user_is_assignee(PDO $pdo, int $task_id, int $current_user_id): bool {
  $st = $pdo->prepare("SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ? LIMIT 1");
  $st->execute([$task_id, $current_user_id]);
  return (bool)$st->fetchColumn();
}

function task_user_can_edit(PDO $pdo, int $task_id, int $current_user_id): bool {
  if (current_role() === 'superadmin') {
    return true;
  }
  return task_user_is_assignee($pdo, $task_id, $current_user_id);
}

function task_require_ownership(PDO $pdo, int $task_id, int $current_user_id): void {
  if (!task_user_can_edit($pdo, $task_id, $current_user_id)) {
    abort(403, 'No tenés permisos para esta acción');
  }
}

function task_handle_action(PDO $pdo, int $current_user_id, string $redirect_to): void {
  if (!is_post()) {
    return;
  }
  $action = post('action');
  if (!in_array($action, ['update_status', 'reassign'], true)) {
    return;
  }

  $task_id = (int)post('task_id', '0');
  if ($task_id <= 0) {
    abort(400, 'Tarea inválida');
  }
  $task = task_fetch_by_id($pdo, $task_id);
  if (!$task) {
    abort(404, 'Tarea no encontrada');
  }
  task_require_ownership($pdo, (int)$task['id'], $current_user_id);

  if ($action === 'update_status') {
    $status = post('status');
    if (!array_key_exists($status, task_statuses())) {
      abort(400, 'Estado inválido');
    }
    $st = $pdo->prepare("UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?");
    $st->execute([$status, $task_id]);
    redirect($redirect_to);
  }

  if ($action === 'reassign') {
    $new_user_id = (int)post('assigned_user_id', '0');
    if ($new_user_id <= 0) {
      abort(400, 'Usuario inválido');
    }
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
    $check->execute([$new_user_id]);
    if (!$check->fetch()) {
      abort(400, 'Usuario inválido');
    }
    $pdo->prepare("DELETE FROM task_assignees WHERE task_id = ?")->execute([$task_id]);
    $st = $pdo->prepare("
      INSERT IGNORE INTO task_assignees (task_id, user_id, assigned_by_user_id)
      VALUES (?, ?, ?)
    ");
    $st->execute([$task_id, $new_user_id, $current_user_id ?: null]);
    redirect($redirect_to);
  }
}
