<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$u = current_user();
$is_superadmin = ($u['role'] ?? '') === 'superadmin';
$can_manage_tasks_settings = hasPerm('tasks_settings');
$can_manage_prestashop = hasPerm('menu_config_prestashop');
$can_view_design = hasPerm('menu_design');
$can_cashbox_manage = hasAnyCashboxPerm('can_manage_cashboxes');
$show_config_menu = $can_manage_tasks_settings || $can_manage_prestashop || $can_view_design || $can_cashbox_manage || $is_superadmin;
?>
<header class="topbar">
  <div class="container topbar-content">
    <div class="brand">
      <span class="brand-title">TS WORK</span>
    </div>
    <nav class="nav">
      <a class="nav-link" href="dashboard.php">Listas</a>
      <?php if (can_create_list()): ?>
        <a class="nav-link" href="list_new.php">Nuevo Listado</a>
      <?php endif; ?>
      <?php if (can_create_product()): ?>
        <a class="nav-link" href="product_new.php">Nuevo Producto</a>
      <?php endif; ?>
      <a class="nav-link" href="product_list.php">Listado de productos</a>
      <a class="nav-link" href="tasks_all.php">Tareas</a>
      <?php if (can_import_csv()): ?>
        <a class="nav-link" href="product_import.php">Importar CSV</a>
      <?php endif; ?>
      <?php if ($show_config_menu): ?>
        <div class="config-menu" data-config-menu>
          <button class="config-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
            Config <span aria-hidden="true">▾</span>
          </button>
          <div class="config-menu-dropdown" role="menu">
            <?php if ($can_manage_tasks_settings): ?>
              <a class="config-menu-item" href="task_settings.php" role="menuitem">Tareas · Configuración</a>
            <?php endif; ?>
            <?php if ($can_manage_prestashop): ?>
              <a class="config-menu-item" href="ps_config.php" role="menuitem">Config PrestaShop</a>
            <?php endif; ?>
            <?php if ($can_view_design): ?>
              <a class="config-menu-item" href="design.php" role="menuitem">Diseño</a>
            <?php endif; ?>
            <?php if ($is_superadmin): ?>
              <a class="config-menu-item" href="roles.php" role="menuitem">Roles</a>
            <?php endif; ?>
            <?php if ($can_cashbox_manage): ?>
              <a class="config-menu-item" href="cash_manage.php" role="menuitem">Administrar cajas</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </nav>
    <div class="topbar-user">
      <span class="muted small">
        <?= e($u['first_name'] . ' ' . $u['last_name']) ?> · <?= e($u['email']) ?>
      </span>
      <a class="btn btn-ghost" href="logout_profile.php">Cerrar perfil</a>
      <a class="btn btn-ghost" href="logout.php">Salir del sistema</a>
    </div>
  </div>
</header>
<script>
  (() => {
    const menu = document.querySelector('[data-config-menu]');
    if (!menu) return;
    const button = menu.querySelector('.config-menu-button');
    const close = () => {
      menu.classList.remove('config-menu--open');
      button?.setAttribute('aria-expanded', 'false');
    };
    const toggle = () => {
      const isOpen = menu.classList.toggle('config-menu--open');
      button?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };
    button?.addEventListener('click', (event) => {
      event.stopPropagation();
      toggle();
    });
    menu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', close);
    });
    document.addEventListener('click', (event) => {
      if (!menu.contains(event.target)) {
        close();
      }
    });
  })();
</script>
