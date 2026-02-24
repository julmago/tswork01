<?php
$bootstrapPath = __DIR__ . '/bootstrap.php';
if (file_exists($bootstrapPath)) {
  require_once $bootstrapPath;
}

$dbPath = __DIR__ . '/db.php';
if (file_exists($dbPath)) {
  require_once $dbPath;
}


if (!function_exists('abort')) {
  function abort(int $code, string $message): void {
    http_response_code($code);
    echo "<h1>Ocurrió un problema</h1>";
    echo "<p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
    exit;
  }
}

if (!function_exists('get')) {
  function get(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
  }
}

if (!function_exists('db')) {
  function db(): PDO {
    $configPath = __DIR__ . '/config.php';
    if (!file_exists($configPath)) {
      abort(500, 'Falta el archivo de configuración para la base de datos.');
    }
    $config = require $configPath;
    $db = $config['db'] ?? [];
    $host = $db['host'] ?? 'localhost';
    $name = $db['name'] ?? '';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';
    if ($name === '' || $user === '') {
      abort(500, 'La configuración de la base de datos está incompleta.');
    }
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    try {
      return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } catch (PDOException $e) {
      abort(500, 'No se pudo conectar con la base de datos.');
    }
  }
}

if (!function_exists('require_login')) {
  function require_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }
    $gatewayActive = function_exists('has_gateway_session')
      ? has_gateway_session()
      : (!empty($_SESSION['gateway_logged']) || !empty($_SESSION['gateway_ok']));
    if (!$gatewayActive) {
      abort(403, 'No tenés permisos para descargar este archivo.');
    }
    if (empty($_SESSION['profile_logged']) || empty($_SESSION['logged_in']) || empty($_SESSION['user'])) {
      abort(403, 'No tenés permisos para descargar este archivo.');
    }
  }
}

require_login();

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id.');

try {
  $st = db()->prepare("SELECT id, name FROM stock_lists WHERE id = ? LIMIT 1");
  $st->execute([$list_id]);
  $list = $st->fetch();
} catch (Throwable $e) {
  abort(500, 'No se pudo obtener el listado solicitado.');
}
if (!$list) abort(404, 'Listado no encontrado.');

try {
  $st = db()->prepare("
    SELECT p.sku, p.name, i.qty
    FROM stock_list_items i
    JOIN products p ON p.id = i.product_id
    WHERE i.stock_list_id = ?
    ORDER BY p.name ASC
  ");
  $st->execute([$list_id]);
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  abort(500, 'No se pudieron obtener los productos del listado.');
}

if (headers_sent()) {
  abort(500, 'No se pudo generar el archivo.');
}

while (ob_get_level() > 0) {
  ob_end_clean();
}

function sanitize_sheet_name(string $name): string {
  $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);
  $name = trim(preg_replace('/\s+/', ' ', $name));
  if ($name === '') {
    $name = 'Listado';
  }
  if (function_exists('mb_substr')) {
    return mb_substr($name, 0, 31);
  }
  return substr($name, 0, 31);
}

function column_letter(int $index): string {
  $letters = '';
  while ($index > 0) {
    $index--;
    $letters = chr(65 + ($index % 26)) . $letters;
    $index = intdiv($index, 26);
  }
  return $letters;
}

function build_sheet_xml(array $rows, string $sheetName): string {
  $sheetData = '';
  $rowNumber = 1;
  foreach ($rows as $row) {
    $cells = '';
    $colIndex = 1;
    foreach ($row as $value) {
      $cellRef = column_letter($colIndex) . $rowNumber;
      $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
      $cells .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
      $colIndex++;
    }
    $sheetData .= '<row r="' . $rowNumber . '">' . $cells . '</row>';
    $rowNumber++;
  }

  return '<?xml version="1.0" encoding="UTF-8"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetData>' . $sheetData . '</sheetData>'
    . '</worksheet>';
}

function build_workbook_xml(string $sheetName): string {
  $safeName = htmlspecialchars($sheetName, ENT_XML1 | ENT_COMPAT, 'UTF-8');
  return '<?xml version="1.0" encoding="UTF-8"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="' . $safeName . '" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';
}

function build_content_types_xml(): string {
  return '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" '
    . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" '
    . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '</Types>';
}

function build_root_rels_xml(): string {
  return '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" '
    . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
    . 'Target="xl/workbook.xml"/>'
    . '</Relationships>';
}

function build_workbook_rels_xml(): string {
  return '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" '
    . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
    . 'Target="worksheets/sheet1.xml"/>'
    . '</Relationships>';
}

function generate_xlsx(array $rows, string $sheetName): string {
  if (!class_exists('ZipArchive')) {
    abort(500, 'No se pudo generar XLSX.');
  }

  $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
  if ($tmpFile === false) {
    abort(500, 'No se pudo generar XLSX.');
  }

  $zip = new ZipArchive();
  if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpFile);
    abort(500, 'No se pudo generar XLSX.');
  }

  $zip->addFromString('[Content_Types].xml', build_content_types_xml());
  $zip->addFromString('_rels/.rels', build_root_rels_xml());
  $zip->addFromString('xl/workbook.xml', build_workbook_xml($sheetName));
  $zip->addFromString('xl/_rels/workbook.xml.rels', build_workbook_rels_xml());
  $zip->addFromString('xl/worksheets/sheet1.xml', build_sheet_xml($rows, $sheetName));
  $zip->close();

  $data = file_get_contents($tmpFile);
  @unlink($tmpFile);

  if ($data === false) {
    abort(500, 'No se pudo generar XLSX.');
  }

  return $data;
}

$sheetName = sanitize_sheet_name($list['name'] ?? 'Listado');
$xlsxRows = [['sku', 'nombre', 'cantidad']];
foreach ($rows as $r) {
  $xlsxRows[] = [$r['sku'], $r['name'], (string)(int)$r['qty']];
}

$binary = generate_xlsx($xlsxRows, $sheetName);

$filename = "listado_{$list_id}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Content-Length: ' . strlen($binary));
echo $binary;
exit;
