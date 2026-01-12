<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../instagram_basic.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$expectedState = $_SESSION['ig_oauth_state'] ?? null;
unset($_SESSION['ig_oauth_state']);

if (!$code || !$state || !$expectedState || !hash_equals($expectedState, $state)) {
  jarvis_audit($userId, 'OAUTH_CONNECT_FAIL', 'instagram', ['reason'=>'invalid_state']);
  header('Location: preferences.php?err=instagram_state');
  exit;
}

// 1) Exchange authorization code for short-lived token
$short = jarvis_instagram_exchange_code($code);
if (empty($short['access_token'])) {
  jarvis_audit($userId, 'OAUTH_CONNECT_FAIL', 'instagram', ['reason'=>'exchange_code_failed', 'resp'=>$short]);
  header('Location: preferences.php?err=instagram_exchange');
  exit;
}

// 2) Exchange short-lived for long-lived token
$long = jarvis_instagram_exchange_long_lived((string)$short['access_token']);
if (empty($long['access_token'])) {
  // Fall back to short token if long-lived exchange fails
  $token = (string)$short['access_token'];
  $expiresIn = 3600;
  $meta = ['note'=>'using_short_lived_token', 'short_user_id'=>$short['user_id'] ?? null, 'long_resp'=>$long];
} else {
  $token = (string)$long['access_token'];
  $expiresIn = (int)($long['expires_in'] ?? 5184000);
  $meta = ['note'=>'using_long_lived_token', 'short_user_id'=>$short['user_id'] ?? null];
}

$expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, $expiresIn));
jarvis_oauth_set($userId, 'instagram', $token, null, $expiresAt, 'user_profile user_media');

jarvis_audit($userId, 'OAUTH_CONNECTED', 'instagram', $meta);
jarvis_notify($userId, 'success', 'Instagram connected', 'Instagram Basic Display is now linked for media updates.', ['provider'=>'instagram']);

header('Location: preferences.php?ok=instagram_connected');
exit;
?>
