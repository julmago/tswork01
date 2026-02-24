<?php
if (!function_exists('env')) {
  function env(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
      return $default;
    }
    return $value;
  }
}
return [
  'gateway_email' => env('GATEWAY_EMAIL', 'tswork@tsmayorista.com.ar'),
  'gateway_password_hash' => env(
    'GATEWAY_PASSWORD_HASH',
    '$2y$12$.R6KXw1K7AlBlj/4SXLc8.ghhSO5k70FZVbe/WmY3YuEFn0xVjTPm'
  ),
  'session_lifetime_days' => (int)env('SESSION_LIFETIME_DAYS', '365'),
  'gateway_cookie_lifetime_days' => (int)env('GATEWAY_COOKIE_LIFETIME_DAYS', '365'),
  'ml_oauth_state_secret' => env('ML_OAUTH_STATE_SECRET', env('GATEWAY_PASSWORD_HASH', 'change-me-ml-oauth-secret')),
];
