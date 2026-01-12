<?php
// Usage: php scripts/create-admin.php <email> [<display_name>]
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if ($argc < 2) {
  echo "Usage: php scripts/create-admin.php <email> [<display_name>]\n";
  exit(2);
}
$email = $argv[1];
$name = $argv[2] ?? 'Admin User';
$pdo = jarvis_pdo();
if (!$pdo) {
  echo "DB not configured.\n";
  exit(2);
}
// Check if user exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e');
$stmt->execute([':e'=>$email]);
$row = $stmt->fetch();
if ($row) {
  $pdo->prepare('UPDATE users SET role = "admin" WHERE id = :id')->execute([':id'=>$row['id']]);
  echo "Promoted existing user ({$email}) to admin.\n";
  exit(0);
}
// Create a simple user with random password
$pw = bin2hex(random_bytes(6));
$password_hash = password_hash($pw, PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users (email, display_name, password_hash, role, created_at) VALUES (:e,:n,:p,:r,NOW())')
  ->execute([':e'=>$email, ':n'=>$name, ':p'=>$password_hash, ':r'=>'admin']);
echo "Created admin user: {$email} with temporary password: {$pw}\n";
echo "Login at /login.php and then change password.\n";
