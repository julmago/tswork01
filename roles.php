<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_role(['superadmin'], 'Solo superadmin puede administrar roles.');

ensure_roles_defaults();
ensure_superadmin_full_access();

$perm_defaults = permission_default_definitions();
$perm_keys = array_keys($perm_defaults);

$sections = [
  'Menú' => [
    'roles_access' => 'Acceso a Roles',
    'menu_config_prestashop' => 'Config PrestaShop',
    'menu_import_csv' => 'Importar CSV',
    'menu_design' => 'Diseño',
    'menu_new_list' => 'Nuevo listado',
    'menu_new_product' => 'Nuevo producto',
    'tasks_settings' => 'Tareas · Configuración',
  ],
  'Listados' => [
    'list_can_sync' => 'Sincronizar',
    'list_can_delete_item' => 'Eliminar items',
    'list_can_close' => 'Cerrar listado',
    'list_can_open' => 'Abrir/reabrir listado',
    'list_can_scan' => 'Cargar por escaneo',
  ],
  'Productos' => [
    'product_can_edit' => 'Editar producto',
    'product_edit_data' => 'Editar Datos',
    'product_edit_providers' => 'Editar Proveedor',
    'product_edit_stock' => 'Editar Stock',
    'product_stock_pull_prestashop' => '↳ Traer stock desde PrestaShop',
    'product_edit_ml' => 'Editar MercadoLibre',
    'product_can_add_code' => 'Agregar códigos',
    'stock_set' => 'Setear stock',
  ],
  'Tareas' => [
    'tasks_delete' => 'Eliminar tareas',
    'can_delete_messages' => 'Puede eliminar mensajes',
  ],
  'Sitios' => [
    'sites_access' => 'Acceso a Sitios',
    'sites_actions_view' => 'Acciones (columna)',
    'sites_edit' => 'Modificar',
    'sites_test_connection' => 'Probar conexión',
    'sites_bulk_import_export' => 'Importar / Exportar',
  ],
  'Caja' => [
    'cash.view_entries_detail' => 'Ver detalle de entradas',
    'cash.view_exits_detail' => 'Ver detalle de salidas',
    'cash.entries.edit' => 'Modificar entradas',
    'cash.entries.delete' => 'Eliminar entradas',
    'cash.exits.edit' => 'Modificar salidas',
    'cash.exits.delete' => 'Eliminar salidas',
  ],
];

$cashbox_perm_fields = [
  'show' => ['label' => 'Mostrar caja', 'db' => 'can_view', 'master' => true],
  'view_module' => ['label' => 'Ver módulo Caja', 'db' => 'can_open_module'],
  'manage_cashboxes' => ['label' => 'Administrar cajas', 'db' => 'can_manage_cashboxes'],
  'view_balance' => ['label' => 'Ver balance', 'db' => 'can_view_balance'],
  'create_entries' => ['label' => 'Crear entradas', 'db' => 'can_create_entries'],
  'create_exits' => ['label' => 'Crear salidas', 'db' => 'can_create_exits'],
  'configure_bills' => ['label' => 'Configurar billetes', 'db' => 'can_configure_bills'],
];

$message = '';
$error = '';
$create_role_key = '';
$create_role_name = '';

