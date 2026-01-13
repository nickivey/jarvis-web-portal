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

$apiEndpoint = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'jarvis.example.com') . '/api/photos';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>iOS Photo Upload Setup — JARVIS</title>
  <link rel="stylesheet" href="/style.css">
  <style>
    html, body {
      background: radial-gradient(1200px 900px at 15% 0%, rgba(0, 150, 255, 0.3), transparent 50%),
                 radial-gradient(900px 800px at 85% 100%, rgba(88, 86, 214, 0.25), transparent 50%),
                 radial-gradient(700px 700px at 50% 50%, rgba(34, 197, 255, 0.08), transparent 60%),
                 linear-gradient(180deg, #001a33 0%, #0a1f4d 50%, #050f33 100%);
      min-height: 100vh;
      color: var(--txt);
    }
    
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background-image:
        linear-gradient(rgba(34, 197, 255, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 150, 255, 0.03) 1px, transparent 1px);
      background-size: 40px 40px;
      opacity: 0.06;
      pointer-events: none;
      mix-blend-mode: overlay;
      z-index: 1;
    }
    
    .ios-setup-page {
      max-width: 1100px;
      margin: 0 auto;
      position: relative;
      z-index: 2;
    }
    
    /* Hero Section */
    .ios-hero {
      background: linear-gradient(135deg, rgba(0, 122, 255, 0.15), rgba(88, 86, 214, 0.15), rgba(255, 45, 85, 0.1));
      border: 1px solid rgba(0, 122, 255, 0.2);
      border-radius: 24px;
      padding: 40px;
      text-align: center;
      margin-bottom: 32px;
      position: relative;
      overflow: hidden;
    }
    .ios-hero::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle at 30% 30%, rgba(0, 122, 255, 0.1), transparent 50%),
                  radial-gradient(circle at 70% 70%, rgba(88, 86, 214, 0.1), transparent 50%);
      animation: heroGlow 8s ease-in-out infinite alternate;
    }
    @keyframes heroGlow {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .ios-hero-content {
      position: relative;
      z-index: 1;
    }
    .ios-hero-icon {
      font-size: 4rem;
      margin-bottom: 16px;
      display: inline-block;
      animation: iosIconFloat 3s ease-in-out infinite;
    }
    @keyframes iosIconFloat {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-10px); }
    }
    .ios-hero h1 {
      font-size: 2rem;
      margin: 0 0 12px 0;
      background: linear-gradient(90deg, #fff, #5ac8fa);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .ios-hero p {
      color: var(--muted);
      font-size: 1.1rem;
      max-width: 600px;
      margin: 0 auto;
    }
    
    /* Main Grid */
    .ios-main-grid {
      display: grid;
      grid-template-columns: 1fr 400px;
      gap: 24px;
    }
    @media (max-width: 900px) {
      .ios-main-grid {
        grid-template-columns: 1fr;
      }
    }
    
    /* Token Card */
    .token-card {
      background: linear-gradient(145deg, rgba(30, 30, 45, 0.95), rgba(20, 25, 40, 0.98));
      border: 1px solid rgba(0, 122, 255, 0.2);
      border-radius: 20px;
      overflow: hidden;
    }
    .token-card-header {
      padding: 20px 24px;
      background: linear-gradient(90deg, rgba(0, 122, 255, 0.1), rgba(88, 86, 214, 0.1));
      border-bottom: 1px solid rgba(0, 122, 255, 0.1);
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .token-card-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: linear-gradient(135deg, rgba(0, 122, 255, 0.3), rgba(88, 86, 214, 0.2));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }
    .token-card-title h3 {
      margin: 0 0 4px 0;
      font-size: 1.1rem;
      color: #fff;
    }
    .token-card-title p {
      margin: 0;
      font-size: 0.85rem;
      color: var(--muted);
    }
    .token-card-body {
      padding: 24px;
    }
    
    /* Token Display */
    .token-display {
      background: rgba(0, 0, 0, 0.4);
      border: 1px solid rgba(0, 122, 255, 0.2);
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 16px;
    }
    .token-label {
      font-size: 0.75rem;
      color: #5ac8fa;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 8px;
    }
    .token-value {
      font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
      font-size: 0.85rem;
      color: #4ade80;
      word-break: break-all;
      padding: 12px;
      background: rgba(0, 0, 0, 0.3);
      border-radius: 8px;
      border: 1px solid rgba(74, 222, 128, 0.2);
    }
    .token-expires {
      font-size: 0.8rem;
      color: var(--muted);
      margin-top: 8px;
    }
    .token-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    @media (max-width: 600px) {
      .token-actions {
        flex-direction: column;
      }
      .token-btn {
        flex: none;
        width: 100%;
      }
    }
    .token-btn {
      flex: 1;
      min-width: 140px;
      padding: 12px 16px;
      border-radius: 10px;
      background: rgba(0, 122, 255, 0.15);
      border: 1px solid rgba(0, 122, 255, 0.3);
      color: #5ac8fa;
      cursor: pointer;
      font-size: 0.9rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s ease;
      text-decoration: none;
    }
    .token-btn:hover {
      background: rgba(0, 122, 255, 0.25);
      transform: translateY(-2px);
    }
    .token-btn.primary {
      background: linear-gradient(135deg, rgba(0, 122, 255, 0.3), rgba(88, 86, 214, 0.25));
      border-color: rgba(0, 122, 255, 0.4);
    }
    
    /* Empty Token State */
    .no-token-state {
      text-align: center;
      padding: 30px 20px;
    }
    .no-token-icon {
      font-size: 3rem;
      margin-bottom: 12px;
      opacity: 0.5;
    }
    .no-token-state h4 {
      color: #fff;
      margin: 0 0 8px 0;
    }
    .no-token-state p {
      color: var(--muted);
      margin: 0 0 16px 0;
      font-size: 0.9rem;
    }
    
    /* Steps Section */
    .steps-section {
      background: rgba(0, 0, 0, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.05);
      border-radius: 20px;
      padding: 24px;
    }
    .steps-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px;
    }
    .steps-header h3 {
      margin: 0;
      color: #fff;
    }
    .step-list {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .step-item {
      display: flex;
      gap: 16px;
      padding: 16px;
      background: rgba(0, 0, 0, 0.2);
      border-radius: 14px;
      border: 1px solid rgba(255, 255, 255, 0.03);
      transition: all 0.2s ease;
    }
    .step-item:hover {
      background: rgba(0, 122, 255, 0.05);
      border-color: rgba(0, 122, 255, 0.1);
    }
    .step-number {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, rgba(0, 122, 255, 0.3), rgba(88, 86, 214, 0.2));
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      color: #5ac8fa;
      flex-shrink: 0;
    }
    .step-content h4 {
      margin: 0 0 6px 0;
      color: #fff;
      font-size: 1rem;
    }
    .step-content p {
      margin: 0;
      color: var(--muted);
      font-size: 0.9rem;
      line-height: 1.5;
    }
    .step-content code {
      background: rgba(0, 0, 0, 0.4);
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 0.85rem;
      color: #4ade80;
    }
    
    /* Sidebar */
    .ios-sidebar {
      display: flex;
      flex-direction: column;
      gap: 20px;
      height: fit-content;
      position: sticky;
      top: 80px;
    }
    @media (max-width: 900px) {
      .ios-sidebar {
        position: relative;
        top: auto;
      }
    }
    
    /* API Info */
    .api-info-card {
      background: rgba(0, 0, 0, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.05);
      border-radius: 16px;
      padding: 20px;
    }
    .api-info-header {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
    }
    .api-info-header h4 {
      margin: 0;
      color: #fff;
      font-size: 0.95rem;
    }
    .api-endpoint {
      background: rgba(0, 0, 0, 0.4);
      border: 1px solid rgba(0, 122, 255, 0.2);
      border-radius: 10px;
      padding: 12px;
      font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
      font-size: 0.8rem;
      color: #5ac8fa;
      word-break: break-all;
      margin-bottom: 12px;
    }
    .api-method {
      display: inline-block;
      background: rgba(74, 222, 128, 0.2);
      color: #4ade80;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      margin-right: 8px;
    }
    
    /* Trigger Options */
    .trigger-options {
      background: rgba(0, 0, 0, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.05);
      border-radius: 16px;
      padding: 20px;
    }
    .trigger-options h4 {
      margin: 0 0 14px 0;
      color: #fff;
      font-size: 0.95rem;
    }
    .trigger-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .trigger-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px;
      background: rgba(0, 0, 0, 0.2);
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.03);
    }
    .trigger-icon {
      font-size: 1.3rem;
    }
    .trigger-info {
      flex: 1;
    }
    .trigger-name {
      font-size: 0.9rem;
      color: #fff;
      margin-bottom: 2px;
    }
    .trigger-desc {
      font-size: 0.75rem;
      color: var(--muted);
    }
    .trigger-badge {
      font-size: 0.65rem;
      padding: 3px 8px;
      border-radius: 6px;
      background: rgba(74, 222, 128, 0.2);
      color: #4ade80;
    }
    .trigger-badge.recommended {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
    }
    
    /* Tips Card */
    .tips-card {
      background: linear-gradient(135deg, rgba(251, 191, 36, 0.1), rgba(251, 146, 60, 0.05));
      border: 1px solid rgba(251, 191, 36, 0.2);
      border-radius: 16px;
      padding: 20px;
    }
    .tips-card h4 {
      margin: 0 0 12px 0;
      color: #fbbf24;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .tips-list {
      margin: 0;
      padding-left: 20px;
      color: var(--muted);
      font-size: 0.85rem;
      line-height: 1.7;
    }
    .tips-list li {
      margin-bottom: 6px;
    }
    
    .curl-modal {
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(10, 10, 15, 0.9);
      backdrop-filter: blur(8px);
      z-index: 9999;
      padding: 24px;
      overflow-y: auto;
    }
    .curl-modal-content {
      background: rgba(30, 30, 45, 0.98);
      border: 1px solid rgba(0, 122, 255, 0.3);
      border-radius: 16px;
      padding: 24px;
      max-width: 700px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
    }
    .curl-modal h3 {
      margin: 0 0 16px 0;
      color: #fff;
    }
    .curl-code {
      background: rgba(0, 0, 0, 0.4);
      border: 1px solid rgba(0, 122, 255, 0.2);
      border-radius: 10px;
      padding: 16px;
      font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
      font-size: 0.85rem;
      color: #4ade80;
      overflow-x: auto;
      white-space: pre-wrap;
      word-break: break-all;
    }
    .curl-modal-actions {
      display: flex;
      gap: 12px;
      margin-top: 16px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }
    @media (max-width: 480px) {
      .curl-modal-actions {
        flex-direction: column;
      }
      .curl-code {
        font-size: 0.75rem;
      }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container ios-setup-page">
  
  <!-- Hero -->
  <div class="ios-hero">
    <div class="ios-hero-content">
      <div class="ios-hero-icon">📱</div>
      <h1>iOS Photo Upload Setup</h1>
      <p>Automatically upload photos from your iPhone to JARVIS using iOS Shortcuts. Set up once and your photos sync seamlessly.</p>
    </div>
  </div>
  
  <!-- Main Grid -->
  <div class="ios-main-grid">
    <!-- Left Column -->
    <div class="ios-main-content">
      <!-- Token Card -->
      <div class="token-card">
        <div class="token-card-header">
          <div class="token-card-icon">🔑</div>
          <div class="token-card-title">
            <h3>Device Upload Token</h3>
            <p>Secure token for iOS Shortcuts authentication</p>
          </div>
        </div>
        <div class="token-card-body">
          <div id="noTokenState" class="no-token-state">
            <div class="no-token-icon">🔐</div>
            <h4>No Token Generated Yet</h4>
            <p>Create a secure upload token to use in your iOS Shortcut</p>
            <button class="token-btn primary" id="createTokenBtn">
              <span>➕</span> Generate Upload Token
            </button>
          </div>
          
          <div id="tokenDisplay" style="display:none;">
            <div class="token-display">
              <div class="token-label">Your Device Token</div>
              <div class="token-value" id="tokenValue">—</div>
              <div class="token-expires">Expires: <span id="tokenExpires">—</span></div>
            </div>
            <div class="token-actions">
              <button class="token-btn" id="copyTokenBtn">
                <span>📋</span> Copy Token
              </button>
              <button class="token-btn" id="curlSampleBtn">
                <span>💻</span> cURL Sample
              </button>
              <button class="token-btn" id="regenerateBtn">
                <span>🔄</span> Regenerate
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Steps Section -->
      <div class="steps-section" style="margin-top: 24px;">
        <div class="steps-header">
          <span style="font-size:1.5rem">📋</span>
          <h3>Setup Steps</h3>
        </div>
        <div class="step-list">
          <div class="step-item">
            <div class="step-number">1</div>
            <div class="step-content">
              <h4>Generate an Upload Token</h4>
              <p>Click the button above to create a secure device token. This token allows your iPhone to upload photos to your account.</p>
            </div>
          </div>
          <div class="step-item">
            <div class="step-number">2</div>
            <div class="step-content">
              <h4>Open Shortcuts App</h4>
              <p>On your iPhone, open the <strong>Shortcuts</strong> app and create a new shortcut.</p>
            </div>
          </div>
          <div class="step-item">
            <div class="step-number">3</div>
            <div class="step-content">
              <h4>Add Photo Selection</h4>
              <p>Add action: <strong>Find Photos</strong> or <strong>Get Latest Photos</strong> to select which photos to upload.</p>
            </div>
          </div>
          <div class="step-item">
            <div class="step-number">4</div>
            <div class="step-content">
              <h4>Configure Upload</h4>
              <p>Add <strong>Get Contents of URL</strong> action with:</p>
              <p style="margin-top:8px">
                • URL: <code><?php echo htmlspecialchars($apiEndpoint); ?></code><br>
                • Method: <code>POST</code><br>
                • Request Body: Form<br>
                • Add field <code>file</code> set to the photo<br>
                • Header: <code>Authorization: Bearer YOUR_TOKEN</code>
              </p>
            </div>
          </div>
          <div class="step-item">
            <div class="step-number">5</div>
            <div class="step-content">
              <h4>Set Up Automation (Optional)</h4>
              <p>Go to Shortcuts → Automation → Create Personal Automation. Choose a trigger like <strong>NFC Tag</strong> or <strong>Back Tap</strong> to run automatically.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Sidebar -->
    <div class="ios-sidebar">
      <!-- API Info -->
      <div class="api-info-card">
        <div class="api-info-header">
          <span>🌐</span>
          <h4>API Endpoint</h4>
        </div>
        <div class="api-endpoint">
          <span class="api-method">POST</span>
          <?php echo htmlspecialchars($apiEndpoint); ?>
        </div>
        <p style="font-size:0.8rem;color:var(--muted);margin:0">
          Accepts multipart form uploads with a <code>file</code> field.
        </p>
      </div>
      
      <!-- Trigger Options -->
      <div class="trigger-options">
        <h4>🚀 Automation Triggers</h4>
        <div class="trigger-list">
          <div class="trigger-item">
            <span class="trigger-icon">📶</span>
            <div class="trigger-info">
              <div class="trigger-name">NFC Tag</div>
              <div class="trigger-desc">Tap an NFC sticker to upload</div>
            </div>
            <span class="trigger-badge recommended">Recommended</span>
          </div>
          <div class="trigger-item">
            <span class="trigger-icon">👆</span>
            <div class="trigger-info">
              <div class="trigger-name">Back Tap</div>
              <div class="trigger-desc">Double/triple tap iPhone back</div>
            </div>
            <span class="trigger-badge">No Prompt</span>
          </div>
          <div class="trigger-item">
            <span class="trigger-icon">🔌</span>
            <div class="trigger-info">
              <div class="trigger-name">Charger Connected</div>
              <div class="trigger-desc">When plugged in at night</div>
            </div>
          </div>
          <div class="trigger-item">
            <span class="trigger-icon">⏰</span>
            <div class="trigger-info">
              <div class="trigger-name">Time of Day</div>
              <div class="trigger-desc">Daily upload at set time</div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Tips -->
      <div class="tips-card">
        <h4>💡 Tips & Best Practices</h4>
        <ul class="tips-list">
          <li>Use Wi-Fi only uploads for large batches</li>
          <li>Add "Show Notification" for upload confirmation</li>
          <li>Test with one photo first before batch uploads</li>
          <li>Tokens are upload-only for security</li>
          <li>Keep your token private</li>
        </ul>
      </div>
      
      <!-- Quick Links -->
      <div style="display:flex;flex-direction:column;gap:10px">
        <a href="/public/photos.php" class="token-btn" style="text-decoration:none;width:100%">
          <span>🖼️</span> View Gallery
        </a>
        <a href="/docs/ios-photo-upload.md" class="token-btn" style="text-decoration:none;width:100%" target="_blank">
          <span>📄</span> Documentation
        </a>
      </div>
    </div>
  </div>
</div>

<!-- cURL Modal -->
<div id="curlModal" class="curl-modal" style="display:none">
  <div class="curl-modal-content">
    <h3>📟 cURL Sample Command</h3>
    <div class="curl-code" id="curlCode"></div>
    <div class="curl-modal-actions">
      <button class="token-btn" id="copyCurlBtn">📋 Copy Command</button>
      <button class="token-btn" id="closeCurlBtn">Close</button>
    </div>
  </div>
</div>

<script>
const apiEndpoint = <?php echo json_encode($apiEndpoint); ?>;
let currentToken = '';

async function api(path, opts) {
  opts = opts || {};
  opts.headers = opts.headers || {};
  const jwt = localStorage.getItem('jarvis_token');
  if (jwt) opts.headers['Authorization'] = 'Bearer ' + jwt;
  const r = await fetch(path, opts);
  if (!r.ok) throw new Error('Request failed: ' + r.status);
  return r.json();
}

async function createToken() {
  try {
    const resp = await api('/api/device_tokens', { 
      method: 'POST', 
      body: JSON.stringify({ label: 'iOS Shortcut ' + new Date().toISOString() }), 
      headers: { 'Content-Type': 'application/json' } 
    });
    const t = resp.token;
    currentToken = t.token;
    
    document.getElementById('tokenValue').textContent = t.token;
    document.getElementById('tokenExpires').textContent = t.expires_at || 'Never';
    document.getElementById('noTokenState').style.display = 'none';
    document.getElementById('tokenDisplay').style.display = 'block';
    
  } catch (e) {
    alert('Failed to create token. Make sure you are logged in.');
    console.error(e);
  }
}

document.getElementById('createTokenBtn').addEventListener('click', createToken);
document.getElementById('regenerateBtn').addEventListener('click', createToken);

document.getElementById('copyTokenBtn').addEventListener('click', function() {
  navigator.clipboard.writeText(currentToken).then(() => {
    this.innerHTML = '<span>✅</span> Copied!';
    setTimeout(() => { this.innerHTML = '<span>📋</span> Copy Token'; }, 2000);
  });
});

document.getElementById('curlSampleBtn').addEventListener('click', function() {
  const curl = `curl -X POST "${apiEndpoint}" \\
  -H "Authorization: Bearer ${currentToken}" \\
  -F "file=@/path/to/photo.jpg" \\
  -F "meta={\\"source\\":\\"curl-test\\"}"`;
  
  document.getElementById('curlCode').textContent = curl;
  document.getElementById('curlModal').style.display = 'flex';
});

document.getElementById('closeCurlBtn').addEventListener('click', function() {
  document.getElementById('curlModal').style.display = 'none';
});

document.getElementById('curlModal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});

document.getElementById('copyCurlBtn').addEventListener('click', function() {
  const curl = document.getElementById('curlCode').textContent;
  navigator.clipboard.writeText(curl).then(() => {
    this.innerHTML = '✅ Copied!';
    setTimeout(() => { this.innerHTML = '📋 Copy Command'; }, 2000);
  });
});
</script>
</body>
</html>
