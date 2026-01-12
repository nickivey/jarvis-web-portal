<?php
require_once __DIR__ . '/../db.php';
$pdo = jarvis_pdo();

try {
  $pdo->exec("ALTER TABLE audit_log ADD COLUMN voice_input_id BIGINT UNSIGNED NULL");
  $pdo->exec("ALTER TABLE audit_log ADD CONSTRAINT fk_audit_voice FOREIGN KEY(voice_input_id) REFERENCES voice_inputs(id) ON DELETE SET NULL");
  echo "Added voice_input_id to audit_log\n";
} catch (Exception $e) {
  echo "Column likely exists or error: " . $e->getMessage() . "\n";
}
