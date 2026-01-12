<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) { session_destroy(); header('Location: login.php'); exit; }
$locations = jarvis_recent_locations($userId, 200);
$focus = isset($_GET['focus']) ? (int)$_GET['focus'] : 0;
$focusLocation = null;
if ($focus > 0) {
  $loc = jarvis_get_location_by_id($focus);
  if ($loc && (int)$loc['user_id'] === $userId) {
    $focusLocation = $loc;
    // ensure focused location appears in the list for rendering
    $found = false;
    foreach ($locations as $l) { if ((int)$l['id'] === (int)$loc['id']) { $found = true; break; } }
    if (!$found) array_unshift($locations, $focusLocation);
  }
}
$focusLat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$focusLon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Location History</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
</head>
<body>
  <div class="navbar">
    <div class="brand">JARVIS</div>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu">☰</button>
    <nav>
      <a href="home.php">Home</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
  <main style="padding:18px">
    <h1>Location History</h1>
    <?php if(empty($locations)): ?>
      <p class="muted">No location entries yet.</p>
    <?php else: ?>
      <div id="map" style="height:320px;border:1px solid #ddd;margin-bottom:12px"></div>
      <table style="width:100%">
        <thead><tr><th>When</th><th>Lat</th><th>Lon</th><th>Accuracy</th><th>Source</th><th></th></tr></thead>
        <tbody>
        <?php foreach($locations as $l): ?>
          <tr>
            <td><?php echo htmlspecialchars($l['created_at']); ?></td>
            <td><?php echo htmlspecialchars($l['lat']); ?></td>
            <td><?php echo htmlspecialchars($l['lon']); ?></td>
            <td><?php echo htmlspecialchars($l['accuracy_m']); ?></td>
            <td><?php echo htmlspecialchars($l['source']); ?></td>
            <td><a href="location_history.php?focus=<?php echo (int)$l['id']; ?>">Focus</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    (function(){
      const locs = <?php echo json_encode($locations); ?>;
      if (!locs || !locs.length) return;
      const map = L.map('map');
      const last = locs[0];
      map.setView([parseFloat(last.lat), parseFloat(last.lon)], 12);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
      let focusId = <?php echo json_encode($focus); ?>;
      let focusLat = <?php echo json_encode($focusLat); ?>;
      let focusLon = <?php echo json_encode($focusLon); ?>;
      let focusLoc = <?php echo json_encode($focusLocation); ?>;
      for (const r of locs) {
        const marker = L.marker([parseFloat(r.lat), parseFloat(r.lon)]).addTo(map);
        marker.bindPopup(`<div><b>${r.source}</b><br>${r.created_at}<br>${parseFloat(r.lat).toFixed(5)}, ${parseFloat(r.lon).toFixed(5)}</div>`);
        if (focusLoc && Number(r.id) === Number(focusLoc.id)) {
          map.setView([parseFloat(r.lat), parseFloat(r.lon)], 13);
          marker.openPopup();
          L.circle([parseFloat(r.lat), parseFloat(r.lon)], { radius: 60, color: 'red', weight: 2 }).addTo(map);
          // highlight table row
          const trs = document.querySelectorAll('tbody tr');
          trs.forEach(tr => {
            if (tr.children && tr.children[1] && tr.children[1].textContent.trim() === String(r.lat)) {
              tr.style.background = '#fff2f2';
            }
          });
        }
        if (!focusLoc && !focusId && focusLat && focusLon) {
          map.setView([parseFloat(focusLat), parseFloat(focusLon)], 13);
        }
      }
    })();
  </script>
</body>
</html>