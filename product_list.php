<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_error.log');
error_reporting(E_ALL);

$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
  mkdir($logsDir, 0775, true);
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/pricing.php';
require_once __DIR__ . '/include/stock.php';

$visibleSites = [];
$syncSites = [];
$syncSiteIds = [];
$syncColumnsCount = 0;
$products = [];
$mlSyncCounts = [];
$q = trim(get('q', ''));
$page = max(1, (int) get('page', 1));
$allowedPerPage = [20, 50, 100, 200, 500, 1000, 2000, 3000];
$defaultPerPage = 50;
$requestedPerPage = (int) get('per_page', $defaultPerPage);
$limit = in_array($requestedPerPage, $allowedPerPage, true) ? $requestedPerPage : $defaultPerPage;
$total = 0;
$total_pages = 1;
$offset = 0;
$numeric = ($q !== '' && ctype_digit($q));
$query_base = [];
$prev_page = 1;
$next_page = 1;
$canSetStock = false;
$csrfToken = '';
$count_sql = '';
$select_sql = '';
$select_params = [];
$sort = (string) get('sort', 'name');
$dir = strtolower((string) get('dir', 'asc'));
$allowedSorts = [
  'sku' => 'p.sku',
  'name' => 'p.name',
  'brand' => 'brand',
  'supplier' => 'supplier_name',
  'stock' => 'ts_stock_qty',
];

if (!isset($allowedSorts[$sort])) {
  $sort = 'name';
}
if ($dir !== 'desc') {
  $dir = 'asc';
}
$orderDirection = strtoupper($dir);

