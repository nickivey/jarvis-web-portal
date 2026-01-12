<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../helpers.php';

if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { session_destroy(); header('Location: login.php'); exit; }
$u = jarvis_user_by_id($uid);
if (!$u) { session_destroy(); header('Location: login.php'); exit; }

// Get photo stats
$pdo = jarvis_pdo();
$stats = ['total' => 0, 'with_gps' => 0, 'this_month' => 0];
if ($pdo) {
  try {
    $stmt = $pdo->prepare('SELECT COUNT(*) as total FROM photos WHERE user_id = :u');
    $stmt->execute([':u' => $uid]);
    $stats['total'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM photos WHERE user_id = :u AND metadata LIKE '%exif_gps%'");
    $stmt->execute([':u' => $uid]);
    $stats['with_gps'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE user_id = :u AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $stmt->execute([':u' => $uid]);
    $stats['this_month'] = (int)$stmt->fetchColumn();
  } catch (Exception $e) {}
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Photo Gallery — JARVIS</title>
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
  <style>
    .gallery-page {
      max-width: 1400px;
      margin: 0 auto;
    }
    
    /* Gallery Header */
    .gallery-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 24px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }
    .gallery-title-section h1 {
      margin: 0 0 8px 0;
      font-size: 2rem;
      background: linear-gradient(90deg, #fff, #d8b4fe);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .gallery-title-section p {
      margin: 0;
      color: var(--muted);
    }
    
    /* Stats Cards */
    .gallery-stats {
      display: flex;
      gap: 12px;
    }
    .stat-card {
      background: linear-gradient(135deg, rgba(200, 100, 255, 0.1), rgba(255, 150, 200, 0.05));
      border: 1px solid rgba(200, 100, 255, 0.2);
      border-radius: 12px;
      padding: 14px 20px;
      text-align: center;
      min-width: 100px;
    }
    .stat-value {
      font-size: 1.5rem;
      font-weight: 700;
      color: #d8b4fe;
    }
    .stat-label {
      font-size: 0.75rem;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    /* Controls Bar */
    .gallery-controls {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .view-tabs {
      display: flex;
      background: rgba(0, 0, 0, 0.3);
      border-radius: 10px;
      padding: 4px;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    .view-tab {
      padding: 8px 16px;
      border-radius: 8px;
      background: transparent;
      border: none;
      color: var(--muted);
      cursor: pointer;
      font-size: 0.9rem;
      transition: all 0.2s ease;
    }
    .view-tab.active {
      background: rgba(200, 100, 255, 0.2);
      color: #d8b4fe;
    }
    .view-tab:hover:not(.active) {
      color: #fff;
    }
    .filter-controls {
      display: flex;
      gap: 12px;
      align-items: center;
    }
    .filter-btn {
      padding: 8px 14px;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: var(--muted);
      cursor: pointer;
      font-size: 0.85rem;
      transition: all 0.2s ease;
    }
    .filter-btn.active, .filter-btn:hover {
      background: rgba(200, 100, 255, 0.15);
      border-color: rgba(200, 100, 255, 0.3);
      color: #d8b4fe;
    }
    
    /* Main Layout */
    .gallery-layout {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
    }
    @media (max-width: 1000px) {
      .gallery-layout {
        grid-template-columns: 1fr;
      }
    }
    
    /* Photo Grid */
    .photo-grid-container {
      background: rgba(0, 0, 0, 0.2);
      border-radius: 16px;
      padding: 16px;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    .photo-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 12px;
    }
    .photo-card {
      position: relative;
      aspect-ratio: 1;
      border-radius: 12px;
      overflow: hidden;
      cursor: pointer;
      transition: all 0.3s ease;
      background: rgba(255, 255, 255, 0.02);
    }
    .photo-card:hover {
      transform: scale(1.03);
      z-index: 2;
      box-shadow: 0 12px 35px rgba(0, 0, 0, 0.4);
    }
    .photo-card img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      filter: grayscale(25%) contrast(0.95);
      transition: filter 0.3s ease;
    }
    .photo-card:hover img {
      filter: grayscale(0%) contrast(1.02);
    }
    .photo-card-overlay {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      padding: 10px;
      background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    .photo-card:hover .photo-card-overlay {
      opacity: 1;
    }
    .photo-card-name {
      font-size: 0.8rem;
      color: #fff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .photo-card-date {
      font-size: 0.7rem;
      color: rgba(255, 255, 255, 0.6);
    }
    .photo-card-badges {
      position: absolute;
      top: 8px;
      right: 8px;
      display: flex;
      gap: 4px;
    }
    .photo-badge {
      width: 24px;
      height: 24px;
      border-radius: 6px;
      background: rgba(0, 0, 0, 0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
    }
    
    /* Sidebar */
    .gallery-sidebar {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    
    /* Map Panel */
    .map-panel {
      background: rgba(0, 0, 0, 0.2);
      border-radius: 16px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    .map-panel-header {
      padding: 14px 16px;
      background: rgba(0, 0, 0, 0.3);
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .map-panel-title {
      font-size: 0.95rem;
      font-weight: 600;
      color: #fff;
    }
    #photosMap {
      height: 280px;
    }
    
    /* Timeline Panel */
    .timeline-panel {
      background: rgba(0, 0, 0, 0.2);
      border-radius: 16px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.05);
      flex: 1;
    }
    .timeline-panel-header {
      padding: 14px 16px;
      background: rgba(0, 0, 0, 0.3);
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }
    .timeline-panel-title {
      font-size: 0.95rem;
      font-weight: 600;
      color: #fff;
    }
    .timeline-list {
      max-height: 300px;
      overflow-y: auto;
      padding: 12px;
    }
    .timeline-item {
      display: flex;
      gap: 12px;
      padding: 10px;
      border-radius: 10px;
      transition: background 0.2s ease;
      cursor: pointer;
    }
    .timeline-item:hover {
      background: rgba(200, 100, 255, 0.1);
    }
    .timeline-thumb {
      width: 48px;
      height: 48px;
      border-radius: 8px;
      object-fit: cover;
      flex-shrink: 0;
    }
    .timeline-info {
      flex: 1;
      min-width: 0;
    }
    .timeline-name {
      font-size: 0.85rem;
      color: #fff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .timeline-date {
      font-size: 0.75rem;
      color: var(--muted);
    }
    
    /* Empty State */
    .gallery-empty {
      text-align: center;
      padding: 60px 24px;
      color: var(--muted);
    }
    .gallery-empty-icon {
      font-size: 4rem;
      margin-bottom: 16px;
      opacity: 0.4;
    }
    .gallery-empty h3 {
      color: #fff;
      margin-bottom: 8px;
    }
    
    /* Upload Section */
    .upload-section {
      background: linear-gradient(135deg, rgba(200, 100, 255, 0.1), rgba(100, 200, 255, 0.05));
      border: 2px dashed rgba(200, 100, 255, 0.3);
      border-radius: 16px;
      padding: 24px;
      text-align: center;
    }
    .upload-section h4 {
      margin: 0 0 8px 0;
      color: #d8b4fe;
    }
    .upload-section p {
      margin: 0 0 16px 0;
      color: var(--muted);
      font-size: 0.9rem;
    }
    
    /* Photo Modal */
    .photo-modal {
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(10, 10, 15, 0.95);
      backdrop-filter: blur(12px);
      z-index: 9999;
      padding: 24px;
    }
    .photo-modal-content {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      max-width: 90vw;
      max-height: 90vh;
    }
    .photo-modal-close {
      position: absolute;
      top: -48px;
      right: 0;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      color: #fff;
      font-size: 1.5rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }
    .photo-modal-close:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: scale(1.1);
    }
    .photo-modal-img {
      max-width: 100%;
      max-height: 70vh;
      border-radius: 16px;
      box-shadow: 0 30px 90px rgba(0, 0, 0, 0.6);
    }
    .photo-modal-info {
      margin-top: 20px;
      text-align: center;
    }
    .photo-modal-caption {
      font-size: 1.1rem;
      color: #fff;
      margin-bottom: 8px;
    }
    .photo-modal-meta {
      display: flex;
      justify-content: center;
      gap: 20px;
      font-size: 0.9rem;
      color: var(--muted);
    }
    .photo-modal-actions {
      display: flex;
      gap: 12px;
      margin-top: 20px;
    }
    
    /* Loading */
    .loading-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 12px;
    }
    .loading-card {
      aspect-ratio: 1;
      border-radius: 12px;
      background: linear-gradient(90deg, rgba(255,255,255,0.03) 0%, rgba(255,255,255,0.08) 50%, rgba(255,255,255,0.03) 100%);
      background-size: 200% 100%;
      animation: shimmer 1.5s ease-in-out infinite;
    }
    @keyframes shimmer {
      0% { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container gallery-page">
  
  <!-- Header -->
  <div class="gallery-header">
    <div class="gallery-title-section">
      <h1>📸 Photo Gallery</h1>
      <p>Your uploaded photos from iOS Shortcuts and other sources</p>
    </div>
    <div class="gallery-stats">
      <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Photos</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?php echo $stats['with_gps']; ?></div>
        <div class="stat-label">With Location</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?php echo $stats['this_month']; ?></div>
        <div class="stat-label">This Month</div>
      </div>
    </div>
  </div>
  
  <!-- Controls -->
  <div class="gallery-controls">
    <div class="view-tabs">
      <button class="view-tab active" data-view="grid">🖼️ Grid</button>
      <button class="view-tab" data-view="timeline">📅 Timeline</button>
      <button class="view-tab" data-view="map">🗺️ Map</button>
    </div>
    <div class="filter-controls">
      <button class="filter-btn active" id="filterAll">All</button>
      <button class="filter-btn" id="filterGps">📍 With GPS</button>
      <a href="/ios_upload_setup.php" class="btn secondary">📱 iOS Upload Setup</a>
    </div>
  </div>
  
  <!-- Main Layout -->
  <div class="gallery-layout">
    <!-- Photo Grid -->
    <div class="photo-grid-container">
      <div id="photoGrid" class="photo-grid">
        <div class="loading-grid" id="loadingGrid">
          <div class="loading-card"></div>
          <div class="loading-card"></div>
          <div class="loading-card"></div>
          <div class="loading-card"></div>
          <div class="loading-card"></div>
          <div class="loading-card"></div>
        </div>
      </div>
      <div id="emptyMsg" class="gallery-empty" style="display:none">
        <div class="gallery-empty-icon">📷</div>
        <h3>No photos yet</h3>
        <p>Upload photos from your iPhone using iOS Shortcuts</p>
        <a href="/ios_upload_setup.php" class="btn">Set Up iOS Upload</a>
      </div>
    </div>
    
    <!-- Sidebar -->
    <div class="gallery-sidebar">
      <!-- Map Panel -->
      <div class="map-panel">
        <div class="map-panel-header">
          <span class="map-panel-title">📍 Photo Locations</span>
          <span id="gpsCount" class="badge">0 photos</span>
        </div>
        <div id="photosMap"></div>
      </div>
      
      <!-- Timeline Panel -->
      <div class="timeline-panel">
        <div class="timeline-panel-header">
          <span class="timeline-panel-title">📅 Recent Uploads</span>
        </div>
        <div class="timeline-list" id="photosTimeline"></div>
      </div>
      
      <!-- Upload Section -->
      <div class="upload-section">
        <h4>📱 Upload from iPhone</h4>
        <p>Set up automatic photo uploads using iOS Shortcuts</p>
        <a href="/ios_upload_setup.php" class="btn">Get Started</a>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
let allPhotos = [];
let map = null;
let markers = null;

// Initialize map
function initMap() {
  const mapEl = document.getElementById('photosMap');
  map = L.map(mapEl, { center: [0, 0], zoom: 2, scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
  markers = L.layerGroup().addTo(map);
}

// Load photos
async function loadPhotos() {
  try {
    const resp = await fetch('/api/photos?limit=200', { 
      headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') } 
    });
    const j = await resp.json();
    
    document.getElementById('loadingGrid').style.display = 'none';
    
    if (!j || !j.photos || !j.photos.length) {
      document.getElementById('emptyMsg').style.display = 'block';
      return;
    }
    
    allPhotos = j.photos;
    renderPhotos(allPhotos);
    renderTimeline(allPhotos);
    renderMapMarkers(allPhotos);
    
  } catch (e) {
    console.error(e);
    document.getElementById('loadingGrid').style.display = 'none';
    document.getElementById('emptyMsg').style.display = 'block';
  }
}

function renderPhotos(photos) {
  const grid = document.getElementById('photoGrid');
  grid.innerHTML = '';
  
  photos.forEach(p => {
    const card = document.createElement('div');
    card.className = 'photo-card';
    card.innerHTML = `
      <img src="/api/photos/${p.id}/download?thumb=1" alt="${p.original_filename || ''}" loading="lazy" />
      <div class="photo-card-badges">
        ${p.metadata?.exif_gps ? '<span class="photo-badge">📍</span>' : ''}
        ${p.metadata?.exif_datetime ? '<span class="photo-badge">📅</span>' : ''}
      </div>
      <div class="photo-card-overlay">
        <div class="photo-card-name">${p.original_filename || 'Photo'}</div>
        <div class="photo-card-date">${p.metadata?.exif_datetime || new Date(p.created_at).toLocaleDateString()}</div>
      </div>
    `;
    card.addEventListener('click', () => openModal(p));
    grid.appendChild(card);
  });
}

function renderTimeline(photos) {
  const timeline = document.getElementById('photosTimeline');
  timeline.innerHTML = '';
  
  photos.slice(0, 10).forEach(p => {
    const item = document.createElement('div');
    item.className = 'timeline-item';
    item.innerHTML = `
      <img src="/api/photos/${p.id}/download?thumb=1" class="timeline-thumb" alt="" />
      <div class="timeline-info">
        <div class="timeline-name">${p.original_filename || 'Photo'}</div>
        <div class="timeline-date">${p.metadata?.exif_datetime || new Date(p.created_at).toLocaleString()}</div>
      </div>
    `;
    item.addEventListener('click', () => openModal(p));
    timeline.appendChild(item);
  });
}

function renderMapMarkers(photos) {
  if (!markers) return;
  markers.clearLayers();
  
  let gpsCount = 0;
  
  photos.forEach(p => {
    if (p.metadata?.exif_gps?.lat && p.metadata?.exif_gps?.lon) {
      gpsCount++;
      const g = p.metadata.exif_gps;
      const marker = L.marker([g.lat, g.lon]).addTo(markers);
      marker.bindPopup(`
        <div style="text-align:center">
          <img src="/api/photos/${p.id}/download?thumb=1" 
               style="width:140px;height:100px;object-fit:cover;border-radius:8px;margin-bottom:8px" />
          <div style="font-weight:600">${p.original_filename || 'Photo'}</div>
          <div style="font-size:12px;color:#666">${p.metadata?.exif_datetime || ''}</div>
        </div>
      `);
    }
  });
  
  document.getElementById('gpsCount').textContent = gpsCount + ' photos';
  
  if (markers.getLayers().length > 0) {
    map.fitBounds(markers.getBounds().pad(0.2));
  }
}

function openModal(photo) {
  let modal = document.getElementById('photoViewModal');
  if (modal) modal.remove();
  
  modal = document.createElement('div');
  modal.id = 'photoViewModal';
  modal.className = 'photo-modal';
  modal.innerHTML = `
    <div class="photo-modal-content">
      <button class="photo-modal-close">&times;</button>
      <img class="photo-modal-img" src="/api/photos/${photo.id}/download" />
      <div class="photo-modal-info">
        <div class="photo-modal-caption">${photo.original_filename || 'Photo'}</div>
        <div class="photo-modal-meta">
          ${photo.metadata?.exif_datetime ? `<span>📅 ${photo.metadata.exif_datetime}</span>` : ''}
          ${photo.metadata?.exif_gps ? `<span>📍 ${photo.metadata.exif_gps.lat.toFixed(4)}, ${photo.metadata.exif_gps.lon.toFixed(4)}</span>` : ''}
          <span>📤 ${new Date(photo.created_at).toLocaleString()}</span>
        </div>
      </div>
      <div class="photo-modal-actions">
        <a href="/api/photos/${photo.id}/download" download class="btn secondary">⬇️ Download</a>
        ${photo.metadata?.exif_gps ? 
          `<a href="/public/location_history.php?lat=${photo.metadata.exif_gps.lat}&lon=${photo.metadata.exif_gps.lon}" class="btn secondary">📍 View Location</a>` 
          : ''}
      </div>
    </div>
  `;
  
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
  modal.querySelector('.photo-modal-close').addEventListener('click', () => modal.remove());
  document.body.appendChild(modal);
}

// Filter handlers
document.getElementById('filterAll')?.addEventListener('click', () => {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('filterAll').classList.add('active');
  renderPhotos(allPhotos);
});

document.getElementById('filterGps')?.addEventListener('click', () => {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('filterGps').classList.add('active');
  const gpsPhotos = allPhotos.filter(p => p.metadata?.exif_gps);
  renderPhotos(gpsPhotos);
});

// View tabs
document.querySelectorAll('.view-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.view-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    // Could implement different views here
  });
});

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  initMap();
  loadPhotos();
});
</script>
</body>
</html>
