<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) { session_destroy(); header('Location: login.php'); exit; }
$locations = jarvis_recent_locations($userId, 200);
// JWT for browser-side API calls (location, command sync, etc.)
$webJwt = null;
try { $webJwt = jarvis_jwt_issue($userId, $_SESSION['username'], 3600); } catch (Throwable $e) { $webJwt = null; }

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
  <!-- Embedded map (Google Maps iframe) will be used for location previews -->
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
      <thead><tr><th>When</th><th>Location</th><th>Lat</th><th>Lon</th><th>Accuracy</th><th>Source</th><th></th></tr></thead>
      <tbody id="locationTableBody">
      <?php foreach($locations as $l): ?>
        <tr data-id="<?php echo (int)$l['id']; ?>">
          <td><?php echo htmlspecialchars($l['created_at']); ?></td>
          <td><?php echo htmlspecialchars($l['address']['city'] ?? ($l['address']['display_name'] ?? '')); ?></td>
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
  <script src="navbar.js"></script>
  <script>
    (function(){
      const token = <?php echo $webJwt ? json_encode($webJwt) : 'null'; ?>;
      let _map = null;
      function osmEmbedUrl(centerLat, centerLon, zoom=12){
        const lat = parseFloat(centerLat); const lon = parseFloat(centerLon); const delta = 0.02;
        const left = (lon - delta).toFixed(6), bottom = (lat - delta).toFixed(6), right = (lon + delta).toFixed(6), top = (lat + delta).toFixed(6);
        return `https://www.openstreetmap.org/export/embed.html?bbox=${left},${bottom},${right},${top}&layer=mapnik&marker=${lat},${lon}`;
      }
      function setEmbedMap(centerLat, centerLon, zoom=12){
        const el = document.getElementById('map');
        if (!el) return;
        const src = osmEmbedUrl(centerLat, centerLon, zoom);
        el.innerHTML = `<div class="embedMapWrap" data-src="${src}"><iframe class="embedMapIframe" src="${src}" style="width:100%;height:100%;border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe><div class="embedFallback" style="display:none;margin-top:8px;font-size:13px"><a class="openOsm" href="${src.replace('/export/embed.html','/')}" target="_blank">Open in OpenStreetMap</a> • <a class="reloadMap" href="#">Reload map</a></div></div>`;
        const iframe = el.querySelector('iframe');
        const fallback = el.querySelector('.embedFallback');
        if (iframe) {
          let loaded = false;
          iframe.addEventListener('load', ()=>{ loaded = true; if (fallback) fallback.style.display = 'none'; });
          setTimeout(()=>{ try{ if (!loaded && fallback) fallback.style.display = 'block'; }catch(e){} }, 3000);
          const rl = el.querySelector('.reloadMap'); if (rl) rl.addEventListener('click',(ev)=>{ ev.preventDefault(); try{ iframe.src = iframe.src; if (fallback) fallback.style.display='none'; }catch(e){} });
        }
        _map = 'embed';
      }

      function buildMap(locs){
        if (!locs || !locs.length) return;
        const last = locs[0];
        setEmbedMap(last.lat, last.lon, 12);
        // No interactive leaflet markers; instead provide quick focus links in the table and let "Focus" button update the iframe
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
          locs.forEach((r, i)=>{
            const tr = document.createElement('tr'); tr.setAttribute('data-id', r.id);
            tr.className = 'new';
            const addrText = (r.address && (r.address.city || r.address.display_name)) ? (r.address.city || r.address.display_name) : '';
            tr.innerHTML = `<td>${r.created_at}</td><td>${addrText}</td><td>${r.lat}</td><td>${r.lon}</td><td>${r.accuracy_m || ''}</td><td>${r.source || ''}</td><td><button class="btn secondary focusLocationBtn" data-id="${r.id}">Focus</button></td>`;
            tbody.appendChild(tr);
            setTimeout(()=>{ try{ tr.classList.remove('new'); }catch(e){} }, 1000 + (i*40));
          });
          buildMap(locs);
          document.getElementById('locationsUpdatedAt').textContent = new Date().toISOString();
          // wire focus buttons
          document.querySelectorAll('.focusLocationBtn').forEach(b=>b.addEventListener('click', ()=>{
            const id = b.getAttribute('data-id');
            const loc = locs.find(x=>String(x.id)===String(id));
            if (loc) { setEmbedMap(loc.lat, loc.lon, 13); }
          }));
        }catch(e){}
      }

      document.getElementById('refreshLocationsBtn').addEventListener('click', ()=> refresh());
      // Initial load
      refresh();

      // Poll for updates every 30s
      let _lhPoll = setInterval(()=>{ if (document.visibilityState==='visible') refresh(); }, 30000);
    })();
  </script>
</body>
</html>