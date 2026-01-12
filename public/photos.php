<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../helpers.php';
[$uid, $u] = require_jwt_user();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Photos — JARVIS</title>
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-sA+e2m4Rj6y3p8nZrjY2g9eXKpJpQmXkM3v0u5wA9hM=" crossorigin="" />
  <style>
    /* compact gallery overrides */
    .photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
    .photo-card{border-radius:12px;overflow:hidden;position:relative;background:linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));border:1px solid rgba(255,255,255,.03)}
    .photo-card img{width:100%;height:160px;object-fit:cover;display:block;filter:grayscale(50%) contrast(.95);transition:transform .18s ease, filter .18s ease}
    .photo-card:hover img{filter:grayscale(0%) contrast(1.02);transform:scale(1.02)}
    .photo-meta{padding:8px;font-size:13px;color:var(--muted);display:flex;flex-direction:column;gap:6px}
    .gallery-empty{color:var(--muted);padding:28px;text-align:center}
    /* timeline & map views */
    #photosMap{height:360px;border-radius:12px;border:1px solid rgba(255,255,255,.03);margin-bottom:12px}
    .timeline{max-height:360px;overflow:auto;padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.03);background:linear-gradient(180deg, rgba(255,255,255,.01), rgba(255,255,255,.00));}
    .timeline-item{padding:8px;border-bottom:1px solid rgba(255,255,255,.02)}
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container">
  <h1>My Photos</h1>
  <p class="muted">Uploaded photos from iOS Shortcuts or other sources. Click a photo to view full size. Use the map to find photos with GPS EXIF.</p>

  <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px">
    <div style="flex:2;min-width:320px">
      <div id="photosMap"></div>
    </div>
    <div style="flex:1;min-width:260px">
      <div class="timeline" id="photosTimeline"></div>
    </div>
  </div>

  <div id="photoGrid" class="photo-grid" style="margin-top:18px"></div>
  <div id="emptyMsg" class="gallery-empty" style="display:none">No photos yet — try uploading via the <a href="/public/ios_photos.php">iOS setup</a>.</div>