try {
  require_login();
  $canSetStock = can_set_stock();
  $csrfToken = csrf_token();
  ensure_brands_schema();
  ensure_sites_schema();
  ensure_stock_schema();

  $where = '';
  $params = [];
  if ($q !== '') {
    $like = '%' . $q . '%';
    $where = "WHERE (
      p.sku LIKE :like_term
      OR p.name LIKE :like_term
      OR EXISTS (
        SELECT 1
        FROM product_codes pc_search
        WHERE pc_search.product_id = p.id
          AND pc_search.code LIKE :like_term
      )
      OR EXISTS (
        SELECT 1
        FROM product_suppliers ps_search
        WHERE ps_search.product_id = p.id
          AND ps_search.is_active = 1
          AND ps_search.supplier_sku LIKE :like_term
      )
    )";
    $params = [
      ':like_term' => $like,
    ];
  }

  $showInListColumn = null;
  $siteShowInListSt = db()->query("SHOW COLUMNS FROM sites");
  if ($siteShowInListSt) {
    foreach ($siteShowInListSt->fetchAll() as $siteColumn) {
      $field = (string)($siteColumn['Field'] ?? '');
      if ($field === 'show_in_list') {
        $showInListColumn = 'show_in_list';
        break;
      }
      if ($field === 'is_visible') {
        $showInListColumn = 'is_visible';
      }
    }
  }

  $visibleSitesSql = "SELECT * FROM sites WHERE is_active = 1";
  if ($showInListColumn !== null) {
    $visibleSitesSql .= " AND {$showInListColumn} = 1";
  }
  $visibleSitesSql .= " ORDER BY id ASC";

  $visibleSitesSt = db()->query($visibleSitesSql);
  $visibleSites = $visibleSitesSt ? $visibleSitesSt->fetchAll() : [];

  foreach ($visibleSites as $s) {
    if ((int)($s['show_sync'] ?? 0) === 1 && ($s['conn_type'] ?? '') === 'mercadolibre') {
      $syncSites[] = $s;
      $syncColumnsCount++;
    }
  }
  $syncSiteIds = array_map(static fn($x): int => (int)$x['id'], $syncSites);

  $supplierMarginColumn = null;
  $supplierDiscountColumn = null;
  $supplierColumnsSt = db()->query("SHOW COLUMNS FROM suppliers");
  if ($supplierColumnsSt) {
    foreach ($supplierColumnsSt->fetchAll() as $supplierColumn) {
      $field = (string)($supplierColumn['Field'] ?? '');
      if ($field === 'base_margin_percent') {
        $supplierMarginColumn = $field;
      }
      if ($field === 'default_margin_percent' && $supplierMarginColumn === null) {
        $supplierMarginColumn = $field;
      } elseif ($supplierMarginColumn === null && stripos($field, 'margin') !== false) {
        $supplierMarginColumn = $field;
      }
      if ($field === 'discount_percent') {
        $supplierDiscountColumn = $field;
      }
      if ($field === 'import_discount_default' && $supplierDiscountColumn === null) {
        $supplierDiscountColumn = $field;
      }
    }
  }

  $supplierMarginExpr = '0';
  if ($supplierMarginColumn !== null) {
    $safeSupplierMarginColumn = str_replace('`', '``', $supplierMarginColumn);
    $supplierMarginExpr = "COALESCE(s.`{$safeSupplierMarginColumn}`, 0)";
  }

  $supplierDiscountExpr = '0';
  if ($supplierDiscountColumn !== null) {
    $safeSupplierDiscountColumn = str_replace('`', '``', $supplierDiscountColumn);
    $supplierDiscountExpr = "COALESCE(s.`{$safeSupplierDiscountColumn}`, 0)";
  }

  $count_sql = "SELECT COUNT(DISTINCT p.id) AS total
    FROM products p
    $where";
  $count_st = db()->prepare($count_sql);
  foreach ($params as $key => $value) {
    $count_st->bindValue($key, $value, PDO::PARAM_STR);
  }
  $count_st->execute();
  $total = (int) $count_st->fetchColumn();
  $total_pages = max(1, (int) ceil($total / $limit));
  if ($page > $total_pages) {
    $page = 1;
  }
  $offset = ($page - 1) * $limit;

  $select_sql = "SELECT p.id, p.sku, p.name, COALESCE(b.name, p.brand) AS brand,"
    . " p.sale_mode, p.sale_units_per_pack,"
    . " s.name AS supplier_name,"
    . " ps1.supplier_sku, ps1.supplier_cost, ps1.cost_unitario, ps1.cost_type, ps1.units_per_pack,"
    . " COALESCE(s.import_default_units_per_pack, 0) AS supplier_default_units_per_pack,"
    . " {$supplierMarginExpr} AS supplier_default_margin_percent,"
    . " {$supplierDiscountExpr} AS supplier_discount_percent,"
    . " COALESCE(tsps.qty, 0) AS ts_stock_qty,"
    . ($numeric
      ? " CASE WHEN EXISTS ("
        . "   SELECT 1 FROM product_codes pc_exact"
        . "   WHERE pc_exact.product_id = p.id"
        . "     AND pc_exact.code = :code_exact"
        . " ) THEN 1 ELSE 0 END AS code_exact_match"
      : " 0 AS code_exact_match")
    . " FROM products p"
    . " LEFT JOIN brands b ON b.id = p.brand_id"
    . " LEFT JOIN ("
    . "   SELECT ps_pick.product_id, ps_pick.supplier_id, ps_pick.supplier_sku, ps_pick.supplier_cost, ps_pick.cost_unitario, ps_pick.cost_type, ps_pick.units_per_pack"
    . "   FROM product_suppliers ps_pick"
    . "   INNER JOIN suppliers s_pick ON s_pick.id = ps_pick.supplier_id"
    . "   INNER JOIN products p_pick ON p_pick.id = ps_pick.product_id"
    . "   WHERE ps_pick.is_active = 1"
    . "     AND NOT EXISTS ("
    . "       SELECT 1"
    . "       FROM product_suppliers ps_better"
    . "       INNER JOIN suppliers s_better ON s_better.id = ps_better.supplier_id"
    . "       INNER JOIN products p_better ON p_better.id = ps_better.product_id"
    . "       WHERE ps_better.product_id = ps_pick.product_id"
    . "         AND ps_better.is_active = 1"
    . "         AND ("
    . "           COALESCE(CASE WHEN ps_better.cost_type = 'PACK' THEN ps_better.supplier_cost / COALESCE(NULLIF(COALESCE(ps_better.units_per_pack, s_better.import_default_units_per_pack, p_better.sale_units_per_pack, 1), 0), 1) ELSE ps_better.supplier_cost END, 999999999)"
    . "           < COALESCE(CASE WHEN ps_pick.cost_type = 'PACK' THEN ps_pick.supplier_cost / COALESCE(NULLIF(COALESCE(ps_pick.units_per_pack, s_pick.import_default_units_per_pack, p_pick.sale_units_per_pack, 1), 0), 1) ELSE ps_pick.supplier_cost END, 999999999)"
    . "           OR ("
    . "             COALESCE(CASE WHEN ps_better.cost_type = 'PACK' THEN ps_better.supplier_cost / COALESCE(NULLIF(COALESCE(ps_better.units_per_pack, s_better.import_default_units_per_pack, p_better.sale_units_per_pack, 1), 0), 1) ELSE ps_better.supplier_cost END, 999999999)"
    . "             = COALESCE(CASE WHEN ps_pick.cost_type = 'PACK' THEN ps_pick.supplier_cost / COALESCE(NULLIF(COALESCE(ps_pick.units_per_pack, s_pick.import_default_units_per_pack, p_pick.sale_units_per_pack, 1), 0), 1) ELSE ps_pick.supplier_cost END, 999999999)"
    . "             AND ps_better.id < ps_pick.id"
    . "           )"
    . "         )"
    . "     )"
    . " ) ps1 ON ps1.product_id = p.id"
    . " LEFT JOIN suppliers s ON s.id = ps1.supplier_id AND s.is_active = 1"
    . " LEFT JOIN ts_product_stock tsps ON tsps.product_id = p.id"
    . " $where"
    . " ORDER BY code_exact_match DESC, " . $allowedSorts[$sort] . " " . $orderDirection . ", p.id ASC"
    . " LIMIT :limit OFFSET :offset";
  $select_params = $params;
  if ($numeric) {
    $select_params[':code_exact'] = $q;
  }
  $st = db()->prepare($select_sql);
  foreach ($select_params as $key => $value) {
    $st->bindValue($key, $value, PDO::PARAM_STR);
  }
  $st->bindValue(':limit', $limit, PDO::PARAM_INT);
  $st->bindValue(':offset', $offset, PDO::PARAM_INT);
  $st->execute();
  $products = $st->fetchAll();

  $productIds = array_map(static fn($p): int => (int)$p['id'], $products);
  if ($productIds && $syncSiteIds) {
    $inP = implode(',', array_fill(0, count($productIds), '?'));
    $inS = implode(',', array_fill(0, count($syncSiteIds), '?'));
    $syncSql = "SELECT product_id, site_id, COUNT(*) AS c
      FROM ts_ml_links
      WHERE product_id IN ($inP) AND site_id IN ($inS)
      GROUP BY product_id, site_id";
    $syncSt = db()->prepare($syncSql);
    $syncSt->execute(array_merge($productIds, $syncSiteIds));
    foreach ($syncSt->fetchAll() as $r) {
      $pid = (int)$r['product_id'];
      $sid = (int)$r['site_id'];
      $mlSyncCounts[$pid][$sid] = (int)$r['c'];
    }
  }

  if ($q !== '') {
    $query_base['q'] = $q;
  }
  if ($sort !== 'name') {
    $query_base['sort'] = $sort;
  }
  if ($dir !== 'asc') {
    $query_base['dir'] = $dir;
  }
  if ($limit !== $defaultPerPage) {
    $query_base['per_page'] = $limit;
  }
  $prev_page = max(1, $page - 1);
  $next_page = min($total_pages, $page + 1);
} catch (Throwable $e) {
  error_log('[product_list] ' . $e->getMessage());
  if ($e instanceof PDOException && isset($e->errorInfo[2])) {
    error_log('[product_list][sql] ' . $e->errorInfo[2]);
  }
  if ($count_sql !== '') {
    error_log('[product_list][count_sql] ' . $count_sql);
    error_log('[product_list][count_params] ' . json_encode($params, JSON_UNESCAPED_UNICODE));
  }
  if ($select_sql !== '') {
    error_log('[product_list][select_sql] ' . $select_sql);
    error_log('[product_list][select_params] ' . json_encode($select_params, JSON_UNESCAPED_UNICODE));
  }

  $visibleSites = [];
  $syncSites = [];
  $syncSiteIds = [];
  $syncColumnsCount = 0;
  $products = [];
  $mlSyncCounts = [];
  $total = 0;
  $total_pages = 1;
  $page = 1;
  $offset = 0;
  $query_base = ($q !== '') ? ['q' => $q] : [];
  if ($sort !== 'name') {
    $query_base['sort'] = $sort;
  }
  if ($dir !== 'asc') {
    $query_base['dir'] = $dir;
  }
  if ($limit !== $defaultPerPage) {
    $query_base['per_page'] = $limit;
  }
  $prev_page = 1;
  $next_page = 1;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="<?= e(app_body_class()) ?>">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <div>
        <h2 class="page-title">Listado de productos</h2>
        <span class="muted">Explorá el catálogo y accedé al detalle.</span>
      </div>
      <div class="inline-actions">
        <?php if (can_create_product()): ?>
          <a class="btn" href="product_new.php">+ Nuevo producto</a>
        <?php endif; ?>
        <?php if (can_import_csv()): ?>
          <a class="btn btn-ghost" href="product_import.php">Importar CSV</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="suppliers.php">Proveedores</a>
      </div>
    </div>

    <div class="card">
      <form method="get" action="product_list.php" id="product-search-form" class="stack">
        <?php if ($limit !== $defaultPerPage): ?>
          <input type="hidden" name="per_page" value="<?= (int) $limit ?>">
        <?php endif; ?>
        <?php if ($sort !== 'name'): ?>
          <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <?php endif; ?>
        <?php if ($dir !== 'asc'): ?>
          <input type="hidden" name="dir" value="<?= e($dir) ?>">
        <?php endif; ?>
        <div class="input-icon">
          <input class="form-control" type="text" name="q" id="product-search-input" value="<?= e($q) ?>" placeholder="Buscar por SKU, nombre o código" autofocus>
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?><a class="btn btn-ghost" href="product_list.php">Limpiar</a><?php endif; ?>
          <?php if ($canSetStock): ?>
            <button class="btn btn-ghost" type="button" id="open-set-stock-modal">Setear Stock</button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <?php
              $buildSortLink = static function (string $column) use ($query_base, $sort, $dir): string {
                $query = $query_base;
                $query['sort'] = $column;
                $query['dir'] = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
                $query['page'] = 1;
                return 'product_list.php?' . http_build_query($query);
              };
              $sortIndicator = static function (string $column) use ($sort, $dir): string {
                if ($sort !== $column) {
                  return '';
                }
                return $dir === 'asc' ? ' ↑' : ' ↓';
              };
            ?>
            <tr>
              <?php if ($canSetStock): ?><th><input type="checkbox" id="select-all-products" aria-label="Seleccionar todos"></th><?php endif; ?>
              <th><a href="<?= e($buildSortLink('sku')) ?>">SKU<?= e($sortIndicator('sku')) ?></a></th>
              <th><a href="<?= e($buildSortLink('name')) ?>">NOMBRE<?= e($sortIndicator('name')) ?></a></th>
              <th><a href="<?= e($buildSortLink('brand')) ?>">MARCA<?= e($sortIndicator('brand')) ?></a></th>
              <th><a href="<?= e($buildSortLink('supplier')) ?>">PROVEEDOR<?= e($sortIndicator('supplier')) ?></a></th>
              <th>SKU PROVEEDOR</th>
              <?php foreach ($visibleSites as $site): ?>
                <th><?= e($site['name']) ?></th>
                <?php if ((int)($site['show_sync'] ?? 0) === 1 && ($site['conn_type'] ?? '') === 'mercadolibre'): ?>
                  <th><?= e($site['name']) ?> SYNC</th>
                <?php endif; ?>
              <?php endforeach; ?>
              <th><a href="<?= e($buildSortLink('stock')) ?>">STOCK<?= e($sortIndicator('stock')) ?></a></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$products): ?>
              <tr><td colspan="<?= 6 + count($visibleSites) + $syncColumnsCount + ($canSetStock ? 1 : 0) ?>">Sin productos.</td></tr>
            <?php else: ?>
              <?php foreach ($products as $p): ?>
                <tr>
                  <?php if ($canSetStock): ?><td><input type="checkbox" class="product-select" value="<?= (int)$p['id'] ?>"></td><?php endif; ?>
                  <td><a href="product_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['sku']) ?></a></td>
                  <td><a href="product_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></td>
                  <td><?= e($p['brand']) ?></td>
                  <td><?= $p['supplier_name'] ? e($p['supplier_name']) : '—' ?></td>
                  <td><?= !empty($p['supplier_sku']) ? e($p['supplier_sku']) : '—' ?></td>
                  <?php foreach ($visibleSites as $site): ?>
                    <td>
                      <?php
                        $effectiveUnitCost = get_effective_unit_cost($p, [
                            'import_default_units_per_pack' => $p['supplier_default_units_per_pack'] ?? 0,
                            'discount_percent' => $p['supplier_discount_percent'] ?? 0,
                          ]);
                        $costForMode = get_cost_for_product_mode($effectiveUnitCost, $p);
                        $priceReason = get_price_unavailable_reason($p, $p);

                        if ($p['supplier_name'] && $costForMode !== null) {
                          $finalPrice = get_final_site_price($costForMode, [
                            'base_percent' => $p['supplier_default_margin_percent'] ?? 0,
                            'discount_percent' => $p['supplier_discount_percent'] ?? 0,
                          ], $site, 0.0);

                          if ($finalPrice === null) {
                            echo '<span title="' . e($priceReason ?? 'Precio incompleto') . '">—</span>';
                          } else {
                            echo e((string)(int)$finalPrice);
                          }

                          if (($p['sale_mode'] ?? 'UNIDAD') === 'PACK' && (int)($p['sale_units_per_pack'] ?? 0) > 0) {
                            echo '<br><span class="muted small">Pack x' . e((string)(int)$p['sale_units_per_pack']) . '</span>';
                          }
                        } else {
                          echo '<span title="' . e($priceReason ?? 'Precio incompleto') . '">—</span>';
                        }
                      ?>
                    </td>
                    <?php if ((int)($site['show_sync'] ?? 0) === 1 && ($site['conn_type'] ?? '') === 'mercadolibre'): ?>
                      <td>
                        <?php
                          $pid = (int)$p['id'];
                          $sid = (int)$site['id'];
                          $c = (int)($mlSyncCounts[$pid][$sid] ?? 0);
                          echo $c > 0 ? e((string)$c) : '—';
                        ?>
                      </td>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <td><?= (int)$p['ts_stock_qty'] ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="inline-actions">
        <form method="get" action="product_list.php" id="per-page-form" class="inline-actions" style="margin-right:auto;">
          <?php if ($q !== ''): ?>
            <input type="hidden" name="q" value="<?= e($q) ?>">
          <?php endif; ?>
          <?php if ($sort !== 'name'): ?>
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
          <?php endif; ?>
          <?php if ($dir !== 'asc'): ?>
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
          <?php endif; ?>
          <input type="hidden" name="page" value="1">
          <label class="muted" for="per-page-select">Por página</label>
          <select class="form-control" name="per_page" id="per-page-select" style="width:auto;">
            <?php foreach ($allowedPerPage as $perPageOption): ?>
              <option value="<?= (int) $perPageOption ?>" <?= $limit === $perPageOption ? 'selected' : '' ?>><?= (int) $perPageOption ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php
          $prev_query = $query_base;
          $prev_query['page'] = $prev_page;
          $next_query = $query_base;
          $next_query['page'] = $next_page;
          $prev_link = 'product_list.php?' . http_build_query($prev_query);
          $next_link = 'product_list.php?' . http_build_query($next_query);
        ?>
        <?php if ($page > 1): ?>
          <a class="btn btn-ghost" href="<?= e($prev_link) ?>">&laquo; Anterior</a>
        <?php else: ?>
          <span class="muted">&laquo; Anterior</span>
        <?php endif; ?>
        <span class="muted">Página <?= (int) $page ?> de <?= (int) $total_pages ?></span>
        <?php if ($page < $total_pages): ?>
          <a class="btn btn-ghost" href="<?= e($next_link) ?>">Siguiente &raquo;</a>
        <?php else: ?>
          <span class="muted">Siguiente &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php if ($canSetStock): ?>
