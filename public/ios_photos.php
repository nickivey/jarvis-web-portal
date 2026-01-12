<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../helpers.php';

[$uid, $u] = require_jwt_user();
$prefs = jarvis_preferences($uid);
$token = '';
// If running locally or for convenience, provide a short-lived token option (use with caution)
if (isset($_GET['token']) && $_GET['token'] === '1' && ($u['role'] ?? '') === 'admin') {
  $token = jarvis_jwt_issue($uid, $u['username'] ?? '', 3600);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>iOS Photo Upload — JARVIS</title>
  <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container">
  <h1>Upload Photos from iOS</h1>
  <p>Use the iOS <strong>Shortcuts</strong> app to upload photos to your Jarvis account.</p>
  <h3>Quick steps</h3>
  <ol>
    <li>Open the Shortcuts app and make a new shortcut.</li>
    <li>Add action: <em>Select Photos</em> or <em>Get Latest Photos</em>.</li>
    <li>Add action: <em>Get File</em> to convert the photo to a file object.</li>
    <li>Add action: <em>Get Contents of URL</em> → POST to <code><?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/photos') ?></code>.</li>
    <li>Set the request to <em>Form</em> and add a field <code>file</code> with the file variable. Add a header <code>Authorization: Bearer &lt;YOUR_JWT&gt;</code>.</li>
  </ol>
  <p>Full guide with screenshots and implementation notes is available in the <a href="/docs/ios-photo-upload.md">project docs</a>.</p>
  <p><a href="/public/ios_upload_setup.php" class="btn">Create a device upload token & install shortcut</a></p>
  <?php if ($token): ?>
    <div class="alert alert-info">Generated token (expires in 1 hour): <code><?= htmlspecialchars($token) ?></code></div>
  <?php endif; ?>
  <p class="muted">Note: this project does not create `/api/photos` by default — see the docs for recommended server-side steps.</p>
</div>
</body>
</html>