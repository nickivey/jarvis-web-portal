<?php
require_once __DIR__ . '/../jwt.php';
try {
  $t = jarvis_jwt_issue(1, 'nickivey', 3600);
  echo $t . PHP_EOL;
} catch (Throwable $e) {
  echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
  exit(1);
}