<div id="set-stock-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:9999; align-items:center; justify-content:center;">
  <div class="card" style="width:min(520px, 95vw);">
    <h3 class="card-title">Setear Stock</h3>
    <div id="set-stock-alert" class="alert" style="display:none;"></div>
    <div class="stack">
      <label class="form-label" for="set-stock-qty">Nuevo stock</label>
      <input class="form-control" type="number" id="set-stock-qty" step="1" required>
      <label class="form-label" for="set-stock-note">Nota (opcional)</label>
      <textarea class="form-control" id="set-stock-note" rows="3" maxlength="1000"></textarea>
    </div>
    <div class="inline-actions" style="margin-top:12px;">
      <button class="btn" type="button" id="apply-set-stock">Aplicar</button>
      <button class="btn btn-ghost" type="button" id="cancel-set-stock">Cancelar</button>
    </div>
  </div>
</div>
<?php endif; ?>

</main>

<script>
  const searchInput = document.getElementById('product-search-input');
  if (searchInput) {
    searchInput.focus();
    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        setTimeout(() => searchInput.focus(), 0);
      }
    });
  }

  const perPageSelect = document.getElementById('per-page-select');
  if (perPageSelect) {
    perPageSelect.addEventListener('change', () => {
      const perPageForm = document.getElementById('per-page-form');
      if (perPageForm) {
        perPageForm.submit();
      }
    });
  }

  <?php if ($canSetStock): ?>
  (() => {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const openBtn = document.getElementById('open-set-stock-modal');
    const modal = document.getElementById('set-stock-modal');
    const cancelBtn = document.getElementById('cancel-set-stock');
    const applyBtn = document.getElementById('apply-set-stock');
    const qtyInput = document.getElementById('set-stock-qty');
    const noteInput = document.getElementById('set-stock-note');
    const alertBox = document.getElementById('set-stock-alert');
    const selectAll = document.getElementById('select-all-products');

    const selectedIds = () => Array.from(document.querySelectorAll('.product-select:checked')).map((el) => parseInt(el.value, 10)).filter((id) => Number.isInteger(id) && id > 0);

    const showAlert = (message, type = 'danger') => {
      if (!alertBox) return;
      alertBox.className = 'alert alert-' + type;
      alertBox.textContent = message;
      alertBox.style.display = 'block';
    };

    const hideAlert = () => {
      if (!alertBox) return;
      alertBox.style.display = 'none';
      alertBox.textContent = '';
    };

    const openModal = () => {
      if (!modal) return;
      hideAlert();
      modal.style.display = 'flex';
      if (qtyInput) qtyInput.focus();
    };

    const closeModal = () => {
      if (!modal) return;
      modal.style.display = 'none';
    };

    if (openBtn) {
      openBtn.addEventListener('click', () => {
        if (selectedIds().length === 0) {
          alert('Seleccioná al menos 1 producto');
          return;
        }
        openModal();
      });
    }

    if (cancelBtn) {
      cancelBtn.addEventListener('click', closeModal);
    }

    if (modal) {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
      });
    }

    if (selectAll) {
      selectAll.addEventListener('change', () => {
        document.querySelectorAll('.product-select').forEach((cb) => {
          cb.checked = selectAll.checked;
        });
      });
    }

    if (applyBtn) {
      applyBtn.addEventListener('click', async () => {
        const ids = selectedIds();
        if (ids.length === 0) {
          showAlert('Seleccioná al menos 1 producto');
          return;
        }

        const rawQty = (qtyInput?.value || '').trim();
        if (!/^-?\d+$/.test(rawQty)) {
          showAlert('Nuevo stock debe ser un entero');
          return;
        }

        applyBtn.disabled = true;
        hideAlert();
        try {
          const body = new URLSearchParams();
          ids.forEach((id) => body.append('product_ids[]', String(id)));
          body.set('new_qty', rawQty);
          body.set('note', noteInput?.value || '');
          body.set('csrf_token', csrfToken);

          const response = await fetch('api/set_stock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(),
          });

          const data = await response.json();
          if (!response.ok || !data.ok) {
            showAlert(data.error || 'No se pudo setear stock');
            return;
          }

          const updated = parseInt(data.updated_count || 0, 10);
          let message = `Stock actualizado en ${updated} productos`;
          if (Array.isArray(data.errors) && data.errors.length > 0) {
            message += `. Errores: ${data.errors.length}`;
          }
          alert(message);
          window.location.reload();
        } catch (error) {
          showAlert('No se pudo setear stock');
        } finally {
          applyBtn.disabled = false;
        }
      });
    }
  })();
  <?php endif; ?>
</script>

</body>
</html>
