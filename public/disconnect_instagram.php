<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];

jarvis_oauth_delete($userId, 'instagram');
jarvis_audit($userId, 'OAUTH_DISCONNECTED', 'instagram', null);
jarvis_notify($userId, 'info', 'Instagram disconnected', 'Instagram token removed from JARVIS.', ['provider'=>'instagram']);

header('Location: preferences.php?ok=instagram_disconnected');
exit;
?>