if (is_post() && post('action') === 'create_role') {
  $create_role_key = trim((string)post('role_key'));
  $create_role_name = trim((string)post('role_name'));
  if ($create_role_key === 'superadmin') {
    $error = 'El rol superadmin no se puede crear.';
  } elseif (!preg_match('/^[a-z0-9_]{3,32}$/', $create_role_key)) {
    $error = 'El ID debe tener entre 3 y 32 caracteres (a-z, 0-9, _).';
  } elseif ($create_role_name === '') {
    $error = 'El nombre visible es obligatorio.';
  } elseif (mb_strlen($create_role_name) > 60) {
    $error = 'El nombre visible debe tener hasta 60 caracteres.';
  } else {
    $st = db()->prepare("SELECT COUNT(*) FROM roles WHERE role_key = ?");
    $st->execute([$create_role_key]);
    $exists = (int)$st->fetchColumn() > 0;
    if ($exists) {
      $error = 'Ya existe un rol con ese ID.';
    } else {
      try {
        db()->beginTransaction();
        $st = db()->prepare("INSERT INTO roles (role_key, role_name, is_system) VALUES (?, ?, 0)");
        $st->execute([$create_role_key, $create_role_name]);

        $perm_st = db()->prepare("INSERT IGNORE INTO role_permissions(role_key, perm_key, perm_value) VALUES(?, ?, ?)");
        foreach ($perm_keys as $perm_key) {
          $perm_st->execute([$create_role_key, $perm_key, 0]);
        }
        db()->commit();
        $message = 'Rol creado.';
        $create_role_key = '';
        $create_role_name = '';
      } catch (Throwable $t) {
        if (db()->inTransaction()) {
          db()->rollBack();
        }
        $error = 'No se pudo crear el rol.';
      }
    }
  }
}

if (is_post() && post('action') === 'delete_role') {
  $role_key = (string)post('role_key');
  if ($role_key === 'superadmin') {
    $error = 'El rol superadmin no se puede eliminar.';
  } else {
    $st = db()->prepare("SELECT role_key, is_system FROM roles WHERE role_key = ? LIMIT 1");
    $st->execute([$role_key]);
    $role_row = $st->fetch();
    if (!$role_row) {
      $error = 'Rol inválido.';
    } elseif ((int)$role_row['is_system'] === 1) {
      $error = 'Solo se pueden eliminar roles personalizados.';
    } else {
      $st = db()->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
      $st->execute([$role_key]);
      $count = (int)$st->fetchColumn();
      if ($count > 0) {
        $error = "Hay {$count} usuarios con este rol. Reasignalos antes de eliminar.";
      } else {
        try {
          db()->beginTransaction();
          $st = db()->prepare("DELETE FROM role_permissions WHERE role_key = ?");
          $st->execute([$role_key]);
          $st = db()->prepare("DELETE FROM role_cashbox_permissions WHERE role_key = ?");
          $st->execute([$role_key]);
          $st = db()->prepare("DELETE FROM roles WHERE role_key = ?");
          $st->execute([$role_key]);
          db()->commit();
          $message = 'Rol eliminado.';
        } catch (Throwable $t) {
          if (db()->inTransaction()) {
            db()->rollBack();
          }
          $error = 'No se pudo eliminar el rol.';
        }
      }
    }
  }
}

