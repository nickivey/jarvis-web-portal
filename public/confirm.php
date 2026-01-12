<?php
session_start();
require_once __DIR__ . '/../db.php';
$u = (string)($_GET['user'] ?? '');
$t = (string)($_GET['token'] ?? '');

$ok = false;
$msg = 'Invalid confirmation link.';
if ($u && $t) {
  $user = jarvis_user_by_username($u);
  if ($user && !empty($user['email_verified_at'])) {
    $ok = true; $msg = 'Account already confirmed. You can log in.';
  } elseif (jarvis_verify_email($u, $t)) {
    $ok = true; $msg = 'Confirmed! You can log in now.';
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Confirm</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <div class="hero"><div class="overlay"></div><h1>JARVIS</h1><p>Email verification</p></div>
  <div class="container">
    <div class="card">
      <h2><?php echo $ok ? 'Success' : 'Error'; ?></h2>
      <div class="<?php echo $ok ? 'success' : 'error'; ?>"><p><?php echo htmlspecialchars($msg); ?></p></div>
      <div class="nav-links"><a href="login.php">Go to login</a></div>
    </div>
  </div>
</body></html>
