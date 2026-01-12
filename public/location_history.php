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
    <div class="brand">
      <img src="images/logo.svg" alt="JARVIS logo" />
      <span class="dot" aria-hidden="true"></span>
      <span>JARVIS</span>
    </div>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu">☰</button>
    <nav>
      <a href="home.php">Home</a>
      <a href="preferences.php">Preferences</a>
      <a href="audit.php">Audit Log</a>
      <a href="notifications.php">Notifications</a>
      <a href="siri.php">Add to Siri</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
  <main style="padding:18px">
    <h1>Location History</h1>
    <div style="margin-bottom:8px;display:flex;gap:8px;align-items:center">
      <button id="refreshLocationsBtn" class="btn">Refresh</button>
      <span class="muted">Last updated: <span id="locationsUpdatedAt">-</span></span>
    </div>
    <div id="no-locs" class="muted" style="display:none">No location entries yet.</div>
    <div id="map" style="height:320px;border:1px solid #ddd;margin-bottom:12px"></div>
    <table style="width:100%">
      <thead><tr><th>When</th><th>Lat</th><th>Lon</th><th>Accuracy</th><th>Source</th><th></th></tr></thead>
      <tbody id="locationTableBody">
      <?php foreach($locations as $l): ?>
        <tr data-id="<?php echo (int)$l['id']; ?>">
          <td><?php echo htmlspecialchars($l['created_at']); ?></td>
          <td><?php echo htmlspecialchars($l['lat']); ?></td>
          <td><?php echo htmlspecialchars($l['lon']); ?></td>
          <td><?php echo htmlspecialchars($l['accuracy_m']); ?></td>
          <td><?php echo htmlspecialchars($l['source']); ?></td>
          <td><button class="btn secondary focusLocationBtn" data-id="<?php echo (int)$l['id']; ?>">Focus</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </main>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="navbar.js"></script>
  <script>
    (function(){
      const token = <?php echo $webJwt ? json_encode($webJwt) : 'null'; ?>;
      let _map = null;
      function buildMap(locs){
        if (!locs || !locs.length || typeof L === 'undefined') return;
        try{ if (_map) _map.remove(); }catch(e){}
        _map = L.map('map');
        const last = locs[0];
        _map.setView([parseFloat(last.lat), parseFloat(last.lon)], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(_map);
        for (const r of locs){
          const marker = L.marker([parseFloat(r.lat), parseFloat(r.lon)]).addTo(_map);
          marker.bindPopup(`<div><b>${r.source}</b><br>${r.created_at}<br>${parseFloat(r.lat).toFixed(5)}, ${parseFloat(r.lon).toFixed(5)}</div>`);
        }
      }

      async function refresh(){
        try{
          const resp = await fetch('/api/locations?limit=200', { headers: token ? { 'Authorization': 'Bearer ' + token } : {} });
          const d = await resp.json().catch(()=>null);
          if (!d || !d.ok) return;
          const locs = d.locations || [];
          const tbody = document.getElementById('locationTableBody');
          const noLocs = document.getElementById('no-locs');
          if (!locs.length){ if (tbody) tbody.innerHTML=''; if (noLocs) noLocs.style.display='block'; return; } else { if (noLocs) noLocs.style.display='none'; }
          tbody.innerHTML = '';
          locs.forEach(r=>{
            const tr = document.createElement('tr'); tr.setAttribute('data-id', r.id);
            tr.innerHTML = `<td>${r.created_at}</td><td>${r.lat}</td><td>${r.lon}</td><td>${r.accuracy_m || ''}</td><td>${r.source || ''}</td><td><button class="btn secondary focusLocationBtn" data-id="${r.id}">Focus</button></td>`;
            tbody.appendChild(tr);
          });
          buildMap(locs);
          document.getElementById('locationsUpdatedAt').textContent = new Date().toISOString();
          // wire focus buttons
          document.querySelectorAll('.focusLocationBtn').forEach(b=>b.addEventListener('click', ()=>{
            const id = b.getAttribute('data-id');
            const loc = locs.find(x=>String(x.id)===String(id));
            if (loc && _map) { _map.setView([parseFloat(loc.lat), parseFloat(loc.lon)], 13); }
          }));
        }catch(e){}
      }

      document.getElementById('refreshLocationsBtn').addEventListener('click', ()=> refresh());
      // Initial load
      refresh();
    })();
  </script>
</body>
</html>