if (is_post() && post('action') === 'save_role') {
  $role_key = (string)post('role_key');
  $st = db()->prepare("SELECT role_key FROM roles WHERE role_key = ? LIMIT 1");
  $st->execute([$role_key]);
  $role_row = $st->fetch();
  if (!$role_row) {
    $error = 'Rol inválido.';
  } elseif ($role_key === 'superadmin') {
    ensure_superadmin_full_access();
    $error = 'El rol superadmin no es editable.';
  } else {
    $role_name = trim(post('role_name'));
    if ($role_name === '') {
      $error = 'El nombre visible es obligatorio.';
    } elseif (mb_strlen($role_name) > 60) {
      $error = 'El nombre visible debe tener hasta 60 caracteres.';
    } else {
      try {
        db()->beginTransaction();
        $st = db()->prepare("UPDATE roles SET role_name = ? WHERE role_key = ?");
        $st->execute([$role_name, $role_key]);

        foreach ($perm_keys as $perm_key) {
          $input_key = 'perm_' . str_replace('.', '_', $perm_key);
          $value = post($input_key) === '1' ? 1 : 0;
          $st = db()->prepare("INSERT INTO role_permissions(role_key, perm_key, perm_value) VALUES(?, ?, ?)
            ON DUPLICATE KEY UPDATE perm_value = VALUES(perm_value)");
          $st->execute([$role_key, $perm_key, $value]);
        }

        $cashboxes = db()->query("SELECT id FROM cashboxes ORDER BY name ASC")->fetchAll();
        if ($cashboxes) {
          $cash_perm = $_POST['cash_perm'] ?? [];
          if (!is_array($cash_perm)) {
            $cash_perm = [];
          }
          $st = db()->prepare("DELETE FROM role_cashbox_permissions WHERE role_key = ?");
          $st->execute([$role_key]);

          $db_columns = array_map(static fn($field) => $field['db'], $cashbox_perm_fields);
          $column_list = implode(', ', $db_columns);
          $placeholders = implode(', ', array_fill(0, count($db_columns), '?'));
          $cashbox_st = db()->prepare(
            "INSERT INTO role_cashbox_permissions (role_key, cashbox_id, {$column_list}) VALUES (?, ?, {$placeholders})"
          );

          foreach ($cashboxes as $cashbox) {
            $cashbox_id = (int)$cashbox['id'];
            $cashbox_input = $cash_perm[$cashbox_id] ?? [];
            if (!is_array($cashbox_input)) {
              $cashbox_input = [];
            }
            $values = [];
            $can_view = !empty($cashbox_input['show']) ? 1 : 0;
            foreach ($cashbox_perm_fields as $perm_key => $field) {
              $value = !empty($cashbox_input[$perm_key]) ? 1 : 0;
              if ($can_view === 0 && $perm_key !== 'show') {
                $value = 0;
              }
              $values[] = $value;
            }
            $cashbox_st->execute(array_merge([$role_key, $cashbox_id], $values));
          }
        }
        db()->commit();
        $message = 'Permisos guardados.';
      } catch (Throwable $t) {
        if (db()->inTransaction()) {
          db()->rollBack();
        }
        $error = 'No se pudieron guardar los cambios.';
      }
    }
  }
}

$st = db()->query("SELECT role_key, role_name, is_system FROM roles ORDER BY is_system DESC, role_name ASC");
$roles = $st->fetchAll();
$role_keys = array_map(static fn($role) => $role['role_key'], $roles);

$cashboxes = db()->query("SELECT id, name FROM cashboxes ORDER BY name ASC")->fetchAll();

$perm_map = [];
if ($role_keys) {
  $placeholders = implode(',', array_fill(0, count($role_keys), '?'));
  $st = db()->prepare("SELECT role_key, perm_key, perm_value FROM role_permissions WHERE role_key IN ({$placeholders})");
  $st->execute($role_keys);
  $perm_rows = $st->fetchAll();
  foreach ($perm_rows as $row) {
    $perm_map[$row['role_key']][$row['perm_key']] = (bool)$row['perm_value'];
  }
}

foreach ($role_keys as $role_key) {
  foreach ($perm_keys as $perm_key) {
    if (!isset($perm_map[$role_key][$perm_key])) {
      $perm_map[$role_key][$perm_key] = !empty($perm_defaults[$perm_key][$role_key]);
    }
  }
}

$cashbox_perm_map = [];
if ($role_keys) {
  $placeholders = implode(',', array_fill(0, count($role_keys), '?'));
  $st = db()->prepare(
    "SELECT role_key, cashbox_id, can_view, can_open_module, can_manage_cashboxes, can_view_balance,
      can_create_entries, can_create_exits, can_configure_bills
     FROM role_cashbox_permissions WHERE role_key IN ({$placeholders})"
  );
  $st->execute($role_keys);
  $cashbox_rows = $st->fetchAll();
  foreach ($cashbox_rows as $row) {
    $role_key = $row['role_key'];
    $cashbox_id = (int)$row['cashbox_id'];
    $cashbox_perm_map[$role_key][$cashbox_id] = [
      'show' => (bool)$row['can_view'],
      'view_module' => (bool)$row['can_open_module'],
      'manage_cashboxes' => (bool)$row['can_manage_cashboxes'],
      'view_balance' => (bool)$row['can_view_balance'],
      'create_entries' => (bool)$row['can_create_entries'],
      'create_exits' => (bool)$row['can_create_exits'],
      'configure_bills' => (bool)$row['can_configure_bills'],
    ];
  }
}

foreach ($role_keys as $role_key) {
  foreach ($cashboxes as $cashbox) {
    $cashbox_id = (int)$cashbox['id'];
    if (!isset($cashbox_perm_map[$role_key][$cashbox_id])) {
      $cashbox_perm_map[$role_key][$cashbox_id] = array_fill_keys(array_keys($cashbox_perm_fields), false);
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
  <style>
    .cashbox-perm-disabled {
      opacity: 0.5;
      pointer-events: none;
    }
    .role-accordion {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .role-accordion-item {
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      background: rgba(15, 15, 15, 0.6);
      overflow: hidden;
    }
    .role-accordion-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 14px 18px;
    }
    .role-accordion-toggle {
      flex: 1;
      min-width: 0;
      display: flex;
      align-items: center;
      gap: 16px;
      background: transparent;
      border: none;
      color: inherit;
      text-align: left;
      cursor: pointer;
    }
    .role-accordion-toggle:focus-visible {
      outline: 2px solid rgba(255, 255, 255, 0.25);
      outline-offset: -2px;
    }
    .role-accordion-title {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .role-accordion-role {
      font-size: 1.05rem;
      font-weight: 600;
    }
    .role-accordion-meta {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.9rem;
      color: rgba(255, 255, 255, 0.7);
    }
    .role-accordion-actions {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-left: auto;
    }
    .role-accordion-caret {
      transition: transform 0.2s ease;
      font-size: 0.85rem;
      opacity: 0.8;
    }
    .role-accordion-item.is-open .role-accordion-caret {
      transform: rotate(90deg);
    }
    .role-accordion-panel {
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      padding: 16px 18px 18px;
      overflow: hidden;
      max-height: 0;
      opacity: 0;
      transition: max-height 0.25s ease, opacity 0.25s ease;
    }
    .role-accordion-item.is-open .role-accordion-panel {
      opacity: 1;
    }
  </style>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Roles y permisos</h2>
      <span class="muted">Solo superadmin puede editar.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card stack" style="margin-bottom:16px;">
      <div class="card-header">
        <h3 class="card-title">Crear rol</h3>
      </div>
      <form method="post" action="roles.php" class="stack">
        <input type="hidden" name="action" value="create_role">
        <div class="form-row">
          <div class="form-group">
            <div class="form-label-row">
              <label class="form-label" for="role_key">ID (role_key)</label>
              <span class="form-help muted">3-32 caracteres, solo a-z, 0-9 y _</span>
            </div>
            <input class="form-control" id="role_key" type="text" name="role_key" value="<?= e($create_role_key) ?>" required maxlength="32" pattern="[a-z0-9_]{3,32}">
          </div>
          <div class="form-group">
            <label class="form-label">Nombre visible</label>
            <input class="form-control" type="text" name="role_name" value="<?= e($create_role_name) ?>" required maxlength="60">
          </div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Crear</button>
        </div>
      </form>
    </div>

    <div class="role-accordion" data-role-accordion>
    <?php foreach ($roles as $role): ?>
      <?php
        $role_key = (string)$role['role_key'];
        $is_system = (bool)$role['is_system'];
        $is_locked = $role_key === 'superadmin';
        $can_delete = !$is_system && $role_key !== 'superadmin';
      ?>
      <div class="role-accordion-item" data-role="<?= e($role_key) ?>">
        <div class="role-accordion-header">
          <button
            type="button"
            class="role-accordion-toggle"
            aria-expanded="false"
            aria-controls="panel-<?= e($role_key) ?>"
            id="hdr-<?= e($role_key) ?>"
          >
            <div class="role-accordion-title">
              <span class="role-accordion-caret" aria-hidden="true">▶</span>
              <span class="role-accordion-role"><?= e($role['role_name']) ?></span>
              <span class="role-accordion-meta">ID: <?= e($role_key) ?></span>
              <?php if ($is_system): ?>
                <span class="badge badge-muted">Sistema</span>
              <?php endif; ?>
            </div>
          </button>
          <div class="role-accordion-actions">
            <?php if ($can_delete): ?>
              <form method="post" action="roles.php" class="no-toggle" onsubmit="return confirm('¿Eliminar rol <?= e($role_key) ?>?');">
                <input type="hidden" name="action" value="delete_role">
                <input type="hidden" name="role_key" value="<?= e($role_key) ?>">
                <button class="btn btn-danger no-toggle" type="submit">Eliminar</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div
          id="panel-<?= e($role_key) ?>"
          class="role-accordion-panel"
          role="region"
          aria-labelledby="hdr-<?= e($role_key) ?>"
          hidden
        >
          <form method="post" action="roles.php" class="stack">
          <input type="hidden" name="action" value="save_role">
          <input type="hidden" name="role_key" value="<?= e($role_key) ?>">

          <div class="form-row">
            <div><strong>ID:</strong> <?= e($role_key) ?></div>
            <div class="form-group">
              <label class="form-label">Nombre visible</label>
              <input class="form-control" type="text" name="role_name" value="<?= e($role['role_name']) ?>" required <?= $is_locked ? 'disabled' : '' ?>>
            </div>
          </div>

          <?php foreach ($sections as $title => $perm_list): ?>
            <div class="card" style="padding:16px;">
              <strong><?= e($title) ?></strong>
              <div class="form-row" style="flex-wrap:wrap;">
                <?php foreach ($perm_list as $perm_key => $label): ?>
                  <?php
                    if ($perm_key === 'tasks_delete' && $is_system && !$is_locked) {
                      continue;
                    }
                    $checked = !empty($perm_map[$role_key][$perm_key]);
                  ?>
                  <?php
                    $product_master_attr = '';
                    if ($title === 'Productos') {
                      if ($perm_key === 'product_can_edit') {
                        $product_master_attr = 'data-product-master';
                      } elseif (in_array($perm_key, ['product_edit_data', 'product_edit_providers', 'product_edit_stock', 'product_stock_pull_prestashop', 'product_edit_ml'], true)) {
                        $product_master_attr = 'data-product-secondary';
                      }
                    }
                  ?>
                  <label class="form-check" style="min-width:220px;">
                    <input type="checkbox" name="perm_<?= e(str_replace('.', '_', $perm_key)) ?>" value="1" <?= $checked ? 'checked' : '' ?> <?= $product_master_attr ?> <?= $is_locked ? 'disabled' : '' ?>>
                    <span><?= e($label) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if ($cashboxes): ?>
            <div class="card stack" style="padding:16px;">
              <strong>Caja</strong>
              <?php foreach ($cashboxes as $cashbox): ?>
                <?php
                  $cashbox_id = (int)$cashbox['id'];
                  $cashbox_name = (string)$cashbox['name'];
                  $cashbox_perms = $cashbox_perm_map[$role_key][$cashbox_id] ?? array_fill_keys(array_keys($cashbox_perm_fields), false);
                ?>
                <div class="card" style="padding:12px;" data-cashbox-permissions>
                  <strong><?= e("Caja: {$cashbox_name}") ?></strong>
                  <div class="form-row" style="flex-wrap:wrap; margin-top:8px;">
                    <?php foreach ($cashbox_perm_fields as $perm_key => $field): ?>
                      <?php
                        $checked = !empty($cashbox_perms[$perm_key]);
                        $is_master = !empty($field['master']);
                      ?>
                      <label class="form-check" style="min-width:220px;">
                        <input
                          type="checkbox"
                          name="cash_perm[<?= e((string)$cashbox_id) ?>][<?= e($perm_key) ?>]"
                          value="1"
                          <?= $checked ? 'checked' : '' ?>
                          <?= $is_master ? 'data-cashbox-master' : 'data-cashbox-secondary' ?>
                          <?= $is_locked ? 'disabled' : '' ?>
                        >
                        <span><?= e($field['label']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="form-actions">
            <?php if ($is_locked): ?>
              <span class="muted">Superadmin no es editable.</span>
            <?php else: ?>
              <button class="btn" type="submit">Guardar cambios</button>
            <?php endif; ?>
          </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</main>
<script>
  (() => {
    const sections = document.querySelectorAll('[data-cashbox-permissions]');
    const sync = (section) => {
      const master = section.querySelector('[data-cashbox-master]');
      if (!master || master.disabled) {
        return;
      }
      const enabled = master.checked;
      section.querySelectorAll('[data-cashbox-secondary]').forEach((input) => {
        if (!enabled) {
          input.checked = false;
        }
        const label = input.closest('label');
        if (label) {
          label.classList.toggle('cashbox-perm-disabled', !enabled);
        }
      });
    };
    sections.forEach((section) => {
      const master = section.querySelector('[data-cashbox-master]');
      if (master) {
        master.addEventListener('change', () => sync(section));
      }
      sync(section);
    });
  })();
</script>
<script>

  (() => {
    const forms = document.querySelectorAll('.role-accordion-panel form');
    const sync = (form) => {
      const master = form.querySelector('[data-product-master]');
      if (!master || master.disabled) {
        return;
      }
      const enabled = master.checked;
      form.querySelectorAll('[data-product-secondary]').forEach((input) => {
        input.disabled = !enabled;
        const label = input.closest('label');
        if (label) {
          label.classList.toggle('cashbox-perm-disabled', !enabled);
        }
      });
    };

    forms.forEach((form) => {
      const master = form.querySelector('[data-product-master]');
      if (!master) {
        return;
      }
      master.addEventListener('change', () => sync(form));
      sync(form);
    });
  })();

  (() => {
    const accordion = document.querySelector('[data-role-accordion]');
    if (!accordion) {
      return;
    }
    const items = Array.from(accordion.querySelectorAll('.role-accordion-item'));
    const headers = items.map((item) => ({
      item,
      header: item.querySelector('.role-accordion-toggle'),
      panel: item.querySelector('.role-accordion-panel'),
      roleKey: item.dataset.role,
    }));

    const closeItem = (entry) => {
      if (!entry) {
        return;
      }
      entry.item.classList.remove('is-open');
      entry.header.setAttribute('aria-expanded', 'false');
      entry.panel.style.maxHeight = '0px';
      entry.panel.addEventListener(
        'transitionend',
        () => {
          if (!entry.item.classList.contains('is-open')) {
            entry.panel.hidden = true;
          }
        },
        { once: true }
      );
    };

    const openItem = (entry) => {
      if (!entry) {
        return;
      }
      headers.forEach((other) => {
        if (other !== entry) {
          closeItem(other);
        }
      });
      entry.panel.hidden = false;
      entry.item.classList.add('is-open');
      entry.header.setAttribute('aria-expanded', 'true');
      requestAnimationFrame(() => {
        entry.panel.style.maxHeight = `${entry.panel.scrollHeight}px`;
      });
      if (entry.roleKey) {
        localStorage.setItem('lastOpenRole', entry.roleKey);
      }
    };

    headers.forEach((entry) => {
      if (!entry.header || !entry.panel) {
        return;
      }
      entry.header.addEventListener('click', (event) => {
        if (event.target && event.target.closest('.no-toggle')) {
          return;
        }
        const isOpen = entry.item.classList.contains('is-open');
        if (isOpen) {
          closeItem(entry);
          localStorage.removeItem('lastOpenRole');
        } else {
          openItem(entry);
        }
      });
    });

    const params = new URLSearchParams(window.location.search);
    const paramRole = params.get('open');
    const storedRole = localStorage.getItem('lastOpenRole');
    const initialRole = paramRole || storedRole;
    if (initialRole) {
      const match = headers.find((entry) => entry.roleKey === initialRole);
      if (match) {
        openItem(match);
      }
    }
  })();
</script>
</body>
</html>
