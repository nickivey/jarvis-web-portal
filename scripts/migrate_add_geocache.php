<?php
require_once __DIR__ . '/../db.php';
$pdo = jarvis_pdo();
if (!$pdo) { echo "DB not configured\n"; exit(1); }
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS location_geocache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lat_round DOUBLE NOT NULL,
  lon_round DOUBLE NOT NULL,
  address_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY ux_latlon_round(lat_round, lon_round)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
$pdo->exec($sql);
echo "location_geocache table ensured\n";
