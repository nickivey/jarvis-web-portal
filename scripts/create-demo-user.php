<?php
require_once __DIR__ . '/../db.php';
if (php_sapi_name() !== 'cli') { echo "Run from CLI\n"; exit(2); }
$u = 'demo';
$pw = 'password';
$pdo = jarvis_pdo();
if (!$pdo) { echo "DB not configured\n"; exit(2); }
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
$stmt->execute([':u'=>$u]);
$row = $stmt->fetch();
$pwHash = password_hash($pw, PASSWORD_DEFAULT);
if ($row) {
  $pdo->prepare('UPDATE users SET email=:e, password_hash=:p, email_verified_at=NOW() WHERE id=:id')
      ->execute([':e'=>$u.'@example.com', ':p'=>$pwHash, ':id'=>$row['id']]);
  echo "Updated user {$u} with password '{$pw}'\n";
  exit(0);
}
$pdo->prepare('INSERT INTO users (username,email,password_hash,role,email_verified_at,created_at) VALUES (:u,:e,:p,:r,NOW(),NOW())')
  ->execute([':u'=>$u, ':e'=>$u.'@example.com', ':p'=>$pwHash, ':r'=>'user']);
$id = $pdo->lastInsertId();
$pdo->prepare('INSERT INTO preferences (user_id) VALUES (:id)')->execute([':id' => $id]);
echo "Created demo user id={$id} username={$u} password={$pw}\n";
