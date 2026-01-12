<?php
/**
 * Simple worker to process pending jobs. Usage:
 *  php scripts/photo_worker.php process_once
 *  php scripts/photo_worker.php run (runs indefinitely with sleep)
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
$mode = $argv[1] ?? 'process_once';
function process_one_job(): bool {
  // Use the in-process helper for a single job so tests can call it directly as well
  $res = jarvis_process_one_job_inprocess();
  if (!$res) {
    echo "No pending jobs or job failed\n";
    $pdo = jarvis_pdo();
    if ($pdo) {
      $stmt = $pdo->query("SELECT id,type,status,available_at,created_at,payload_json FROM jobs ORDER BY id DESC LIMIT 10");
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      echo "Jobs (latest 10): " . json_encode($rows) . "\n";
    }
    return false;
  }
  echo "Job processed in-process\n";
  return true;
}
if ($mode === 'run') {
  while (true) {
    process_one_job();
    sleep(5);
  }
} else {
  exit(process_one_job() ? 0 : 1);
}
