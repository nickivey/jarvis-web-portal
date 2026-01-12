<?php
// Usage: php scripts/set-user-password.php <username> <new_password>
require_once __DIR__ . '/../db.php';
if ($argc < 3) { echo "Usage: php scripts/set-user-password.php <username> <new_password>\n"; exit(2); }
$username = $argv[1];
$newpw = $argv[2];
$u = jarvis_user_by_username($username);
if (!$u) { echo "User not found: $username\n"; exit(1); }
$hash = password_hash($newpw, PASSWORD_DEFAULT);
$pdo = jarvis_pdo();
if (!$pdo) { echo "DB not configured\n"; exit(3); }
$stmt = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
$stmt->execute([':h'=>$hash, ':id'=>$u['id']]);
$ok = password_verify($newpw, $pdo->prepare('SELECT password_hash FROM users WHERE id=:id')->execute([':id'=>$u['id']]) ? ($pdo->query("SELECT password_hash FROM users WHERE id={$u['id']}")->fetchColumn()) : '');
// verify
$row = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
$row->execute([':id'=>$u['id']]);
$ph = $row->fetchColumn();
if ($ph && password_verify($newpw, $ph)) {
  echo "Password for user {$username} set successfully.\n";
  // audit
  $stmt = $pdo->prepare('INSERT INTO audit_log (user_id,action,entity,metadata_json,ip,user_agent) VALUES (NULL,:a,:e,:m,:ip,:ua)');
  $stmt->execute([':a'=>'PASSWORD_SET_BY_ADMIN', ':e'=>'admin', ':m'=>json_encode(['target_username'=>$username,'method'=>'cli','note'=>'set to provided value']), ':ip'=>null, ':ua'=>null]);
  exit(0);
}
echo "Failed to set password for $username\n"; exit(4);