</div>
<script>
(async function(){
  try{
    const grid = document.getElementById('photoGrid');
    const resp = await fetch('/api/photos?limit=200', { headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') } });
    const j = await resp.json();
    if (!j || !j.photos || !j.photos.length) { document.getElementById('emptyMsg').style.display='block'; return; }
    j.photos.forEach(p=>{
      const c = document.createElement('div'); c.className='photo-card';
      const img = document.createElement('img'); img.src='/api/photos/'+p.id+'/download?thumb=1'; img.alt = p.original_filename || '';
      img.addEventListener('click', ()=>{ openModal(p.id, p.original_filename); });
      const meta = document.createElement('div'); meta.className='photo-meta';
      let left = '<span>' + (p.original_filename||'') + '</span>';
      let right = '<span>' + (new Date(p.created_at||'')).toLocaleString() + '</span>';
      if (p.metadata && p.metadata.exif_datetime) left += '<div style="font-size:12px;color:var(--muted)">Taken: ' + p.metadata.exif_datetime + '</div>';
      if (p.metadata && p.metadata.exif_gps) {
        const g = p.metadata.exif_gps;
        right = '<span><a href="/public/location_history.php?lat=' + encodeURIComponent(g.lat) + '&lon=' + encodeURIComponent(g.lon) + '">Location</a></span>' + right;
      } else if (p.metadata && p.metadata.photo_location_id) {
        right = '<span><a href="/public/location_history.php?location_id=' + encodeURIComponent(p.metadata.photo_location_id) + '">Location</a></span>' + right;
      }
      meta.innerHTML = left + right;
      c.appendChild(img); c.appendChild(meta); grid.appendChild(c);
    });
  } catch(e){ console.error(e); document.getElementById('emptyMsg').style.display='block'; }
})();
function openModal(id, title){
  let modal = document.getElementById('photoViewModal');
  if (!modal) {
    modal = document.createElement('div'); modal.id='photoViewModal'; modal.style.position='fixed'; modal.style.inset='0'; modal.style.display='flex'; modal.style.alignItems='center'; modal.style.justifyContent='center'; modal.style.background='rgba(10,12,14,0.86)'; modal.style.zIndex=9999; modal.style.padding='24px';
    const img = document.createElement('img'); img.id='photoViewImg'; img.style.maxWidth='90%'; img.style.maxHeight='90%'; img.style.borderRadius='12px'; img.style.boxShadow='0 28px 80px rgba(0,0,0,.6)';
    const caption = document.createElement('div'); caption.id='photoViewCaption'; caption.style.color='var(--muted)'; caption.style.marginTop='12px'; caption.style.textAlign='center'; caption.style.fontSize='13px';
    const wrap = document.createElement('div'); wrap.style.display='flex'; wrap.style.flexDirection='column'; wrap.style.alignItems='center'; wrap.appendChild(img); wrap.appendChild(caption);
    modal.appendChild(wrap);
    modal.addEventListener('click', (e)=>{ if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
  }
  document.getElementById('photoViewImg').src = '/api/photos/' + id + '/download';
  document.getElementById('photoViewCaption').textContent = title || '';
}
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-o9N1jGgEN5QVDf6m5Yk0iZg+9VQ2s5G5m7r4b1o3pEo=" crossorigin=""></script>
<script>
(async function(){
  try{
    const grid = document.getElementById('photoGrid');
    const resp = await fetch('/api/photos?limit=200', { headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') } });
    const j = await resp.json();
    if (!j || !j.photos || !j.photos.length) { document.getElementById('emptyMsg').style.display='block'; return; }

    // Initialize map
    const mapEl = document.getElementById('photosMap');
    const map = L.map(mapEl, { center: [0,0], zoom: 2, scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    const markers = L.layerGroup().addTo(map);

    // Timeline list
    const timeline = document.getElementById('photosTimeline'); timeline.innerHTML='';

    j.photos.forEach(p=>{
      // Grid
      const c = document.createElement('div'); c.className='photo-card';
      const img = document.createElement('img'); img.src='/api/photos/'+p.id+'/download?thumb=1'; img.alt = p.original_filename || '';
      img.addEventListener('click', ()=>{ openModal(p.id, p.original_filename); });
      const meta = document.createElement('div'); meta.className='photo-meta';
      let left = '<span>' + (p.original_filename||'') + '</span>';
      if (p.metadata && p.metadata.exif_datetime) left += '<div style="font-size:12px;color:var(--muted)">Taken: ' + p.metadata.exif_datetime + '</div>';
      let right = '<span>' + (new Date(p.created_at||'')).toLocaleString() + '</span>';
      if (p.metadata && p.metadata.exif_gps) {
        const g = p.metadata.exif_gps;
        right = '<span><a href="/public/location_history.php?lat=' + encodeURIComponent(g.lat) + '&lon=' + encodeURIComponent(g.lon) + '">Location</a></span>' + right;
      } else if (p.metadata && p.metadata.photo_location_id) {
        right = '<span><a href="/public/location_history.php?location_id=' + encodeURIComponent(p.metadata.photo_location_id) + '">Location</a></span>' + right;
      }
      meta.innerHTML = left + right;
      c.appendChild(img); c.appendChild(meta); grid.appendChild(c);

      // Timeline
      const ti = document.createElement('div'); ti.className='timeline-item'; ti.innerHTML = '<strong>' + (p.original_filename||'') + '</strong><div style="color:var(--muted);font-size:13px">' + (p.metadata && p.metadata.exif_datetime ? p.metadata.exif_datetime : (p.created_at||'')) + '</div>';
      timeline.appendChild(ti);

      // Map markers for GPS photos
      if (p.metadata && p.metadata.exif_gps && p.metadata.exif_gps.lat && p.metadata.exif_gps.lon) {
        try {
          const g = p.metadata.exif_gps;
          const marker = L.marker([g.lat, g.lon]).addTo(markers);
          marker.bindPopup('<div style="text-align:center"><img src="/api/photos/'+p.id+'/download?thumb=1" style="width:120px;height:80px;object-fit:cover;border-radius:8px;margin-bottom:6px"><div>' + (p.original_filename||'') + '</div></div>');
        } catch(e) {}
      }
    });

    // Fit map to markers bounds if there are markers
    if (markers.getLayers().length > 0) {
      const bounds = markers.getBounds(); map.fitBounds(bounds.pad(0.2));
    }

  } catch(e){ console.error(e); document.getElementById('emptyMsg').style.display='block'; }
})();
</script>
</body></html>