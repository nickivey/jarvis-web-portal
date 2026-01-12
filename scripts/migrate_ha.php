<?php
require_once __DIR__ . '/../db.php';
$pdo = jarvis_pdo();
if (!$pdo) { echo "DB not configured.\n"; exit(1); }

$sql = "
CREATE TABLE IF NOT EXISTS home_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'switch',
  status VARCHAR(32) NOT NULL DEFAULT 'off',
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_hd_user(user_id),
  CONSTRAINT fk_hd_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
  $pdo->exec($sql);
  echo "Migration successful: home_devices table created.\n";
  
  // Seed a sample device for testing
  $check = $pdo->query("SELECT COUNT(*) FROM home_devices")->fetchColumn();
  if ($check == 0) {
      // Find first user
      $uid = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
      if ($uid) {
          $pdo->prepare("INSERT INTO home_devices (user_id, name, type, status) VALUES (?, 'Office Lights', 'light', 'off')")->execute([$uid]);
          echo "Seeded 'Office Lights' for user $uid.\n";
      }
  }
} catch (PDOException $e) {
  echo "Migration failed: " . $e->getMessage() . "\n";
  exit(1);
}
