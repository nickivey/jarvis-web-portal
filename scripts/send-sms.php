#!/usr/bin/env php
<?php
// usage: php scripts/send-sms.php TO_NUMBER "Message text"
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

if ($argc < 3) {
  echo "Usage: php scripts/send-sms.php TO_NUMBER \"Message text\"\n";
  exit(1);
}
$to = $argv[1];
$text = $argv[2];

if (jarvis_send_sms($to, $text)) {
  echo "Message queued/sent (Twilio returned success)\n";
  exit(0);
} else {
  echo "Message failed (see logs)\n";
  exit(2);
}
