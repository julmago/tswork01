<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/stock_sync.php';

ensure_sites_schema();

function ml_http_build_query(array $params): string {
  return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function ml_token_url(): string {
  return 'https://api.mercadolibre.com/oauth/token';
}

function ml_oauth_callback_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  if ($host === '') {
    throw new RuntimeException('No se pudo determinar el host para OAuth.');
  }
  return $scheme . '://' . $host . url_path('ml_oauth_callback.php');
}

function ml_post_form(string $url, array $data): array {
  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('No se pudo inicializar cURL.');
  }

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, ml_http_build_query($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    throw new RuntimeException('Error cURL: ' . $err);
  }

  $json = json_decode((string)$resp, true);
  if (!is_array($json)) {
    $json = [];
  }

  return ['code' => $code, 'json' => $json, 'raw' => (string)$resp];
}

function ml_oauth_state_decode(string $state, string $secret): ?array {
  $parts = explode('.', $state, 2);
  if (count($parts) !== 2) {
    return null;
  }
  [$encodedPayload, $encodedSignature] = $parts;
  if ($encodedPayload === '' || $encodedSignature === '') {
    return null;
  }

  $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedPayload, $secret, true)), '+/', '-_'), '=');
  if (!hash_equals($expected, $encodedSignature)) {
    return null;
  }

  $payloadRaw = base64_decode(strtr($encodedPayload, '-_', '+/'), true);
  if ($payloadRaw === false) {
    return null;
  }

  $payload = json_decode($payloadRaw, true);
  if (!is_array($payload)) {
    return null;
  }

  return $payload;
}

$code = trim((string)get('code', ''));
$state = trim((string)get('state', ''));
if ($code === '' || $state === '') {
  http_response_code(200);
  header('Content-Type: text/plain; charset=utf-8');
  echo "OAuth callback. Falta code/state.";
  exit;
}

$stateSecret = (string)(auth_config()['ml_oauth_state_secret'] ?? '');
if ($stateSecret === '') {
  $stateSecret = 'change-me-ml-oauth-secret';
}

$payload = ml_oauth_state_decode($state, $stateSecret);
$siteId = (int)($payload['site_id'] ?? 0);
$ts = (int)($payload['ts'] ?? 0);
if (!$payload || $siteId <= 0 || $ts <= 0) {
  header('Location: sites.php?oauth_error=state');
  exit;
}

if ((time() - $ts) > 600) {
  header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=state_expired');
  exit;
}

$pdo = db();

try {
  $st = $pdo->prepare('SELECT ml_client_id, ml_client_secret, ml_redirect_uri FROM site_connections WHERE site_id = ? LIMIT 1');
  $st->execute([$siteId]);
  $cfg = $st->fetch();

  $clientId = trim((string)($cfg['ml_client_id'] ?? ''));
  $clientSecret = trim((string)($cfg['ml_client_secret'] ?? ''));
  $redirectUri = trim((string)($cfg['ml_redirect_uri'] ?? ''));
  if ($redirectUri === '') {
    $redirectUri = ml_oauth_callback_url();
  }
  if ($clientId === '' || $clientSecret === '') {
    header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=missing_ml_config');
    exit;
  }

  $tokenResponse = ml_post_form(ml_token_url(), [
    'grant_type' => 'authorization_code',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $code,
    'redirect_uri' => $redirectUri,
  ]);

  $tokenData = $tokenResponse['json'];
  $accessToken = trim((string)($tokenData['access_token'] ?? ''));
  $refreshToken = trim((string)($tokenData['refresh_token'] ?? ''));
  $userId = trim((string)($tokenData['user_id'] ?? ''));
  $expiresIn = (int)($tokenData['expires_in'] ?? 0);

  if ($tokenResponse['code'] < 200 || $tokenResponse['code'] >= 300 || $accessToken === '' || $refreshToken === '') {
    header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=token');
    exit;
  }

  $expiresAt = null;
  if ($expiresIn > 0) {
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
  }

  $st = $pdo->prepare('UPDATE site_connections SET ml_access_token = ?, ml_refresh_token = ?, ml_expires_at = ?, ml_token_expires_at = ?, ml_connected_at = NOW(), ml_user_id = ?, ml_app_id = COALESCE(NULLIF(ml_app_id, ""), ?), ml_status = ?, updated_at = NOW() WHERE site_id = ?');
  $st->execute([
    $accessToken,
    $refreshToken,
    $expiresAt,
    $expiresAt,
    $userId !== '' ? $userId : null,
    $clientId,
    'CONNECTED',
    $siteId,
  ]);


  try {
    $callbackUrl = rtrim(base_url(), '/') . '/ml_webhook.php';
    $subResult = stock_sync_ml_register_default_subscriptions($siteId, $callbackUrl);

    $topicsSummary = [];
    foreach ((array)($subResult['topics'] ?? []) as $topicName => $topicResult) {
      $topicsSummary[] = $topicName . ':' . (((bool)($topicResult['ok'] ?? false)) ? 'ok' : 'fail');
    }

    $pdo->prepare('UPDATE site_connections SET ml_notification_callback_url = ?, updated_at = NOW() WHERE site_id = ?')
      ->execute([$callbackUrl, $siteId]);

    error_log('[ml_oauth_callback] ML webhook callback configurada site_id=' . $siteId . ' callback=' . $callbackUrl . ' topics=' . implode(',', $topicsSummary));
  } catch (Throwable $subscriptionError) {
    error_log('[ml_oauth_callback] ML subscribe error site_id=' . $siteId . ' err=' . $subscriptionError->getMessage());
  }

  header('Location: sites.php?edit_id=' . $siteId . '&oauth_connected=1');
  exit;
} catch (Throwable $t) {
  header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=exchange');
  exit;
}
