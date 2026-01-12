<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$deviceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($deviceId <= 0) { header('Location: preferences.php?err=invalid_device'); exit; }
jarvis_unregister_device($userId, $deviceId);
jarvis_audit($userId, 'DEVICE_DISCONNECTED', 'device', ['device_id'=>$deviceId]);
header('Location: preferences.php?ok=device_disconnected'); exit;
