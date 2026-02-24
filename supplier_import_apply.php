<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();

if (!is_post()) {
  redirect('suppliers.php');
}

$runId = (int)post('run_id', '0');
if ($runId <= 0) {
  abort(400, 'Importación inválida.');
}

$stRun = db()->prepare('SELECT * FROM supplier_import_runs WHERE id = ? LIMIT 1');
$stRun->execute([$runId]);
$run = $stRun->fetch();
if (!$run) {
  abort(404, 'Importación no encontrada.');
}
if (!empty($run['applied_at'])) {
  redirect('supplier_import_preview.php?run_id=' . $runId);
}

$selectedCostTypeColumn = trim((string)($run['selected_cost_type_column'] ?? ''));
$selectedUnitsPerPackColumn = trim((string)($run['selected_units_per_pack_column'] ?? ''));

$rowsSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ? AND status = 'MATCHED' AND chosen_by_rule = 1");
$rowsSt->execute([$runId]);
$rows = $rowsSt->fetchAll();

$stMatches = db()->prepare('SELECT id, product_id FROM product_suppliers WHERE supplier_id = ? AND supplier_sku = ? AND is_active = 1 ORDER BY id ASC');

$changedBy = (int)(current_user()['id'] ?? 0);
if ($changedBy <= 0) {
  $changedBy = null;
}

try {
  db()->beginTransaction();

  $stBefore = db()->prepare("SELECT ps.supplier_cost, ps.cost_type, ps.units_per_pack, ps.cost_unitario,
      COALESCE(s.import_default_units_per_pack, 0) AS supplier_default_units_per_pack,
      COALESCE(p.sale_units_per_pack, 0) AS product_units_pack
    FROM product_suppliers ps
    LEFT JOIN suppliers s ON s.id = ps.supplier_id
    LEFT JOIN products p ON p.id = ps.product_id
    WHERE ps.id = ?
    LIMIT 1");
  $stUpdate = db()->prepare("UPDATE product_suppliers
    SET supplier_cost = ?,
        cost_type = ?,
        units_per_pack = ?,
        cost_unitario = ?,
        updated_at = NOW()
    WHERE id = ?");
  $stHist = db()->prepare('INSERT INTO product_supplier_cost_history(product_supplier_id, run_id, cost_before, cost_after, changed_by, note) VALUES(?, ?, ?, ?, ?, ?)');

  foreach ($rows as $row) {
    $supplierSku = trim((string)($row['supplier_sku'] ?? ''));
    if ($supplierSku === '') {
      continue;
    }

    $stMatches->execute([(int)$run['supplier_id'], $supplierSku]);
    $matches = $stMatches->fetchAll();
    if (!$matches) {
      continue;
    }

    if (!isset($row['raw_price']) || $row['raw_price'] === null || (float)$row['raw_price'] < 0) {
      continue;
    }

    $extraDiscount = isset($run['extra_discount_percent']) ? (float)$run['extra_discount_percent'] : 0.0;
    $supplierDiscount = isset($run['supplier_discount_percent']) ? (float)$run['supplier_discount_percent'] : 0.0;
    $priceAfterSupplierDiscount = (float)$row['raw_price'] * (1 - ($supplierDiscount / 100));
    $priceAfterFileDiscount = $priceAfterSupplierDiscount * (1 - ($extraDiscount / 100));
    $supplierCostToSave = (int)round($priceAfterFileDiscount, 0);

    foreach ($matches as $match) {
      $psId = (int)$match['id'];
      if ($psId <= 0) {
        continue;
      }

      $stBefore->execute([$psId]);
      $before = $stBefore->fetch();

      $costType = strtoupper((string)($before['cost_type'] ?? 'UNIDAD'));
      if ($selectedCostTypeColumn !== '') {
        $rawCostType = strtoupper(trim((string)($row['raw_cost_type'] ?? '')));
        if (in_array($rawCostType, ['UNIDAD', 'PACK'], true)) {
          $costType = $rawCostType;
        }
      }
      if (!in_array($costType, ['UNIDAD', 'PACK'], true)) {
        $costType = 'UNIDAD';
      }

      $unitsPerPack = isset($before['units_per_pack']) ? (int)$before['units_per_pack'] : 0;
      if ($selectedUnitsPerPackColumn !== '') {
        $rawUnitsPerPack = (int)($row['raw_units_per_pack'] ?? 0);
        if ($rawUnitsPerPack > 0) {
          $unitsPerPack = $rawUnitsPerPack;
        }
      }
      if ($costType === 'PACK' && $unitsPerPack <= 0) {
        $unitsPerPack = isset($before['supplier_default_units_per_pack']) ? (int)$before['supplier_default_units_per_pack'] : 0;
      }
      if ($costType === 'PACK' && $unitsPerPack <= 0) {
        $unitsPerPack = isset($before['product_units_pack']) ? (int)$before['product_units_pack'] : 0;
      }
      if ($costType !== 'PACK') {
        $unitsPerPack = null;
      }

      $costUnitarioToSave = $supplierCostToSave;
      if ($costType === 'PACK') {
        $divisor = (int)$unitsPerPack;
        if ($divisor <= 0) {
          $divisor = 1;
        }
        $costUnitarioToSave = (int)round($supplierCostToSave / $divisor, 0);
      }

      $stUpdate->execute([$supplierCostToSave, $costType, $unitsPerPack, $costUnitarioToSave, $psId]);
      $stHist->execute([$psId, $runId, $before['supplier_cost'] ?? null, $supplierCostToSave, $changedBy, 'supplier import apply']);
    }
  }

  $stDone = db()->prepare('UPDATE supplier_import_runs SET applied_at = NOW() WHERE id = ?');
  $stDone->execute([$runId]);

  db()->commit();
} catch (Throwable $t) {
  if (db()->inTransaction()) {
    db()->rollBack();
  }
  abort(500, 'No se pudo aplicar la importación.');
}

redirect('supplier_import_preview.php?run_id=' . $runId);
