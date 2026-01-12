<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
jarvis_oauth_delete($userId, 'google');
jarvis_audit($userId, 'OAUTH_DISCONNECTED', 'google', null);
header('Location: preferences.php?ok=google_disconnected'); exit;
