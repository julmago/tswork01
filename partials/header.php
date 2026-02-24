<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
$u = current_user();
$role = $u['role'] ?? '';
$is_superadmin = $role === 'superadmin';
$can_manage_tasks_settings = hasPerm('tasks_settings');
$can_manage_prestashop = hasPerm('menu_config_prestashop');
$can_view_design = hasPerm('menu_design');
$can_cashbox_access = false;
$can_cashbox_manage = hasPerm('cashbox_manage_boxes');
$show_config_menu = $can_manage_tasks_settings || $can_manage_prestashop || $can_view_design || $can_cashbox_manage || $is_superadmin;
$display_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
if ($display_name === '') {
  $display_name = $u['email'] ?? 'Usuario';
}


$pdo = db();
$current_user_id = (int)($u['id'] ?? 0);
$unread_notifications_count = 0;
$st_unread_notifications = $pdo->prepare('SELECT COUNT(*) FROM ts_notifications WHERE user_id = ? AND is_read = 0');
$st_unread_notifications->execute([$current_user_id]);
$unread_notifications_count = (int)$st_unread_notifications->fetchColumn();

$st_last_tasks_seen = $pdo->prepare('SELECT last_tasks_seen_at FROM users WHERE id = ? LIMIT 1');
$st_last_tasks_seen->execute([$current_user_id]);
$last_tasks_seen_at = (string)($st_last_tasks_seen->fetchColumn() ?: '');
if ($last_tasks_seen_at === '') {
  $last_tasks_seen_at = '1970-01-01 00:00:00';
}
$st_new_tasks = $pdo->prepare("
  SELECT COUNT(DISTINCT t.id)
  FROM tasks t
  JOIN task_assignees ta ON ta.task_id = t.id
  WHERE ta.user_id = ?
    AND t.created_at > ?
    AND t.status <> 'completed'
");
$st_new_tasks->execute([$current_user_id, $last_tasks_seen_at]);
$new_tasks_count = (int)$st_new_tasks->fetchColumn();

$cashboxes = [];
$active_cashbox_id = 0;
if ($is_superadmin || hasAnyCashboxPerm('can_view')) {
  require_once __DIR__ . '/../cash_helpers.php';
  $cashboxes = getAllowedCashboxes(db(), $u);
  $active_cashbox_id = cashbox_selected_id();
}
$can_cashbox_access = !empty($cashboxes);
?>
<header class="topbar">
  <div class="container topbar-content">
    <div class="topbar-left">
      <a class="brand" href="<?= url_path('dashboard.php') ?>">
        <span class="brand-title">TS WORK</span>
      </a>
      <nav class="nav nav-primary">
        <a class="nav-link" href="<?= url_path('dashboard.php') ?>">Listas</a>
        <a class="nav-link" href="<?= url_path('product_list.php') ?>">Productos</a>
        <a class="nav-link" href="<?= url_path('sites.php') ?>">Sitios</a>
        <a class="nav-link" href="<?= url_path('tasks_all.php') ?>">Tarea<?php if ($new_tasks_count > 0): ?> <span class="badge badge-danger"><?= (int)$new_tasks_count ?></span><?php endif; ?></a>
        <a class="nav-link" href="<?= url_path('inbox.php') ?>">Mensajería<?php if ($unread_notifications_count > 0): ?> <span class="badge badge-danger"><?= (int)$unread_notifications_count ?></span><?php endif; ?></a>
        <?php if ($can_cashbox_access): ?>
          <div class="cash-menu" data-cash-menu>
            <button class="cash-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
              Caja <span aria-hidden="true">▾</span>
            </button>
            <div class="cash-menu-dropdown" role="menu">
              <?php $cash_redirect = url_path('cash_select.php'); ?>
              <?php foreach ($cashboxes as $cashbox): ?>
                <?php
                  $cashbox_id = (int)$cashbox['id'];
                  $is_active = $cashbox_id === $active_cashbox_id;
                ?>
                <a class="cash-menu-item<?= $is_active ? ' cash-menu-item--active' : '' ?>"
                   href="<?= url_path('cash_set.php?id=' . $cashbox_id . '&redirect=' . rawurlencode($cash_redirect)) ?>"
                   role="menuitem">
                  <?= e($cashbox['name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </nav>
    </div>
    <div class="topbar-center" aria-hidden="true"></div>
    <div class="topbar-right">
      <nav class="nav nav-utility">
        <?php if ($show_config_menu): ?>
          <div class="config-menu" data-config-menu>
            <button class="config-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
              Config <span aria-hidden="true">▾</span>
            </button>
            <div class="config-menu-dropdown" role="menu">
              <?php if ($can_manage_tasks_settings): ?>
                <a class="config-menu-item" href="<?= url_path('task_settings.php') ?>" role="menuitem">Tareas · Configuración</a>
              <?php endif; ?>
              <?php if ($can_manage_prestashop): ?>
                <a class="config-menu-item" href="<?= url_path('ps_config.php') ?>" role="menuitem">Config PrestaShop</a>
              <?php endif; ?>
              <?php if ($can_view_design): ?>
                <a class="config-menu-item" href="<?= url_path('design.php') ?>" role="menuitem">Diseño</a>
              <?php endif; ?>
              <?php if ($is_superadmin): ?>
                <a class="config-menu-item" href="<?= url_path('roles.php') ?>" role="menuitem">Roles</a>
              <?php endif; ?>
              <?php if ($can_cashbox_manage): ?>
                <a class="config-menu-item" href="<?= url_path('cash_manage.php') ?>" role="menuitem">Administrar cajas</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </nav>
      <div class="user-menu" data-user-menu>
        <button class="user-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
          <?= e($display_name) ?> <span aria-hidden="true">▾</span>
        </button>
        <div class="user-menu-dropdown" role="menu">
          <a class="user-menu-item" href="<?= url_path('logout_profile.php') ?>" role="menuitem">Cerrar perfil</a>
          <a class="user-menu-item" href="<?= url_path('logout_system.php') ?>" role="menuitem">Salir del sistema</a>
        </div>
      </div>
    </div>
  </div>
</header>
<script>
  (() => {
    const setupMenu = (selector, buttonSelector, openClass) => {
      const menu = document.querySelector(selector);
      if (!menu) return;
      const button = menu.querySelector(buttonSelector);
      const close = () => {
        menu.classList.remove(openClass);
        button?.setAttribute('aria-expanded', 'false');
      };
      const toggle = () => {
        const isOpen = menu.classList.toggle(openClass);
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
    };

    setupMenu('[data-user-menu]', '.user-menu-button', 'user-menu--open');
    setupMenu('[data-config-menu]', '.config-menu-button', 'config-menu--open');
    setupMenu('[data-cash-menu]', '.cash-menu-button', 'cash-menu--open');
  })();
</script>
