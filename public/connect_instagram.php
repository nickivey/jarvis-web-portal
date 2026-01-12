<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../instagram_basic.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$userId = (int)$_SESSION['user_id'];
$state = bin2hex(random_bytes(16));
$_SESSION['ig_oauth_state'] = $state;

// Basic environment check
if (!getenv('INSTAGRAM_CLIENT_ID') || !getenv('INSTAGRAM_CLIENT_SECRET')) {
  jarvis_audit($userId, 'OAUTH_CONNECT_FAIL', 'instagram', ['reason'=>'missing_client_env']);
  header('Location: preferences.php?err=instagram_env_missing');
  exit;
}

jarvis_audit($userId, 'OAUTH_CONNECT_START', 'instagram', null);
header('Location: ' . jarvis_instagram_auth_url($state));
exit;
?>
