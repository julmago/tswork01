<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_import_lib.php';
require_login();
ensure_product_suppliers_schema();

if (!is_post()) {
  redirect('suppliers.php');
}

$supplierId = (int)post('supplier_id', '0');
if ($supplierId <= 0) {
  abort(400, 'Proveedor inválido.');
}

$st = db()->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$supplierId]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
}

$sourceType = strtoupper(trim((string)post('source_type', 'FILE')));
if (!in_array($sourceType, ['FILE', 'PASTE'], true)) {
  abort(400, 'Tipo de fuente inválido.');
}

$detectedFormat = 'unknown';
$detectedDelimiter = null;
$detectedLabel = 'Desconocido';
$tmpName = '';
$filename = '';
$pasteText = (string)post('paste_text', '');
$parseType = '';
$parseOptions = [];

if ($sourceType === 'PASTE') {
  if (trim($pasteText) === '') {
    abort(400, 'Pegá texto para importar.');
  }

  $separatorInput = strtoupper(trim((string)post('paste_separator', 'AUTO')));
  $separatorMap = [
    'AUTO' => null,
    'TAB' => "\t",
    'SEMICOLON' => ';',
    'COMMA' => ',',
    'PIPE' => '|',
  ];
  if (!array_key_exists($separatorInput, $separatorMap)) {
    $separatorInput = 'AUTO';
  }
  $forcedDelimiter = $separatorMap[$separatorInput];

  $parsedPreview = supplier_import_parse_delimited_text($pasteText, $forcedDelimiter);
  if (!$parsedPreview['rows']) {
    abort(400, 'No se encontraron filas válidas para importar.');
  }

  $parseType = 'PASTE';
  $parseOptions['forced_delimiter'] = $forcedDelimiter;
  $detectedFormat = 'txt';
  $detectedDelimiter = $parsedPreview['delimiter'] ?? null;
  $detectedLabel = 'TXT/PEGADO';
} else {
  if (!isset($_FILES['source_file']) || $_FILES['source_file']['error'] !== UPLOAD_ERR_OK) {
    abort(400, 'Subí un archivo válido.');
  }

  $tmpName = (string)$_FILES['source_file']['tmp_name'];
  $filename = (string)$_FILES['source_file']['name'];
  $mimeHint = (string)($_FILES['source_file']['type'] ?? '');

  $detection = supplier_import_detect_file_format($tmpName, $filename, $mimeHint);
  $detectedFormat = (string)($detection['format'] ?? 'unknown');

  if ($detectedFormat === 'xlsx' || $detectedFormat === 'xls') {
    if ($detectedFormat === 'xls' && !file_exists(__DIR__ . '/vendor/autoload.php')) {
      abort(400, 'Archivo .xls no soportado en este entorno. Convertí a .xlsx o .csv.');
    }
    $parseType = 'XLSX';
    $detectedLabel = strtoupper($detectedFormat);
  } elseif ($detectedFormat === 'csv') {
    $parseType = 'CSV';
    $content = (string)file_get_contents($tmpName);
    $parsedPreview = supplier_import_parse_delimited_text($content);
    $detectedDelimiter = $parsedPreview['delimiter'] ?? null;
    $detectedLabel = 'CSV';
  } elseif ($detectedFormat === 'txt') {
    $parseType = 'TXT';
    $content = (string)file_get_contents($tmpName);
    $parsedPreview = supplier_import_parse_delimited_text($content);
    $detectedDelimiter = $parsedPreview['delimiter'] ?? null;
    $detectedLabel = 'TXT';
  } elseif ($detectedFormat === 'pdf') {
    abort(400, 'PDF no soportado aún.');
  } else {
    abort(400, 'No se pudo detectar el formato del archivo. Probá con CSV/XLSX/TXT.');
  }
}

try {
  $table = supplier_import_parse_table($parseType, $tmpName, $pasteText, $parseOptions);
  $analysis = supplier_import_analyze_table($table);
  if (empty($analysis['data_rows'])) {
    throw new RuntimeException('No se encontraron filas válidas para importar.');
  }

  $token = bin2hex(random_bytes(16));
  $_SESSION['supplier_import_mapping'][$token] = [
    'supplier_id' => $supplierId,
    'source_type' => $sourceType,
    'filename' => $filename,
    'analysis' => $analysis,
    'detected_format' => $detectedFormat,
    'detected_delimiter' => $detectedDelimiter,
    'detected_label' => $detectedLabel,
    'parse_source_type' => $parseType,
  ];

  redirect('supplier_import_mapping.php?token=' . urlencode($token));
} catch (Throwable $t) {
  abort(400, 'No se pudo procesar la importación: ' . $t->getMessage());
}
