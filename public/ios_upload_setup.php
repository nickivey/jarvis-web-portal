<?php
require_once __DIR__ . '/../index.php'; // ensure same auth helpers are available
// Minimal install page for iOS Shortcuts Upload
if (session_status() === PHP_SESSION_NONE) session_start();
$hasToken = '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Setup iOS Photo Upload to Jarvis</title>
  <style>
    body { font-family: Arial, sans-serif; max-width: 760px; margin: 2rem auto; }
    pre { background:#f6f8fa;padding:1rem;border-radius:6px; }
    .token { font-family: monospace; background:#222;color:#fff;padding:6px 8px;border-radius:4px; display:inline-block; }
    button { padding:8px 12px;border-radius:6px;border:1px solid #ccc;background:#eee; }
  </style>
</head>
<body>
  <h1>Upload photos from iPhone (Shortcuts)</h1>
  <p>Use this page to create a per-device upload token for iOS Shortcuts. The token is upload-only and can be revoked anytime.</p>

  <div id="actions">
    <button id="create" type="button">Create device upload token</button>
    <button id="refresh" type="button" style="display:none;">Generate new token</button>
  </div>

  <div id="tokenbox" style="margin-top:16px;display:none;">
    <h3>Your device token (keep private)</h3>
    <div><span class="token" id="tokenval"></span></div>
    <p>Expires: <span id="tokenexp">—</span></p>
    <p>
      <button id="downloadShortcut">Download Shortcut template (paste token)</button>
      <button id="copyCurl">Copy cURL sample</button>
    </p>
  </div>

  <hr>
  <h3>Manual install steps (quick)</h3>
  <ol>
    <li>Tap <strong>Create device upload token</strong>. Copy the token.</li>
    <li>Open the Shortcuts app on your iPhone and create a new shortcut that:<ul>
      <li>Find Photos (filter as you like)</li>
      <li>For Each Photo → Get File from Photo → Get Contents of URL</li>
      <li>Set URL to <code><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . '/api/photos'); ?></code></li>
      <li>Method: POST; Request Body: Form; Add field named <code>file</code> and set to the File variable</li>
      <li>Header: <code>Authorization: Bearer &lt;DEVICE_TOKEN&gt;</code> (paste your token)</li>
      <li>Optional: Add a notification on success/failure</li>
    </ul></li>
    <li>Save the Shortcut and assign a Personal Automation trigger (NFC, Charger connect, or Time-based). Note: some personal automations require confirmation to run on some iOS versions.</li>
  </ol>

  <hr>
  <h3>Security & tips</h3>
  <ul>
    <li>Tokens are upload-only and can be revoked from this page.</li>
    <li>Keep the token private; it's bearer-authenticated.</li>
    <li>Shortcuts tests with small batches and simple retries for reliability.</li>
  </ul>

<script>
async function api(path, opts) {
  opts = opts || {};
  opts.headers = opts.headers || {};
  // Use JWT from localStorage if present (the app stores token in localStorage after login)
  const jwt = localStorage.getItem('jarvis_token');
  if (jwt) opts.headers['Authorization'] = 'Bearer ' + jwt;
  const r = await fetch(path, opts);
  if (!r.ok) throw new Error('Request failed: ' + r.status);
  return r.json();
}

document.getElementById('create').addEventListener('click', async function() {
  try {
    this.disabled = true;
    const resp = await api('/api/device_tokens', { method: 'POST', body: JSON.stringify({ label: 'iOS Shortcut ' + new Date().toISOString() }), headers: { 'Content-Type': 'application/json' } });
    const t = resp.token;
    document.getElementById('tokenval').textContent = t.token;
    document.getElementById('tokenexp').textContent = t.expires_at || 'no expiry';
    document.getElementById('tokenbox').style.display = 'block';
    document.getElementById('refresh').style.display = '';
    this.style.display = 'none';
  } catch (e) {
    alert('Failed to create token. Are you logged in?');
    this.disabled = false;
  }
});

document.getElementById('refresh').addEventListener('click', function() { document.getElementById('create').click(); });

document.getElementById('copyCurl').addEventListener('click', function() {
  const token = document.getElementById('tokenval').textContent;
  const url = location.origin + '/api/photos';
  const curl = `curl -H "Authorization: Bearer ${token}" -F "file=@/path/to/photo.jpg" ${url}`;
  navigator.clipboard.writeText(curl).then(()=>alert('cURL copied to clipboard'));
});

document.getElementById('downloadShortcut').addEventListener('click', function() {
  const token = document.getElementById('tokenval').textContent;
  const doc = {
    name: 'Jarvis Photo Upload (import)',
    description: 'Shortcut template. After importing, edit the Get Contents of URL action to set Authorization header: Bearer ' + token,
    steps: [
      'Find Photos → Filter as desired',
      'For Each → Get File from Photo',
      'Get Contents of URL (POST) → URL: ' + location.origin + '/api/photos',
      'Headers: Authorization: Bearer ' + token + ', Request body: Form, field: file (File variable)'
    ]
  };
  const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'JarvisPhotoShortcut.json';
  document.body.appendChild(a);
  a.click();
  URL.revokeObjectURL(a.href);
  a.remove();
});
</script>
</body>
</html>
