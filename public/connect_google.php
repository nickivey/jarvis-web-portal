<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$clientId = jarvis_setting_get('GOOGLE_CLIENT_ID') ?: '';
$redirect = jarvis_setting_get('GOOGLE_REDIRECT_URI') ?: (jarvis_site_url() ? jarvis_site_url() . '/public/google_callback.php' : '');
if (!$clientId || !$redirect) {
  header('Location: login.php?error=google_not_configured'); exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = http_build_query([
  'client_id' => $clientId,
  'redirect_uri' => $redirect,
  'response_type' => 'code',
  'scope' => 'openid email profile https://www.googleapis.com/auth/calendar.readonly',
  'state' => $state,
  'access_type' => 'offline',
  'prompt' => 'select_account'
]);

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
header('Location: ' . $authUrl);
exit;
