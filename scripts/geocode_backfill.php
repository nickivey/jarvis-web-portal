<?php
require_once __DIR__ . '/../db.php';
$pdo = jarvis_pdo();
if (!$pdo) { echo "DB not configured\n"; exit(1); }
// iterate distinct rounded lat/lon from location_logs and ensure cache entries exist
$stmt = $pdo->prepare('SELECT DISTINCT ROUND(lat,3) AS latr, ROUND(lon,3) AS lonr FROM location_logs ORDER BY latr, lonr');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($rows) . " distinct rounded coordinates to check\n";
$done = 0; $failed = 0;
foreach ($rows as $r) {
  $lat = (float)$r['latr']; $lon = (float)$r['lonr'];
  // skip if exists
  $q = $pdo->prepare('SELECT id FROM location_geocache WHERE lat_round=:la AND lon_round=:lo LIMIT 1');
  $q->execute([':la'=>$lat, ':lo'=>$lon]);
  if ($q->fetch()) { $done++; continue; }
  // call reverse geocode to populate cache
  try {
    $res = jarvis_reverse_geocode($lat, $lon);
    if ($res) { echo "Cached: {$lat},{$lon} -> " . ($res['city'] ?? ($res['display_name'] ?? '(name)')) . "\n"; $done++; }
    else { echo "No result for: {$lat},{$lon}\n"; $failed++; }
  } catch (Throwable $e) { echo "Error for {$lat},{$lon}: " . $e->getMessage() . "\n"; $failed++; }
  // be gentle with remote service
  usleep(200000); // 200ms
}

echo "Done. Cached: {$done}, failed: {$failed}\n";
