<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
if (php_sapi_name() !== 'cli') { echo "Run from CLI\n"; exit(2); }
$user = $argv[1] ?? 'e2e_bot';
$u = jarvis_user_by_username($user);
if (!$u) { echo "User not found\n"; exit(2); }
$token = jarvis_jwt_issue((int)$u['id'], $u['username'], 3600);
echo $token . "\n";
