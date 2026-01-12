<?php
session_start();
require_once __DIR__ . '/../db.php';

if (isset($_SESSION['username'])) { header('Location: home.php'); exit; }

$error = '';
$message = '';
$token = trim($_GET['token'] ?? '');
$userId = null;

// Validate token upfront
if ($token !== '') {
  $pdo = jarvis_pdo();
  $stmt = $pdo->prepare('SELECT id FROM users WHERE password_reset_token=:t AND password_reset_expires_at > NOW() LIMIT 1');
  $stmt->execute([':t'=>$token]);
  $row = $stmt->fetch();
  if ($row) {
    $userId = (int)$row['id'];
    // Log token validation
    jarvis_audit($userId, 'PASSWORD_RESET_TOKEN_VALIDATED', 'auth', ['token_length'=>strlen($token)]);
  } else {
    // Log failed reset attempt (invalid/expired token)
    $stmt2 = $pdo->prepare('SELECT id FROM users WHERE password_reset_token=:t LIMIT 1');
    $stmt2->execute([':t'=>$token]);
    $row2 = $stmt2->fetch();
    if ($row2) {
      // Token exists but expired
      $uid = (int)$row2['id'];
      jarvis_audit($uid, 'PASSWORD_RESET_TOKEN_EXPIRED', 'auth', ['token_length'=>strlen($token)]);
    }
    $error = 'Invalid or expired reset link. Please request a new one.';
  }
}

if ($token === '') {
  $error = 'No reset token provided.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId) {
  $newPass = (string)($_POST['password'] ?? '');
  $confirmPass = (string)($_POST['password_confirm'] ?? '');
  
  if ($newPass === '' || $confirmPass === '') {
    $error = 'Password is required.';
    jarvis_audit($userId, 'PASSWORD_RESET_VALIDATION_FAILED', 'auth', ['reason'=>'password_empty']);
  } elseif ($newPass !== $confirmPass) {
    $error = 'Passwords do not match.';
    jarvis_audit($userId, 'PASSWORD_RESET_VALIDATION_FAILED', 'auth', ['reason'=>'password_mismatch']);
  } elseif (strlen($newPass) < 8) {
    $error = 'Password must be at least 8 characters.';
    jarvis_audit($userId, 'PASSWORD_RESET_VALIDATION_FAILED', 'auth', ['reason'=>'password_too_short']);
  } else {
    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    if (jarvis_reset_password_with_token($token, $hash)) {
      $message = 'Password reset successful. You can now log in.';
      $token = ''; // clear token so form doesn't reappear
    } else {
      $error = 'Invalid or expired reset link. Please request a new one.';
    }
  }
}
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>JARVIS • Reset Password</title>
  <link rel="stylesheet" href="style.css" />
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
      <a href="login.php">Login</a>
      <a href="register.php">Register</a>
    </nav>
  </div>
  <div class="hero">
    <div class="scanlines" aria-hidden="true"></div>
    <img src="images/hero.svg" alt="" class="hero-ill" aria-hidden="true" />
    <h1>Reset Password</h1>
    <p>Enter your new password below.</p>
  </div>
  <div class="container">
    <div class="card">
      <h2>Reset Password</h2>
      <?php if ($message): ?>
        <div class="success"><p><?php echo htmlspecialchars($message); ?></p><div class="nav-links"><a href="login.php">Go to login</a></div></div>
      <?php elseif ($error): ?>
        <div class="error"><p><?php echo htmlspecialchars($error); ?></p></div>
      <?php endif; ?>
      
      <?php if ($token !== ''): ?>
        <form method="post">
          <label>New Password</label>
          <input type="password" name="password" required />
          <label>Confirm Password</label>
          <input type="password" name="password_confirm" required />
          <button class="btn" type="submit">Reset Password</button>
        </form>
      <?php endif; ?>
      
      <div class="nav-links"><a href="login.php">Back to login</a></div>
    </div>
  </div>
  <script src="navbar.js"></script>
</body></html>
