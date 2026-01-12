#!/usr/bin/env php
<?php
// usage: php scripts/seed-admin.php <email> [<display_name>] [<password>]
require_once __DIR__ . '/../db.php';

if ($argc < 2) {
  echo "Usage: php scripts/seed-admin.php <email> [<display_name>] [<password>]\n";
  exit(1);
}
$email = $argv[1];
$name = $argv[2] ?? ($email);
$pw = $argv[3] ?? null;
$pdo = jarvis_pdo();
if (!$pdo) { echo "DB not configured\n"; exit(2); }
$stmt = $pdo->prepare('SELECT id FROM users WHERE email=:e LIMIT 1');
$stmt->execute([':e'=>$email]);
$row = $stmt->fetch();
if ($row) {
  $id = (int)$row['id'];
  $pdo->prepare('UPDATE users SET role="admin" WHERE id=:id')->execute([':id'=>$id]);
  echo "Promoted existing user {$email} (id={$id}) to admin.\n";
  exit(0);
}

// create user
$pw = $pw ?: substr(bin2hex(random_bytes(6)),0,12);
$token = bin2hex(random_bytes(24));
$pdo->prepare('INSERT INTO users (username,email,password_hash,email_verify_token) VALUES (:u,:e,:h,:t)')
    ->execute([':u'=>$name, ':e'=>$email, ':h'=>password_hash($pw, PASSWORD_DEFAULT), ':t'=>$token]);
$id = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO preferences (user_id) VALUES (:id)')->execute([':id'=>$id]);
$pdo->prepare('UPDATE users SET role="admin" WHERE id=:id')->execute([':id'=>$id]);

echo "Created admin user: {$email} with temporary password: {$pw}\n";
echo "Please verify email {$email} or use the Admin UI to resend confirmation.\n";
