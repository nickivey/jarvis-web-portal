<?php
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../db.php';

// Prefer an explicit username via env TEST_USER, else try 'demo', else fallback to id=1
$preferredUser = getenv('TEST_USER') ?: 'demo';

try {
  $u = jarvis_user_by_username($preferredUser);
  if ($u && !empty($u['id'])) {
    $t = jarvis_jwt_issue((int)$u['id'], $u['username'], 3600);
    echo $t . PHP_EOL;
    exit(0);
  }
  // Fallback to id=1 if preferred user not found
  $t = jarvis_jwt_issue(1, 'nickivey', 3600);
  echo $t . PHP_EOL;
} catch (Throwable $e) {
  echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
  exit(1);
}
