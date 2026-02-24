<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

require_login();
ensure_sites_schema();

function ml_http_build_query(array $params): string {
  return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function ml_oauth_base_url(): string {
  return 'https://auth.mercadolibre.com.ar/authorization';
}

function ml_oauth_callback_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  if ($host === '') {
    throw new RuntimeException('No se pudo determinar el host para OAuth.');
  }
  return $scheme . '://' . $host . url_path('ml_oauth_callback.php');
}

function ml_oauth_sign_state(array $payload, string $secret): string {
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('No se pudo serializar el estado OAuth.');
  }
  $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
  $sig = hash_hmac('sha256', $encoded, $secret, true);
  $sigEncoded = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
  return $encoded . '.' . $sigEncoded;
}

$siteId = (int)get('site_id', '0');
if ($siteId <= 0) {
  header('Location: sites.php?oauth_error=site');
  exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT s.id, sc.ml_client_id, sc.ml_client_secret, sc.ml_redirect_uri FROM sites s LEFT JOIN site_connections sc ON sc.site_id = s.id WHERE s.id = ? LIMIT 1');
$st->execute([$siteId]);
$siteCfg = $st->fetch();
if (!$siteCfg) {
  header('Location: sites.php?oauth_error=site');
  exit;
}

$clientId = trim((string)($siteCfg['ml_client_id'] ?? ''));
$clientSecret = trim((string)($siteCfg['ml_client_secret'] ?? ''));
$redirectUri = trim((string)($siteCfg['ml_redirect_uri'] ?? ''));
if ($redirectUri === '') {
  $redirectUri = ml_oauth_callback_url();
}

if ($clientId === '' || $clientSecret === '') {
  header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=missing_ml_config');
  exit;
}

$stateSecret = (string)(auth_config()['ml_oauth_state_secret'] ?? '');
if ($stateSecret === '') {
  $stateSecret = 'change-me-ml-oauth-secret';
}

$state = ml_oauth_sign_state([
  'site_id' => $siteId,
  'nonce' => bin2hex(random_bytes(16)),
  'ts' => time(),
], $stateSecret);

$authUrl = ml_oauth_base_url() . '?' . ml_http_build_query([
  'response_type' => 'code',
  'client_id' => $clientId,
  'redirect_uri' => $redirectUri,
  'state' => $state,
]);

header('Location: ' . $authUrl);
exit;
