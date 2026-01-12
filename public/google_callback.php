<?php
session_start();
require_once __DIR__ . '/../db.php';

$state = $_GET['state'] ?? null;
if (!$state || !isset($_SESSION['google_oauth_state']) || $_SESSION['google_oauth_state'] !== $state) {
  jarvis_audit(null, 'OAUTH_CONNECT_FAIL', 'google', ['reason'=>'invalid_state']);
  header('Location: login.php?error=invalid_state'); exit;
}
unset($_SESSION['google_oauth_state']);

$code = $_GET['code'] ?? null;
if (!$code) { header('Location: login.php?error=no_code'); exit; }

$clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
$clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
$redirect = getenv('GOOGLE_REDIRECT_URI') ?: (jarvis_site_url() ? jarvis_site_url() . '/public/google_callback.php' : '');
if (!$clientId || !$clientSecret || !$redirect) {
  jarvis_audit(null, 'OAUTH_CONNECT_FAIL', 'google', ['reason'=>'not_configured']);
  header('Location: login.php?error=google_not_configured'); exit;
}

$body = http_build_query([
  'code' => $code,
  'client_id' => $clientId,
  'client_secret' => $clientSecret,
  'redirect_uri' => $redirect,
  'grant_type' => 'authorization_code'
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $body,
  CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$resp = curl_exec($ch);
$codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($resp === false || $codeHttp < 200 || $codeHttp >= 400) {
  jarvis_audit(null, 'OAUTH_CONNECT_FAIL', 'google', ['reason'=>'token_exchange_failed','resp'=>$resp]);
  header('Location: login.php?error=token_exchange'); exit;
}

$data = json_decode($resp, true);
$accessToken = $data['access_token'] ?? null;
$refreshToken = $data['refresh_token'] ?? null;
$expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : null;
$idToken = $data['id_token'] ?? null;

if (!$idToken) {
  jarvis_audit(null, 'OAUTH_CONNECT_FAIL', 'google', ['reason'=>'no_id_token']);
  header('Location: login.php?error=no_id_token'); exit;
}

$infoObj = jarvis_verify_google_id_token($idToken, $clientId);
if (!$infoObj || empty($infoObj['email']) || empty($infoObj['email_verified'])) {
  jarvis_audit(null, 'OAUTH_CONNECT_FAIL', 'google', ['reason'=>'invalid_id_token']);
  header('Location: login.php?error=invalid_id_token'); exit;
}

$email = (string)$infoObj['email'];
$sub = (string)$infoObj['sub'];
$name = (string)($infoObj['name'] ?? '');

// If user is already logged in, link Google to their account instead of creating/signing in
if (isset($_SESSION['user_id'])) {
  $currentId = (int)$_SESSION['user_id'];
  $expiresAt = $expiresIn ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null;
  jarvis_oauth_set($currentId, 'google', $accessToken, $refreshToken, $expiresAt, 'openid email profile');
  jarvis_audit($currentId, 'OAUTH_CONNECTED', 'google', ['sub'=>$sub,'email'=>$email,'linked_by'=>'user']);
  header('Location: preferences.php?ok=google_connected'); exit;
}

// Find or create user by email
$user = jarvis_user_by_email($email);
if (!$user) {
  // Create a username from the email local part
  $local = preg_replace('/[^a-z0-9_]/', '', strtolower(strstr($email, '@', true) ?: 'googleuser'));
  $username = $local ?: 'googleuser';
  $suffix = '';
  $tries = 0;
  while (jarvis_user_by_username($username . $suffix)) {
    $tries++;
    $suffix = '_' . substr(bin2hex(random_bytes(3)),0,6);
    if ($tries > 10) throw new RuntimeException('Could not make unique username');
  }
  $username = $username . $suffix;
  $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
  $userId = jarvis_create_user($username, $email, null, $passwordHash, null);
  // mark email verified
  jarvis_mark_email_verified($userId);
  $user = jarvis_user_by_id($userId);
  jarvis_audit($userId, 'REGISTER_OAUTH', 'google', ['email'=>$email,'sub'=>$sub,'name'=>$name]);
}

// Ensure email verified flag is set
if (empty($user['email_verified_at'])) jarvis_mark_email_verified((int)$user['id']);

// Store tokens
$expiresAt = $expiresIn ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null;
jarvis_oauth_set((int)$user['id'], 'google', $accessToken, $refreshToken, $expiresAt, 'openid email profile');
jarvis_audit((int)$user['id'], 'OAUTH_CONNECTED', 'google', ['sub'=>$sub,'email'=>$email]);

// Log the user in
$_SESSION['username'] = $user['username'];
$_SESSION['user_id'] = (int)$user['id'];
jarvis_update_last_login((int)$user['id']);
jarvis_audit((int)$user['id'], 'LOGIN_SUCCESS', 'auth', ['provider'=>'google']);

header('Location: home.php'); exit;
