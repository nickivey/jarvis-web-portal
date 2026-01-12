#!/usr/bin/env php
<?php
// usage: php scripts/set-secret.php KEY VALUE
// Example: php scripts/set-secret.php GOOGLE_CLIENT_ID "..."
require_once __DIR__ . '/../db.php';

if ($argc < 3) {
  echo "Usage: php scripts/set-secret.php KEY VALUE\n";
  exit(1);
}
$key = $argv[1];
$value = $argv[2];
try {
  jarvis_setting_set($key, $value);
  echo "Set $key\n";
} catch (Throwable $e) {
  echo "Error: " . $e->getMessage() . "\n";
  exit(2);
}
